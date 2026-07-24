<?php

use App\Models\Product;
use App\Models\User;
use App\Support\AnalyticsConfig;
use App\Support\AnalyticsConsent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

function enableP5A1SessionAnalytics(): void
{
    config()->set([
        'analytics.ingestion.enabled' => true,
        'analytics.consent.version' => 1,
        'analytics.session.ttl_minutes' => 30,
        'analytics.session.max_hours' => 24,
        'analytics.rate_limit.per_minute' => 30,
        'analytics.properties.max_bytes' => 4096,
        'analytics.ip_hash.key' => str_repeat('s', 32),
        'analytics.ip_hash.key_version' => 2,
    ]);
}

function p5a1InsertSession(string $sessionId, string $visitorId, $startedAt, $lastSeenAt): void
{
    DB::connection('pgsql_migration')->table('analytics_sessions')->insert([
        'id' => $sessionId,
        'visitor_id' => $visitorId,
        'user_id' => null,
        'started_at' => $startedAt,
        'last_seen_at' => $lastSeenAt,
        'ended_at' => null,
        'entry_path' => '/old',
        'exit_path' => '/old',
        'page_views' => 0,
        'utm_source' => null,
        'utm_medium' => null,
        'utm_campaign' => null,
        'device_type' => 'desktop',
        'country_code' => null,
        'created_at' => $startedAt,
        'updated_at' => $lastSeenAt,
    ]);
}

function p5a1SessionResponseCookie($response, string $name): ?Cookie
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === $name) {
            return $cookie;
        }
    }

    return null;
}

it('creates the first session and sets only encrypted UUID identity cookies', function () {
    enableP5A1SessionAnalytics();
    $visitorId = (string) Str::uuid();
    $path = '/session/'.Str::lower(Str::random(8));

    $response = $this
        ->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, AnalyticsConsent::encode(AnalyticsConsent::GRANTED))
        ->withCookie(AnalyticsConfig::VISITOR_COOKIE, $visitorId)
        ->postJson('/analytics/events', [
            'event_name' => 'page_view',
            'page_path' => $path,
            'properties' => [],
        ])
        ->assertNoContent();
    $event = DB::connection('pgsql_migration')->table('events')->where('page_path', $path)->first();
    $session = DB::connection('pgsql_migration')->table('analytics_sessions')->where('id', $event->session_id)->first();
    $sessionCookie = p5a1SessionResponseCookie($response->baseResponse, AnalyticsConfig::SESSION_COOKIE);

    expect($session)->not->toBeNull()
        ->and($session->visitor_id)->toBe($visitorId)
        ->and($session->page_views)->toBe(1)
        ->and($session->entry_path)->toBe($path)
        ->and($session->exit_path)->toBe($path)
        ->and($sessionCookie)->not->toBeNull()
        ->and($sessionCookie->isHttpOnly())->toBeTrue()
        ->and($sessionCookie->getSameSite())->toBe(Cookie::SAMESITE_STRICT)
        ->and($sessionCookie->getValue())->not->toContain($event->session_id);
});

it('reuses one session and increments page views only for page_view', function () {
    enableP5A1SessionAnalytics();
    $visitorId = (string) Str::uuid();
    $firstPath = '/reuse/'.Str::lower(Str::random(8));

    $this->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, AnalyticsConsent::encode(AnalyticsConsent::GRANTED))
        ->withCookie(AnalyticsConfig::VISITOR_COOKIE, $visitorId)
        ->postJson('/analytics/events', [
            'event_name' => 'page_view',
            'page_path' => $firstPath,
            'properties' => [],
        ])->assertNoContent();
    $sessionId = DB::connection('pgsql_migration')->table('events')->where('page_path', $firstPath)->value('session_id');
    $product = Product::factory()->create();
    $secondPath = $firstPath.'/product';

    $this->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, AnalyticsConsent::encode(AnalyticsConsent::GRANTED))
        ->withCookie(AnalyticsConfig::VISITOR_COOKIE, $visitorId)
        ->withCookie(AnalyticsConfig::SESSION_COOKIE, $sessionId)
        ->postJson('/analytics/events', [
            'event_name' => 'product_view',
            'entity_type' => 'product',
            'entity_id' => $product->id,
            'page_path' => $secondPath,
            'properties' => ['placement' => 'catalog'],
        ])->assertNoContent();

    $session = DB::connection('pgsql_migration')->table('analytics_sessions')->where('id', $sessionId)->first();
    expect($session->page_views)->toBe(1)
        ->and($session->exit_path)->toBe($secondPath)
        ->and(DB::connection('pgsql_migration')->table('events')->where('session_id', $sessionId)->count())->toBe(2);
});

it('starts a new session after inactivity or maximum age', function (string $reason) {
    enableP5A1SessionAnalytics();
    $visitorId = (string) Str::uuid();
    $oldSessionId = (string) Str::uuid();
    $startedAt = $reason === 'max-age' ? now()->subHours(25) : now()->subHours(2);
    $lastSeenAt = $reason === 'inactivity' ? now()->subHours(2) : now();
    p5a1InsertSession($oldSessionId, $visitorId, $startedAt, $lastSeenAt);
    $path = '/expired-session/'.Str::lower(Str::random(8));

    $this->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, AnalyticsConsent::encode(AnalyticsConsent::GRANTED))
        ->withCookie(AnalyticsConfig::VISITOR_COOKIE, $visitorId)
        ->withCookie(AnalyticsConfig::SESSION_COOKIE, $oldSessionId)
        ->postJson('/analytics/events', [
            'event_name' => 'page_view',
            'page_path' => $path,
            'properties' => [],
        ])->assertNoContent();

    $effective = DB::connection('pgsql_migration')->table('events')->where('page_path', $path)->value('session_id');
    expect($effective)->not->toBe($oldSessionId)
        ->and(DB::connection('pgsql_migration')->table('analytics_sessions')->where('visitor_id', $visitorId)->count())->toBe(2);
})->with(['inactivity' => ['inactivity'], 'maximum age' => ['max-age']]);

it('never reuses a session belonging to another analytics visitor', function () {
    enableP5A1SessionAnalytics();
    $owner = DB::connection('pgsql_migration');
    $firstVisitor = (string) Str::uuid();
    $secondVisitor = (string) Str::uuid();
    $foreignSession = (string) Str::uuid();
    p5a1InsertSession($foreignSession, $firstVisitor, now()->subMinute(), now()->subMinute());
    $path = '/foreign-session/'.Str::lower(Str::random(8));

    $this->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, AnalyticsConsent::encode(AnalyticsConsent::GRANTED))
        ->withCookie(AnalyticsConfig::VISITOR_COOKIE, $secondVisitor)
        ->withCookie(AnalyticsConfig::SESSION_COOKIE, $foreignSession)
        ->postJson('/analytics/events', [
            'event_name' => 'page_view',
            'page_path' => $path,
            'properties' => [],
        ])->assertNoContent();

    $event = $owner->table('events')->where('page_path', $path)->first();
    expect($event->visitor_id)->toBe($secondVisitor)
        ->and($event->session_id)->not->toBe($foreignSession)
        ->and($owner->table('analytics_sessions')->where('id', $foreignSession)->value('visitor_id'))->toBe($firstVisitor);
});

it('derives user_id only from the authenticated server context and never erases it', function () {
    enableP5A1SessionAnalytics();
    $user = User::factory()->create();
    $visitorId = (string) Str::uuid();
    $path = '/authenticated/'.Str::lower(Str::random(8));

    $this->actingAs($user)
        ->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, AnalyticsConsent::encode(AnalyticsConsent::GRANTED))
        ->withCookie(AnalyticsConfig::VISITOR_COOKIE, $visitorId)
        ->postJson('/analytics/events', [
            'event_name' => 'page_view',
            'page_path' => $path,
            'properties' => [],
        ])->assertNoContent();

    $event = DB::connection('pgsql_migration')->table('events')->where('page_path', $path)->first();
    expect($event->user_id)->toBe($user->id)
        ->and(DB::connection('pgsql_migration')->table('analytics_sessions')->where('id', $event->session_id)->value('user_id'))->toBe($user->id);
});

it('blocks an existing analytics session immediately after consent revocation', function () {
    enableP5A1SessionAnalytics();
    $visitorId = (string) Str::uuid();
    $sessionId = (string) Str::uuid();
    p5a1InsertSession($sessionId, $visitorId, now()->subMinute(), now()->subMinute());
    $before = DB::connection('pgsql_migration')->table('events')->count();

    $this->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, AnalyticsConsent::encode(AnalyticsConsent::DENIED))
        ->withCookie(AnalyticsConfig::VISITOR_COOKIE, $visitorId)
        ->withCookie(AnalyticsConfig::SESSION_COOKIE, $sessionId)
        ->postJson('/analytics/events', [
            'event_name' => 'page_view',
            'page_path' => '/revoked',
            'properties' => [],
        ])->assertNoContent()
        ->assertCookieMissing(AnalyticsConfig::SESSION_COOKIE);

    expect(DB::connection('pgsql_migration')->table('events')->count())->toBe($before)
        ->and(DB::connection('pgsql_migration')->table('analytics_sessions')->where('id', $sessionId)->value('page_views'))->toBe(0);
});
