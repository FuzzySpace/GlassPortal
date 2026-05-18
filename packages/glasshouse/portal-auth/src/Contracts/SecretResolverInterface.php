<?php

namespace GlassHouse\PortalAuth\Contracts;

interface SecretResolverInterface
{
    /**
     * Resolve the HMAC secret to use when VERIFYING a token for the given audience.
     *
     * Priority is implementation-defined but must never throw — return an empty
     * string if no secret is configured. An empty secret will cause all signature
     * checks to fail (desired: fail closed).
     *
     * @param string $audience   The module key / audience claim from the token.
     * @param string $kid        Optional key ID from the token header.
     */
    public function resolveForVerification(string $audience, string $kid = ''): string;
}
