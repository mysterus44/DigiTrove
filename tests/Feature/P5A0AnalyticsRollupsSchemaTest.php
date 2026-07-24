<?php

use App\Models\DailyFunnelStat;
use App\Models\DailyProductStat;
use App\Models\DailySalesStat;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshesDatabaseAsOwner as RefreshDatabase;

uses(RefreshDatabase::class);

function expectP5A0RollupViolation(string $table, array $attributes, string $constraint): void
{
    $exception = null;

    try {
        DB::transaction(fn () => DB::table($table)->insert($attributes));
    } catch (QueryException $queryException) {
        $exception = $queryException;
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe('23514')
        ->and($exception->getMessage())->toContain($constraint);
}

it('creates three FK-free rollups with the intended currency-safe primary keys', function () {
    foreach (['daily_sales_stats', 'daily_product_stats', 'daily_funnel_stats'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue()
            ->and(DB::table('pg_constraint')->where('conrelid', DB::raw("'public.{$table}'::regclass"))->where('contype', 'f')->count())->toBe(0);
    }

    $primaryKeys = DB::select(<<<'SQL'
        SELECT c.relname AS table_name, pg_get_constraintdef(k.oid) AS definition
        FROM pg_constraint k
        JOIN pg_class c ON c.oid = k.conrelid
        WHERE k.contype = 'p'
          AND c.relname IN ('daily_sales_stats', 'daily_product_stats', 'daily_funnel_stats')
        ORDER BY c.relname
        SQL);

    expect(collect($primaryKeys)->pluck('definition', 'table_name')->all())->toBe([
        'daily_funnel_stats' => 'PRIMARY KEY (day)',
        'daily_product_stats' => 'PRIMARY KEY (day, product_id, currency)',
        'daily_sales_stats' => 'PRIMARY KEY (day, currency)',
    ]);
});

it('keeps daily sales separated by currency and enforces exact integer formulas', function () {
    $base = [
        'day' => '2026-07-24',
        'orders_count' => 2,
        'gross_revenue_minor' => 22000,
        'discount_minor' => 2000,
        'tax_minor' => 1000,
        'refunds_minor' => 3000,
        'net_revenue_minor' => 18000,
        'average_order_minor' => 10500,
        'updated_at' => now(),
    ];

    DB::table('daily_sales_stats')->insert([
        [...$base, 'currency' => 'XOF'],
        [...$base, 'currency' => 'USD'],
    ]);

    expect(DB::table('daily_sales_stats')->where('day', '2026-07-24')->count())->toBe(2);

    expectP5A0RollupViolation('daily_sales_stats', [...$base, 'day' => '2026-07-25', 'currency' => 'xof'], 'daily_sales_stats_currency_format_check');
    expectP5A0RollupViolation('daily_sales_stats', [
        ...$base,
        'day' => '2026-07-26',
        'currency' => 'EUR',
        'orders_count' => -1,
        'gross_revenue_minor' => 0,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'refunds_minor' => 0,
        'net_revenue_minor' => 0,
        'average_order_minor' => 0,
    ], 'daily_sales_stats_counts_non_negative_check');
    expectP5A0RollupViolation('daily_sales_stats', [...$base, 'day' => '2026-07-27', 'currency' => 'EUR', 'refunds_minor' => -1], 'daily_sales_stats_amounts_check');
    expectP5A0RollupViolation('daily_sales_stats', [...$base, 'day' => '2026-07-28', 'currency' => 'EUR', 'net_revenue_minor' => 18001], 'daily_sales_stats_net_formula_check');
    expectP5A0RollupViolation('daily_sales_stats', [...$base, 'day' => '2026-07-29', 'currency' => 'EUR', 'average_order_minor' => 10499], 'daily_sales_stats_average_formula_check');
});

it('keeps product rollups soft-linked and currency-safe without undefined refund attribution', function () {
    $base = [
        'day' => '2026-07-24',
        'product_id' => 999999,
        'views' => 12,
        'add_to_carts' => 4,
        'purchases' => 2,
        'revenue_minor' => 5000,
        'updated_at' => now(),
    ];

    DB::table('daily_product_stats')->insert([
        [...$base, 'currency' => 'XOF'],
        [...$base, 'currency' => 'USD'],
    ]);

    expect(DB::table('daily_product_stats')->where('product_id', 999999)->count())->toBe(2)
        ->and(Schema::hasColumn('daily_product_stats', 'refunds_minor'))->toBeFalse();

    expectP5A0RollupViolation('daily_product_stats', [...$base, 'day' => '2026-07-25', 'currency' => 'xof'], 'daily_product_stats_currency_format_check');
    expectP5A0RollupViolation('daily_product_stats', [...$base, 'day' => '2026-07-26', 'currency' => 'EUR', 'views' => -1], 'daily_product_stats_metrics_non_negative_check');
    expectP5A0RollupViolation('daily_product_stats', [...$base, 'day' => '2026-07-27', 'currency' => 'EUR', 'product_id' => 0], 'daily_product_stats_product_id_positive_check');
});

it('stores non-monotonic funnel observations and new customers once per day', function () {
    DB::table('daily_funnel_stats')->insert([
        'day' => '2026-07-24',
        'visitors' => 2,
        'sessions' => 5,
        'product_views' => 3,
        'add_to_carts' => 7,
        'checkouts' => 1,
        'purchases' => 4,
        'new_customers' => 6,
        'updated_at' => now(),
    ]);

    expect(DB::table('daily_funnel_stats')->value('new_customers'))->toBe(6)
        ->and(Schema::hasColumn('daily_sales_stats', 'new_customers'))->toBeFalse()
        ->and(Schema::hasColumn('daily_product_stats', 'new_customers'))->toBeFalse();

    expectP5A0RollupViolation('daily_funnel_stats', [
        'day' => '2026-07-25',
        'visitors' => -1,
        'sessions' => 0,
        'product_views' => 0,
        'add_to_carts' => 0,
        'checkouts' => 0,
        'purchases' => 0,
        'new_customers' => 0,
        'updated_at' => now(),
    ], 'daily_funnel_stats_metrics_non_negative_check');
});

it('uses only integer physical types for money and counters and honest composite-key models', function () {
    $forbidden = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->whereIn('table_name', ['daily_sales_stats', 'daily_product_stats', 'daily_funnel_stats'])
        ->whereIn('data_type', ['numeric', 'decimal', 'real', 'double precision', 'money'])
        ->count();

    $sales = DailySalesStat::factory()->make();
    $product = DailyProductStat::factory()->make();
    $funnel = DailyFunnelStat::factory()->make();

    expect($forbidden)->toBe(0)
        ->and($sales->getIncrementing())->toBeFalse()
        ->and($sales->getKeyName())->toBeNull()
        ->and($product->getIncrementing())->toBeFalse()
        ->and($product->getKeyName())->toBeNull()
        ->and($funnel->getIncrementing())->toBeFalse()
        ->and($funnel->getKeyName())->toBe('day')
        ->and($sales->gross_revenue_minor)->toBeInt()
        ->and($product->revenue_minor)->toBeInt();
});
