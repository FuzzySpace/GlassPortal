<?php

namespace GlassHouse\PortalAuth\Replay;

use GlassHouse\PortalAuth\Contracts\ReplayStoreInterface;

/**
 * In-memory replay store for testing and non-persistent single-process use.
 *
 * Consumed JTIs are held in a plain array for the lifetime of the object.
 * Do NOT use in production — restarts and multiple processes will clear state.
 */
class ArrayReplayStore implements ReplayStoreInterface
{
    /** @var array<string, int>  jti => expire-at unix timestamp */
    private array $consumed = [];

    public function isConsumed(string $jti): bool
    {
        $this->evict();
        return isset($this->consumed[$jti]);
    }

    public function consume(string $jti, int $ttlSeconds = 600): void
    {
        $this->consumed[$jti] = time() + $ttlSeconds;
    }

    public function isHealthy(): bool
    {
        return true;
    }

    /** Remove expired entries to prevent unbounded growth in long-running tests. */
    private function evict(): void
    {
        $now = time();
        foreach ($this->consumed as $jti => $expiresAt) {
            if ($now >= $expiresAt) {
                unset($this->consumed[$jti]);
            }
        }
    }
}
