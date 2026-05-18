<?php

namespace GlassHouse\PortalAuth\Laravel;

use GlassHouse\PortalAuth\Contracts\ReplayStoreInterface;
use GlassHouse\PortalAuth\Contracts\SecretResolverInterface;
use GlassHouse\PortalAuth\Replay\LaravelCacheReplayStore;
use GlassHouse\PortalAuth\Sso\ModuleSecretResolver;
use GlassHouse\PortalAuth\Sso\SignedLaunchTokenParser;
use GlassHouse\PortalAuth\Sso\SignedLaunchVerifier;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel service provider for the glasshouse/portal-auth SDK.
 *
 * Binds the SDK's core interfaces into the Laravel container so that
 * module applications using Laravel can receive them via dependency injection.
 *
 * Register in config/app.php providers array or via package discovery.
 *
 * Middleware registration (in bootstrap/app.php or Kernel.php):
 *   'portal.signed-launch' => \GlassHouse\PortalAuth\Laravel\Middleware\VerifySignedModuleLaunch::class,
 *   'portal.mtls'          => \GlassHouse\PortalAuth\Laravel\Middleware\VerifyBackChannelMtls::class,
 */
class PortalAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SecretResolverInterface::class, function () {
            $cfg = (array) config('glasshouse_sso', []);
            return ModuleSecretResolver::fromConfig($cfg);
        });

        $this->app->singleton(ReplayStoreInterface::class, function ($app) {
            return new LaravelCacheReplayStore($app->make('cache.store'));
        });

        $this->app->singleton(SignedLaunchVerifier::class, function ($app) {
            $cfg = (array) config('glasshouse_sso', []);
            return new SignedLaunchVerifier(
                secretResolver: $app->make(SecretResolverInterface::class),
                replayStore:    $app->make(ReplayStoreInterface::class),
                parser:         new SignedLaunchTokenParser(),
                issuer:         (string) ($cfg['issuer']            ?? 'glassportal'),
                clockSkew:      (int)    ($cfg['clock_skew_seconds'] ?? 30),
                replayTtl:      (int)    ($cfg['nonce_cache_ttl_seconds'] ?? 600),
            );
        });
    }

    public function boot(): void
    {
        // Nothing to publish in v1 — config is read from the host application.
    }
}
