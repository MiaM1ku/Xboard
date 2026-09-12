<?php

namespace Tests\Unit;

use App\Services\NodeEntryTrafficService;
use PHPUnit\Framework\TestCase;

class NodeEntryTrafficServiceTest extends TestCase
{
    public function test_child_entry_traffic_is_split_from_the_parent(): void
    {
        $rows = collect((new NodeEntryTrafficService())->merge(
            [
                136 => ['id' => 136, 'upload' => 24, 'download' => 212, 'total' => 236],
            ],
            [
                138 => ['id' => 138, 'upload' => 5, 'download' => 34, 'total' => 39],
                137 => ['id' => 137, 'upload' => 1, 'download' => 19, 'total' => 20],
            ],
            [138 => 136, 137 => 136],
        ))->keyBy('id');

        self::assertSame(177, $rows[136]['total']);
        self::assertSame(18, $rows[136]['upload']);
        self::assertSame(159, $rows[136]['download']);
        self::assertSame(39, $rows[138]['total']);
        self::assertSame(20, $rows[137]['total']);
    }
}
