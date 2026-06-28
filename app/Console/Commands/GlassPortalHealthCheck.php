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

        // 7e-vi. Back-channel service resolvable (Phase 11)
        try {
            app(\App\Services\Sso\BackChannelLaunchService::class);
            $this->pass('sso.backchannel_service', 'BackChannelLaunchService is resolvable from container');
        } catch (\Throwable $e) {
            $this->checkFail('sso.backchannel_service', 'BackChannelLaunchService not resolvable: ' . $e->getMessage());
            $allPassed = false;
        }

        // 7e-vii. Back-channel cache probe (Phase 11)
        try {
            $bcService = app(\App\Services\Sso\BackChannelLaunchService::class);
            if ($bcService->isCacheUsable()) {
                $this->pass('sso.backchannel_cache', 'Back-channel code cache is writable and readable');
            } else {
                $this->warnCheck('sso.backchannel_cache', 'Back-channel cache probe failed — check CACHE_STORE');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('sso.backchannel_cache', 'Could not probe back-channel cache: ' . $e->getMessage());
        }

        // 7e-viii. Back-channel redemption route registered (Phase 11)
        try {
            $routes    = app('router')->getRoutes();
            $bcRoute   = $routes->getByName('api.sso.backchannel.redeem');
            $bcEnabled = config('glasshouse_sso.backchannel.enabled', false);

            if ($bcRoute !== null && $bcEnabled) {
                $this->pass('routes.backchannel_redeem', 'api.sso.backchannel.redeem route registered and back-channel enabled');
            } elseif ($bcRoute !== null) {
                $this->warnCheck('routes.backchannel_redeem', 'api.sso.backchannel.redeem route registered but GLASSPORTAL_BACKCHANNEL_SSO_ENABLED is false');
            } else {
                $this->warnCheck('routes.backchannel_redeem', 'api.sso.backchannel.redeem route not found — check routes/api.php');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('routes.backchannel_redeem', 'Could not check back-channel redeem route: ' . $e->getMessage());
        }

        // 7e-ix. Back-channel config (Phase 11) — informational
        try {
            $bcEnabled = config('glasshouse_sso.backchannel.enabled', false);
            $bcTtl     = (int) config('glasshouse_sso.backchannel.code_ttl_seconds', 60);
            $hasLinks  = false;
            try {
                $hasLinks = \App\Models\OrganizationModuleLink::where('auth_mode', 'backchannel_launch')
                    ->where('status', 'active')
                    ->exists();
            } catch (\Throwable) {
                // DB not ready
            }

            if ($bcEnabled) {
                $this->pass('config.backchannel', "Back-channel SSO enabled (code TTL: {$bcTtl}s)");
            } elseif ($hasLinks) {
                $this->checkFail('config.backchannel', 'Active backchannel_launch links exist but GLASSPORTAL_BACKCHANNEL_SSO_ENABLED is false — launches will fail');
                $allPassed = false;
            } else {
                $this->warnCheck('config.backchannel', 'Back-channel SSO not enabled (set GLASSPORTAL_BACKCHANNEL_SSO_ENABLED=true to enable)');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('config.backchannel', 'Could not check back-channel config: ' . $e->getMessage());
        }

        // 7e-x. Per-module secret capability (Phase 12)
        try {
            $resolver     = app(\App\Services\Sso\ModuleSecretResolver::class);
            $signedModules = [];
            try {
                $signedModules = \App\Models\OrganizationModuleLink::where('auth_mode', 'signed_launch')
                    ->where('status', 'active')
                    ->pluck('module_key')
                    ->unique()
                    ->all();
            } catch (\Throwable) {}

            $withPerModule    = array_values(array_filter($signedModules, fn ($k) => $resolver->hasPerModuleSecret($k)));
            $withoutPerModule = array_values(array_diff($signedModules, $withPerModule));

            if (empty($signedModules)) {
                $this->warnCheck('sso.per_module_secrets', 'No active signed_launch links — per-module secrets not yet needed');
            } elseif (empty($withoutPerModule)) {
                $this->pass('sso.per_module_secrets', count($withPerModule) . ' signed_launch module(s) use per-module secrets');
            } elseif (app()->environment('production')) {
                $list = implode(', ', $withoutPerModule);
                $this->warnCheck('sso.per_module_secrets', "Modules using global shared secret in production: {$list} — per-module secrets recommended for isolation");
            } else {
                $list = implode(', ', $withoutPerModule);
                $this->pass('sso.per_module_secrets', "Per-module secrets not set for: {$list} (using global fallback — acceptable in dev)");
            }
        } catch (\Throwable $e) {
            $this->warnCheck('sso.per_module_secrets', 'Could not check per-module secrets: ' . $e->getMessage());
        }

        // 7e-xi. mTLS config state (Phase 12)
        try {
            $mtlsRequired = (bool) config('glasshouse_sso.backchannel.require_mtls', false);
            $bcEnabled    = (bool) config('glasshouse_sso.backchannel.enabled', false);

            if ($mtlsRequired) {
                $header = config('glasshouse_sso.backchannel.mtls_verified_header', 'X-Client-Cert-Verified');
                $this->pass('sso.backchannel_mtls', "mTLS required on back-channel redeem endpoint (header: {$header})");
            } elseif ($bcEnabled && app()->environment('production')) {
                $this->warnCheck('sso.backchannel_mtls', 'Back-channel enabled but GLASSPORTAL_BACKCHANNEL_REQUIRE_MTLS is false — consider enabling mTLS in production');
            } else {
                $this->warnCheck('sso.backchannel_mtls', 'mTLS not required on back-channel redeem endpoint (acceptable in dev/staging)');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('sso.backchannel_mtls', 'Could not check mTLS config: ' . $e->getMessage());
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

        // 7h. Portal-auth SDK readiness (Phase 13/14)

        // 7h-i. Package path present
        try {
            $pkgPath = base_path('packages/glasshouse/portal-auth');
            if (is_dir($pkgPath)) {
                $this->pass('sso.portal_auth_sdk.path', "SDK package directory present: packages/glasshouse/portal-auth");
            } else {
                $this->warnCheck('sso.portal_auth_sdk.path', 'SDK package directory missing: packages/glasshouse/portal-auth — run: git submodule update or restore the package');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('sso.portal_auth_sdk.path', 'Could not check SDK path: ' . $e->getMessage());
        }

        // 7h-ii. Package composer.json valid and correct
        try {
            $pkgComposer = base_path('packages/glasshouse/portal-auth/composer.json');
            if (! file_exists($pkgComposer)) {
                $this->warnCheck('sso.portal_auth_sdk.composer', 'SDK composer.json missing — run: composer dump-autoload');
            } else {
                $manifest = json_decode(file_get_contents($pkgComposer), true);
                $pkgName  = $manifest['name'] ?? '';
                $phpReq   = $manifest['require']['php'] ?? '';
                $ns       = array_key_first((array) ($manifest['autoload']['psr-4'] ?? []));

                if ($pkgName === 'glasshouse/portal-auth' && str_contains($phpReq, '8.') && $ns === 'GlassHouse\\PortalAuth\\') {
                    $ver = $manifest['version'] ?? 'unversioned';
                    $this->pass('sso.portal_auth_sdk.composer', "SDK composer.json valid — {$pkgName} v{$ver}, PHP {$phpReq}, namespace {$ns}");
                } else {
                    $this->warnCheck('sso.portal_auth_sdk.composer', "SDK composer.json unexpected values — name={$pkgName}, php={$phpReq}, ns={$ns}");
                }
            }
        } catch (\Throwable $e) {
            $this->warnCheck('sso.portal_auth_sdk.composer', 'Could not validate SDK composer.json: ' . $e->getMessage());
        }

        // 7h-iii. SDK classes autoloadable
        try {
            $coreClasses = [
                \GlassHouse\PortalAuth\Sso\SignedLaunchVerifier::class,
                \GlassHouse\PortalAuth\Sso\ModuleSecretResolver::class,
                \GlassHouse\PortalAuth\Sso\SignedLaunchTokenParser::class,
                \GlassHouse\PortalAuth\Replay\ArrayReplayStore::class,
                \GlassHouse\PortalAuth\Replay\LaravelCacheReplayStore::class,
                \GlassHouse\PortalAuth\Contracts\SecretResolverInterface::class,
                \GlassHouse\PortalAuth\Contracts\ReplayStoreInterface::class,
                \GlassHouse\PortalAuth\DTO\SignedLaunchVerificationResult::class,
                \GlassHouse\PortalAuth\DTO\VerifiedLaunchContext::class,
                \GlassHouse\PortalAuth\DTO\BackChannelRedeemResult::class,
                \GlassHouse\PortalAuth\Exceptions\PortalAuthException::class,
                \GlassHouse\PortalAuth\Laravel\PortalAuthServiceProvider::class,
                \GlassHouse\PortalAuth\Laravel\Middleware\VerifySignedModuleLaunch::class,
                \GlassHouse\PortalAuth\Laravel\Middleware\VerifyBackChannelMtls::class,
            ];
            $missing = [];
            foreach ($coreClasses as $class) {
                if (! class_exists($class) && ! interface_exists($class)) {
                    $missing[] = basename(str_replace('\\', '/', $class));
                }
            }
            if (empty($missing)) {
                $this->pass('sso.portal_auth_sdk', 'SDK classes autoloadable (' . count($coreClasses) . ' checked)');
            } else {
                // Warn: missing classes in dev typically means composer dump-autoload hasn't been run.
                $this->warnCheck('sso.portal_auth_sdk', 'SDK classes not autoloadable: ' . implode(', ', $missing) . ' — run: composer dump-autoload');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('sso.portal_auth_sdk', 'Could not check SDK classes: ' . $e->getMessage());
        }

        // 7i. Phase 15 — key registry / JWKS

        // 7i-i. At least one signing key configured (registry or legacy)
        try {
            $keyResolver = app(\App\Services\Sso\SigningKeyResolver::class);
            $legacySecret = (string) config('glasshouse_sso.signing_secret', '');
            $hasRegistry  = $keyResolver->hasRegistry();

            if ($keyResolver->hasActiveKey()) {
                $activeKid = config('glasshouse_sso.active_kid', '');
                $this->pass('sso.keys_configured', "Active signing key configured (kid: {$activeKid})");
            } elseif ($hasRegistry) {
                $this->warnCheck('sso.keys_configured', 'key_registry is present but active_kid is not set or does not point to an active entry — set GLASSPORTAL_SIGNED_LAUNCH_ACTIVE_KID');
            } elseif ($legacySecret !== '') {
                $this->pass('sso.keys_configured', 'Using legacy signing_secret (no key_registry — acceptable in dev; migrate to key_registry for rotation support)');
            } else {
                $hasSignedLinks = false;
                try {
                    $hasSignedLinks = \App\Models\OrganizationModuleLink::whereIn('auth_mode', ['signed_launch', 'backchannel_launch'])
                        ->where('status', 'active')
                        ->exists();
                } catch (\Throwable) {}

                if ($hasSignedLinks) {
                    $this->checkFail('sso.keys_configured', 'No signing key configured but active SSO links exist — set GLASSPORTAL_SIGNED_LAUNCH_SECRET or configure key_registry');
                    $allPassed = false;
                } else {
                    $this->warnCheck('sso.keys_configured', 'No signing key configured (no active SSO links — set GLASSPORTAL_SIGNED_LAUNCH_SECRET before enabling)');
                }
            }
        } catch (\Throwable $e) {
            $this->warnCheck('sso.keys_configured', 'Could not check signing key config: ' . $e->getMessage());
        }

        // 7i-ii. active_kid validity
        try {
            $activeKid = (string) config('glasshouse_sso.active_kid', '');
            if ($activeKid === '') {
                $this->warnCheck('sso.active_kid', 'GLASSPORTAL_SIGNED_LAUNCH_ACTIVE_KID not set — using legacy mode (acceptable in dev)');
            } else {
                $keyResolver = app(\App\Services\Sso\SigningKeyResolver::class);
                if ($keyResolver->hasActiveKey()) {
                    $this->pass('sso.active_kid', "active_kid '{$activeKid}' resolves to a valid active key_registry entry");
                } else {
                    $registry = (array) config('glasshouse_sso.key_registry', []);
                    $entry    = $registry[$activeKid] ?? null;
                    $status   = $entry['status'] ?? 'missing';
                    $this->warnCheck('sso.active_kid', "active_kid '{$activeKid}' is set but status is '{$status}' — key not usable for issuance");
                }
            }
        } catch (\Throwable $e) {
            $this->warnCheck('sso.active_kid', 'Could not check active_kid config: ' . $e->getMessage());
        }

        // 7i-iii. JWKS route registered
        try {
            $routes    = app('router')->getRoutes();
            $jwksRoute = $routes->getByName('glassportal.jwks');
            if ($jwksRoute !== null) {
                $this->pass('sso.jwks_route', 'glassportal.jwks route registered at /.well-known/glassportal/jwks.json');
            } else {
                $this->warnCheck('sso.jwks_route', 'glassportal.jwks route not found — check routes/web.php');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('sso.jwks_route', 'Could not check JWKS route: ' . $e->getMessage());
        }

        // 7i-iv. Legacy secret fallback warning
        try {
            $hasRegistry  = count((array) config('glasshouse_sso.key_registry', [])) > 0;
            $legacySecret = (string) config('glasshouse_sso.signing_secret', '');
            if (! $hasRegistry && $legacySecret !== '' && app()->environment('production')) {
                $this->warnCheck('sso.legacy_secret_fallback', 'Using global signing_secret without key_registry in production — consider migrating to key_registry for rotation support');
            } elseif (! $hasRegistry && $legacySecret !== '') {
                $this->pass('sso.legacy_secret_fallback', 'Legacy single-secret mode (no key_registry — acceptable in dev)');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('sso.legacy_secret_fallback', 'Could not check legacy secret fallback: ' . $e->getMessage());
        }

        // 7j. SIONA connector checks (Phase 18)

        // 7j-i. SIONA present in both registries
        try {
            $sionaInModules = config('glasshouse.modules.siona') !== null;
            $sionaInLaunch  = config('glasshouse.launch_modules.siona') !== null;

            if ($sionaInModules && $sionaInLaunch) {
                $this->pass('siona.module_registry', 'SIONA present in connector registry and customer launch registry');
            } else {
                $missing = implode(', ', array_filter([
                    $sionaInModules ? null : 'glasshouse.modules.siona',
                    $sionaInLaunch  ? null : 'glasshouse.launch_modules.siona',
                ]));
                $this->warnCheck('siona.module_registry', "SIONA missing from: {$missing} — check config/glasshouse.php");
            }
        } catch (\Throwable $e) {
            $this->warnCheck('siona.module_registry', 'Could not check SIONA module registry: ' . $e->getMessage());
        }

        // 7j-ii. SIONA config state
        try {
            $sionaEnabled = (bool) config('siona.enabled', false);
            $sionaUrl     = (string) config('siona.api_url', '');

            if ($sionaEnabled && $sionaUrl !== '') {
                $this->pass('siona.config', "SIONA connector enabled, API URL configured: {$sionaUrl}");
            } elseif ($sionaEnabled) {
                $this->warnCheck('siona.config', 'SIONA enabled but SIONA_API_URL is not set — health probing disabled');
            } else {
                $this->warnCheck('siona.config', 'SIONA connector not enabled (set SIONA_ENABLED=true and SIONA_API_URL to activate)');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('siona.config', 'Could not check SIONA config: ' . $e->getMessage());
        }

        // 7j-iii. SIONA connector health route registered
        try {
            $routes     = app('router')->getRoutes();
            $sionaRoute = $routes->getByName('api.connectors.siona.health');
            if ($sionaRoute !== null) {
                $this->pass('siona.connector_route', 'api.connectors.siona.health route registered at /api/connectors/siona/health');
            } else {
                $this->warnCheck('siona.connector_route', 'api.connectors.siona.health route not found — check routes/api.php');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('siona.connector_route', 'Could not check SIONA connector route: ' . $e->getMessage());
        }

        // 7k. SIONA Phase 19 — connector client and launch readiness

        // 7k-i. SionaConnectorClient resolvable from container
        try {
            $sionaClient = app(\App\Services\Siona\SionaConnectorClient::class);
            $this->pass('siona.connector_client', 'SionaConnectorClient is resolvable from container');
        } catch (\Throwable $e) {
            $this->checkFail('siona.connector_client', 'SionaConnectorClient not resolvable: ' . $e->getMessage());
            $allPassed = false;
        }

        // 7k-ii. SIONA launch registry has supported_auth_modes
        try {
            $sionald = config('glasshouse.launch_modules.siona', null);
            $modes   = $sionald['supported_auth_modes'] ?? [];
            $required = ['standalone', 'signed_launch', 'backchannel_launch'];
            $missing  = array_diff($required, $modes);

            if ($sionald !== null && empty($missing)) {
                $this->pass('siona.launch_registry', 'SIONA launch_modules entry has all required supported_auth_modes: ' . implode(', ', $modes));
            } elseif ($sionald !== null) {
                $this->warnCheck('siona.launch_registry', 'SIONA launch_modules entry is missing supported_auth_modes: ' . implode(', ', $missing));
            } else {
                $this->warnCheck('siona.launch_registry', 'SIONA not found in glasshouse.launch_modules — check config/glasshouse.php');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('siona.launch_registry', 'Could not check SIONA launch registry: ' . $e->getMessage());
        }

        // 7k-iii. organization_module_links.metadata column present (supports workspace mapping)
        try {
            if (Schema::hasColumn('organization_module_links', 'metadata')) {
                $this->pass('siona.module_link_support', 'organization_module_links.metadata column present — supports SIONA workspace mapping via metadata JSON');
            } else {
                $this->warnCheck('siona.module_link_support', 'organization_module_links.metadata column missing — SIONA workspace mapping unavailable');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('siona.module_link_support', 'Could not check metadata column: ' . $e->getMessage());
        }

        // 7k-iv. SIONA health probe — warn-only
        try {
            $sionaClient = app(\App\Services\Siona\SionaConnectorClient::class);
            $health      = $sionaClient->health();
            $status      = $health['status'];
            $latency     = isset($health['latency_ms']) ? " ({$health['latency_ms']}ms)" : '';

            if ($status === 'ok') {
                $this->pass('siona.health_probe', "SIONA health probe: ok{$latency}");
            } elseif ($status === 'unconfigured') {
                $this->warnCheck('siona.health_probe', 'SIONA health probe: unconfigured — set SIONA_ENABLED=true and SIONA_API_URL to enable');
            } else {
                $this->warnCheck('siona.health_probe', "SIONA health probe: {$status} — {$health['message']}");
            }
        } catch (\Throwable $e) {
            $this->warnCheck('siona.health_probe', 'SIONA health probe failed: ' . $e->getMessage());
        }

        // 7l. SIONA Phase 20 — tenant provisioning + account linking
        // All warn-only — unconfigured provisioning never fails the healthcheck.

        // 7l-i. Tenant provisioning config
        try {
            $provEnabled = (bool) config('siona.provisioning.enabled', false);
            $provPath    = (string) config('siona.provisioning.path', '');
            $sionaClient = app(\App\Services\Siona\SionaConnectorClient::class);

            if ($provEnabled && $provPath !== '' && $sionaClient->isProvisioningConfigured()) {
                $this->pass('siona.tenant_provisioning_config', "SIONA tenant provisioning enabled (POST {$provPath})");
            } elseif ($provEnabled && $provPath !== '') {
                $this->warnCheck('siona.tenant_provisioning_config', 'SIONA tenant provisioning enabled but back-channel not ready — set SIONA_ENABLED=true, SIONA_API_URL, and SIONA_API_TOKEN');
            } elseif ($provEnabled) {
                $this->warnCheck('siona.tenant_provisioning_config', 'SIONA_PROVISIONING_ENABLED is true but SIONA_PROVISIONING_PATH is empty — set the tenant endpoint path');
            } else {
                $this->warnCheck('siona.tenant_provisioning_config', 'SIONA tenant provisioning not enabled (set SIONA_PROVISIONING_ENABLED=true to enable)');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('siona.tenant_provisioning_config', 'Could not check SIONA tenant provisioning config: ' . $e->getMessage());
        }

        // 7l-ii. Workspace mapping column
        try {
            if (Schema::hasColumn('organizations', 'siona_workspace_id')) {
                $this->pass('siona.workspace_mapping_column', 'organizations.siona_workspace_id column present');
            } else {
                $this->warnCheck('siona.workspace_mapping_column', 'organizations.siona_workspace_id missing — run: php artisan migrate');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('siona.workspace_mapping_column', 'Could not check siona_workspace_id column: ' . $e->getMessage());
        }

        // 7l-iii. Management back-channel readiness (server-to-server API credentials)
        // Reports presence only — the token value is never read or printed here.
        try {
            $sionaClient = app(\App\Services\Siona\SionaConnectorClient::class);
            if ($sionaClient->isBackChannelReady()) {
                $this->pass('siona.backchannel_ready', 'SIONA management back-channel ready (API URL + token present)');
            } elseif ((bool) config('siona.enabled', false) && (string) config('siona.api_url', '') !== '') {
                $this->warnCheck('siona.backchannel_ready', 'SIONA API URL set but SIONA_API_TOKEN is empty — back-channel calls (provisioning) would be unauthenticated');
            } else {
                $this->warnCheck('siona.backchannel_ready', 'SIONA management back-channel not ready (set SIONA_ENABLED=true, SIONA_API_URL, SIONA_API_TOKEN)');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('siona.backchannel_ready', 'Could not check SIONA back-channel readiness: ' . $e->getMessage());
        }

        // 7m. SIONA Phase 21A — per-module signing secret
        // Reports presence/absence and fallback mode only — never the secret value.
        try {
            $resolver     = app(\App\Services\Sso\ModuleSecretResolver::class);
            $hasDedicated = $resolver->hasPerModuleSecret('siona');
            $hasFallback  = $resolver->activeKeyInfo() !== null
                || (string) config('glasshouse_sso.signing_secret', '') !== '';

            // Are there active SIONA links that actually depend on a signing secret?
            $sionaSsoActive = false;
            try {
                $sionaSsoActive = \App\Models\OrganizationModuleLink::where('module_key', 'siona')
                    ->whereIn('auth_mode', ['signed_launch', 'backchannel_launch'])
                    ->where('status', 'active')
                    ->exists();
            } catch (\Throwable) {
                // DB not ready — treat as no active links
            }

            if ($hasDedicated) {
                $this->pass('siona.per_module_secret', 'SIONA uses a dedicated per-module signing secret (GLASSPORTAL_MODULE_SECRET_SIONA set)');
            } elseif (! $sionaSsoActive) {
                $this->warnCheck('siona.per_module_secret', 'No active SIONA signed_launch/backchannel links — dedicated GLASSPORTAL_MODULE_SECRET_SIONA not yet required (global fallback in effect)');
            } elseif ($hasFallback) {
                $this->warnCheck('siona.per_module_secret', 'SIONA signed_launch/backchannel is active but using the GLOBAL fallback secret — set GLASSPORTAL_MODULE_SECRET_SIONA for per-module isolation');
            } else {
                $this->checkFail('siona.per_module_secret', 'SIONA signed_launch/backchannel is active but NO signing secret is configured — set GLASSPORTAL_MODULE_SECRET_SIONA or GLASSPORTAL_SIGNED_LAUNCH_SECRET; launches will fail');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->warnCheck('siona.per_module_secret', 'Could not check SIONA per-module signing secret: ' . $e->getMessage());
        }

        // 7n. GlassSite (Phase 22) — public product catalog

        // 7n-i. Catalog table present
        try {
            if (Schema::hasTable('public_product_catalog_entries')) {
                $this->pass('glasssite.catalog_table', 'public_product_catalog_entries table present');
            } else {
                $this->checkFail('glasssite.catalog_table', 'public_product_catalog_entries table missing — run: php artisan migrate');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->checkFail('glasssite.catalog_table', 'Could not check catalog table: ' . $e->getMessage());
            $allPassed = false;
        }

        // 7n-ii. Public catalog routes registered
        try {
            $routes   = app('router')->getRoutes();
            $indexRt  = $routes->getByName('public.products.index');
            $showRt    = $routes->getByName('public.products.show');
            if ($indexRt !== null && $showRt !== null) {
                $this->pass('glasssite.public_routes', 'public catalog routes registered (/products, /products/{slug})');
            } else {
                $this->checkFail('glasssite.public_routes', 'public catalog routes missing — check routes/web.php');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->warnCheck('glasssite.public_routes', 'Could not check public catalog routes: ' . $e->getMessage());
        }

        // 7n-iii. Admin catalog routes registered
        try {
            $adminRt = app('router')->getRoutes()->getByName('admin.site.catalog.index');
            if ($adminRt !== null) {
                $this->pass('glasssite.admin_routes', 'admin catalog routes registered (admin/site/catalog)');
            } else {
                $this->checkFail('glasssite.admin_routes', 'admin catalog routes missing — check routes/web.php');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->warnCheck('glasssite.admin_routes', 'Could not check admin catalog routes: ' . $e->getMessage());
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

        // 9. Billing source-of-truth documentation (Phase 23) — non-blocking.
        // Advisory only: verifies the reconciliation report + ADR are present so
        // billing work can find the decision. Never fails the healthcheck.
        try {
            if (is_file(base_path('docs/phase23/billing-source-reconciliation.md'))) {
                $this->pass('billing.source_reconciliation_doc', 'Billing source reconciliation report present (docs/phase23/billing-source-reconciliation.md)');
            } else {
                $this->warnCheck('billing.source_reconciliation_doc', 'Billing source reconciliation report missing (docs/phase23/billing-source-reconciliation.md)');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('billing.source_reconciliation_doc', 'Could not check billing reconciliation doc: ' . $e->getMessage());
        }

        try {
            if (is_file(base_path('docs/architecture/billing-source-of-truth.md'))) {
                $this->pass('billing.source_of_truth_adr', 'Billing source-of-truth ADR present (docs/architecture/billing-source-of-truth.md)');
            } else {
                $this->warnCheck('billing.source_of_truth_adr', 'Billing source-of-truth ADR missing (docs/architecture/billing-source-of-truth.md)');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('billing.source_of_truth_adr', 'Could not check billing source-of-truth ADR: ' . $e->getMessage());
        }

        // 10. GlassBilling foundation (Phase 24)

        // 10a. Billing foundation tables
        try {
            $required = [
                'billing_customers', 'billing_products', 'billing_plans', 'billing_subscriptions',
                'billing_invoices', 'billing_payments', 'billing_payment_methods', 'billing_events',
            ];
            $missing = array_values(array_filter($required, fn ($t) => ! Schema::hasTable($t)));
            if (empty($missing)) {
                $this->pass('billing.tables', count($required) . ' billing foundation tables present');
            } else {
                $this->checkFail('billing.tables', 'Missing billing tables: ' . implode(', ', $missing) . ' — run: php artisan migrate');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->checkFail('billing.tables', 'Could not check billing tables: ' . $e->getMessage());
            $allPassed = false;
        }

        // 10b. Billing models loadable
        try {
            $models = [
                \App\Models\BillingCustomer::class, \App\Models\BillingProduct::class,
                \App\Models\BillingPlan::class, \App\Models\BillingSubscription::class,
                \App\Models\BillingInvoice::class, \App\Models\BillingPayment::class,
                \App\Models\BillingPaymentMethod::class, \App\Models\BillingEvent::class,
            ];
            $missing = array_values(array_filter($models, fn ($m) => ! class_exists($m)));
            if (empty($missing)) {
                $this->pass('billing.models', count($models) . ' billing Eloquent models loadable');
            } else {
                $names = implode(', ', array_map(fn ($m) => class_basename($m), $missing));
                $this->checkFail('billing.models', "Missing billing models: {$names}");
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->warnCheck('billing.models', 'Could not check billing models: ' . $e->getMessage());
        }

        // 10c. Stripe configuration — presence only, NEVER prints key values.
        try {
            $stripe = app(\App\Services\Billing\StripeBillingClient::class);
            if (! $stripe->isEnabled()) {
                $this->warnCheck('billing.stripe_config', 'Billing not enabled (set GLASSBILLING_ENABLED=true + GLASSBILLING_MODE=stripe to activate)');
            } elseif ($stripe->isConfigured()) {
                $this->pass('billing.stripe_config', "Stripe configured (mode={$stripe->mode()}, secret key present)");
            } elseif ($strict) {
                $this->checkFail('billing.stripe_config', 'Billing enabled but Stripe is not configured — set STRIPE_SECRET_KEY (strict mode)');
                $allPassed = false;
            } else {
                $this->warnCheck('billing.stripe_config', 'Billing enabled but STRIPE_SECRET_KEY is not set (acceptable in dev/staging)');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('billing.stripe_config', 'Could not check Stripe config: ' . $e->getMessage());
        }

        // 10d. Stripe webhook secret — presence only, NEVER prints the value.
        try {
            $stripe = app(\App\Services\Billing\StripeBillingClient::class);
            if (! $stripe->isEnabled()) {
                $this->warnCheck('billing.webhook_secret', 'Billing not enabled — STRIPE_WEBHOOK_SECRET not yet required');
            } elseif ($stripe->hasWebhookSecret()) {
                $this->pass('billing.webhook_secret', 'STRIPE_WEBHOOK_SECRET is configured');
            } elseif ($strict) {
                $this->checkFail('billing.webhook_secret', 'Billing enabled but STRIPE_WEBHOOK_SECRET is not set (strict mode)');
                $allPassed = false;
            } else {
                $this->warnCheck('billing.webhook_secret', 'STRIPE_WEBHOOK_SECRET is not set (acceptable until webhook intake is enabled)');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('billing.webhook_secret', 'Could not check webhook secret: ' . $e->getMessage());
        }

        // 11. Billing service entitlements (Phase 25)

        // 11a. Entitlements table
        try {
            if (Schema::hasTable('billing_service_entitlements')) {
                $this->pass('billing.entitlements_table', 'billing_service_entitlements table present');
            } else {
                $this->checkFail('billing.entitlements_table', 'billing_service_entitlements table missing — run: php artisan migrate');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->checkFail('billing.entitlements_table', 'Could not check entitlements table: ' . $e->getMessage());
            $allPassed = false;
        }

        // 11b. Entitlement events table
        try {
            if (Schema::hasTable('billing_service_entitlement_events')) {
                $this->pass('billing.entitlement_events_table', 'billing_service_entitlement_events table present');
            } else {
                $this->checkFail('billing.entitlement_events_table', 'billing_service_entitlement_events table missing — run: php artisan migrate');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->checkFail('billing.entitlement_events_table', 'Could not check entitlement events table: ' . $e->getMessage());
            $allPassed = false;
        }

        // 11c. Entitlement models loadable
        try {
            $ok = class_exists(\App\Models\BillingServiceEntitlement::class)
                && class_exists(\App\Models\BillingServiceEntitlementEvent::class);
            if ($ok) {
                $this->pass('billing.entitlement_models', 'Entitlement models loadable (BillingServiceEntitlement + Event)');
            } else {
                $this->checkFail('billing.entitlement_models', 'Entitlement model class(es) not found');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->warnCheck('billing.entitlement_models', 'Could not check entitlement models: ' . $e->getMessage());
        }

        // 11d. Lifecycle service resolvable
        try {
            app(\App\Services\Billing\BillingEntitlementService::class);
            $this->pass('billing.entitlement_service', 'BillingEntitlementService is resolvable from container');
        } catch (\Throwable $e) {
            $this->checkFail('billing.entitlement_service', 'BillingEntitlementService not resolvable: ' . $e->getMessage());
            $allPassed = false;
        }

        // 12. Provisioning request engine (Phase 26)

        // 12a. Requests table
        try {
            if (Schema::hasTable('provisioning_requests')) {
                $this->pass('provisioning.requests_table', 'provisioning_requests table present');
            } else {
                $this->checkFail('provisioning.requests_table', 'provisioning_requests table missing — run: php artisan migrate');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->checkFail('provisioning.requests_table', 'Could not check provisioning_requests table: ' . $e->getMessage());
            $allPassed = false;
        }

        // 12b. Request events table
        try {
            if (Schema::hasTable('provisioning_request_events')) {
                $this->pass('provisioning.request_events_table', 'provisioning_request_events table present');
            } else {
                $this->checkFail('provisioning.request_events_table', 'provisioning_request_events table missing — run: php artisan migrate');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->checkFail('provisioning.request_events_table', 'Could not check provisioning_request_events table: ' . $e->getMessage());
            $allPassed = false;
        }

        // 12c. Models loadable
        try {
            $ok = class_exists(\App\Models\ProvisioningRequest::class)
                && class_exists(\App\Models\ProvisioningRequestEvent::class);
            if ($ok) {
                $this->pass('provisioning.models', 'Provisioning models loadable (ProvisioningRequest + Event)');
            } else {
                $this->checkFail('provisioning.models', 'Provisioning model class(es) not found');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->warnCheck('provisioning.models', 'Could not check provisioning models: ' . $e->getMessage());
        }

        // 12d. Request service resolvable
        try {
            app(\App\Services\Provisioning\ProvisioningRequestService::class);
            $this->pass('provisioning.service', 'ProvisioningRequestService is resolvable from container');
        } catch (\Throwable $e) {
            $this->checkFail('provisioning.service', 'ProvisioningRequestService not resolvable: ' . $e->getMessage());
            $allPassed = false;
        }

        // 12e. Driver registry (metadata only — nothing executes)
        try {
            $drivers = array_keys((array) config('provisioning.drivers', []));
            if (! empty($drivers)) {
                $this->pass('provisioning.driver_registry', count($drivers) . ' driver(s) registered: ' . implode(', ', $drivers));
            } else {
                $this->warnCheck('provisioning.driver_registry', 'No provisioning drivers configured in config/provisioning.php');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('provisioning.driver_registry', 'Could not check driver registry: ' . $e->getMessage());
        }

        // 13. Stripe Checkout + verified webhook intake (Phase 27)

        // 13a. Checkout sessions table
        try {
            if (Schema::hasTable('billing_checkout_sessions')) {
                $this->pass('billing.checkout_sessions_table', 'billing_checkout_sessions table present');
            } else {
                $this->checkFail('billing.checkout_sessions_table', 'billing_checkout_sessions table missing — run: php artisan migrate');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->checkFail('billing.checkout_sessions_table', 'Could not check checkout sessions table: ' . $e->getMessage());
            $allPassed = false;
        }

        // 13b. Checkout session model loadable
        try {
            if (class_exists(\App\Models\BillingCheckoutSession::class)) {
                $this->pass('billing.checkout_model', 'BillingCheckoutSession model loadable');
            } else {
                $this->checkFail('billing.checkout_model', 'BillingCheckoutSession model class not found');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->warnCheck('billing.checkout_model', 'Could not check checkout model: ' . $e->getMessage());
        }

        // 13c. Checkout service resolvable
        try {
            app(\App\Services\Billing\StripeCheckoutService::class);
            $this->pass('billing.checkout_service', 'StripeCheckoutService is resolvable from container');
        } catch (\Throwable $e) {
            $this->checkFail('billing.checkout_service', 'StripeCheckoutService not resolvable: ' . $e->getMessage());
            $allPassed = false;
        }

        // 13d. Webhook intake route registered
        try {
            $routes      = app('router')->getRoutes();
            $webhookRoute = $routes->getByName('api.billing.stripe.webhook');
            if ($webhookRoute !== null) {
                $this->pass('billing.stripe_webhook_route', 'api.billing.stripe.webhook route registered at POST /api/billing/stripe/webhook');
            } else {
                $this->checkFail('billing.stripe_webhook_route', 'api.billing.stripe.webhook route not found — check routes/api.php');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->warnCheck('billing.stripe_webhook_route', 'Could not check Stripe webhook route: ' . $e->getMessage());
        }

        // 13e. Webhook intake service resolvable
        try {
            app(\App\Services\Billing\StripeWebhookService::class);
            $this->pass('billing.stripe_webhook_service', 'StripeWebhookService is resolvable from container');
        } catch (\Throwable $e) {
            $this->checkFail('billing.stripe_webhook_service', 'StripeWebhookService not resolvable: ' . $e->getMessage());
            $allPassed = false;
        }

        // 13f. Checkout config — presence only, NEVER prints key values.
        // Warn while disabled/dev; strict-fail only when checkout is enabled but
        // Stripe is not actually configured (it would fail at runtime).
        try {
            $checkoutEnabled = (bool) config('billing.checkout.enabled', false);
            $stripe          = app(\App\Services\Billing\StripeBillingClient::class);

            if (! $checkoutEnabled) {
                $this->warnCheck('billing.stripe_checkout_config', 'Customer checkout disabled (set GLASSBILLING_CHECKOUT_ENABLED=true to enable)');
            } elseif ($stripe->isConfigured()) {
                $mode = (string) config('billing.checkout.mode', 'subscription');
                $this->pass('billing.stripe_checkout_config', "Customer checkout enabled (mode={$mode}, Stripe configured)");
            } elseif ($strict) {
                $this->checkFail('billing.stripe_checkout_config', 'Checkout enabled but Stripe is not configured — set GLASSBILLING_ENABLED=true, GLASSBILLING_MODE=stripe, STRIPE_SECRET_KEY (strict mode)');
                $allPassed = false;
            } else {
                $this->warnCheck('billing.stripe_checkout_config', 'Checkout enabled but Stripe is not configured — checkout will fail safely until STRIPE_SECRET_KEY is set');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('billing.stripe_checkout_config', 'Could not check checkout config: ' . $e->getMessage());
        }

        // 13g. Webhook intake config — presence only, NEVER prints the secret.
        // Fail closed under strict: enabled intake with no signing secret cannot
        // verify signatures and would reject everything.
        try {
            $webhooksEnabled = (bool) config('billing.webhooks.enabled', false);
            $stripe          = app(\App\Services\Billing\StripeBillingClient::class);

            if (! $webhooksEnabled) {
                $this->warnCheck('billing.stripe_webhook_config', 'Webhook intake disabled (set GLASSBILLING_WEBHOOKS_ENABLED=true to enable; endpoint returns 404 while disabled)');
            } elseif ($stripe->hasWebhookSecret()) {
                $tolerance = (int) config('billing.webhooks.tolerance', 300);
                $this->pass('billing.stripe_webhook_config', "Webhook intake enabled (signature verification on, tolerance={$tolerance}s)");
            } elseif ($strict) {
                $this->checkFail('billing.stripe_webhook_config', 'Webhook intake enabled but STRIPE_WEBHOOK_SECRET is not set — signatures cannot be verified; intake fails closed (strict mode)');
                $allPassed = false;
            } else {
                $this->warnCheck('billing.stripe_webhook_config', 'Webhook intake enabled but STRIPE_WEBHOOK_SECRET is not set — endpoint fails closed (HTTP 500) until the secret is configured');
            }
        } catch (\Throwable $e) {
            $this->warnCheck('billing.stripe_webhook_config', 'Could not check webhook intake config: ' . $e->getMessage());
        }

        // 14. Customer billing self-service (Phase 28)

        // 14a. Change requests table
        try {
            if (Schema::hasTable('billing_change_requests')) {
                $this->pass('billing.change_requests_table', 'billing_change_requests table present');
            } else {
                $this->checkFail('billing.change_requests_table', 'billing_change_requests table missing — run: php artisan migrate');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->checkFail('billing.change_requests_table', 'Could not check change requests table: ' . $e->getMessage());
            $allPassed = false;
        }

        // 14b. Change request model loadable
        try {
            if (class_exists(\App\Models\BillingChangeRequest::class)) {
                $this->pass('billing.change_request_model', 'BillingChangeRequest model loadable');
            } else {
                $this->checkFail('billing.change_request_model', 'BillingChangeRequest model class not found');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->warnCheck('billing.change_request_model', 'Could not check change request model: ' . $e->getMessage());
        }

        // 14c. Self-service controller + scope service resolvable
        try {
            app(\App\Http\Controllers\Portal\BillingController::class);
            app(\App\Services\Billing\BillingSelfServiceService::class);
            $this->pass('billing.self_service_controller', 'Portal billing controller + BillingSelfServiceService resolvable from container');
        } catch (\Throwable $e) {
            $this->checkFail('billing.self_service_controller', 'Self-service controller/service not resolvable: ' . $e->getMessage());
            $allPassed = false;
        }

        // 14d. Change request workflow service resolvable
        try {
            app(\App\Services\Billing\BillingChangeRequestService::class);
            $this->pass('billing.change_request_workflow', 'BillingChangeRequestService is resolvable from container');
        } catch (\Throwable $e) {
            $this->checkFail('billing.change_request_workflow', 'BillingChangeRequestService not resolvable: ' . $e->getMessage());
            $allPassed = false;
        }

        // 14e. Self-service routes registered
        try {
            $routes   = app('router')->getRoutes();
            $required = [
                'portal.billing.dashboard',
                'portal.billing.subscriptions',
                'portal.billing.invoices',
                'portal.billing.payments',
                'portal.billing.checkout-sessions',
                'portal.billing.change-requests',
                'portal.billing.change-requests.store',
                'admin.billing.change-requests',
            ];
            $missing = array_values(array_filter($required, fn ($name) => $routes->getByName($name) === null));
            if (empty($missing)) {
                $this->pass('billing.self_service_routes', count($required) . ' customer/admin billing self-service routes registered');
            } else {
                $this->checkFail('billing.self_service_routes', 'Missing billing self-service routes: ' . implode(', ', $missing) . ' — check routes/web.php');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->warnCheck('billing.self_service_routes', 'Could not check self-service routes: ' . $e->getMessage());
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
