<?php

declare(strict_types=1);

use PDO;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\RollupRefreshFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * Proves the P6-A1.2 no-lost-generation guarantee. The outbox row lock that process
 * takes first is the serialization point: an enqueue arriving while a row is being
 * processed blocks on that lock, then applies afterwards, so requested_generation
 * always ends strictly ahead of the just-processed generation.
 */
function p6a12SecondConnection(): PDO
{
    $cfg = config('database.connections.pgsql_migration');
    $dbName = Fx::owner()->getDatabaseName();

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $cfg['host'], $cfg['port'] ?? 5432, $dbName),
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

it('never loses a generation raised by a sequential later event', function () {
    $contactId = Fx::contact();
    $order = Fx::paidOrder(5000, 'XOF');
    Fx::attribute($order['order'], $contactId);          // requested 1

    expect(Fx::process($contactId, 'XOF')->status)->toBe('refreshed');
    expect((int) Fx::outbox($contactId, 'XOF')->processed_generation)->toBe(1);

    Fx::succeedRefund($order['order'], $order['payment'], 2000, 5000); // requested 2
    $row = Fx::outbox($contactId, 'XOF');
    expect((int) $row->requested_generation)->toBe(2)
        ->and((int) $row->processed_generation)->toBe(1);

    expect(Fx::process($contactId, 'XOF')->status)->toBe('refreshed');
    expect((int) Fx::outbox($contactId, 'XOF')->processed_generation)->toBe(2);
});

it('serialises an enqueue that arrives while the row is being processed (generation 4 then 5)', function () {
    $contactId = Fx::contact();
    $order = Fx::paidOrder(5000, 'XOF');
    Fx::attribute($order['order'], $contactId);

    // Fast-forward requested to 4 without losing coherence.
    Fx::owner()->update(
        'UPDATE crm_commerce_rollup_refresh_outbox SET requested_generation = 4 WHERE contact_id = ? AND currency = ?',
        [$contactId, 'XOF'],
    );

    $connA = Fx::owner();
    $connB = p6a12SecondConnection();

    // A takes the row lock exactly as process() does, observing generation 4.
    $connA->beginTransaction();
    $observed = $connA->selectOne(
        'SELECT requested_generation FROM crm_commerce_rollup_refresh_outbox WHERE contact_id = ? AND currency = ? FOR UPDATE',
        [$contactId, 'XOF'],
    );
    expect((int) $observed->requested_generation)->toBe(4);

    // B (a concurrent refund's enqueue → generation 5) must block on the same row.
    $connB->exec('SET statement_timeout TO 800');
    $blocked = false;
    try {
        $stmt = $connB->prepare('SELECT public.enqueue_crm_commerce_rollup_refresh(?, ?)');
        $stmt->execute([$contactId, 'XOF']);
    } catch (PDOException $e) {
        $blocked = true;
    }
    expect($blocked)->toBeTrue();

    // A finishes processing generation 4 and commits.
    $connA->update(
        'UPDATE crm_commerce_rollup_refresh_outbox SET processed_generation = 4, updated_at = NOW() WHERE contact_id = ? AND currency = ?',
        [$contactId, 'XOF'],
    );
    $connA->commit();

    // Only now does B's enqueue apply — generation 5 is preserved, never collapsed to 4.
    $connB->exec('SET statement_timeout TO 0');
    $stmt = $connB->prepare('SELECT public.enqueue_crm_commerce_rollup_refresh(?, ?)');
    $stmt->execute([$contactId, 'XOF']);
    $connB = null;

    $final = Fx::outbox($contactId, 'XOF');
    expect((int) $final->processed_generation)->toBe(4)
        ->and((int) $final->requested_generation)->toBe(5);
});

it('does not cross-block distinct currencies of the same contact', function () {
    $contactId = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $contactId);
    Fx::attribute(Fx::paidOrder(4000, 'USD')['order'], $contactId);

    $connA = Fx::owner();
    $connB = p6a12SecondConnection();

    // Lock the XOF row; a USD enqueue on B is a different row and must NOT block.
    $connA->beginTransaction();
    $connA->selectOne(
        'SELECT 1 AS locked FROM crm_commerce_rollup_refresh_outbox WHERE contact_id = ? AND currency = ? FOR UPDATE',
        [$contactId, 'XOF'],
    );

    $connB->exec('SET statement_timeout TO 800');
    $stmt = $connB->prepare('SELECT public.enqueue_crm_commerce_rollup_refresh(?, ?)');
    $stmt->execute([$contactId, 'USD']);   // completes without blocking
    $connB = null;

    $connA->commit();

    expect((int) Fx::outbox($contactId, 'USD')->requested_generation)->toBe(2);
});
