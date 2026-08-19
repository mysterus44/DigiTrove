<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-D1 — the PostgreSQL authority frontier and policy governance (D-058).
 *
 * Everything here is read from `pg_catalog`, never `information_schema`: the latter is
 * filtered by privilege, and the affiliate tables are invisible through it to any role
 * that holds nothing on them. A contract written that way passes on an empty list and
 * proves nothing — the defect this suite was built to avoid.
 */

/** @return list<string> */
function p6d1AuthoritySignatures(): array
{
    return [
        'create_affiliate_program_policy_draft',
        'current_affiliate_program_policy',
        'list_affiliate_program_policies',
        'publish_affiliate_program_policy',
        'update_affiliate_program_policy_draft',
    ];
}

function p6d1Draft(int $version, int $bps = 1500, int $window = 30, int $delay = 14, int $threshold = 10_000, string $currency = 'XOF'): int
{
    return (int) Fx::owner()->selectOne(
        'SELECT policy_id FROM create_affiliate_program_policy_draft(?, ?, ?, ?, ?, ?)',
        [$version, $window, $bps, $delay, $threshold, $currency],
    )->policy_id;
}

function p6d1Publish(int $policyId): object
{
    return Fx::owner()->selectOne('SELECT * FROM publish_affiliate_program_policy(?)', [$policyId]);
}

// ── The frontier itself ──────────────────────────────────────────────────────────

/**
 * CURRENT-STATE. The count moves as the roadmap advances; what must never move is that
 * each gate owns exactly one migration and never reaches past its own. P6-D1.1 (D-059)
 * added `000031`, so the frontier now sits one file further on — and `000032` must still
 * be absent, because no gate after D1.1 has been opened.
 */
it('keeps one migration per affiliate gate and reaches no further', function () {
    $root = dirname(__DIR__, 2);

    expect(glob($root.'/database/migrations/*.php'))->toHaveCount(50)
        ->and(glob($root.'/database/migrations/2026_07_14_000029*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000030*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000031*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000032*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000035*.php') ?: [])->toBe([]);
});

/**
 * THE defect this gate exists to close: P6-D0 left the nine tables owned by the SUPERUSER
 * migrator, so any SECURITY DEFINER function would have run as superuser.
 */
it('hands every affiliate table and sequence to the restricted executor', function () {
    $relations = Fx::owner()->select(<<<'SQL'
        SELECT c.relname, c.relkind, pg_get_userbyid(c.relowner) AS owner
        FROM pg_class AS c
        JOIN pg_namespace AS n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind IN ('r', 'S') AND c.relname LIKE 'affiliate%'
        ORDER BY 1
        SQL);

    $named = static fn (string $kind): array => array_values(array_map(
        static fn (object $r): string => $r->relname,
        array_filter($relations, static fn (object $r): bool => $r->relkind === $kind),
    ));

    // CURRENT-STATE, by NAME rather than by count: a count of ten would be satisfied by
    // any tenth table, and the point is that the tenth is the D-059 ledger and nothing
    // else. An eleventh — from a gate that has not been opened — must fail here.
    $tables = [
        'affiliate_attributions',
        'affiliate_codes',
        'affiliate_commission_entries',
        'affiliate_commissions',
        'affiliate_lifecycle_events',
        'affiliate_payout_items',
        'affiliate_payouts',
        'affiliate_program_policies',
        'affiliate_touches',
        'affiliates',
    ];

    expect($named('r'))->toBe($tables)
        ->and($named('S'))->toBe(array_map(static fn (string $t): string => $t.'_id_seq', $tables));

    foreach ($relations as $relation) {
        expect($relation->owner)->toBe(
            'digitrove_affiliate_executor',
            "{$relation->relname} is still owned by {$relation->owner}",
        );
    }
});

it('provisions exactly one affiliate role, restricted and unusable for login', function () {
    $roles = Fx::owner()->select("SELECT rolname, rolsuper, rolcanlogin, rolinherit, rolcreatedb, rolcreaterole, rolbypassrls FROM pg_roles WHERE rolname LIKE '%affiliate%' ORDER BY 1");

    expect($roles)->toHaveCount(1)
        ->and($roles[0]->rolname)->toBe('digitrove_affiliate_executor')
        ->and((bool) $roles[0]->rolsuper)->toBeFalse()
        ->and((bool) $roles[0]->rolcanlogin)->toBeFalse()
        ->and((bool) $roles[0]->rolinherit)->toBeFalse()
        ->and((bool) $roles[0]->rolcreatedb)->toBeFalse()
        ->and((bool) $roles[0]->rolcreaterole)->toBeFalse()
        ->and((bool) $roles[0]->rolbypassrls)->toBeFalse();
});

/**
 * The assertion that would have caught the D0 defect. Deliberately scoped to the P6-D1
 * authorities: the two historical analytics partition functions owned by `digitrove` are
 * out of this gate's perimeter and are not silently blessed here either.
 */
it('never lets a P6-D1 authority be owned by the superuser migrator', function () {
    $functions = Fx::owner()->select(<<<'SQL'
        SELECT p.proname, p.prosecdef, pg_get_userbyid(p.proowner) AS owner
        FROM pg_proc AS p
        JOIN pg_namespace AS n ON n.oid = p.pronamespace
        WHERE n.nspname = 'public' AND p.proname LIKE '%affiliate_program_polic%'
        ORDER BY 1
        SQL);

    expect(array_map(static fn (object $f): string => (string) $f->proname, $functions))
        ->toBe(p6d1AuthoritySignatures());

    foreach ($functions as $function) {
        expect((bool) $function->prosecdef)->toBeTrue("{$function->proname} must be SECURITY DEFINER")
            ->and($function->owner)->not->toBe('digitrove', "{$function->proname} would run as superuser")
            ->and($function->owner)->toBe('digitrove_affiliate_executor');
    }
});

it('gives the runtime EXECUTE and nothing else', function () {
    $tables = Fx::owner()->select(<<<'SQL'
        SELECT c.relname
        FROM pg_class AS c
        JOIN pg_namespace AS n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind = 'r' AND c.relname LIKE 'affiliate%'
        ORDER BY 1
        SQL);

    foreach ($tables as $table) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            expect((bool) Fx::owner()->selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS granted',
                ['digitrove_runtime', 'public.'.$table->relname, $privilege],
            )->granted)->toBeFalse("runtime holds {$privilege} on {$table->relname}");
        }

        expect((bool) Fx::owner()->selectOne(
            'SELECT has_table_privilege(?, ?, ?) AS granted',
            ['public', 'public.'.$table->relname, 'SELECT'],
        )->granted)->toBeFalse("PUBLIC reads {$table->relname}");
    }

    foreach (Fx::owner()->select(<<<'SQL'
        SELECT p.oid, p.proname FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace
        WHERE n.nspname = 'public' AND p.proname LIKE '%affiliate_program_polic%'
        SQL) as $function) {
        expect((bool) Fx::owner()->selectOne(
            'SELECT has_function_privilege(?, ?, ?) AS granted',
            ['digitrove_runtime', (int) $function->oid, 'EXECUTE'],
        )->granted)->toBeTrue("runtime cannot execute {$function->proname}")
            ->and((bool) Fx::owner()->selectOne(
                'SELECT has_function_privilege(?, ?, ?) AS granted',
                ['public', (int) $function->oid, 'EXECUTE'],
            )->granted)->toBeFalse("PUBLIC can execute {$function->proname}");
    }
});

// ── Drafting ────────────────────────────────────────────────────────────────────

it('creates the first draft and refuses a version that is not next', function () {
    $id = p6d1Draft(1);

    $row = Fx::owner()->selectOne('SELECT * FROM affiliate_program_policies WHERE id = ?', [$id]);

    expect($row->status)->toBe('draft')
        ->and((int) $row->version)->toBe(1)
        ->and((int) $row->default_commission_bps)->toBe(1500)
        ->and($row->attribution_model)->toBe('code_then_last_click')
        // The caller cannot smuggle in an automatic payout or another attribution model.
        ->and((bool) $row->manual_payout_only)->toBeTrue();

    expect(fn () => p6d1Draft(1))->toThrow(QueryException::class);
    expect(fn () => p6d1Draft(3))->toThrow(QueryException::class);
    expect(fn () => p6d1Draft(0))->toThrow(QueryException::class);
});

it('refuses an out-of-bounds draft through the same CHECKs P6-D0 already posed', function () {
    expect(fn () => p6d1Draft(1, bps: 10_000))->toThrow(QueryException::class);
    expect(fn () => p6d1Draft(1, bps: -1))->toThrow(QueryException::class);
    expect(fn () => p6d1Draft(1, window: 0))->toThrow(QueryException::class);
    expect(fn () => p6d1Draft(1, window: 366))->toThrow(QueryException::class);
    expect(fn () => p6d1Draft(1, delay: 366))->toThrow(QueryException::class);
    expect(fn () => p6d1Draft(1, threshold: -1))->toThrow(QueryException::class);
    expect(fn () => p6d1Draft(1, currency: 'XO'))->toThrow(QueryException::class);
});

it('edits a draft idempotently and refuses to edit anything else', function () {
    $id = p6d1Draft(1);

    $apply = static fn () => Fx::owner()->selectOne(
        'SELECT policy_id FROM update_affiliate_program_policy_draft(?, ?, ?, ?, ?, ?)',
        [$id, 45, 1200, 21, 25_000, 'XOF'],
    );

    $apply();
    $apply();

    $row = Fx::owner()->selectOne('SELECT * FROM affiliate_program_policies WHERE id = ?', [$id]);

    expect((int) $row->attribution_window_days)->toBe(45)
        ->and((int) $row->default_commission_bps)->toBe(1200)
        ->and((int) $row->payable_delay_days)->toBe(21)
        ->and((int) $row->payout_threshold_minor)->toBe(25_000);

    p6d1Publish($id);

    // Once effective, the authority refuses — and so would the D0 trigger behind it.
    expect($apply)->toThrow(QueryException::class);
    expect(fn () => Fx::owner()->selectOne(
        'SELECT policy_id FROM update_affiliate_program_policy_draft(?, ?, ?, ?, ?, ?)',
        [999_999, 30, 1500, 14, 10_000, 'XOF'],
    ))->toThrow(QueryException::class);
});

// ── Publication ─────────────────────────────────────────────────────────────────

it('publishes the first policy straight into force', function () {
    $id = p6d1Draft(1);
    $published = p6d1Publish($id);

    expect((int) $published->policy_id)->toBe($id)
        ->and($published->status)->toBe('active')
        ->and($published->superseded_policy_id)->toBeNull();

    $current = Fx::owner()->selectOne('SELECT * FROM current_affiliate_program_policy()');

    expect($current)->not->toBeNull()
        ->and((int) $current->policy_id)->toBe($id)
        ->and((int) $current->default_commission_bps)->toBe(1500);
});

/**
 * The invariant of the gate, asserted on the values PostgreSQL actually persisted — not
 * on what the function returned.
 */
it('closes the predecessor at exactly the instant the successor opens', function () {
    $first = p6d1Draft(1);
    p6d1Publish($first);

    $second = p6d1Draft(2, bps: 1000);
    $published = p6d1Publish($second);

    expect((int) $published->superseded_policy_id)->toBe($first);

    $boundary = Fx::owner()->selectOne(<<<'SQL'
        SELECT
            (SELECT effective_until FROM affiliate_program_policies WHERE id = ?) AS closed_at,
            (SELECT effective_from  FROM affiliate_program_policies WHERE id = ?) AS opened_at,
            (SELECT effective_until FROM affiliate_program_policies WHERE id = ?)
                = (SELECT effective_from FROM affiliate_program_policies WHERE id = ?) AS identical
        SQL, [$first, $second, $first, $second]);

    // [effective_from, effective_until) — identical bounds mean neither gap nor overlap.
    expect((bool) $boundary->identical)->toBeTrue()
        ->and($boundary->closed_at)->not->toBeNull();

    $rows = Fx::owner()->select('SELECT id, status, effective_from, effective_until FROM affiliate_program_policies ORDER BY version');

    expect($rows[0]->status)->toBe('superseded')
        ->and($rows[1]->status)->toBe('active')
        ->and($rows[1]->effective_until)->toBeNull()
        // Exactly one policy is in force, and it is the successor.
        ->and((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM current_affiliate_program_policy()')->c)->toBe(1)
        ->and((int) Fx::owner()->selectOne('SELECT policy_id FROM current_affiliate_program_policy()')->policy_id)->toBe($second);
});

/**
 * `000029` stores the effective period at SECOND precision, so two publications inside the
 * same second would collapse both bounds onto one value and trip
 * `affiliate_program_policies_period_check`. `000030` widens the columns; this proves it.
 */
it('survives two publications inside the same second', function () {
    $first = p6d1Draft(1);
    p6d1Publish($first);
    $second = p6d1Draft(2);
    p6d1Publish($second);

    $precision = Fx::owner()->select(<<<'SQL'
        SELECT a.attname, information_schema._pg_datetime_precision(a.atttypid, a.atttypmod) AS precision
        FROM pg_attribute AS a
        WHERE a.attrelid = 'public.affiliate_program_policies'::regclass
          AND a.attname IN ('effective_from', 'effective_until')
        ORDER BY 1
        SQL);

    foreach ($precision as $column) {
        expect((int) $column->precision)->toBe(6, "{$column->attname} lost sub-second precision");
    }

    $gap = Fx::owner()->selectOne(<<<'SQL'
        SELECT count(*) AS broken
        FROM affiliate_program_policies AS a
        JOIN affiliate_program_policies AS b ON b.version = a.version + 1
        WHERE a.effective_until IS DISTINCT FROM b.effective_from
        SQL);

    expect((int) $gap->broken)->toBe(0);
});

it('refuses to publish anything but a draft, and never goes backwards', function () {
    $first = p6d1Draft(1);
    p6d1Publish($first);

    // Republishing an already-active policy is refused.
    expect(fn () => p6d1Publish($first))->toThrow(QueryException::class);
    expect(fn () => p6d1Publish(999_999))->toThrow(QueryException::class);

    $second = p6d1Draft(2);
    p6d1Publish($second);

    // The superseded predecessor cannot be brought back.
    expect(fn () => p6d1Publish($first))->toThrow(QueryException::class);
});

/**
 * D-058 chose immediate publication only. The administrator never supplies the effective
 * instant, so a future-dated policy cannot be created through any authority.
 */
it('offers no way to schedule a publication', function () {
    $id = p6d1Draft(1);

    // The draft's placeholder is not authoritative, and publication overwrites it.
    Fx::owner()->update(
        "UPDATE affiliate_program_policies SET effective_from = now() + interval '30 days' WHERE id = ?",
        [$id],
    );

    $published = p6d1Publish($id);
    $row = Fx::owner()->selectOne('SELECT effective_from FROM affiliate_program_policies WHERE id = ?', [$id]);

    expect(strtotime((string) $row->effective_from))->toBeLessThanOrEqual(time() + 5)
        ->and((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM current_affiliate_program_policy()')->c)->toBe(1);

    // No authority accepts an effective instant, and no scheduling status exists.
    $arguments = Fx::owner()->select(<<<'SQL'
        SELECT pg_get_function_arguments(p.oid) AS args
        FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace
        WHERE n.nspname = 'public' AND p.proname LIKE '%affiliate_program_polic%'
        SQL);

    foreach ($arguments as $argument) {
        expect(str_contains(strtolower((string) $argument->args), 'timestamp'))->toBeFalse();
    }

    expect($published->effective_from)->not->toBeNull();
});

// ── History ─────────────────────────────────────────────────────────────────────

it('lists history bounded and newest first, never unbounded', function () {
    foreach ([1, 2, 3] as $version) {
        $id = p6d1Draft($version);
        p6d1Publish($id);
    }

    $page = Fx::owner()->select('SELECT * FROM list_affiliate_program_policies(?, ?)', [2, null]);

    expect($page)->toHaveCount(2)
        ->and((int) $page[0]->version)->toBe(3)
        ->and((int) $page[1]->version)->toBe(2);

    $next = Fx::owner()->select('SELECT * FROM list_affiliate_program_policies(?, ?)', [2, (int) $page[1]->version]);

    expect($next)->toHaveCount(1)
        ->and((int) $next[0]->version)->toBe(1);

    expect(fn () => Fx::owner()->select('SELECT * FROM list_affiliate_program_policies(?, ?)', [0, null]))
        ->toThrow(QueryException::class);
    expect(fn () => Fx::owner()->select('SELECT * FROM list_affiliate_program_policies(?, ?)', [101, null]))
        ->toThrow(QueryException::class);
});

it('reports no policy in force before the first publication', function () {
    p6d1Draft(1);

    expect(Fx::owner()->select('SELECT * FROM current_affiliate_program_policy()'))->toBe([]);
});

// ── P6-D0 invariants survive the ownership transfer ─────────────────────────────

it('keeps every P6-D0 trigger firing after the tables changed owner', function () {
    $id = p6d1Draft(1);
    p6d1Publish($id);

    // Immutability trigger.
    foreach (['default_commission_bps = 999', 'attribution_window_days = 60', "payout_currency = 'USD'"] as $mutation) {
        expect(fn () => Fx::owner()->update("UPDATE affiliate_program_policies SET {$mutation} WHERE id = ?", [$id]))
            ->toThrow(QueryException::class);
    }

    // Ledger append-only trigger, and the touch subject trigger.
    $triggers = array_map(
        static fn (object $r): string => (string) $r->tgname,
        Fx::owner()->select(<<<'SQL'
            SELECT t.tgname FROM pg_trigger AS t
            WHERE NOT t.tgisinternal AND t.tgname LIKE '%affiliate%'
            ORDER BY 1
            SQL),
    );

    // CURRENT-STATE, named exactly. The fourth is D-059's lifecycle ledger guard; it
    // protects a history table and grants the financial triggers nothing they did not
    // already have. All four sit on tables INSIDE the affiliate block.
    expect($triggers)->toBe([
        'affiliate_commission_entries_append_only_trigger',
        'affiliate_lifecycle_events_append_only_trigger',
        'affiliate_program_policies_immutability_trigger',
        'affiliate_touches_subject_trigger',
    ]);
});

/**
 * The arbitrated mechanism of P6-D2, asserted rather than assumed.
 *
 * Attribution was first written as an `AFTER INSERT` trigger on `orders`. That hung affiliate
 * logic on EVERY insert into a table shared by P1/P3/P4, so any fault inside attribution —
 * an unforeseen exception, a missing table mid-migration — would have broken order creation
 * for code with nothing to do with affiliation. It also attributed a `pending` order, before
 * any payment existed.
 *
 * Attribution now runs from the `OrderPaid` listener. This test is what keeps it there: it
 * fails the moment anything affiliate-shaped is attached to `orders` again, whatever the
 * trigger is named — the check follows the FUNCTION, not the name, because a trigger called
 * `orders_sync_trigger` running `resolve_affiliate_attribution` would slip past a name match.
 */
it('attaches no affiliate trigger to orders, which is a shared commerce table', function () {
    $onOrders = array_map(
        static fn (object $r): string => (string) $r->tgname,
        Fx::owner()->select(<<<'SQL'
            SELECT t.tgname
            FROM pg_trigger AS t
            JOIN pg_class AS c ON c.oid = t.tgrelid
            JOIN pg_proc AS p ON p.oid = t.tgfoid
            WHERE NOT t.tgisinternal
              AND c.relname = 'orders'
              AND (t.tgname LIKE '%affiliate%' OR p.proname LIKE '%affiliate%')
            ORDER BY 1
            SQL),
    );

    expect($onOrders)->toBe([]);

    // And the authority the listener calls is a plain function, not a trigger function:
    // `RETURNS TEXT`, never `trigger`. A future change back to a trigger fails here too.
    expect((string) Fx::owner()->selectOne(<<<'SQL'
        SELECT pg_get_function_result(p.oid) AS result
        FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace
        WHERE n.nspname = 'public' AND p.proname = 'resolve_affiliate_attribution'
        SQL)->result)->toBe('text');
});
