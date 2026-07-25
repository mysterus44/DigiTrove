<?php

use App\Enums\OrderStatus;
use App\Models\Product;
use App\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseAsOwner as RefreshDatabase;
use Tests\Support\P5A2RollupFixture;

uses(RefreshDatabase::class);

it('recalculates authoritative paid sales and refund-day money per currency', function () {
    P5A2RollupFixture::beginRepeatableRead();
    $day = CarbonImmutable::parse('2026-07-20', 'UTC');
    $product = Product::factory()->create();
    $fixture = P5A2RollupFixture::paidOrder($day->addHours(10), $product, 'XOF', 2, 5_000, 100);

    $fixture['order']->update(['status' => OrderStatus::PartiallyRefunded]);
    Refund::factory()->forPayment($fixture['payment'])->succeeded()->create([
        'amount_minor' => 1_000,
        'requested_at' => $day->addHours(11),
        'succeeded_at' => $day->addHours(12),
    ]);

    $first = P5A2RollupFixture::rollup($day);
    $second = P5A2RollupFixture::rollup($day);
    $stats = DB::connection('pgsql_migration')->table('daily_sales_stats')
        ->where('day', $day->toDateString())
        ->where('currency', 'XOF')
        ->first();

    expect($first)->toBe($second)
        ->and($stats->orders_count)->toBe(1)
        ->and($stats->gross_revenue_minor)->toBe(10_000)
        ->and($stats->discount_minor)->toBe(0)
        ->and($stats->tax_minor)->toBe(100)
        ->and($stats->refunds_minor)->toBe(1_000)
        ->and($stats->net_revenue_minor)->toBe(9_100)
        ->and($stats->average_order_minor)->toBe(10_100);
});
