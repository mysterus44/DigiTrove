<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Durcissement pré-production — H2.1 et H2.5
|--------------------------------------------------------------------------
|
| Two guarantees, both measured on the real routing stack: the baseline headers
| exist and NEVER overwrite a stricter one a controller already set, and the
| webhook ingress is throttled like every other public write in the repository.
|
| The database harness is required because two of these cases exercise real
| routes — the storefront home page, and a 404 that must reach the redirect
| middleware before the headers are applied.
|
*/

/** Run the middleware over a response, as the kernel would. */
function hardeningThrough(Request $request, array $existingHeaders = [])
{
    $response = response('body', 200, $existingHeaders);

    return (new SecurityHeaders)->handle($request, fn () => $response);
}

/*
|--------------------------------------------------------------------------
| H2.1 — baseline headers
|--------------------------------------------------------------------------
*/

it('sets the baseline headers on a storefront page', function (): void {
    $response = $this->get('/');

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toContain("form-action 'self'")
        ->toContain("base-uri 'self'");
});

it('gives the admin panel the eval it structurally needs, and the storefront nothing extra', function (): void {
    // MEASURED IN A REAL BROWSER, not deduced. Filament drives its UI through Alpine, which
    // compiles every x-data/x-show/x-bind expression with `new Function`. Under a policy
    // without 'unsafe-eval' the panel is not degraded but DEAD — 20 EvalErrors, password
    // field uninitialised, submit button unbound, modals inert — while the HTML is served
    // perfectly and every assertion in this suite still passes. No test can see that; only
    // a browser can. Verified afterwards with 11/11 Alpine components initialised and the
    // reveal-password toggle actually flipping the input from `password` to `text`.
    //
    // The relaxation stops at the admin path: the storefront is what an anonymous visitor
    // reaches, and it must never inherit it.
    $admin = $this->get('/admin/login')->headers->get('Content-Security-Policy');
    $public = $this->get('/')->headers->get('Content-Security-Policy');

    expect($admin)->toContain("'unsafe-eval'")
        ->and($public)->not->toContain("'unsafe-eval'")
        // Everything else stays identical between the two.
        ->and($public)->toContain("frame-ancestors 'none'")
        ->and($admin)->toContain("frame-ancestors 'none'");
});

it('never allows an external origin outside local', function (): void {
    // In production `@vite` emits built assets from the same origin, so 'self' covers them.
    // The dev-server allowance exists only so local HMR is not silently broken.
    expect(app()->environment('local'))->toBeFalse()
        ->and($this->get('/')->headers->get('Content-Security-Policy'))
        ->not->toContain('5173');
});

it('ships a template that defaults to safe, not to convenient', function (): void {
    // `APP_DEBUG=true` in production is the classic configuration leak: full stack traces,
    // environment variables and SQL on every error page. The template must not be the
    // reason someone deploys with it on — same rule as `MAIL_HOST` being left blank.
    expect(file_get_contents(base_path('.env.example')))
        ->toMatch('/(?m)^APP_DEBUG=false$/')
        ->not->toMatch('/(?m)^APP_DEBUG=true$/');
});

it('sets them on a 404 too, since the redirect middleware runs first', function (): void {
    $this->get('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('NEVER overwrites a stricter policy a controller already set', function (): void {
    // The download landing, cart resume and CRM export pages ship a nonce-based
    // `default-src 'none'`. A middleware that overwrote them would silently downgrade the
    // most sensitive pages in the application to the loosest policy in it.
    $strict = "default-src 'none'; script-src 'nonce-abc'; frame-ancestors 'none'";

    $response = hardeningThrough(Request::create('/downloads/x'), [
        'Content-Security-Policy' => $strict,
        'Referrer-Policy' => 'no-referrer',
    ]);

    expect($response->headers->get('Content-Security-Policy'))->toBe($strict)
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        // The headers it did NOT already carry are still added.
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY');
});

it('does not announce HSTS over plain http', function (): void {
    $response = hardeningThrough(Request::create('http://digitrove.test/'));

    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('does not announce HSTS in local or testing even over https', function (): void {
    // Announced from a dev machine it would make http://localhost unreachable for a year.
    $response = hardeningThrough(Request::create('https://digitrove.test/'));

    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| H2.5 — webhook ingress is throttled
|--------------------------------------------------------------------------
*/

it('throttles every provider webhook route', function (string $uri, string $method): void {
    $route = Route::getRoutes()->getRoutes();

    $matched = collect($route)->first(
        fn ($r): bool => $r->uri() === $uri && in_array($method, $r->methods(), true),
    );

    expect($matched)->not->toBeNull()
        ->and($matched->gatherMiddleware())->toContain('throttle:payment-webhook');
})->with([
    ['api/webhooks/payments/cinetpay', 'GET'],
    ['api/webhooks/payments/cinetpay', 'POST'],
    ['api/webhooks/payments/geniuspay', 'GET'],
    ['api/webhooks/payments/geniuspay', 'POST'],
]);

it('keeps the ceiling high enough that a provider burst is never dropped', function (): void {
    // A dropped payment notification is far worse than absorbing some junk, so this limit
    // bounds abuse rather than shaping normal traffic. If someone lowers it, they should
    // have to change this number deliberately.
    $limiter = app(RateLimiter::class)->limiter('payment-webhook');

    expect($limiter)->not->toBeNull()
        ->and($limiter(Request::create('/'))->maxAttempts)->toBe(120);
});
