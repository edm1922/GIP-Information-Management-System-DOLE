<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\ForcePasswordChange;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
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

        // Enable Sanctum SPA stateful authentication for API routes from configured
        // stateful domains (first-party cookie/session auth for the Next.js frontend).
        $middleware->statefulApi();

        // SEC-06: a deactivated account must lose access immediately, even when
        // it still holds a valid session. Registered before the forced-password
        // middleware so inactive accounts are rejected first. Unauthenticated
        // requests (login, public registration, health check) pass through.
        $middleware->appendToGroup('web', EnsureAccountIsActive::class);
        $middleware->appendToGroup('api', EnsureAccountIsActive::class);

        $middleware->appendToGroup('web', ForcePasswordChange::class);
        $middleware->appendToGroup('api', ForcePasswordChange::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every `/api/*` request must get a JSON error (401/403/404/419/422/429/500)
        // regardless of the client's Accept header, so the SPA never follows a
        // redirect to the Blade login page.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
