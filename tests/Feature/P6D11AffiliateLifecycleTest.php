<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-D1.1 — affiliate lifecycle and codes (D-059).
 *
 * Every inventory is read from `pg_catalog`, never `information_schema`: the latter is
 * filtered by privilege and returns an empty list to a role holding nothing, which makes a
 * contract pass on nothing — the defect this suite exists to avoid.
 */
function p6d11User(UserRole $role = UserRole::Customer, UserStatus $status = UserStatus::Active, bool $deleted = false): int
{
    $user = User::factory()->create(['role' => $role, 'status' => $status]);

    if ($deleted) {
        $user->delete();
    }

    return (int) $user->id;
}

function p6d11Admin(): int
{
    return p6d11User(UserRole::Admin);
}

function p6d11Apply(int $userId): int
{
    return (int) Fx::owner()->selectOne('SELECT affiliate_id FROM submit_affiliate_application(?)', [$userId])->affiliate_id;
}

function p6d11Review(int $affiliateId, string $action, int $actorId, ?string $reason = null): object
{
    return Fx::owner()->selectOne(
        'SELECT * FROM review_affiliate_application(?, ?, ?, ?)',
        [$affiliateId, $action, $actorId, $reason],
    );
}

/** An approved affiliate, ready for suspension/rotation scenarios. */
function p6d11ActiveAffiliate(): array
{
    $userId = p6d11User();
    $adminId = p6d11Admin();
    $affiliateId = p6d11Apply($userId);
    $review = p6d11Review($affiliateId, 'approve', $adminId);

    return ['affiliate' => $affiliateId, 'user' => $userId, 'admin' => $adminId, 'code' => (string) $review->issued_code];
}

function p6d11Status(int $affiliateId): string
{
    return (string) Fx::owner()->selectOne('SELECT status FROM affiliates WHERE id = ?', [$affiliateId])->status;
}

function p6d11ActiveCodeId(int $affiliateId): ?int
{
    $row = Fx::owner()->selectOne('SELECT id FROM affiliate_codes WHERE affiliate_id = ? AND is_active', [$affiliateId]);

    return $row === null ? null : (int) $row->id;
}

function p6d11Rotate(int $affiliateId, int $expectedCodeId, int $actorId): string
{
    return (string) Fx::owner()->selectOne(
        'SELECT issued_code FROM rotate_affiliate_code(?, ?, ?)',
        [$affiliateId, $expectedCodeId, $actorId],
    )->issued_code;
}

function p6d11ActiveCodes(int $affiliateId): int
{
    return (int) Fx::owner()->selectOne(
        'SELECT count(*) AS c FROM affiliate_codes WHERE affiliate_id = ? AND is_active', [$affiliateId],
    )->c;
}

/** @return list<string> */
function p6d11Events(int $affiliateId): array
{
    return array_map(
        static fn (object $r): string => ($r->from_status ?? 'NULL').'->'.$r->to_status.'/'.$r->event_kind,
        Fx::owner()->select('SELECT from_status, to_status, event_kind FROM affiliate_lifecycle_events WHERE affiliate_id = ? ORDER BY id', [$affiliateId]),
    );
}

// ── Migration frontier ──────────────────────────────────────────────────────────

it('adds exactly migration 000031 and never opens the next one', function () {
    $root = dirname(__DIR__, 2);

    expect(glob($root.'/database/migrations/*.php'))->toHaveCount(51)
        ->and(glob($root.'/database/migrations/2026_07_14_000031*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000036*.php') ?: [])->toBe([]);
});

// ── Privilege boundary ──────────────────────────────────────────────────────────

/**
 * The executor must read exactly what eligibility needs and nothing else. A table-wide
 * grant would hand it the password hash and the e-mail.
 */
it('gives the executor column-level access to users, and only the eligibility columns', function () {
    $columns = Fx::owner()->select(<<<'SQL'
        SELECT a.attname,
               has_column_privilege('digitrove_affiliate_executor', 'public.users', a.attname, 'SELECT') AS granted
        FROM pg_attribute AS a
        WHERE a.attrelid = 'public.users'::regclass AND a.attnum > 0 AND NOT a.attisdropped
        ORDER BY a.attname
        SQL);

    $granted = array_values(array_map(
        static fn (object $c): string => (string) $c->attname,
        array_filter($columns, static fn (object $c): bool => (bool) $c->granted),
    ));

    sort($granted);

    expect($granted)->toBe(['deleted_at', 'id', 'role', 'status'])
        // No DML on users, ever.
        ->and((bool) Fx::owner()->selectOne("SELECT has_table_privilege('digitrove_affiliate_executor', 'public.users', 'UPDATE') AS g")->g)->toBeFalse();
});

it('keeps the runtime out of every affiliate table', function () {
    foreach (['affiliates', 'affiliate_codes', 'affiliate_lifecycle_events'] as $table) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            expect((bool) Fx::owner()->selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS g', ['digitrove_runtime', 'public.'.$table, $privilege],
            )->g)->toBeFalse("runtime holds {$privilege} on {$table}");
        }

        expect((bool) Fx::owner()->selectOne(
            'SELECT has_table_privilege(?, ?, ?) AS g', ['public', 'public.'.$table, 'SELECT'],
        )->g)->toBeFalse("PUBLIC reads {$table}");
    }

    expect((string) Fx::owner()->selectOne("SELECT pg_get_userbyid(relowner) AS o FROM pg_class WHERE relname = 'affiliate_lifecycle_events'")->o)
        ->toBe('digitrove_affiliate_executor');
});

/**
 * The exact function inventory, by NAME. A count alone would let an unexpected function
 * slip in whenever another one disappeared.
 */
it('owns an exact inventory of affiliate functions, none of them by the superuser', function () {
    $functions = Fx::owner()->select(<<<'SQL'
        SELECT p.proname, p.prosecdef, pg_get_userbyid(p.proowner) AS owner,
               has_function_privilege('digitrove_runtime', p.oid, 'EXECUTE') AS runtime,
               has_function_privilege('public', p.oid, 'EXECUTE') AS pub
        FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace
        WHERE n.nspname = 'public' AND p.proname LIKE '%affiliate%'
        ORDER BY 1
        SQL);

    $names = array_map(static fn (object $f): string => (string) $f->proname, $functions);

    expect($names)->toBe([
        // P6-D1.1 internal helper — never callable by the runtime.
        // À VALIDER (P6-D3) — the three commission authorities.
        'accrue_affiliate_commissions',
        'apply_affiliate_refund_reversal',
        'assert_affiliate_actor_is_admin',
        'close_affiliate',
        // P6-D1 policy governance.
        'create_affiliate_program_policy_draft',
        'current_affiliate_program_policy',
        // P6-D0 integrity guards.
        'enforce_affiliate_ledger_append_only',
        'enforce_affiliate_lifecycle_append_only',
        'enforce_affiliate_policy_immutability',
        'enforce_affiliate_touch_subject',
        // P6-D1.1 internal helper.
        'generate_affiliate_code',
        'get_affiliate',
        'list_affiliate_codes',
        'list_affiliate_lifecycle_events',
        // À VALIDER (P6-D4) — the five payout authorities.
        'list_affiliate_payout_candidates',
        'list_affiliate_payout_items',
        'list_affiliate_payouts',
        'list_affiliate_program_policies',
        'list_affiliates',
        'promote_affiliate_commissions_to_payable',
        'publish_affiliate_program_policy',
        'reactivate_affiliate',
        // P6-D2 capture and attribution authorities.
        'record_affiliate_touch',
        'request_affiliate_payout',
        'resolve_affiliate_attribution',
        'review_affiliate_application',
        'rotate_affiliate_code',
        'submit_affiliate_application',
        'suspend_affiliate',
        'transition_affiliate_payout',
        'update_affiliate_program_policy_draft',
    ]);

    $internal = ['generate_affiliate_code', 'assert_affiliate_actor_is_admin'];
    $integrity = ['enforce_affiliate_ledger_append_only', 'enforce_affiliate_lifecycle_append_only', 'enforce_affiliate_policy_immutability', 'enforce_affiliate_touch_subject'];

    foreach ($functions as $function) {
        expect((bool) $function->pub)->toBeFalse("PUBLIC can execute {$function->proname}");

        if (in_array($function->proname, $integrity, true)) {
            continue;
        }

        // Every operational authority runs as the RESTRICTED executor, never the superuser.
        expect((bool) $function->prosecdef)->toBeTrue("{$function->proname} must be SECURITY DEFINER")
            ->and($function->owner)->toBe('digitrove_affiliate_executor')
            ->and($function->owner)->not->toBe('digitrove');

        // Minting a code must be an EFFECT of approve/reactivate/rotate, never a callable.
        expect((bool) $function->runtime)->toBe(
            ! in_array($function->proname, $internal, true),
            "{$function->proname} runtime EXECUTE is wrong",
        );
    }
});

// ── The ledger ──────────────────────────────────────────────────────────────────

it('refuses to let a lifecycle event be rewritten or erased', function () {
    $ctx = p6d11ActiveAffiliate();
    $before = Fx::owner()->select('SELECT * FROM affiliate_lifecycle_events WHERE affiliate_id = ? ORDER BY id', [$ctx['affiliate']]);

    // Attempted as the OWNER, not merely as a runtime that lacks the privilege: the point
    // is the integrity guard itself, not the ACL.
    expect(fn () => Fx::owner()->update("UPDATE affiliate_lifecycle_events SET to_status = 'closed' WHERE affiliate_id = ?", [$ctx['affiliate']]))
        ->toThrow(QueryException::class, 'append-only');
    expect(fn () => Fx::owner()->delete('DELETE FROM affiliate_lifecycle_events WHERE affiliate_id = ?', [$ctx['affiliate']]))
        ->toThrow(QueryException::class, 'append-only');

    expect(Fx::owner()->select('SELECT * FROM affiliate_lifecycle_events WHERE affiliate_id = ? ORDER BY id', [$ctx['affiliate']]))
        ->toEqual($before);
});

/**
 * The ledger must not be able to claim a transition the state machine forbids — otherwise
 * the audit trail becomes fiction.
 */
it('refuses a lifecycle event whose kind contradicts its transition', function () {
    $ctx = p6d11ActiveAffiliate();

    $insert = static fn (?string $from, string $to, string $kind) => Fx::owner()->insert(
        'INSERT INTO affiliate_lifecycle_events (affiliate_id, from_status, to_status, event_kind, occurred_at, created_at) VALUES (?, ?, ?, ?, now(), now())',
        [$ctx['affiliate'], $from, $to, $kind],
    );

    foreach ([
        ['rejected', 'closed', 'application_approved'],
        ['pending', 'suspended', 'affiliate_suspended'],
        ['active', 'pending', 'application_reapplied'],
        [null, 'active', 'application_submitted'],
        ['active', 'closed', 'application_rejected'],
    ] as [$from, $to, $kind]) {
        expect(fn () => $insert($from, $to, $kind))
            ->toThrow(QueryException::class, 'affiliate_lifecycle_events_transition_check');
    }

    // And every legal pairing is accepted. The affiliate already carries the two events
    // of its own approval, so this is the third.
    $insert('active', 'suspended', 'affiliate_suspended');
    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_lifecycle_events WHERE affiliate_id = ?', [$ctx['affiliate']])->c)->toBe(3);
});

// ── Eligibility and actor ───────────────────────────────────────────────────────

it('admits only an ordinary, active, non-deleted account', function (UserRole $role, UserStatus $status, bool $deleted, bool $allowed) {
    $userId = p6d11User($role, $status, $deleted);

    if ($allowed) {
        expect(p6d11Apply($userId))->toBeGreaterThan(0);

        return;
    }

    expect(fn () => p6d11Apply($userId))->toThrow(QueryException::class, 'not eligible');
})->with([
    'active customer' => [UserRole::Customer, UserStatus::Active, false, true],
    'admin' => [UserRole::Admin, UserStatus::Active, false, false],
    'staff' => [UserRole::Staff, UserStatus::Active, false, false],
    'suspended customer' => [UserRole::Customer, UserStatus::Suspended, false, false],
    'blocked customer' => [UserRole::Customer, UserStatus::Blocked, false, false],
    'deleted customer' => [UserRole::Customer, UserStatus::Active, true, false],
]);

it('refuses any actor that is not an active administrator', function (UserRole $role, UserStatus $status, bool $deleted, bool $allowed) {
    $affiliateId = p6d11Apply(p6d11User());
    $actorId = p6d11User($role, $status, $deleted);

    if ($allowed) {
        expect(p6d11Review($affiliateId, 'approve', $actorId)->affiliate_status)->toBe('active');

        return;
    }

    expect(fn () => p6d11Review($affiliateId, 'approve', $actorId))
        ->toThrow(QueryException::class, 'not an active administrator');
})->with([
    'active admin' => [UserRole::Admin, UserStatus::Active, false, true],
    'staff' => [UserRole::Staff, UserStatus::Active, false, false],
    'customer' => [UserRole::Customer, UserStatus::Active, false, false],
    'suspended admin' => [UserRole::Admin, UserStatus::Suspended, false, false],
    'blocked admin' => [UserRole::Admin, UserStatus::Blocked, false, false],
    'deleted admin' => [UserRole::Admin, UserStatus::Active, true, false],
]);

/** Eligibility is a fact at the instant of the transition, not a permanent grant. */
it('refuses approval when the account stopped being eligible after applying', function () {
    $userId = p6d11User();
    $affiliateId = p6d11Apply($userId);

    User::withTrashed()->find($userId)->update(['status' => UserStatus::Blocked]);

    expect(fn () => p6d11Review($affiliateId, 'approve', p6d11Admin()))
        ->toThrow(QueryException::class, 'no longer eligible');

    expect(p6d11Status($affiliateId))->toBe('pending')
        ->and(p6d11ActiveCodes($affiliateId))->toBe(0);
});

// ── Codes ───────────────────────────────────────────────────────────────────────

it('issues one server-generated code on approval and never a second active one', function () {
    $ctx = p6d11ActiveAffiliate();

    // A second affiliate, approved the same way, to show the code carries nothing about
    // the account. A naive "the code must not contain the user id" check would be
    // meaningless: a short numeric id appears inside twelve hex characters by chance.
    $other = p6d11ActiveAffiliate();

    expect($ctx['code'])->toMatch('/^[0-9A-F]{12}$/')
        ->and($other['code'])->toMatch('/^[0-9A-F]{12}$/')
        ->and($other['code'])->not->toBe($ctx['code'])
        ->and(p6d11ActiveCodes($ctx['affiliate']))->toBe(1);

    expect(fn () => Fx::owner()->insert(
        'INSERT INTO affiliate_codes (affiliate_id, code, is_active, created_at, updated_at) VALUES (?, ?, true, now(), now())',
        [$ctx['affiliate'], 'ZZZZ9999'],
    ))->toThrow(QueryException::class, 'affiliate_codes_single_active');
});

it('keeps is_active and deactivated_at agreeing in both directions', function () {
    $ctx = p6d11ActiveAffiliate();

    expect(fn () => Fx::owner()->update('UPDATE affiliate_codes SET deactivated_at = now() WHERE affiliate_id = ? AND is_active', [$ctx['affiliate']]))
        ->toThrow(QueryException::class, 'affiliate_codes_activation_coherence_check');

    expect(fn () => Fx::owner()->insert(
        'INSERT INTO affiliate_codes (affiliate_id, code, is_active, created_at, updated_at) VALUES (?, ?, false, now(), now())',
        [$ctx['affiliate'], 'YYYY8888'],
    ))->toThrow(QueryException::class, 'affiliate_codes_activation_coherence_check');
});

it('rotates to a new code and keeps the old one reserved for ever', function () {
    $ctx = p6d11ActiveAffiliate();

    $codeId = p6d11ActiveCodeId($ctx['affiliate']);
    $rotated = p6d11Rotate($ctx['affiliate'], $codeId, $ctx['admin']);

    expect($rotated)->not->toBe($ctx['code'])
        ->and($rotated)->toMatch('/^[0-9A-F]{12}$/')
        ->and(p6d11ActiveCodes($ctx['affiliate']))->toBe(1)
        // Rotation is not a lifecycle transition: recording active → active would
        // falsify a change of state that never happened.
        ->and(p6d11Events($ctx['affiliate']))->toBe(['NULL->pending/application_submitted', 'pending->active/application_approved']);

    $old = Fx::owner()->selectOne('SELECT is_active, deactivated_at FROM affiliate_codes WHERE code = ?', [$ctx['code']]);

    expect((bool) $old->is_active)->toBeFalse()
        ->and($old->deactivated_at)->not->toBeNull();

    // A retired code can never be handed out again, to anybody.
    expect(fn () => Fx::owner()->insert(
        'INSERT INTO affiliate_codes (affiliate_id, code, is_active, created_at, updated_at) VALUES (?, ?, true, now(), now())',
        [p6d11Apply(p6d11User()), $ctx['code']],
    ))->toThrow(QueryException::class, 'affiliate_codes_code_unique');
});

/**
 * Rotation is a COMPARE-AND-SWAP, and this proves it without any concurrency: replaying a
 * request that names a code already rotated away is refused as stale, while a fresh
 * request naming the current code still works. Rotation is repeatable — it is the stale
 * REQUEST that is rejected, not rotation itself.
 */
it('rotates only when the named code is still the active one', function () {
    $ctx = p6d11ActiveAffiliate();
    $codeA = (int) p6d11ActiveCodeId($ctx['affiliate']);

    $b = p6d11Rotate($ctx['affiliate'], $codeA, $ctx['admin']);
    $codeB = (int) p6d11ActiveCodeId($ctx['affiliate']);

    expect($codeB)->not->toBe($codeA);

    // The same request replayed: the code it meant to replace is gone.
    expect(fn () => p6d11Rotate($ctx['affiliate'], $codeA, $ctx['admin']))
        ->toThrow(QueryException::class, 'stale affiliate code rotation');

    // Nothing was minted by the refusal.
    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_codes WHERE affiliate_id = ?', [$ctx['affiliate']])->c)->toBe(2)
        ->and(p6d11ActiveCodes($ctx['affiliate']))->toBe(1);

    // And a fresh request naming the CURRENT code is perfectly legitimate.
    $c = p6d11Rotate($ctx['affiliate'], $codeB, $ctx['admin']);

    expect($c)->not->toBe($b)
        ->and((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_codes WHERE affiliate_id = ?', [$ctx['affiliate']])->c)->toBe(3)
        ->and(p6d11ActiveCodes($ctx['affiliate']))->toBe(1);
});

/** Exactly one rotate authority, and no ghost overload left by the signature change. */
it('exposes exactly one rotation signature', function () {
    $signatures = array_map(
        static fn (object $r): string => (string) $r->sig,
        Fx::owner()->select(<<<'SQL'
            SELECT p.oid::regprocedure::text AS sig
            FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.proname = 'rotate_affiliate_code'
            ORDER BY 1
            SQL),
    );

    expect($signatures)->toBe(['rotate_affiliate_code(bigint,bigint,bigint)']);
});

// ── Full lifecycle ──────────────────────────────────────────────────────────────

/**
 * The decision D-059 turns on, proven end to end: the snapshot holds the current state,
 * the ledger holds the history, and neither pretends to be the other.
 */
it('records a whole history in the ledger while the snapshot keeps only the present', function () {
    $userId = p6d11User();
    $adminId = p6d11Admin();

    $affiliateId = p6d11Apply($userId);
    p6d11Review($affiliateId, 'reject', $adminId, 'incomplete');
    p6d11Apply($userId);                                   // reapply
    p6d11Review($affiliateId, 'approve', $adminId);
    Fx::owner()->selectOne('SELECT * FROM suspend_affiliate(?, ?, ?)', [$affiliateId, $adminId, 'policy_breach']);
    Fx::owner()->selectOne('SELECT * FROM reactivate_affiliate(?, ?)', [$affiliateId, $adminId]);
    p6d11Rotate($affiliateId, (int) p6d11ActiveCodeId($affiliateId), $adminId);
    Fx::owner()->selectOne('SELECT * FROM close_affiliate(?, ?, ?)', [$affiliateId, $adminId, 'requested']);

    // SEVEN events, not eight: the rotation is not a lifecycle transition.
    expect(p6d11Events($affiliateId))->toBe([
        'NULL->pending/application_submitted',
        'pending->rejected/application_rejected',
        'rejected->pending/application_reapplied',
        'pending->active/application_approved',
        'active->suspended/affiliate_suspended',
        'suspended->active/affiliate_reactivated',
        'active->closed/affiliate_closed',
    ]);

    $snapshot = Fx::owner()->selectOne('SELECT * FROM affiliates WHERE id = ?', [$affiliateId]);

    expect($snapshot->status)->toBe('closed')
        // Cumulative markers, all preserved — this is the semantics D-059 chose.
        ->and($snapshot->rejected_at)->not->toBeNull()
        ->and($snapshot->approved_at)->not->toBeNull()
        ->and($snapshot->suspended_at)->not->toBeNull()
        ->and($snapshot->closed_at)->not->toBeNull()
        // Three codes, all retired, each with its own deactivation instant.
        ->and((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_codes WHERE affiliate_id = ?', [$affiliateId])->c)->toBe(3)
        ->and(p6d11ActiveCodes($affiliateId))->toBe(0)
        ->and((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_codes WHERE affiliate_id = ? AND deactivated_at IS NULL', [$affiliateId])->c)->toBe(0);
});

/**
 * A future reader must not "fix" this: an active affiliate legitimately carries the date
 * of the refusal it recovered from. The history is in the ledger, not in these columns.
 */
it('leaves rejected_at standing on an affiliate that was approved after reapplying', function () {
    $userId = p6d11User();
    $adminId = p6d11Admin();

    $affiliateId = p6d11Apply($userId);
    p6d11Review($affiliateId, 'reject', $adminId);
    $rejectedAt = Fx::owner()->selectOne('SELECT rejected_at, applied_at FROM affiliates WHERE id = ?', [$affiliateId]);

    p6d11Apply($userId);
    p6d11Review($affiliateId, 'approve', $adminId);

    $after = Fx::owner()->selectOne('SELECT * FROM affiliates WHERE id = ?', [$affiliateId]);

    expect($after->status)->toBe('active')
        ->and($after->rejected_at)->toBe($rejectedAt->rejected_at)
        // The reapplication moved applied_at forward…
        ->and(strtotime((string) $after->applied_at))->toBeGreaterThanOrEqual(strtotime((string) $rejectedAt->applied_at))
        // …and the review of the NEW application named its reviewer.
        ->and($after->reviewed_by_user_id)->not->toBeNull();
});

// ── Replay ──────────────────────────────────────────────────────────────────────

it('refuses every replayed transition without touching history', function () {
    $ctx = p6d11ActiveAffiliate();
    $affiliateId = $ctx['affiliate'];
    $adminId = $ctx['admin'];

    $snapshot = fn (): array => [
        'row' => Fx::owner()->selectOne('SELECT * FROM affiliates WHERE id = ?', [$affiliateId]),
        'events' => p6d11Events($affiliateId),
        'codes' => Fx::owner()->select('SELECT id, code, is_active, deactivated_at FROM affiliate_codes WHERE affiliate_id = ? ORDER BY id', [$affiliateId]),
    ];

    $before = $snapshot();

    expect(fn () => p6d11Review($affiliateId, 'approve', $adminId))->toThrow(QueryException::class, 'only a pending application');
    expect(fn () => Fx::owner()->selectOne('SELECT * FROM reactivate_affiliate(?, ?)', [$affiliateId, $adminId]))->toThrow(QueryException::class, 'only a suspended affiliate');
    expect(fn () => p6d11Apply($ctx['user']))->toThrow(QueryException::class, 'only a rejected application');

    expect($snapshot())->toEqual($before);

    // And once closed, nothing reopens it.
    Fx::owner()->selectOne('SELECT * FROM close_affiliate(?, ?, ?)', [$affiliateId, $adminId, null]);

    foreach ([
        fn () => Fx::owner()->selectOne('SELECT * FROM close_affiliate(?, ?, ?)', [$affiliateId, $adminId, null]),
        fn () => Fx::owner()->selectOne('SELECT * FROM suspend_affiliate(?, ?, ?)', [$affiliateId, $adminId, null]),
        fn () => Fx::owner()->selectOne('SELECT * FROM reactivate_affiliate(?, ?)', [$affiliateId, $adminId]),
        fn () => p6d11Rotate($affiliateId, 999_999, $adminId),
        fn () => p6d11Apply($ctx['user']),
    ] as $replay) {
        expect($replay)->toThrow(QueryException::class);
    }

    expect(p6d11Status($affiliateId))->toBe('closed')
        ->and(p6d11ActiveCodes($affiliateId))->toBe(0);
});

// ── Read model: the compare-and-swap token ──────────────────────────────────────

/**
 * `rotate_affiliate_code` demands the id of the code the administrator meant to replace,
 * so the authoritative read has to hand that id out. Without it the only way to obtain the
 * token would be a fresh lookup at click time — which is precisely the read the CAS exists
 * to forbid, because it would prove nothing about what was on screen.
 */
function p6d11Detail(int $affiliateId): object
{
    return Fx::owner()->selectOne('SELECT * FROM get_affiliate(?)', [$affiliateId]);
}

it('hands out the active code together with the id that identifies it', function () {
    $ctx = p6d11ActiveAffiliate();
    $detail = p6d11Detail($ctx['affiliate']);

    $row = Fx::owner()->selectOne(
        'SELECT id, code FROM affiliate_codes WHERE affiliate_id = ? AND is_active',
        [$ctx['affiliate']],
    );

    // Both columns describe the SAME affiliate_codes row, not merely two true facts.
    expect((int) $detail->active_code_id)->toBe((int) $row->id)
        ->and((string) $detail->active_code)->toBe((string) $row->code)
        ->and((string) $detail->active_code)->toBe($ctx['code'])
        ->and((string) $detail->affiliate_status)->toBe('active');
});

it('reports no active code at all outside the active state', function (string $reach) {
    $adminId = p6d11Admin();
    $affiliateId = p6d11Apply(p6d11User());

    if ($reach !== 'pending') {
        p6d11Review($affiliateId, $reach === 'rejected' ? 'reject' : 'approve', $adminId);
    }

    if ($reach === 'suspended' || $reach === 'closed') {
        Fx::owner()->selectOne('SELECT * FROM suspend_affiliate(?, ?, ?)', [$affiliateId, $adminId, null]);
    }

    if ($reach === 'closed') {
        Fx::owner()->selectOne('SELECT * FROM close_affiliate(?, ?, ?)', [$affiliateId, $adminId, null]);
    }

    $detail = p6d11Detail($affiliateId);

    // Never half a pair: an id without a value would let the UI offer a rotation on a code
    // it cannot show, and a value without an id could not be rotated at all.
    expect((string) $detail->affiliate_status)->toBe($reach)
        ->and($detail->active_code_id)->toBeNull()
        ->and($detail->active_code)->toBeNull();
})->with(['pending', 'rejected', 'suspended', 'closed']);

it('moves both halves of the pair together when the code is rotated', function () {
    $ctx = p6d11ActiveAffiliate();
    $before = p6d11Detail($ctx['affiliate']);

    $newCode = p6d11Rotate($ctx['affiliate'], (int) $before->active_code_id, $ctx['admin']);
    $after = p6d11Detail($ctx['affiliate']);

    // The forbidden outcomes are the crossed ones: the old id beside the new value would
    // hand the next rotation a token that is already spent.
    expect((int) $after->active_code_id)->not->toBe((int) $before->active_code_id)
        ->and((string) $after->active_code)->toBe($newCode)
        ->and((string) $after->active_code)->not->toBe((string) $before->active_code)
        ->and((int) $after->active_code_id)->toBe(p6d11ActiveCodeId($ctx['affiliate']));
});

it('keeps the retired code in history while dropping it from the pair', function () {
    $ctx = p6d11ActiveAffiliate();
    $codeId = (int) p6d11Detail($ctx['affiliate'])->active_code_id;

    Fx::owner()->selectOne('SELECT * FROM suspend_affiliate(?, ?, ?)', [$ctx['affiliate'], $ctx['admin'], null]);
    $suspended = p6d11Detail($ctx['affiliate']);

    expect($suspended->active_code_id)->toBeNull()
        ->and($suspended->active_code)->toBeNull()
        // Retired, never erased: the code stays reserved so it can never be minted again.
        ->and((bool) Fx::owner()->selectOne('SELECT is_active FROM affiliate_codes WHERE id = ?', [$codeId])->is_active)->toBeFalse();

    Fx::owner()->selectOne('SELECT * FROM reactivate_affiliate(?, ?)', [$ctx['affiliate'], $ctx['admin']]);
    $reactivated = p6d11Detail($ctx['affiliate']);

    expect((int) $reactivated->active_code_id)->not->toBe($codeId)
        ->and((string) $reactivated->active_code)->not->toBe($ctx['code'])
        ->and((int) $reactivated->active_code_id)->toBe(p6d11ActiveCodeId($ctx['affiliate']));
});

it('declares active_code_id in the read authority and withholds it from the list', function () {
    // Catalogue introspection, not a substring hunt in the function body: `proargnames`
    // paired with `proargmodes` is what PostgreSQL itself resolves the signature from.
    $columns = static fn (string $name): array => array_map(
        static fn (object $r): string => (string) $r->argname,
        Fx::owner()->select(
            <<<'SQL'
            SELECT a.argname
            FROM pg_catalog.pg_proc AS p
            JOIN pg_catalog.pg_namespace AS n ON n.oid = p.pronamespace
            CROSS JOIN LATERAL unnest(p.proargnames, p.proargmodes) AS a(argname, argmode)
            WHERE n.nspname = 'public' AND p.proname = ? AND a.argmode = 't'
            SQL,
            [$name],
        ),
    );

    expect($columns('get_affiliate'))->toContain('active_code_id')->toContain('active_code');

    // The list stays a browsing projection. A rotation token belongs to the detail snapshot
    // an administrator actually read, so the list must not be able to hand one out.
    expect($columns('list_affiliates'))->toContain('active_code')->not->toContain('active_code_id');
});
