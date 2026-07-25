<?php

use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesDatabaseAsOwner as RefreshDatabase;
use Tests\Support\P5A2RollupFixture;

uses(RefreshDatabase::class);

it('derives the UTC funnel and first paid customer from authoritative timestamps', function () {
    P5A2RollupFixture::beginRepeatableRead();
    $day = CarbonImmutable::parse('2026-07-17', 'UTC');
    $product = Product::factory()->create();
    $user = User::factory()->create();
    $visitorA = (string) Str::uuid();
    $visitorB = (string) Str::uuid();
    $sessionA = (string) Str::uuid();
    $sessionB = (string) Str::uuid();

    P5A2RollupFixture::productView($day->addHours(1), $product->id, $visitorA, $sessionA);
    P5A2RollupFixture::productView($day->addHours(2), $product->id, $visitorA, $sessionA);
    P5A2RollupFixture::productView($day->addHours(3), $product->id, $visitorB, $sessionB);
    P5A2RollupFixture::paidOrder($day->addHours(9), $product, user: $user);
    P5A2RollupFixture::paidOrder($day->addHours(10), $product);

    P5A2RollupFixture::rollup($day);
    $stats = DB::connection('pgsql_migration')->table('daily_funnel_stats')
        ->where('day', $day->toDateString())
        ->first();

    expect($stats->visitors)->toBe(2)
        ->and($stats->sessions)->toBe(2)
        ->and($stats->product_views)->toBe(3)
        ->and($stats->add_to_carts)->toBe(0)
        ->and($stats->checkouts)->toBe(2)
        ->and($stats->purchases)->toBe(2)
        ->and($stats->new_customers)->toBe(1);
});
