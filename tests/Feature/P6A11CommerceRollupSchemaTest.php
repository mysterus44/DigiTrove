<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithCrmDatabase;

uses(InteractsWithCrmDatabase::class);

it('installs only the P6-A1.1 table with closed physical constraints', function () {
    expect(Schema::hasTable('crm_contact_commerce_rollups'))->toBeTrue();

    // Check columns
    $columns = Schema::getColumns('crm_contact_commerce_rollups');
    $columnNames = array_column($columns, 'name');
    expect($columnNames)->toContain(
        'contact_id',
        'currency',
        'acquired_orders_count',
        'gross_revenue_minor',
        'refunded_amount_minor',
        'net_revenue_minor',
        'first_acquired_at',
        'last_acquired_at',
        'last_refunded_at',
        'calculation_version',
        'refreshed_at'
    )->not->toContain(
        'id',
        'public_id',
        'created_at',
        'updated_at',
        'reconciled_at',
        'checksum',
        'email',
        'user_id',
        'visitor_id'
    );

    // CURRENT-STATE : 48 migrations, la derniere etant 000032 (P6-D2, autorites de
    // touche et d'attribution). Nommee et non comptee : une 49e migration, ou une
    // derniere differente, doit toujours faire echouer ce contrat.
    $migrations = DB::table('migrations')->orderBy('id')->get();
    expect($migrations)->toHaveCount(48)
        ->and($migrations->last()->migration)->toBe('2026_07_14_000032_create_affiliate_attribution_authorities');

    // Aucune migration 000033 : P6-D2 est la frontiere courante.
    expect(glob(database_path('migrations').'/2026_07_14_000033*.php') ?: [])->toBe([]);
});

it('verifies exact restrictive foreign keys checks and primary key', function () {
    $constraints = DB::select(<<<'SQL'
        SELECT conname, contype, pg_get_constraintdef(c.oid) as definition
        FROM pg_constraint c
        JOIN pg_class t ON c.conrelid = t.oid
        WHERE t.relname = 'crm_contact_commerce_rollups'
    SQL);

    $constraintDefinitions = collect($constraints)->pluck('definition', 'conname')->all();

    expect($constraintDefinitions['crm_contact_commerce_rollups_pkey'])->toContain('PRIMARY KEY (contact_id, currency)')
        ->and($constraintDefinitions['crm_contact_commerce_rollups_contact_fk'])->toContain('FOREIGN KEY (contact_id) REFERENCES crm_contacts(id) ON DELETE RESTRICT')
        ->and($constraintDefinitions['crm_contact_commerce_rollups_currency_check'])->toContain("CHECK (((currency)::text ~ '^[A-Z]{3}$'::text))")
        ->and($constraintDefinitions['crm_contact_commerce_rollups_orders_count_check'])->toContain('CHECK ((acquired_orders_count > 0))')
        ->and($constraintDefinitions['crm_contact_commerce_rollups_gross_revenue_check'])->toContain('CHECK ((gross_revenue_minor >= 0))')
        ->and($constraintDefinitions['crm_contact_commerce_rollups_refunded_amount_check'])->toContain('CHECK (((refunded_amount_minor >= 0) AND (refunded_amount_minor <= gross_revenue_minor)))')
        ->and($constraintDefinitions['crm_contact_commerce_rollups_calculation_version_check'])->toContain('CHECK ((calculation_version > 0))')
        ->and($constraintDefinitions['crm_contact_commerce_rollups_dates_check'])->toContain('CHECK ((first_acquired_at <= last_acquired_at))');

    // The removed refund_date_check is NOT present (Commerce does not guarantee temporal order)
    expect(array_key_exists('crm_contact_commerce_rollups_refund_date_check', $constraintDefinitions))->toBeFalse();

    // Verify no additional indexes exist besides PK
    $indexes = DB::select(<<<'SQL'
        SELECT indexname
        FROM pg_indexes
        WHERE tablename = 'crm_contact_commerce_rollups'
    SQL);
    expect($indexes)->toHaveCount(1)
        ->and($indexes[0]->indexname)->toBe('crm_contact_commerce_rollups_pkey');
});

it('verifies net_revenue_minor is a generated stored column', function () {
    $column = DB::select(<<<'SQL'
        SELECT
            a.attgenerated,
            pg_catalog.format_type(a.atttypid, a.atttypmod) AS formatted_type,
            pg_catalog.pg_get_expr(ad.adbin, ad.adrelid) AS generation_expression
        FROM pg_catalog.pg_attribute AS a
        INNER JOIN pg_catalog.pg_class AS c
            ON c.oid = a.attrelid
        INNER JOIN pg_catalog.pg_namespace AS n
            ON n.oid = c.relnamespace
        LEFT JOIN pg_catalog.pg_attrdef AS ad
            ON ad.adrelid = a.attrelid
           AND ad.adnum = a.attnum
        WHERE n.nspname = 'public'
          AND c.relname = 'crm_contact_commerce_rollups'
          AND c.relkind = 'r'
          AND a.attname = 'net_revenue_minor'
          AND a.attnum > 0
          AND NOT a.attisdropped
    SQL);

    expect($column)->toHaveCount(1);

    $col = $column[0];
    expect($col->attgenerated)->toBe('s')
        ->and($col->formatted_type)->toBe('bigint')
        ->and($col->generation_expression)->toContain('gross_revenue_minor')
        ->and($col->generation_expression)->toContain('refunded_amount_minor')
        ->and($col->generation_expression)->toContain('-');
});

it('verifies all money columns are bigint', function () {
    $moneyColumns = ['gross_revenue_minor', 'refunded_amount_minor', 'net_revenue_minor', 'acquired_orders_count'];
    foreach ($moneyColumns as $colName) {
        $col = DB::selectOne(
            "SELECT t.typname FROM pg_attribute a JOIN pg_class c ON a.attrelid = c.oid JOIN pg_type t ON a.atttypid = t.oid WHERE c.relname = 'crm_contact_commerce_rollups' AND a.attname = ? AND a.attnum > 0 AND NOT a.attisdropped",
            [$colName]
        );
        expect($col)->not->toBeNull("Column $colName should exist")
            ->and($col->typname)->toBe('int8', "Column $colName should be int8 (bigint)");
    }
});

it('creates no trigger, no new role, no additional object', function () {
    // No triggers on the rollup table
    $triggers = DB::select(<<<'SQL'
        SELECT tgname FROM pg_trigger
        JOIN pg_class ON pg_trigger.tgrelid = pg_class.oid
        WHERE pg_class.relname = 'crm_contact_commerce_rollups'
          AND NOT pg_trigger.tgisinternal
    SQL);
    expect($triggers)->toBeEmpty();

    // No new role created by 000022
    // digitrove_crm_executor was created by 000020, not 000022
    // Verify no role like 'digitrove_rollup_*' etc exists
    $suspectRoles = DB::select("SELECT rolname FROM pg_roles WHERE rolname LIKE 'digitrove_rollup%'");
    expect($suspectRoles)->toBeEmpty();
});

it('verifies the refresh function exists with correct signature and attributes', function () {
    $func = DB::selectOne(<<<'SQL'
        SELECT 
            p.proname,
            p.prosecdef AS is_security_definer,
            p.proconfig AS config,
            r.rolname AS owner
        FROM pg_proc p
        JOIN pg_roles r ON p.proowner = r.oid
        WHERE p.proname = 'refresh_crm_contact_commerce_rollup'
    SQL);

    expect($func)->not->toBeNull()
        ->and($func->is_security_definer)->toBeTrue()
        ->and($func->owner)->toBe('digitrove_crm_executor');

    // Verify search_path is fixed
    $config = $func->config;
    expect($config)->toContain('search_path=public, pg_temp');
});
