<?php

namespace Plugin\TrafficReport\Services;

use App\Models\Server;
use App\Models\ServerRouteProfile;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use App\Models\UserNodeTraffic;
use App\Services\Plugin\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class TrafficReportService
{
    public function send(Command $command, string $title, int $startAt, int $endAt): int
    {
        $url = $this->config('webhook_url', '');
        if (blank($url)) {
            $command->info('Traffic report webhook URL is not set.');
            return Command::SUCCESS;
        }

        $parts = [
            $title,
            $this->buildNodeReport($startAt, $endAt),
            $this->buildUserReport($startAt, $endAt),
        ];

        return $this->sendReport(
            $command,
            $url,
            $this->config('authorization_header', ''),
            implode("\n\n", $parts)
        );
    }

    private function buildNodeReport(int $startAt, int $endAt): string
    {
        $nodeStats = StatServer::query()->where('record_type', 'd')
            ->whereBetween('record_at', [$startAt, $endAt])
            ->selectRaw('server_id, sum(u + d) as total_traffic')
            ->groupBy('server_id')->get();
        $nodeLabels = Server::query()
            ->whereIn('id', $nodeStats->pluck('server_id')->filter()->all())
            ->pluck('name', 'id');

        $profileStats = collect();
        $profileLabels = collect();
        if (Schema::hasTable('v2_user_node_traffic')) {
            $profileStats = UserNodeTraffic::query()->where('record_type', 'd')
                ->whereBetween('record_at', [$startAt, $endAt])
                ->where('profile_uuid', '<>', '')
                ->selectRaw('server_id, route_profile_id, sum(u + d) as total_traffic')
                ->groupBy('server_id', 'route_profile_id')->get();
            $profileLabels = ServerRouteProfile::query()
                ->whereIn('id', $profileStats->pluck('route_profile_id')->filter()->all())
                ->pluck('name', 'id');
        }

        $entries = $this->splitTrafficCandidates(
            $nodeStats,
            $profileStats,
            $nodeLabels,
            $profileLabels
        )->sortByDesc('total_traffic')->take($this->topLimit())->values();

        return $this->formatReportLines(
            sprintf('## 节点流量排行 (Top %d)', $this->topLimit()),
            $entries
        );
    }

    private function buildUserReport(int $startAt, int $endAt): string
    {
        $stats = StatUser::query()->where('record_type', 'd')
            ->whereBetween('record_at', [$startAt, $endAt])
            ->selectRaw('user_id, sum(u + d) as total_traffic')
            ->groupBy('user_id')->orderByDesc('total_traffic')
            ->limit($this->topLimit())->get();
        if ($stats->isEmpty()) {
            return sprintf("## 用户流量排行 (Top %d)\n- 暂无数据", $this->topLimit());
        }

        $userIds = $stats->pluck('user_id')->filter()->all();
        $emails = User::query()->whereIn('id', $userIds)->pluck('email', 'id');
        $nodeTotals = collect();
        $profileTotals = collect();
        if (Schema::hasTable('v2_user_node_traffic')) {
            $nodeTotals = UserNodeTraffic::query()->whereIn('user_id', $userIds)
                ->where('record_type', 'd')->whereBetween('record_at', [$startAt, $endAt])
                ->where('profile_uuid', '')
                ->selectRaw('user_id, server_id, sum(u + d) as total_traffic')
                ->groupBy('user_id', 'server_id')->get();
            $profileTotals = UserNodeTraffic::query()->whereIn('user_id', $userIds)
                ->where('record_type', 'd')->whereBetween('record_at', [$startAt, $endAt])
                ->where('profile_uuid', '<>', '')
                ->selectRaw('user_id, server_id, route_profile_id, sum(u + d) as total_traffic')
                ->groupBy('user_id', 'server_id', 'route_profile_id')->get();
        }

        $servers = Server::query()->whereIn('id', $nodeTotals->pluck('server_id')->filter()->all())
            ->pluck('name', 'id');
        $profiles = ServerRouteProfile::query()
            ->whereIn('id', $profileTotals->pluck('route_profile_id')->filter()->all())
            ->pluck('name', 'id');

        $lines = $stats->values()->map(function ($stat, $index) use (
            $emails, $nodeTotals, $profileTotals, $servers, $profiles
        ) {
            $uid = (int) $stat->user_id;
            $topNode = $this->splitTrafficCandidates(
                $nodeTotals->where('user_id', $uid),
                $profileTotals->where('user_id', $uid),
                $servers,
                $profiles
            )->sortByDesc('total_traffic')->first();
            $suffix = $topNode
                ? sprintf(' · 最高节点 %s %s',
                    $this->escapeMarkdown($topNode->label),
                    $this->formatBytes((int) $topNode->total_traffic))
                : '';
            return sprintf('%d. %s: %s%s', $index + 1,
                $this->escapeMarkdown($emails->get($uid, 'Unknown User ('.$uid.')')),
                $this->formatBytes((int) $stat->total_traffic),
                $suffix);
        })->implode("\n");

        return sprintf("## 用户流量排行 (Top %d)\n%s", $this->topLimit(), $lines);
    }

    /**
     * Profile traffic is a subset of its physical node total. Subtract it from
     * the physical node and add the profile back as its own reportable node so
     * the same bytes are not shown once as a node and again as an extra exit.
     */
    private function splitTrafficCandidates(
        Collection $nodeStats,
        Collection $profileStats,
        Collection $nodeLabels,
        Collection $profileLabels
    ): Collection {
        $profileTrafficByServer = $profileStats->groupBy('server_id')
            ->map(fn (Collection $items) => (int) $items->sum('total_traffic'));

        $nodes = $nodeStats->map(function ($stat) use ($profileTrafficByServer, $nodeLabels) {
            $serverId = (int) $stat->server_id;
            return (object) [
                'label' => $nodeLabels->get($serverId, sprintf('Unknown Server (%d)', $serverId)),
                'total_traffic' => max(
                    0,
                    (int) $stat->total_traffic - (int) $profileTrafficByServer->get($serverId, 0)
                ),
            ];
        });

        $profiles = $profileStats->map(function ($stat) use ($profileLabels) {
            $profileId = (int) $stat->route_profile_id;
            return (object) [
                'label' => $profileLabels->get($profileId, sprintf('Unknown Profile (%d)', $profileId)),
                'total_traffic' => (int) $stat->total_traffic,
            ];
        });

        return $nodes->concat($profiles)
            ->filter(fn ($entry) => $entry->total_traffic > 0)
            ->values();
    }

    private function formatReportLines(string $title, Collection $entries): string
    {
        if ($entries->isEmpty()) return "{$title}\n- 暂无数据";
        $lines = $entries->values()->map(function ($entry, $index) {
            return sprintf('%d. %s: %s', $index + 1, $this->escapeMarkdown($entry->label),
                $this->formatBytes((int) $entry->total_traffic));
        })->implode("\n");
        return "{$title}\n{$lines}";
    }

    private function sendReport(Command $command, string $url, ?string $authorization, string $message): int
    {
        $headers = ['Content-Type' => 'application/json'];
        if (filled($authorization)) $headers['Authorization'] = $authorization;
        try {
            $response = Http::withHeaders($headers)->post($url, ['message' => $message]);
            if ($response->successful()) {
                $command->info('Report sent successfully.');
                return Command::SUCCESS;
            }
            $command->error('Failed to send report: '.$response->body());
        } catch (\Throwable $e) {
            $command->error('Exception sending report: '.$e->getMessage());
        }
        return Command::FAILURE;
    }

    private function config(string $key, mixed $default = null): mixed
    {
        $plugin = app(PluginManager::class)->getEnabledPlugins()['traffic_report'] ?? null;
        $value = $plugin ? $plugin->getConfig($key, $default) : $default;
        if (filled($value)) return $value;
        return match ($key) {
            'webhook_url' => admin_setting('daily_report_url', $default),
            'authorization_header' => admin_setting('daily_report_header_authorization', $default),
            default => $value,
        };
    }

    private function topLimit(): int { return max(1, (int) $this->config('top_limit', 5)); }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = min((int) floor(($bytes ? log($bytes) : 0) / log(1024)), count($units) - 1);
        return round($bytes / pow(1024, $pow), $precision).' '.$units[$pow];
    }

    private function escapeMarkdown(string $value): string
    {
        return str_replace(
            ['\\', '`', '*', '_', '[', ']'],
            ['\\\\', '\`', '\*', '\_', '\[', '\]'],
            $value
        );
    }
}
