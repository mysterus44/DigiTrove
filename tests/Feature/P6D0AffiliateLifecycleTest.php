<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\AffiliateFixtures as Aff;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-D0 lifecycle contract (§3 / §4).
 *
 * The question these tests answer is not "does the foreign key exist" but "what happens to
 * the MONEY when the thing it points at goes away". Deleting an identity must never delete
 * a financial fact, and a retention policy must never be permanently vetoed by this gate.
 */

// ── Nothing cascades ─────────────────────────────────────────────────────────────

it('cascades nothing: every affiliate foreign key restricts or nulls', function () {
    $rules = Fx::owner()->select(<<<'SQL'
        SELECT tc.constraint_name, tc.table_name, rc.delete_rule
        FROM information_schema.table_constraints AS tc
        JOIN information_schema.referential_constraints AS rc ON rc.constraint_name = tc.constraint_name
        WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name LIKE 'affiliate%'
        ORDER BY 1
        SQL);

    expect($rules)->not->toBeEmpty();

    foreach ($rules as $rule) {
        expect($rule->delete_rule)->toBeIn(
            ['NO ACTION', 'RESTRICT', 'SET NULL'],
            "{$rule->constraint_name} on {$rule->table_name} uses {$rule->delete_rule}",
        );
    }

    // Only NON-financial identity references may be nulled: a reviewer, a requester, an
    // approver, an author, and the subject of a touch. Nothing that carries an amount.
    $nullable = array_map(
        static fn (object $r): string => (string) $r->constraint_name,
        array_values(array_filter($rules, static fn (object $r): bool => $r->delete_rule === 'SET NULL')),
    );

    sort($nullable);

    // CURRENT-STATE. D-059 adds the ledger's ACTOR — an administrator who may one day be
    // erased. The transition itself survives with a null actor rather than the history
    // being deleted, and no amount is reachable from it.
    expect($nullable)->toBe([
        'affiliate_commission_entries_created_by_user_id_foreign',
        'affiliate_lifecycle_events_actor_user_id_foreign',
        'affiliate_payouts_approved_by_user_id_foreign',
        'affiliate_payouts_requested_by_user_id_foreign',
        'affiliate_touches_user_id_foreign',
        'affiliate_touches_visitor_id_foreign',
        'affiliates_reviewed_by_user_id_foreign',
    ]);
});

// ── Visitors: erasure stays possible, money stays intact ─────────────────────────

/**
 * The repository's own precedent decides this: `orders.visitor_id` and `carts.visitor_id`
 * are both `ON DELETE SET NULL`. A financial row never depends on a visitor surviving.
 */
it('matches the repository precedent for visitor references', function () {
    $rules = Fx::owner()->select(<<<'SQL'
        SELECT tc.table_name, rc.delete_rule
        FROM information_schema.table_constraints AS tc
        JOIN information_schema.referential_constraints AS rc ON rc.constraint_name = tc.constraint_name
        JOIN information_schema.constraint_column_usage AS ccu ON ccu.constraint_name = tc.constraint_name
        WHERE tc.constraint_type = 'FOREIGN KEY' AND ccu.table_name = 'visitors'
        ORDER BY 1
        SQL);

    $byTable = [];

    foreach ($rules as $rule) {
        $byTable[$rule->table_name] = $rule->delete_rule;
    }

    expect($byTable)->toBe([
        'affiliate_touches' => 'SET NULL',
        'carts' => 'SET NULL',
        'orders' => 'SET NULL',
    ]);
});

/**
 * The defect this closes: a CHECK requiring a subject would be re-evaluated by the SET
 * NULL update and would veto EVERY visitor erasure for ever. Anchoring is therefore an
 * INSERT-time rule, so a purge or anonymisation policy is never permanently blocked.
 */
it('lets a visitor be erased without destroying or blocking anything financial', function () {
    $affiliateId = Aff::affiliate(Aff::user(), 'active');
    $codeId = Aff::code($affiliateId, 'ERASE001');

    $visitorId = (string) Str::uuid();
    Fx::owner()->insert(
        'INSERT INTO visitors (id, first_seen_at, last_seen_at, created_at, updated_at) VALUES (?, now(), now(), now(), now())',
        [$visitorId],
    );

    $touchId = Aff::touch($affiliateId, $codeId, visitorId: $visitorId);

    // The erasure succeeds — it is not vetoed by the affiliate schema.
    Fx::owner()->delete('DELETE FROM visitors WHERE id = ?', [$visitorId]);

    $touch = Fx::owner()->selectOne('SELECT visitor_id, user_id, affiliate_id, affiliate_code_id FROM affiliate_touches WHERE id = ?', [$touchId]);

    // The touch SURVIVES, unanchored. It can now match nothing, which is the fail-closed
    // outcome — and it is still identifiable by its own non-PII columns.
    expect($touch)->not->toBeNull()
        ->and($touch->visitor_id)->toBeNull()
        ->and($touch->user_id)->toBeNull()
        ->and((int) $touch->affiliate_id)->toBe($affiliateId)
        ->and((int) $touch->affiliate_code_id)->toBe($codeId);
});

it('still refuses to CREATE a touch anchored to nobody', function () {
    $affiliateId = Aff::affiliate(Aff::user(), 'active');
    $codeId = Aff::code($affiliateId, 'ERASE002');

    expect(fn () => Fx::owner()->insert(
        "INSERT INTO affiliate_touches (affiliate_id, affiliate_code_id, source, occurred_at, expires_at, created_at, updated_at)
         VALUES (?, ?, 'link', now(), now() + interval '30 days', now(), now())",
        [$affiliateId, $codeId],
    ))->toThrow(QueryException::class);
});

/**
 * An attribution built from a touch keeps its OWN snapshots. Erasing the visitor behind
 * that touch must not make the sale unexplainable.
 */
it('keeps an attribution whole after its visitor is erased', function () {
    $ctx = Aff::attributableOrder();

    $visitorId = (string) Str::uuid();
    Fx::owner()->insert(
        'INSERT INTO visitors (id, first_seen_at, last_seen_at, created_at, updated_at) VALUES (?, now(), now(), now(), now())',
        [$visitorId],
    );
    $touchId = Aff::touch($ctx['affiliate_id'], $ctx['code_id'], visitorId: $visitorId);

    Fx::owner()->insert(
        "INSERT INTO affiliate_attributions (order_id, affiliate_id, affiliate_code_id, affiliate_touch_id, policy_id, matched_by, attributed_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'last_click', now(), now(), now())",
        [$ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $touchId, $ctx['policy_id']],
    );

    Fx::owner()->delete('DELETE FROM visitors WHERE id = ?', [$visitorId]);

    $attribution = Fx::owner()->selectOne('SELECT * FROM affiliate_attributions WHERE order_id = ?', [$ctx['order_id']]);

    expect($attribution)->not->toBeNull()
        ->and((int) $attribution->affiliate_id)->toBe($ctx['affiliate_id'])
        ->and((int) $attribution->affiliate_code_id)->toBe($ctx['code_id'])
        ->and((int) $attribution->policy_id)->toBe($ctx['policy_id'])
        ->and((int) $attribution->affiliate_touch_id)->toBe($touchId);
});

// ── Codes, touches, affiliates: history is never erased by a lifecycle change ────

it('deactivating a code never removes the touches that used it', function () {
    $affiliateId = Aff::affiliate(Aff::user(), 'active');
    $codeId = Aff::code($affiliateId, 'ROTATE01');
    $touchId = Aff::touch($affiliateId, $codeId, userId: Aff::user());

    // Deactivation is a state change with its own timestamp, never a delete.
    Fx::owner()->update('UPDATE affiliate_codes SET is_active = false, deactivated_at = now() WHERE id = ?', [$codeId]);

    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_touches WHERE id = ?', [$touchId])->c)->toBe(1);

    // And a code with history cannot be deleted at all.
    expect(fn () => Fx::owner()->delete('DELETE FROM affiliate_codes WHERE id = ?', [$codeId]))
        ->toThrow(QueryException::class);
});

it('refuses to delete an affiliate, a touch or an attribution that money depends on', function () {
    $ctx = Aff::attributableOrder();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);
    Aff::commission($ctx, $attributionId, amount: 750, base: 5000);

    // Closing an affiliate is a STATUS change; deleting the row is refused outright.
    expect(fn () => Fx::owner()->delete('DELETE FROM affiliates WHERE id = ?', [$ctx['affiliate_id']]))
        ->toThrow(QueryException::class);
    expect(fn () => Fx::owner()->delete('DELETE FROM affiliate_attributions WHERE id = ?', [$attributionId]))
        ->toThrow(QueryException::class);

    Fx::owner()->update("UPDATE affiliates SET status = 'closed', closed_at = now() WHERE id = ?", [$ctx['affiliate_id']]);

    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_commissions WHERE attribution_id = ?', [$attributionId])->c)->toBe(1);
});

it('refuses to delete a touch that backs an attribution', function () {
    $ctx = Aff::attributableOrder();
    $touchId = Aff::touch($ctx['affiliate_id'], $ctx['code_id'], userId: Aff::user());

    Fx::owner()->insert(
        "INSERT INTO affiliate_attributions (order_id, affiliate_id, affiliate_code_id, affiliate_touch_id, policy_id, matched_by, attributed_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'last_click', now(), now(), now())",
        [$ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $touchId, $ctx['policy_id']],
    );

    expect(fn () => Fx::owner()->delete('DELETE FROM affiliate_touches WHERE id = ?', [$touchId]))
        ->toThrow(QueryException::class);
});

it('refuses to delete a policy that a commission still points at', function () {
    $ctx = Aff::attributableOrder();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);
    Aff::commission($ctx, $attributionId, amount: 750, base: 5000);

    expect(fn () => Fx::owner()->delete('DELETE FROM affiliate_program_policies WHERE id = ?', [$ctx['policy_id']]))
        ->toThrow(QueryException::class);
});
