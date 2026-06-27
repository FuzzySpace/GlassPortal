<?php

use App\Http\Controllers\Admin\BillingApprovalsController;
use App\Http\Controllers\Admin\CustomersController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ModuleLinksController;
use App\Http\Controllers\Admin\ModulesController;
use App\Http\Controllers\Admin\ProvisioningController;
use App\Http\Controllers\Admin\ServicesController;
use App\Http\Controllers\Admin\SionaProvisioningController;
use App\Http\Controllers\Api\JwksController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Dev\SsoConsumeController;
use App\Http\Controllers\Portal\DashboardController as PortalDashboardController;
use App\Http\Controllers\Portal\ModuleLaunchController;
use App\Http\Controllers\Portal\ModulesController as PortalModulesController;
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
| Well-known / JWKS endpoint — Phase 15
|--------------------------------------------------------------------------
|
| Publishes safe key metadata (no secrets) for downstream modules.
| Placed in web.php (not api.php) because api.php adds an /api/ prefix.
| No authentication — key metadata is intentionally public.
|
*/

Route::get('/.well-known/glassportal/jwks.json', [JwksController::class, 'index'])
    ->name('glassportal.jwks');

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
        Route::get('/',                          [DashboardController::class,    'index'])->name('dashboard');
        Route::get('/modules',                   [ModulesController::class,      'index'])->name('modules');

        // Module link CRUD (except show — detail not needed; index covers it)
        Route::get('/module-links',              [ModuleLinksController::class,  'index'])->name('module-links');
        Route::get('/module-links/create',       [ModuleLinksController::class,  'create'])->name('module-links.create');
        Route::post('/module-links',             [ModuleLinksController::class,  'store'])->name('module-links.store');
        Route::get('/module-links/{moduleLink}/edit',   [ModuleLinksController::class, 'edit'])->name('module-links.edit');
        Route::patch('/module-links/{moduleLink}',      [ModuleLinksController::class, 'update'])->name('module-links.update');
        Route::delete('/module-links/{moduleLink}',     [ModuleLinksController::class, 'destroy'])->name('module-links.destroy');

        Route::get('/services',                  [ServicesController::class,     'index'])->name('services');
        Route::get('/services/{id}',             [ServicesController::class,     'show'])->name('services.show');
        Route::get('/provisioning',              [ProvisioningController::class, 'index'])->name('provisioning');
        Route::get('/provisioning/{id}',         [ProvisioningController::class, 'show'])->name('provisioning.show');
        Route::get('/customers',                 [CustomersController::class,    'index'])->name('customers');
        Route::get('/customers/{id}',            [CustomersController::class,    'show'])->name('customers.show');

        // Phase 20: SIONA tenant provisioning — admin-only (owner/admin).
        // The stacked role middleware narrows the surrounding staff group to
        // owner/admin only (intersection), so staff/support get a 403.
        Route::post('/customers/{organization}/siona/provision', [SionaProvisioningController::class, 'store'])
            ->middleware('role:owner,admin')
            ->name('customers.siona.provision');

        Route::get('/billing-approvals',         [BillingApprovalsController::class, 'index'])->name('billing-approvals');
        Route::get('/billing-approvals/{id}',    [BillingApprovalsController::class, 'show'])->name('billing-approvals.show');
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
        Route::get('/modules',  [PortalModulesController::class,   'index'])->name('modules');
        Route::get('/support',  [SupportController::class,         'index'])->name('support');

        // Audited launch — GET so the browser can open in a new tab if needed
        Route::get('/modules/{moduleLink}/launch', [ModuleLaunchController::class, 'launch'])->name('module.launch');
    });

/*
|--------------------------------------------------------------------------
| Dev / test SSO consumer  (local + testing environments only)
|--------------------------------------------------------------------------
|
| Simulates a downstream module receiving a signed launch POST.
| Verifies the SLP token and returns the decoded identity context as JSON.
| Guards: not registered in production.
|
*/

if (app()->environment('local', 'testing') || config('glasshouse_sso.enable_dev_sso_consume', false)) {
    Route::post('/_dev/sso/consume/{moduleKey}', [SsoConsumeController::class, 'consume'])
        ->middleware('signed.launch')
        ->name('dev.sso.consume');
}
