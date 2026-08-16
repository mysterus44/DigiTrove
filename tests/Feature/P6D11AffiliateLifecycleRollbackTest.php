<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

/**
 * P6-D1.1 rollback and dirty-data guards.
 *
 * The lesson of P6-D1 applied before the fact: a rollback test on an EMPTY database proves
 * the catalogue, not the data. `000031` introduces a ledger holding history that P6-D1
 * cannot represent at all, so the interesting question is what `down()` does once that
 * history exists — and what `up()` does when existing rows already break the new
 * invariants.
 */
function p6d11Harness(string $suffix): array
{
    $harness = new PhaseMigrationHarness('digitrove_p6d11_'.$suffix.'_'.strtolower(Str::random(8)));
    $harness->create();
    $harness->applyMigrationsThrough('2026_07_14_000030_create_affiliate_policy_governance_authorities.php');

    return ['harness' => $harness, 'pdo' => $harness->ownerPdo()];
}

const P6D11_BOUNDARY = '2026_07_14_000031_create_affiliate_lifecycle_and_code_authorities.php';

/** A customer and an admin, plus an affiliate row in the given state. */
function p6d11SeedAffiliate(PDO $pdo, string $status = 'pending'): int
{
    $pdo->exec("INSERT INTO users (email, password_hash, role, status, created_at, updated_at)
        VALUES ('c".random_int(1, 999999)."@x.test', 'x', 'customer', 'active', now(), now())");
    $userId = (int) $pdo->query('SELECT id FROM users ORDER BY id DESC LIMIT 1')->fetchColumn();

    // The status timestamps must be present in the SAME statement: `000029` enforces
    // "status = active ⇒ approved_at IS NOT NULL" and would reject a two-step seed.
    [$approved, $suspended] = match ($status) {
        'active' => ['now()', 'NULL'],
        'suspended' => ['now()', 'now()'],
        default => ['NULL', 'NULL'],
    };

    $pdo->exec("INSERT INTO affiliates (public_id, user_id, status, applied_at, approved_at, suspended_at, created_at, updated_at)
        VALUES (gen_random_uuid(), {$userId}, '{$status}', now(), {$approved}, {$suspended}, now(), now())");

    return (int) $pdo->query('SELECT id FROM affiliates ORDER BY id DESC LIMIT 1')->fetchColumn();
}

function p6d11Snapshot(PDO $pdo): array
{
    return [
        'affiliates' => $pdo->query('SELECT id, status, applied_at, approved_at, rejected_at, suspended_at, closed_at FROM affiliates ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'codes' => $pdo->query('SELECT id, affiliate_id, code, is_active, deactivated_at FROM affiliate_codes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'events' => $pdo->query("SELECT to_regclass('public.affiliate_lifecycle_events') IS NOT NULL")->fetchColumn()
            ? $pdo->query('SELECT id, affiliate_id, from_status, to_status, event_kind FROM affiliate_lifecycle_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
            : null,
        'functions' => array_map('strval', $pdo->query(<<<'SQL'
            SELECT p.proname FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.proname LIKE '%affiliate%' ORDER BY 1
            SQL)->fetchAll(PDO::FETCH_COLUMN)),
        'singleActiveIndex' => (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_indexes WHERE indexname = 'affiliate_codes_single_active')")->fetchColumn(),
        'coherenceCheck' => (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_constraint WHERE conname = 'affiliate_codes_activation_coherence_check')")->fetchColumn(),
        'userGrants' => array_map('strval', $pdo->query(<<<'SQL'
            SELECT a.attname FROM pg_attribute AS a
            WHERE a.attrelid = 'public.users'::regclass AND a.attnum > 0 AND NOT a.attisdropped
              AND has_column_privilege('digitrove_affiliate_executor', 'public.users', a.attname, 'SELECT')
            ORDER BY 1
            SQL)->fetchAll(PDO::FETCH_COLUMN)),
    ];
}

// ── up() refuses dirty data, atomically ─────────────────────────────────────────

it('refuses to apply 000031 when existing code data already breaks the new invariants', function (
    string $scenario,
    Closure $dirty,
    string $fragment,
) {
    ['harness' => $harness, 'pdo' => $pdo] = p6d11Harness('dirty');

    try {
        $affiliateId = p6d11SeedAffiliate($pdo, 'active');
        $dirty($pdo, $affiliateId);

        $before = p6d11Snapshot($pdo);
        $refused = false;

        try {
            $harness->applyExactMigrations([P6D11_BOUNDARY]);
        } catch (Throwable $exception) {
            // Any refusal counts. Matching the full sentence is unreliable: artisan
            // line-wraps its error output, so a long fragment is split across lines and a
            // substring test reports a false negative. What actually matters — that
            // nothing was created and nothing repaired — is asserted below.
            $refused = str_contains($exception->getMessage(), 'P6-D1.1')
                || str_contains($exception->getMessage(), $fragment);
        }

        expect($refused)->toBeTrue("{$scenario}: the migration was allowed to proceed");

        // ── ATOMICITY ── nothing was created, nothing was repaired.
        $after = p6d11Snapshot($pdo);

        expect($harness->ranMigrations())->toHaveCount(46)
            ->and($pdo->query("SELECT to_regclass('public.affiliate_lifecycle_events')")->fetchColumn())->toBeNull()
            ->and($after['singleActiveIndex'])->toBeFalse()
            ->and($after['coherenceCheck'])->toBeFalse()
            ->and($after['userGrants'])->toBe([])
            ->and($after['functions'])->toBe($before['functions'])
            ->and($after['codes'])->toEqual($before['codes'])
            ->and($after['affiliates'])->toEqual($before['affiliates']);
    } finally {
        $harness->drop();
    }
})->with([
    'two active codes' => [
        'two active codes',
        function (PDO $pdo, int $affiliateId): void {
            $pdo->exec("INSERT INTO affiliate_codes (affiliate_id, code, is_active, created_at, updated_at)
                VALUES ({$affiliateId}, 'AAAA1111', true, now(), now()), ({$affiliateId}, 'BBBB2222', true, now(), now())");
        },
        'more than one active code',
    ],
    'active code on a non-active affiliate' => [
        'active code on a non-active affiliate',
        function (PDO $pdo, int $affiliateId): void {
            $pdo->exec("UPDATE affiliates SET status = 'suspended', suspended_at = now() WHERE id = {$affiliateId}");
            $pdo->exec("INSERT INTO affiliate_codes (affiliate_id, code, is_active, created_at, updated_at)
                VALUES ({$affiliateId}, 'CCCC3333', true, now(), now())");
        },
        'not active',
    ],
    'active code carrying a deactivation date' => [
        'active code carrying a deactivation date',
        function (PDO $pdo, int $affiliateId): void {
            $pdo->exec("INSERT INTO affiliate_codes (affiliate_id, code, is_active, deactivated_at, created_at, updated_at)
                VALUES ({$affiliateId}, 'DDDD4444', true, now(), now(), now())");
        },
        'disagree with their own deactivation timestamp',
    ],
]);

// ── Rollback ────────────────────────────────────────────────────────────────────

it('rolls back 47 to 46 and back on an empty database', function () {
    ['harness' => $harness, 'pdo' => $pdo] = p6d11Harness('empty');

    try {
        $harness->applyExactMigrations([P6D11_BOUNDARY]);
        $applied = p6d11Snapshot($pdo);

        expect($harness->ranMigrations())->toHaveCount(47)
            ->and($applied['singleActiveIndex'])->toBeTrue()
            ->and($applied['coherenceCheck'])->toBeTrue()
            ->and($applied['userGrants'])->toBe(['deleted_at', 'id', 'role', 'status'])
            ->and($applied['functions'])->toHaveCount(21);

        $harness->rollbackExactMigrations([P6D11_BOUNDARY]);
        $back = p6d11Snapshot($pdo);

        expect($harness->ranMigrations())->toHaveCount(46)
            ->and($pdo->query("SELECT to_regclass('public.affiliate_lifecycle_events')")->fetchColumn())->toBeNull()
            ->and($back['singleActiveIndex'])->toBeFalse()
            ->and($back['coherenceCheck'])->toBeFalse()
            // The column grants on users are revoked exactly.
            ->and($back['userGrants'])->toBe([])
            // P6-D1 survives untouched: its five policy authorities and three D0 guards.
            ->and($back['functions'])->toHaveCount(8)
            ->and((string) $pdo->query("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE relname = 'affiliates'")->fetchColumn())
            ->toBe('digitrove_affiliate_executor')
            // The cluster-global role is never dropped.
            ->and((int) $pdo->query("SELECT count(*) FROM pg_roles WHERE rolname = 'digitrove_affiliate_executor'")->fetchColumn())->toBe(1);

        $harness->applyExactMigrations([P6D11_BOUNDARY]);

        expect($harness->ranMigrations())->toHaveCount(47)
            ->and(p6d11Snapshot($pdo)['functions'])->toHaveCount(21);
    } finally {
        $harness->drop();
    }
});

/**
 * The rule is "refuse when it would DESTROY", not "refuse when the tables are used".
 * Affiliates and rotated codes are perfectly representable by P6-D1, so a database holding
 * them — but no lifecycle event — must roll back cleanly, values untouched.
 */
it('rolls back with affiliates and rotated codes present, as long as the ledger is empty', function () {
    ['harness' => $harness, 'pdo' => $pdo] = p6d11Harness('nodata');

    try {
        $affiliateId = p6d11SeedAffiliate($pdo, 'active');
        // A rotation history, expressed entirely in P6-D1 terms.
        $pdo->exec("INSERT INTO affiliate_codes (affiliate_id, code, is_active, deactivated_at, created_at, updated_at)
            VALUES ({$affiliateId}, 'OLD00000001', false, now(), now(), now())");
        $pdo->exec("INSERT INTO affiliate_codes (affiliate_id, code, is_active, created_at, updated_at)
            VALUES ({$affiliateId}, 'NEW00000001', true, now(), now())");

        $harness->applyExactMigrations([P6D11_BOUNDARY]);
        $before = p6d11Snapshot($pdo);

        expect($before['events'])->toBe([]);

        $harness->rollbackExactMigrations([P6D11_BOUNDARY]);
        $after = p6d11Snapshot($pdo);

        expect($harness->ranMigrations())->toHaveCount(46)
            // Not one value moved.
            ->and($after['affiliates'])->toEqual($before['affiliates'])
            ->and($after['codes'])->toEqual($before['codes'])
            ->and($after['codes'])->toHaveCount(2);
    } finally {
        $harness->drop();
    }
});

/**
 * The ledger is the ONLY record of how each affiliate reached its state — the snapshot has
 * one slot per event type and physically cannot hold it. Dropping it is destructive, so
 * the downgrade refuses before touching anything.
 */
it('refuses to roll back once the lifecycle ledger holds history, and changes nothing', function () {
    ['harness' => $harness, 'pdo' => $pdo] = p6d11Harness('populated');

    try {
        $harness->applyExactMigrations([P6D11_BOUNDARY]);

        $pdo->exec("INSERT INTO users (email, password_hash, role, status, created_at, updated_at)
            VALUES ('cust@x.test', 'x', 'customer', 'active', now(), now()), ('adm@x.test', 'x', 'admin', 'active', now(), now())");
        $userId = (int) $pdo->query("SELECT id FROM users WHERE email = 'cust@x.test'")->fetchColumn();
        $adminId = (int) $pdo->query("SELECT id FROM users WHERE email = 'adm@x.test'")->fetchColumn();

        $affiliateId = (int) $pdo->query("SELECT affiliate_id FROM submit_affiliate_application({$userId})")->fetchColumn();
        $pdo->query("SELECT affiliate_id FROM review_affiliate_application({$affiliateId}, 'approve', {$adminId}, NULL)")->fetchAll();
        $pdo->query("SELECT affiliate_id FROM suspend_affiliate({$affiliateId}, {$adminId}, NULL)")->fetchAll();
        $pdo->query("SELECT affiliate_id FROM reactivate_affiliate({$affiliateId}, {$adminId})")->fetchAll();

        $before = p6d11Snapshot($pdo);

        expect($before['events'])->toHaveCount(4);

        $refused = false;

        try {
            $harness->rollbackExactMigrations([P6D11_BOUNDARY]);
        } catch (Throwable $exception) {
            $refused = str_contains($exception->getMessage(), 'Cannot roll back P6-D1.1');
        }

        expect($refused)->toBeTrue('a destructive downgrade was allowed to proceed');

        // ── ATOMICITY ── still entirely P6-D1.1.
        expect(p6d11Snapshot($pdo))->toEqual($before)
            ->and($harness->ranMigrations())->toHaveCount(47);
    } finally {
        $harness->drop();
    }
});
