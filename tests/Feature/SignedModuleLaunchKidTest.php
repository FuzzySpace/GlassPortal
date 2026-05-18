<?php

namespace Tests\Feature;

use App\Models\OrganizationModuleLink;
use App\Models\User;
use App\Services\Sso\SignedLaunchTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignedModuleLaunchKidTest extends TestCase
{
    use RefreshDatabase;

    private function makeLink(string $moduleKey = 'testmodule'): OrganizationModuleLink
    {
        $org  = \App\Models\Organization::factory()->create();
        $user = User::factory()->create(['role' => \App\Enums\UserRole::Customer]);

        return OrganizationModuleLink::factory()->create([
            'organization_id' => $org->id,
            'module_key'      => $moduleKey,
            'auth_mode'       => 'signed_launch',
            'status'          => 'active',
        ]);
    }

    private function makeUser(): User
    {
        return User::factory()->create(['role' => \App\Enums\UserRole::Customer]);
    }

    // =========================================================================
    // Active kid in key_registry is used for issuance
    // =========================================================================

    public function test_generate_embeds_active_kid_from_key_registry(): void
    {
        config([
            'glasshouse_sso.key_registry' => [
                'v2' => ['secret' => 'secret-v2-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'algorithm' => 'HS256', 'status' => 'active'],
            ],
            'glasshouse_sso.active_kid'   => 'v2',
            'glasshouse_sso.signing_secret' => '',
        ]);
        $this->app->forgetInstance(\App\Services\Sso\SigningKeyResolver::class);

        $link    = $this->makeLink();
        $user    = $this->makeUser();
        $service = new SignedLaunchTokenService();
        $result  = $service->generate($link, $user);

        $parts     = explode('.', $result['token']);
        $header    = json_decode(base64_decode(str_pad(strtr($parts[0], '-_', '+/'), strlen($parts[0]) % 4 === 0 ? strlen($parts[0]) : strlen($parts[0]) + 4 - (strlen($parts[0]) % 4), '=')), true);
        $this->assertSame('v2', $header['kid']);
    }

    public function test_token_signed_with_key_registry_secret_verifies_correctly(): void
    {
        $secret = 'secret-v2-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
        config([
            'glasshouse_sso.key_registry' => [
                'v2' => ['secret' => $secret, 'algorithm' => 'HS256', 'status' => 'active'],
            ],
            'glasshouse_sso.active_kid'     => 'v2',
            'glasshouse_sso.signing_secret' => '',
        ]);
        $this->app->forgetInstance(\App\Services\Sso\SigningKeyResolver::class);

        $link    = $this->makeLink();
        $user    = $this->makeUser();
        $service = new SignedLaunchTokenService();
        $result  = $service->generate($link, $user);
        $payload = $service->verify($result['token'], $link->module_key);

        $this->assertSame($link->module_key, $payload['aud']);
        $this->assertSame((string) $user->id, $payload['sub']);
    }

    // =========================================================================
    // Legacy (no kid) tokens still verify
    // =========================================================================

    public function test_legacy_no_kid_token_verifies_against_global_secret(): void
    {
        $secret = 'legacy-global-secret-xxxxxxxxxxxx';
        config([
            'glasshouse_sso.key_registry'   => [],
            'glasshouse_sso.active_kid'     => '',
            'glasshouse_sso.signing_secret' => $secret,
            'glasshouse_sso.key_id'         => '',
        ]);
        $this->app->forgetInstance(\App\Services\Sso\SigningKeyResolver::class);

        $link    = $this->makeLink();
        $user    = $this->makeUser();
        $service = new SignedLaunchTokenService();
        $result  = $service->generate($link, $user);

        // Confirm no kid in header
        $parts  = explode('.', $result['token']);
        $header = json_decode(base64_decode(str_pad(strtr($parts[0], '-_', '+/'), strlen($parts[0]) % 4 === 0 ? strlen($parts[0]) : strlen($parts[0]) + 4 - (strlen($parts[0]) % 4), '=')), true);
        $this->assertArrayNotHasKey('kid', $header);

        $payload = $service->verify($result['token'], $link->module_key);
        $this->assertSame($link->module_key, $payload['aud']);
    }

    // =========================================================================
    // Previous kid still verifies
    // =========================================================================

    public function test_token_with_previous_kid_still_verifies(): void
    {
        $secretV1 = 'secret-v1-previous-xxxxxxxxxxxx';
        $secretV2 = 'secret-v2-active-xxxxxxxxxxxxxx';

        // First generate a v1 token (v1 active)
        config([
            'glasshouse_sso.key_registry' => [
                'v1' => ['secret' => $secretV1, 'algorithm' => 'HS256', 'status' => 'active'],
            ],
            'glasshouse_sso.active_kid'     => 'v1',
            'glasshouse_sso.signing_secret' => '',
        ]);
        $this->app->forgetInstance(\App\Services\Sso\SigningKeyResolver::class);

        $link    = $this->makeLink();
        $user    = $this->makeUser();
        $service = new SignedLaunchTokenService();
        $v1Token = $service->generate($link, $user)['token'];

        // Now rotate: v1 becomes previous, v2 is active
        config([
            'glasshouse_sso.key_registry' => [
                'v1' => ['secret' => $secretV1, 'algorithm' => 'HS256', 'status' => 'previous'],
                'v2' => ['secret' => $secretV2, 'algorithm' => 'HS256', 'status' => 'active'],
            ],
            'glasshouse_sso.active_kid' => 'v2',
        ]);
        $this->app->forgetInstance(\App\Services\Sso\SigningKeyResolver::class);

        $serviceAfterRotation = new SignedLaunchTokenService();
        $payload = $serviceAfterRotation->verify($v1Token, $link->module_key);
        $this->assertSame($link->module_key, $payload['aud']);
    }

    // =========================================================================
    // Disabled kid is rejected
    // =========================================================================

    public function test_token_with_disabled_kid_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/signature/i');

        $secretV1 = 'secret-v1-disabled-xxxxxxxxxxxx';
        $secretV2 = 'secret-v2-active-xxxxxxxxxxxxxx';

        // Generate v1 token while it's active
        config([
            'glasshouse_sso.key_registry' => [
                'v1' => ['secret' => $secretV1, 'algorithm' => 'HS256', 'status' => 'active'],
            ],
            'glasshouse_sso.active_kid'     => 'v1',
            'glasshouse_sso.signing_secret' => '',
        ]);
        $this->app->forgetInstance(\App\Services\Sso\SigningKeyResolver::class);

        $link    = $this->makeLink();
        $user    = $this->makeUser();
        $service = new SignedLaunchTokenService();
        $v1Token = $service->generate($link, $user)['token'];

        // Disable v1
        config([
            'glasshouse_sso.key_registry' => [
                'v1' => ['secret' => $secretV1, 'algorithm' => 'HS256', 'status' => 'disabled'],
                'v2' => ['secret' => $secretV2, 'algorithm' => 'HS256', 'status' => 'active'],
            ],
            'glasshouse_sso.active_kid' => 'v2',
        ]);
        $this->app->forgetInstance(\App\Services\Sso\SigningKeyResolver::class);

        $serviceAfterRevoke = new SignedLaunchTokenService();
        $serviceAfterRevoke->verify($v1Token, $link->module_key);
    }

    // =========================================================================
    // Per-module secret takes precedence and bypasses kid embedding
    // =========================================================================

    public function test_per_module_secret_takes_precedence_over_key_registry(): void
    {
        $perModuleSecret = 'per-module-secret-glasspanel-xxx';
        config([
            'glasshouse_sso.key_registry' => [
                'v2' => ['secret' => 'registry-secret', 'algorithm' => 'HS256', 'status' => 'active'],
            ],
            'glasshouse_sso.active_kid'       => 'v2',
            'glasshouse_sso.signing_secret'   => '',
            'glasshouse_sso.per_module_secrets' => [
                'glasspanel' => $perModuleSecret,
            ],
        ]);
        $this->app->forgetInstance(\App\Services\Sso\SigningKeyResolver::class);

        $link    = $this->makeLink('glasspanel');
        $user    = $this->makeUser();
        $service = new SignedLaunchTokenService();
        $result  = $service->generate($link, $user);

        // Per-module tokens should not embed a kid (no registry kid needed)
        $parts  = explode('.', $result['token']);
        $header = json_decode(base64_decode(str_pad(strtr($parts[0], '-_', '+/'), strlen($parts[0]) % 4 === 0 ? strlen($parts[0]) : strlen($parts[0]) + 4 - (strlen($parts[0]) % 4), '=')), true);
        $this->assertArrayNotHasKey('kid', $header);

        // Verify succeeds using per-module secret
        $payload = $service->verify($result['token'], 'glasspanel');
        $this->assertSame('glasspanel', $payload['aud']);
    }
}
