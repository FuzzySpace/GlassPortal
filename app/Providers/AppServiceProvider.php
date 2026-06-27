<?php

namespace App\Providers;

use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\StripeBillingClient;
use App\Services\Provisioning\ProvisioningRequestService;
use App\Services\Siona\SionaConnectorClient;
use App\Services\Siona\SionaTenantProvisioningService;
use App\Services\Sso\SigningKeyResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SigningKeyResolver::class, fn () => new SigningKeyResolver());
        $this->app->singleton(SionaConnectorClient::class, fn () => new SionaConnectorClient());
        $this->app->singleton(
            SionaTenantProvisioningService::class,
            fn ($app) => new SionaTenantProvisioningService($app->make(SionaConnectorClient::class)),
        );
        $this->app->singleton(StripeBillingClient::class, fn () => new StripeBillingClient());
        $this->app->singleton(BillingEntitlementService::class, fn () => new BillingEntitlementService());
        $this->app->singleton(
            ProvisioningRequestService::class,
            fn ($app) => new ProvisioningRequestService($app->make(BillingEntitlementService::class)),
        );
    }

    public function boot(): void
    {
        //
    }
}
