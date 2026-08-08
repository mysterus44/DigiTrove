<?php

declare(strict_types=1);

use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\RollupRefreshFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-A1.3 concurrency: a single active run, batch serialisation on the run row, and
 * strict independence from the live P6-A1.2 signals.
 */
function p6a13SecondConnection(): PDO
{
    $cfg = config('database.connections.pgsql_migration');

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $cfg['host'], $cfg['port'] ?? 5432, Fx::owner()->getDatabaseName()),
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

it('refuses a second concurrent start while a run is active', function () {
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], Fx::contact());
    Fx::owner()->selectOne('SELECT * FROM start_crm_commerce_rollup_backfill(50)');

    $second = p6a13SecondConnection();
    $blocked = false;
    try {
        $second->query('SELECT * FROM start_crm_commerce_rollup_backfill(50)')->fetchAll();
    } catch (PDOException) {
        $blocked = true;
    }
    $second = null;

    expect($blocked)->toBeTrue()
        ->and((int) Fx::owner()->selectOne('SELECT COUNT(*) AS c FROM crm_commerce_rollup_backfill_runs')->c)->toBe(1);
});

it('serialises two concurrent batches of the same run on the run row', function () {
    $c1 = Fx::contact();
    $c2 = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $c1);
    Fx::attribute(Fx::paidOrder(4000, 'XOF')['order'], $c2);

    $runId = (int) Fx::owner()->selectOne('SELECT * FROM start_crm_commerce_rollup_backfill(1)')->run_id;

    $connA = Fx::owner();
    $connB = p6a13SecondConnection();

    // A holds the run row exactly as process_batch does.
    $connA->beginTransaction();
    $connA->selectOne('SELECT 1 AS locked FROM crm_commerce_rollup_backfill_runs WHERE id = ? FOR UPDATE', [$runId]);

    // B must block on the same row rather than double-processing the batch.
    $connB->exec('SET statement_timeout TO 800');
    $blocked = false;
    try {
        $connB->query('SELECT * FROM process_crm_commerce_rollup_backfill_batch('.$runId.')')->fetchAll();
    } catch (PDOException) {
        $blocked = true;
    }
    expect($blocked)->toBeTrue();

    $connA->commit();

    // Once the lock is released, batches proceed normally and consume each pair once.
    $connB->exec('SET statement_timeout TO 0');
    $connB->query('SELECT * FROM process_crm_commerce_rollup_backfill_batch('.$runId.')')->fetchAll();
    $connB = null;

    expect((int) Fx::owner()->selectOne('SELECT enqueued_pairs_count AS c FROM crm_commerce_rollup_backfill_runs WHERE id = ?', [$runId])->c)->toBe(1);
});

// ── Contrat exact du high-water mark (borne, PAS un snapshot MVCC) ──────────────

it('covers every attribution that already existed when the run started (case 1)', function () {
    $c1 = Fx::contact();
    $c2 = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $c1);
    Fx::attribute(Fx::paidOrder(4000, 'USD')['order'], $c2);
    $before = [
        [$c1, 'XOF', (int) Fx::outbox($c1, 'XOF')->requested_generation],
        [$c2, 'USD', (int) Fx::outbox($c2, 'USD')->requested_generation],
    ];

    $runId = (int) Fx::owner()->selectOne('SELECT * FROM start_crm_commerce_rollup_backfill(50)')->run_id;
    Fx::owner()->selectOne('SELECT * FROM process_crm_commerce_rollup_backfill_batch(?)', [$runId]);

    // Every pair present at start satisfies order_id <= HWM and is enqueued exactly once.
    foreach ($before as [$contactId, $currency, $generation]) {
        expect((int) Fx::outbox($contactId, $currency)->requested_generation)->toBe($generation + 1);
    }
});

it('leaves a late attribution on a NEW order to the P6-A1.2 trigger (case 2)', function () {
    $existing = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $existing);

    $runId = (int) Fx::owner()->selectOne('SELECT * FROM start_crm_commerce_rollup_backfill(50)')->run_id;
    $hwm = (int) Fx::owner()->selectOne('SELECT attribution_order_id_high_water_mark AS m FROM crm_commerce_rollup_backfill_runs WHERE id = ?', [$runId])->m;

    // New order => order_id > HWM.
    $late = Fx::contact();
    $lateOrder = Fx::paidOrder(6000, 'XOF');
    expect($lateOrder['order'])->toBeGreaterThan($hwm);
    Fx::attribute($lateOrder['order'], $late);
    $lateGeneration = (int) Fx::outbox($late, 'XOF')->requested_generation;

    $result = Fx::owner()->selectOne('SELECT * FROM process_crm_commerce_rollup_backfill_batch(?)', [$runId]);

    // The backfill ignored it, but the durable trigger had already enqueued it: no loss.
    expect((int) $result->enqueued_in_batch)->toBe(1)
        ->and($lateGeneration)->toBeGreaterThanOrEqual(1)
        ->and((int) Fx::outbox($late, 'XOF')->requested_generation)->toBe($lateGeneration);
});

it('never loses a late attribution on an OLD order, whichever side of the cursor it lands (case 3)', function () {
    // Two contacts so the cursor can be positioned strictly between them.
    $first = Fx::contact();
    $second = Fx::contact();
    $firstOrder = Fx::paidOrder(5000, 'XOF');
    $secondOrder = Fx::paidOrder(4000, 'XOF');
    Fx::attribute($firstOrder['order'], $first);
    Fx::attribute($secondOrder['order'], $second);

    // An OLD order that exists but is not attributed yet: its id is below the HWM.
    $oldUnattributed = Fx::paidOrder(3000, 'XOF');
    $thirdOrder = Fx::paidOrder(2000, 'XOF');
    Fx::attribute($thirdOrder['order'], Fx::contact());

    $runId = (int) Fx::owner()->selectOne('SELECT * FROM start_crm_commerce_rollup_backfill(1)')->run_id;
    $hwm = (int) Fx::owner()->selectOne('SELECT attribution_order_id_high_water_mark AS m FROM crm_commerce_rollup_backfill_runs WHERE id = ?', [$runId])->m;
    expect($oldUnattributed['order'])->toBeLessThan($hwm);

    // Advance the cursor past the first contact.
    Fx::owner()->selectOne('SELECT * FROM process_crm_commerce_rollup_backfill_batch(?)', [$runId]);

    // A LATE attribution on that OLD order (order_id <= HWM) for a brand-new contact
    // whose key sorts BEHIND the cursor: the backfill may never revisit it.
    $lateContact = Fx::contact();
    Fx::attribute($oldUnattributed['order'], $lateContact);

    // The P6-A1.2 trigger enqueued it regardless — that is what makes the design safe.
    $lateOutbox = Fx::outbox($lateContact, 'XOF');
    expect($lateOutbox)->not->toBeNull()
        ->and((int) $lateOutbox->requested_generation)->toBeGreaterThanOrEqual(1);

    // Draining the durable pipeline yields the correct projection for that contact.
    Fx::process($lateContact, 'XOF');
    expect((int) Fx::rollup($lateContact, 'XOF')->gross_revenue_minor)->toBe(3000);
});

it('keeps double coverage (backfill + trigger) financially exact (case 4)', function () {
    $contactId = Fx::contact();
    $order = Fx::paidOrder(5000, 'XOF');
    Fx::attribute($order['order'], $contactId);          // trigger enqueue, generation 1

    $runId = (int) Fx::owner()->selectOne('SELECT * FROM start_crm_commerce_rollup_backfill(50)')->run_id;
    Fx::owner()->selectOne('SELECT * FROM process_crm_commerce_rollup_backfill_batch(?)', [$runId]);

    // The very same pair was covered twice: the generation simply grew.
    expect((int) Fx::outbox($contactId, 'XOF')->requested_generation)->toBe(2);

    Fx::process($contactId, 'XOF');
    $rollup = Fx::rollup($contactId, 'XOF');

    // No double counting: P6-A1.1 recomputed from Commerce.
    expect((int) $rollup->acquired_orders_count)->toBe(1)
        ->and((int) $rollup->gross_revenue_minor)->toBe(5000)
        ->and((int) $rollup->net_revenue_minor)->toBe(5000);
});

it('coexists with a live P6-A1.2 signal on the very same pair', function () {
    $contactId = Fx::contact();
    $order = Fx::paidOrder(5000, 'XOF');
    Fx::attribute($order['order'], $contactId);           // generation 1 (trigger)

    $runId = (int) Fx::owner()->selectOne('SELECT * FROM start_crm_commerce_rollup_backfill(50)')->run_id;

    // A refund succeeds mid-run: P6-A1.2 bumps the same pair concurrently.
    Fx::succeedRefund($order['order'], $order['payment'], 2000, 5000);  // generation 2
    expect((int) Fx::outbox($contactId, 'XOF')->requested_generation)->toBe(2);

    // The backfill enqueues the historical pair as well: generations simply add up,
    // and P6-A1.1 recomputes authoritatively — no double counting is possible.
    Fx::owner()->selectOne('SELECT * FROM process_crm_commerce_rollup_backfill_batch(?)', [$runId]);
    expect((int) Fx::outbox($contactId, 'XOF')->requested_generation)->toBe(3);

    Fx::process($contactId, 'XOF');
    $rollup = Fx::rollup($contactId, 'XOF');
    expect((int) $rollup->gross_revenue_minor)->toBe(5000)
        ->and((int) $rollup->refunded_amount_minor)->toBe(2000)
        ->and((int) $rollup->net_revenue_minor)->toBe(3000);
});

it('keeps a re-run idempotent in financial terms even though generations increase', function () {
    $contactId = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $contactId);

    // First backfill + drain.
    $firstRun = (int) Fx::owner()->selectOne('SELECT * FROM start_crm_commerce_rollup_backfill(50)')->run_id;
    Fx::owner()->selectOne('SELECT * FROM process_crm_commerce_rollup_backfill_batch(?)', [$firstRun]);
    Fx::process($contactId, 'XOF');
    $first = Fx::rollup($contactId, 'XOF');

    // A second explicit backfill re-enqueues the same pair.
    $secondRun = (int) Fx::owner()->selectOne('SELECT * FROM start_crm_commerce_rollup_backfill(50)')->run_id;
    Fx::owner()->selectOne('SELECT * FROM process_crm_commerce_rollup_backfill_batch(?)', [$secondRun]);
    Fx::process($contactId, 'XOF');
    $second = Fx::rollup($contactId, 'XOF');

    // The money is reconstructed from Commerce, so it is identical.
    expect((int) $second->acquired_orders_count)->toBe((int) $first->acquired_orders_count)
        ->and((int) $second->gross_revenue_minor)->toBe((int) $first->gross_revenue_minor)
        ->and((int) $second->refunded_amount_minor)->toBe((int) $first->refunded_amount_minor)
        ->and((int) $second->net_revenue_minor)->toBe((int) $first->net_revenue_minor);
});

it('keeps two currencies and two contacts independent within one run', function () {
    $c1 = Fx::contact();
    $c2 = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $c1);
    Fx::attribute(Fx::paidOrder(4000, 'USD')['order'], $c1);
    Fx::attribute(Fx::paidOrder(3000, 'XOF')['order'], $c2);

    $runId = (int) Fx::owner()->selectOne('SELECT * FROM start_crm_commerce_rollup_backfill(50)')->run_id;
    Fx::owner()->selectOne('SELECT * FROM process_crm_commerce_rollup_backfill_batch(?)', [$runId]);

    foreach ([[$c1, 'XOF'], [$c1, 'USD'], [$c2, 'XOF']] as [$contactId, $currency]) {
        Fx::process($contactId, $currency);
        expect(Fx::rollup($contactId, $currency))->not->toBeNull();
    }

    expect((int) Fx::rollup($c1, 'XOF')->gross_revenue_minor)->toBe(5000)
        ->and((int) Fx::rollup($c1, 'USD')->gross_revenue_minor)->toBe(4000)
        ->and((int) Fx::rollup($c2, 'XOF')->gross_revenue_minor)->toBe(3000);
});

it('never lets a failed batch leave a partial cursor, counter or enqueue', function () {
    $contactId = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $contactId);
    $runId = (int) Fx::owner()->selectOne('SELECT * FROM start_crm_commerce_rollup_backfill(50)')->run_id;
    $generationBefore = (int) Fx::outbox($contactId, 'XOF')->requested_generation;

    // Make the enqueue authority fail from underneath the batch with a temporary CHECK
    // on the outbox. NO trigger is ever disabled and no ACL is weakened: the constraint
    // is added and dropped by the table's own owner.
    Fx::owner()->statement('SET ROLE digitrove_crm_executor');
    try {
        // NOT VALID: existing rows are left alone, but the batch's own UPSERT is refused.
        Fx::owner()->statement("ALTER TABLE public.crm_commerce_rollup_refresh_outbox ADD CONSTRAINT p6a13_force_failure CHECK (currency <> 'XOF') NOT VALID");
        $result = Fx::owner()->selectOne('SELECT * FROM process_crm_commerce_rollup_backfill_batch(?)', [$runId]);

        expect($result->status)->toBe('failed');

        $run = Fx::owner()->selectOne('SELECT * FROM crm_commerce_rollup_backfill_runs WHERE id = ?', [$runId]);
        expect($run->cursor_contact_id)->toBeNull()
            ->and($run->cursor_currency)->toBeNull()
            ->and((int) $run->batches_processed_count)->toBe(0)
            ->and((int) $run->enqueued_pairs_count)->toBe(0)
            ->and($run->last_error_code)->not->toBeNull()
            // No partial enqueue survived the rolled-back subtransaction.
            ->and((int) Fx::outbox($contactId, 'XOF')->requested_generation)->toBe($generationBefore);
    } finally {
        Fx::owner()->statement('ALTER TABLE public.crm_commerce_rollup_refresh_outbox DROP CONSTRAINT IF EXISTS p6a13_force_failure');
        Fx::owner()->statement('RESET ROLE');
    }

    // The failed run is then resumable only through an explicit retry.
    expect(Fx::owner()->selectOne('SELECT * FROM retry_crm_commerce_rollup_backfill_run(?)', [$runId])->status)->toBe('ready');
    expect(Fx::owner()->selectOne('SELECT * FROM process_crm_commerce_rollup_backfill_batch(?)', [$runId])->status)->toBe('completed');
    expect((int) Fx::outbox($contactId, 'XOF')->requested_generation)->toBe($generationBefore + 1);
});
