<?php

namespace App\Providers;

use App\Services\Billing\BillingChangeRequestService;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\BillingSelfServiceService;
use App\Services\Billing\StripeBillingClient;
use App\Services\Billing\StripeCheckoutService;
use App\Services\Billing\StripeWebhookService;
use App\Services\Pilot\PilotReadinessService;
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

        // Phase 28 — customer billing self-service.
        $this->app->singleton(BillingSelfServiceService::class, fn () => new BillingSelfServiceService());
        $this->app->singleton(
            BillingChangeRequestService::class,
            fn ($app) => new BillingChangeRequestService($app->make(BillingSelfServiceService::class)),
        );

        // Phase 29 — pilot/product-test readiness.
        $this->app->singleton(PilotReadinessService::class, fn () => new PilotReadinessService());
    }

    public function boot(): void
    {
        //
    }
}
