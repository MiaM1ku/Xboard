<?php

namespace Tests\Unit;

use App\Utils\Helper;
use PHPUnit\Framework\TestCase;

class SubscriptionTransferEnableTest extends TestCase
{
    public function test_unlimited_users_get_a_100tib_display_cap(): void
    {
        $expected = 100 * 1024 * 1024 * 1024 * 1024;

        self::assertSame($expected, Helper::subscriptionTransferEnable(0));
        self::assertSame($expected, Helper::subscriptionTransferEnable(null));
        self::assertSame($expected, Helper::SUBSCRIPTION_UNLIMITED_DISPLAY_BYTES);
    }

    public function test_limited_users_keep_their_quota(): void
    {
        self::assertSame(1073741824, Helper::subscriptionTransferEnable(1073741824));
    }
}
