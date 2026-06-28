<?php

namespace Tests\Unit\Billing;

use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\BillingSelfServiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 28 — BillingSelfServiceService: billing-scope resolution, strict
 * cross-organization isolation, and the dashboard summary.
 */
class BillingSelfServiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): BillingSelfServiceService
    {
        return app(BillingSelfServiceService::class);
    }

    public function test_scope_includes_org_and_user_customers(): void
    {
        $org   = Organization::factory()->create();
        $user  = User::factory()->create(['organization_id' => $org->id, 'role' => 'customer']);
        $orgC  = BillingCustomer::factory()->forOrganization($org)->create();
        $userC = BillingCustomer::factory()->forUser($user)->create();
        BillingCustomer::factory()->forOrganization(Organization::factory()->create())->create(); // other org

        $ids = $this->service()->billingCustomerIds($user);

        sort($ids);
        $expected = [$orgC->id, $userC->id];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function test_scoped_queries_exclude_other_organisations(): void
    {
        $org  = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => 'customer']);
        $mine = BillingCustomer::factory()->forOrganization($org)->create();

        $otherOrg   = Organization::factory()->create();
        $otherCust  = BillingCustomer::factory()->forOrganization($otherOrg)->create();

        BillingSubscription::factory()->create(['billing_customer_id' => $mine->id]);
        BillingSubscription::factory()->create(['billing_customer_id' => $otherCust->id]);
        BillingInvoice::factory()->create(['billing_customer_id' => $mine->id]);
        BillingInvoice::factory()->create(['billing_customer_id' => $otherCust->id]);
        BillingPayment::factory()->create(['billing_customer_id' => $mine->id]);

        $this->assertSame(1, $this->service()->subscriptionsQuery($user)->count());
        $this->assertSame(1, $this->service()->invoicesQuery($user)->count());
        $this->assertSame(1, $this->service()->paymentsQuery($user)->count());
    }

    public function test_ownership_checks_reject_cross_org_records(): void
    {
        $org  = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => 'customer']);
        $mine = BillingCustomer::factory()->forOrganization($org)->create();

        $otherOrg  = Organization::factory()->create();
        $otherCust = BillingCustomer::factory()->forOrganization($otherOrg)->create();

        $ownSub   = BillingSubscription::factory()->create(['billing_customer_id' => $mine->id]);
        $otherSub = BillingSubscription::factory()->create(['billing_customer_id' => $otherCust->id]);

        $this->assertTrue($this->service()->ownsSubscription($user, $ownSub));
        $this->assertFalse($this->service()->ownsSubscription($user, $otherSub));
    }

    public function test_dashboard_summarises_only_owned_records(): void
    {
        $org  = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => 'customer']);
        $mine = BillingCustomer::factory()->forOrganization($org)->create();

        BillingSubscription::factory()->create(['billing_customer_id' => $mine->id, 'status' => 'active']);
        BillingSubscription::factory()->create(['billing_customer_id' => $mine->id, 'status' => 'past_due']);

        $otherCust = BillingCustomer::factory()->forOrganization(Organization::factory()->create())->create();
        BillingSubscription::factory()->create(['billing_customer_id' => $otherCust->id, 'status' => 'active']);

        $data = $this->service()->dashboard($user);

        $this->assertTrue($data['hasBillingScope']);
        $this->assertSame(1, $data['activeSubscriptions']->count());
        $this->assertSame(1, $data['pastDueSubscriptions']->count());
        $this->assertNotEmpty($data['warnings']); // past-due warning present
    }

    public function test_user_without_billing_records_has_no_scope(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'organization_id' => null]);

        $this->assertFalse($this->service()->hasBillingScope($user));
        $this->assertFalse($this->service()->dashboard($user)['hasBillingScope']);
    }
}
