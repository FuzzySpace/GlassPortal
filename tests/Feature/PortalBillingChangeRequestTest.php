<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BillingChangeRequest;
use App\Models\BillingCustomer;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 28 — customer billing change request submission + self-cancel.
 * Workflow records only; ownership strictly enforced.
 */
class PortalBillingChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    private function noCsrf(): static
    {
        return $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /** @return array{0: User, 1: BillingSubscription} */
    private function customerWithSubscription(): array
    {
        $org  = Organization::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Customer->value, 'organization_id' => $org->id]);
        $cust = BillingCustomer::factory()->forOrganization($org)->create();
        $sub  = BillingSubscription::factory()->create(['billing_customer_id' => $cust->id, 'status' => 'active']);

        return [$user, $sub];
    }

    // -------------------------------------------------------------------------
    // Access
    // -------------------------------------------------------------------------

    public function test_guest_redirected_from_create(): void
    {
        $this->get('/portal/billing/change-requests/create')->assertRedirect('/login');
    }

    public function test_customer_can_view_create_form(): void
    {
        [$user] = $this->customerWithSubscription();
        $this->actingAs($user)->get('/portal/billing/change-requests/create')->assertStatus(200);
    }

    public function test_customer_cannot_access_admin_change_requests(): void
    {
        [$user] = $this->customerWithSubscription();
        $this->actingAs($user)->get('/admin/billing/change-requests')->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Submit
    // -------------------------------------------------------------------------

    public function test_customer_can_submit_cancellation_request(): void
    {
        [$user, $sub] = $this->customerWithSubscription();

        $this->noCsrf()->actingAs($user)->post(route('portal.billing.change-requests.store'), [
            'request_type'            => BillingChangeRequest::TYPE_CANCEL_SUBSCRIPTION,
            'billing_subscription_id' => $sub->id,
            'customer_message'        => 'Please cancel at period end.',
        ])->assertRedirect();

        $this->assertDatabaseHas('billing_change_requests', [
            'user_id'                 => $user->id,
            'request_type'            => BillingChangeRequest::TYPE_CANCEL_SUBSCRIPTION,
            'billing_subscription_id' => $sub->id,
            'status'                  => BillingChangeRequest::STATUS_SUBMITTED,
        ]);
    }

    public function test_customer_can_submit_billing_support_request(): void
    {
        [$user] = $this->customerWithSubscription();

        $this->noCsrf()->actingAs($user)->post(route('portal.billing.change-requests.store'), [
            'request_type'     => BillingChangeRequest::TYPE_BILLING_SUPPORT,
            'customer_message' => 'A question about my charges.',
        ])->assertRedirect();

        $this->assertDatabaseHas('billing_change_requests', [
            'user_id'      => $user->id,
            'request_type' => BillingChangeRequest::TYPE_BILLING_SUPPORT,
        ]);
    }

    public function test_customer_can_submit_valid_plan_change_request(): void
    {
        [$user, $sub] = $this->customerWithSubscription();
        $target = BillingPlan::factory()->create(['status' => 'active']);

        $this->noCsrf()->actingAs($user)->post(route('portal.billing.change-requests.store'), [
            'request_type'            => BillingChangeRequest::TYPE_CHANGE_PLAN,
            'billing_subscription_id' => $sub->id,
            'requested_plan_id'       => $target->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('billing_change_requests', [
            'request_type'      => BillingChangeRequest::TYPE_CHANGE_PLAN,
            'requested_plan_id' => $target->id,
        ]);
    }

    public function test_customer_cannot_submit_request_for_another_orgs_subscription(): void
    {
        [$user] = $this->customerWithSubscription();
        $otherCust = BillingCustomer::factory()->forOrganization(Organization::factory()->create())->create();
        $otherSub  = BillingSubscription::factory()->create(['billing_customer_id' => $otherCust->id]);

        $this->noCsrf()->actingAs($user)->post(route('portal.billing.change-requests.store'), [
            'request_type'            => BillingChangeRequest::TYPE_CANCEL_SUBSCRIPTION,
            'billing_subscription_id' => $otherSub->id,
        ])->assertRedirect(route('portal.billing.change-requests.create'));

        $this->assertDatabaseMissing('billing_change_requests', ['billing_subscription_id' => $otherSub->id]);
    }

    public function test_invalid_type_fails_validation(): void
    {
        [$user] = $this->customerWithSubscription();

        $this->noCsrf()->actingAs($user)->post(route('portal.billing.change-requests.store'), [
            'request_type' => 'not_a_real_type',
        ])->assertSessionHasErrors('request_type');

        $this->assertSame(0, BillingChangeRequest::count());
    }

    public function test_submitted_request_keys_are_unique(): void
    {
        [$user] = $this->customerWithSubscription();

        for ($i = 0; $i < 3; $i++) {
            $this->noCsrf()->actingAs($user)->post(route('portal.billing.change-requests.store'), [
                'request_type' => BillingChangeRequest::TYPE_BILLING_SUPPORT,
            ]);
        }

        $this->assertSame(3, BillingChangeRequest::distinct('request_key')->count('request_key'));
    }

    // -------------------------------------------------------------------------
    // View + cancel own
    // -------------------------------------------------------------------------

    public function test_customer_cannot_view_another_users_request(): void
    {
        [$user] = $this->customerWithSubscription();
        $otherReq = BillingChangeRequest::factory()->create(); // belongs to a different factory user/org

        $this->actingAs($user)->get(route('portal.billing.change-requests.show', $otherReq))->assertNotFound();
    }

    public function test_customer_can_cancel_own_pending_request(): void
    {
        [$user] = $this->customerWithSubscription();
        $request = BillingChangeRequest::factory()->forUser($user)->create(['status' => BillingChangeRequest::STATUS_SUBMITTED]);

        $this->noCsrf()->actingAs($user)
            ->post(route('portal.billing.change-requests.cancel', $request))
            ->assertRedirect(route('portal.billing.change-requests.show', $request));

        $this->assertSame(BillingChangeRequest::STATUS_CANCELLED, $request->fresh()->status);
    }

    public function test_customer_cannot_cancel_reviewed_request(): void
    {
        [$user] = $this->customerWithSubscription();
        $request = BillingChangeRequest::factory()->forUser($user)->create(['status' => BillingChangeRequest::STATUS_UNDER_REVIEW]);

        $this->noCsrf()->actingAs($user)->post(route('portal.billing.change-requests.cancel', $request));

        $this->assertSame(BillingChangeRequest::STATUS_UNDER_REVIEW, $request->fresh()->status);
    }

    public function test_customer_cannot_cancel_another_users_request(): void
    {
        [$user] = $this->customerWithSubscription();
        $otherReq = BillingChangeRequest::factory()->create(['status' => BillingChangeRequest::STATUS_SUBMITTED]);

        $this->noCsrf()->actingAs($user)
            ->post(route('portal.billing.change-requests.cancel', $otherReq))
            ->assertNotFound();

        $this->assertSame(BillingChangeRequest::STATUS_SUBMITTED, $otherReq->fresh()->status);
    }
}
