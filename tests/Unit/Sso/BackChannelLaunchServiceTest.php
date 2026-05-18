<?php

namespace Tests\Unit\Sso;

use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use App\Services\Sso\BackChannelLaunchCodeResult;
use App\Services\Sso\BackChannelLaunchService;
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackChannelLaunchServiceTest extends TestCase
{
    use RefreshDatabase;

    private BackChannelLaunchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['glasshouse_sso.backchannel.enabled'                  => true]);
        config(['glasshouse_sso.backchannel.code_ttl_seconds'         => 60]);
        config(['glasshouse_sso.backchannel.replay_cache_ttl_seconds' => 600]);
        config(['glasshouse_sso.backchannel.strict_module_match'      => true]);

        $this->service = new BackChannelLaunchService();
    }

    // -------------------------------------------------------------------------
    // issueCode — success
    // -------------------------------------------------------------------------

    public function test_issue_code_returns_ok_result(): void
    {
        [$link, $user] = $this->fixtures();
        $result = $this->service->issueCode($link, $user);

        $this->assertInstanceOf(BackChannelLaunchCodeResult::class, $result);
        $this->assertTrue($result->ok);
        $this->assertSame('ok', $result->reason);
    }

    public function test_issued_code_is_64_hex_chars(): void
    {
        [$link, $user] = $this->fixtures();
        $result = $this->service->issueCode($link, $user);

        $this->assertNotNull($result->code);
        $this->assertSame(64, strlen($result->code));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result->code);
    }

    public function test_issued_codes_are_unique(): void
    {
        [$link, $user] = $this->fixtures();
        $a = $this->service->issueCode($link, $user)->code;
        $b = $this->service->issueCode($link, $user)->code;

        $this->assertNotSame($a, $b);
    }

    public function test_issue_result_has_future_expires_at(): void
    {
        [$link, $user] = $this->fixtures();
        $result = $this->service->issueCode($link, $user);

        $this->assertGreaterThan(time(), $result->expiresAt);
    }

    public function test_issue_result_does_not_contain_raw_code_in_user_fields(): void
    {
        [$link, $user] = $this->fixtures();
        $result = $this->service->issueCode($link, $user);

        // The result object should NOT populate user identity fields on issue
        $this->assertNull($result->userId);
        $this->assertNull($result->email);
        $this->assertNull($result->role);
    }

    // -------------------------------------------------------------------------
    // redeemCode — success
    // -------------------------------------------------------------------------

    public function test_redeem_valid_code_returns_ok_result(): void
    {
        [$link, $user] = $this->fixtures();
        $issued = $this->service->issueCode($link, $user);

        $result = $this->service->redeemCode($link->module_key, $issued->code);

        $this->assertTrue($result->ok);
        $this->assertSame('ok', $result->reason);
    }

    public function test_redeem_result_has_correct_identity_fields(): void
    {
        [$link, $user] = $this->fixtures();
        $issued = $this->service->issueCode($link, $user);
        $result = $this->service->redeemCode($link->module_key, $issued->code);

        $this->assertSame($link->module_key, $result->moduleKey);
        $this->assertSame((string) $user->id, $result->userId);
        $this->assertSame((string) $link->organization_id, $result->orgId);
        $this->assertSame($user->email, $result->email);
        $this->assertSame($user->name, $result->name);
        $this->assertNotEmpty($result->role);
    }

    public function test_redeem_result_has_no_code_field(): void
    {
        [$link, $user] = $this->fixtures();
        $issued = $this->service->issueCode($link, $user);
        $result = $this->service->redeemCode($link->module_key, $issued->code);

        // Code is never returned on redemption
        $this->assertNull($result->code);
    }

    // -------------------------------------------------------------------------
    // redeemCode — failure paths
    // -------------------------------------------------------------------------

    public function test_empty_code_returns_missing_code(): void
    {
        $result = $this->service->redeemCode('glasspanel', '');

        $this->assertFalse($result->ok);
        $this->assertSame('missing_code', $result->reason);
    }

    public function test_short_code_returns_malformed_code(): void
    {
        $result = $this->service->redeemCode('glasspanel', 'abc123');

        $this->assertFalse($result->ok);
        $this->assertSame('malformed_code', $result->reason);
    }

    public function test_non_hex_code_returns_malformed_code(): void
    {
        $result = $this->service->redeemCode('glasspanel', str_repeat('g', 64));

        $this->assertFalse($result->ok);
        $this->assertSame('malformed_code', $result->reason);
    }

    public function test_unknown_code_returns_code_not_found(): void
    {
        $result = $this->service->redeemCode('glasspanel', str_repeat('a', 64));

        $this->assertFalse($result->ok);
        $this->assertSame('code_not_found', $result->reason);
    }

    public function test_replayed_code_returns_code_replayed(): void
    {
        [$link, $user] = $this->fixtures();
        $issued = $this->service->issueCode($link, $user);

        $first = $this->service->redeemCode($link->module_key, $issued->code);
        $this->assertTrue($first->ok);

        $second = $this->service->redeemCode($link->module_key, $issued->code);
        $this->assertFalse($second->ok);
        $this->assertSame('code_replayed', $second->reason);
    }

    public function test_wrong_module_key_returns_wrong_module(): void
    {
        [$link, $user] = $this->fixtures();
        $issued = $this->service->issueCode($link, $user);

        $result = $this->service->redeemCode('completely-wrong-module', $issued->code);

        $this->assertFalse($result->ok);
        $this->assertSame('wrong_module', $result->reason);
    }

    public function test_wrong_module_does_not_consume_code(): void
    {
        [$link, $user] = $this->fixtures();
        $issued = $this->service->issueCode($link, $user);

        // Wrong module → not consumed
        $this->service->redeemCode('wrong-module', $issued->code);

        // Correct module → should still work
        $result = $this->service->redeemCode($link->module_key, $issued->code);
        $this->assertTrue($result->ok);
    }

    public function test_inactive_link_returns_inactive_module_link(): void
    {
        [$link, $user] = $this->fixtures();
        $issued = $this->service->issueCode($link, $user);

        // Deactivate the link after issuing the code
        $link->update(['status' => 'inactive']);

        $result = $this->service->redeemCode($link->module_key, $issued->code);

        $this->assertFalse($result->ok);
        $this->assertSame('inactive_module_link', $result->reason);
    }

    // -------------------------------------------------------------------------
    // Disabled
    // -------------------------------------------------------------------------

    public function test_issue_when_disabled_returns_backchannel_disabled(): void
    {
        config(['glasshouse_sso.backchannel.enabled' => false]);
        $service = new BackChannelLaunchService();

        [$link, $user] = $this->fixtures();
        $result = $service->issueCode($link, $user);

        $this->assertFalse($result->ok);
        $this->assertSame('backchannel_disabled', $result->reason);
    }

    public function test_redeem_when_disabled_returns_backchannel_disabled(): void
    {
        config(['glasshouse_sso.backchannel.enabled' => false]);
        $service = new BackChannelLaunchService();

        $result = $service->redeemCode('glasspanel', str_repeat('a', 64));

        $this->assertFalse($result->ok);
        $this->assertSame('backchannel_disabled', $result->reason);
    }

    // -------------------------------------------------------------------------
    // Cache probe
    // -------------------------------------------------------------------------

    public function test_cache_probe_returns_true_when_cache_working(): void
    {
        $this->assertTrue($this->service->isCacheUsable());
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function fixtures(): array
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()
            ->withLaunchUrl('https://panel.test/sso/receive')
            ->forModule('glasspanel', 'GlassPanel')
            ->create([
                'organization_id' => $org->id,
                'auth_mode'       => 'backchannel_launch',
                'status'          => 'active',
            ]);
        $user = User::factory()->create([
            'role'            => UserRole::Customer->value,
            'organization_id' => $org->id,
            'email'           => 'bctest@example.test',
            'name'            => 'Back-Channel Test User',
        ]);
        return [$link, $user];
    }
}
