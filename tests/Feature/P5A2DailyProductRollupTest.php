<?php

use App\Enums\ProductType;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseAsOwner as RefreshDatabase;
use Tests\Support\P5A2RollupFixture;

uses(RefreshDatabase::class);

it('uses the immutable purchased identity and keeps bundle revenue on the bundle per currency', function () {
    P5A2RollupFixture::beginRepeatableRead();
    $day = CarbonImmutable::parse('2026-07-19', 'UTC');
    $bundle = Product::factory()->create(['type' => ProductType::Bundle]);
    $component = Product::factory()->create(['type' => ProductType::Ebook]);

    DB::connection('pgsql_migration')->table('product_bundles')->insert([
        'bundle_id' => $bundle->id,
        'child_product_id' => $component->id,
    ]);
    $xof = P5A2RollupFixture::paidOrder($day->addHours(8), $bundle, 'XOF', 2, 3_000);
    P5A2RollupFixture::paidOrder($day->addHours(9), $bundle, 'EUR', 1, 12_000);
    $bundle->forceDelete();

    expect($xof['item']->fresh()->product_id)->toBeNull()
        ->and($xof['item']->fresh()->purchased_product_id)->toBe($bundle->id);

    P5A2RollupFixture::rollup($day);
    $stats = DB::connection('pgsql_migration')->table('daily_product_stats')
        ->where('day', $day->toDateString())
        ->where('product_id', $bundle->id)
        ->orderBy('currency')
        ->get();

    expect($stats)->toHaveCount(2)
        ->and($stats->pluck('currency')->all())->toBe(['EUR', 'XOF'])
        ->and($stats->firstWhere('currency', 'EUR')->purchases)->toBe(1)
        ->and($stats->firstWhere('currency', 'EUR')->revenue_minor)->toBe(12_000)
        ->and($stats->firstWhere('currency', 'XOF')->purchases)->toBe(2)
        ->and($stats->firstWhere('currency', 'XOF')->revenue_minor)->toBe(6_000)
        ->and(DB::connection('pgsql_migration')->table('daily_product_stats')
            ->where('product_id', $component->id)->count())->toBe(0);
});
