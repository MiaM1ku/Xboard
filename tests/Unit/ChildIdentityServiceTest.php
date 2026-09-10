<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Models\User;
use App\Services\ChildIdentityService;
use App\Utils\Helper;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class ChildIdentityServiceTest extends TestCase
{
    public function test_derivation_is_stable_and_unique_per_child(): void
    {
        $base = '279d4f89-3a2c-488d-a67c-2d39a72acdde';

        $first = ChildIdentityService::deriveUUID($base, 159);
        $second = ChildIdentityService::deriveUUID($base, 137);

        self::assertSame($first, ChildIdentityService::deriveUUID($base, 159));
        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first);
    }

    public function test_child_subscription_password_differs_from_parent_and_keeps_server_key(): void
    {
        $createdAt = 1700000000;
        $parent = $this->shadowsocksNode(161, 0, $createdAt);
        $child = $this->shadowsocksNode(159, 161, $createdAt + 3600);
        $child->setRelation('parent', $parent);

        $user = new User();
        $user->uuid = '279d4f89-3a2c-488d-a67c-2d39a72acdde';

        $parentPassword = $parent->generateServerPassword($user);
        $childPassword = $child->generateServerPassword($user);

        self::assertNotSame($parentPassword, $childPassword);

        $serverKey = Helper::getServerKey($parent->created_at, 16);
        self::assertStringStartsWith($serverKey . ':', $parentPassword);
        self::assertStringStartsWith($serverKey . ':', $childPassword);

        $childKey = Helper::uuidToBase64(ChildIdentityService::deriveUUID($user->uuid, 159), 16);
        self::assertSame($serverKey . ':' . $childKey, $childPassword);
    }

    public function test_parent_user_list_includes_child_identities(): void
    {
        $parent = $this->shadowsocksNode(161, 0, 1700000000);
        $child = $this->shadowsocksNode(159, 161, 1700003600, 'Bage IX');
        $parent->setRelation('children', new Collection([$child]));
        $parent->setRelation('routeProfiles', new Collection());
        $parent->setRelation('outboundTemplates', new Collection());

        $user = (object) ['id' => 79, 'uuid' => '279d4f89-3a2c-488d-a67c-2d39a72acdde'];
        $identities = (new ChildIdentityService())->forUser($parent, $user);

        self::assertCount(1, $identities);
        self::assertSame(ChildIdentityService::authUser(79, 159), $identities[0]['auth_user']);
        self::assertSame(ChildIdentityService::deriveUUID($user->uuid, 159), $identities[0]['uuid']);
        self::assertSame('direct', $identities[0]['outbound_tag']);
    }

    public function test_parse_auth_user_accepts_child_and_profile_forms(): void
    {
        self::assertSame(
            ['user_id' => 79, 'child_id' => 159, 'profile_uuid' => null],
            ChildIdentityService::parseAuthUser('xb:u:79:cn:159')
        );
        self::assertSame(
            ['user_id' => 79, 'child_id' => 159, 'profile_uuid' => 'aaaa-bbbb'],
            ChildIdentityService::parseAuthUser('xb:u:79:cn:159:rp:aaaa-bbbb')
        );
        self::assertSame(
            ['user_id' => 79, 'child_id' => null, 'profile_uuid' => 'aaaa-bbbb'],
            ChildIdentityService::parseAuthUser('xb:u:79:rp:aaaa-bbbb')
        );
        self::assertNull(ChildIdentityService::parseAuthUser('not-an-identity'));
    }

    private function shadowsocksNode(int $id, int $parentId, int $createdAt, string $name = 'node'): Server
    {
        $node = new Server();
        $node->setRawAttributes([
            'id' => $id,
            'name' => $name,
            'type' => Server::TYPE_SHADOWSOCKS,
            'parent_id' => $parentId,
            'enabled' => 1,
            'created_at' => $createdAt,
            'protocol_settings' => json_encode(['cipher' => '2022-blake3-aes-128-gcm']),
        ]);
        $node->exists = true;

        return $node;
    }
}
