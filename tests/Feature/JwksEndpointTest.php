<?php

namespace Tests\Feature;

use App\Services\Sso\SigningKeyResolver;
use Tests\TestCase;

class JwksEndpointTest extends TestCase
{
    private function makeResolver(array $registry = [], string $activeKid = ''): SigningKeyResolver
    {
        return new SigningKeyResolver($registry, $activeKid);
    }

    public function test_jwks_endpoint_returns_200(): void
    {
        $response = $this->get('/.well-known/glassportal/jwks.json');
        $response->assertStatus(200);
    }

    public function test_jwks_endpoint_returns_json(): void
    {
        $response = $this->get('/.well-known/glassportal/jwks.json');
        $response->assertJsonStructure(['keys']);
    }

    public function test_jwks_endpoint_has_keys_array(): void
    {
        $response = $this->get('/.well-known/glassportal/jwks.json');
        $data     = $response->json();
        $this->assertArrayHasKey('keys', $data);
        $this->assertIsArray($data['keys']);
    }

    public function test_jwks_endpoint_returns_empty_keys_when_no_registry(): void
    {
        // No key_registry configured — empty keys array expected
        config(['glasshouse_sso.key_registry' => []]);
        $this->app->forgetInstance(SigningKeyResolver::class);

        $response = $this->get('/.well-known/glassportal/jwks.json');
        $response->assertJson(['keys' => []]);
    }

    public function test_jwks_endpoint_excludes_disabled_keys(): void
    {
        config([
            'glasshouse_sso.key_registry' => [
                'v1' => ['secret' => 'secret1', 'algorithm' => 'HS256', 'status' => 'disabled'],
                'v2' => ['secret' => 'secret2', 'algorithm' => 'HS256', 'status' => 'active'],
            ],
            'glasshouse_sso.active_kid' => 'v2',
        ]);
        $this->app->forgetInstance(SigningKeyResolver::class);

        $response = $this->get('/.well-known/glassportal/jwks.json');
        $response->assertStatus(200);

        $keys = $response->json('keys');
        $this->assertCount(1, $keys);
        $this->assertSame('v2', $keys[0]['kid']);
    }

    public function test_jwks_endpoint_includes_active_and_previous_keys(): void
    {
        config([
            'glasshouse_sso.key_registry' => [
                'v1' => ['secret' => 'secret1', 'algorithm' => 'HS256', 'status' => 'previous'],
                'v2' => ['secret' => 'secret2', 'algorithm' => 'HS256', 'status' => 'active'],
            ],
            'glasshouse_sso.active_kid' => 'v2',
        ]);
        $this->app->forgetInstance(SigningKeyResolver::class);

        $response = $this->get('/.well-known/glassportal/jwks.json');
        $keys     = $response->json('keys');

        $this->assertCount(2, $keys);
        $kids = array_column($keys, 'kid');
        $this->assertContains('v1', $kids);
        $this->assertContains('v2', $kids);
    }

    public function test_jwks_response_never_contains_raw_secret(): void
    {
        $secret = 'super-secret-that-must-not-leak';
        config([
            'glasshouse_sso.key_registry' => [
                'v2' => ['secret' => $secret, 'algorithm' => 'HS256', 'status' => 'active'],
            ],
            'glasshouse_sso.active_kid' => 'v2',
        ]);
        $this->app->forgetInstance(SigningKeyResolver::class);

        $response = $this->get('/.well-known/glassportal/jwks.json');
        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    public function test_jwks_response_body_has_no_secret_or_k_field(): void
    {
        config([
            'glasshouse_sso.key_registry' => [
                'v2' => ['secret' => 'secret2', 'algorithm' => 'HS256', 'status' => 'active'],
            ],
            'glasshouse_sso.active_kid' => 'v2',
        ]);
        $this->app->forgetInstance(SigningKeyResolver::class);

        $response = $this->get('/.well-known/glassportal/jwks.json');
        $keys     = $response->json('keys');

        foreach ($keys as $key) {
            $this->assertArrayNotHasKey('secret', $key);
            $this->assertArrayNotHasKey('k', $key);
        }
    }

    public function test_jwks_response_key_has_required_fields(): void
    {
        config([
            'glasshouse_sso.key_registry' => [
                'v2' => ['secret' => 'secret2', 'algorithm' => 'HS256', 'status' => 'active'],
            ],
            'glasshouse_sso.active_kid' => 'v2',
        ]);
        $this->app->forgetInstance(SigningKeyResolver::class);

        $response = $this->get('/.well-known/glassportal/jwks.json');
        $keys     = $response->json('keys');

        $this->assertNotEmpty($keys);
        $key = $keys[0];
        $this->assertArrayHasKey('kid', $key);
        $this->assertArrayHasKey('alg', $key);
        $this->assertArrayHasKey('use', $key);
        $this->assertArrayHasKey('kty', $key);
        $this->assertArrayHasKey('status', $key);
        $this->assertArrayHasKey('iss', $key);
        $this->assertSame('sig', $key['use']);
        $this->assertSame('oct', $key['kty']);
    }

    public function test_jwks_endpoint_is_accessible_without_authentication(): void
    {
        // Confirm the route has no auth middleware
        $response = $this->get('/.well-known/glassportal/jwks.json');
        $this->assertNotSame(302, $response->getStatusCode(), 'JWKS route must not redirect to login');
    }

    public function test_jwks_route_is_named_glassportal_jwks(): void
    {
        $this->assertTrue(
            app('router')->getRoutes()->getByName('glassportal.jwks') !== null,
            'glassportal.jwks named route must exist'
        );
    }
}
