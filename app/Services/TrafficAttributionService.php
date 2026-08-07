<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerRouteProfile;
use Illuminate\Support\Facades\DB;

/**
 * Persists non-billable traffic attribution. Node totals mirror the normal
 * traffic payload; profile totals are a subset and must never be charged.
 */
class TrafficAttributionService
{
    public function recordNodeTraffic(Server $server, array $traffic): void
    {
        $this->upsert($server, $traffic, null);
    }

    public function recordProfileTraffic(Server $server, array $traffic): void
    {
        foreach ($traffic as $authUser => $value) {
            if (!is_string($authUser)
                || !preg_match('/^xb:u:(\d+):rp:([A-Za-z0-9-]{1,64})$/', $authUser, $matches)
            ) {
                continue;
            }

            $userId = (int) $matches[1];
            $profile = ServerRouteProfile::query()
                ->where('server_id', $server->id)
                ->where('uuid', $matches[2])
                ->first();
            if (!$profile) {
                continue;
            }

            $this->upsert($server, [$userId => $value], $profile);
        }
    }

    private function upsert(Server $server, array $traffic, ?ServerRouteProfile $profile): void
    {
        $recordAt = strtotime(date('Y-m-d'));
        $now = now();
        $rows = [];
        foreach ($traffic as $userId => $value) {
            if (!is_numeric($userId) || !$this->validTraffic($value)) {
                continue;
            }
            $u = max(0, (int) $value[0]);
            $d = max(0, (int) $value[1]);
            if ($u === 0 && $d === 0) {
                continue;
            }
            $rows[] = [
                'user_id' => (int) $userId,
                'server_id' => (int) $server->id,
                'route_profile_id' => $profile?->id,
                'outbound_template_id' => $profile?->outbound_template_id,
                'profile_uuid' => $profile?->uuid ?? '',
                'u' => $u,
                'd' => $d,
                'record_type' => 'd',
                'record_at' => $recordAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!$rows) {
            return;
        }

        $table = 'v2_user_node_traffic';
        $unique = ['user_id', 'server_id', 'profile_uuid', 'record_type', 'record_at'];
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            $updates = [
                'u' => DB::raw("{$table}.u + EXCLUDED.u"),
                'd' => DB::raw("{$table}.d + EXCLUDED.d"),
                'route_profile_id' => DB::raw('EXCLUDED.route_profile_id'),
                'outbound_template_id' => DB::raw('EXCLUDED.outbound_template_id'),
                'updated_at' => DB::raw('EXCLUDED.updated_at'),
            ];
        } elseif ($driver === 'sqlite') {
            $updates = [
                'u' => DB::raw('u + excluded.u'),
                'd' => DB::raw('d + excluded.d'),
                'route_profile_id' => DB::raw('excluded.route_profile_id'),
                'outbound_template_id' => DB::raw('excluded.outbound_template_id'),
                'updated_at' => DB::raw('excluded.updated_at'),
            ];
        } else {
            $updates = [
                'u' => DB::raw('u + VALUES(u)'),
                'd' => DB::raw('d + VALUES(d)'),
                'route_profile_id' => DB::raw('VALUES(route_profile_id)'),
                'outbound_template_id' => DB::raw('VALUES(outbound_template_id)'),
                'updated_at' => DB::raw('VALUES(updated_at)'),
            ];
        }

        DB::table($table)->upsert($rows, $unique, $updates);
    }

    private function validTraffic(mixed $value): bool
    {
        return is_array($value) && count($value) === 2
            && is_numeric($value[0]) && is_numeric($value[1]);
    }
}
