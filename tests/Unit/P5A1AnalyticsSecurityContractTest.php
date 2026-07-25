<?php

$root = dirname(__DIR__, 2);

it('declares fail-closed analytics defaults without reading env outside config', function () use ($root) {
    $config = file_get_contents($root.'/config/analytics.php');
    $support = file_get_contents($root.'/app/Support/AnalyticsConfig.php');
    $service = file_get_contents($root.'/app/Services/Analytics/FirstPartyAnalyticsIngestionService.php');

    expect($config)->toContain("env('ANALYTICS_INGESTION_ENABLED', false)")
        ->and($config)->toContain("env('ANALYTICS_CONSENT_VERSION', 1)")
        ->and($config)->toContain("env('ANALYTICS_IP_HASH_KEY')")
        ->and($support)->not->toContain('env(')
        ->and($service)->not->toContain('env(')
        ->and($support)->toContain('Analytics ingestion requires HTTPS.')
        ->and($support)->toContain('The analytics IP HMAC key is not configured.');
});

it('keeps the application behind one prepared analytics authority and outside ambient transactions', function () use ($root) {
    $service = file_get_contents($root.'/app/Services/Analytics/FirstPartyAnalyticsIngestionService.php');

    expect($service)->toContain('DB::transactionLevel() !== 0')
        ->and($service)->toContain('public.ingest_first_party_analytics_event')
        ->and($service)->toContain('Product::query()->whereKey')
        ->and($service)->not->toContain('AnalyticsEvent::')
        ->and($service)->not->toContain('AnalyticsSession::')
        ->and($service)->not->toContain('DB::transaction(')
        ->and($service)->not->toContain('Http::')
        ->and($service)->not->toContain('dispatch(');
});

it('pins authentication compatibility inside both PostgreSQL session lookups', function () use ($root) {
    $migration = file_get_contents($root.'/database/migrations/2026_07_14_000017_create_analytics_ingestion_authority.php');
    $compatible = <<<'SQL'
        s.user_id IS NULL
                                      OR (
                                          p_authenticated_user_id IS NOT NULL
                                          AND s.user_id = p_authenticated_user_id
                                      )
        SQL;

    expect($migration)->not->toContain('OR p_authenticated_user_id IS NULL')
        ->and(substr_count($migration, $compatible))->toBe(2)
        ->and($migration)->toContain('user_id = COALESCE(user_id, p_authenticated_user_id)');
});

it('contains no third-party tracker, browser storage, financial event or raw identity field', function () use ($root) {
    $paths = [
        $root.'/app/Http/Controllers/AnalyticsConsentController.php',
        $root.'/app/Http/Controllers/AnalyticsEventController.php',
        $root.'/app/Http/Requests/StoreAnalyticsEventRequest.php',
        $root.'/app/Services/Analytics/FirstPartyAnalyticsIngestionService.php',
        $root.'/routes/web.php',
    ];
    $source = implode("\n", array_map('file_get_contents', $paths));

    foreach (['localStorage', 'sessionStorage', 'googletag', 'gtag(', 'facebook', 'tiktok', 'Meta Pixel'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }

    $request = file_get_contents($root.'/app/Http/Requests/StoreAnalyticsEventRequest.php');
    expect($request)->toContain("Rule::in(['page_view', 'product_view'])")
        ->and($request)->not->toContain("'purchase'")
        ->and($request)->not->toContain("'payment'")
        ->and($request)->not->toContain("'refund'")
        ->and($request)->not->toContain("'revenue'")
        ->and($request)->not->toContain("'email'")
        ->and($request)->not->toContain("'phone'");
});

it('registers the analytics service explicitly in the fail-closed P4-B allowlist', function () use ($root) {
    $guard = file_get_contents($root.'/tests/Feature/P4BDownloadLogsTest.php');

    expect($guard)->toContain("'Analytics/FirstPartyAnalyticsIngestionService.php'")
        ->and(substr_count($guard, 'Analytics/FirstPartyAnalyticsIngestionService.php'))->toBe(1);
});
