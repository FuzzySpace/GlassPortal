<?php

use App\Http\Controllers\Api\BackChannelRedeemController;
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
| Back-Channel SSO Redemption — Phase 11
|--------------------------------------------------------------------------
|
| Server-to-server endpoint for modules to redeem one-time launch codes.
| The module receives a launch_code via POST form from the browser and
| exchanges it here for identity data. Rate-limited to 30 req/min per IP.
|
*/

Route::post('/sso/backchannel/redeem/{moduleKey}', [BackChannelRedeemController::class, 'redeem'])
    ->middleware(['throttle:30,1', 'backchannel.mtls'])
    ->name('api.sso.backchannel.redeem');

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
