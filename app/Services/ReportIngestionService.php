<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class ReportIngestionService
{
    private const RECEIPT_TTL = 86400;

    public function claim(int $nodeId, string $sourceId, ?string $reportId): bool
    {
        if ($reportId === null || $reportId === '') {
            return true;
        }

        $key = 'node:report_receipt:' . $nodeId . ':'
            . substr(hash('sha256', $sourceId), 0, 24) . ':' . $reportId;

        // Illuminate's PhpRedisConnection::set() accepts the expiry and NX flag
        // as separate arguments. Passing the native PhpRedis options array here
        // makes Laravel treat that array as an array key and throws a TypeError.
        return (bool) Redis::set($key, '1', 'EX', self::RECEIPT_TTL, 'NX');
    }

    public function release(int $nodeId, string $sourceId, ?string $reportId): void
    {
        if ($reportId === null || $reportId === '') {
            return;
        }
        Redis::del('node:report_receipt:' . $nodeId . ':'
            . substr(hash('sha256', $sourceId), 0, 24) . ':' . $reportId);
    }
}
