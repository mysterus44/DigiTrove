<?php

use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

/*
|--------------------------------------------------------------------------
| H1 — frontière de rollback de 000036
|--------------------------------------------------------------------------
|
| ⚠️ ROLLBACK LOSSLESS-ONLY. Redescendre supprime `expired_from_status`, la seule
| chose qui distingue « jamais résolu » de « fournisseur injoignable » sur des
| lignes d'audit financier. Le refus est donc la PREMIÈRE opération, avant tout
| DROP — la leçon de P6-D1, désormais une règle. Après un vrai run de
| réconciliation, ce refus est le cas NORMALEMENT ATTENDU, pas un défaut.
|
*/

const H1_MIGRATION = '2026_07_14_000036_create_webhook_reconciliation_state.php';

/** Insert a signed webhook event on the harness (owner) connection. */
function h1RollbackEvent(PhaseMigrationHarness $harness, string $status): int
{
    $now = now()->toDateTimeString();
    $pdo = $harness->ownerPdo();

    $columns = [
        'provider' => 'cinetpay',
        'external_event_id' => 'evt_'.Str::random(24),
        'event_type' => 'PAYMENT',
        'payload_hash' => hash('sha256', Str::random(32)),
        'filtered_payload' => json_encode(['cpm_trans_id' => 'x']),
        'signature_verified' => 'true',
        'processing_status' => $status,
        'received_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    if ($status === 'failed') {
        $columns['failed_at'] = $now;
        $columns['processing_error_sanitized'] = 'Provider verification could not be completed.';
    }

    $names = implode(', ', array_keys($columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));

    $statement = $pdo->prepare("INSERT INTO payment_webhook_events ($names) VALUES ($placeholders) RETURNING id");
    $statement->execute(array_values($columns));

    return (int) $statement->fetchColumn();
}

/** @return array<int, array<string, mixed>> */
function h1RollbackQuery(PhaseMigrationHarness $harness, string $sql): array
{
    return $harness->ownerPdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

it('rolls back 000036 alone while nothing has expired, restoring the exact 000005 boundary', function () {
    $harness = new PhaseMigrationHarness('digitrove_h1_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough(H1_MIGRATION);

        expect($applied)->toHaveCount(52)
            ->and(end($applied))->toBe('2026_07_14_000036_create_webhook_reconciliation_state');

        expect($harness->rollbackExactMigrations([H1_MIGRATION]))->toBe([
            '2026_07_14_000036_create_webhook_reconciliation_state',
        ]);

        $constraint = h1RollbackQuery($harness, <<<'SQL'
            SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint
            WHERE conname = 'payment_webhook_events_processing_status_check'
            SQL)[0]['def'];

        // The new state is gone; the four original ones are back exactly as 000005 had them.
        expect($constraint)->not->toContain('unresolved_expired')
            ->toContain('received')->toContain('processed')
            ->toContain('ignored')->toContain('failed');

        expect(h1RollbackQuery($harness, <<<'SQL'
            SELECT attname FROM pg_attribute
            WHERE attrelid = 'public.payment_webhook_events'::regclass
              AND attname IN ('expired_at', 'expired_from_status') AND NOT attisdropped
            SQL))->toBeEmpty();

        expect(h1RollbackQuery($harness, <<<'SQL'
            SELECT indexname FROM pg_indexes
            WHERE indexname = 'payment_webhook_events_reconciliation_index'
            SQL))->toBeEmpty();

        // Everything the boundary must NOT have taken with it.
        expect($harness->hasTable('payment_webhook_events'))->toBeTrue()
            ->and($harness->hasTable('payments'))->toBeTrue()
            ->and($harness->hasTable('refunds'))->toBeTrue()
            ->and($harness->hasTable('download_logs'))->toBeTrue()
            ->and($harness->ranMigrations())->toHaveCount(51);
    } finally {
        $harness->drop();
    }
});

it('restores the 000005 state machine literally: failed becomes terminal again', function () {
    $harness = new PhaseMigrationHarness('digitrove_h1_machine_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $harness->applyMigrationsThrough(H1_MIGRATION);
        $harness->rollbackExactMigrations([H1_MIGRATION]);

        $id = h1RollbackEvent($harness, 'failed');

        // Under 000036 this edge existed for `unresolved_expired` only; after the rollback
        // `failed` is terminal for everything, exactly as 000005 wrote it.
        expect(fn () => $harness->ownerPdo()->exec(
            "UPDATE payment_webhook_events SET processing_status = 'ignored' WHERE id = $id"
        ))->toThrow(PDOException::class);
    } finally {
        $harness->drop();
    }
});

it('REFUSES the rollback once an event has expired, and mutates nothing at all', function () {
    $harness = new PhaseMigrationHarness('digitrove_h1_refuse_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $harness->applyMigrationsThrough(H1_MIGRATION);

        $id = h1RollbackEvent($harness, 'received');
        $harness->ownerPdo()->exec(<<<SQL
            UPDATE payment_webhook_events
            SET processing_status = 'unresolved_expired',
                expired_at = now(),
                expired_from_status = 'received'
            WHERE id = $id
            SQL);

        // `Throwable` is an INTERFACE, so `class_exists` refuses it and Pest silently
        // compares MESSAGES instead. Asserting on the message is better anyway: it proves
        // OUR guard refused, not that the rollback failed for some incidental reason.
        try {
            $harness->rollbackExactMigrations([H1_MIGRATION]);
            test()->fail('expected the rollback to be refused');
        } catch (Throwable $exception) {
            // ⚠️ The harness runs `artisan` as a subprocess, and Symfony's console frames
            // errors at a fixed width — it BREAKS WORDS mid-token, so "Rolling back" and
            // "Nothing has been modified" arrive split across lines. Asserting on the raw
            // message would pass or fail depending on terminal width.
            $message = (string) preg_replace('/\s+/', ' ', $exception->getMessage());

            expect($message)
                ->toContain('Refusing to roll back 000036')
                ->toContain('unresolved_expired')
                ->toContain('Nothing has been modified');
        }

        // The refusal is the FIRST operation: nothing was dropped, nothing was altered.
        expect(h1RollbackQuery($harness, <<<'SQL'
            SELECT attname FROM pg_attribute
            WHERE attrelid = 'public.payment_webhook_events'::regclass
              AND attname IN ('expired_at', 'expired_from_status') AND NOT attisdropped
            SQL))->toHaveCount(2);

        $constraint = h1RollbackQuery($harness, <<<'SQL'
            SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint
            WHERE conname = 'payment_webhook_events_processing_status_check'
            SQL)[0]['def'];

        expect($constraint)->toContain('unresolved_expired')
            // The audit row itself is untouched, provenance included — which is the whole
            // point: rolling back would have erased it.
            ->and(h1RollbackQuery($harness, "SELECT expired_from_status FROM payment_webhook_events WHERE id = $id")[0]['expired_from_status'])
            ->toBe('received')
            ->and($harness->ranMigrations())->toHaveCount(52);
    } finally {
        $harness->drop();
    }
});
