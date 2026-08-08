<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\RollupRefreshFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-A1.3 durable run authority: start (bounded high-water mark, single active run),
 * transactional batches, durable resumable cursor, completion, explicit retry.
 */
function p6a13RunOwner(): Connection
{
    return DB::connection('pgsql_migration');
}

function p6a13Start(int $batchSize = 50): object
{
    return p6a13RunOwner()->selectOne('SELECT * FROM start_crm_commerce_rollup_backfill(?)', [$batchSize]);
}

function p6a13Batch(int $runId): object
{
    return p6a13RunOwner()->selectOne('SELECT * FROM process_crm_commerce_rollup_backfill_batch(?)', [$runId]);
}

function p6a13Run(int $runId): ?object
{
    return p6a13RunOwner()->selectOne('SELECT * FROM get_crm_commerce_rollup_backfill_run(?)', [$runId]);
}

function p6a13Retry(int $runId): object
{
    return p6a13RunOwner()->selectOne('SELECT * FROM retry_crm_commerce_rollup_backfill_run(?)', [$runId]);
}

// ── Start ───────────────────────────────────────────────────────────────────────

it('starts a run that freezes the high-water mark without scanning or enqueuing anything', function () {
    $contactId = Fx::contact();
    $order = Fx::paidOrder(5000, 'XOF');
    Fx::attribute($order['order'], $contactId);
    $generationBefore = (int) Fx::outbox($contactId, 'XOF')->requested_generation;

    $run = p6a13Start(50);

    expect((int) $run->attribution_order_id_high_water_mark)->toBe($order['order'])
        ->and((int) $run->batch_size)->toBe(50)
        ->and($run->status)->toBe('ready')
        // Starting a run enqueues nothing on its own.
        ->and((int) Fx::outbox($contactId, 'XOF')->requested_generation)->toBe($generationBefore);
});

it('freezes a zero high-water mark when no attribution exists', function () {
    expect((int) p6a13Start(50)->attribution_order_id_high_water_mark)->toBe(0);
});

it('refuses a second active run and an out-of-range batch size', function () {
    p6a13Start(50);

    expect(fn () => p6a13Start(50))->toThrow(QueryException::class);
    expect(fn () => p6a13RunOwner()->selectOne('SELECT * FROM start_crm_commerce_rollup_backfill(0)'))->toThrow(QueryException::class);
    expect(fn () => p6a13RunOwner()->selectOne('SELECT * FROM start_crm_commerce_rollup_backfill(101)'))->toThrow(QueryException::class);
});

// ── Batches ─────────────────────────────────────────────────────────────────────

it('enqueues each historical pair exactly once and completes the run', function () {
    $c1 = Fx::contact();
    $c2 = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $c1);
    Fx::attribute(Fx::paidOrder(4000, 'USD')['order'], $c1);
    Fx::attribute(Fx::paidOrder(3000, 'XOF')['order'], $c2);

    $before = [
        [$c1, 'XOF', (int) Fx::outbox($c1, 'XOF')->requested_generation],
        [$c1, 'USD', (int) Fx::outbox($c1, 'USD')->requested_generation],
        [$c2, 'XOF', (int) Fx::outbox($c2, 'XOF')->requested_generation],
    ];

    $runId = (int) p6a13Start(50)->run_id;
    $result = p6a13Batch($runId);

    expect($result->status)->toBe('completed')
        ->and((int) $result->enqueued_in_batch)->toBe(3)
        ->and((int) $result->enqueued_pairs_count)->toBe(3);

    // Each pair received exactly one additional generation.
    foreach ($before as [$contactId, $currency, $generation]) {
        expect((int) Fx::outbox($contactId, $currency)->requested_generation)->toBe($generation + 1);
    }
});

it('advances a durable cursor across bounded batches and resumes exactly where it stopped', function () {
    $c1 = Fx::contact();
    $c2 = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $c1);
    Fx::attribute(Fx::paidOrder(4000, 'USD')['order'], $c1);
    Fx::attribute(Fx::paidOrder(3000, 'XOF')['order'], $c2);

    $runId = (int) p6a13Start(1)->run_id;

    $first = p6a13Batch($runId);
    expect($first->status)->toBe('ready')
        ->and((int) $first->enqueued_in_batch)->toBe(1);
    $afterFirst = p6a13Run($runId);
    expect((int) $afterFirst->cursor_contact_id)->toBe($c1)
        ->and($afterFirst->cursor_currency)->toBe('USD')
        ->and((int) $afterFirst->batches_processed_count)->toBe(1);

    $second = p6a13Batch($runId);
    expect((int) $second->enqueued_in_batch)->toBe(1);
    $afterSecond = p6a13Run($runId);
    expect((int) $afterSecond->cursor_contact_id)->toBe($c1)
        ->and($afterSecond->cursor_currency)->toBe('XOF');

    $third = p6a13Batch($runId);
    expect((int) $third->enqueued_in_batch)->toBe(1);
    $afterThird = p6a13Run($runId);
    expect((int) $afterThird->cursor_contact_id)->toBe($c2)
        ->and($afterThird->cursor_currency)->toBe('XOF')
        ->and((int) $afterThird->enqueued_pairs_count)->toBe(3)
        ->and((int) $afterThird->batches_processed_count)->toBe(3);

    // A run whose last batch was full needs one more (empty) batch to complete.
    $fourth = p6a13Batch($runId);
    expect($fourth->status)->toBe('completed')
        ->and((int) $fourth->enqueued_in_batch)->toBe(0);
});

it('completes immediately when there is no historical candidate at all', function () {
    $runId = (int) p6a13Start(50)->run_id;

    expect(p6a13Batch($runId)->status)->toBe('completed');
    expect(p6a13Run($runId)->status)->toBe('completed');
});

it('is a no-op once the run is completed', function () {
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], Fx::contact());
    $runId = (int) p6a13Start(50)->run_id;
    p6a13Batch($runId);

    $again = p6a13Batch($runId);
    expect($again->status)->toBe('completed')
        ->and((int) $again->enqueued_in_batch)->toBe(0);
});

it('ignores an attribution on a new order above the frozen high-water mark', function () {
    $before = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $before);

    $runId = (int) p6a13Start(50)->run_id;

    // New attribution above the HWM: P6-A1.2's trigger already handled it.
    $after = Fx::contact();
    Fx::attribute(Fx::paidOrder(6000, 'XOF')['order'], $after);
    $afterGeneration = (int) Fx::outbox($after, 'XOF')->requested_generation;

    $result = p6a13Batch($runId);

    expect((int) $result->enqueued_in_batch)->toBe(1)
        ->and((int) Fx::outbox($after, 'XOF')->requested_generation)->toBe($afterGeneration);
});

// ── Errors / retry ──────────────────────────────────────────────────────────────

it('reports a missing run and an invalid identifier without mutating anything', function () {
    expect(p6a13Batch(999999)->status)->toBe('not_found')
        ->and(p6a13Retry(999999)->status)->toBe('not_found')
        ->and(p6a13Run(999999))->toBeNull();

    expect(fn () => p6a13Batch(0))->toThrow(QueryException::class);
});

it('retries a failed run only when it is explicitly failed', function () {
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], Fx::contact());
    $runId = (int) p6a13Start(50)->run_id;

    // A ready run is never silently reset by a retry.
    expect(p6a13Retry($runId)->status)->toBe('ready');

    // Force the failed state through the authority-owned table, then retry explicitly.
    p6a13RunOwner()->update(
        "UPDATE crm_commerce_rollup_backfill_runs SET status = 'failed', failed_at = NOW(), started_at = NOW(), last_error_code = '40001' WHERE id = ?",
        [$runId],
    );
    expect(p6a13Run($runId)->status)->toBe('failed');

    expect(p6a13Retry($runId)->status)->toBe('ready');
    $resumed = p6a13Run($runId);
    expect($resumed->status)->toBe('ready')
        ->and($resumed->last_error_code)->toBe('40001');

    // And it can then continue normally.
    expect(p6a13Batch($runId)->status)->toBe('completed');
});

it('stores only a SQLSTATE code and never a raw error message', function () {
    $columns = array_map(
        static fn (object $c): string => $c->column_name,
        p6a13RunOwner()->select("SELECT column_name FROM information_schema.columns WHERE table_name = 'crm_commerce_rollup_backfill_runs'"),
    );

    expect($columns)->toContain('last_error_code')
        ->and($columns)->not->toContain('last_error_message')
        ->and($columns)->not->toContain('error_message')
        ->and($columns)->not->toContain('exception')
        ->and($columns)->not->toContain('stack_trace');

    // The column physically cannot hold a message: it is a 5-character SQLSTATE.
    expect(fn () => p6a13RunOwner()->update(
        "UPDATE crm_commerce_rollup_backfill_runs SET last_error_code = 'could not serialize access' WHERE id = ?",
        [(int) p6a13Start(50)->run_id],
    ))->toThrow(QueryException::class);
});
