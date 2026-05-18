<?php

namespace Tests\Unit\PortalAuthSdk;

use GlassHouse\PortalAuth\Replay\ArrayReplayStore;
use Tests\TestCase;

class ArrayReplayStoreTest extends TestCase
{
    public function test_jti_not_consumed_initially(): void
    {
        $store = new ArrayReplayStore();
        $this->assertFalse($store->isConsumed('abc123'));
    }

    public function test_consumed_jti_is_detected(): void
    {
        $store = new ArrayReplayStore();
        $store->consume('abc123', 600);
        $this->assertTrue($store->isConsumed('abc123'));
    }

    public function test_different_jtis_are_independent(): void
    {
        $store = new ArrayReplayStore();
        $store->consume('jti-1', 600);

        $this->assertTrue($store->isConsumed('jti-1'));
        $this->assertFalse($store->isConsumed('jti-2'));
    }

    public function test_is_healthy_returns_true(): void
    {
        $store = new ArrayReplayStore();
        $this->assertTrue($store->isHealthy());
    }
}
