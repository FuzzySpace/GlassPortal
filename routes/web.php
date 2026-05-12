<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| GlassPortal Web Routes
|--------------------------------------------------------------------------
|
| Phase 2 — landing, portal stub, and admin stub.
| Authentication and real module views are Phase 3+.
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Staff portal placeholder (Phase 3+)
Route::get('/admin', function () {
    return view('placeholder', [
        'title'       => 'Staff Portal',
        'description' => 'The GlassPortal staff operations surface is under construction.',
        'phase'       => '3+',
    ]);
})->name('admin');

// Customer portal placeholder (Phase 3+)
Route::get('/portal', function () {
    return view('placeholder', [
        'title'       => 'Customer Portal',
        'description' => 'The GlassPortal customer self-service surface is under construction.',
        'phase'       => '3+',
    ]);
})->name('portal');
