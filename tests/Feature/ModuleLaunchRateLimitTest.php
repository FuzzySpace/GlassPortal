<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ModuleLaunchEvent;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ModuleLaunchRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'rate-limit-test-secret-long-enough-for-hmac-sha256-testing';

    protected function setUp(): void
    {
        parent::setUp();
        config(['glasshouse_sso.signing_secret'        => $this->secret]);
        config(['glasshouse_sso.issuer'                => 'glassportal-test']);
        config(['glasshouse_sso.rate_limit_per_minute' => 20]);
        RateLimiter::clear('module-launch:*');
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('module-launch:*');
        parent::tearDown();
    }

    private function customerUser(Organization $org): User
    {
        return User::factory()->create([
            'role'            => UserRole::Customer->value,
            'organization_id' => $org->id,
        ]);
    }

    private function activeLink(Organization $org): OrganizationModuleLink
    {
        return OrganizationModuleLink::factory()
            ->withLaunchUrl('https://module.test')
            ->forModule('dns', 'DNS')
            ->create(['organization_id' => $org->id, 'status' => 'active']);
    }

    // -------------------------------------------------------------------------
    // Normal flow — rate limit not yet hit
    // -------------------------------------------------------------------------

    public function test_launch_succeeds_within_rate_limit(): void
    {
        $org  = Organization::factory()->create();
        $link = $this->activeLink($org);
        $user = $this->customerUser($org);

        config(['glasshouse_sso.rate_limit_per_minute' => 5]);

        // First attempt should succeed
        $this->actingAs($user)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertRedirect('https://module.test');
    }

    // -------------------------------------------------------------------------
    // Rate limit exceeded
    // -------------------------------------------------------------------------

    public function test_launch_is_blocked_when_rate_limit_exceeded(): void
    {
        $org  = Organization::factory()->create();
        $link = $this->activeLink($org);
        $user = $this->customerUser($org);

        // Exhaust the limit manually
        $rateLimitKey = 'module-launch:' . $user->id . ':' . $link->id;
        config(['glasshouse_sso.rate_limit_per_minute' => 2]);
        RateLimiter::hit($rateLimitKey, 60);
        RateLimiter::hit($rateLimitKey, 60);

        $this->actingAs($user)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertRedirect('/portal/modules');
    }

    public function test_rate_limited_launch_creates_audit_event(): void
    {
        $org  = Organization::factory()->create();
        $link = $this->activeLink($org);
        $user = $this->customerUser($org);

        // Exhaust the limit
        $rateLimitKey = 'module-launch:' . $user->id . ':' . $link->id;
        config(['glasshouse_sso.rate_limit_per_minute' => 1]);
        RateLimiter::hit($rateLimitKey, 60);

        $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        $this->assertDatabaseHas('module_launch_events', [
            'module_link_id' => $link->id,
            'user_id'        => $user->id,
            'event_type'     => 'rate_limited',
        ]);
    }

    public function test_rate_limited_event_has_no_metadata(): void
    {
        $org  = Organization::factory()->create();
        $link = $this->activeLink($org);
        $user = $this->customerUser($org);

        $rateLimitKey = 'module-launch:' . $user->id . ':' . $link->id;
        config(['glasshouse_sso.rate_limit_per_minute' => 1]);
        RateLimiter::hit($rateLimitKey, 60);

        $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        $event = ModuleLaunchEvent::where('event_type', 'rate_limited')->first();
        $this->assertNotNull($event);
        $this->assertNull($event->metadata);
    }

    // -------------------------------------------------------------------------
    // Cross-org: rate limit check fires after the 403 ownership guard
    // -------------------------------------------------------------------------

    public function test_cross_org_still_gets_403_not_rate_limited(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $link = $this->activeLink($org1);
        $user = $this->customerUser($org2);

        // Exhaust whatever limit applies
        config(['glasshouse_sso.rate_limit_per_minute' => 1]);

        $this->actingAs($user)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertForbidden();

        $this->assertDatabaseCount('module_launch_events', 0);
    }

    // -------------------------------------------------------------------------
    // Rate limit key is per-user-per-link (not global)
    // -------------------------------------------------------------------------

    public function test_rate_limit_is_per_user_per_link(): void
    {
        $org   = Organization::factory()->create();
        $link  = $this->activeLink($org);
        $user1 = $this->customerUser($org);
        $user2 = $this->customerUser($org);

        config(['glasshouse_sso.rate_limit_per_minute' => 1]);

        // Exhaust limit for user1
        RateLimiter::hit('module-launch:' . $user1->id . ':' . $link->id, 60);

        // user1 is blocked
        $this->actingAs($user1)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertRedirect('/portal/modules');

        // user2 is NOT blocked (different key)
        $this->actingAs($user2)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertRedirect('https://module.test');
    }
}
