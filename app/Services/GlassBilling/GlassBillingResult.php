<?php

namespace App\Services\GlassBilling;

/**
 * Normalized result returned by every GlassBillingClient method.
 *
 * ok         — true when the request succeeded (2xx) or false on any failure
 * status     — HTTP status code, or null if no response was received
 * data       — decoded response body (array/mixed) on success, null on failure
 * error      — sanitized error message, null on success
 * latency_ms — round-trip time in milliseconds, null if not measured
 */
final readonly class GlassBillingResult
{
    public function __construct(
        public bool   $ok,
        public ?int   $status     = null,
        public mixed  $data       = null,
        public ?string $error     = null,
        public ?int   $latency_ms = null,
    ) {}

    public static function success(mixed $data, int $status = 200, int $latency_ms = 0): self
    {
        return new self(ok: true, status: $status, data: $data, latency_ms: $latency_ms);
    }

    public static function failure(string $error, ?int $status = null, int $latency_ms = 0): self
    {
        return new self(ok: false, status: $status, error: $error, latency_ms: $latency_ms);
    }

    public static function unconfigured(): self
    {
        return new self(ok: false, error: 'GlassBilling is not configured');
    }
}
