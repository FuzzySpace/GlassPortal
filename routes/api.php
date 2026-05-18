<?php

use App\Http\Controllers\Api\BackChannelRedeemController;
use App\Http\Controllers\Api\Connectors\SionaHealthController;
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
| SIONA connector health (Phase 18)
|--------------------------------------------------------------------------
|
| Returns safe health metadata for the SIONA AI sales module connector.
| Always HTTP 200 — use the "status" field in the body to detect health state.
|
*/

Route::get('/connectors/siona/health', [SionaHealthController::class, 'index'])
    ->name('api.connectors.siona.health');

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
