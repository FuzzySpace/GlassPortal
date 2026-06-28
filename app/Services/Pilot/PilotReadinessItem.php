<?php

namespace App\Services\Pilot;

/**
 * One pilot-readiness check result (Phase 29).
 *
 * Carries no secrets — `message` and `action` are operator-readable strings only.
 * `status` is one of READY / WARNING / BLOCKED / UNKNOWN.
 */
final readonly class PilotReadinessItem
{
    public const READY   = 'ready';
    public const WARNING = 'warning';
    public const BLOCKED = 'blocked';
    public const UNKNOWN = 'unknown';

    public function __construct(
        public string $key,
        public string $category,
        public string $status,
        public string $message,
        public string $action = '',
    ) {}

    public static function ready(string $key, string $category, string $message, string $action = ''): self
    {
        return new self($key, $category, self::READY, $message, $action);
    }

    public static function warning(string $key, string $category, string $message, string $action = ''): self
    {
        return new self($key, $category, self::WARNING, $message, $action);
    }

    public static function blocked(string $key, string $category, string $message, string $action = ''): self
    {
        return new self($key, $category, self::BLOCKED, $message, $action);
    }

    public static function unknown(string $key, string $category, string $message, string $action = ''): self
    {
        return new self($key, $category, self::UNKNOWN, $message, $action);
    }

    public function isBlocked(): bool
    {
        return $this->status === self::BLOCKED;
    }

    /** A single-character glyph for CLI rendering (✓ / ! / ✗ / ?). */
    public function glyph(): string
    {
        return match ($this->status) {
            self::READY   => '✓',
            self::WARNING => '!',
            self::BLOCKED => '✗',
            default       => '?',
        };
    }
}
