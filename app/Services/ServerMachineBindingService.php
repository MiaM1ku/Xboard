<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachineBinding;
use Illuminate\Support\Facades\DB;

class ServerMachineBindingService
{
    /**
     * Replace a server's machine assignments. The pivot is authoritative;
     * v2_server.machine_id is only mirrored for legacy agents and clients.
     *
     * @param array<int, int|array{machine_id:int,state?:string}> $bindings
     */
    public static function sync(Server $server, array $bindings): void
    {
        $oldMachineIds = $server->machineBindings()
            ->pluck('machine_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $records = [];
        $legacyMachineId = null;

        foreach ($bindings as $binding) {
            $machineId = is_array($binding) ? (int) ($binding['machine_id'] ?? 0) : (int) $binding;
            if ($machineId <= 0) {
                continue;
            }

            $state = is_array($binding)
                ? (string) ($binding['state'] ?? ServerMachineBinding::STATE_ACTIVE)
                : ServerMachineBinding::STATE_ACTIVE;
            if (!in_array($state, ServerMachineBinding::STATES, true)) {
                $state = ServerMachineBinding::STATE_ACTIVE;
            }

            $records[$machineId] = [
                'state' => $state,
                'desired_config_version' => max(1, (int) $server->config_version),
                'updated_at' => now(),
            ];
            if ($legacyMachineId === null && $state !== ServerMachineBinding::STATE_DISABLED) {
                $legacyMachineId = $machineId;
            }
        }

        DB::transaction(function () use ($server, $records, $legacyMachineId): void {
            $server->machines()->sync($records);
            $server->forceFill(['machine_id' => $legacyMachineId])->saveQuietly();
        });

        foreach (array_unique([...$oldMachineIds, ...array_map('intval', array_keys($records))]) as $machineId) {
            NodeSyncService::notifyMachineNodesChanged($machineId);
        }
    }
}
