<?php

namespace GlassHouse\PortalAuth\Sso;

/**
 * Low-level SLP (Signed Launch Payload) token parsing and HMAC helpers.
 *
 * No framework dependencies — safe to use in any PHP 8.3+ context.
 */
final class SignedLaunchTokenParser
{
    public function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function decode(string $data): string
    {
        $padded = $data . str_repeat('=', (4 - strlen($data) % 4) % 4);
        return (string) base64_decode(strtr($padded, '-_', '+/'));
    }

    public function hmacB64(string $data, string $secret): string
    {
        return $this->encode(hash_hmac('sha256', $data, $secret, true));
    }

    /**
     * Split a compact SLP token and return [$headerB64, $payloadB64, $sigB64].
     * Returns null if the token does not have exactly 3 dot-separated parts.
     *
     * @return array{string,string,string}|null
     */
    public function split(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        return $parts;
    }

    /**
     * Decode the header part and return an associative array.
     * Returns an empty array on JSON parse failure.
     */
    public function decodeHeader(string $headerB64): array
    {
        return (array) (json_decode($this->decode($headerB64), true) ?? []);
    }

    /**
     * Decode the payload part and return an associative array.
     * Returns null on JSON parse failure.
     */
    public function decodePayload(string $payloadB64): ?array
    {
        $decoded = json_decode($this->decode($payloadB64), true);
        return is_array($decoded) ? $decoded : null;
    }
}
