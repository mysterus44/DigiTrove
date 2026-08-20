<?php

use App\Http\Middleware\ApplyStoredRedirects;
use App\Http\Middleware\SecurityHeaders;
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
    ->withMiddleware(function (Middleware $middleware): void {
        // P7. GLOBAL, not appended to the `web` group — measured, not assumed: an unmatched
        // URI raises `NotFoundHttpException` DURING routing, so it never reaches a group
        // middleware at all. A redirect table registered on `web` would simply never fire.
        //
        // Global middleware wraps the router, so it sees the 404 the router produced. It
        // still only acts on a 404, which keeps the original guarantee intact: a stored
        // redirect can never shadow a real route, and a row for a path that later becomes a
        // genuine page silently stops mattering instead of breaking the site.
        $middleware->append(ApplyStoredRedirects::class);

        // H2.1. GLOBAL, and appended AFTER the redirect middleware so a 301 response
        // carries the headers too. It is a floor that never overwrites a header a
        // controller already set — the nonce-based `default-src 'none'` pages stay strict.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->is('analytics/*')
                || $request->expectsJson(),
        );
    })->create();
