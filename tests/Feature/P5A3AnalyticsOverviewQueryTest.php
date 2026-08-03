<?php

use App\Services\Analytics\Read\AnalyticsOverviewQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;
use Tests\Support\P5A3DashboardFixture as Fixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-03 12:00:00', 'UTC'));
    Cache::clear();
    Fixture::clear();
});

afterEach(function () {
    Fixture::clear();
    CarbonImmutable::setTestNow();
});

it('returns global funnel totals and keeps every sales currency separate', function () {
    Fixture::funnel('2026-08-01', 10, 8, 7, 5, 4, 3, 2);
    Fixture::funnel('2026-08-02', 20, 16, 14, 10, 8, 6, 4);
    Fixture::sales('2026-08-01', 'XOF', 2, 20_000, 1_000, 0, 2_000, 17_000, 9_500);
    Fixture::sales('2026-08-02', 'USD', 1, 1_000, 0, 100, 0, 1_100, 1_100);

    $result = app(AnalyticsOverviewQuery::class)->get('2026-08-01', '2026-08-03');

    expect($result->from)->toBe('2026-08-01')
        ->and($result->to)->toBe('2026-08-03')
        ->and($result->coverageStart)->toBe('2026-08-01')
        ->and($result->coverageEnd)->toBe('2026-08-02')
        ->and($result->missingDays)->toBe(['2026-08-03'])
        ->and($result->currentDayProvisional)->toBeFalse()
        ->and($result->visitors)->toBe(30)
        ->and($result->sessions)->toBe(24)
        ->and($result->purchases)->toBe(9)
        ->and(array_keys($result->salesByCurrency))->toBe(['USD', 'XOF'])
        ->and($result->salesByCurrency['XOF']->netRevenueMinor)->toBe(17_000)
        ->and($result->salesByCurrency['USD']->netRevenueMinor)->toBe(1_100);
});

it('marks today provisional only when its rollup exists', function () {
    Fixture::funnel('2026-08-03', 1, 1, 1, 1, 1, 1, 1);

    $result = app(AnalyticsOverviewQuery::class)->get('2026-08-03', '2026-08-03');

    expect($result->currentDayProvisional)->toBeTrue()
        ->and($result->missingDays)->toBe([]);
});

it('validates an inclusive UTC range with a bounded default and no future day', function () {
    $query = app(AnalyticsOverviewQuery::class);
    $default = $query->get();

    expect($default->from)->toBe('2026-07-05')
        ->and($default->to)->toBe('2026-08-03')
        ->and(fn () => $query->get('2026-08-04', '2026-08-04'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $query->get('2026-08-03', '2026-08-02'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $query->get('2025-07-01', '2026-08-03'))->toThrow(InvalidArgumentException::class);
});

it('uses the dedicated reader cache scope and can disable caching', function () {
    Fixture::funnel('2026-08-01', 10, 8, 7, 5, 4, 3, 2);
    $query = app(AnalyticsOverviewQuery::class);

    expect($query->get('2026-08-01', '2026-08-01')->visitors)->toBe(10);
    DB::connection('pgsql_migration')->table('daily_funnel_stats')->where('day', '2026-08-01')->update(['visitors' => 99]);
    expect($query->get('2026-08-01', '2026-08-01')->visitors)->toBe(10);

    config()->set('analytics.dashboard.cache_seconds', 0);
    expect($query->get('2026-08-01', '2026-08-01')->visitors)->toBe(99)
        ->and(DB::connection('pgsql_analytics_reader')->transactionLevel())->toBe(0);
});

it('refuses an ambient reader transaction', function () {
    config()->set('analytics.dashboard.cache_seconds', 0);
    $reader = DB::connection('pgsql_analytics_reader');
    $reader->beginTransaction();

    try {
        expect(fn () => app(AnalyticsOverviewQuery::class)->get())
            ->toThrow(RuntimeException::class, 'Analytics reader refuses an ambient transaction.');
    } finally {
        $reader->rollBack();
    }
});

it('preserves a negative per-currency net without clamping', function () {
    Fixture::funnel('2026-08-01', 1, 1, 1, 1, 1, 0, 0);
    Fixture::sales('2026-08-01', 'XOF', 0, 0, 0, 0, 500, -500, 0);

    expect(app(AnalyticsOverviewQuery::class)->get('2026-08-01', '2026-08-01')
        ->salesByCurrency['XOF']->netRevenueMinor)->toBe(-500);
});

it('fails closed before connecting when the reader identity is incomplete', function () {
    config()->set('database.connections.pgsql_analytics_reader.password', '');
    DB::purge('pgsql_analytics_reader');

    expect(fn () => app(AnalyticsOverviewQuery::class)->get())
        ->toThrow(RuntimeException::class, 'Analytics dashboard is unavailable.');
});
