<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 29 — admin pilot readiness page. Owner/admin only, read-only, no secrets.
 */
class AdminPilotReadinessPageTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/pilot-readiness')->assertRedirect('/login');
    }

    public function test_customer_is_forbidden(): void
    {
        $this->actingAs($this->user(UserRole::Customer->value))
            ->get('/admin/pilot-readiness')->assertForbidden();
    }

    public function test_staff_is_forbidden(): void
    {
        // Owner/admin only — staff are in the surrounding group but blocked here.
        $this->actingAs($this->user(UserRole::Staff->value))
            ->get('/admin/pilot-readiness')->assertForbidden();
    }

    public function test_admin_can_access_and_sees_categories_and_links(): void
    {
        $this->actingAs($this->user(UserRole::Admin->value))
            ->get('/admin/pilot-readiness')
            ->assertStatus(200)
            ->assertSeeText('Product catalog readiness')
            ->assertSeeText('Security boundary readiness')
            ->assertSeeText('Operator quick links')
            // a quick link to an admin billing area
            ->assertSee(route('admin.billing.products'));
    }

    public function test_page_warns_when_on_legacy_billing_url(): void
    {
        // Simulate the app being reached via the legacy billing runtime URL.
        config(['app.url' => config('pilot.legacy_billing_url')]);

        $this->actingAs($this->user(UserRole::Admin->value))
            ->get('/admin/pilot-readiness')
            ->assertStatus(200)
            ->assertSeeText('Runtime exposure readiness')
            ->assertSeeText('LEGACY billing runtime');
    }

    public function test_page_never_renders_secret_values(): void
    {
        $secret = 'sk_live_PILOT_PAGE_SECRET_MUST_NOT_LEAK';
        $whsec  = 'whsec_PILOT_PAGE_SECRET_MUST_NOT_LEAK';
        config([
            'billing.enabled'               => true,
            'billing.mode'                  => 'stripe',
            'billing.stripe.secret_key'     => $secret,
            'billing.stripe.webhook_secret' => $whsec,
            'billing.checkout.enabled'      => true,
            'billing.webhooks.enabled'      => true,
        ]);

        $content = $this->actingAs($this->user(UserRole::Admin->value))
            ->get('/admin/pilot-readiness')->getContent();

        $this->assertStringNotContainsString($secret, $content);
        $this->assertStringNotContainsString($whsec, $content);
    }
}
