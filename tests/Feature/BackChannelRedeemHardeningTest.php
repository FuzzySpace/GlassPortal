<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ModuleLaunchEvent;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use App\Services\Sso\BackChannelLaunchService;
use App\Services\Sso\SignedLaunchTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackChannelRedeemHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['glasshouse_sso.backchannel.enabled'                  => true]);
        config(['glasshouse_sso.backchannel.code_ttl_seconds'         => 60]);
        config(['glasshouse_sso.backchannel.replay_cache_ttl_seconds' => 600]);
        config(['glasshouse_sso.backchannel.strict_module_match'      => true]);
        config(['glasshouse_sso.backchannel.require_mtls'             => false]);
        config(['glasshouse_sso.backchannel.mtls_verified_header'     => 'X-Client-Cert-Verified']);
        config(['glasshouse_sso.backchannel.mtls_verified_value'      => 'SUCCESS']);
    }

    // -------------------------------------------------------------------------
    // Audit events — success
    // -------------------------------------------------------------------------

    public function test_successful_redeem_creates_backchannel_redeem_success_event(): void
    {
        [$link, $user, $code] = $this->fixtures();
        $this->assertDatabaseCount('module_launch_events', 0);

        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", ['launch_code' => $code])
            ->assertStatus(200);

        $this->assertDatabaseHas('module_launch_events', [
            'module_key' => $link->module_key,
            'auth_mode'  => 'backchannel_launch',
            'event_type' => 'backchannel_redeem_success',
            'reason'     => null,
        ]);
    }

    public function test_success_audit_event_has_user_and_org_context(): void
    {
        [$link, $user, $code] = $this->fixtures();

        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", ['launch_code' => $code])
            ->assertStatus(200);

        $event = ModuleLaunchEvent::first();
        $this->assertNotNull($event);
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame((int) $link->organization_id, $event->organization_id);
        $this->assertSame($link->id, $event->module_link_id);
    }

    public function test_success_audit_metadata_contains_expires_at(): void
    {
        [$link, $user, $code] = $this->fixtures();

        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", ['launch_code' => $code])
            ->assertStatus(200);

        $event = ModuleLaunchEvent::first();
        $this->assertNotNull($event->metadata);
        $this->assertArrayHasKey('expires_at', $event->metadata);
        $this->assertGreaterThan(time(), $event->metadata['expires_at']);
    }

    // -------------------------------------------------------------------------
    // Audit events — replay
    // -------------------------------------------------------------------------

    public function test_replay_creates_backchannel_replay_blocked_event(): void
    {
        [$link, $user, $code] = $this->fixtures();

        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", ['launch_code' => $code])
            ->assertStatus(200);
        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", ['launch_code' => $code])
            ->assertStatus(401);

        $this->assertDatabaseHas('module_launch_events', [
            'auth_mode'  => 'backchannel_launch',
            'event_type' => 'backchannel_replay_blocked',
            'reason'     => 'code_replayed',
        ]);
    }

    // -------------------------------------------------------------------------
    // Audit events — wrong module
    // -------------------------------------------------------------------------

    public function test_wrong_module_creates_backchannel_redeem_failed_event(): void
    {
        [$link, $user, $code] = $this->fixtures();

        $this->postJson('/api/sso/backchannel/redeem/wrong-module', ['launch_code' => $code])
            ->assertStatus(403);

        $this->assertDatabaseHas('module_launch_events', [
            'module_key' => 'wrong-module',
            'auth_mode'  => 'backchannel_launch',
            'event_type' => 'backchannel_redeem_failed',
            'reason'     => 'wrong_module',
        ]);
    }

    // -------------------------------------------------------------------------
    // Audit events — inactive link (has context because code was consumed first)
    // -------------------------------------------------------------------------

    public function test_inactive_link_creates_failed_event_with_link_context(): void
    {
        [$link, $user, $code] = $this->fixtures();
        $link->update(['status' => 'inactive']);

        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", ['launch_code' => $code])
            ->assertStatus(403);

        $event = ModuleLaunchEvent::where('event_type', 'backchannel_redeem_failed')->first();
        $this->assertNotNull($event);
        $this->assertSame('inactive_module_link', $event->reason);
        $this->assertSame($link->id, $event->module_link_id);
    }

    // -------------------------------------------------------------------------
    // Audit events — format errors are NOT audited
    // -------------------------------------------------------------------------

    public function test_missing_code_does_not_create_audit_event(): void
    {
        $this->postJson('/api/sso/backchannel/redeem/glasspanel', [])->assertStatus(401);
        $this->assertDatabaseCount('module_launch_events', 0);
    }

    public function test_malformed_code_does_not_create_audit_event(): void
    {
        $this->postJson('/api/sso/backchannel/redeem/glasspanel', ['launch_code' => 'tooshort'])->assertStatus(401);
        $this->assertDatabaseCount('module_launch_events', 0);
    }

    public function test_code_not_found_does_not_create_audit_event(): void
    {
        $this->postJson('/api/sso/backchannel/redeem/glasspanel', ['launch_code' => str_repeat('a', 64)])->assertStatus(401);
        $this->assertDatabaseCount('module_launch_events', 0);
    }

    // -------------------------------------------------------------------------
    // Security — audit must not leak raw code, email, or name
    // -------------------------------------------------------------------------

    public function test_audit_event_does_not_contain_raw_code(): void
    {
        [$link, $user, $code] = $this->fixtures();

        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", ['launch_code' => $code])
            ->assertStatus(200);

        $serialised = json_encode(ModuleLaunchEvent::first()->toArray());
        $this->assertStringNotContainsString($code, $serialised);
    }

    public function test_audit_event_metadata_does_not_contain_email_or_name(): void
    {
        [$link, $user, $code] = $this->fixtures();

        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", ['launch_code' => $code])
            ->assertStatus(200);

        $metadata = ModuleLaunchEvent::first()->metadata ?? [];
        $this->assertArrayNotHasKey('email', $metadata);
        $this->assertArrayNotHasKey('name', $metadata);
    }

    // -------------------------------------------------------------------------
    // mTLS enforcement
    // -------------------------------------------------------------------------

    public function test_mtls_not_required_by_default(): void
    {
        [$link, $user, $code] = $this->fixtures();

        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", ['launch_code' => $code])
            ->assertStatus(200);
    }

    public function test_mtls_required_rejects_without_header(): void
    {
        config(['glasshouse_sso.backchannel.require_mtls' => true]);
        [$link, $user, $code] = $this->fixtures();

        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", ['launch_code' => $code])
            ->assertStatus(401)
            ->assertJson(['ok' => false])
            ->assertJsonPath('reason', 'mtls_required');
    }

    public function test_mtls_accepted_when_header_present(): void
    {
        config(['glasshouse_sso.backchannel.require_mtls' => true]);
        [$link, $user, $code] = $this->fixtures();

        $this->withHeaders(['X-Client-Cert-Verified' => 'SUCCESS'])
            ->postJson("/api/sso/backchannel/redeem/{$link->module_key}", ['launch_code' => $code])
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    public function test_mtls_rejected_with_wrong_header_value(): void
    {
        config(['glasshouse_sso.backchannel.require_mtls' => true]);
        [$link, $user, $code] = $this->fixtures();

        $this->withHeaders(['X-Client-Cert-Verified' => 'FAILED'])
            ->postJson("/api/sso/backchannel/redeem/{$link->module_key}", ['launch_code' => $code])
            ->assertStatus(401)
            ->assertJsonPath('reason', 'mtls_required');
    }

    public function test_custom_mtls_header_name_is_respected(): void
    {
        config([
            'glasshouse_sso.backchannel.require_mtls'         => true,
            'glasshouse_sso.backchannel.mtls_verified_header' => 'X-SSL-Client-Verify',
            'glasshouse_sso.backchannel.mtls_verified_value'  => 'OK',
        ]);
        [$link, $user, $code] = $this->fixtures();

        $this->withHeaders(['X-SSL-Client-Verify' => 'OK'])
            ->postJson("/api/sso/backchannel/redeem/{$link->module_key}", ['launch_code' => $code])
            ->assertStatus(200);
    }

    public function test_mtls_rejection_does_not_create_audit_event(): void
    {
        config(['glasshouse_sso.backchannel.require_mtls' => true]);
        [$link, $user, $code] = $this->fixtures();

        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", ['launch_code' => $code])
            ->assertStatus(401);

        // Middleware short-circuits before controller — no DB write expected
        $this->assertDatabaseCount('module_launch_events', 0);
    }

    // -------------------------------------------------------------------------
    // Per-module secret — end-to-end signed_launch isolation
    // -------------------------------------------------------------------------

    public function test_token_signed_with_per_module_secret_verifies_correctly(): void
    {
        $moduleSecret = 'glasspanel-per-module-secret-long-enough-for-hmac-sha256';
        config([
            'glasshouse_sso.signing_secret'          => 'global-fallback-secret-long-enough-for-hmac',
            'glasshouse_sso.per_module_secrets'       => ['glasspanel' => $moduleSecret],
            'glasshouse_sso.issuer'                   => 'glassportal-test',
            'glasshouse_sso.default_ttl_seconds'      => 60,
            'glasshouse_sso.max_ttl_seconds'          => 300,
            'glasshouse_sso.clock_skew_seconds'       => 30,
            'glasshouse_sso.nonce_cache_ttl_seconds'  => 600,
            'glasshouse_sso.key_id'                   => '',
            'glasshouse_sso.keys'                     => [],
        ]);

        $svc  = new SignedLaunchTokenService();
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()
            ->withLaunchUrl('https://panel.test')
            ->forModule('glasspanel', 'GlassPanel')
            ->create(['organization_id' => $org->id, 'auth_mode' => 'signed_launch', 'status' => 'active']);
        $user = User::factory()->create(['role' => UserRole::Customer->value, 'organization_id' => $org->id]);

        $token   = $svc->generate($link, $user)['token'];
        $payload = $svc->verify($token, 'glasspanel');

        $this->assertSame('glasspanel', $payload['aud']);
        $this->assertSame((string) $user->id, $payload['sub']);
    }

    public function test_token_signed_with_per_module_secret_fails_global_only_verify(): void
    {
        $moduleSecret = 'glasspanel-per-module-secret-long-enough-for-hmac-sha256';
        $globalSecret = 'global-fallback-secret-long-enough-for-hmac-sha256-x';

        config([
            'glasshouse_sso.signing_secret'          => $globalSecret,
            'glasshouse_sso.per_module_secrets'       => ['glasspanel' => $moduleSecret],
            'glasshouse_sso.issuer'                   => 'glassportal-test',
            'glasshouse_sso.default_ttl_seconds'      => 60,
            'glasshouse_sso.max_ttl_seconds'          => 300,
            'glasshouse_sso.clock_skew_seconds'       => 30,
            'glasshouse_sso.nonce_cache_ttl_seconds'  => 600,
            'glasshouse_sso.key_id'                   => '',
            'glasshouse_sso.keys'                     => [],
        ]);

        $svc  = new SignedLaunchTokenService();
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()
            ->withLaunchUrl('https://panel.test')
            ->forModule('glasspanel', 'GlassPanel')
            ->create(['organization_id' => $org->id, 'auth_mode' => 'signed_launch', 'status' => 'active']);
        $user = User::factory()->create(['role' => UserRole::Customer->value, 'organization_id' => $org->id]);

        $token = $svc->generate($link, $user)['token'];

        // Strip the per-module secret — verifier now has only the global secret
        config(['glasshouse_sso.per_module_secrets' => []]);
        $globalSvc = new SignedLaunchTokenService();

        $this->expectException(\InvalidArgumentException::class);
        $globalSvc->verify($token, 'glasspanel');
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
            'email'           => 'hardening@example.test',
            'name'            => 'Hardening Test User',
        ]);
        $issued = (new BackChannelLaunchService())->issueCode($link, $user);
        return [$link, $user, $issued->code];
    }
}
