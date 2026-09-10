<?php

namespace App\Services;

use App\Models\Server;

class ChildIdentityService
{
    public const DOMAIN = 'xboard-child-node:v1:';
    public const CHILD_PROFILE_UUID = 'child';

    public function forUser(Server $server, object $user): array
    {
        if (self::isChildNode($server) || !self::supports($server)) {
            return [];
        }

        $server->loadMissing(['children', 'routeProfiles.outboundTemplate', 'outboundTemplates']);
        $profiles = (new RouteIdentityService())->forUser($server, $user);
        $identities = [];

        foreach ($server->children as $child) {
            if ($child->enabled === false || $child->enabled === 0 || $child->enabled === '0') {
                continue;
            }

            $childId = (int) $child->id;
            $identities[] = [
                'profile_id' => self::childKey($childId),
                'profile_name' => $child->name,
                'uuid' => self::credentialFor($child, (string) $user->uuid),
                'auth_user' => self::authUser((int) $user->id, $childId),
                'outbound_tag' => 'direct',
            ];

            foreach ($profiles as $profile) {
                if (!RouteIdentityService::allowsEntry($profile, $childId)) {
                    continue;
                }

                $identities[] = [
                    'profile_id' => self::childKey($childId, $profile['profile_id']),
                    'profile_name' => $profile['profile_name'],
                    'uuid' => self::credentialFor($child, $profile['uuid']),
                    'auth_user' => self::authUser((int) $user->id, $childId, $profile['profile_id']),
                    'outbound_tag' => $profile['outbound_tag'],
                ];
            }
        }

        return $identities;
    }

    public static function supports(Server $server): bool
    {
        return in_array($server->type, [
            Server::TYPE_TROJAN,
            Server::TYPE_SHADOWSOCKS,
            Server::TYPE_VLESS,
        ], true);
    }

    public static function isChildNode(Server $server): bool
    {
        return (int) $server->parent_id > 0;
    }

    public static function credentialFor(Server $server, string $baseCredential): string
    {
        if (!self::isChildNode($server) || !self::supports($server)) {
            return $baseCredential;
        }

        return self::deriveUUID($baseCredential, (int) $server->id);
    }

    public static function deriveUUID(string $baseUUID, int $childId): string
    {
        $hex = str_replace('-', '', strtolower($baseUUID));
        $key = ctype_xdigit($hex) && strlen($hex) === 32 ? hex2bin($hex) : $baseUUID;
        $bytes = substr(hash_hmac('sha256', self::DOMAIN . $childId, $key, true), 0, 16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $derived = bin2hex($bytes);

        return substr($derived, 0, 8) . '-'
            . substr($derived, 8, 4) . '-'
            . substr($derived, 12, 4) . '-'
            . substr($derived, 16, 4) . '-'
            . substr($derived, 20, 12);
    }

    public static function authUser(int $userId, int $childId, ?string $profileUuid = null): string
    {
        $authUser = sprintf('xb:u:%d:cn:%d', $userId, $childId);
        if ($profileUuid) {
            $authUser .= ':rp:' . $profileUuid;
        }

        return $authUser;
    }

    public static function childKey(int $childId, ?string $profileUuid = null): string
    {
        $key = 'child-' . $childId;
        if ($profileUuid) {
            $key .= '-rp-' . $profileUuid;
        }

        return $key;
    }

    /**
     * @return array{user_id:int,child_id:?int,profile_uuid:?string}|null
     */
    public static function parseAuthUser(string $authUser): ?array
    {
        if (preg_match('/^xb:u:(\d+):cn:(\d+):rp:([A-Za-z0-9-]{1,64})$/', $authUser, $matches)) {
            return [
                'user_id' => (int) $matches[1],
                'child_id' => (int) $matches[2],
                'profile_uuid' => $matches[3],
            ];
        }

        if (preg_match('/^xb:u:(\d+):cn:(\d+)$/', $authUser, $matches)) {
            return [
                'user_id' => (int) $matches[1],
                'child_id' => (int) $matches[2],
                'profile_uuid' => null,
            ];
        }

        if (preg_match('/^xb:u:(\d+):rp:([A-Za-z0-9-]{1,64})$/', $authUser, $matches)) {
            return [
                'user_id' => (int) $matches[1],
                'child_id' => null,
                'profile_uuid' => $matches[2],
            ];
        }

        return null;
    }
}
