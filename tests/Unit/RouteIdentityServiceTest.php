<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Services\RouteIdentityService;
use PHPUnit\Framework\TestCase;

class RouteIdentityServiceTest extends TestCase
{
    public function test_derivation_is_stable_and_uuid_v4_compatible(): void
    {
        $derived = RouteIdentityService::deriveUUID(
            '279d4f89-3a2c-488d-a67c-2d39a72acdde',
            'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'
        );

        self::assertSame('b5313bd0-10c4-4bf1-ac2b-ce6b2f0ef1c7', $derived);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $derived);
    }

    public function test_different_profiles_produce_different_credentials(): void
    {
        $base = '279d4f89-3a2c-488d-a67c-2d39a72acdde';

        self::assertNotSame(
            RouteIdentityService::deriveUUID($base, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'),
            RouteIdentityService::deriveUUID($base, '11111111-2222-4333-8444-555555555555')
        );
    }

    public function test_profiles_are_supported_for_trojan_and_vless_reality(): void
    {
        $service = new RouteIdentityService();

        $trojan = (new Server())->setRawAttributes([
            'type' => Server::TYPE_TROJAN,
            'protocol_settings' => json_encode(['tls' => 1]),
        ]);
        $vlessReality = (new Server())->setRawAttributes([
            'type' => Server::TYPE_VLESS,
            'protocol_settings' => json_encode(['tls' => 2]),
        ]);
        $vlessTLS = (new Server())->setRawAttributes([
            'type' => Server::TYPE_VLESS,
            'protocol_settings' => json_encode(['tls' => 1]),
        ]);
        $shadowsocks = (new Server())->setRawAttributes([
            'type' => Server::TYPE_SHADOWSOCKS,
            'protocol_settings' => json_encode(['cipher' => '2022-blake3-aes-128-gcm']),
        ]);

        self::assertTrue($service->supportsProfiles($trojan));
        self::assertTrue($service->supportsProfiles($vlessReality));
        self::assertTrue($service->supportsProfiles($shadowsocks));
        self::assertFalse($service->supportsProfiles($vlessTLS));
    }

    public function test_entry_filter_defaults_to_all_and_can_target_one_child(): void
    {
        self::assertTrue(RouteIdentityService::allowsEntry([], 137));
        self::assertTrue(RouteIdentityService::allowsEntry(['entry_server_ids' => []], 137));
        self::assertTrue(RouteIdentityService::allowsEntry(['entry_server_ids' => ['137']], 137));
        self::assertFalse(RouteIdentityService::allowsEntry(['entry_server_ids' => [137]], 138));
    }
}
