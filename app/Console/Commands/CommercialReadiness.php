<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\BillingPlan;
use App\Models\BillingProduct;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 29D — Commercial v1 readiness verification.
 *
 * Aggregates the checks that must pass before onboarding the first paying
 * customer, per docs/architecture/commercial-v1-decision.md. Read-only:
 * performs no writes, executes no provisioning, prints no secret values.
 *
 * Exit codes: 0 = ready (possibly with warnings), 1 = one or more blockers.
 */
class CommercialReadiness extends Command
{
    protected $signature   = 'glassportal:commercial-readiness';
    protected $description = 'Verify commercial v1 readiness (blockers vs warnings) without touching runtime state';

    private int $blockers = 0;
    private int $warnings = 0;

    /** Docs that must exist per the Phase 29C reconciliation program. */
    private const REQUIRED_DOCS = [
        'docs/architecture/glassportal-glassbilling-reconciliation.md',
        'docs/architecture/sdk-api-parity-review.md',
        'docs/architecture/runtime-consolidation-plan.md',
        'docs/architecture/commercial-v1-decision.md',
        'docs/state/billing-capability-map.md',
        'docs/state/sdk-contract-map.md',
        'docs/state/legacy-billing-runtime-inventory.md',
        'docs/state/runtime-map.md',
        'docs/state/repository-map.md',
        'docs/state/phase-status.md',
        'docs/runbooks/admin-bootstrap.md',
        'docs/runbooks/commercial-v1-launch.md',
        'docs/runbooks/sdk-parity-check.md',
        'docs/runbooks/runtime-consolidation.md',
        'docs/runbooks/ai-operator-preflight.md',
    ];

    /** Billing tables the embedded GlassBilling domain module requires. */
    private const BILLING_TABLES = [
        'billing_customers',
        'billing_products',
        'billing_plans',
        'billing_subscriptions',
        'billing_invoices',
        'billing_payments',
        'billing_events',
        'billing_checkout_sessions',
        'billing_service_entitlements',
        'billing_service_entitlement_events',
        'billing_change_requests',
        'provisioning_requests',
        'provisioning_request_events',
    ];

    /** Route names required for the commercial pilot flow. */
    private const REQUIRED_ROUTE_NAMES = [
        'portal.billing.dashboard',
        'portal.billing.subscriptions',
        'portal.billing.invoices',
        'portal.billing.payments',
        'portal.billing.plans',
        'portal.billing.checkout',
        'portal.billing.change-requests',
        'admin.billing.overview',
        'admin.billing.change-requests',
        'admin.billing.entitlements',
        'admin.provisioning.requests.index',
    ];

    public function handle(): int
    {
        $this->line('');
        $this->line('  <fg=blue>GlassPortal Commercial V1 Readiness</>');
        $this->line('  ' . now()->toIso8601String());
        $this->line('');

        $this->checkFoundation();
        $this->checkAdminBootstrap();
        $this->checkCatalog();
        $this->checkStripeConfiguration();
        $this->checkBillingSchema();
        $this->checkRoutes();
        $this->checkBoundaries();
        $this->checkDocs();
        $this->checkRuntimeDrift();

        $this->line('');
        if ($this->blockers > 0) {
            $this->line("  <fg=red>NOT READY — {$this->blockers} blocker(s), {$this->warnings} warning(s).</>");
            $this->line('');

            return self::FAILURE;
        }

        $label = $this->warnings > 0
            ? "READY WITH WARNINGS — {$this->warnings} warning(s). Review before onboarding."
            : 'READY — all commercial v1 checks passed.';
        $this->line("  <fg=green>{$label}</>");
        $this->line('');

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Check groups
    // -------------------------------------------------------------------------

    private function checkFoundation(): void
    {
        $this->section('Foundation');

        // App boots (trivially true here) + APP_KEY.
        $this->pass('app.boot', 'Application bootstrap OK');

        $key = (string) config('app.key', '');
        if ($key !== '' && str_starts_with($key, 'base64:')) {
            $this->pass('app.key', 'APP_KEY is set');
        } else {
            $this->blocker('app.key', 'APP_KEY missing — run: php artisan key:generate');
        }

        if (config('app.debug') === true && config('app.env') === 'production') {
            $this->blocker('app.debug', 'APP_DEBUG=true in production — must be disabled before commercial use');
        } else {
            $this->pass('app.debug', 'Debug mode appropriate for environment (' . config('app.env') . ')');
        }

        try {
            DB::connection()->getPdo();
            $this->pass('db.connect', 'Database reachable (' . DB::connection()->getDriverName() . ')');
        } catch (\Throwable $e) {
            $this->blocker('db.connect', 'Database not reachable: ' . $e->getMessage());

            return;
        }

        // Migrations current: no pending migration files.
        try {
            $ran   = collect(DB::table('migrations')->pluck('migration'));
            $files = collect(glob(database_path('migrations/*.php')))
                ->map(fn ($p) => basename($p, '.php'));
            $pending = $files->diff($ran);
            if ($pending->isEmpty()) {
                $this->pass('db.migrations', 'All ' . $files->count() . ' migrations applied');
            } else {
                $this->blocker('db.migrations', 'Pending migrations: ' . $pending->take(5)->implode(', ') . ' — run: php artisan migrate');
            }
        } catch (\Throwable $e) {
            $this->blocker('db.migrations', 'Could not verify migrations: ' . $e->getMessage());
        }
    }

    private function checkAdminBootstrap(): void
    {
        $this->section('Admin bootstrap');

        // Bootstrap command registered.
        $commands = collect(array_keys(\Illuminate\Support\Facades\Artisan::all()));
        if ($commands->contains('glassportal:create-admin')) {
            $this->pass('admin.bootstrap_command_registered', 'glassportal:create-admin command registered');
        } else {
            $this->blocker('admin.bootstrap_command_registered', 'glassportal:create-admin command not registered');
        }

        // At least one owner/admin exists.
        try {
            $count = User::query()
                ->whereIn('role', [UserRole::Owner->value, UserRole::Admin->value])
                ->count();
            if ($count > 0) {
                $this->pass('admin.owner_user_exists', "{$count} owner/admin account(s) present");
            } else {
                $this->blocker('admin.owner_user_exists', 'No owner/admin account — run: php artisan glassportal:create-admin --role=owner (see docs/runbooks/admin-bootstrap.md)');
            }
        } catch (\Throwable $e) {
            $this->blocker('admin.owner_user_exists', 'Could not check admin accounts: ' . $e->getMessage());
        }

        // Role middleware registered under the expected alias.
        if (class_exists(\App\Http\Middleware\EnsureUserHasRole::class)) {
            $this->pass('admin.role_middleware_registered', 'EnsureUserHasRole middleware class present (alias: role)');
        } else {
            $this->blocker('admin.role_middleware_registered', 'EnsureUserHasRole middleware class missing');
        }

        // Route protection: admin + portal groups carry auth and role middleware.
        $this->checkRouteProtection('admin.staff_routes_protected', 'admin.billing.overview', ['auth', 'role:owner,admin,staff,support', 'role:owner,admin']);
        $this->checkRouteProtection('admin.customer_routes_protected', 'portal.billing.dashboard', ['auth', 'role:customer']);
    }

    private function checkCatalog(): void
    {
        $this->section('Catalog');

        try {
            $products = BillingProduct::query()->count();
            $plans    = BillingPlan::query()->where('status', 'active')->count();

            if ($products > 0) {
                $this->pass('catalog.products_exist', "{$products} billing product(s) present");
            } else {
                $this->blocker('catalog.products_exist', 'No billing products defined — create at least one before onboarding');
            }

            if ($plans > 0) {
                $this->pass('catalog.active_plans_exist', "{$plans} active plan(s) present");
            } else {
                $this->blocker('catalog.active_plans_exist', 'No active billing plans — activate at least one plan');
            }

            $unpriced = BillingPlan::query()
                ->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('stripe_price_id')->orWhere('stripe_price_id', ''))
                ->count();
            if ($unpriced === 0) {
                $this->pass('catalog.plans_priced', 'All active plans have a Stripe price ID');
            } else {
                $this->warn2('catalog.plans_priced', "{$unpriced} active plan(s) missing stripe_price_id — checkout will fail for those plans");
            }
        } catch (\Throwable $e) {
            $this->blocker('catalog.check', 'Could not inspect catalog: ' . $e->getMessage());
        }
    }

    private function checkStripeConfiguration(): void
    {
        $this->section('Stripe (no secret values are printed)');

        $enabled  = (bool) config('billing.enabled', false);
        $secret   = (string) config('billing.stripe.secret_key', '');
        $whSecret = (string) config('billing.stripe.webhook_secret', '');
        $checkout = (bool) config('billing.checkout.enabled', false);
        $webhooks = (bool) config('billing.webhooks.enabled', false);

        if (! $enabled) {
            $this->blocker('stripe.billing_enabled', 'billing.enabled is false — the billing module is off');
        } else {
            $this->pass('stripe.billing_enabled', 'Billing module enabled');
        }

        if ($secret === '') {
            $this->blocker('stripe.secret_key', 'STRIPE_SECRET_KEY not configured');
        } elseif (str_starts_with($secret, 'sk_test_')) {
            $this->warn2('stripe.secret_key', 'Stripe TEST-mode key configured — acceptable for pilot validation; switch to live keys only with explicit approval');
        } elseif (str_starts_with($secret, 'sk_live_')) {
            $this->pass('stripe.secret_key', 'Stripe LIVE-mode key configured (value not shown)');
        } else {
            $this->warn2('stripe.secret_key', 'Stripe key present but not a recognized sk_test_/sk_live_ prefix');
        }

        if (! $checkout) {
            $this->blocker('stripe.checkout_enabled', 'billing.checkout.enabled is false — customers cannot start checkout');
        } else {
            $this->pass('stripe.checkout_enabled', 'Checkout enabled');
        }

        if (! $webhooks) {
            $this->blocker('stripe.webhooks_enabled', 'billing.webhooks.enabled is false — billing state cannot update from Stripe');
        } elseif ($whSecret === '') {
            $this->blocker('stripe.webhook_secret', 'Webhook intake enabled but STRIPE_WEBHOOK_SECRET missing — intake fails closed');
        } else {
            $this->pass('stripe.webhooks', 'Webhook intake enabled with signing secret set (value not shown)');
        }

        // Exactly one webhook consumer estate-wide: this app's route must exist;
        // the standalone GlassBilling consumer must remain unwired (verified via
        // the parity runbook — here we assert our own single route).
        $routes  = app('router')->getRoutes();
        $matches = 0;
        foreach ($routes as $route) {
            if (str_contains($route->uri(), 'billing/stripe/webhook')) {
                $matches++;
            }
        }
        if ($matches === 1) {
            $this->pass('stripe.single_webhook_consumer', 'Exactly one Stripe webhook route registered in this app');
        } elseif ($matches === 0) {
            $this->blocker('stripe.single_webhook_consumer', 'Stripe webhook route not registered');
        } else {
            $this->blocker('stripe.single_webhook_consumer', "{$matches} Stripe webhook routes registered — must be exactly one");
        }
    }

    private function checkBillingSchema(): void
    {
        $this->section('Billing schema');

        $missing = [];
        foreach (self::BILLING_TABLES as $table) {
            try {
                if (! Schema::hasTable($table)) {
                    $missing[] = $table;
                }
            } catch (\Throwable) {
                $missing[] = $table;
            }
        }

        if ($missing === []) {
            $this->pass('billing.tables', count(self::BILLING_TABLES) . ' billing/provisioning tables present');
        } else {
            $this->blocker('billing.tables', 'Missing tables: ' . implode(', ', $missing) . ' — run: php artisan migrate');
        }
    }

    private function checkRoutes(): void
    {
        $this->section('Commercial flow routes');

        $routes  = app('router')->getRoutes();
        $missing = array_values(array_filter(
            self::REQUIRED_ROUTE_NAMES,
            fn (string $name) => $routes->getByName($name) === null,
        ));

        if ($missing === []) {
            $this->pass('routes.commercial_flow', count(self::REQUIRED_ROUTE_NAMES) . ' required customer/admin routes registered');
        } else {
            $this->blocker('routes.commercial_flow', 'Missing routes: ' . implode(', ', $missing));
        }
    }

    private function checkBoundaries(): void
    {
        $this->section('Safety boundaries');

        // No infrastructure execution path: the portal must not ship provider
        // execution clients. Guard against accidental introduction.
        $forbidden = [
            app_path('Services/Provisioning/GlassPanelExecutor.php'),
            app_path('Services/Provisioning/ProxmoxClient.php'),
            app_path('Services/GlassPanel'),
        ];
        $present = array_values(array_filter($forbidden, fn ($p) => file_exists($p)));
        if ($present === []) {
            $this->pass('boundary.no_infra_execution', 'No infrastructure execution clients present (provisioning remains intent-only)');
        } else {
            $this->blocker('boundary.no_infra_execution', 'Infrastructure execution code present without approval: ' . implode(', ', $present));
        }

        // Provisioning request engine must not auto-execute: verify the model's
        // transition map still requires explicit operator transitions.
        try {
            $transitions = \App\Models\ProvisioningRequest::TRANSITIONS;
            $autoPath    = ($transitions['pending_approval'] ?? []) === ['completed'];
            if (! $autoPath) {
                $this->pass('boundary.approval_gated_provisioning', 'Provisioning requests require explicit approval transitions');
            } else {
                $this->blocker('boundary.approval_gated_provisioning', 'Provisioning transition map allows skipping approval');
            }
        } catch (\Throwable $e) {
            $this->warn2('boundary.approval_gated_provisioning', 'Could not verify transition map: ' . $e->getMessage());
        }
    }

    private function checkDocs(): void
    {
        $this->section('Documentation');

        $missing = array_values(array_filter(
            self::REQUIRED_DOCS,
            fn (string $doc) => ! is_file(base_path($doc)),
        ));

        if ($missing === []) {
            $this->pass('docs.required', count(self::REQUIRED_DOCS) . ' required architecture/runbook documents present');
        } else {
            $this->blocker('docs.required', 'Missing documents: ' . implode(', ', $missing));
        }
    }

    private function checkRuntimeDrift(): void
    {
        $this->section('Runtime drift guards');

        // The preserved companion runtime must never be configured as this
        // portal's own URL, and the read bridge should not point at :18188.
        $appUrl    = (string) config('app.url', '');
        $bridgeUrl = (string) config('glassbilling.base_url', '');

        if (str_contains($appUrl, ':18180')) {
            $this->blocker('runtime.app_url', 'APP_URL points at :18180 (preserved GlassBilling companion) — the portal is :18188. See docs/state/runtime-map.md');
        } else {
            $this->pass('runtime.app_url', 'APP_URL does not point at the preserved companion runtime');
        }

        if ($bridgeUrl !== '' && str_contains($bridgeUrl, ':18188')) {
            $this->warn2('runtime.bridge_url', 'GLASSBILLING_BASE_URL points at the portal itself (:18188) — the read bridge should target the companion runtime or be left unset');
        } else {
            $this->pass('runtime.bridge_url', $bridgeUrl === '' ? 'Read bridge not configured (acceptable for v1)' : 'Read bridge URL does not conflict with the portal runtime');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function checkRouteProtection(string $check, string $routeName, array $expected): void
    {
        try {
            $route = app('router')->getRoutes()->getByName($routeName);
            if ($route === null) {
                $this->blocker($check, "Route {$routeName} not registered");

                return;
            }
            $middleware = $route->gatherMiddleware();
            $missing    = array_values(array_diff($expected, $middleware));
            if ($missing === []) {
                $this->pass($check, "{$routeName} protected by: " . implode(', ', $expected));
            } else {
                $this->blocker($check, "{$routeName} missing middleware: " . implode(', ', $missing));
            }
        } catch (\Throwable $e) {
            $this->blocker($check, "Could not inspect {$routeName}: " . $e->getMessage());
        }
    }

    private function section(string $title): void
    {
        $this->line("  <fg=cyan>{$title}</>");
    }

    private function pass(string $check, string $message): void
    {
        $this->line("  <fg=green>✓</> <fg=white>{$check}</>  {$message}");
    }

    private function blocker(string $check, string $message): void
    {
        $this->blockers++;
        $this->line("  <fg=red>✗</> <fg=white>{$check}</>  {$message}");
    }

    private function warn2(string $check, string $message): void
    {
        $this->warnings++;
        $this->line("  <fg=yellow>!</> <fg=white>{$check}</>  {$message}");
    }
}
