<?php

use App\Services\Analytics\Read\AnalyticsSalesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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

it('returns stable paginated daily sales and calculated zero-sales days', function () {
    Fixture::funnel('2026-08-01', 10, 10, 10, 10, 10, 10, 10);
    Fixture::funnel('2026-08-02', 10, 10, 10, 10, 10, 10, 10);
    Fixture::funnel('2026-08-03', 10, 10, 10, 10, 10, 10, 10);
    Fixture::sales('2026-08-01', 'XOF', 2, 20_000, 1_000, 100, 2_000, 17_100, 9_550);
    Fixture::sales('2026-08-03', 'XOF', 1, 5_000, 0, 0, 0, 5_000, 5_000);
    Fixture::sales('2026-08-03', 'USD', 1, 200, 0, 0, 0, 200, 200);

    $result = app(AnalyticsSalesQuery::class)->get('XOF', '2026-08-01', '2026-08-03', 1, 2);

    expect($result->currency)->toBe('XOF')
        ->and($result->totalRows)->toBe(3)
        ->and($result->lastPage)->toBe(2)
        ->and(array_column($result->items, 'day'))->toBe(['2026-08-03', '2026-08-02'])
        ->and($result->items[1]->ordersCount)->toBe(0)
        ->and(array_column($result->series, 'day'))->toBe(['2026-08-01', '2026-08-02', '2026-08-03'])
        ->and($result->totals->ordersCount)->toBe(3)
        ->and($result->totals->grossRevenueMinor)->toBe(25_000)
        ->and($result->totals->netRevenueMinor)->toBe(22_100)
        ->and($result->missingDays)->toBe([])
        ->and($result->currentDayProvisional)->toBeTrue();
});

it('does not fabricate data for uncalculated days', function () {
    Fixture::funnel('2026-08-01', 1, 1, 1, 1, 1, 1, 1);

    $result = app(AnalyticsSalesQuery::class)->get('XOF', '2026-08-01', '2026-08-03');

    expect($result->totalRows)->toBe(1)
        ->and($result->missingDays)->toBe(['2026-08-02', '2026-08-03'])
        ->and($result->items[0]->day)->toBe('2026-08-01');
});

it('validates currency range and pagination before any query', function () {
    $query = app(AnalyticsSalesQuery::class);

    expect(fn () => $query->get('xof'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $query->get('x0f'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $query->get('XOF', page: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $query->get('XOF', perPage: 101))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $query->get('XOF', '2026-08-04', '2026-08-04'))->toThrow(InvalidArgumentException::class);
});

it('uses cache keys scoped by currency range page and page size', function () {
    Fixture::funnel('2026-08-01', 1, 1, 1, 1, 1, 1, 1);
    Fixture::sales('2026-08-01', 'XOF', 1, 1_000, 0, 0, 0, 1_000, 1_000);
    Fixture::sales('2026-08-01', 'USD', 1, 10, 0, 0, 0, 10, 10);
    $query = app(AnalyticsSalesQuery::class);

    expect($query->get('XOF', '2026-08-01', '2026-08-01')->totals->netRevenueMinor)->toBe(1_000)
        ->and($query->get('USD', '2026-08-01', '2026-08-01')->totals->netRevenueMinor)->toBe(10);
});
