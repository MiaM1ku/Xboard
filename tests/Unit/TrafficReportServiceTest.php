<?php

namespace Tests\Unit;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Plugin\TrafficReport\Services\TrafficReportService;
use ReflectionMethod;

class TrafficReportServiceTest extends TestCase
{
    public function test_profile_traffic_is_reported_as_an_independent_node_without_double_counting(): void
    {
        $entries = $this->invoke('splitTrafficCandidates',
            collect([
                (object) ['server_id' => 1, 'total_traffic' => 20 * 1024 ** 3],
                (object) ['server_id' => 2, 'total_traffic' => 10 * 1024 ** 3],
            ]),
            collect([
                (object) ['server_id' => 1, 'route_profile_id' => 5, 'total_traffic' => 6 * 1024 ** 3],
            ]),
            collect([1 => 'Physical Node', 2 => 'Second Node']),
            collect([5 => 'Exit Node'])
        )->keyBy('label');

        self::assertSame(14 * 1024 ** 3, $entries['Physical Node']->total_traffic);
        self::assertSame(10 * 1024 ** 3, $entries['Second Node']->total_traffic);
        self::assertSame(6 * 1024 ** 3, $entries['Exit Node']->total_traffic);
        self::assertSame(30 * 1024 ** 3, $entries->sum('total_traffic'));
    }

    public function test_standard_markdown_output_does_not_escape_domain_dots_or_wrap_traffic_in_code(): void
    {
        $bytes = (int) (14.79 * 1024 ** 3);
        $output = $this->invoke('formatReportLines',
            '## 用户流量排行 (Top 5)',
            collect([(object) ['label' => 'shimeng@nyaproxy.xyz', 'total_traffic' => $bytes]])
        );

        self::assertSame(
            "## 用户流量排行 (Top 5)\n1. shimeng@nyaproxy.xyz: 14.79 GB",
            $output
        );
        self::assertStringNotContainsString('nyaproxy\\.xyz', $output);
        self::assertStringNotContainsString('`14.79 GB`', $output);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(TrafficReportService::class, $method);

        return $reflection->invoke(new TrafficReportService(), ...$arguments);
    }
}
