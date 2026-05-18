<?php

namespace GlassHouse\PortalAuth\Tests;

use GlassHouse\PortalAuth\Sso\SignedLaunchTokenParser;
use PHPUnit\Framework\TestCase;

/**
 * Standalone unit tests for SignedLaunchTokenParser.
 * No Laravel required — runs with plain PHPUnit.
 */
class SignedLaunchTokenParserTest extends TestCase
{
    private SignedLaunchTokenParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SignedLaunchTokenParser();
    }

    public function test_encode_decode_roundtrip(): void
    {
        $original = '{"alg":"HS256","typ":"SLP"}';
        $encoded  = $this->parser->encode($original);
        $decoded  = $this->parser->decode($encoded);

        $this->assertSame($original, $decoded);
    }

    public function test_encode_produces_url_safe_base64(): void
    {
        // Standard base64 uses +/ which are URL-unsafe; base64url uses -_
        $data    = random_bytes(64); // likely to contain +/ bytes
        $encoded = $this->parser->encode($data);

        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertStringNotContainsString('=', $encoded, 'Padding must be stripped');
    }

    public function test_split_returns_three_parts_for_valid_token(): void
    {
        $parts = $this->parser->split('a.b.c');

        $this->assertIsArray($parts);
        $this->assertCount(3, $parts);
        $this->assertSame(['a', 'b', 'c'], $parts);
    }

    public function test_split_returns_null_for_two_part_string(): void
    {
        $this->assertNull($this->parser->split('a.b'));
    }

    public function test_split_returns_null_for_four_part_string(): void
    {
        $this->assertNull($this->parser->split('a.b.c.d'));
    }

    public function test_decode_header_returns_array(): void
    {
        $header     = ['alg' => 'HS256', 'typ' => 'SLP'];
        $headerB64  = $this->parser->encode(json_encode($header));
        $decoded    = $this->parser->decodeHeader($headerB64);

        $this->assertSame('HS256', $decoded['alg']);
        $this->assertSame('SLP', $decoded['typ']);
    }

    public function test_decode_header_returns_empty_array_on_invalid_json(): void
    {
        $badB64 = $this->parser->encode('not-json');
        $result = $this->parser->decodeHeader($badB64);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_decode_payload_returns_null_on_invalid_json(): void
    {
        $badB64 = $this->parser->encode('not-json');
        $this->assertNull($this->parser->decodePayload($badB64));
    }

    public function test_decode_payload_returns_array_on_valid_json(): void
    {
        $payload   = ['sub' => '42', 'aud' => 'glasspanel'];
        $payloadB64 = $this->parser->encode(json_encode($payload));
        $decoded    = $this->parser->decodePayload($payloadB64);

        $this->assertSame('42', $decoded['sub']);
        $this->assertSame('glasspanel', $decoded['aud']);
    }

    public function test_hmac_b64_is_deterministic(): void
    {
        $data   = 'header.payload';
        $secret = 'test-secret-key';

        $sig1 = $this->parser->hmacB64($data, $secret);
        $sig2 = $this->parser->hmacB64($data, $secret);

        $this->assertSame($sig1, $sig2);
    }

    public function test_hmac_b64_differs_with_different_secrets(): void
    {
        $data = 'header.payload';

        $sig1 = $this->parser->hmacB64($data, 'secret-one');
        $sig2 = $this->parser->hmacB64($data, 'secret-two');

        $this->assertNotSame($sig1, $sig2);
    }

    public function test_hmac_b64_output_is_url_safe(): void
    {
        $sig = $this->parser->hmacB64('some-data', 'some-secret');

        $this->assertStringNotContainsString('+', $sig);
        $this->assertStringNotContainsString('/', $sig);
        $this->assertStringNotContainsString('=', $sig);
    }
}
