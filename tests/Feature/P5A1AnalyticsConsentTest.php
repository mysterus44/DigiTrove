<?php

use App\Support\AnalyticsConfig;
use App\Support\AnalyticsConsent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Cookie;

function p5a1ResponseCookie($response, string $name): ?Cookie
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === $name) {
            return $cookie;
        }
    }

    return null;
}

it('round-trips an encrypted analytics cookie through the web middleware', function () {
    Route::middleware('web')->get('/_p5a1-cookie-probe', function (Request $request) {
        return response()->json(['value' => $request->cookie(AnalyticsConfig::CONSENT_COOKIE)]);
    });
    $value = AnalyticsConsent::encode(AnalyticsConsent::GRANTED);

    $this->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, $value)
        ->getJson('/_p5a1-cookie-probe')
        ->assertExactJson(['value' => $value]);
});

it('returns only the current versioned consent state', function () {
    config()->set('analytics.consent.version', 7);

    $this->getJson('/analytics/consent')
        ->assertOk()
        ->assertExactJson(['consent' => 'unset', 'version' => 7]);

    $this->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, AnalyticsConsent::encode(AnalyticsConsent::GRANTED))
        ->getJson('/analytics/consent')
        ->assertOk()
        ->assertExactJson(['consent' => 'granted', 'version' => 7])
        ->assertJsonMissing(['visitor_id'])
        ->assertJsonMissing(['session_id']);
});

it('treats malformed and obsolete consent cookies as unset', function (string $cookie) {
    config()->set('analytics.consent.version', 2);

    $this->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, $cookie)
        ->getJson('/analytics/consent')
        ->assertExactJson(['consent' => 'unset', 'version' => 2]);
})->with([
    'malformed' => ['not-json'],
    'obsolete' => [json_encode(['consent' => 'granted', 'version' => 1], JSON_THROW_ON_ERROR)],
    'unknown state' => [json_encode(['consent' => 'maybe', 'version' => 2], JSON_THROW_ON_ERROR)],
    'extra identity' => [json_encode(['consent' => 'granted', 'version' => 2, 'visitor' => 'x'], JSON_THROW_ON_ERROR)],
]);

it('sets an encrypted HttpOnly Strict first-party consent cookie', function () {
    config()->set('analytics.consent.version', 3);

    $response = $this->postJson('/analytics/consent', ['consent' => 'granted'])
        ->assertNoContent()
        ->assertCookie(AnalyticsConfig::CONSENT_COOKIE);
    $cookie = p5a1ResponseCookie($response->baseResponse, AnalyticsConfig::CONSENT_COOKIE);

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe(Cookie::SAMESITE_STRICT)
        ->and($cookie->isSecure())->toBeFalse()
        ->and($cookie->getValue())->not->toContain('granted')
        ->and($cookie->getValue())->not->toContain('"version":3');
});

it('sets Secure cookies outside local and testing environments', function () {
    config()->set('app.env', 'production');

    $response = $this->postJson('/analytics/consent', ['consent' => 'granted'])->assertNoContent();
    $cookie = p5a1ResponseCookie($response->baseResponse, AnalyticsConfig::CONSENT_COOKIE);

    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe(Cookie::SAMESITE_STRICT);
});

it('refusal and revocation expire only analytics identity cookies', function (string $method) {
    $this->withCookie('dt_commerce_visitor', 'commerce-must-survive');

    $response = $method === 'POST'
        ? $this->postJson('/analytics/consent', ['consent' => 'denied'])
        : $this->deleteJson('/analytics/consent');

    $response->assertNoContent()
        ->assertCookie(AnalyticsConfig::CONSENT_COOKIE)
        ->assertCookieExpired(AnalyticsConfig::VISITOR_COOKIE)
        ->assertCookieExpired(AnalyticsConfig::SESSION_COOKIE)
        ->assertCookieMissing('dt_commerce_visitor');
})->with(['explicit denial' => ['POST'], 'revocation' => ['DELETE']]);

it('rejects invalid consent states and cross-origin mutations', function () {
    $invalid = $this->postJson('/analytics/consent', ['consent' => 'implicit']);
    expect($invalid->getStatusCode())->toBe(422);

    $this->withHeader('Origin', 'https://attacker.example')
        ->postJson('/analytics/consent', ['consent' => 'granted'])
        ->assertForbidden();

    $this->withHeader('Sec-Fetch-Site', 'cross-site')
        ->deleteJson('/analytics/consent')
        ->assertForbidden();
});

it('keeps analytics routes in the real web middleware boundary', function () {
    foreach ([
        'analytics.consent.show',
        'analytics.consent.store',
        'analytics.consent.destroy',
        'analytics.events.store',
    ] as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain('web');
    }
});
