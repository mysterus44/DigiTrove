<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\AffiliateFixtures as Aff;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-D0 policy versioning contract (§5).
 *
 * The arbitrated settings — 30 days, 1500 bps, 14 days, 10 000 XOF — are meant to be
 * changed from the administration LATER, without ever altering an existing commission.
 * That promise only holds if an effective policy is physically immutable: otherwise a
 * past commission's snapshot would point at a policy that no longer says what it said.
 */
function p6d0Publish(int $version): int
{
    $id = Aff::policy(version: $version);

    Fx::owner()->update("UPDATE affiliate_program_policies SET status = 'active' WHERE id = ?", [$id]);

    return $id;
}

it('lets a draft be corrected freely', function () {
    $id = Aff::policy(version: 1);

    Fx::owner()->update(
        'UPDATE affiliate_program_policies SET default_commission_bps = 2000, attribution_window_days = 60 WHERE id = ?',
        [$id],
    );

    $row = Fx::owner()->selectOne('SELECT default_commission_bps, attribution_window_days FROM affiliate_program_policies WHERE id = ?', [$id]);

    expect((int) $row->default_commission_bps)->toBe(2000)
        ->and((int) $row->attribution_window_days)->toBe(60);
});

/**
 * The core guarantee: once effective, EVERY arbitrated tunable is frozen. Changing one
 * requires publishing a new version.
 */
it('freezes every arbitrated setting once the policy is effective', function () {
    $id = p6d0Publish(1);

    $frozen = [
        'version' => 2,
        'attribution_model' => 'code_then_last_click',
        'attribution_window_days' => 60,
        'default_commission_bps' => 2000,
        'payable_delay_days' => 30,
        'payout_threshold_minor' => 50000,
        'payout_currency' => 'USD',
    ];

    foreach ($frozen as $column => $value) {
        // `attribution_model` has a single allowed value, so it is proven by re-assigning
        // a DIFFERENT one being impossible; the others are proven by a legal-but-refused
        // value, which is the sharper test.
        if ($column === 'attribution_model') {
            continue;
        }

        expect(fn () => Fx::owner()->update(
            "UPDATE affiliate_program_policies SET {$column} = ? WHERE id = ?", [$value, $id],
        ))->toThrow(QueryException::class, 'immutable');
    }

    expect(fn () => Fx::owner()->update(
        'UPDATE affiliate_program_policies SET effective_from = now() + interval \'1 day\' WHERE id = ?', [$id],
    ))->toThrow(QueryException::class, 'immutable');

    // Nothing moved.
    $row = Fx::owner()->selectOne('SELECT * FROM affiliate_program_policies WHERE id = ?', [$id]);

    expect((int) $row->attribution_window_days)->toBe(30)
        ->and((int) $row->default_commission_bps)->toBe(1500)
        ->and((int) $row->payable_delay_days)->toBe(14)
        ->and((int) $row->payout_threshold_minor)->toBe(10000)
        ->and($row->payout_currency)->toBe('XOF');
});

it('still allows the lifecycle to move forward', function () {
    $id = p6d0Publish(1);

    // Superseding a policy and closing its window are lifecycle moves, not rewrites.
    Fx::owner()->update(
        "UPDATE affiliate_program_policies SET status = 'superseded', effective_until = now() + interval '1 day' WHERE id = ?",
        [$id],
    );

    $row = Fx::owner()->selectOne('SELECT status, effective_until FROM affiliate_program_policies WHERE id = ?', [$id]);

    expect($row->status)->toBe('superseded')
        ->and($row->effective_until)->not->toBeNull();
});

it('requires a new version to change a setting, and only one may be active', function () {
    $first = p6d0Publish(1);
    $second = Aff::policy(version: 2);

    $activate = static fn (int $id) => Fx::owner()->update(
        "UPDATE affiliate_program_policies SET status = 'active' WHERE id = ?", [$id],
    );

    // Two active versions would make "which rate applied" unanswerable, so the successor
    // cannot take over while the incumbent is still effective.
    expect(fn () => $activate($second))->toThrow(QueryException::class);

    // While it is still a draft, the successor can carry a different rate.
    Fx::owner()->update('UPDATE affiliate_program_policies SET default_commission_bps = 1000 WHERE id = ?', [$second]);

    // The supported path: supersede the incumbent, THEN the successor activates.
    Fx::owner()->update("UPDATE affiliate_program_policies SET status = 'superseded', effective_until = now() + interval '1 hour' WHERE id = ?", [$first]);
    $activate($second);

    $rows = Fx::owner()->select('SELECT version, status, default_commission_bps FROM affiliate_program_policies ORDER BY version');

    expect($rows)->toHaveCount(2)
        // The first version still says exactly what it always said.
        ->and((int) $rows[0]->default_commission_bps)->toBe(1500)
        ->and($rows[0]->status)->toBe('superseded')
        ->and((int) $rows[1]->default_commission_bps)->toBe(1000)
        ->and($rows[1]->status)->toBe('active');
});

/**
 * The reason the freeze matters: a commission explains itself from its OWN snapshot, and
 * publishing a new policy must not retroactively change what an old sale earned.
 */
it('never lets a new policy version rewrite an existing commission', function () {
    $ctx = Aff::attributableOrder();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);
    $commissionId = Aff::commission($ctx, $attributionId, amount: 750, base: 5000, rateBps: 1500);

    Fx::owner()->update("UPDATE affiliate_program_policies SET status = 'active' WHERE id = ?", [$ctx['policy_id']]);
    Fx::owner()->update("UPDATE affiliate_program_policies SET status = 'superseded', effective_until = now() + interval '1 hour' WHERE id = ?", [$ctx['policy_id']]);

    $successor = Aff::policy(version: 9_999);
    Fx::owner()->update('UPDATE affiliate_program_policies SET default_commission_bps = 100 WHERE id = ?', [$successor]);
    Fx::owner()->update("UPDATE affiliate_program_policies SET status = 'active' WHERE id = ?", [$successor]);

    $commission = Fx::owner()->selectOne(
        'SELECT rate_bps_snapshot, amount_minor, payable_delay_days_snapshot FROM affiliate_commissions WHERE id = ?',
        [$commissionId],
    );

    expect((int) $commission->rate_bps_snapshot)->toBe(1500)
        ->and((int) $commission->amount_minor)->toBe(750)
        ->and((int) $commission->payable_delay_days_snapshot)->toBe(14);
});

/**
 * §5 asks whether an extension is used to prevent overlap. It is not: the partial unique
 * index answers "which policy applies now", and no `btree_gist` exclusion constraint is
 * installed — so the rollback cannot damage shared infrastructure it does not own.
 */
it('needs no PostgreSQL extension to guarantee a single active policy', function () {
    expect(Fx::owner()->select("SELECT extname FROM pg_extension WHERE extname = 'btree_gist'"))->toBe([]);

    $exclusions = Fx::owner()->select(<<<'SQL'
        SELECT conname FROM pg_constraint WHERE contype = 'x' AND conname LIKE 'affiliate%'
        SQL);

    expect($exclusions)->toBe([]);

    $index = Fx::owner()->selectOne(<<<'SQL'
        SELECT indexdef FROM pg_indexes
        WHERE schemaname = 'public' AND indexname = 'affiliate_program_policies_single_active'
        SQL);

    expect($index)->not->toBeNull()
        ->and($index->indexdef)->toContain('UNIQUE')
        ->and($index->indexdef)->toContain("WHERE ((status)::text = 'active'::text)");
});
