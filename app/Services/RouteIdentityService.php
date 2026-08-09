<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerRouteProfile;

class RouteIdentityService
{
    public const DOMAIN = 'xboard-route-profile:v1:';

    public function forUser(Server $server, object $user): array
    {
        if (!$this->supportsProfiles($server)) {
            return [];
        }

        $server->loadMissing(['routeProfiles.outboundTemplate', 'outboundTemplates']);
        return $server->routeProfiles
            ->where('enabled', true)
            ->map(function (ServerRouteProfile $profile) use ($server, $user): array {
                $outboundTag = 'direct';
                if ($profile->outbound_template_id !== null) {
                    $attachment = $server->outboundTemplates
                        ->firstWhere('id', $profile->outbound_template_id);
                    $outboundTag = $attachment?->pivot?->tag ?? '';
                }

                return [
                    'profile_id' => $profile->uuid,
                    'profile_name' => $profile->name,
                    'uuid' => self::deriveUUID((string) $user->uuid, (string) $profile->uuid),
                    'auth_user' => sprintf('xb:u:%d:rp:%s', (int) $user->id, $profile->uuid),
                    'outbound_tag' => $outboundTag,
                    'entry_server_ids' => $profile->entry_server_ids ?? [],
                ];
            })
            ->filter(fn (array $identity) => $identity['outbound_tag'] !== '')
            ->values()
            ->all();
    }

    public function supportsProfiles(Server $server): bool
    {
        if ($server->type === Server::TYPE_TROJAN) {
            return true;
        }

        if ($server->type === Server::TYPE_SHADOWSOCKS) {
            return true;
        }

        return $server->type === Server::TYPE_VLESS
            && (int) data_get($server->protocol_settings, 'tls') === 2;
    }

    public static function allowsEntry(array $identity, int $serverId): bool
    {
        $entryServerIds = collect($identity['entry_server_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique();

        return $entryServerIds->isEmpty() || $entryServerIds->contains($serverId);
    }

    public static function deriveUUID(string $baseUUID, string $profileUUID): string
    {
        $hex = str_replace('-', '', strtolower($baseUUID));
        $key = ctype_xdigit($hex) && strlen($hex) === 32 ? hex2bin($hex) : $baseUUID;
        $bytes = substr(hash_hmac('sha256', self::DOMAIN . strtolower($profileUUID), $key, true), 0, 16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $derived = bin2hex($bytes);

        return substr($derived, 0, 8) . '-'
            . substr($derived, 8, 4) . '-'
            . substr($derived, 12, 4) . '-'
            . substr($derived, 16, 4) . '-'
            . substr($derived, 20, 12);
    }
}
