<?php

namespace App\Console\Commands;

use App\Models\OutboundTemplate;
use App\Models\Server;
use App\Models\ServerMachineBinding;
use App\Models\ServerRouteProfile;
use App\Services\NodeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class MergeRealityNodes extends Command
{
    protected $signature = 'xboard:merge-reality-nodes
        {primary=183 : 保留为唯一入站的节点 ID}
        {secondaries=185,186,187 : 以逗号分隔、要迁移为 outbound 档案的节点 ID}
        {--port= : 合并后的公网端口；默认使用主节点 server_port}
        {--apply : 实际执行；不传时仅做检查和预览}
        {--force-old-agent : 即使机器没有上报 route-profiles-v1 能力也执行（危险）}';

    protected $description = '将多个 VLESS REALITY 端口安全合并为单入站和多个身份 outbound 档案';

    public function handle(): int
    {
        try {
            $primaryId = (int) $this->argument('primary');
            $secondaryIds = collect(explode(',', (string) $this->argument('secondaries')))
                ->map(fn ($id) => (int) trim($id))
                ->filter()
                ->unique()
                ->values();
            if ($primaryId < 1 || $secondaryIds->isEmpty() || $secondaryIds->contains($primaryId)) {
                throw new RuntimeException('主节点和从节点 ID 无效或发生重复');
            }

            $allIds = collect([$primaryId])->concat($secondaryIds)->values();
            $servers = Server::with(['machines', 'outboundTemplates', 'routeProfiles'])
                ->whereIn('id', $allIds->all())
                ->get()
                ->keyBy('id');
            if ($servers->count() !== $allIds->count()) {
                $missing = $allIds->diff($servers->keys())->implode(', ');
                throw new RuntimeException("找不到节点：{$missing}");
            }

            /** @var Server $primary */
            $primary = $servers->get($primaryId);
            $this->assertReality($primary, '主节点');
            $port = (int) ($this->option('port') ?: $primary->server_port ?: $primary->port);
            if ($port < 1 || $port > 65535) {
                throw new RuntimeException('合并端口必须介于 1 到 65535');
            }

            $primaryMachineIds = $this->servingMachineIds($primary);
            if ($primaryMachineIds === []) {
                throw new RuntimeException('主节点没有 active/draining 机器绑定');
            }
            $this->assertAgentCapability($primary);

            $plans = [];
            foreach ($secondaryIds as $sort => $secondaryId) {
                /** @var Server $secondary */
                $secondary = $servers->get($secondaryId);
                $this->assertReality($secondary, "节点 {$secondaryId}");
                if ((string) $secondary->host !== (string) $primary->host) {
                    throw new RuntimeException("节点 {$secondaryId} 与主节点不在同一 host");
                }
                if ($this->servingMachineIds($secondary) !== $primaryMachineIds) {
                    throw new RuntimeException("节点 {$secondaryId} 与主节点的机器绑定不一致");
                }
                $plans[] = $this->outboundPlan($primary, $secondary, $sort);
            }

            $this->table(
                ['旧节点', '旧端口', '合并端口', '档案', 'outbound', '目标'],
                collect($plans)->map(fn ($plan) => [
                    $plan['server']->id,
                    $plan['server']->server_port,
                    $port,
                    $plan['profile_name'],
                    $plan['tag'],
                    $plan['settings']['server'] . ':' . $plan['settings']['server_port'],
                ])->all()
            );
            $this->line("原节点 {$primaryId} 保留为直连档案；三个派生 UUID 共用 {$primary->host}:{$port}。");

            if (!$this->option('apply')) {
                $this->warn('仅完成预检，数据库未修改。确认 Agent 能力后加 --apply 执行。');
                return self::SUCCESS;
            }

            $backupPath = $this->writeBackup($servers, $primary);
            DB::transaction(function () use ($primary, $plans, $port): void {
                $attachments = [];
                foreach ($plans as $plan) {
                    $template = OutboundTemplate::query()->firstOrNew([
                        'name' => $plan['template_name'],
                    ]);
                    $template->fill([
                        'protocol' => OutboundTemplate::PROTOCOL_SOCKS5,
                        'settings' => $plan['settings'],
                        'enabled' => true,
                    ])->save();

                    $attachments[$template->id] = [
                        'tag' => $plan['tag'],
                        'sort' => $plan['sort'],
                        'enabled' => true,
                    ];
                    ServerRouteProfile::query()->updateOrCreate(
                        ['server_id' => $primary->id, 'name' => $plan['profile_name']],
                        [
                            'outbound_template_id' => $template->id,
                            'sort' => $plan['sort'],
                            'enabled' => true,
                        ]
                    );

                    $plan['server']->forceFill(['enabled' => false, 'show' => false])->save();
                }

                $primary->outboundTemplates()->syncWithoutDetaching($attachments);
                $primary->forceFill([
                    'port' => (string) $port,
                    'server_port' => $port,
                    'enabled' => true,
                    'show' => true,
                    'config_version' => max(1, (int) $primary->config_version) + 1,
                ])->save();
            });

            NodeSyncService::notifyFullSync($primary->id);
            foreach ($primaryMachineIds as $machineId) {
                NodeSyncService::notifyMachineNodesChanged($machineId);
            }

            $this->info("已合并到节点 {$primaryId} 的端口 {$port}。回滚快照：{$backupPath}");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function assertReality(Server $server, string $label): void
    {
        if ($server->type !== Server::TYPE_VLESS || (int) data_get($server->protocol_settings, 'tls') !== 2) {
            throw new RuntimeException("{$label} 必须是 VLESS + REALITY");
        }
    }

    private function assertAgentCapability(Server $server): void
    {
        if ($this->option('force-old-agent')) {
            $this->warn('已跳过 Agent 能力保护；旧 Agent 可能无法启动此配置。');
            return;
        }

        foreach ($server->machines as $machine) {
            if (!in_array($machine->pivot->state, [
                ServerMachineBinding::STATE_ACTIVE,
                ServerMachineBinding::STATE_DRAINING,
            ], true)) {
                continue;
            }
            if (!in_array('route-profiles-v1', $machine->capabilities ?? [], true)) {
                throw new RuntimeException(
                    "机器 {$machine->id} 尚未上报 route-profiles-v1；请先升级 Xboard-Node，确认心跳后再合并"
                );
            }
        }
    }

    private function servingMachineIds(Server $server): array
    {
        return $server->machines
            ->filter(fn ($machine) => in_array($machine->pivot->state, [
                ServerMachineBinding::STATE_ACTIVE,
                ServerMachineBinding::STATE_DRAINING,
            ], true))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    private function outboundPlan(Server $primary, Server $secondary, int $sort): array
    {
        $outbounds = collect($secondary->custom_outbounds ?? []);
        if ($outbounds->count() !== 1) {
            throw new RuntimeException("节点 {$secondary->id} 必须且只能有一个 SOCKS outbound");
        }
        $outbound = $outbounds->first();
        $protocol = strtolower((string) ($outbound['protocol'] ?? ''));
        $settings = $outbound['settings'] ?? [];
        $tag = (string) ($outbound['tag'] ?? '');
        if (!in_array($protocol, ['socks', 'socks5'], true) || $tag === '') {
            throw new RuntimeException("节点 {$secondary->id} 的 outbound 不是有效 SOCKS5 配置");
        }
        if (empty($settings['server']) || empty($settings['server_port'])) {
            throw new RuntimeException("节点 {$secondary->id} 的 SOCKS5 地址或端口为空");
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $tag)) {
            throw new RuntimeException("节点 {$secondary->id} 的 outbound tag 不合法：{$tag}");
        }

        $routes = collect($secondary->custom_routes ?? []);
        $hasCatchAll = $routes->contains(fn ($route) =>
            ($route['action'] ?? null) === 'route'
            && ($route['outbound'] ?? null) === $tag
            && empty($route['match'])
        );
        if (!$hasCatchAll) {
            throw new RuntimeException("节点 {$secondary->id} 没有指向 {$tag} 的全量路由");
        }

        return [
            'server' => $secondary,
            'sort' => $sort,
            'tag' => $tag,
            'profile_name' => $secondary->name,
            'template_name' => "[REALITY {$primary->id}] {$secondary->name}",
            'settings' => array_filter([
                'server' => (string) $settings['server'],
                'server_port' => (int) $settings['server_port'],
                'username' => $settings['username'] ?? null,
                'password' => $settings['password'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
        ];
    }

    private function writeBackup($servers, Server $primary): string
    {
        $directory = storage_path('backup/reality-merge');
        File::ensureDirectoryExists($directory, 0700, true);
        $path = $directory . '/' . now()->format('Y-m-d_H-i-s') . "-primary-{$primary->id}.json";
        $payload = [
            'created_at' => now()->toIso8601String(),
            'servers' => $servers->map(fn (Server $server) => $server->getAttributes())->values()->all(),
            'primary_outbounds' => $primary->outboundTemplates->map(fn ($template) => [
                'attributes' => $template->getAttributes(),
                'pivot' => $template->pivot->getAttributes(),
            ])->values()->all(),
            'primary_profiles' => $primary->routeProfiles->map->getAttributes()->values()->all(),
        ];
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        return $path;
    }
}
