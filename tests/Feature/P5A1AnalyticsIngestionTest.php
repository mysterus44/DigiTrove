<?php

use App\Models\Product;
use App\Services\Analytics\FirstPartyAnalyticsIngestionService;
use App\Support\AnalyticsConfig;
use App\Support\AnalyticsConsent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const P5A1_INGESTION_FUNCTION_SIGNATURE = 'public.ingest_first_party_analytics_event(uuid,uuid,bigint,uuid,character varying,character varying,bigint,jsonb,character varying,character varying,character varying,character varying,character varying,character varying,character varying,character varying,smallint,integer,integer,integer)';

function enableP5A1Analytics(array $overrides = []): void
{
    config()->set([
        'analytics.ingestion.enabled' => true,
        'analytics.consent.version' => 1,
        'analytics.session.ttl_minutes' => 30,
        'analytics.session.max_hours' => 24,
        'analytics.rate_limit.per_minute' => 30,
        'analytics.properties.max_bytes' => 4096,
        'analytics.ip_hash.key' => str_repeat('a', 32),
        'analytics.ip_hash.key_version' => 1,
        ...$overrides,
    ]);
}

function p5a1ConsentCookie(string $state = AnalyticsConsent::GRANTED): string
{
    return AnalyticsConsent::encode($state);
}

function p5a1EventCount(): int
{
    return DB::connection('pgsql_migration')->table('events')->count();
}

it('keeps first-party analytics ingestion disabled by default without requiring a key', function () {
    config()->set('analytics.ingestion.enabled', false);
    config()->set('analytics.ip_hash.key', null);
    $before = p5a1EventCount();

    $this->postJson('/analytics/events', [
        'event_name' => 'page_view',
        'page_path' => '/',
        'properties' => [],
    ])->assertNoContent();

    expect(p5a1EventCount())->toBe($before);
});

it('writes nothing without current explicit granted consent', function (string $kind) {
    enableP5A1Analytics();
    $before = p5a1EventCount();
    $request = $this;

    if ($kind === 'denied') {
        $request = $request->withCredentials()
            ->withCookie(AnalyticsConfig::CONSENT_COOKIE, p5a1ConsentCookie(AnalyticsConsent::DENIED));
    } elseif ($kind === 'obsolete') {
        $request = $request->withCredentials()->withCookie(
            AnalyticsConfig::CONSENT_COOKIE,
            json_encode(['consent' => 'granted', 'version' => 0], JSON_THROW_ON_ERROR),
        );
    }

    $request->postJson('/analytics/events', [
        'event_name' => 'page_view',
        'page_path' => '/privacy-gate',
        'properties' => [],
    ])->assertNoContent();

    expect(p5a1EventCount())->toBe($before);
})->with(['absent' => ['absent'], 'denied' => ['denied'], 'obsolete' => ['obsolete']]);

it('fails closed on unsafe analytics configuration and production HTTP', function (string $key, mixed $value) {
    enableP5A1Analytics();
    $consent = p5a1ConsentCookie();
    config()->set($key, $value);
    $before = p5a1EventCount();

    $this->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, $consent)
        ->postJson('/analytics/events', [
            'event_name' => 'page_view',
            'page_path' => '/misconfigured',
            'properties' => [],
        ])
        ->assertNoContent();

    expect(p5a1EventCount())->toBe($before);
})->with([
    'missing HMAC key' => ['analytics.ip_hash.key', null],
    'short HMAC key' => ['analytics.ip_hash.key', 'too-short'],
    'invalid key version' => ['analytics.ip_hash.key_version', 0],
    'invalid consent version' => ['analytics.consent.version', 0],
    'invalid TTL' => ['analytics.session.ttl_minutes', 0],
    'TTL beyond maximum age' => ['analytics.session.ttl_minutes', 1_441],
    'invalid maximum age' => ['analytics.session.max_hours', 0],
    'invalid limiter' => ['analytics.rate_limit.per_minute', 0],
    'invalid properties bound' => ['analytics.properties.max_bytes', 4_097],
    'production HTTP' => ['app.env', 'production'],
]);

it('ingests one valid page view with server-derived context', function () {
    enableP5A1Analytics();
    $visitorId = (string) Str::uuid();
    $path = '/catalog/'.Str::lower(Str::random(8));
    $ip = '203.0.113.17';

    $response = $this
        ->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, p5a1ConsentCookie())
        ->withCookie(AnalyticsConfig::VISITOR_COOKIE, $visitorId)
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders([
            'Referer' => 'https://EXAMPLE.test/source?q=secret#fragment',
            'User-Agent' => 'Mozilla/5.0 (iPhone; Mobile)',
        ])
        ->postJson('/analytics/events', [
            'event_name' => 'page_view',
            'page_path' => $path.'?token=never-store#fragment',
            'properties' => [],
            'utm_source' => 'Newsletter',
            'utm_medium' => 'Email',
            'utm_campaign' => 'Summer_2026',
        ])
        ->assertNoContent()
        ->assertCookie(AnalyticsConfig::VISITOR_COOKIE)
        ->assertCookie(AnalyticsConfig::SESSION_COOKIE);

    $event = DB::connection('pgsql_migration')->table('events')->where('page_path', $path)->first();

    expect($event)->not->toBeNull()
        ->and($event->visitor_id)->toBe($visitorId)
        ->and($event->event_name)->toBe('page_view')
        ->and($event->entity_type)->toBeNull()
        ->and($event->entity_id)->toBeNull()
        ->and($event->properties)->toBe('{}')
        ->and($event->referrer_host)->toBe('example.test')
        ->and($event->utm_source)->toBe('newsletter')
        ->and($event->utm_medium)->toBe('email')
        ->and($event->utm_campaign)->toBe('summer_2026')
        ->and($event->device_type)->toBe('mobile')
        ->and($event->ip_hash)->toBe(hash_hmac('sha256', $ip, str_repeat('a', 32)))
        ->and($event->ip_hash_key_version)->toBe(1)
        ->and($event->occurred_at)->not->toBeNull()
        ->and($event->created_at)->not->toBeNull()
        ->and($response->getContent())->toBe('');
});

it('ingests a product view only for an existing product and allowlisted placement', function () {
    enableP5A1Analytics();
    $product = Product::factory()->create();
    $path = '/products/'.Str::lower(Str::random(8));

    $this->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, p5a1ConsentCookie())
        ->postJson('/analytics/events', [
            'event_name' => 'product_view',
            'entity_type' => 'product',
            'entity_id' => $product->id,
            'page_path' => $path,
            'properties' => ['placement' => 'recommendation'],
        ])
        ->assertNoContent();

    $event = DB::connection('pgsql_migration')->table('events')->where('page_path', $path)->first();
    expect($event)->not->toBeNull()
        ->and($event->entity_type)->toBe('product')
        ->and($event->entity_id)->toBe($product->id)
        ->and(json_decode($event->properties, true, 8, JSON_THROW_ON_ERROR))->toBe(['placement' => 'recommendation']);
});

it('rejects an unknown product without an analytics write', function () {
    enableP5A1Analytics();
    $before = p5a1EventCount();

    $this->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, p5a1ConsentCookie())
        ->postJson('/analytics/events', [
            'event_name' => 'product_view',
            'entity_type' => 'product',
            'entity_id' => PHP_INT_MAX,
            'page_path' => '/products/unknown',
            'properties' => ['placement' => 'direct'],
        ])
        ->assertUnprocessable()
        ->assertContent('');

    expect(p5a1EventCount())->toBe($before);
});

it('rejects forbidden, sensitive, arbitrary and oversized client payloads', function (array $payload) {
    enableP5A1Analytics();
    $before = p5a1EventCount();
    $valid = [
        'event_name' => 'page_view',
        'page_path' => '/validation',
        'properties' => [],
    ];

    $this->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, p5a1ConsentCookie())
        ->postJson('/analytics/events', [...$valid, ...$payload])
        ->assertUnprocessable();

    expect(p5a1EventCount())->toBe($before);
})->with([
    'financial event' => [['event_name' => 'purchase']],
    'arbitrary event' => [['event_name' => 'custom_event']],
    'client user id' => [['user_id' => 1]],
    'client timestamp' => [['occurred_at' => now()->toIso8601String()]],
    'client IP' => [['ip_hash' => str_repeat('a', 64)]],
    'client device' => [['device_type' => 'desktop']],
    'page properties' => [['properties' => ['revenue' => 100]]],
    'oversized properties' => [['properties' => ['data' => str_repeat('x', 5000)]]],
    'unsafe path' => [['page_path' => "https://example.test/\r\n"]],
]);

it('requires JSON and rejects query-string event transport and cross-origin requests', function () {
    enableP5A1Analytics();
    $this->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, p5a1ConsentCookie())
        ->post('/analytics/events', [
            'event_name' => 'page_view',
            'page_path' => '/',
            'properties' => [],
        ])
        ->assertUnprocessable();

    $this->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, p5a1ConsentCookie())
        ->postJson('/analytics/events?event_name=purchase', [
            'event_name' => 'page_view',
            'page_path' => '/',
            'properties' => [],
        ])
        ->assertUnprocessable();

    $this->withCredentials()
        ->withCookie(AnalyticsConfig::CONSENT_COOKIE, p5a1ConsentCookie())
        ->withHeader('Origin', 'https://attacker.example')
        ->postJson('/analytics/events', [
            'event_name' => 'page_view',
            'page_path' => '/',
            'properties' => [],
        ])
        ->assertForbidden();
});

it('returns a uniform 204 when the PostgreSQL analytics authority is unavailable', function () {
    enableP5A1Analytics();
    $owner = DB::connection('pgsql_migration');
    $owner->statement('SET ROLE digitrove_analytics_executor');
    $owner->statement('REVOKE EXECUTE ON FUNCTION '.P5A1_INGESTION_FUNCTION_SIGNATURE.' FROM digitrove_runtime');
    $owner->statement('RESET ROLE');

    try {
        $response = $this->withCredentials()
            ->withCookie(AnalyticsConfig::CONSENT_COOKIE, p5a1ConsentCookie())
            ->postJson('/analytics/events', [
                'event_name' => 'page_view',
                'page_path' => '/authority-unavailable',
                'properties' => [],
            ]);

        $response->assertNoContent()
            ->assertCookieMissing(AnalyticsConfig::SESSION_COOKIE);

        expect($response->getContent())->toBe('')
            ->and($response->headers->all())->not->toHaveKey('x-sqlstate');
    } finally {
        $owner->statement('SET ROLE digitrove_analytics_executor');
        $owner->statement('GRANT EXECUTE ON FUNCTION '.P5A1_INGESTION_FUNCTION_SIGNATURE.' TO digitrove_runtime');
        $owner->statement('RESET ROLE');
    }
});

it('rate limits on digests without revealing a raw visitor or IP', function () {
    enableP5A1Analytics(['analytics.rate_limit.per_minute' => 2]);
    $visitorId = (string) Str::uuid();
    $ip = '198.51.100.73';
    $prefix = '/rate-limit/'.Str::lower(Str::random(8));
    $before = p5a1EventCount();

    foreach (range(1, 3) as $attempt) {
        $this->withCredentials()
            ->withCookie(AnalyticsConfig::CONSENT_COOKIE, p5a1ConsentCookie())
            ->withCookie(AnalyticsConfig::VISITOR_COOKIE, $visitorId)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/analytics/events', [
                'event_name' => 'page_view',
                'page_path' => $prefix.'/'.$attempt,
                'properties' => [],
            ])
            ->assertNoContent();
    }

    expect(p5a1EventCount() - $before)->toBe(2);

    $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
    expect($provider)->toContain("hash_hmac('sha256', \$ip, \$secret)")
        ->and($provider)->toContain("hash('sha256', (string) \$request->cookie")
        ->and($provider)->not->toContain('->by($ip)')
        ->and($provider)->not->toContain('->by((string) $request->cookie');
});

it('refuses to join an ambient Commerce transaction before doing any write', function () {
    enableP5A1Analytics();
    $request = Request::create('/analytics/events', 'POST', [], [
        AnalyticsConfig::CONSENT_COOKIE => p5a1ConsentCookie(),
    ], [], ['REMOTE_ADDR' => '127.0.0.1']);
    $service = app(FirstPartyAnalyticsIngestionService::class);
    $before = p5a1EventCount();

    DB::beginTransaction();
    try {
        expect(fn () => $service->ingest($request, [
            'event_name' => 'page_view',
            'page_path' => '/ambient',
            'properties' => [],
        ]))->toThrow(RuntimeException::class, 'cannot join an ambient transaction');
    } finally {
        DB::rollBack();
    }

    expect(p5a1EventCount())->toBe($before);
});
