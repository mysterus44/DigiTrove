<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

/*
|--------------------------------------------------------------------------
| H1 — machine à états de la réconciliation (migration 000036)
|--------------------------------------------------------------------------
|
| Real PostgreSQL, and every transition below runs under `digitrove_runtime` —
| the same role production uses. That is the point: a test that simulated the
| refusal in PHP would prove nothing about what the database actually enforces.
| The pattern is P4-B0's.
|
*/

/** Prove the assertions below run under the runtime role, not the owner. */
function h1CurrentRole(): string
{
    return (string) DB::selectOne('SELECT current_user AS role')->role;
}

/**
 * Insert an event in a chosen state, through the OWNER — fixtures are setup, not the
 * behaviour under test. The runtime role does the transitions.
 */
function h1Event(string $status = 'received', bool $signed = true, array $overrides = []): int
{
    $now = now();

    $row = array_merge([
        'provider' => 'cinetpay',
        'external_event_id' => $signed ? 'evt_'.Str::random(24) : null,
        'payment_id' => null,
        'event_type' => 'PAYMENT',
        'payload_hash' => hash('sha256', Str::random(32)),
        'filtered_payload' => $signed ? json_encode(['cpm_trans_id' => 'x']) : null,
        'signature_verified' => $signed,
        'processing_status' => $status,
        'received_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    if ($status === 'processed' || $status === 'ignored') {
        $row['processed_at'] ??= $now;
    }

    if ($status === 'failed') {
        $row['failed_at'] ??= $now;
        $row['processing_error_sanitized'] ??= 'Provider verification could not be completed.';
    }

    return (int) DB::connection('pgsql_migration')
        ->table('payment_webhook_events')
        ->insertGetId($row);
}

/** Expire an event the way the reconciliation command will, under the runtime role. */
function h1Expire(int $id, string $from): void
{
    DB::table('payment_webhook_events')->where('id', $id)->update([
        'processing_status' => 'unresolved_expired',
        'expired_at' => now(),
        'expired_from_status' => $from,
        'updated_at' => now(),
    ]);
}

it('runs these transitions under the restricted runtime role', function (): void {
    expect(h1CurrentRole())->toBe('digitrove_runtime');
});

/*
|--------------------------------------------------------------------------
| The two new edges, and nothing else
|--------------------------------------------------------------------------
*/

it('closes out a `received` event that was never resolved', function (): void {
    // The P3-D3 window: the provider reference only exists locally after the second
    // initiation transaction, so a webhook arriving in between finds nothing.
    $id = h1Event('received');

    h1Expire($id, 'received');

    $row = DB::table('payment_webhook_events')->find($id);
    expect($row->processing_status)->toBe('unresolved_expired')
        ->and($row->expired_from_status)->toBe('received')
        ->and($row->expired_at)->not->toBeNull()
        // Nothing was ever processed, so no processing date may appear.
        ->and($row->processed_at)->toBeNull()
        ->and($row->failed_at)->toBeNull();
});

it('closes out a `failed` event and KEEPS the failure date', function (): void {
    // The counter-call failed. `failed_at` is what says the provider was unreachable, and
    // the immutability trigger freezes it — expiring the row must carry it through, not
    // erase the only evidence of why it never completed.
    $id = h1Event('failed');
    $failedAt = DB::table('payment_webhook_events')->find($id)->failed_at;

    h1Expire($id, 'failed');

    $row = DB::table('payment_webhook_events')->find($id);
    expect($row->processing_status)->toBe('unresolved_expired')
        ->and($row->expired_from_status)->toBe('failed')
        ->and($row->failed_at)->toBe($failedAt);
});

/*
|--------------------------------------------------------------------------
| The decisional terminals stay final — no exception
|--------------------------------------------------------------------------
*/

it('refuses every transition out of `processed`, which means money was confirmed', function (string $target): void {
    $id = h1Event('processed');

    expect(fn () => DB::table('payment_webhook_events')->where('id', $id)->update([
        'processing_status' => $target,
        'expired_at' => now(),
        'expired_from_status' => 'received',
    ]))->toThrow(QueryException::class);

    expect(DB::table('payment_webhook_events')->find($id)->processing_status)->toBe('processed');
})->with(['unresolved_expired', 'received', 'failed', 'ignored']);

it('refuses every transition out of `ignored`, which means a deliberate rejection', function (string $target): void {
    $id = h1Event('ignored');

    expect(fn () => DB::table('payment_webhook_events')->where('id', $id)->update([
        'processing_status' => $target,
        'expired_at' => now(),
        'expired_from_status' => 'received',
    ]))->toThrow(QueryException::class);

    expect(DB::table('payment_webhook_events')->find($id)->processing_status)->toBe('ignored');
})->with(['unresolved_expired', 'received', 'processed', 'failed']);

it('opens exactly ONE edge out of `failed` — not processed, not ignored', function (string $target): void {
    // The relaxation is targeted. `failed` may be closed out as expired; it may NOT be
    // retroactively turned into a decision that was never taken.
    $id = h1Event('failed');

    expect(fn () => DB::table('payment_webhook_events')->where('id', $id)->update([
        'processing_status' => $target,
        'processed_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(DB::table('payment_webhook_events')->find($id)->processing_status)->toBe('failed');
})->with(['processed', 'ignored', 'received']);

it('makes `unresolved_expired` itself terminal', function (string $target): void {
    $id = h1Event('received');
    h1Expire($id, 'received');

    expect(fn () => DB::table('payment_webhook_events')->where('id', $id)->update([
        'processing_status' => $target,
    ]))->toThrow(QueryException::class);
})->with(['received', 'processed', 'ignored', 'failed']);

/*
|--------------------------------------------------------------------------
| The provenance cannot be forged or rewritten
|--------------------------------------------------------------------------
*/

it('refuses an expiry that claims the wrong provenance', function (): void {
    // A `received` event has no `failed_at`, so claiming it came from `failed` is a lie the
    // CHECK catches in both directions.
    $id = h1Event('received');

    expect(fn () => h1Expire($id, 'failed'))->toThrow(QueryException::class);
});

it('refuses an expiry from a state that cannot expire', function (string $from): void {
    $id = h1Event('received');

    expect(fn () => h1Expire($id, $from))->toThrow(QueryException::class);
})->with(['processed', 'ignored', 'unresolved_expired', 'anything']);

it('freezes the provenance once written', function (): void {
    $id = h1Event('failed');
    h1Expire($id, 'failed');

    // Relabelling would destroy the only diagnostic this state carries.
    expect(fn () => DB::table('payment_webhook_events')->where('id', $id)->update([
        'expired_from_status' => 'received',
    ]))->toThrow(QueryException::class);
});

it('freezes the expiry date once stamped', function (): void {
    $id = h1Event('received');
    h1Expire($id, 'received');

    expect(fn () => DB::table('payment_webhook_events')->where('id', $id)->update([
        'expired_at' => now()->addDay(),
    ]))->toThrow(QueryException::class);
});

it('refuses a provenance on any state that is not expired', function (): void {
    $id = h1Event('received');

    expect(fn () => DB::table('payment_webhook_events')->where('id', $id)->update([
        'expired_from_status' => 'received',
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| An unsigned event can never expire, and that is deliberate
|--------------------------------------------------------------------------
*/

it('cannot expire an event whose signature never verified', function (): void {
    // `payment_webhook_events_invalid_minimal_shape_check` (000005) constrains an unsigned
    // event to stay `failed`. That CHECK is left INTACT: such a webhook did not "fail to be
    // processed", it was DELIBERATELY REJECTED. Conflating the two would erase the very
    // distinction this gate exists to preserve — so the reconciliation only ever looks at
    // signed events.
    $id = h1Event('failed', signed: false, overrides: [
        'event_type' => null,
        'processing_error_sanitized' => 'Webhook signature verification failed.',
    ]);

    expect(fn () => h1Expire($id, 'failed'))->toThrow(QueryException::class);

    expect(DB::table('payment_webhook_events')->find($id)->processing_status)->toBe('failed');
});

/*
|--------------------------------------------------------------------------
| The migration itself
|--------------------------------------------------------------------------
*/

it('applies 000036 with the new state, the two columns and the partial index', function (): void {
    $constraint = DB::connection('pgsql_migration')->selectOne(<<<'SQL'
        SELECT pg_get_constraintdef(oid) AS def
        FROM pg_constraint
        WHERE conname = 'payment_webhook_events_processing_status_check'
        SQL);

    expect($constraint->def)->toContain('unresolved_expired')
        // The four original states remain valid: this is an addition, never a removal.
        ->toContain('received')->toContain('processed')
        ->toContain('ignored')->toContain('failed');

    $columns = DB::connection('pgsql_migration')->select(<<<'SQL'
        SELECT attname FROM pg_attribute
        WHERE attrelid = 'public.payment_webhook_events'::regclass
          AND attname IN ('expired_at', 'expired_from_status') AND NOT attisdropped
        SQL);

    expect($columns)->toHaveCount(2);

    $index = DB::connection('pgsql_migration')->selectOne(<<<'SQL'
        SELECT indexdef FROM pg_indexes
        WHERE indexname = 'payment_webhook_events_reconciliation_index'
        SQL);

    expect($index?->indexdef)->toContain('received')->toContain('failed');
});

it('counts 52 migrations, names the last one, and has no 000037', function (): void {
    $files = collect(File::files(database_path('migrations')))
        ->map(fn ($file): string => $file->getFilename())
        ->values()
        ->all();

    sort($files);

    expect($files)->toHaveCount(52)
        ->and(end($files))->toBe('2026_07_14_000036_create_webhook_reconciliation_state.php')
        ->and(collect($files)->filter(fn (string $f): bool => str_contains($f, '000037')))->toBeEmpty();
});
