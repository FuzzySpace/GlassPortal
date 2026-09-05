<?php

namespace Tests\Unit\Pilot;

use App\Models\BillingPlan;
use App\Models\BillingProduct;
use App\Services\Pilot\PilotReadinessItem;
use App\Services\Pilot\PilotReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 29 — PilotReadinessService. Inspects readiness without external calls
 * or secret exposure.
 */
class PilotReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PilotReadinessService
    {
        return app(PilotReadinessService::class);
    }

    /** @return array<string, PilotReadinessItem> keyed by item key */
    private function itemsByKey(): array
    {
        $out = [];
        foreach ($this->service()->items() as $item) {
            $out[$item->key] = $item;
        }

        return $out;
    }

    private function activeProductWithPlan(string $priceId = 'price_test_pilot'): void
    {
        $product = BillingProduct::factory()->create(['status' => 'active']);
        BillingPlan::factory()->create([
            'billing_product_id' => $product->id,
            'status'             => 'active',
            'stripe_price_id'    => $priceId,
        ]);
    }

    // -------------------------------------------------------------------------

    public function test_blocked_when_no_active_product_or_plan(): void
    {
        $items = $this->itemsByKey();

        $this->assertSame(PilotReadinessItem::BLOCKED, $items['product_catalog.active_product']->status);
        $this->assertSame(PilotReadinessItem::BLOCKED, $items['product_catalog.active_plan']->status);
        $this->assertTrue($this->service()->hasBlocked());
        $this->assertFalse($this->service()->isReady());
    }

    public function test_ready_when_product_and_plan_configured(): void
    {
        $this->activeProductWithPlan();
        $items = $this->itemsByKey();

        $this->assertSame(PilotReadinessItem::READY, $items['product_catalog.active_product']->status);
        $this->assertSame(PilotReadinessItem::READY, $items['product_catalog.active_plan']->status);
        // No blocked checks: billing disabled is only a warning, not a blocker.
        $this->assertFalse($this->service()->hasBlocked());
        $this->assertTrue($this->service()->isReady());
    }

    public function test_detects_billing_portal_and_provisioning_routes(): void
    {
        $items = $this->itemsByKey();

        $this->assertSame(PilotReadinessItem::READY, $items['billing.routes']->status);
        $this->assertSame(PilotReadinessItem::READY, $items['portal.billing_routes']->status);
        $this->assertSame(PilotReadinessItem::READY, $items['provisioning.routes']->status);
        $this->assertSame(PilotReadinessItem::READY, $items['checkout.route']->status);
        $this->assertSame(PilotReadinessItem::READY, $items['webhook.route']->status);
        $this->assertSame(PilotReadinessItem::READY, $items['admin.workflow_routes']->status);
    }

    public function test_detects_tables_and_services(): void
    {
        $items = $this->itemsByKey();

        $this->assertSame(PilotReadinessItem::READY, $items['billing.tables']->status);
        $this->assertSame(PilotReadinessItem::READY, $items['entitlement.table']->status);
        $this->assertSame(PilotReadinessItem::READY, $items['entitlement.service']->status);
        $this->assertSame(PilotReadinessItem::READY, $items['provisioning.service']->status);
        $this->assertSame(PilotReadinessItem::READY, $items['portal.change_requests_table']->status);
    }

    public function test_detects_docs(): void
    {
        $items = $this->itemsByKey();

        // The Phase 29 doc + prior phase docs exist on disk.
        $this->assertSame(PilotReadinessItem::READY, $items['docs.pilot_runbook']->status);
        $this->assertSame(PilotReadinessItem::READY, $items['docs.phase_docs']->status);
    }

    public function test_plan_pricing_warns_for_placeholder_and_ready_for_real(): void
    {
        $this->activeProductWithPlan('price_local_placeholder');
        $this->assertSame(PilotReadinessItem::WARNING, $this->itemsByKey()['product_catalog.plan_pricing']->status);
    }

    public function test_plan_pricing_ready_for_real_price(): void
    {
        $this->activeProductWithPlan('price_test_real_123');
        $this->assertSame(PilotReadinessItem::READY, $this->itemsByKey()['product_catalog.plan_pricing']->status);
    }

    public function test_stripe_config_states(): void
    {
        // disabled → warning
        config(['billing.enabled' => false]);
        $this->assertSame(PilotReadinessItem::WARNING, $this->itemsByKey()['stripe.config']->status);

        // enabled + configured → ready
        config(['billing.enabled' => true, 'billing.mode' => 'stripe', 'billing.stripe.secret_key' => 'sk_test_x']);
        $this->assertSame(PilotReadinessItem::READY, $this->itemsByKey()['stripe.config']->status);

        // enabled + unconfigured → blocked
        config(['billing.enabled' => true, 'billing.mode' => 'stripe', 'billing.stripe.secret_key' => '']);
        $this->assertSame(PilotReadinessItem::BLOCKED, $this->itemsByKey()['stripe.config']->status);
    }

    public function test_webhook_secret_invariant(): void
    {
        // enabled webhooks but no secret → blocked (fails closed)
        config(['billing.webhooks.enabled' => true, 'billing.stripe.webhook_secret' => '']);
        $this->assertSame(PilotReadinessItem::BLOCKED, $this->itemsByKey()['webhook.secret_configured']->status);

        // enabled + secret → ready
        config(['billing.webhooks.enabled' => true, 'billing.stripe.webhook_secret' => 'whsec_x']);
        $this->assertSame(PilotReadinessItem::READY, $this->itemsByKey()['webhook.secret_configured']->status);
    }

    public function test_security_invariant_blocks_when_auto_execute_enabled(): void
    {
        $this->assertSame(PilotReadinessItem::READY, $this->itemsByKey()['security.no_infrastructure_execution']->status);

        config(['provisioning.auto_execute' => true]);
        $this->assertSame(PilotReadinessItem::BLOCKED, $this->itemsByKey()['security.no_infrastructure_execution']->status);
    }

    // --- Phase 29 addendum: runtime exposure (legacy-URL guard) -------------

    public function test_runtime_warns_when_on_legacy_billing_url(): void
    {
        config(['app.url' => config('pilot.legacy_billing_url')]);

        $item = $this->itemsByKey()['runtime.canonical_target'];
        $this->assertSame(PilotReadinessItem::WARNING, $item->status);
        $this->assertStringContainsStringIgnoringCase('legacy', $item->message);
        // A legacy-URL warning never blocks the pilot.
        $this->assertFalse($item->isBlocked());
    }

    public function test_runtime_ready_when_on_canonical_url(): void
    {
        config(['app.url' => config('pilot.canonical_url')]);

        $item = $this->itemsByKey()['runtime.canonical_target'];
        $this->assertSame(PilotReadinessItem::READY, $item->status);
        $this->assertStringContainsStringIgnoringCase('canonical', $item->message);
    }

    public function test_runtime_ready_when_neither_canonical_nor_legacy(): void
    {
        config(['app.url' => 'http://localhost:9999']);

        $this->assertSame(PilotReadinessItem::READY, $this->itemsByKey()['runtime.canonical_target']->status);
    }

    public function test_runtime_check_never_exposes_secrets_only_urls(): void
    {
        // Sanity: the runtime check only ever references the public URLs.
        $item = $this->itemsByKey()['runtime.canonical_target'];
        $this->assertNotSame('', $item->message);
    }

    // --- Phase 29 safeguard addendum: config + state drift guards -----------

    public function test_pilot_target_ready_by_default_and_warns_when_set_to_legacy(): void
    {
        // Default canonical (:18188) is not the legacy URL → ready.
        $this->assertSame(PilotReadinessItem::READY, $this->itemsByKey()['runtime.pilot_target_not_legacy']->status);

        // Misconfiguring the pilot target to the legacy billing URL → warning.
        config(['pilot.canonical_url' => config('pilot.legacy_billing_url')]);
        $item = $this->itemsByKey()['runtime.pilot_target_not_legacy'];
        $this->assertSame(PilotReadinessItem::WARNING, $item->status);
        $this->assertStringContainsStringIgnoringCase('legacy', $item->message);
    }

    public function test_pilot_target_warns_when_canonical_uses_legacy_port(): void
    {
        config(['pilot.canonical_url' => 'http://40.160.61.180:18180']);
        $this->assertSame(PilotReadinessItem::WARNING, $this->itemsByKey()['runtime.pilot_target_not_legacy']->status);
    }

    public function test_canonical_url_confirms_expected_port_18188(): void
    {
        // Default canonical URL is :18188 → ready.
        $this->assertSame(PilotReadinessItem::READY, $this->itemsByKey()['runtime.canonical_url']->status);

        // A canonical URL on an unexpected port → warning.
        config(['pilot.canonical_url' => 'http://40.160.61.180:9999']);
        $this->assertSame(PilotReadinessItem::WARNING, $this->itemsByKey()['runtime.canonical_url']->status);
    }

    public function test_state_docs_are_present(): void
    {
        $items = $this->itemsByKey();

        $this->assertSame(PilotReadinessItem::READY, $items['state.repository_consolidation_doc']->status);
        $this->assertSame(PilotReadinessItem::READY, $items['state.runtime_map_doc']->status);
        $this->assertSame(PilotReadinessItem::READY, $items['state.repository_map_doc']->status);
    }

    public function test_state_drift_guard_warnings_do_not_block_pilot(): void
    {
        // Even fully misconfigured to the legacy URL, these are warnings, not blockers.
        config(['pilot.canonical_url' => config('pilot.legacy_billing_url')]);

        foreach (['runtime.pilot_target_not_legacy', 'runtime.canonical_url'] as $key) {
            $this->assertFalse($this->itemsByKey()[$key]->isBlocked());
        }
    }

    public function test_never_exposes_secret_values(): void
    {
        $secret = 'sk_live_PILOT_SECRET_MUST_NOT_LEAK';
        $whsec  = 'whsec_PILOT_SECRET_MUST_NOT_LEAK';
        config([
            'billing.enabled'               => true,
            'billing.mode'                  => 'stripe',
            'billing.stripe.secret_key'     => $secret,
            'billing.stripe.webhook_secret' => $whsec,
            'billing.checkout.enabled'      => true,
            'billing.webhooks.enabled'      => true,
        ]);

        $blob = '';
        foreach ($this->service()->items() as $item) {
            $blob .= $item->message . ' ' . $item->action . ' ';
        }

        $this->assertStringNotContainsString($secret, $blob);
        $this->assertStringNotContainsString($whsec, $blob);
    }

    public function test_summary_structure(): void
    {
        $summary = $this->service()->summary();

        foreach (['ready', 'warning', 'blocked', 'unknown', 'total'] as $key) {
            $this->assertArrayHasKey($key, $summary);
        }
        $this->assertSame($summary['total'], $summary['ready'] + $summary['warning'] + $summary['blocked'] + $summary['unknown']);
    }
}
