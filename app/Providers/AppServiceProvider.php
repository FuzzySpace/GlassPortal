<?php

namespace App\Providers;

use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\StripeBillingClient;
use App\Services\Billing\StripeCheckoutService;
use App\Services\Billing\StripeWebhookService;
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
        $this->app->singleton(
            StripeCheckoutService::class,
            fn ($app) => new StripeCheckoutService($app->make(StripeBillingClient::class)),
        );
        $this->app->singleton(BillingEntitlementService::class, fn () => new BillingEntitlementService());
        $this->app->singleton(
            ProvisioningRequestService::class,
            fn ($app) => new ProvisioningRequestService($app->make(BillingEntitlementService::class)),
        );
        $this->app->singleton(
            StripeWebhookService::class,
            fn ($app) => new StripeWebhookService(
                $app->make(BillingEntitlementService::class),
                $app->make(ProvisioningRequestService::class),
            ),
        );
    }

    public function boot(): void
    {
        //
    }
}
