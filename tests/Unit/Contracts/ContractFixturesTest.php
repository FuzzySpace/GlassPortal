<?php

namespace Tests\Unit\Contracts;

use App\Models\BillingCheckoutSession;
use App\Models\BillingChangeRequest;
use App\Models\BillingServiceEntitlement;
use App\Models\BillingSubscription;
use App\Models\ProvisioningRequest;
use PHPUnit\Framework\TestCase;

/**
 * Phase 29D — contract fixture guard. Freezes the v1 cross-system payload
 * shapes documented in docs/state/sdk-contract-map.md. If a model enum or a
 * fixture drifts, these tests fail, forcing a deliberate contract bump instead
 * of silent drift between GlassPortal, GlassBilling, and GlassPanel.
 *
 * Pure PHPUnit (no Laravel app boot needed) — reads fixtures + class constants.
 */
class ContractFixturesTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../Fixtures/contracts';

    private const EXPECTED_FIXTURES = [
        'customer',
        'product-plan',
        'checkout-session',
        'subscription',
        'invoice-payment',
        'entitlement',
        'provisioning-request',
        'provider-reference',
        'lifecycle-status',
    ];

    /** @return array<string,mixed> */
    private function fixture(string $name): array
    {
        $path = self::FIXTURE_DIR . "/{$name}.json";
        $this->assertFileExists($path, "Contract fixture missing: {$name}.json");

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, "Fixture {$name}.json is not valid JSON");

        return $decoded;
    }

    public function test_all_nine_contract_fixtures_exist_and_are_versioned(): void
    {
        foreach (self::EXPECTED_FIXTURES as $name) {
            $fixture = $this->fixture($name);
            $this->assertSame($name, $fixture['contract'] ?? null, "{$name}.json contract field mismatch");
            $this->assertSame('v1', $fixture['version'] ?? null, "{$name}.json must declare version v1");
        }
    }

    public function test_checkout_session_statuses_match_model(): void
    {
        $fixture = $this->fixture('checkout-session');

        $this->assertSame(
            [
                BillingCheckoutSession::STATUS_OPEN,
                BillingCheckoutSession::STATUS_COMPLETE,
                BillingCheckoutSession::STATUS_EXPIRED,
            ],
            $fixture['enums']['status'],
            'Checkout session status enum drifted from BillingCheckoutSession model',
        );
    }

    public function test_subscription_live_statuses_match_model(): void
    {
        $fixture = $this->fixture('subscription');

        $this->assertSame(
            BillingSubscription::LIVE_STATUSES,
            $fixture['enums']['live_statuses'],
            'Subscription live statuses drifted from BillingSubscription model',
        );

        // Stripe vocabulary must contain every live status.
        foreach (BillingSubscription::LIVE_STATUSES as $status) {
            $this->assertContains($status, $fixture['enums']['status']);
        }
    }

    public function test_entitlement_statuses_match_model(): void
    {
        $fixture = $this->fixture('entitlement');

        $this->assertSame(
            BillingServiceEntitlement::STATUSES,
            $fixture['enums']['status'],
            'Entitlement status enum drifted from BillingServiceEntitlement model',
        );
        $this->assertSame(
            BillingServiceEntitlement::TERMINAL_STATUSES,
            $fixture['enums']['terminal_statuses'],
            'Entitlement terminal statuses drifted from model',
        );
    }

    public function test_provisioning_request_statuses_and_actions_match_model(): void
    {
        $fixture = $this->fixture('provisioning-request');

        $this->assertSame(
            ProvisioningRequest::STATUSES,
            $fixture['enums']['status'],
            'Provisioning request status enum drifted from ProvisioningRequest model',
        );
        $this->assertSame(
            ProvisioningRequest::TERMINAL_STATUSES,
            $fixture['enums']['terminal_statuses'],
            'Provisioning request terminal statuses drifted from model',
        );
        $this->assertSame(
            ProvisioningRequest::ACTIONS,
            $fixture['enums']['actions'],
            'Provisioning request actions drifted from model',
        );

        // The v1 example must always be approval-gated and non-executed.
        $example = $fixture['example'];
        $this->assertTrue($example['requires_approval']);
        $this->assertSame(ProvisioningRequest::STATUS_PENDING_APPROVAL, $example['status']);
    }

    public function test_lifecycle_status_mapping_is_total_over_portal_statuses(): void
    {
        $fixture = $this->fixture('lifecycle-status');
        $mapping = $fixture['mapping']['portal_entitlement_to_standalone_customer_service'];

        // Every portal entitlement status must appear in the mapping (value may
        // be null for billing-side-only states, but the key must exist).
        foreach (BillingServiceEntitlement::STATUSES as $status) {
            $this->assertArrayHasKey(
                $status,
                $mapping,
                "Entitlement status '{$status}' missing from cross-system lifecycle mapping",
            );
        }

        // Mapped targets must be valid standalone CustomerService statuses.
        $standalone = $fixture['standalone_reference']['customer_service_statuses'];
        foreach ($mapping as $portalStatus => $target) {
            if ($target !== null) {
                $this->assertContains(
                    $target,
                    $standalone,
                    "Mapping target '{$target}' for '{$portalStatus}' is not a standalone CustomerService status",
                );
            }
        }
    }

    public function test_provider_reference_providers_are_the_approved_set(): void
    {
        $fixture = $this->fixture('provider-reference');

        $this->assertSame(
            ['glasspanel', 'proxmox', 'pterodactyl', 'mailcow', 'powerdns', 'manual'],
            $fixture['enums']['provider'],
        );
    }

    public function test_change_request_statuses_still_match_documented_workflow(): void
    {
        // Change requests are not one of the nine fixtures but their enum is part
        // of the documented workflow contract; guard it here.
        $this->assertSame(
            ['submitted', 'under_review', 'approved', 'rejected', 'completed', 'cancelled'],
            BillingChangeRequest::STATUSES,
            'BillingChangeRequest statuses drifted from the documented workflow contract',
        );
    }

    public function test_monetary_fields_use_minor_units_and_iso_currency(): void
    {
        $planFixture    = $this->fixture('product-plan');
        $invoiceFixture = $this->fixture('invoice-payment');

        $this->assertIsInt($planFixture['example']['plan']['amount_cents']);
        $this->assertMatchesRegularExpression('/^[a-z]{3}$/', $planFixture['example']['plan']['currency']);
        $this->assertIsInt($invoiceFixture['example']['invoice']['amount_due_cents']);
        $this->assertIsInt($invoiceFixture['example']['payment']['amount_cents']);
        $this->assertMatchesRegularExpression('/^[a-z]{3}$/', $invoiceFixture['example']['payment']['currency']);
    }
}
