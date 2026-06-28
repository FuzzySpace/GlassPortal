<?php

namespace App\Services\Pilot;

use App\Models\BillingPlan;
use App\Models\BillingProduct;
use App\Services\Billing\StripeBillingClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inspects the running GlassPortal install and reports whether it is ready for a
 * controlled pilot / product test (Phase 29).
 *
 * Read-only and side-effect-free: it inspects config, schema, registered routes,
 * resolvable services, seeded products/plans, and docs. It makes **no external
 * Stripe API calls, no infrastructure calls, and never returns or prints secret
 * values** — only presence booleans / counts. The result is a flat list of
 * {@see PilotReadinessItem}s grouped into operator-facing categories.
 */
class PilotReadinessService
{
    // Category labels (ordered).
    public const CAT_APPLICATION   = 'Application health';
    public const CAT_RUNTIME        = 'Runtime exposure readiness';
    public const CAT_PRODUCT        = 'Product catalog readiness';
    public const CAT_BILLING        = 'Billing readiness';
    public const CAT_STRIPE         = 'Stripe readiness';
    public const CAT_CHECKOUT       = 'Checkout readiness';
    public const CAT_WEBHOOK        = 'Webhook readiness';
    public const CAT_ENTITLEMENT    = 'Entitlement readiness';
    public const CAT_PROVISIONING   = 'Provisioning request readiness';
    public const CAT_PORTAL         = 'Customer portal readiness';
    public const CAT_ADMIN          = 'Admin workflow readiness';
    public const CAT_DOCS           = 'Documentation readiness';
    public const CAT_SECURITY       = 'Security boundary readiness';
    public const CAT_STATE          = 'State & drift-guard readiness';

    /** The canonical pilot URL is expected to be served on this public port. */
    private const EXPECTED_CANONICAL_PORT = '18188';

    /**
     * Every readiness item, in category order.
     *
     * @return list<PilotReadinessItem>
     */
    public function items(): array
    {
        return array_merge(
            $this->applicationItems(),
            $this->runtimeItems(),
            $this->productItems(),
            $this->billingItems(),
            $this->stripeItems(),
            $this->checkoutItems(),
            $this->webhookItems(),
            $this->entitlementItems(),
            $this->provisioningItems(),
            $this->portalItems(),
            $this->adminItems(),
            $this->docsItems(),
            $this->securityItems(),
            $this->stateItems(),
        );
    }

    /**
     * Items grouped by category, preserving category order.
     *
     * @return array<string, list<PilotReadinessItem>>
     */
    public function categories(): array
    {
        $grouped = [];
        foreach ($this->items() as $item) {
            $grouped[$item->category][] = $item;
        }

        return $grouped;
    }

    public function hasBlocked(): bool
    {
        foreach ($this->items() as $item) {
            if ($item->isBlocked()) {
                return true;
            }
        }

        return false;
    }

    public function isReady(): bool
    {
        return ! $this->hasBlocked();
    }

    /**
     * @return array{ready:int, warning:int, blocked:int, unknown:int, total:int}
     */
    public function summary(): array
    {
        $counts = [
            PilotReadinessItem::READY   => 0,
            PilotReadinessItem::WARNING => 0,
            PilotReadinessItem::BLOCKED => 0,
            PilotReadinessItem::UNKNOWN => 0,
        ];
        $items = $this->items();
        foreach ($items as $item) {
            $counts[$item->status] = ($counts[$item->status] ?? 0) + 1;
        }

        return [
            'ready'   => $counts[PilotReadinessItem::READY],
            'warning' => $counts[PilotReadinessItem::WARNING],
            'blocked' => $counts[PilotReadinessItem::BLOCKED],
            'unknown' => $counts[PilotReadinessItem::UNKNOWN],
            'total'   => count($items),
        ];
    }

    // -------------------------------------------------------------------------
    // Categories

    /** @return list<PilotReadinessItem> */
    private function applicationItems(): array
    {
        $items = [];

        try {
            DB::connection()->getPdo();
            $items[] = PilotReadinessItem::ready('application.database', self::CAT_APPLICATION,
                'Database is reachable (' . DB::connection()->getDriverName() . ').');
        } catch (\Throwable $e) {
            $items[] = PilotReadinessItem::blocked('application.database', self::CAT_APPLICATION,
                'Database is not reachable.', 'Check DB connection settings, then run: php artisan migrate');
        }

        $key = (string) config('app.key', '');
        $items[] = ($key !== '' && str_starts_with($key, 'base64:'))
            ? PilotReadinessItem::ready('application.app_key', self::CAT_APPLICATION, 'APP_KEY is set.')
            : PilotReadinessItem::blocked('application.app_key', self::CAT_APPLICATION,
                'APP_KEY is missing.', 'Run: php artisan key:generate');

        return $items;
    }

    /**
     * Warn if the operator appears to be testing the LEGACY billing runtime
     * instead of the canonical GlassPortal pilot target. Compares the current
     * runtime authority (configured app URL + live request host) against the
     * configured canonical / legacy URLs. Config-only; never redirects.
     *
     * @return list<PilotReadinessItem>
     */
    private function runtimeItems(): array
    {
        $canonical = rtrim((string) config('pilot.canonical_url', ''), '/');
        $legacy    = rtrim((string) config('pilot.legacy_billing_url', ''), '/');

        $candidates       = $this->currentRuntimeCandidates();
        $legacyAuthority  = $this->authority($legacy);
        $canonicalAuthority = $this->authority($canonical);

        $items = [];

        // (a) Which runtime is the operator actually on right now? The standalone
        // billing runtime (:18180) is preserved / potential canonical (pending
        // Phase 29C) — not the pilot target, and not legacy/dead.
        if ($legacyAuthority !== '' && in_array($legacyAuthority, $candidates, true)) {
            $items[] = PilotReadinessItem::warning('runtime.canonical_target', self::CAT_RUNTIME,
                "You appear to be on the standalone billing runtime ({$legacy}), not the canonical GlassPortal pilot target.",
                "Use the canonical GlassPortal pilot target instead: {$canonical}.");
        } elseif ($canonicalAuthority !== '' && in_array($canonicalAuthority, $candidates, true)) {
            $items[] = PilotReadinessItem::ready('runtime.canonical_target', self::CAT_RUNTIME,
                "On the canonical GlassPortal pilot target ({$canonical}).");
        } else {
            $items[] = PilotReadinessItem::ready('runtime.canonical_target', self::CAT_RUNTIME,
                "Current runtime is not the standalone billing URL. Canonical pilot target is {$canonical}.");
        }

        // (b) Drift guard: the CONFIGURED pilot target must not be the standalone URL.
        $targetIsStandalone = ($canonicalAuthority !== '' && $canonicalAuthority === $legacyAuthority)
            || str_contains($canonical, ':18180');
        $items[] = $targetIsStandalone
            ? PilotReadinessItem::warning('runtime.pilot_target_not_legacy', self::CAT_RUNTIME,
                "The configured pilot target ({$canonical}) is the standalone billing URL, not GlassPortal.",
                'Set PILOT_CANONICAL_URL to the GlassPortal URL (…:' . self::EXPECTED_CANONICAL_PORT . ').')
            : PilotReadinessItem::ready('runtime.pilot_target_not_legacy', self::CAT_RUNTIME,
                'Configured pilot target is not the standalone billing URL.');

        // (c) Drift guard: confirm the canonical pilot URL is the expected :18188.
        $port = self::EXPECTED_CANONICAL_PORT;
        $items[] = (str_contains($canonicalAuthority, ':' . $port) || str_contains($canonical, ':' . $port))
            ? PilotReadinessItem::ready('runtime.canonical_url', self::CAT_RUNTIME,
                "Canonical pilot URL is :{$port} as expected ({$canonical}).")
            : PilotReadinessItem::warning('runtime.canonical_url', self::CAT_RUNTIME,
                "Canonical pilot URL is not the expected :{$port} ({$canonical}).",
                "Set PILOT_CANONICAL_URL to http://40.160.61.180:{$port}.");

        // (d) Phase 29B: the standalone billing URL must be documented (consolidation input).
        $items[] = $legacy !== ''
            ? PilotReadinessItem::ready('runtime.legacy_billing_url_documented', self::CAT_RUNTIME,
                "Standalone billing URL is documented ({$legacy}).")
            : PilotReadinessItem::warning('runtime.legacy_billing_url_documented', self::CAT_RUNTIME,
                'Standalone billing URL is not documented.',
                'Set PILOT_LEGACY_BILLING_URL and document it in docs/state/legacy-billing-runtime-inventory.md.');

        // (e) Phase 29B: canonical and standalone URLs must be distinct (two runtimes).
        $items[] = ($canonicalAuthority !== '' && $legacyAuthority !== '' && $canonicalAuthority !== $legacyAuthority)
            ? PilotReadinessItem::ready('runtime.canonical_and_legacy_urls_distinct', self::CAT_RUNTIME,
                'Canonical and standalone runtime URLs are distinct.')
            : PilotReadinessItem::warning('runtime.canonical_and_legacy_urls_distinct', self::CAT_RUNTIME,
                'Canonical and standalone runtime URLs are not distinct.',
                'They must differ — check PILOT_CANONICAL_URL / PILOT_LEGACY_BILLING_URL.');

        return $items;
    }

    /** @return list<PilotReadinessItem> */
    private function productItems(): array
    {
        $items = [];

        try {
            $activeProducts = BillingProduct::active()->count();
            $items[] = $activeProducts > 0
                ? PilotReadinessItem::ready('product_catalog.active_product', self::CAT_PRODUCT,
                    "{$activeProducts} active product(s) configured.")
                : PilotReadinessItem::blocked('product_catalog.active_product', self::CAT_PRODUCT,
                    'No active billing product exists.',
                    'Seed pilot data (php artisan migrate:fresh --seed) or create a product under Admin → Billing → Products.');

            $activePlans = BillingPlan::active()->count();
            $items[] = $activePlans > 0
                ? PilotReadinessItem::ready('product_catalog.active_plan', self::CAT_PRODUCT,
                    "{$activePlans} active plan(s) configured.")
                : PilotReadinessItem::blocked('product_catalog.active_plan', self::CAT_PRODUCT,
                    'No active billing plan exists.',
                    'Seed pilot data or create a plan with a price under Admin → Billing → Plans.');

            // Pricing: an active plan needs a real (non-placeholder) Stripe price id
            // before live checkout testing. Placeholder = blank or "price_local…".
            $activePlanModels = BillingPlan::active()->get(['stripe_price_id']);
            $withRealPrice = $activePlanModels->filter(fn ($p) => $this->isRealPriceRef($p->stripe_price_id))->count();
            if ($activePlanModels->isEmpty()) {
                // active_plan already reported blocked above; nothing to add here.
            } elseif ($withRealPrice > 0) {
                $items[] = PilotReadinessItem::ready('product_catalog.plan_pricing', self::CAT_PRODUCT,
                    "{$withRealPrice} active plan(s) have a Stripe price id.");
            } else {
                $items[] = PilotReadinessItem::warning('product_catalog.plan_pricing', self::CAT_PRODUCT,
                    'Active plans use placeholder/local price references only.',
                    'Set a real Stripe TEST price id (price_…) on a plan before live checkout testing.');
            }
        } catch (\Throwable $e) {
            $items[] = PilotReadinessItem::unknown('product_catalog.active_product', self::CAT_PRODUCT,
                'Could not inspect products/plans.', 'Run migrations: php artisan migrate');
        }

        return $items;
    }

    /** @return list<PilotReadinessItem> */
    private function billingItems(): array
    {
        $required = [
            'billing_customers', 'billing_products', 'billing_plans', 'billing_subscriptions',
            'billing_invoices', 'billing_payments', 'billing_events',
        ];
        $items = [];
        $items[] = $this->tablesItem('billing.tables', self::CAT_BILLING, $required, 'billing foundation tables');
        $items[] = $this->routesItem('billing.routes', self::CAT_BILLING, ['admin.billing.overview'],
            'Admin billing routes are registered.', 'Check routes/web.php (admin billing group).');

        return $items;
    }

    /** @return list<PilotReadinessItem> */
    private function stripeItems(): array
    {
        $stripe = $this->stripe();
        if ($stripe === null) {
            return [PilotReadinessItem::unknown('stripe.config', self::CAT_STRIPE, 'Stripe client not resolvable.')];
        }

        if (! $stripe->isEnabled()) {
            return [PilotReadinessItem::warning('stripe.config', self::CAT_STRIPE,
                'Billing/Stripe is disabled — fine for a local dry run.',
                'Set GLASSBILLING_ENABLED=true, GLASSBILLING_MODE=stripe and STRIPE_SECRET_KEY (test) for a live pilot.')];
        }

        if ($stripe->isConfigured()) {
            return [PilotReadinessItem::ready('stripe.config', self::CAT_STRIPE,
                "Stripe configured (mode={$stripe->mode()}, secret key present).")];
        }

        return [PilotReadinessItem::blocked('stripe.config', self::CAT_STRIPE,
            'Billing is enabled but Stripe is not configured.',
            'Set STRIPE_SECRET_KEY (test mode) or disable billing for a local dry run.')];
    }

    /** @return list<PilotReadinessItem> */
    private function checkoutItems(): array
    {
        $items = [];
        $stripe = $this->stripe();
        $checkoutEnabled = (bool) config('billing.checkout.enabled', false);

        if (! $checkoutEnabled) {
            $items[] = PilotReadinessItem::warning('checkout.config', self::CAT_CHECKOUT,
                'Customer checkout is disabled.',
                'Set GLASSBILLING_CHECKOUT_ENABLED=true for a live checkout test.');
        } elseif ($stripe !== null && $stripe->isConfigured()) {
            $items[] = PilotReadinessItem::ready('checkout.config', self::CAT_CHECKOUT,
                'Customer checkout is enabled and Stripe is configured.');
        } else {
            $items[] = PilotReadinessItem::blocked('checkout.config', self::CAT_CHECKOUT,
                'Checkout is enabled but Stripe is not configured.',
                'Configure STRIPE_SECRET_KEY (test) or disable checkout.');
        }

        $items[] = $this->routesItem('checkout.route', self::CAT_CHECKOUT, ['portal.billing.checkout'],
            'Customer checkout-start route is registered.', 'Check routes/web.php (portal billing group).');

        return $items;
    }

    /** @return list<PilotReadinessItem> */
    private function webhookItems(): array
    {
        $items = [];
        $items[] = $this->routesItem('webhook.route', self::CAT_WEBHOOK, ['api.billing.stripe.webhook'],
            'Stripe webhook intake route is registered.', 'Check routes/api.php.');

        $stripe = $this->stripe();
        $webhooksEnabled = (bool) config('billing.webhooks.enabled', false);

        if (! $webhooksEnabled) {
            $items[] = PilotReadinessItem::warning('webhook.secret_configured', self::CAT_WEBHOOK,
                'Webhook intake is disabled (endpoint returns 404 while disabled).',
                'Set GLASSBILLING_WEBHOOKS_ENABLED=true and STRIPE_WEBHOOK_SECRET (test) for a webhook test.');
        } elseif ($stripe !== null && $stripe->hasWebhookSecret()) {
            $items[] = PilotReadinessItem::ready('webhook.secret_configured', self::CAT_WEBHOOK,
                'Webhook signing secret is configured (signature verification on).');
        } else {
            $items[] = PilotReadinessItem::blocked('webhook.secret_configured', self::CAT_WEBHOOK,
                'Webhook intake is enabled but no signing secret is set (fails closed).',
                'Set STRIPE_WEBHOOK_SECRET (test) or disable webhook intake.');
        }

        return $items;
    }

    /** @return list<PilotReadinessItem> */
    private function entitlementItems(): array
    {
        return [
            $this->tablesItem('entitlement.table', self::CAT_ENTITLEMENT, ['billing_service_entitlements'], 'entitlements table'),
            $this->serviceItem('entitlement.service', self::CAT_ENTITLEMENT,
                \App\Services\Billing\BillingEntitlementService::class, 'Entitlement lifecycle service'),
        ];
    }

    /** @return list<PilotReadinessItem> */
    private function provisioningItems(): array
    {
        return [
            $this->tablesItem('provisioning.table', self::CAT_PROVISIONING, ['provisioning_requests'], 'provisioning_requests table'),
            $this->routesItem('provisioning.routes', self::CAT_PROVISIONING, ['admin.provisioning.requests.index'],
                'Provisioning request admin routes are registered.', 'Check routes/web.php.'),
            $this->serviceItem('provisioning.service', self::CAT_PROVISIONING,
                \App\Services\Provisioning\ProvisioningRequestService::class, 'Provisioning request engine'),
        ];
    }

    /** @return list<PilotReadinessItem> */
    private function portalItems(): array
    {
        $routes = [
            'portal.billing.dashboard', 'portal.billing.subscriptions', 'portal.billing.invoices',
            'portal.billing.payments', 'portal.billing.checkout-sessions', 'portal.billing.change-requests',
            'portal.billing.plans',
        ];

        return [
            $this->routesItem('portal.billing_routes', self::CAT_PORTAL, $routes,
                'Customer billing self-service routes are registered.', 'Check routes/web.php (portal billing group).'),
            $this->tablesItem('portal.change_requests_table', self::CAT_PORTAL, ['billing_change_requests'], 'billing_change_requests table'),
        ];
    }

    /** @return list<PilotReadinessItem> */
    private function adminItems(): array
    {
        $routes = [
            'admin.billing.customers', 'admin.billing.subscriptions', 'admin.billing.entitlements',
            'admin.billing.checkout-sessions', 'admin.billing.change-requests',
        ];

        return [
            $this->routesItem('admin.workflow_routes', self::CAT_ADMIN, $routes,
                'Admin billing/workflow routes are registered.', 'Check routes/web.php (admin billing group).'),
        ];
    }

    /** @return list<PilotReadinessItem> */
    private function docsItems(): array
    {
        $items = [];

        $items[] = is_file(base_path('docs/phase29/product-test-pilot-readiness.md'))
            ? PilotReadinessItem::ready('docs.pilot_runbook', self::CAT_DOCS, 'Pilot readiness doc is present.')
            : PilotReadinessItem::warning('docs.pilot_runbook', self::CAT_DOCS,
                'Pilot readiness doc is missing.', 'Add docs/phase29/product-test-pilot-readiness.md.');

        $phaseDocs = [
            'docs/phase27/stripe-checkout-webhook-intake.md',
            'docs/phase28/customer-billing-self-service.md',
            'docs/architecture/repository-consolidation.md',
            'docs/phase29/runtime-exposure-inventory.md',
        ];
        $missing = array_values(array_filter($phaseDocs, fn ($d) => ! is_file(base_path($d))));
        $items[] = empty($missing)
            ? PilotReadinessItem::ready('docs.phase_docs', self::CAT_DOCS, 'Billing/architecture phase docs are present.')
            : PilotReadinessItem::warning('docs.phase_docs', self::CAT_DOCS,
                'Some phase docs are missing: ' . implode(', ', $missing) . '.', 'Restore the missing docs.');

        return $items;
    }

    /** @return list<PilotReadinessItem> */
    private function securityItems(): array
    {
        $items = [];

        // Invariant: provisioning is approval-gated and nothing auto-executes
        // infrastructure in this phase. A truthy auto-execute flag would break it.
        $autoExecute = (bool) config('provisioning.auto_execute', false);
        $items[] = $autoExecute
            ? PilotReadinessItem::blocked('security.no_infrastructure_execution', self::CAT_SECURITY,
                'provisioning.auto_execute is enabled — infrastructure could execute automatically.',
                'Set PROVISIONING_AUTO_EXECUTE=false / remove the flag. Pilots must be approval-gated.')
            : PilotReadinessItem::ready('security.no_infrastructure_execution', self::CAT_SECURITY,
                'Provisioning is approval-gated; no driver auto-execution is configured.');

        $items[] = PilotReadinessItem::ready('security.no_secret_exposure', self::CAT_SECURITY,
            'Readiness reports presence booleans only; no secret values are returned or printed.');

        return $items;
    }

    /**
     * Drift guard: the state/decision docs that keep repository + runtime reality
     * aligned must exist. Advisory (warning) if a doc is missing.
     *
     * @return list<PilotReadinessItem>
     */
    private function stateItems(): array
    {
        $docs = [
            'state.repository_consolidation_doc'      => ['docs/architecture/repository-consolidation.md', 'Repository consolidation ADR'],
            'state.runtime_map_doc'                   => ['docs/state/runtime-map.md', 'Runtime map'],
            'state.repository_map_doc'                => ['docs/state/repository-map.md', 'Repository map'],
            // Phase 29B — runtime consolidation planning docs.
            'runtime.runtime_consolidation_plan_doc'  => ['docs/architecture/runtime-consolidation-plan.md', 'Runtime consolidation plan (ADR)'],
            'runtime.legacy_billing_inventory_doc'    => ['docs/state/legacy-billing-runtime-inventory.md', 'Standalone billing runtime inventory'],
            'runtime.runtime_consolidation_runbook'   => ['docs/runbooks/runtime-consolidation.md', 'Runtime consolidation runbook'],
            // Phase 29C — billing architecture reconciliation (SDK/API parity gate).
            'state.billing_reconciliation_doc'        => ['docs/architecture/billing-architecture-reconciliation.md', 'Billing architecture reconciliation (29C; SDK/API parity gate)'],
        ];

        $items = [];
        foreach ($docs as $key => [$path, $label]) {
            $items[] = is_file(base_path($path))
                ? PilotReadinessItem::ready($key, self::CAT_STATE, "{$label} present ({$path}).")
                : PilotReadinessItem::warning($key, self::CAT_STATE, "{$label} missing.", "Add {$path}.");
        }

        return $items;
    }

    // -------------------------------------------------------------------------
    // Helpers

    /** A non-placeholder Stripe price reference (non-blank, not a local stub). */
    private function isRealPriceRef(?string $ref): bool
    {
        $ref = (string) $ref;

        return $ref !== '' && ! str_starts_with($ref, 'price_local');
    }

    /**
     * The host[:port] authorities that identify the runtime the operator is on:
     * the configured app URL, plus the live request host when serving HTTP.
     *
     * @return list<string>
     */
    private function currentRuntimeCandidates(): array
    {
        $candidates = [];

        $appAuthority = $this->authority((string) config('app.url', ''));
        if ($appAuthority !== '') {
            $candidates[] = $appAuthority;
        }

        try {
            $reqHost = strtolower((string) request()->getHttpHost());
            if ($reqHost !== '') {
                $candidates[] = $reqHost;
            }
        } catch (\Throwable $e) {
            // No bound request (e.g. some console contexts) — app URL is enough.
        }

        return array_values(array_unique($candidates));
    }

    /** Normalize a URL to a lowercase host[:port] authority. */
    private function authority(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return '';
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            return strtolower($url); // bare host with no scheme
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return strtolower($host . $port);
    }

    private function stripe(): ?StripeBillingClient
    {
        try {
            return app(StripeBillingClient::class);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @param list<string> $tables */
    private function tablesItem(string $key, string $category, array $tables, string $label): PilotReadinessItem
    {
        try {
            $missing = array_values(array_filter($tables, fn ($t) => ! Schema::hasTable($t)));

            return empty($missing)
                ? PilotReadinessItem::ready($key, $category, ucfirst($label) . ' present (' . count($tables) . ').')
                : PilotReadinessItem::blocked($key, $category,
                    'Missing ' . $label . ': ' . implode(', ', $missing) . '.', 'Run: php artisan migrate');
        } catch (\Throwable $e) {
            return PilotReadinessItem::unknown($key, $category, 'Could not check ' . $label . '.');
        }
    }

    /** @param list<string> $routeNames */
    private function routesItem(string $key, string $category, array $routeNames, string $okMessage, string $action): PilotReadinessItem
    {
        try {
            $routes  = app('router')->getRoutes();
            $missing = array_values(array_filter($routeNames, fn ($n) => $routes->getByName($n) === null));

            return empty($missing)
                ? PilotReadinessItem::ready($key, $category, $okMessage)
                : PilotReadinessItem::blocked($key, $category, 'Missing routes: ' . implode(', ', $missing) . '.', $action);
        } catch (\Throwable $e) {
            return PilotReadinessItem::unknown($key, $category, 'Could not check routes.');
        }
    }

    private function serviceItem(string $key, string $category, string $class, string $label): PilotReadinessItem
    {
        try {
            app($class);

            return PilotReadinessItem::ready($key, $category, $label . ' is resolvable.');
        } catch (\Throwable $e) {
            return PilotReadinessItem::blocked($key, $category, $label . ' is not resolvable.', 'Check the service provider bindings.');
        }
    }
}
