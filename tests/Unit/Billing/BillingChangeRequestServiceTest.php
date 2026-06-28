<?php

namespace Tests\Unit\Billing;

use App\Models\BillingChangeRequest;
use App\Models\BillingCustomer;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\BillingChangeRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 28 — BillingChangeRequestService. Workflow records only: submit +
 * lifecycle transitions, ownership enforced, NO Stripe/subscription/infra
 * mutation.
 */
class BillingChangeRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): BillingChangeRequestService
    {
        return app(BillingChangeRequestService::class);
    }

    private function customerWithSubscription(): array
    {
        $org  = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => 'customer']);
        $cust = BillingCustomer::factory()->forOrganization($org)->create();
        $plan = BillingPlan::factory()->create();
        $sub  = BillingSubscription::factory()->create([
            'billing_customer_id' => $cust->id,
            'billing_plan_id'     => $plan->id,
            'status'              => 'active',
        ]);

        return [$user, $sub, $plan];
    }

    // -------------------------------------------------------------------------
    // Submit
    // -------------------------------------------------------------------------

    public function test_submit_billing_support_request_succeeds(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $result = $this->service()->submit($user, BillingChangeRequest::TYPE_BILLING_SUPPORT, [
            'customer_message' => 'I have a question about my bill.',
        ]);

        $this->assertTrue($result->ok);
        $this->assertSame(BillingChangeRequest::STATUS_SUBMITTED, $result->changeRequest->status);
        $this->assertSame($user->getKey(), $result->changeRequest->user_id);
    }

    public function test_submit_cancellation_links_owned_subscription(): void
    {
        [$user, $sub] = $this->customerWithSubscription();

        $result = $this->service()->submit($user, BillingChangeRequest::TYPE_CANCEL_SUBSCRIPTION, [
            'billing_subscription_id' => $sub->id,
        ]);

        $this->assertTrue($result->ok);
        $this->assertSame($sub->id, $result->changeRequest->billing_subscription_id);
        $this->assertSame($sub->billing_plan_id, $result->changeRequest->billing_plan_id);
    }

    public function test_submit_change_plan_requires_valid_active_target_plan(): void
    {
        [$user, $sub] = $this->customerWithSubscription();
        $target = BillingPlan::factory()->create(['status' => 'active']);

        $ok = $this->service()->submit($user, BillingChangeRequest::TYPE_CHANGE_PLAN, [
            'billing_subscription_id' => $sub->id,
            'requested_plan_id'       => $target->id,
        ]);
        $this->assertTrue($ok->ok);
        $this->assertSame($target->id, $ok->changeRequest->requested_plan_id);

        // Missing/invalid target plan fails safe.
        $bad = $this->service()->submit($user, BillingChangeRequest::TYPE_CHANGE_PLAN, [
            'billing_subscription_id' => $sub->id,
            'requested_plan_id'       => null,
        ]);
        $this->assertFalse($bad->ok);
    }

    public function test_submit_rejects_another_organisations_subscription(): void
    {
        [$user] = $this->customerWithSubscription();

        $otherOrg  = Organization::factory()->create();
        $otherCust = BillingCustomer::factory()->forOrganization($otherOrg)->create();
        $otherSub  = BillingSubscription::factory()->create(['billing_customer_id' => $otherCust->id]);

        $result = $this->service()->submit($user, BillingChangeRequest::TYPE_CANCEL_SUBSCRIPTION, [
            'billing_subscription_id' => $otherSub->id,
        ]);

        $this->assertFalse($result->ok);
        $this->assertSame('forbidden', $result->status);
        $this->assertSame(0, BillingChangeRequest::count());
    }

    public function test_submit_rejects_unknown_type(): void
    {
        $user   = User::factory()->create(['role' => 'customer']);
        $result = $this->service()->submit($user, 'do_something_weird', []);

        $this->assertFalse($result->ok);
        $this->assertSame(0, BillingChangeRequest::count());
    }

    public function test_request_keys_are_unique(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $keys = [];
        for ($i = 0; $i < 5; $i++) {
            $keys[] = $this->service()->submit($user, BillingChangeRequest::TYPE_BILLING_SUPPORT, [])->changeRequest->request_key;
        }

        $this->assertCount(5, array_unique($keys));
    }

    // -------------------------------------------------------------------------
    // Customer cancel
    // -------------------------------------------------------------------------

    public function test_customer_can_cancel_own_submitted_request(): void
    {
        $user    = User::factory()->create(['role' => 'customer']);
        $request = BillingChangeRequest::factory()->forUser($user)->create(['status' => BillingChangeRequest::STATUS_SUBMITTED]);

        $result = $this->service()->customerCancel($user, $request);

        $this->assertTrue($result->ok);
        $this->assertSame(BillingChangeRequest::STATUS_CANCELLED, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->cancelled_at);
    }

    public function test_customer_cannot_cancel_request_under_review(): void
    {
        $user    = User::factory()->create(['role' => 'customer']);
        $request = BillingChangeRequest::factory()->forUser($user)->create(['status' => BillingChangeRequest::STATUS_UNDER_REVIEW]);

        $result = $this->service()->customerCancel($user, $request);

        $this->assertFalse($result->ok);
        $this->assertSame(BillingChangeRequest::STATUS_UNDER_REVIEW, $request->fresh()->status);
    }

    public function test_customer_cannot_cancel_another_users_request(): void
    {
        $user    = User::factory()->create(['role' => 'customer']);
        $other   = User::factory()->create(['role' => 'customer']);
        $request = BillingChangeRequest::factory()->forUser($other)->create(['status' => BillingChangeRequest::STATUS_SUBMITTED]);

        $result = $this->service()->customerCancel($user, $request);

        $this->assertFalse($result->ok);
        $this->assertSame('forbidden', $result->status);
    }

    // -------------------------------------------------------------------------
    // Admin workflow
    // -------------------------------------------------------------------------

    public function test_admin_lifecycle_under_review_approve_complete(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $request = BillingChangeRequest::factory()->create(['status' => BillingChangeRequest::STATUS_SUBMITTED]);

        $this->assertTrue($this->service()->markUnderReview($request, $admin, 'looking into it')->ok);
        $request->refresh();
        $this->assertSame(BillingChangeRequest::STATUS_UNDER_REVIEW, $request->status);
        $this->assertSame($admin->getKey(), $request->reviewed_by);

        $this->assertTrue($this->service()->approve($request, $admin)->ok);
        $request->refresh();
        $this->assertSame(BillingChangeRequest::STATUS_APPROVED, $request->status);

        $this->assertTrue($this->service()->complete($request, $admin)->ok);
        $request->refresh();
        $this->assertSame(BillingChangeRequest::STATUS_COMPLETED, $request->status);
        $this->assertNotNull($request->completed_at);
    }

    public function test_admin_invalid_transition_is_rejected(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $request = BillingChangeRequest::factory()->create(['status' => BillingChangeRequest::STATUS_SUBMITTED]);

        // submitted → completed is not allowed.
        $result = $this->service()->complete($request, $admin);

        $this->assertFalse($result->ok);
        $this->assertSame('invalid_transition', $result->status);
        $this->assertSame(BillingChangeRequest::STATUS_SUBMITTED, $request->fresh()->status);
    }

    public function test_admin_notes_are_appended(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $request = BillingChangeRequest::factory()->create(['status' => BillingChangeRequest::STATUS_SUBMITTED]);

        $this->service()->markUnderReview($request, $admin, 'first note');
        $this->service()->approve($request->fresh(), $admin, 'second note');

        $notes = $request->fresh()->admin_notes;
        $this->assertStringContainsString('first note', $notes);
        $this->assertStringContainsString('second note', $notes);
    }

    // -------------------------------------------------------------------------
    // Hard boundaries
    // -------------------------------------------------------------------------

    public function test_workflow_never_makes_outbound_calls_or_mutates_subscription(): void
    {
        Http::fake();

        [$user, $sub] = $this->customerWithSubscription();
        $admin = User::factory()->create(['role' => 'admin']);

        $request = $this->service()->submit($user, BillingChangeRequest::TYPE_CANCEL_SUBSCRIPTION, [
            'billing_subscription_id' => $sub->id,
        ])->changeRequest;

        $this->service()->markUnderReview($request, $admin);
        $this->service()->approve($request->fresh(), $admin);
        $this->service()->complete($request->fresh(), $admin);

        // No Stripe / SIONA / infrastructure calls, and the subscription itself
        // is untouched — these are workflow records only.
        Http::assertNothingSent();
        $this->assertSame('active', $sub->fresh()->status);
    }
}
