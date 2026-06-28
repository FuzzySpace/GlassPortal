<?php

namespace Tests\Unit\Billing;

use App\Models\BillingChangeRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 28 — BillingChangeRequest model: lifecycle state machine, customer
 * cancel rule, scopes, labels, and secret redaction.
 */
class BillingChangeRequestModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_transition_map_allows_only_listed_transitions(): void
    {
        $submitted = BillingChangeRequest::factory()->create(['status' => BillingChangeRequest::STATUS_SUBMITTED]);
        $this->assertTrue($submitted->canTransitionTo(BillingChangeRequest::STATUS_UNDER_REVIEW));
        $this->assertTrue($submitted->canTransitionTo(BillingChangeRequest::STATUS_APPROVED));
        $this->assertFalse($submitted->canTransitionTo(BillingChangeRequest::STATUS_COMPLETED));

        $approved = BillingChangeRequest::factory()->create(['status' => BillingChangeRequest::STATUS_APPROVED]);
        $this->assertTrue($approved->canTransitionTo(BillingChangeRequest::STATUS_COMPLETED));
        $this->assertFalse($approved->canTransitionTo(BillingChangeRequest::STATUS_UNDER_REVIEW));
    }

    public function test_terminal_statuses_allow_no_transitions(): void
    {
        foreach (BillingChangeRequest::TERMINAL_STATUSES as $status) {
            $request = BillingChangeRequest::factory()->create(['status' => $status]);
            $this->assertTrue($request->isTerminal());
            $this->assertSame([], BillingChangeRequest::TRANSITIONS[$status]);
        }
    }

    public function test_is_customer_cancellable_only_while_submitted(): void
    {
        $submitted = BillingChangeRequest::factory()->create(['status' => BillingChangeRequest::STATUS_SUBMITTED]);
        $this->assertTrue($submitted->isCustomerCancellable());

        foreach ([
            BillingChangeRequest::STATUS_UNDER_REVIEW,
            BillingChangeRequest::STATUS_APPROVED,
            BillingChangeRequest::STATUS_REJECTED,
            BillingChangeRequest::STATUS_COMPLETED,
            BillingChangeRequest::STATUS_CANCELLED,
        ] as $status) {
            $request = BillingChangeRequest::factory()->create(['status' => $status]);
            $this->assertFalse($request->isCustomerCancellable(), "{$status} must not be customer-cancellable");
        }
    }

    public function test_scopes_filter_by_org_user_and_open(): void
    {
        $org  = Organization::factory()->create();
        $user = User::factory()->create();

        BillingChangeRequest::factory()->forOrganization($org)->create();
        BillingChangeRequest::factory()->forUser($user)->create();
        BillingChangeRequest::factory()->create(['status' => BillingChangeRequest::STATUS_COMPLETED]);

        $this->assertSame(1, BillingChangeRequest::forOrganization($org->id)->count());
        $this->assertSame(1, BillingChangeRequest::forUser($user->id)->count());
        // 2 of the 3 are non-terminal (submitted) → open.
        $this->assertSame(2, BillingChangeRequest::open()->count());
    }

    public function test_type_label_is_human_readable(): void
    {
        $request = BillingChangeRequest::factory()->type(BillingChangeRequest::TYPE_CANCEL_SUBSCRIPTION)->create();
        $this->assertSame('Cancel subscription', $request->typeLabel());
    }

    public function test_safe_metadata_redacts_secret_keys(): void
    {
        $request = BillingChangeRequest::factory()->create([
            'metadata' => [
                'note'           => 'visible',
                'api_token'      => 'TOK_MUST_NOT_LEAK',
                'stripe_secret'  => 'SK_MUST_NOT_LEAK',
                'signing_secret' => 'SIG_MUST_NOT_LEAK',
                'webhook_secret' => 'WH_MUST_NOT_LEAK',
                'private_key'    => 'PK_MUST_NOT_LEAK',
                'password'       => 'PW_MUST_NOT_LEAK',
            ],
        ]);

        $safe = $request->safeMetadata();
        $json = (string) json_encode($safe);

        $this->assertSame('visible', $safe['note']);
        foreach (['api_token', 'stripe_secret', 'signing_secret', 'webhook_secret', 'private_key', 'password'] as $key) {
            $this->assertSame('[redacted]', $safe[$key]);
        }
        foreach (['TOK_MUST_NOT_LEAK', 'SK_MUST_NOT_LEAK', 'SIG_MUST_NOT_LEAK', 'WH_MUST_NOT_LEAK', 'PK_MUST_NOT_LEAK', 'PW_MUST_NOT_LEAK'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }
}
