<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachineBinding;
use Illuminate\Support\Facades\DB;

class NodeConfigVersionService
{
    public static function bump(Server|int $server): int
    {
        $serverId = $server instanceof Server ? (int) $server->id : $server;

        $version = DB::transaction(function () use ($serverId): int {
            $record = Server::query()->lockForUpdate()->findOrFail($serverId);
            $version = max(1, (int) $record->config_version) + 1;

            $record->forceFill(['config_version' => $version])->saveQuietly();
            ServerMachineBinding::query()
                ->where('server_id', $serverId)
                ->update(['desired_config_version' => $version]);

            return $version;
        });

        DB::afterCommit(fn () => NodeSyncService::notifyFullSync($serverId));

        return $version;
    }

    /**
     * @param iterable<int, int|string> $serverIds
     */
    public static function bumpMany(iterable $serverIds): void
    {
        collect($serverIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->each(fn ($id) => self::bump($id));
    }
}
