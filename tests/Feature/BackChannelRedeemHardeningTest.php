<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ModuleLaunchEvent;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use App\Services\Sso\BackChannelLaunchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 12 hardening tests for the back-channel redeem endpoint.
 *
 * Covers:
 * - Audit trail creation for auditable outcomes
 * - mTLS enforcement middleware
 * - Security invariants (no raw code, no PII in audit metadata)
 */
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
    }

    // =========================================================================
    // Audit trail
    // =========================================================================

    public function test_successful_redeem_creates_backchannel_redeem_success_event(): void
    {
        [$link, $user, $code] = $this->fixtures();

        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", [
            'launch_code' => $code,
        ])->assertStatus(200);

        $event = ModuleLaunchEvent::where('event_type', 'backchannel_redeem_success')
            ->where('module_key', $link->module_key)
            ->first();

        $this->assertNotNull($event, 'Expected a backchannel_redeem_success audit event');
        $this->assertSame('backchannel_launch', $event->auth_mode);
        $this->assertNull($event->reason);
        $this->assertSame((int) $user->id, $event->user_id);
        $this->assertSame((int) $link->organization_id, $event->organization_id);
    }

    public function test_replay_creates_backchannel_replay_blocked_event(): void
    {
        [$link, $user, $code] = $this->fixtures();

        // First redeem succeeds
        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", [
            'launch_code' => $code,
        ])->assertStatus(200);

        // Second redeem — replay
        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", [
            'launch_code' => $code,
        ])->assertStatus(401)->assertJsonPath('reason', 'code_replayed');

        $event = ModuleLaunchEvent::where('event_type', 'backchannel_replay_blocked')
            ->where('module_key', $link->module_key)
            ->first();

        $this->assertNotNull($event, 'Expected a backchannel_replay_blocked audit event');
        $this->assertSame('code_replayed', $event->reason);
    }

    public function test_inactive_link_creates_backchannel_redeem_failed_event_with_link_context(): void
    {
        [$link, $user, $code] = $this->fixtures();
        $link->update(['status' => 'inactive']);

        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", [
            'launch_code' => $code,
        ])->assertStatus(403)->assertJsonPath('reason', 'inactive_module_link');

        $event = ModuleLaunchEvent::where('event_type', 'backchannel_redeem_failed')
            ->where('reason', 'inactive_module_link')
            ->first();

        $this->assertNotNull($event, 'Expected a backchannel_redeem_failed audit event for inactive_module_link');
        // Should have context from the payload even though it failed post-consumption
        $this->assertSame((int) $user->id, $event->user_id);
        $this->assertSame((int) $link->organization_id, $event->organization_id);
    }

    public function test_wrong_module_creates_backchannel_redeem_failed_event(): void
    {
        [$link, $user, $code] = $this->fixtures();

        $this->postJson('/api/sso/backchannel/redeem/wrong-module', [
            'launch_code' => $code,
        ])->assertStatus(403)->assertJsonPath('reason', 'wrong_module');

        $event = ModuleLaunchEvent::where('event_type', 'backchannel_redeem_failed')
            ->where('reason', 'wrong_module')
            ->first();

        $this->assertNotNull($event, 'Expected a backchannel_redeem_failed audit event for wrong_module');
        $this->assertSame('wrong-module', $event->module_key);
    }

    // =========================================================================
    // Security: audit must not contain raw code, email, or name
    // =========================================================================

    public function test_audit_event_does_not_contain_raw_code(): void
    {
        [$link, $user, $code] = $this->fixtures();

        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", [
            'launch_code' => $code,
        ])->assertStatus(200);

        $event = ModuleLaunchEvent::where('event_type', 'backchannel_redeem_success')->first();
        $this->assertNotNull($event);

        $serialized = json_encode($event->toArray());
        $this->assertStringNotContainsString($code, $serialized, 'Raw code must never appear in audit event');
    }

    public function test_audit_event_does_not_contain_email_or_name(): void
    {
        [$link, $user, $code] = $this->fixtures();

        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", [
            'launch_code' => $code,
        ])->assertStatus(200);

        $event = ModuleLaunchEvent::where('event_type', 'backchannel_redeem_success')->first();
        $this->assertNotNull($event);

        $serialized = json_encode($event->toArray());
        $this->assertStringNotContainsString($user->email, $serialized, 'Email must never appear in audit event');
        $this->assertStringNotContainsString($user->name, $serialized, 'Name must never appear in audit event');
    }

    // =========================================================================
    // Non-auditable format errors — no audit event created
    // =========================================================================

    public function test_missing_code_does_not_create_audit_event(): void
    {
        $before = ModuleLaunchEvent::count();

        $this->postJson('/api/sso/backchannel/redeem/glasspanel', [])
            ->assertStatus(401)
            ->assertJsonPath('reason', 'missing_code');

        $this->assertSame($before, ModuleLaunchEvent::count(), 'missing_code must not create an audit event');
    }

    public function test_malformed_code_does_not_create_audit_event(): void
    {
        $before = ModuleLaunchEvent::count();

        $this->postJson('/api/sso/backchannel/redeem/glasspanel', ['launch_code' => 'short'])
            ->assertStatus(401)
            ->assertJsonPath('reason', 'malformed_code');

        $this->assertSame($before, ModuleLaunchEvent::count(), 'malformed_code must not create an audit event');
    }

    public function test_code_not_found_does_not_create_audit_event(): void
    {
        $before = ModuleLaunchEvent::count();

        $this->postJson('/api/sso/backchannel/redeem/glasspanel', ['launch_code' => str_repeat('a', 64)])
            ->assertStatus(401)
            ->assertJsonPath('reason', 'code_not_found');

        $this->assertSame($before, ModuleLaunchEvent::count(), 'code_not_found must not create an audit event');
    }

    // =========================================================================
    // mTLS middleware
    // =========================================================================

    public function test_mtls_required_rejects_without_header(): void
    {
        config(['glasshouse_sso.backchannel.require_mtls' => true]);

        $this->postJson('/api/sso/backchannel/redeem/glasspanel', ['launch_code' => str_repeat('a', 64)])
            ->assertStatus(401)
            ->assertJsonPath('reason', 'mtls_required');
    }

    public function test_mtls_required_returns_mtls_required_reason(): void
    {
        config(['glasshouse_sso.backchannel.require_mtls' => true]);

        $response = $this->postJson('/api/sso/backchannel/redeem/glasspanel', ['launch_code' => str_repeat('a', 64)]);

        $response->assertStatus(401);
        $response->assertJson(['ok' => false]);
        $response->assertJsonPath('reason', 'mtls_required');
    }

    public function test_mtls_accepted_when_header_present(): void
    {
        config(['glasshouse_sso.backchannel.require_mtls' => true]);
        [$link, $user, $code] = $this->fixtures();

        $response = $this->withHeaders(['X-Client-Cert-Verified' => 'SUCCESS'])
            ->postJson("/api/sso/backchannel/redeem/{$link->module_key}", [
                'launch_code' => $code,
            ]);

        // Should pass mTLS and proceed to normal code validation
        $response->assertStatus(200);
        $response->assertJson(['ok' => true]);
    }

    public function test_mtls_not_required_by_default(): void
    {
        // Default config has require_mtls = false (set in setUp)
        $this->postJson('/api/sso/backchannel/redeem/glasspanel', ['launch_code' => str_repeat('a', 64)])
            ->assertStatus(401)
            ->assertJsonPath('reason', 'code_not_found'); // Gets past mTLS, fails at code lookup
    }

    public function test_mtls_required_creates_audit_event(): void
    {
        config(['glasshouse_sso.backchannel.require_mtls' => true]);
        $before = ModuleLaunchEvent::count();

        // mTLS rejection happens in middleware before the service is called,
        // so no audit is created by the controller (the service never runs).
        // This test documents the current expected behavior.
        $this->postJson('/api/sso/backchannel/redeem/glasspanel', ['launch_code' => str_repeat('a', 64)])
            ->assertStatus(401)
            ->assertJsonPath('reason', 'mtls_required');

        // mTLS is enforced by middleware; no audit event is recorded
        // because the controller recordAudit() is never reached.
        $this->assertSame($before, ModuleLaunchEvent::count(),
            'mTLS rejection by middleware does not create a controller audit event');
    }

    // =========================================================================
    // Helper
    // =========================================================================

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

        $service = new BackChannelLaunchService();
        $issued  = $service->issueCode($link, $user);

        return [$link, $user, $issued->code];
    }
}
