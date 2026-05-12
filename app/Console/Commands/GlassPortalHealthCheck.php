<?php

namespace App\Console\Commands;

use App\Services\GlassBilling\GlassBillingClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GlassPortalHealthCheck extends Command
{
    protected $signature   = 'glassportal:healthcheck';
    protected $description = 'Run GlassPortal system health checks and report status';

    public function handle(GlassBillingClient $billing): int
    {
        $this->line('');
        $this->line('  <fg=blue>GlassPortal Health Check</>');
        $this->line('  ' . now()->toIso8601String());
        $this->line('');

        $allPassed = true;

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

        // 6. Module config loads
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

        // 7. GlassBilling connector
        try {
            $health = $billing->health();
            $status = $health['status'];
            $detail = $health['detail'] ?? '';

            if ($status === 'online') {
                $this->pass('glassbilling.health', "GlassBilling: online — {$detail}");
            } elseif ($status === 'unconfigured') {
                $this->warn_check('glassbilling.health', 'GlassBilling: not configured (set GLASSBILLING_API_URL + GLASSBILLING_API_TOKEN)');
            } else {
                $this->warn_check('glassbilling.health', "GlassBilling: {$status} — {$detail}");
            }
        } catch (\Throwable $e) {
            $this->warn_check('glassbilling.health', 'GlassBilling: exception — ' . $e->getMessage());
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

    protected function warn_check(string $check, string $message): void
    {
        $this->line("  <fg=yellow>!</> <fg=white>{$check}</>  {$message}");
    }
}
