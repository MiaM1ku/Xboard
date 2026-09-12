<?php

namespace App\Services;

use App\Models\Server;
use App\Models\StatServer;
use App\Models\UserNodeTraffic;
use Illuminate\Support\Facades\Schema;

class NodeEntryTrafficService
{
    /**
     * @return list<array{id:int,name:string,upload:int,download:int,total:int}>
     */
    public function rank(int $startAt, int $endAt, int $limit = 10): array
    {
        $parents = StatServer::query()
            ->selectRaw('server_id as id, SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->groupBy('server_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->id => [
                'id' => (int) $row->id,
                'upload' => (int) $row->upload,
                'download' => (int) $row->download,
                'total' => (int) $row->total,
            ]])
            ->all();

        $children = Server::query()
            ->where('parent_id', '>', 0)
            ->get(['id', 'parent_id']);

        $childTotals = [];
        if ($children->isNotEmpty() && Schema::hasTable('v2_user_node_traffic')) {
            $childTotals = UserNodeTraffic::query()
                ->selectRaw('server_id as id, SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
                ->where('record_at', '>=', $startAt)
                ->where('record_at', '<', $endAt)
                ->whereIn('server_id', $children->pluck('id'))
                ->groupBy('server_id')
                ->get()
                ->mapWithKeys(fn ($row) => [(int) $row->id => [
                    'id' => (int) $row->id,
                    'upload' => (int) $row->upload,
                    'download' => (int) $row->download,
                    'total' => (int) $row->total,
                ]])
                ->all();
        }

        $rows = $this->merge($parents, $childTotals, $children->pluck('parent_id', 'id')->map(fn ($id) => (int) $id)->all());
        $names = Server::query()->whereIn('id', collect($rows)->pluck('id'))->pluck('name', 'id');

        return collect($rows)
            ->filter(fn (array $row) => $row['total'] > 0)
            ->sortByDesc('total')
            ->take($limit)
            ->map(fn (array $row) => [
                'id' => $row['id'],
                'name' => $names[$row['id']] ?? "Node #{$row['id']}",
                'upload' => $row['upload'],
                'download' => $row['download'],
                'total' => $row['total'],
            ])
            ->values()
            ->all();
    }

    /**
     * Subtract child-entry traffic from the physical parent total.
     *
     * @param array<int, array{id:int,upload:int,download:int,total:int}> $parents
     * @param array<int, array{id:int,upload:int,download:int,total:int}> $children
     * @param array<int, int> $childParentMap
     * @return list<array{id:int,upload:int,download:int,total:int}>
     */
    public function merge(array $parents, array $children, array $childParentMap): array
    {
        $remaining = $parents;
        foreach ($children as $childId => $traffic) {
            $parentId = (int) ($childParentMap[$childId] ?? 0);
            if ($parentId <= 0 || !isset($remaining[$parentId])) {
                continue;
            }
            $remaining[$parentId]['upload'] = max(0, $remaining[$parentId]['upload'] - $traffic['upload']);
            $remaining[$parentId]['download'] = max(0, $remaining[$parentId]['download'] - $traffic['download']);
            $remaining[$parentId]['total'] = $remaining[$parentId]['upload'] + $remaining[$parentId]['download'];
        }

        return array_values(array_merge($remaining, $children));
    }
}
