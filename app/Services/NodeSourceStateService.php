<?php

namespace App\Services;

use App\Models\Server;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class NodeSourceStateService
{
    private const SOURCE_PREFIX = 'node:source_state:';
    private const INDEX_PREFIX = 'node:source_index:';

    public function record(Server $node, string $sourceId, array $patch): void
    {
        $hash = substr(hash('sha256', $sourceId), 0, 24);
        $key = self::SOURCE_PREFIX . $node->id . ':' . $hash;
        $current = json_decode((string) Redis::get($key), true);
        if (!is_array($current)) {
            $current = [];
        }

        // Each top-level field is a complete snapshot from one source. Using a
        // recursive merge would keep old entries when an agent reports an empty
        // `online` map, so replace patched fields atomically instead.
        $state = array_replace($current, $patch, [
            'source_id' => $sourceId,
            'updated_at' => time(),
        ]);
        $ttl = $this->ttl();
        Redis::setex($key, $ttl, json_encode($state, JSON_UNESCAPED_SLASHES));
        Redis::sadd(self::INDEX_PREFIX . $node->id, $hash);
        Redis::expire(self::INDEX_PREFIX . $node->id, $ttl * 2);

        $this->refreshLegacyAggregate($node);
    }

    public function clear(Server $node, string $sourceId): void
    {
        $hash = substr(hash('sha256', $sourceId), 0, 24);
        Redis::del(self::SOURCE_PREFIX . $node->id . ':' . $hash);
        Redis::srem(self::INDEX_PREFIX . $node->id, $hash);
        $this->refreshLegacyAggregate($node);
    }

    public function states(Server|int $node): array
    {
        $nodeId = $node instanceof Server ? (int) $node->id : $node;
        $states = [];
        foreach (Redis::smembers(self::INDEX_PREFIX . $nodeId) as $hash) {
            $encoded = Redis::get(self::SOURCE_PREFIX . $nodeId . ':' . $hash);
            if (!$encoded) {
                Redis::srem(self::INDEX_PREFIX . $nodeId, $hash);
                continue;
            }
            $state = json_decode($encoded, true);
            if (is_array($state)) {
                $states[] = $state;
            }
        }
        return $states;
    }

    private function refreshLegacyAggregate(Server $node): void
    {
        $states = $this->states($node);
        $type = strtoupper($node->type);
        $nodeId = $node->id;
        $onlineUserIds = [];
        $activeConnections = 0;
        $totalConnections = 0;
        $metricsSources = [];
        $loadSources = [];
        $lastSeen = 0;

        foreach ($states as $state) {
            $lastSeen = max($lastSeen, (int) ($state['updated_at'] ?? 0));
            foreach (($state['online'] ?? []) as $userId => $count) {
                if ((int) $count > 0) {
                    $onlineUserIds[(int) $userId] = true;
                    $activeConnections += (int) $count;
                }
            }
            $metrics = $state['metrics'] ?? [];
            $activeConnections += empty($state['online']) ? (int) ($metrics['active_connections'] ?? 0) : 0;
            $totalConnections += (int) ($metrics['total_connections'] ?? 0);
            if ($metrics !== []) {
                $metricsSources[] = [
                    'source_id' => $state['source_id'] ?? 'unknown',
                    ...$metrics,
                ];
            }
            if (!empty($state['status'])) {
                $loadSources[] = [
                    'source_id' => $state['source_id'] ?? 'unknown',
                    ...$state['status'],
                ];
            }
        }

        $ttl = $this->ttl();
        Cache::put(CacheKey::get("SERVER_{$type}_ONLINE_USER", $nodeId), count($onlineUserIds), $ttl);
        Cache::put(CacheKey::get("SERVER_{$type}_LAST_CHECK_AT", $nodeId), $lastSeen ?: null, $ttl);
        Cache::put(CacheKey::get("SERVER_{$type}_LOAD_STATUS", $nodeId), [
            'sources' => $loadSources,
            'updated_at' => $lastSeen,
        ], $ttl);
        Cache::put(CacheKey::get("SERVER_{$type}_METRICS", $nodeId), [
            'active_connections' => $activeConnections,
            'total_connections' => $totalConnections,
            'active_users' => count($onlineUserIds),
            'sources' => $metricsSources,
            'updated_at' => $lastSeen,
        ], $ttl);
    }

    private function ttl(): int
    {
        return max(300, (int) admin_setting('server_push_interval', 60) * 3);
    }
}
