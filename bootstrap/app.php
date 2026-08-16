<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role'                 => \App\Http\Middleware\EnsureUserHasRole::class,
            'signed.launch'        => \App\Http\Middleware\VerifySignedModuleLaunch::class,
            'verify.signed.launch' => \App\Http\Middleware\VerifySignedModuleLaunch::class,
            'backchannel.mtls'     => \App\Http\Middleware\VerifyBackChannelMtls::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
        // Phase 29D+: Exception monitoring — Sentry integration when configured.
        if (class_exists(\Sentry\Laravel\Integration::class)) {
            $exceptions->reportable(function (\Throwable $e) {
                \Sentry\Laravel\Integration::captureUnhandledException($e);
            });
        }

        // Dedicated billing exception logging for production debugging.
        $exceptions->reportable(function (\Throwable $e) {
            if (str_contains(get_class($e), 'Billing') || str_contains(get_class($e), 'Stripe')) {
                \Illuminate\Support\Facades\Log::channel('billing')->error(
                    '[BillingException] ' . $e->getMessage(),
                    ['exception' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine()]
                );
            }
        })->stop();
    })->create();
