<?php

namespace App\Console\Commands;

use App\Services\GlassBilling\GlassBillingClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GlassPortalHealthCheck extends Command
{
    protected $signature   = 'glassportal:healthcheck {--strict : Fail if GlassBilling is unreachable or returns auth error}';
    protected $description = 'Run GlassPortal system health checks and report status';

    public function handle(GlassBillingClient $billing): int
    {
        $strict     = (bool) $this->option('strict');
        $allPassed  = true;

        $this->line('');
        $this->line('  <fg=blue>GlassPortal Health Check</>');
        $this->line('  ' . now()->toIso8601String());
        if ($strict) {
            $this->line('  <fg=yellow>Mode: strict</>');
        }
        $this->line('');

        // 1. App boots (trivially true if we reach this point)
        $this->pass('app.boot', 'Application bootstrap OK');

        // 2. APP_KEY is set
        $key = config('app.key', '');
        if ($key !== '' && str_starts_with($key, 'base64:')) {
            $this->pass('app.key', 'APP_KEY is set');
        } else {
            $this->checkFail('app.key', 'APP_KEY is missing or not generated — run: php artisan key:generate');
            $allPassed = false;
        }

        // 3. Storage writable
        if (is_writable(storage_path())) {
            $this->pass('storage.writable', 'storage/ is writable');
        } else {
            $this->checkFail('storage.writable', 'storage/ is not writable — run: chmod -R 775 storage');
            $allPassed = false;
        }

        // 4. Sessions configured
        $driver = config('session.driver', '');
        if ($driver !== '') {
            $this->pass('session.driver', "Session driver: {$driver}");
        } else {
            $this->checkFail('session.driver', 'SESSION_DRIVER is not configured');
            $allPassed = false;
        }

        // 5. DB reachable
        try {
            DB::connection()->getPdo();
            $dbDriver = DB::connection()->getDriverName();
            $this->pass('db.connect', "Database reachable ({$dbDriver})");
        } catch (\Throwable $e) {
            $this->checkFail('db.connect', 'Database not reachable: ' . $e->getMessage());
            $allPassed = false;
        }

        // 6. Auth tables exist
        try {
            $usersOk  = Schema::hasTable('users');
            $orgsOk   = Schema::hasTable('organizations');

            if ($usersOk && $orgsOk) {
                $this->pass('db.auth_tables', 'users + organizations tables exist');
            } else {
                $missing = implode(', ', array_filter([
                    $usersOk  ? null : 'users',
                    $orgsOk   ? null : 'organizations',
                ]));
                $this->checkFail('db.auth_tables', "Missing tables: {$missing} — run: php artisan migrate");
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->checkFail('db.auth_tables', 'Could not check auth tables: ' . $e->getMessage());
            $allPassed = false;
        }

        // 6b. Customer mapping column
        try {
            if (Schema::hasColumn('organizations', 'glassbilling_customer_id')) {
                $this->pass('db.customer_mapping', 'organizations.glassbilling_customer_id column present');
            } else {
                $this->checkFail('db.customer_mapping', 'organizations.glassbilling_customer_id missing — run: php artisan migrate');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->warnCheck('db.customer_mapping', 'Could not check customer mapping column: ' . $e->getMessage());
        }

        // 6c. Module links table
        try {
            if (Schema::hasTable('organization_module_links')) {
                $this->pass('db.module_links', 'organization_module_links table present');
            } else {
                $this->checkFail('db.module_links', 'organization_module_links table missing — run: php artisan migrate');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->checkFail('db.module_links', 'Could not check module links table: ' . $e->getMessage());
            $allPassed = false;
        }

        // 6d. Module launch events table
        try {
            if (Schema::hasTable('module_launch_events')) {
                $this->pass('db.module_launch_events', 'module_launch_events table present');
            } else {
                $this->checkFail('db.module_launch_events', 'module_launch_events table missing — run: php artisan migrate');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->checkFail('db.module_launch_events', 'Could not check module_launch_events table: ' . $e->getMessage());
            $allPassed = false;
        }

        // 7. Module config loads
        try {
            $modules       = config('glasshouse.modules', null);
            $launchModules = config('glasshouse.launch_modules', null);
            if (is_array($modules) && is_array($launchModules)) {
                $this->pass('config.modules', count($modules) . ' connector module(s), ' . count($launchModules) . ' launch module(s) in config/glasshouse.php');
            } else {
                $this->checkFail('config.modules', 'config/glasshouse.php did not return expected modules arrays');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->checkFail('config.modules', 'Error loading module config: ' . $e->getMessage());
            $allPassed = false;
        }

        // 7b. Launch module route registered
        try {
            $routes = app('router')->getRoutes();
            if ($routes->getByName('portal.module.launch') !== null) {
                $this->pass('routes.module_launch', 'portal.module.launch route registered');
            } else {
                $this->checkFail('routes.module_launch', 'portal.module.launch route not found — check routes/web.php');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->warnCheck('routes.module_launch', 'Could not verify module launch route: ' . $e->getMessage());
        }

        // 7c. Launch module config keys
        try {
            $launchModules = config('glasshouse.launch_modules', []);
            $requiredKeys  = ['glassbilling', 'glasspanel', 'aria', 'dns', 'mail', 'support', 'infrastructure'];
            $missing       = array_diff($requiredKeys, array_keys($launchModules));
            if (empty($missing)) {
                $this->pass('config.launch_modules', 'All ' . count($requiredKeys) . ' expected launch module keys present');
            } else {
                $this->warnCheck('config.launch_modules', 'Missing launch module keys: ' . implode(', ', $missing));
            }
        } catch (\Throwable $e) {
            $this->warnCheck('config.launch_modules', 'Could not verify launch module keys: ' . $e->getMessage());
        }

        // 7d. Signed launch secret
        try {
            $secret = config('glasshouse_sso.signing_secret', '');
            // Detect active signed_launch links only if DB is available
            $hasSignedLinks = false;
            try {
                $hasSignedLinks = \App\Models\OrganizationModuleLink::where('auth_mode', 'signed_launch')
                    ->where('status', 'active')
                    ->exists();
            } catch (\Throwable) {
                // DB not ready — skip active-link check
            }

            if ($secret !== '') {
                $this->pass('config.signed_launch', 'GLASSPORTAL_SIGNED_LAUNCH_SECRET is configured');
            } elseif ($hasSignedLinks) {
                $this->checkFail('config.signed_launch', 'GLASSPORTAL_SIGNED_LAUNCH_SECRET is not set but active signed_launch links exist — launches will fail');
                $allPassed = false;
            } else {
                $this->warnCheck('config.signed_launch', 'GLASSPORTAL_SIGNED_LAUNCH_SECRET is not set (no active signed_launch links detected — set before enabling signed_launch links)');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('config.signed_launch', 'Could not check signed launch secret: ' . $e->getMessage());
        }

        // 7e. Key ID (Phase 9) — informational only
        try {
            $keyId = config('glasshouse_sso.key_id', '');
            if ($keyId !== '') {
                $this->pass('sso.key_id', "GLASSPORTAL_SIGNED_LAUNCH_KEY_ID is set: {$keyId}");
            } else {
                $this->warnCheck('sso.key_id', 'GLASSPORTAL_SIGNED_LAUNCH_KEY_ID not set — tokens issued without kid (single-secret mode)');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('sso.key_id', 'Could not check key_id: ' . $e->getMessage());
        }

        // 7e-ii. Middleware alias 'signed.launch' (Phase 9)
        // Middleware aliases from bootstrap/app.php are HTTP-kernel-scoped and not
        // visible to the router during artisan; verify by checking the class exists
        // and that the dev route (which uses the alias) is registered.
        try {
            $mwClass  = \App\Http\Middleware\VerifySignedModuleLaunch::class;
            $devRoute = app('router')->getRoutes()->getByName('dev.sso.consume');
            if (class_exists($mwClass) && $devRoute !== null) {
                $this->pass('middleware.signed_launch', 'signed.launch middleware class present and applied to dev.sso.consume route');
            } elseif (class_exists($mwClass)) {
                $this->warnCheck('middleware.signed_launch', 'VerifySignedModuleLaunch class exists; register alias in bootstrap/app.php if missing');
            } else {
                $this->checkFail('middleware.signed_launch', 'VerifySignedModuleLaunch class not found — check app/Http/Middleware/');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->warnCheck('middleware.signed_launch', 'Could not check signed.launch middleware: ' . $e->getMessage());
        }

        // 7e-iii. Verifier service resolvable (Phase 9)
        try {
            app(\App\Services\Sso\SignedLaunchVerifierService::class);
            $this->pass('verifier.service', 'SignedLaunchVerifierService is resolvable from container');
        } catch (\Throwable $e) {
            $this->checkFail('verifier.service', 'SignedLaunchVerifierService not resolvable: ' . $e->getMessage());
            $allPassed = false;
        }

        // 7e-iv. ModuleSignedLaunchVerifier resolvable (Phase 10)
        try {
            app(\App\Services\Sso\ModuleSignedLaunchVerifier::class);
            $this->pass('sso.module_verifier', 'ModuleSignedLaunchVerifier is resolvable from container');
        } catch (\Throwable $e) {
            $this->checkFail('sso.module_verifier', 'ModuleSignedLaunchVerifier not resolvable: ' . $e->getMessage());
            $allPassed = false;
        }

        // 7e-v. Replay-protection cache usable (Phase 10)
        try {
            $modVerifier = app(\App\Services\Sso\ModuleSignedLaunchVerifier::class);
            if ($modVerifier->isCacheUsable()) {
                $this->pass('sso.replay_cache', 'JTI replay-protection cache is writable and readable');
            } else {
                $this->warnCheck('sso.replay_cache', 'Cache probe failed — replay protection is degraded; check CACHE_STORE');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('sso.replay_cache', 'Could not probe replay cache: ' . $e->getMessage());
        }

        // 7f. Dev SSO consumer route (Phase 9) — only expected in local/testing
        try {
            $routes    = app('router')->getRoutes();
            $devRoute  = $routes->getByName('dev.sso.consume');
            $isDevEnv  = app()->environment('local', 'testing');

            if ($devRoute !== null && $isDevEnv) {
                $this->pass('routes.sso_consumer_dev', 'dev.sso.consume route registered (local/testing only)');
            } elseif ($devRoute !== null && ! $isDevEnv) {
                $this->checkFail('routes.sso_consumer_dev', 'dev.sso.consume route is registered in a non-dev environment — check routes/web.php');
                $allPassed = false;
            } else {
                $this->warnCheck('routes.sso_consumer_dev', 'dev.sso.consume route not registered (expected only in local/testing)');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('routes.sso_consumer_dev', 'Could not check dev SSO consumer route: ' . $e->getMessage());
        }

        // 7g. Launch rate limit config (Phase 9)
        try {
            $limit = (int) config('glasshouse_sso.rate_limit_per_minute', 20);
            if ($limit > 0) {
                $this->pass('rate_limits.module_launch', "Portal launch rate limit: {$limit} requests/minute/user/link");
            } else {
                $this->warnCheck('rate_limits.module_launch', 'GLASSPORTAL_LAUNCH_RATE_LIMIT is 0 — rate limiting is effectively disabled');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('rate_limits.module_launch', 'Could not check launch rate limit config: ' . $e->getMessage());
        }

        // 8. GlassBilling connector
        try {
            $health = $billing->health();
            $status = $health['status'];
            $detail = $health['detail'] ?? '';
            $latency = isset($health['latency_ms']) ? " ({$health['latency_ms']}ms)" : '';

            if ($status === 'online') {
                $this->pass('glassbilling.health', "GlassBilling: online — {$detail}{$latency}");
            } elseif ($status === 'unconfigured') {
                $this->warnCheck('glassbilling.health', 'GlassBilling: not configured (set GLASSBILLING_BASE_URL + GLASSBILLING_API_TOKEN)');
            } else {
                // offline or auth error
                $httpStatus = $health['http_status'] ?? null;
                $isAuthError = $httpStatus === 401 || $httpStatus === 403;

                if ($strict) {
                    $this->checkFail('glassbilling.health', "GlassBilling: {$status} — {$detail}{$latency}");
                    $allPassed = false;
                } else {
                    $this->warnCheck('glassbilling.health', "GlassBilling: {$status} — {$detail}{$latency}");
                }

                if ($isAuthError) {
                    $this->warnCheck('glassbilling.auth', 'GlassBilling returned an auth error — verify GLASSBILLING_API_TOKEN');
                    if ($strict) {
                        $allPassed = false;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->warnCheck('glassbilling.health', 'GlassBilling: exception — ' . $e->getMessage());
        }

        $this->line('');

        if ($allPassed) {
            $this->line('  <fg=green>All required checks passed.</>');
        } else {
            $this->line('  <fg=red>One or more required checks failed. See above.</>');
        }

        $this->line('');

        return $allPassed ? self::SUCCESS : self::FAILURE;
    }

    protected function pass(string $check, string $message): void
    {
        $this->line("  <fg=green>✓</> <fg=white>{$check}</>  {$message}");
    }

    protected function checkFail(string $check, string $message): void
    {
        $this->line("  <fg=red>✗</> <fg=white>{$check}</>  {$message}");
    }

    protected function warnCheck(string $check, string $message): void
    {
        $this->line("  <fg=yellow>!</> <fg=white>{$check}</>  {$message}");
    }
}
