<?php

namespace App\Providers;

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
    }

    public function boot(): void
    {
        //
    }
}
