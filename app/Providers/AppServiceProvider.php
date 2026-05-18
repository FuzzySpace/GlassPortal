<?php

namespace App\Providers;

use App\Services\Siona\SionaConnectorClient;
use App\Services\Sso\SigningKeyResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SigningKeyResolver::class, fn () => new SigningKeyResolver());
        $this->app->singleton(SionaConnectorClient::class, fn () => new SionaConnectorClient());
    }

    public function boot(): void
    {
        //
    }
}
