<?php

namespace GlassHouse\PortalAuth\Exceptions;

use RuntimeException;

/**
 * Base exception for all portal-auth SDK errors.
 *
 * Carries a machine-readable reason code for upstream mapping.
 * The raw token or signing secret must never appear in the message.
 */
class PortalAuthException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
