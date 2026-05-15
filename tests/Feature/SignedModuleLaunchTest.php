<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ModuleLaunchEvent;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignedModuleLaunchTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-signed-launch-secret-long-enough-for-hmac-sha256';

    protected function setUp(): void
    {
        parent::setUp();
        config(['glasshouse_sso.signing_secret' => $this->secret]);
        config(['glasshouse_sso.issuer'         => 'glassportal-test']);
    }

    private function customerUser(?Organization $org = null): User
    {
        return User::factory()->create([
            'role'            => UserRole::Customer->value,
            'organization_id' => $org?->id,
        ]);
    }

    private function signedLink(Organization $org, string $url = 'https://module.test/launch'): OrganizationModuleLink
    {
        return OrganizationModuleLink::factory()->create([
            'organization_id' => $org->id,
            'module_key'      => 'glasspanel',
            'display_name'    => 'GlassPanel',
            'auth_mode'       => 'signed_launch',
            'external_url'    => $url,
            'status'          => 'active',
        ]);
    }

    // -------------------------------------------------------------------------
    // Happy path — signed launch
    // -------------------------------------------------------------------------

    public function test_customer_can_launch_own_signed_module(): void
    {
        $org  = Organization::factory()->create();
        $link = $this->signedLink($org);
        $user = $this->customerUser($org);

        $this->actingAs($user)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertStatus(200)
            ->assertSeeText('Launching securely');
    }

    public function test_signed_launch_shows_handoff_page_not_stub(): void
    {
        $org  = Organization::factory()->create();
        $link = $this->signedLink($org);
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        $response->assertStatus(200);
        // Should show the handoff page, NOT the stub "coming soon" page
        $response->assertDontSeeText('Single Sign-On Coming Soon');
        $response->assertSeeText('Launching securely');
    }

    public function test_signed_launch_creates_audit_event_with_jti(): void
    {
        $org  = Organization::factory()->create();
        $link = $this->signedLink($org);
        $user = $this->customerUser($org);

        $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        $event = ModuleLaunchEvent::where('module_link_id', $link->id)
            ->where('event_type', 'signed_launch_issued')
            ->first();

        $this->assertNotNull($event, 'Expected a signed_launch_issued event');
        $this->assertNotNull($event->metadata['jti'] ?? null, 'JTI must be in event metadata');
        $this->assertNotNull($event->metadata['expires_at'] ?? null, 'expires_at must be in event metadata');
    }

    public function test_launch_event_metadata_does_not_contain_token_or_secret(): void
    {
        $org  = Organization::factory()->create();
        $link = $this->signedLink($org);
        $user = $this->customerUser($org);

        $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        $event = ModuleLaunchEvent::where('module_link_id', $link->id)->first();
        $this->assertNotNull($event);

        $metaJson = json_encode($event->metadata ?? []);
        // The token is 3 dot-separated base64url parts — check for format pattern
        $this->assertStringNotContainsString($this->secret, $metaJson);
        // Verify jti is present but no full token (tokens have exactly 2 dots and are long)
        $jti = $event->metadata['jti'] ?? '';
        $this->assertNotEmpty($jti);
        $this->assertStringNotContainsString('.', $jti, 'JTI must not be the full token');
    }

    public function test_portal_modules_page_does_not_render_signed_token(): void
    {
        $org  = Organization::factory()->create();
        $link = $this->signedLink($org);
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/modules');

        $response->assertStatus(200);
        // The listing page must not contain any signed token (3-part base64url)
        // Tokens are only present on the handoff page
        $content = $response->getContent();
        // A signed token has two dots and is at least 100 chars — use heuristic check
        $this->assertStringNotContainsString('slt=', $content);
        // The signing secret must never appear
        $this->assertStringNotContainsString($this->secret, $content);
    }

    // -------------------------------------------------------------------------
    // Security: org isolation
    // -------------------------------------------------------------------------

    public function test_customer_cannot_launch_another_orgs_module(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $link = $this->signedLink($org1);
        $user = $this->customerUser($org2);

        $this->actingAs($user)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertForbidden();
    }

    public function test_cross_org_launch_does_not_create_audit_event(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $link = $this->signedLink($org1);
        $user = $this->customerUser($org2);

        $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        $this->assertDatabaseCount('module_launch_events', 0);
    }

    // -------------------------------------------------------------------------
    // Denied: inactive
    // -------------------------------------------------------------------------

    public function test_inactive_signed_link_cannot_launch(): void
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()->create([
            'organization_id' => $org->id,
            'auth_mode'       => 'signed_launch',
            'external_url'    => 'https://module.test/launch',
            'status'          => 'inactive',
        ]);
        $user = $this->customerUser($org);

        $this->actingAs($user)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertRedirect('/portal/modules');
    }

    public function test_inactive_link_creates_denied_event(): void
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()->create([
            'organization_id' => $org->id,
            'auth_mode'       => 'signed_launch',
            'external_url'    => 'https://module.test/launch',
            'status'          => 'inactive',
        ]);
        $user = $this->customerUser($org);

        $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        $this->assertDatabaseHas('module_launch_events', [
            'module_link_id' => $link->id,
            'event_type'     => 'denied',
        ]);
    }

    // -------------------------------------------------------------------------
    // Denied: missing signing secret
    // -------------------------------------------------------------------------

    public function test_signed_launch_without_secret_fails_safely_and_audits_failure(): void
    {
        config(['glasshouse_sso.signing_secret' => '']);

        $org  = Organization::factory()->create();
        $link = $this->signedLink($org);
        $user = $this->customerUser($org);

        $this->actingAs($user)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertRedirect('/portal/modules');

        $this->assertDatabaseHas('module_launch_events', [
            'module_link_id' => $link->id,
            'event_type'     => 'failed',
        ]);
    }

    public function test_failure_event_does_not_contain_secret_in_metadata(): void
    {
        config(['glasshouse_sso.signing_secret' => '']);

        $org  = Organization::factory()->create();
        $link = $this->signedLink($org);
        $user = $this->customerUser($org);

        $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        $event = ModuleLaunchEvent::where('module_link_id', $link->id)->first();
        $this->assertNotNull($event);
        $this->assertNull($event->metadata);
    }

    // -------------------------------------------------------------------------
    // Denied: missing external URL
    // -------------------------------------------------------------------------

    public function test_signed_launch_without_external_url_fails_safely(): void
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()->create([
            'organization_id' => $org->id,
            'auth_mode'       => 'signed_launch',
            'external_url'    => null,
            'status'          => 'active',
        ]);
        $user = $this->customerUser($org);

        $this->actingAs($user)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertRedirect('/portal/modules');
    }

    // -------------------------------------------------------------------------
    // Backward compat: standalone external URL still works
    // -------------------------------------------------------------------------

    public function test_external_url_standalone_mode_still_works(): void
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()
            ->withLaunchUrl('https://standalone.test')
            ->create(['organization_id' => $org->id, 'status' => 'active']);
        $user = $this->customerUser($org);

        $this->actingAs($user)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertRedirect('https://standalone.test');
    }

    // -------------------------------------------------------------------------
    // Stub modes still work
    // -------------------------------------------------------------------------

    public function test_shared_session_still_stubs_safely(): void
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()
            ->ssoMode('shared_session')
            ->create(['organization_id' => $org->id, 'status' => 'active']);
        $user = $this->customerUser($org);

        $this->actingAs($user)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertStatus(200)
            ->assertSeeText('Single Sign-On Coming Soon');
    }

    public function test_oauth_still_stubs_safely(): void
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()
            ->ssoMode('oauth')
            ->create(['organization_id' => $org->id, 'status' => 'active']);
        $user = $this->customerUser($org);

        $this->actingAs($user)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertStatus(200)
            ->assertSeeText('Single Sign-On Coming Soon');
    }

    public function test_stub_sso_creates_stubbed_audit_event(): void
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()
            ->ssoMode('oauth')
            ->create(['organization_id' => $org->id, 'status' => 'active']);
        $user = $this->customerUser($org);

        $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        $this->assertDatabaseHas('module_launch_events', [
            'module_link_id' => $link->id,
            'event_type'     => 'stubbed',
        ]);
    }

    // -------------------------------------------------------------------------
    // Portal modules listing: signed_launch shows "Secure launch" label
    // -------------------------------------------------------------------------

    public function test_portal_modules_shows_secure_launch_for_signed_link(): void
    {
        $org  = Organization::factory()->create();
        $this->signedLink($org);
        $user = $this->customerUser($org);

        $this->actingAs($user)
            ->get('/portal/modules')
            ->assertStatus(200)
            ->assertSeeText('Secure launch');
    }

    public function test_portal_modules_signed_link_shows_setup_required_when_secret_missing(): void
    {
        config(['glasshouse_sso.signing_secret' => '']);

        $org  = Organization::factory()->create();
        $this->signedLink($org);
        $user = $this->customerUser($org);

        $this->actingAs($user)
            ->get('/portal/modules')
            ->assertStatus(200)
            ->assertSeeText('Setup required');
    }
}
