<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\ForcePasswordChange;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        // A reverse proxy/tunnel in front of the app sends X-Forwarded-* headers,
        // which must be trusted for HTTPS URL generation and secure cookies.
        // The local Cloudflare tunnel connector connects from loopback, and a
        // direct deployment (Azure nginx + Let's Encrypt) never does, so only
        // loopback is trusted by default. Use TRUSTED_PROXIES (comma separated)
        // to extend this for other proxies.
        $trustedProxies = ['127.0.0.1', '::1'];
        if ($extra = env('TRUSTED_PROXIES')) {
            $trustedProxies = array_merge($trustedProxies, explode(',', $extra));
        }
        $middleware->trustProxies(at: $trustedProxies);

        $middleware->appendToGroup('web', ForcePasswordChange::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
