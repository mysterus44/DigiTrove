<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\RollupRefreshFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-A1.3 candidate source, bounded high-water mark and keyset pagination.
 *
 * The historical population is exactly `crm_order_attributions ⋈ acquired orders`.
 * No e-mail, resolver, User or Visitor is ever consulted.
 */
function p6a13Owner(): Connection
{
    return DB::connection('pgsql_migration');
}

function p6a13HighWaterMark(): int
{
    return (int) p6a13Owner()->selectOne(
        'SELECT COALESCE(MAX(order_id), 0) AS m FROM crm_order_attributions',
    )->m;
}

/** @return list<array{contact_id:int,currency:string}> */
function p6a13Candidates(int $highWaterMark, ?int $cursorContact = null, ?string $cursorCurrency = null, int $limit = 100): array
{
    $rows = p6a13Owner()->select(
        'SELECT * FROM list_crm_commerce_rollup_backfill_candidates(?, ?, ?, ?)',
        [$highWaterMark, $cursorContact, $cursorCurrency, $limit],
    );

    return array_map(
        static fn (object $r): array => ['contact_id' => (int) $r->contact_id, 'currency' => (string) $r->currency],
        $rows,
    );
}

// ── Source de vérité ────────────────────────────────────────────────────────────

it('lists an attributed paid order as one candidate pair', function () {
    $contactId = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $contactId);

    expect(p6a13Candidates(p6a13HighWaterMark()))
        ->toBe([['contact_id' => $contactId, 'currency' => 'XOF']]);
});

it('includes partially_refunded and refunded acquired orders', function () {
    $partial = Fx::contact();
    $full = Fx::contact();
    $a = Fx::paidOrder(5000, 'XOF');
    Fx::attribute($a['order'], $partial);
    Fx::succeedRefund($a['order'], $a['payment'], 2000, 5000);   // partially_refunded

    $b = Fx::paidOrder(4000, 'XOF');
    Fx::attribute($b['order'], $full);
    Fx::succeedRefund($b['order'], $b['payment'], 4000, 4000);   // refunded

    $pairs = p6a13Candidates(p6a13HighWaterMark());
    expect($pairs)->toContain(['contact_id' => $partial, 'currency' => 'XOF'])
        ->and($pairs)->toContain(['contact_id' => $full, 'currency' => 'XOF']);
});

it('includes a free acquired order', function () {
    $contactId = Fx::contact();
    Fx::attribute(Fx::paidOrder(0, 'XOF')['order'], $contactId);

    expect(p6a13Candidates(p6a13HighWaterMark()))
        ->toBe([['contact_id' => $contactId, 'currency' => 'XOF']]);
});

it('excludes an order that is not acquired and one without an attribution', function () {
    // Pending order (no paid_at, no succeeded payment) attributed to a contact.
    Fx::attribute(Fx::pendingOrder(1000, 'XOF'), Fx::contact());

    // Acquired order with NO attribution at all.
    Fx::paidOrder(7000, 'XOF');

    expect(p6a13Candidates(p6a13HighWaterMark()))->toBe([]);
});

it('collapses many orders of one contact and currency into a single pair', function () {
    $contactId = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $contactId);
    Fx::attribute(Fx::paidOrder(3000, 'XOF')['order'], $contactId);
    Fx::attribute(Fx::paidOrder(1000, 'XOF')['order'], $contactId);

    expect(p6a13Candidates(p6a13HighWaterMark()))
        ->toBe([['contact_id' => $contactId, 'currency' => 'XOF']]);
});

it('keeps two currencies of one contact as two distinct pairs', function () {
    $contactId = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $contactId);
    Fx::attribute(Fx::paidOrder(4000, 'USD')['order'], $contactId);

    expect(p6a13Candidates(p6a13HighWaterMark()))->toBe([
        ['contact_id' => $contactId, 'currency' => 'USD'],
        ['contact_id' => $contactId, 'currency' => 'XOF'],
    ]);
});

// ── High-water mark (bound, NOT an MVCC snapshot) ────────────────────────────────

it('bounds the run: an attribution on a NEW order above the high-water mark is excluded', function () {
    $before = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $before);
    $highWaterMark = p6a13HighWaterMark();

    // A later attribution on a NEW order (order_id > HWM) — captured by the P6-A1.2
    // trigger, not by this historical run.
    $after = Fx::contact();
    Fx::attribute(Fx::paidOrder(6000, 'XOF')['order'], $after);

    $pairs = p6a13Candidates($highWaterMark);
    expect($pairs)->toBe([['contact_id' => $before, 'currency' => 'XOF']]);

    // The newer pair is nevertheless already enqueued durably by P6-A1.2.
    expect(Fx::outbox($after, 'XOF'))->not->toBeNull();
});

it('returns nothing for a zero high-water mark', function () {
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], Fx::contact());

    expect(p6a13Candidates(0))->toBe([]);
});

// ── Keyset ──────────────────────────────────────────────────────────────────────

it('paginates by keyset without gaps or duplicates across batches of one', function () {
    $c1 = Fx::contact();
    $c2 = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $c1);
    Fx::attribute(Fx::paidOrder(4000, 'USD')['order'], $c1);
    Fx::attribute(Fx::paidOrder(3000, 'XOF')['order'], $c2);

    $highWaterMark = p6a13HighWaterMark();
    $seen = [];
    $cursorContact = null;
    $cursorCurrency = null;

    while (true) {
        $page = p6a13Candidates($highWaterMark, $cursorContact, $cursorCurrency, 1);
        if ($page === []) {
            break;
        }
        $seen[] = $page[0];
        $cursorContact = $page[0]['contact_id'];
        $cursorCurrency = $page[0]['currency'];
    }

    expect($seen)->toBe([
        ['contact_id' => $c1, 'currency' => 'USD'],
        ['contact_id' => $c1, 'currency' => 'XOF'],
        ['contact_id' => $c2, 'currency' => 'XOF'],
    ])->and($seen)->toHaveCount(count(array_unique(array_map('json_encode', $seen))));
});

it('rejects an invalid limit, high-water mark or half-null cursor', function () {
    $highWaterMark = p6a13HighWaterMark();

    expect(fn () => p6a13Candidates($highWaterMark, null, null, 0))->toThrow(QueryException::class);
    expect(fn () => p6a13Candidates($highWaterMark, null, null, 101))->toThrow(QueryException::class);
    expect(fn () => p6a13Candidates(-1))->toThrow(QueryException::class);
    expect(fn () => p6a13Candidates($highWaterMark, 1, null, 10))->toThrow(QueryException::class);
});

it('never mutates anything while listing candidates', function () {
    $contactId = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $contactId);
    $before = (int) Fx::outbox($contactId, 'XOF')->requested_generation;

    p6a13Candidates(p6a13HighWaterMark());

    expect((int) Fx::outbox($contactId, 'XOF')->requested_generation)->toBe($before)
        ->and((int) p6a13Owner()->selectOne('SELECT COUNT(*) AS c FROM crm_commerce_rollup_backfill_runs')->c)->toBe(0);
});
