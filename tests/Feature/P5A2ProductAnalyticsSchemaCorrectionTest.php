<?php

use App\Models\DailyProductEngagementStat;
use App\Models\DailyProductStat;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshesDatabaseAsOwner as RefreshDatabase;

uses(RefreshDatabase::class);

it('separates currency-free product engagement from commercial product rollups', function () {
    expect(Schema::hasTable('daily_product_engagement_stats'))->toBeTrue()
        ->and(Schema::getColumnListing('daily_product_engagement_stats'))->toBe([
            'day',
            'product_id',
            'views',
            'add_to_carts',
            'updated_at',
        ])
        ->and(Schema::hasColumn('daily_product_engagement_stats', 'currency'))->toBeFalse()
        ->and(Schema::hasColumn('daily_product_stats', 'views'))->toBeFalse()
        ->and(Schema::hasColumn('daily_product_stats', 'add_to_carts'))->toBeFalse();

    $primaryKey = DB::selectOne(<<<'SQL'
        SELECT pg_get_constraintdef(oid) AS definition
        FROM pg_constraint
        WHERE conrelid = 'public.daily_product_engagement_stats'::regclass
          AND contype = 'p'
        SQL);

    expect($primaryKey?->definition)->toBe('PRIMARY KEY (day, product_id)')
        ->and(DB::table('pg_constraint')
            ->where('conrelid', DB::raw("'public.daily_product_engagement_stats'::regclass"))
            ->where('contype', 'f')
            ->count())->toBe(0);
});

it('keeps commercial product metrics separated by authoritative currency', function () {
    $base = [
        'day' => '2026-07-24',
        'product_id' => 42,
        'purchases' => 2,
        'revenue_minor' => 5000,
        'updated_at' => now(),
    ];

    DB::table('daily_product_stats')->insert([
        [...$base, 'currency' => 'XOF'],
        [...$base, 'currency' => 'USD'],
    ]);

    DB::table('daily_product_engagement_stats')->insert([
        'day' => '2026-07-24',
        'product_id' => 42,
        'views' => 3,
        'add_to_carts' => 0,
        'updated_at' => now(),
    ]);

    expect(DB::table('daily_product_stats')->where('product_id', 42)->count())->toBe(2)
        ->and(DB::table('daily_product_engagement_stats')->where('product_id', 42)->count())->toBe(1)
        ->and(DailyProductStat::factory()->make()->getAttributes())->not->toHaveKeys(['views', 'add_to_carts'])
        ->and(DailyProductEngagementStat::factory()->make()->currency)->toBeNull();
});

it('keeps engagement rollups dark to public and the general runtime', function () {
    $publicAcl = DB::selectOne(<<<'SQL'
        SELECT EXISTS (
            SELECT 1
            FROM pg_class c
            CROSS JOIN LATERAL aclexplode(COALESCE(c.relacl, acldefault('r', c.relowner))) acl
            WHERE c.oid = 'public.daily_product_engagement_stats'::regclass
              AND acl.grantee = 0
              AND acl.privilege_type IN ('SELECT', 'INSERT')
        ) AS has_dml
        SQL);

    expect($publicAcl?->has_dml)->toBeFalse();

    $exception = null;

    try {
        DB::connection('pgsql')->table('daily_product_engagement_stats')->insert([
            'day' => '2026-07-24',
            'product_id' => 7,
            'views' => 1,
            'add_to_carts' => 0,
            'updated_at' => now(),
        ]);
    } catch (QueryException $queryException) {
        $exception = $queryException;
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe('42501');
});
