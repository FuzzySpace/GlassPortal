<?php

namespace Tests\Unit\Provisioning;

use App\Models\BillingCustomer;
use App\Models\BillingServiceEntitlement;
use App\Models\Organization;
use App\Models\ProvisioningRequest;
use App\Models\ProvisioningRequestEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 26 — provisioning request model: tables, relationships, helpers, scopes,
 * and secret redaction.
 */
class ProvisioningRequestModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('provisioning_requests'));
        $this->assertTrue(Schema::hasTable('provisioning_request_events'));
    }

    public function test_factories_create_valid_records(): void
    {
        $request = ProvisioningRequest::factory()->create();
        $event   = ProvisioningRequestEvent::factory()->create();

        $this->assertDatabaseHas('provisioning_requests', ['id' => $request->id]);
        $this->assertDatabaseHas('provisioning_request_events', ['id' => $event->id]);
    }

    public function test_relationships_work(): void
    {
        $org         = Organization::factory()->create();
        $customer    = BillingCustomer::factory()->forOrganization($org)->create();
        $entitlement = BillingServiceEntitlement::factory()->create(['billing_customer_id' => $customer->id, 'organization_id' => $org->id]);
        $approver    = User::factory()->create();

        $request = ProvisioningRequest::factory()->create([
            'billing_service_entitlement_id' => $entitlement->id,
            'billing_customer_id'            => $customer->id,
            'organization_id'                => $org->id,
            'user_id'                        => $approver->id,
            'approved_by'                    => $approver->id,
        ]);
        ProvisioningRequestEvent::factory()->create(['provisioning_request_id' => $request->id]);

        $this->assertSame($entitlement->id, $request->entitlement->id);
        $this->assertSame($customer->id, $request->customer->id);
        $this->assertSame($org->id, $request->organization->id);
        $this->assertSame($approver->id, $request->approvedBy->id);
        $this->assertCount(1, $request->events);

        // Reverse relationships.
        $this->assertTrue($entitlement->provisioningRequests->contains($request));
        $this->assertTrue($customer->provisioningRequests->contains($request));
        $this->assertTrue($org->provisioningRequests->contains($request));
    }

    public function test_status_helpers_and_transition_map(): void
    {
        $pending = ProvisioningRequest::factory()->create(['status' => 'pending_approval']);
        $this->assertTrue($pending->isPendingApproval());
        $this->assertTrue($pending->canApprove());
        $this->assertTrue($pending->canReject());
        $this->assertTrue($pending->canCancel());
        $this->assertFalse($pending->canQueue());
        $this->assertFalse($pending->canStart());
        $this->assertFalse($pending->isTerminal());

        $approved = ProvisioningRequest::factory()->status('approved')->create();
        $this->assertTrue($approved->isApproved());
        $this->assertTrue($approved->canQueue());

        $failed = ProvisioningRequest::factory()->status('failed')->create();
        $this->assertTrue($failed->canQueue()); // retry
        $this->assertFalse($failed->isTerminal());

        foreach (['completed', 'rejected', 'cancelled'] as $terminal) {
            $r = ProvisioningRequest::factory()->status($terminal)->create();
            $this->assertTrue($r->isTerminal(), "{$terminal} should be terminal");
            $this->assertFalse($r->canCancel());
        }
    }

    public function test_scopes(): void
    {
        ProvisioningRequest::factory()->create(['status' => 'pending_approval']);
        ProvisioningRequest::factory()->status('completed')->create();
        ProvisioningRequest::factory()->action('terminate')->status('approved')->create();

        $this->assertSame(1, ProvisioningRequest::pendingApproval()->count());
        $this->assertSame(2, ProvisioningRequest::open()->count()); // pending_approval + approved (completed is terminal)
        $this->assertSame(1, ProvisioningRequest::forAction('terminate')->count());
    }

    public function test_redaction_hides_secret_shaped_values(): void
    {
        $request = ProvisioningRequest::factory()->create([
            'payload' => [
                'plan'           => 'pro',
                'api_token'      => 'TOKVAL',
                'stripe_secret'  => 'STRIPEVAL',
                'password'       => 'PWVAL',
                'signing_secret' => 'SIGNVAL',
                'private_key'    => 'PKVAL',
                'nested'         => ['secret' => 'NESTEDVAL', 'ok' => 'fine'],
            ],
        ]);

        $safe = $request->safePayload();
        $json = json_encode($safe);

        $this->assertSame('pro', $safe['plan']);
        $this->assertSame('fine', $safe['nested']['ok']);
        foreach (['TOKVAL', 'STRIPEVAL', 'PWVAL', 'SIGNVAL', 'PKVAL', 'NESTEDVAL'] as $secretValue) {
            $this->assertStringNotContainsString($secretValue, $json);
        }
        $this->assertSame('[redacted]', $safe['api_token']);
    }
}
