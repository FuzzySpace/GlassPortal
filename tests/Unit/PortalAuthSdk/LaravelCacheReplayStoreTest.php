<?php

namespace Tests\Unit\PortalAuthSdk;

use GlassHouse\PortalAuth\Replay\LaravelCacheReplayStore;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Tests for the Laravel-cache-backed replay store.
 *
 * Uses the array cache driver set up by Laravel's TestCase.
 */
class LaravelCacheReplayStoreTest extends TestCase
{
    private LaravelCacheReplayStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new LaravelCacheReplayStore(Cache::store('array'));
    }

    public function test_jti_not_consumed_initially(): void
    {
        $this->assertFalse($this->store->isConsumed('test-jti-1'));
    }

    public function test_consumed_jti_is_detected(): void
    {
        $this->store->consume('test-jti-2', 600);
        $this->assertTrue($this->store->isConsumed('test-jti-2'));
    }

    public function test_different_jtis_are_independent(): void
    {
        $this->store->consume('jti-a', 600);

        $this->assertTrue($this->store->isConsumed('jti-a'));
        $this->assertFalse($this->store->isConsumed('jti-b'));
    }

    public function test_is_healthy_returns_true_with_array_store(): void
    {
        $this->assertTrue($this->store->isHealthy());
    }

    public function test_cache_key_is_namespaced(): void
    {
        $jti = 'namespaced-jti-test';
        $this->store->consume($jti, 600);

        // Verify the key uses the correct prefix, not a bare JTI
        $this->assertFalse(Cache::store('array')->has($jti));
        $this->assertTrue(Cache::store('array')->has("glasshouse:portal-auth:consumed:{$jti}"));
    }
}
