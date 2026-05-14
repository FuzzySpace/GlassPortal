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

        // 7. Module config loads
        try {
            $modules = config('glasshouse.modules', null);
            if (is_array($modules)) {
                $this->pass('config.modules', count($modules) . ' module(s) registered in config/glasshouse.php');
            } else {
                $this->checkFail('config.modules', 'config/glasshouse.php did not return expected modules array');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->checkFail('config.modules', 'Error loading module config: ' . $e->getMessage());
            $allPassed = false;
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
