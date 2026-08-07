<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Services\ServerService;
use App\Services\ServerMachineBindingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManageController extends Controller
{
    public function getNodes(Request $request)
    {
        $servers = ServerService::getAllServers();
        $servers->load(['outboundTemplates', 'routeProfiles.outboundTemplate']);

        $servers = $servers->map(function ($item) {
            $activeOutbounds = $item->outboundTemplates
                ->filter(fn ($template) => (bool) $template->enabled && (bool) $template->pivot->enabled)
                ->values();
            $activeProfiles = $item->routeProfiles
                ->filter(fn ($profile) => (bool) $profile->enabled)
                ->values();

            $item['groups'] = ServerGroup::whereIn('id', $item['group_ids'] ?? [])->get(['name', 'id']);
            $item['parent'] = $item->parent;
            $item['machine_ids'] = $item->machines->pluck('id')->map(fn ($id) => (int) $id)->values();
			$item['connection_methods'] = $item->parent_id ? ['parent'] : ['node_id', 'machine'];
			$item['connection_node_id'] = $item->code ?: $item->id;
            $item['outbound_count'] = $activeOutbounds->count();
            $item['outbound_names'] = $activeOutbounds->pluck('name')->values();
            $item['route_profile_count'] = $activeProfiles->count();
            $item['route_profile_names'] = $activeProfiles->pluck('name')->values();
            $item['machine_bindings'] = $item->machines->map(fn ($machine) => [
                'machine_id' => (int) $machine->id,
                'state' => $machine->pivot->state,
                'desired_config_version' => (int) $machine->pivot->desired_config_version,
                'applied_config_version' => $machine->pivot->applied_config_version !== null
                    ? (int) $machine->pivot->applied_config_version
                    : null,
                'last_error' => $machine->pivot->last_error,
                'last_seen_at' => $machine->pivot->last_seen_at,
            ])->values();
            $item->unsetRelation('outboundTemplates');
            $item->unsetRelation('routeProfiles');
            return $item;
        });
        return $this->success($servers);
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '1m');
        $params = $request->validate([
            '*.id' => 'numeric',
            '*.order' => 'numeric'
        ]);

        try {
            DB::beginTransaction();
            collect($params)->each(function ($item) {
                if (isset($item['id']) && isset($item['order'])) {
                    Server::where('id', $item['id'])->update(['sort' => $item['order']]);
                }
            });
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);

        }
        return $this->success(true);
    }

    public function save(ServerSave $request)
    {
        $params = $request->validated();
        $params = $this->normalizeParent($params, $request->integer('id') ?: null);
        $isChild = !empty($params['parent_id']);
        if ($request->input('id')) {
            $server = Server::find($request->input('id'));
            if (!$server) {
                return $this->fail([400202, '服务器不存在']);
            }
			$bindings = $isChild ? [] : $this->extractBindings($params, $request, $server);
            try {
                DB::transaction(function () use ($server, $params, $bindings): void {
                    $server->update($params);
                    if ($bindings !== null) {
                        ServerMachineBindingService::sync($server, $bindings);
                    }
                });
                return $this->success(true);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }

		$bindings = $isChild ? [] : $this->extractBindings($params, $request);
        try {
            DB::transaction(function () use ($params, $bindings): void {
                $server = Server::create($params);
                ServerMachineBindingService::sync($server, $bindings ?? []);
            });
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '创建失败']);
        }
    }

    private function normalizeParent(array $params, ?int $serverId): array
    {
        $parentId = (int) ($params['parent_id'] ?? 0);
        if ($parentId <= 0) {
            $params['parent_id'] = null;
            return $params;
        }

        if ($serverId !== null && $parentId === $serverId) {
            throw new ApiException('节点不能将自己设为父节点');
        }

        $parent = Server::find($parentId);
        if (!$parent) {
            throw new ApiException('父节点不存在');
        }
        if (!empty($parent->parent_id)) {
            throw new ApiException('父节点必须是实际后端节点，不能继续嵌套');
        }
        if ($parent->type !== ($params['type'] ?? null)) {
            throw new ApiException('父节点与当前节点的协议类型必须一致');
        }

        $params['parent_id'] = $parentId;
        $params['machine_id'] = null;
        return $params;
    }

    public function update(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer',
            'show' => 'nullable|integer',
            'machine_id' => 'nullable|integer',
            'machine_ids' => 'nullable|array',
            'machine_ids.*' => 'integer|distinct|exists:v2_server_machine,id',
            'machine_bindings' => 'nullable|array',
            'machine_bindings.*.machine_id' => 'required|integer|distinct|exists:v2_server_machine,id',
            'machine_bindings.*.state' => 'nullable|string|in:active,draining,disabled',
            'enabled' => 'nullable|boolean',
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        if (array_key_exists('show', $params)) {
            $server->show = (int) $params['show'];
        }
        $bindings = $this->extractBindings($params, $request, $server);
        if (array_key_exists('enabled', $params)) {
            $server->enabled = (bool) $params['enabled'];
        }

        if (!$server->save()) {
            return $this->fail([500, '保存失败']);
        }

        if ($bindings !== null) {
            ServerMachineBindingService::sync($server, $bindings);
        }

        return $this->success(true);
    }

    /**
     * 删除
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);
        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }
        if ($server->delete() === false) {
            return $this->fail([500, '删除失败']);
        }

        return $this->success(true);
    }

    /**
     * 批量删除节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要删除的节点']);
        }

        try {
            $deleted = Server::whereIn('id', $ids)->delete();
            if ($deleted === false) {
                return $this->fail([500, '批量删除失败']);
            }
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量删除失败']);
        }
    }

    /**
     * 重置节点流量
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetTraffic(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        try {
            $server->u = 0;
            $server->d = 0;
            $server->save();
            
            Log::info("Server {$server->id} ({$server->name}) traffic reset by admin");
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '重置失败']);
        }
    }

    /**
     * 批量重置节点流量
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchResetTraffic(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要重置的节点']);
        }

        try {
            Server::whereIn('id', $ids)->update([
                'u' => 0,
                'd' => 0,
            ]);
            
            Log::info("Servers " . implode(',', $ids) . " traffic reset by admin");
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量重置失败']);
        }
    }

    /**
     * 批量更新节点属性（show等）
     */
    public function batchUpdate(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'show' => 'nullable|integer|in:0,1',
            'enabled' => 'nullable|boolean',
            'machine_id' => 'nullable|integer',
            'machine_ids' => 'nullable|array',
            'machine_ids.*' => 'integer|distinct|exists:v2_server_machine,id',
        ]);

        $ids = $params['ids'];
        if (empty($ids)) {
            return $this->fail([400, '请选择要更新的节点']);
        }

        $update = [];
        if (array_key_exists('show', $params) && $params['show'] !== null) {
            $update['show'] = (int) $params['show'];
        }
        if (array_key_exists('enabled', $params) && $params['enabled'] !== null) {
            $update['enabled'] = (bool) $params['enabled'];
        }
        $bindings = null;
        if (array_key_exists('machine_ids', $params)) {
            $bindings = $params['machine_ids'];
            unset($params['machine_ids']);
        } elseif (array_key_exists('machine_id', $params)) {
            $bindings = $params['machine_id'] ? [(int) $params['machine_id']] : [];
        }

        if (empty($update)) {
            return $this->fail([400, '没有可更新的字段']);
        }

        try {
            $servers = Server::whereIn('id', $ids)->get();
            DB::transaction(function () use ($servers, $update, $bindings) {
                /** @var Server $server */
                foreach ($servers as $server) {
                    $server->update($update);
                    if ($bindings !== null) {
                        ServerMachineBindingService::sync($server, $bindings);
                    }
                }
            });
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量更新失败']);
        }
    }

    /**
     * 复制节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function copy(Request $request)
    {
        $server = Server::find($request->input('id'));
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        $copiedServer = $server->replicate();
        $copiedServer->show = 0;
        $copiedServer->code = null;
        $copiedServer->u = 0;
        $copiedServer->d = 0;
        $copiedServer->save();

        return $this->success(true);
    }

    /**
     * Generate ECH (Encrypted Client Hello) key pair.
     * Returns PEM-encoded ECH key (server-side) and ECH config (client-side).
     */
    public function generateEchKey(Request $request)
    {
        $publicName = $request->input('public_name', 'ech.example.com');
        if (strlen($publicName) < 1 || strlen($publicName) > 253) {
            throw new ApiException('public_name must be a valid domain (1-253 bytes)');
        }

        // Generate X25519 key pair
        $privateKey = random_bytes(32);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);

        $configId = random_int(0, 255);

        // Build ECHConfigContents (draft-ietf-tls-esni-18)
        $contents = '';
        $contents .= pack('C', $configId);                // config_id
        $contents .= pack('n', 0x0020);                   // kem_id: DHKEM(X25519)
        $contents .= pack('n', 32) . $publicKey;          // public_key (length-prefixed)
        // cipher_suites: 2 suites × 4 bytes = 8 bytes
        $contents .= pack('n', 8);                        // cipher_suites byte length
        $contents .= pack('nn', 0x0001, 0x0001);          // HKDF-SHA256 + AES-128-GCM
        $contents .= pack('nn', 0x0001, 0x0003);          // HKDF-SHA256 + ChaCha20Poly1305
        $contents .= pack('C', 0);                        // max_name_length
        $contents .= pack('C', strlen($publicName)) . $publicName;
        $contents .= pack('n', 0);                        // extensions: empty

        // ECHConfig = version(2) + length(2) + contents
        $echConfig = pack('n', 0xfe0d) . pack('n', strlen($contents)) . $contents;

        // ECHConfigList = total_length(2) + configs
        $echConfigList = pack('n', strlen($echConfig)) . $echConfig;

        // ECH Keys = private_key_len(2) + key(32) + config_len(2) + config
        $echKeysPayload = pack('n', 32) . $privateKey . pack('n', strlen($echConfig)) . $echConfig;

        $keyPem = "-----BEGIN ECH KEYS-----\n"
            . chunk_split(base64_encode($echKeysPayload), 64, "\n")
            . "-----END ECH KEYS-----";

        $configPem = "-----BEGIN ECH CONFIGS-----\n"
            . chunk_split(base64_encode($echConfigList), 64, "\n")
            . "-----END ECH CONFIGS-----";

        return $this->success([
            'key' => $keyPem,
            'config' => $configPem,
        ]);
    }

    private function extractBindings(array &$params, Request $request, ?Server $server = null): ?array
    {
        $bindings = null;
        if ($request->has('machine_bindings')) {
            $bindings = $params['machine_bindings'] ?? [];
        } elseif ($request->has('machine_ids')) {
            $bindings = $params['machine_ids'] ?? [];
        } elseif ($request->has('machine_id')) {
			$machineId = !empty($params['machine_id']) ? (int) $params['machine_id'] : null;

			// The original admin dist only knows one machine_id. When it edits a
			// node that already has multiple bindings, it submits the mirrored
			// first machine again. Preserve the full binding set in that case;
			// selecting another machine or clearing the field remains intentional.
			if ($server
				&& $machineId !== null
				&& $machineId === (int) $server->machine_id
				&& $server->machineBindings()->count() > 1) {
				$bindings = null;
			} else {
				$bindings = $machineId !== null ? [$machineId] : [];
			}
        }

        unset($params['machine_bindings'], $params['machine_ids']);
        return $bindings;
    }
}
