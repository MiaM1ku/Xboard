<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

/**
 * Source-isolated online device snapshots.
 *
 * A source is one agent process on one machine. Replacing a snapshot never
 * mutates another source, so DNS-balanced backends can report the same logical
 * node safely. Aggregation deduplicates normalized IP addresses globally.
 */
class DeviceStateService
{
    private const SOURCE_PREFIX = 'device:source:';
    private const NODE_SOURCES_PREFIX = 'device:node_sources:';
    private const USER_SOURCES_PREFIX = 'device:user_sources:';
    private const TTL = 300;

    public function replaceSourceSnapshot(int $nodeId, string $sourceId, array $devices): array
    {
        $sourceHash = self::sourceHash($sourceId);
        $sourceKey = self::sourceKey($nodeId, $sourceHash);
        $old = Redis::hgetall($sourceKey);
        $normalized = [];

        foreach ($devices as $userId => $ips) {
            if (!is_numeric($userId) || !is_array($ips)) {
                continue;
            }
            $userId = (int) $userId;
            $clean = self::normalizeIPs($ips);
            if ($userId > 0 && $clean !== []) {
                $normalized[(string) $userId] = json_encode($clean, JSON_UNESCAPED_SLASHES);
            }
        }

        Redis::del($sourceKey);
        if ($normalized !== []) {
            Redis::hmset($sourceKey, $normalized);
            Redis::expire($sourceKey, self::TTL);
            Redis::sadd(self::nodeSourcesKey($nodeId), $sourceHash);
            Redis::expire(self::nodeSourcesKey($nodeId), self::TTL * 2);
        } else {
            Redis::srem(self::nodeSourcesKey($nodeId), $sourceHash);
        }

        $oldUserIds = array_map('intval', array_keys($old));
        $newUserIds = array_map('intval', array_keys($normalized));
        $reference = self::sourceReference($nodeId, $sourceHash);

        foreach (array_diff($oldUserIds, $newUserIds) as $userId) {
            Redis::srem(self::userSourcesKey($userId), $reference);
        }
        foreach ($newUserIds as $userId) {
            Redis::sadd(self::userSourcesKey($userId), $reference);
            Redis::expire(self::userSourcesKey($userId), self::TTL * 2);
        }

        $affected = array_values(array_unique([...$oldUserIds, ...$newUserIds]));
        foreach ($affected as $userId) {
            $this->notifyUpdate($userId);
        }

        return $affected;
    }

    /** Backward-compatible partial update used by legacy HTTP endpoints. */
    public function setDevices(int $userId, int $nodeId, array $ips, string $sourceId = 'legacy'): void
    {
        $sourceHash = self::sourceHash($sourceId);
        $sourceKey = self::sourceKey($nodeId, $sourceHash);
        $reference = self::sourceReference($nodeId, $sourceHash);
        $clean = self::normalizeIPs($ips);

        if ($clean === []) {
            Redis::hdel($sourceKey, (string) $userId);
            Redis::srem(self::userSourcesKey($userId), $reference);
        } else {
            Redis::hset($sourceKey, (string) $userId, json_encode($clean, JSON_UNESCAPED_SLASHES));
            Redis::expire($sourceKey, self::TTL);
            Redis::sadd(self::nodeSourcesKey($nodeId), $sourceHash);
            Redis::expire(self::nodeSourcesKey($nodeId), self::TTL * 2);
            Redis::sadd(self::userSourcesKey($userId), $reference);
            Redis::expire(self::userSourcesKey($userId), self::TTL * 2);
        }

        $this->notifyUpdate($userId);
    }

    public function getNodeDevices(int $nodeId, ?string $sourceId = null): array
    {
        $sourceHashes = $sourceId !== null
            ? [self::sourceHash($sourceId)]
            : Redis::smembers(self::nodeSourcesKey($nodeId));
        $result = [];

        foreach ($sourceHashes as $sourceHash) {
            $key = self::sourceKey($nodeId, $sourceHash);
            $snapshot = Redis::hgetall($key);
            if ($snapshot === []) {
                Redis::srem(self::nodeSourcesKey($nodeId), $sourceHash);
                continue;
            }
            foreach ($snapshot as $userId => $encoded) {
                $ips = json_decode($encoded, true);
                if (is_array($ips)) {
                    $result[(int) $userId] = array_values(array_unique([
                        ...($result[(int) $userId] ?? []),
                        ...$ips,
                    ]));
                }
            }
        }

        return $result;
    }

    public function removeNodeDevices(int $nodeId, int $userId, ?string $sourceId = null): void
    {
        $sourceHashes = $sourceId !== null
            ? [self::sourceHash($sourceId)]
            : Redis::smembers(self::nodeSourcesKey($nodeId));

        foreach ($sourceHashes as $sourceHash) {
            Redis::hdel(self::sourceKey($nodeId, $sourceHash), (string) $userId);
            Redis::srem(self::userSourcesKey($userId), self::sourceReference($nodeId, $sourceHash));
        }
        $this->notifyUpdate($userId);
    }

    public function clearSource(int $nodeId, string $sourceId): array
    {
        $sourceHash = self::sourceHash($sourceId);
        $sourceKey = self::sourceKey($nodeId, $sourceHash);
        $userIds = array_map('intval', Redis::hkeys($sourceKey));
        Redis::del($sourceKey);
        Redis::srem(self::nodeSourcesKey($nodeId), $sourceHash);

        foreach ($userIds as $userId) {
            Redis::srem(self::userSourcesKey($userId), self::sourceReference($nodeId, $sourceHash));
            $this->notifyUpdate($userId);
        }
        return $userIds;
    }

    /** Clear every source for a node; reserved for destructive node deletion. */
    public function clearAllNodeDevices(int $nodeId): array
    {
        $affected = [];
        foreach (Redis::smembers(self::nodeSourcesKey($nodeId)) as $sourceHash) {
            $sourceKey = self::sourceKey($nodeId, $sourceHash);
            $userIds = array_map('intval', Redis::hkeys($sourceKey));
            $affected = [...$affected, ...$userIds];
            Redis::del($sourceKey);
            foreach ($userIds as $userId) {
                Redis::srem(self::userSourcesKey($userId), self::sourceReference($nodeId, $sourceHash));
            }
        }
        Redis::del(self::nodeSourcesKey($nodeId));

        $affected = array_values(array_unique($affected));
        foreach ($affected as $userId) {
            $this->notifyUpdate($userId);
        }
        return $affected;
    }

    public function getDeviceCount(int $userId): int
    {
        return count($this->getUserDevices($userId));
    }

    public function getAliveList(Collection $users): array
    {
        $result = [];
        foreach ($users as $user) {
            $count = $this->getDeviceCount((int) $user->id);
            if ($count > 0) {
                $result[(int) $user->id] = $count;
            }
        }
        return $result;
    }

    public function getUsersDevices(array $userIds): array
    {
        $result = [];
        foreach ($userIds as $userId) {
            $ips = $this->getUserDevices((int) $userId);
            if ($ips !== []) {
                $result[(int) $userId] = $ips;
            }
        }
        return $result;
    }

    public function getUserDevices(int $userId): array
    {
        $ips = [];
        $userSourcesKey = self::userSourcesKey($userId);
        foreach (Redis::smembers($userSourcesKey) as $reference) {
            [$nodeId, $sourceHash] = array_pad(explode(':', $reference, 2), 2, null);
            if ($nodeId === null || $sourceHash === null) {
                Redis::srem($userSourcesKey, $reference);
                continue;
            }
            $encoded = Redis::hget(self::sourceKey((int) $nodeId, $sourceHash), (string) $userId);
            if (!$encoded) {
                Redis::srem($userSourcesKey, $reference);
                continue;
            }
            $sourceIps = json_decode($encoded, true);
            if (is_array($sourceIps)) {
                $ips = [...$ips, ...$sourceIps];
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * Return the current source-isolated device snapshot grouped by node.
     * This lets the admin distinguish "authorized nodes" from nodes the user
     * is actually connected to right now.
     *
     * @return array<int, array<int, string>>
     */
    public function getUserNodeDevices(int $userId): array
    {
        $result = [];
        $userSourcesKey = self::userSourcesKey($userId);
        foreach (Redis::smembers($userSourcesKey) as $reference) {
            [$nodeId, $sourceHash] = array_pad(explode(':', $reference, 2), 2, null);
            if ($nodeId === null || $sourceHash === null) {
                Redis::srem($userSourcesKey, $reference);
                continue;
            }
            $encoded = Redis::hget(self::sourceKey((int) $nodeId, $sourceHash), (string) $userId);
            if (!$encoded) {
                Redis::srem($userSourcesKey, $reference);
                continue;
            }
            $ips = json_decode($encoded, true);
            if (!is_array($ips)) {
                continue;
            }
            $result[(int) $nodeId] = array_values(array_unique([
                ...($result[(int) $nodeId] ?? []),
                ...self::normalizeIPs($ips),
            ]));
        }
        return $result;
    }

    public function notifyUpdate(int $userId): void
    {
        User::query()->whereKey($userId)->update([
            'online_count' => $this->getDeviceCount($userId),
            'last_online_at' => now(),
        ]);
    }

    public static function sourceId(?int $machineId, ?string $agentInstanceId): string
    {
        $machine = $machineId && $machineId > 0 ? "machine:{$machineId}" : 'standalone';
        $instance = trim((string) $agentInstanceId);
        return $machine . ':' . ($instance !== '' ? $instance : 'legacy');
    }

    private static function normalizeIPs(array $ips): array
    {
        $result = [];
        foreach ($ips as $ip) {
            if (!is_string($ip)) {
                continue;
            }
            $ip = trim($ip);
            if (preg_match('/^\[(.+)](?::\d+)?$/', $ip, $match)) {
                $ip = $match[1];
            } elseif (preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $ip, $match)) {
                $ip = $match[1];
            }
            if (str_starts_with(strtolower($ip), '::ffff:')) {
                $mapped = substr($ip, 7);
                if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ip = $mapped;
                }
            }
            $ip = preg_replace('/%.+$/', '', $ip) ?? $ip;
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $result[] = strtolower($ip);
            }
        }
        return array_values(array_unique($result));
    }

    private static function sourceHash(string $sourceId): string
    {
        return substr(hash('sha256', $sourceId), 0, 24);
    }

    private static function sourceKey(int $nodeId, string $sourceHash): string
    {
        return self::SOURCE_PREFIX . $nodeId . ':' . $sourceHash;
    }

    private static function nodeSourcesKey(int $nodeId): string
    {
        return self::NODE_SOURCES_PREFIX . $nodeId;
    }

    private static function userSourcesKey(int $userId): string
    {
        return self::USER_SOURCES_PREFIX . $userId;
    }

    private static function sourceReference(int $nodeId, string $sourceHash): string
    {
        return $nodeId . ':' . $sourceHash;
    }
}
