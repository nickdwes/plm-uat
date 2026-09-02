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
        /*
         * TLS terminates at Caddy, so without this the application sees plain
         * http and generates http URLs. Signed URLs are the visible symptom:
         * the signature is computed over the whole URL, so one minted as https
         * never matches a request read as http, and every signed link 403s.
         *
         * It also decides what `$request->ip()` returns — untrusted, audit
         * entries record the proxy rather than the person.
         *
         * Scoped to private ranges rather than '*' because the container also
         * publishes port 9090 on the host, so it is not exclusively reachable
         * through Caddy.
         */
        $middleware->trustProxies(
            at: ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
        );

        $middleware->alias([
            'auth'        => \App\Http\Middleware\Authenticate::class,
            'verified'    => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'org.context' => \App\Http\Middleware\ResolveOrganizationContext::class,
        ]);

        // The coarse "every action" audit — one row per successful mutating
        // request that no model observer already detailed. Appended to the API
        // group so it wraps the whole request and can read the response status.
        $middleware->api(append: [
            \App\Http\Middleware\AuditRequests::class,
        ]);

        // Ensure org.context resolves the tenant before SubstituteBindings
        // tries to resolve route-model bindings (which use BelongsToOrganization scope).
        $middleware->priority([
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Auth\Middleware\Authenticate::class,
            \App\Http\Middleware\ResolveOrganizationContext::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*'));
    })->create();
