<?php

namespace Tests\Unit\Sso;

use App\Services\Sso\SigningKeyResolver;
use Tests\TestCase;

class SigningKeyResolverTest extends TestCase
{
    // =========================================================================
    // activeSigningKey
    // =========================================================================

    public function test_returns_null_when_no_active_kid_configured(): void
    {
        $resolver = new SigningKeyResolver([], '');
        $this->assertNull($resolver->activeSigningKey());
    }

    public function test_returns_null_when_active_kid_not_in_registry(): void
    {
        $resolver = new SigningKeyResolver([], 'v99');
        $this->assertNull($resolver->activeSigningKey());
    }

    public function test_returns_null_when_active_kid_entry_is_previous(): void
    {
        $registry = [
            'v1' => ['secret' => 'secret1', 'algorithm' => 'HS256', 'status' => 'previous'],
        ];
        $resolver = new SigningKeyResolver($registry, 'v1');
        $this->assertNull($resolver->activeSigningKey());
    }

    public function test_returns_null_when_active_kid_entry_is_disabled(): void
    {
        $registry = [
            'v1' => ['secret' => 'secret1', 'algorithm' => 'HS256', 'status' => 'disabled'],
        ];
        $resolver = new SigningKeyResolver($registry, 'v1');
        $this->assertNull($resolver->activeSigningKey());
    }

    public function test_returns_null_when_active_key_has_empty_secret(): void
    {
        $registry = [
            'v2' => ['secret' => '', 'algorithm' => 'HS256', 'status' => 'active'],
        ];
        $resolver = new SigningKeyResolver($registry, 'v2');
        $this->assertNull($resolver->activeSigningKey());
    }

    public function test_returns_active_key_when_fully_configured(): void
    {
        $registry = [
            'v2' => ['secret' => 'secret-v2', 'algorithm' => 'HS256', 'status' => 'active'],
        ];
        $resolver = new SigningKeyResolver($registry, 'v2');
        $result   = $resolver->activeSigningKey();

        $this->assertNotNull($result);
        $this->assertSame('v2', $result['kid']);
        $this->assertSame('secret-v2', $result['secret']);
        $this->assertSame('HS256', $result['algorithm']);
    }

    public function test_active_key_does_not_include_disabled_entry(): void
    {
        $registry = [
            'v1' => ['secret' => 'secret1', 'algorithm' => 'HS256', 'status' => 'disabled'],
            'v2' => ['secret' => 'secret2', 'algorithm' => 'HS256', 'status' => 'active'],
        ];
        $resolver = new SigningKeyResolver($registry, 'v2');
        $result   = $resolver->activeSigningKey();

        $this->assertNotNull($result);
        $this->assertSame('v2', $result['kid']);
    }

    // =========================================================================
    // resolveByKid
    // =========================================================================

    public function test_resolve_by_kid_returns_empty_string_for_unknown_kid(): void
    {
        $resolver = new SigningKeyResolver([], '');
        $this->assertSame('', $resolver->resolveByKid('unknown'));
    }

    public function test_resolve_by_kid_returns_empty_string_for_empty_kid(): void
    {
        $registry = [
            'v1' => ['secret' => 'secret1', 'algorithm' => 'HS256', 'status' => 'active'],
        ];
        $resolver = new SigningKeyResolver($registry, 'v1');
        $this->assertSame('', $resolver->resolveByKid(''));
    }

    public function test_resolve_by_kid_returns_null_for_disabled_key(): void
    {
        $registry = [
            'v1' => ['secret' => 'secret1', 'algorithm' => 'HS256', 'status' => 'disabled'],
        ];
        $resolver = new SigningKeyResolver($registry, '');
        $this->assertNull($resolver->resolveByKid('v1'));
    }

    public function test_resolve_by_kid_returns_secret_for_active_key(): void
    {
        $registry = [
            'v2' => ['secret' => 'secret-v2', 'algorithm' => 'HS256', 'status' => 'active'],
        ];
        $resolver = new SigningKeyResolver($registry, 'v2');
        $this->assertSame('secret-v2', $resolver->resolveByKid('v2'));
    }

    public function test_resolve_by_kid_returns_secret_for_previous_key(): void
    {
        $registry = [
            'v1' => ['secret' => 'secret-v1', 'algorithm' => 'HS256', 'status' => 'previous'],
        ];
        $resolver = new SigningKeyResolver($registry, '');
        $this->assertSame('secret-v1', $resolver->resolveByKid('v1'));
    }

    // =========================================================================
    // publicKeyMetadata
    // =========================================================================

    public function test_public_key_metadata_excludes_disabled_keys(): void
    {
        $registry = [
            'v1' => ['secret' => 'secret1', 'algorithm' => 'HS256', 'status' => 'disabled'],
            'v2' => ['secret' => 'secret2', 'algorithm' => 'HS256', 'status' => 'active'],
        ];
        $resolver = new SigningKeyResolver($registry, 'v2');
        $keys     = $resolver->publicKeyMetadata();

        $this->assertCount(1, $keys);
        $this->assertSame('v2', $keys[0]['kid']);
    }

    public function test_public_key_metadata_includes_active_and_previous(): void
    {
        $registry = [
            'v1' => ['secret' => 'secret1', 'algorithm' => 'HS256', 'status' => 'previous'],
            'v2' => ['secret' => 'secret2', 'algorithm' => 'HS256', 'status' => 'active'],
        ];
        $resolver = new SigningKeyResolver($registry, 'v2');
        $keys     = $resolver->publicKeyMetadata();

        $this->assertCount(2, $keys);
        $kids = array_column($keys, 'kid');
        $this->assertContains('v1', $kids);
        $this->assertContains('v2', $kids);
    }

    public function test_public_key_metadata_never_includes_raw_secret(): void
    {
        $registry = [
            'v2' => ['secret' => 'super-secret', 'algorithm' => 'HS256', 'status' => 'active'],
        ];
        $resolver = new SigningKeyResolver($registry, 'v2');
        $keys     = $resolver->publicKeyMetadata();

        $serialized = json_encode($keys);
        $this->assertStringNotContainsString('super-secret', $serialized);
        $this->assertArrayNotHasKey('secret', $keys[0]);
        $this->assertArrayNotHasKey('k', $keys[0]);
    }

    public function test_public_key_metadata_has_correct_structure(): void
    {
        $registry = [
            'v2' => ['secret' => 'secret2', 'algorithm' => 'HS256', 'status' => 'active'],
        ];
        $resolver = new SigningKeyResolver($registry, 'v2');
        $keys     = $resolver->publicKeyMetadata();

        $this->assertArrayHasKey('kid', $keys[0]);
        $this->assertArrayHasKey('alg', $keys[0]);
        $this->assertArrayHasKey('use', $keys[0]);
        $this->assertArrayHasKey('kty', $keys[0]);
        $this->assertArrayHasKey('status', $keys[0]);
        $this->assertArrayHasKey('iss', $keys[0]);
        $this->assertSame('sig', $keys[0]['use']);
        $this->assertSame('oct', $keys[0]['kty']);
    }

    public function test_public_key_metadata_empty_when_no_registry(): void
    {
        $resolver = new SigningKeyResolver([], '');
        $this->assertEmpty($resolver->publicKeyMetadata());
    }

    // =========================================================================
    // hasActiveKey / hasRegistry
    // =========================================================================

    public function test_has_active_key_true_when_active_key_configured(): void
    {
        $registry = ['v2' => ['secret' => 'secret2', 'algorithm' => 'HS256', 'status' => 'active']];
        $resolver = new SigningKeyResolver($registry, 'v2');
        $this->assertTrue($resolver->hasActiveKey());
    }

    public function test_has_active_key_false_when_no_registry(): void
    {
        $resolver = new SigningKeyResolver([], '');
        $this->assertFalse($resolver->hasActiveKey());
    }

    public function test_has_registry_true_when_registry_has_entries(): void
    {
        $registry = ['v1' => ['secret' => 'secret1', 'algorithm' => 'HS256', 'status' => 'disabled']];
        $resolver = new SigningKeyResolver($registry, '');
        $this->assertTrue($resolver->hasRegistry());
    }

    public function test_has_registry_false_when_empty(): void
    {
        $resolver = new SigningKeyResolver([], '');
        $this->assertFalse($resolver->hasRegistry());
    }
}
