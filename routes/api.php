<?php

use App\Services\GlassBilling\GlassBillingClient;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| GlassPortal API Routes
|--------------------------------------------------------------------------
*/

Route::get('/health', function () {
    return response()->json([
        'status'  => 'ok',
        'service' => 'GlassPortal',
        'version' => config('app.version', 'dev'),
        'env'     => app()->environment(),
        'time'    => now()->toIso8601String(),
    ]);
});

Route::get('/glassbilling/health', function (GlassBillingClient $client) {
    $health = $client->health();

    return response()->json([
        'module' => 'GlassBilling',
        ...$health,
    ], $health['status'] === 'online' ? 200 : 503);
});

/*
|--------------------------------------------------------------------------
| Other module health stubs (Phase 4+)
|--------------------------------------------------------------------------
*/

$stubModules = ['glasspanel', 'aria', 'proxmox', 'powerdns', 'mailcow', 'pterodactyl'];

foreach ($stubModules as $slug) {
    Route::get("/connectors/{$slug}/health", function () use ($slug) {
        $module = config("glasshouse.modules.{$slug}", []);

        return response()->json([
            'module'  => $module['display_name'] ?? $slug,
            'status'  => 'stub',
            'message' => 'Connector not yet implemented. Phase 4+.',
        ], 501);
    });
}
