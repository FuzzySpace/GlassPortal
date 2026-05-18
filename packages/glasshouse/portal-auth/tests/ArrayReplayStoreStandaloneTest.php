<?php

namespace GlassHouse\PortalAuth\Tests;

use GlassHouse\PortalAuth\Replay\ArrayReplayStore;
use PHPUnit\Framework\TestCase;

/**
 * Standalone unit tests for ArrayReplayStore.
 * No Laravel required — runs with plain PHPUnit.
 */
class ArrayReplayStoreStandaloneTest extends TestCase
{
    public function test_new_jti_is_not_consumed(): void
    {
        $store = new ArrayReplayStore();
        $this->assertFalse($store->isConsumed('fresh-jti'));
    }

    public function test_consumed_jti_is_detected(): void
    {
        $store = new ArrayReplayStore();
        $store->consume('my-jti', 600);
        $this->assertTrue($store->isConsumed('my-jti'));
    }

    public function test_different_jtis_are_independent(): void
    {
        $store = new ArrayReplayStore();
        $store->consume('jti-a', 600);

        $this->assertTrue($store->isConsumed('jti-a'));
        $this->assertFalse($store->isConsumed('jti-b'));
    }

    public function test_is_always_healthy(): void
    {
        $store = new ArrayReplayStore();
        $this->assertTrue($store->isHealthy());
    }

    public function test_multiple_consumes_are_idempotent(): void
    {
        $store = new ArrayReplayStore();
        $store->consume('dup', 600);
        $store->consume('dup', 600);

        $this->assertTrue($store->isConsumed('dup'));
    }
}
