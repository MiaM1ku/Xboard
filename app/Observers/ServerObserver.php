<?php

namespace App\Observers;

use App\Models\Server;
use App\Services\NodeConfigVersionService;
use App\Services\NodeSyncService;

class ServerObserver
{
    public bool $afterCommit = true;

    public function created(Server $server): void
    {
        // Bindings are synchronized after the server row is created.
    }

    public function updated(Server $server): void
    {
        if ($server->wasChanged([
            'group_ids',
            'server_port',
            'protocol_settings',
            'type',
            'route_ids',
            'custom_outbounds',
            'custom_routes',
            'cert_config',
        ])) {
            NodeConfigVersionService::bump($server);
        }

        if ($server->wasChanged('enabled')) {
            foreach ($server->machineBindings()->pluck('machine_id') as $machineId) {
                NodeSyncService::notifyMachineNodesChanged((int) $machineId);
            }
        }
    }

    public function deleting(Server $server): void
    {
        $machineIds = $server->machineBindings()->pluck('machine_id')->map(fn ($id) => (int) $id)->all();
        \Illuminate\Support\Facades\DB::afterCommit(function () use ($machineIds): void {
            foreach ($machineIds as $machineId) {
                NodeSyncService::notifyMachineNodesChanged($machineId);
            }
        });
    }

    private function notifyMachineChange(?int $newMachineId, ?int $oldMachineId): void
    {
        $notified = [];

        if ($newMachineId) {
            NodeSyncService::notifyMachineNodesChanged($newMachineId);
            $notified[] = $newMachineId;
        }

        if ($oldMachineId && !in_array($oldMachineId, $notified, true)) {
            NodeSyncService::notifyMachineNodesChanged($oldMachineId);
        }
    }

    private function notifyMachineNodesChanged(?int $machineId): void
    {
        if ($machineId) {
            NodeSyncService::notifyMachineNodesChanged($machineId);
        }
    }
}
