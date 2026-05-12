<?php

use App\Http\Controllers\Admin\CustomersController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ModulesController;
use App\Http\Controllers\Admin\ProvisioningController;
use App\Http\Controllers\Admin\ServicesController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Portal\DashboardController as PortalDashboardController;
use App\Http\Controllers\Portal\ServicesController as PortalServicesController;
use App\Http\Controllers\Portal\SupportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('welcome');
})->name('home');

/*
|--------------------------------------------------------------------------
| Auth routes
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Staff / admin routes  (role: owner, admin, staff, support)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:owner,admin,staff,support'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/',             [DashboardController::class,   'index'])->name('dashboard');
        Route::get('/modules',      [ModulesController::class,     'index'])->name('modules');
        Route::get('/services',     [ServicesController::class,    'index'])->name('services');
        Route::get('/provisioning', [ProvisioningController::class,'index'])->name('provisioning');
        Route::get('/customers',    [CustomersController::class,   'index'])->name('customers');
    });

/*
|--------------------------------------------------------------------------
| Customer portal routes  (role: customer)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:customer'])
    ->prefix('portal')
    ->name('portal.')
    ->group(function () {
        Route::get('/',         [PortalDashboardController::class, 'index'])->name('dashboard');
        Route::get('/services', [PortalServicesController::class,  'index'])->name('services');
        Route::get('/support',  [SupportController::class,         'index'])->name('support');
    });
