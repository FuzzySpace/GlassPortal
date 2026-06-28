<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BillingChangeRequest;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 28 — admin billing change request review workflow. Owner/admin only;
 * transitions are workflow-only (no Stripe / infrastructure mutation).
 */
class AdminBillingChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin->value]);
    }

    private function noCsrf(): static
    {
        return $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function action(User $actor, BillingChangeRequest $request, string $action): \Illuminate\Testing\TestResponse
    {
        return $this->noCsrf()->actingAs($actor)
            ->post(route('admin.billing.change-requests.action', [$request, $action]));
    }

    // -------------------------------------------------------------------------
    // Access control
    // -------------------------------------------------------------------------

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/admin/billing/change-requests')->assertRedirect('/login');
    }

    public function test_customer_and_staff_are_forbidden(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer->value]);
        $staff    = User::factory()->create(['role' => UserRole::Staff->value]);

        $this->actingAs($customer)->get('/admin/billing/change-requests')->assertForbidden();
        // Owner/admin only — staff are in the surrounding group but blocked here.
        $this->actingAs($staff)->get('/admin/billing/change-requests')->assertForbidden();
    }

    public function test_admin_can_list_and_view_requests(): void
    {
        $request = BillingChangeRequest::factory()->type(BillingChangeRequest::TYPE_CANCEL_SUBSCRIPTION)->create();

        $this->actingAs($this->admin())->get('/admin/billing/change-requests')
            ->assertStatus(200)
            ->assertSeeText('Cancel subscription');

        $this->actingAs($this->admin())->get(route('admin.billing.change-requests.show', $request))
            ->assertStatus(200)
            ->assertSeeText($request->request_key);
    }

    // -------------------------------------------------------------------------
    // Workflow
    // -------------------------------------------------------------------------

    public function test_admin_can_mark_under_review(): void
    {
        $request = BillingChangeRequest::factory()->create(['status' => BillingChangeRequest::STATUS_SUBMITTED]);

        $this->action($this->admin(), $request, 'under-review')
            ->assertRedirect(route('admin.billing.change-requests.show', $request));

        $this->assertSame(BillingChangeRequest::STATUS_UNDER_REVIEW, $request->fresh()->status);
    }

    public function test_admin_can_approve(): void
    {
        $request = BillingChangeRequest::factory()->underReview()->create();

        $this->action($this->admin(), $request, 'approve');

        $this->assertSame(BillingChangeRequest::STATUS_APPROVED, $request->fresh()->status);
    }

    public function test_admin_can_reject(): void
    {
        $request = BillingChangeRequest::factory()->create(['status' => BillingChangeRequest::STATUS_SUBMITTED]);

        $this->action($this->admin(), $request, 'reject');

        $this->assertSame(BillingChangeRequest::STATUS_REJECTED, $request->fresh()->status);
    }

    public function test_admin_can_complete_an_approved_request(): void
    {
        $request = BillingChangeRequest::factory()->create(['status' => BillingChangeRequest::STATUS_APPROVED]);

        $this->action($this->admin(), $request, 'complete');

        $request->refresh();
        $this->assertSame(BillingChangeRequest::STATUS_COMPLETED, $request->status);
        $this->assertNotNull($request->completed_at);
    }

    public function test_invalid_transition_redirects_with_error_and_keeps_status(): void
    {
        // submitted → completed is not allowed.
        $request = BillingChangeRequest::factory()->create(['status' => BillingChangeRequest::STATUS_SUBMITTED]);

        $this->action($this->admin(), $request, 'complete')->assertSessionHas('error');

        $this->assertSame(BillingChangeRequest::STATUS_SUBMITTED, $request->fresh()->status);
    }

    public function test_admin_note_is_recorded_on_action(): void
    {
        $request = BillingChangeRequest::factory()->create(['status' => BillingChangeRequest::STATUS_SUBMITTED]);

        $this->noCsrf()->actingAs($this->admin())
            ->post(route('admin.billing.change-requests.action', [$request, 'under-review']), [
                'admin_notes' => 'Reviewed and contacting the customer.',
            ]);

        $this->assertStringContainsString('Reviewed and contacting the customer.', (string) $request->fresh()->admin_notes);
    }

    // -------------------------------------------------------------------------
    // Secret hygiene
    // -------------------------------------------------------------------------

    public function test_detail_redacts_secret_metadata(): void
    {
        $request = BillingChangeRequest::factory()->create([
            'metadata' => ['stripe_secret' => 'SK_ADMIN_CR_LEAK', 'webhook_secret' => 'WH_ADMIN_CR_LEAK'],
        ]);

        $content = $this->actingAs($this->admin())
            ->get(route('admin.billing.change-requests.show', $request))
            ->getContent();

        $this->assertStringNotContainsString('SK_ADMIN_CR_LEAK', $content);
        $this->assertStringNotContainsString('WH_ADMIN_CR_LEAK', $content);
    }
}
