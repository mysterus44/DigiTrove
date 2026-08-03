<?php

use App\Services\Analytics\Read\AnalyticsProductQuery;
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

it('merges currency-free engagement with one commercial currency without duplication', function () {
    Fixture::funnel('2026-08-01', 1, 1, 1, 0, 0, 0, 0);
    Fixture::funnel('2026-08-02', 1, 1, 1, 0, 0, 0, 0);
    Fixture::productEngagement('2026-08-01', 10, 5);
    Fixture::productEngagement('2026-08-02', 20, 7);
    Fixture::productSales('2026-08-01', 10, 'XOF', 2, 1_000);
    Fixture::productSales('2026-08-02', 30, 'XOF', 1, 500);
    Fixture::productSales('2026-08-01', 10, 'USD', 9, 99_000);

    $result = app(AnalyticsProductQuery::class)->get('XOF', '2026-08-01', '2026-08-02');

    expect($result->currency)->toBe('XOF')
        ->and($result->coverageStart)->toBe('2026-08-01')
        ->and($result->coverageEnd)->toBe('2026-08-02')
        ->and($result->missingDays)->toBe([])
        ->and($result->total)->toBe(3)
        ->and(array_column($result->rows, 'productId'))->toBe([10, 30, 20])
        ->and($result->rows[0]->label)->toBe('Produit #10')
        ->and($result->rows[0]->views)->toBe(5)
        ->and($result->rows[0]->purchases)->toBe(2)
        ->and($result->rows[0]->revenueMinor)->toBe(1_000)
        ->and($result->rows[0]->averageRevenuePerPurchaseMinor)->toBe(500)
        ->and($result->rows[1]->views)->toBe(0)
        ->and($result->rows[2]->purchases)->toBe(0)
        ->and($result->rows[2]->averageRevenuePerPurchaseMinor)->toBeNull();
});

it('validates currency sort pagination and the bounded UTC period before reading', function () {
    $query = app(AnalyticsProductQuery::class);

    expect(fn () => $query->get('xof'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $query->get('XOF', sort: 'drop_table'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $query->get('XOF', page: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $query->get('XOF', perPage: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $query->get('XOF', perPage: 101))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $query->get('XOF', '2025-07-01', '2026-08-03'))->toThrow(InvalidArgumentException::class);
});

it('uses each closed stable sort and paginates deterministically', function () {
    Fixture::funnel('2026-08-01', 1, 1, 1, 0, 0, 0, 0);

    foreach ([3 => [4, 20, 200], 1 => [8, 10, 100], 2 => [8, 10, 100]] as $id => [$views, $purchases, $revenue]) {
        Fixture::productEngagement('2026-08-01', $id, $views);
        Fixture::productSales('2026-08-01', $id, 'XOF', $purchases, $revenue);
    }

    $query = app(AnalyticsProductQuery::class);

    expect(array_column($query->get('XOF', '2026-08-01', '2026-08-01', 'revenue_desc')->rows, 'productId'))->toBe([3, 1, 2])
        ->and(array_column($query->get('XOF', '2026-08-01', '2026-08-01', 'purchases_desc')->rows, 'productId'))->toBe([3, 1, 2])
        ->and(array_column($query->get('XOF', '2026-08-01', '2026-08-01', 'views_desc')->rows, 'productId'))->toBe([1, 2, 3])
        ->and(array_column($query->get('XOF', '2026-08-01', '2026-08-01', 'product_id_asc')->rows, 'productId'))->toBe([1, 2, 3])
        ->and($query->get('XOF', '2026-08-01', '2026-08-01', page: 2, perPage: 2)->lastPage)->toBe(2)
        ->and(array_column($query->get('XOF', '2026-08-01', '2026-08-01', page: 2, perPage: 2)->rows, 'productId'))->toBe([2]);
});

it('reports calendar gaps and the current UTC day without fabricating zero rows', function () {
    Fixture::funnel('2026-08-01', 0, 0, 0, 0, 0, 0, 0);
    Fixture::funnel('2026-08-03', 0, 0, 0, 0, 0, 0, 0);
    Fixture::productEngagement('2026-08-03', 10, 1);

    $result = app(AnalyticsProductQuery::class)->get('XOF', '2026-08-01', '2026-08-03');

    expect($result->missingDays)->toBe(['2026-08-02'])
        ->and($result->currentDayProvisional)->toBeTrue()
        ->and($result->total)->toBe(1);
});

it('scopes cache by currency sort page and page size and refuses ambient reader transactions', function () {
    Fixture::funnel('2026-08-01', 1, 1, 1, 0, 0, 0, 0);
    Fixture::productSales('2026-08-01', 10, 'XOF', 1, 100);
    $query = app(AnalyticsProductQuery::class);

    $query->get('XOF', '2026-08-01', '2026-08-01', 'views_desc', 2, 10);
    expect(Cache::has('analytics:v1|role=admin|scope=global|timezone=UTC|query=products|from=2026-08-01|to=2026-08-01|currency=XOF|sort=views_desc|page=2|per_page=10'))->toBeTrue();

    config()->set('analytics.dashboard.cache_seconds', 0);
    $reader = DB::connection('pgsql_analytics_reader');
    $reader->beginTransaction();

    try {
        expect(fn () => $query->get('XOF'))->toThrow(RuntimeException::class, 'Analytics reader refuses an ambient transaction.');
    } finally {
        $reader->rollBack();
    }
});
