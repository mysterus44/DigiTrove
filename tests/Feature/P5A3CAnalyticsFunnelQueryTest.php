<?php

use App\Services\Analytics\Read\AnalyticsFunnelQuery;
use App\Services\Analytics\Read\Data\AnalyticsFunnel;
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

it('returns exact aggregate volumes and descriptive non-cohorted ratios', function () {
    Fixture::funnel('2026-08-01', 10, 8, 12, 99, 4, 2, 5);
    Fixture::funnel('2026-08-02', 20, 12, 18, 77, 6, 3, 0);

    $result = app(AnalyticsFunnelQuery::class)->get('2026-08-01', '2026-08-02');

    expect($result->visitors)->toBe(30)
        ->and($result->sessions)->toBe(20)
        ->and($result->productViews)->toBe(30)
        ->and($result->checkouts)->toBe(10)
        ->and($result->purchases)->toBe(5)
        ->and($result->newCustomers)->toBe(5)
        ->and($result->addToCartsTracked)->toBeFalse()
        ->and($result->ratios->viewsPerSession)->toBe('1.500000')
        ->and($result->ratios->checkoutRatePerSession)->toBe('0.500000')
        ->and($result->ratios->purchaseRatePerSession)->toBe('0.250000')
        ->and($result->ratios->purchasePerCheckout)->toBe('0.500000')
        ->and($result->ratios->newCustomerShare)->toBe('1.000000');
});

it('keeps missing days null while preserving measured zero and provisional today', function () {
    Fixture::funnel('2026-08-01', 0, 0, 0, 0, 0, 0, 0);
    Fixture::funnel('2026-08-03', 2, 1, 3, 0, 1, 1, 1);

    $result = app(AnalyticsFunnelQuery::class)->get('2026-08-01', '2026-08-03');

    expect(array_column($result->dailySeries, 'day'))->toBe(['2026-08-01', '2026-08-02', '2026-08-03'])
        ->and($result->dailySeries[0]->sessions)->toBe(0)
        ->and($result->dailySeries[1]->sessions)->toBeNull()
        ->and($result->dailySeries[1]->productViews)->toBeNull()
        ->and($result->missingDays)->toBe(['2026-08-02'])
        ->and($result->currentDayProvisional)->toBeTrue();
});

it('distinguishes a period without coverage from a calculated day containing real zeros', function () {
    $uncovered = app(AnalyticsFunnelQuery::class)->get('2026-08-01', '2026-08-02');

    expect($uncovered->coverageStart)->toBeNull()
        ->and($uncovered->coverageEnd)->toBeNull()
        ->and($uncovered->missingDays)->toBe(['2026-08-01', '2026-08-02'])
        ->and(array_column($uncovered->dailySeries, 'sessions'))->toBe([null, null])
        ->and(array_column($uncovered->dailySeries, 'productViews'))->toBe([null, null])
        ->and($uncovered->visitors)->toBe(0)
        ->and($uncovered->ratios->viewsPerSession)->toBeNull();

    Fixture::funnel('2026-08-03', 0, 0, 0, 0, 0, 0, 0);
    $measuredZero = app(AnalyticsFunnelQuery::class)->get('2026-08-03', '2026-08-03');

    expect($measuredZero->coverageStart)->toBe('2026-08-03')
        ->and($measuredZero->coverageEnd)->toBe('2026-08-03')
        ->and($measuredZero->missingDays)->toBe([])
        ->and($measuredZero->dailySeries[0]->sessions)->toBe(0)
        ->and($measuredZero->dailySeries[0]->productViews)->toBe(0)
        ->and($measuredZero->visitors)->toBe(0)
        ->and($measuredZero->ratios->viewsPerSession)->toBeNull();
});

it('returns unavailable ratios for zero denominators and never caps ratios', function () {
    Fixture::funnel('2026-08-01', 10, 0, 4, 0, 0, 2, 5);

    $ratios = app(AnalyticsFunnelQuery::class)->get('2026-08-01', '2026-08-01')->ratios;

    expect($ratios->viewsPerSession)->toBeNull()
        ->and($ratios->checkoutRatePerSession)->toBeNull()
        ->and($ratios->purchaseRatePerSession)->toBeNull()
        ->and($ratios->purchasePerCheckout)->toBeNull()
        ->and($ratios->newCustomerShare)->toBe('2.500000');
});

it('uses the global currency-free cache scope and validates the UTC range', function () {
    Fixture::funnel('2026-08-01', 1, 1, 1, 0, 1, 1, 1);
    $query = app(AnalyticsFunnelQuery::class);

    $query->get('2026-08-01', '2026-08-01');

    $key = 'analytics:v1|role=admin|scope=global|timezone=UTC|query=funnel|from=2026-08-01|to=2026-08-01|currency=none|page=none|per_page=none';
    expect(Cache::has($key))->toBeTrue()
        ->and(Cache::get($key))->toBeArray()
        ->and($query->get('2026-08-01', '2026-08-01'))->toBeInstanceOf(AnalyticsFunnel::class)
        ->and(fn () => $query->get('2026-08-04', '2026-08-04'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $query->get('2025-07-01', '2026-08-03'))->toThrow(InvalidArgumentException::class);
});
