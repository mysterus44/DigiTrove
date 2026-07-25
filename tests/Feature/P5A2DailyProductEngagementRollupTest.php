<?php

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesDatabaseAsOwner as RefreshDatabase;
use Tests\Support\P5A2RollupFixture;

uses(RefreshDatabase::class);

it('counts each product view once without a currency or catalogue join', function () {
    P5A2RollupFixture::beginRepeatableRead();
    $day = CarbonImmutable::parse('2026-07-18', 'UTC');
    $product = Product::factory()->create();
    $productId = $product->id;

    P5A2RollupFixture::productView($day->addHours(1), $productId, (string) Str::uuid(), (string) Str::uuid());
    P5A2RollupFixture::productView($day->addHours(2), $productId, (string) Str::uuid(), (string) Str::uuid());
    $product->forceDelete();

    P5A2RollupFixture::rollup($day);
    $stats = DB::connection('pgsql_migration')->table('daily_product_engagement_stats')
        ->where('day', $day->toDateString())
        ->where('product_id', $productId)
        ->first();

    expect($stats->views)->toBe(2)
        ->and($stats->add_to_carts)->toBe(0)
        ->and(DB::connection('pgsql_migration')->getSchemaBuilder()
            ->hasColumn('daily_product_engagement_stats', 'currency'))->toBeFalse()
        ->and(DB::connection('pgsql_migration')->table('daily_product_stats')
            ->where('day', $day->toDateString())->count())->toBe(0);
});
