<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCrmDatabase;

uses(InteractsWithCrmDatabase::class);

function p6a13Schema(): Connection
{
    return DB::connection('pgsql_migration');
}

it('adds exactly one migration (000024) and no 000025', function () {
    $root = dirname(__DIR__, 2);

    expect(glob($root.'/database/migrations/*.php'))->toHaveCount(47)
        ->and(glob($root.'/database/migrations/2026_07_14_000024*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000032*.php'))->toBe([]);
});

it('creates the run table with exactly the durable audit columns and no PII', function () {
    $columns = array_map(
        static fn (object $c): string => $c->column_name,
        p6a13Schema()->select("SELECT column_name FROM information_schema.columns WHERE table_name = 'crm_commerce_rollup_backfill_runs'"),
    );
    sort($columns);

    expect($columns)->toBe([
        'attribution_order_id_high_water_mark', 'batch_size', 'batches_processed_count',
        'completed_at', 'created_at', 'cursor_contact_id', 'cursor_currency',
        'enqueued_pairs_count', 'failed_at', 'id', 'last_error_code', 'started_at',
        'status', 'updated_at',
    ]);

    foreach (['email', 'customer_email', 'name', 'phone', 'user_id', 'visitor_id', 'order_id', 'refund_id', 'payload', 'exception', 'stack_trace'] as $forbidden) {
        expect($columns)->not->toContain($forbidden);
    }
});

it('enforces the high-water mark, batch size, cursor pairing, counters and status invariants', function () {
    foreach ([
        'crm_commerce_rollup_backfill_runs_high_water_mark_check',
        'crm_commerce_rollup_backfill_runs_batch_size_check',
        'crm_commerce_rollup_backfill_runs_cursor_contact_check',
        'crm_commerce_rollup_backfill_runs_cursor_currency_check',
        'crm_commerce_rollup_backfill_runs_cursor_pairing_check',
        'crm_commerce_rollup_backfill_runs_batches_count_check',
        'crm_commerce_rollup_backfill_runs_pairs_count_check',
        'crm_commerce_rollup_backfill_runs_status_check',
        'crm_commerce_rollup_backfill_runs_error_code_check',
        'crm_commerce_rollup_backfill_runs_status_dates_check',
    ] as $constraint) {
        expect((bool) p6a13Schema()->selectOne(
            'SELECT EXISTS(SELECT 1 FROM pg_constraint WHERE conname = ?) AS present',
            [$constraint],
        )->present)->toBeTrue();
    }
});

it('rejects a half-null cursor, an out-of-range batch size and an unknown status', function () {
    $insert = static fn (string $columns, string $values): callable => static fn () => p6a13Schema()->statement(
        "INSERT INTO crm_commerce_rollup_backfill_runs ({$columns}, created_at, updated_at) VALUES ({$values}, NOW(), NOW())",
    );

    // cursor_contact_id without cursor_currency.
    expect($insert('attribution_order_id_high_water_mark, batch_size, cursor_contact_id, status', "1, 50, 7, 'ready'"))
        ->toThrow(QueryException::class);
    // batch_size out of range.
    expect($insert('attribution_order_id_high_water_mark, batch_size, status', "1, 101, 'ready'"))
        ->toThrow(QueryException::class);
    // unknown status.
    expect($insert('attribution_order_id_high_water_mark, batch_size, status', "1, 50, 'paused'"))
        ->toThrow(QueryException::class);
    // completed without completed_at.
    expect($insert('attribution_order_id_high_water_mark, batch_size, status', "1, 50, 'completed'"))
        ->toThrow(QueryException::class);
});

it('allows at most one active run through a partial unique index', function () {
    p6a13Schema()->statement(
        "INSERT INTO crm_commerce_rollup_backfill_runs (attribution_order_id_high_water_mark, batch_size, status, created_at, updated_at) VALUES (1, 50, 'ready', NOW(), NOW())",
    );

    expect(fn () => p6a13Schema()->statement(
        "INSERT INTO crm_commerce_rollup_backfill_runs (attribution_order_id_high_water_mark, batch_size, status, created_at, updated_at) VALUES (2, 50, 'ready', NOW(), NOW())",
    ))->toThrow(QueryException::class);

    // A completed run does not block a new active one.
    p6a13Schema()->statement(
        "UPDATE crm_commerce_rollup_backfill_runs SET status = 'completed', completed_at = NOW() WHERE status = 'ready'",
    );
    p6a13Schema()->statement(
        "INSERT INTO crm_commerce_rollup_backfill_runs (attribution_order_id_high_water_mark, batch_size, status, created_at, updated_at) VALUES (3, 50, 'ready', NOW(), NOW())",
    );

    expect((int) p6a13Schema()->selectOne('SELECT COUNT(*) AS c FROM crm_commerce_rollup_backfill_runs')->c)->toBe(2);
});

it('installs the six backfill authorities and creates no new trigger on commerce', function () {
    foreach ([
        'current_crm_commerce_rollup_backfill_high_water_mark',
        'list_crm_commerce_rollup_backfill_candidates',
        'start_crm_commerce_rollup_backfill',
        'get_crm_commerce_rollup_backfill_run',
        'process_crm_commerce_rollup_backfill_batch',
        'retry_crm_commerce_rollup_backfill_run',
    ] as $function) {
        expect((bool) p6a13Schema()->selectOne(
            'SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = ?) AS present',
            [$function],
        )->present)->toBeTrue();
    }

    // P6-A1.3 adds no trigger at all: it never reacts to commerce, it is operator-driven.
    expect((bool) p6a13Schema()->selectOne(
        "SELECT EXISTS(SELECT 1 FROM pg_trigger WHERE tgname LIKE '%backfill%') AS present",
    )->present)->toBeFalse();
});
