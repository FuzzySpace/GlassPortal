<?php

namespace GlassHouse\PortalAuth\Contracts;

interface ReplayStoreInterface
{
    /**
     * Check whether the given JTI has already been consumed (replayed).
     *
     * Returns true  → JTI was already used; reject the token.
     * Returns false → JTI not seen; safe to proceed.
     */
    public function isConsumed(string $jti): bool;

    /**
     * Mark the JTI as consumed so subsequent calls to isConsumed() return true.
     *
     * @param int $ttlSeconds  How long to retain the consumed marker.
     */
    public function consume(string $jti, int $ttlSeconds = 600): void;

    /**
     * True when the store backend is available and functioning.
     * Used for health checks — never block token verification on this.
     */
    public function isHealthy(): bool;
}
