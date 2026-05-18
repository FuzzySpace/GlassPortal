<?php

namespace GlassHouse\PortalAuth\Replay;

use GlassHouse\PortalAuth\Contracts\ReplayStoreInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Laravel-cache-backed replay store for production use.
 *
 * Requires illuminate/cache. JTIs are stored under a namespaced key so they
 * can coexist with GlassPortal's native "signed-launch:issued:" entries without
 * conflict.
 *
 * Cache key format: glasshouse:portal-auth:consumed:{jti}
 */
class LaravelCacheReplayStore implements ReplayStoreInterface
{
    private const PREFIX = 'glasshouse:portal-auth:consumed:';

    public function __construct(private readonly CacheRepository $cache) {}

    public function isConsumed(string $jti): bool
    {
        try {
            return $this->cache->has(self::PREFIX . $jti);
        } catch (\Throwable) {
            // Fail open on cache failure — the caller should degrade gracefully.
            return false;
        }
    }

    public function consume(string $jti, int $ttlSeconds = 600): void
    {
        try {
            $this->cache->put(self::PREFIX . $jti, 1, $ttlSeconds);
        } catch (\Throwable) {
            // Cache write failure is non-fatal — degraded replay protection.
        }
    }

    public function isHealthy(): bool
    {
        try {
            $probe = self::PREFIX . 'probe:' . bin2hex(random_bytes(4));
            $this->cache->put($probe, 1, 5);
            $ok = $this->cache->has($probe);
            $this->cache->forget($probe);
            return $ok;
        } catch (\Throwable) {
            return false;
        }
    }
}
