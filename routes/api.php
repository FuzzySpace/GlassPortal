<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| GlassPortal API Routes
|--------------------------------------------------------------------------
|
| Phase 2 — stubs only. Live connectors are Phase 3+.
|
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

/*
|--------------------------------------------------------------------------
| Module connector stubs (Phase 3+)
|--------------------------------------------------------------------------
|
| These routes will proxy to their respective Glasshouse modules once
| connectors are implemented. For now they return 501 Not Implemented.
|
*/

$modules = [
    'glassbilling' => 'GlassBilling',
    'glasspanel'   => 'GlassPanel',
    'aria'         => 'Aria',
    'proxmox'      => 'Proxmox',
    'powerdns'     => 'PowerDNS',
    'mailcow'      => 'Mailcow',
];

foreach ($modules as $slug => $label) {
    Route::get("/connectors/{$slug}/health", function () use ($label) {
        return response()->json([
            'module'  => $label,
            'status'  => 'not_implemented',
            'message' => "{$label} connector is not yet wired. Phase 3+.",
        ], 501);
    });
}
