<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-D1.1 concurrency, on TWO REAL PostgreSQL connections.
 *
 * The distinction this suite exists to prove: a row lock guarantees ORDER, it does not
 * prove the command is still RELEVANT once the lock is acquired. For most transitions the
 * state machine settles that by itself — approve after reject is refused because the row
 * is no longer pending. Rotation was the exception, and it now carries the identity of the
 * code it means to replace.
 */
function p6d11Conn(): PDO
{
    $cfg = config('database.connections.pgsql_migration');

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $cfg['host'], $cfg['port'] ?? 5432, Fx::owner()->getDatabaseName()),
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function p6d11CUser(UserRole $role = UserRole::Customer): int
{
    return (int) User::factory()->create(['role' => $role, 'status' => UserStatus::Active])->id;
}

function p6d11CApply(int $userId): int
{
    return (int) Fx::owner()->selectOne('SELECT affiliate_id FROM submit_affiliate_application(?)', [$userId])->affiliate_id;
}

function p6d11CApprove(int $affiliateId, int $adminId): void
{
    Fx::owner()->selectOne('SELECT * FROM review_affiliate_application(?, ?, ?, ?)', [$affiliateId, 'approve', $adminId, null]);
}

/** Runs a statement on a second connection, returning the SQLSTATE when it fails. */
function p6d11Try(PDO $pdo, string $sql, array $bindings): ?string
{
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($bindings);
        $statement->fetchAll();

        return null;
    } catch (PDOException $exception) {
        return (string) $exception->getCode();
    }
}

function p6d11State(int $affiliateId): array
{
    return [
        'status' => (string) Fx::owner()->selectOne('SELECT status FROM affiliates WHERE id = ?', [$affiliateId])->status,
        'codes' => (int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_codes WHERE affiliate_id = ?', [$affiliateId])->c,
        'active' => (int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_codes WHERE affiliate_id = ? AND is_active', [$affiliateId])->c,
        'events' => (int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_lifecycle_events WHERE affiliate_id = ?', [$affiliateId])->c,
    ];
}

// ── The race that motivated the CAS ─────────────────────────────────────────────

/**
 * Two administrators looking at the SAME active code, both pressing rotate. Serialisation
 * alone would give two rotations and three codes; the second request is stale and must be
 * refused.
 */
it('lets one of two concurrent rotations win and refuses the other as stale', function () {
    $adminId = p6d11CUser(UserRole::Admin);
    $affiliateId = p6d11CApply(p6d11CUser());
    p6d11CApprove($affiliateId, $adminId);

    $codeA = (int) Fx::owner()->selectOne('SELECT id FROM affiliate_codes WHERE affiliate_id = ? AND is_active', [$affiliateId])->id;

    $connA = Fx::owner();
    $connB = p6d11Conn();

    // A rotates inside an open transaction, holding the affiliate row.
    $connA->beginTransaction();
    $connA->selectOne('SELECT * FROM rotate_affiliate_code(?, ?, ?)', [$affiliateId, $codeA, $adminId]);

    // B, aiming at the SAME code, is blocked on that row while A holds it.
    $connB->exec('SET statement_timeout TO 800');
    $blocked = p6d11Try($connB, 'SELECT * FROM rotate_affiliate_code(?, ?, ?)', [$affiliateId, $codeA, $adminId]);

    expect($blocked)->not->toBeNull('the second rotation was not serialised by the row lock');

    $connA->commit();

    // Now unblocked, B retries the SAME request. The lock is free, so only the CAS can
    // save us here — and it does: the code B meant to replace is no longer active.
    $connB->exec('SET statement_timeout TO 5000');
    $sqlState = p6d11Try($connB, 'SELECT * FROM rotate_affiliate_code(?, ?, ?)', [$affiliateId, $codeA, $adminId]);

    expect($sqlState)->toBe('AF001');

    $state = p6d11State($affiliateId);

    // Two codes, not three: the stale request minted nothing.
    expect($state['codes'])->toBe(2)
        ->and($state['active'])->toBe(1)
        ->and($state['status'])->toBe('active')
        ->and((bool) Fx::owner()->selectOne('SELECT is_active FROM affiliate_codes WHERE id = ?', [$codeA])->is_active)->toBeFalse();
});

// ── The races the state machine settles on its own ──────────────────────────────

it('creates exactly one affiliate when the same customer applies twice at once', function () {
    $userId = p6d11CUser();
    $connB = p6d11Conn();

    $connA = Fx::owner();
    $connA->beginTransaction();
    $affiliateId = (int) $connA->selectOne('SELECT affiliate_id FROM submit_affiliate_application(?)', [$userId])->affiliate_id;

    // B collides on `affiliates_user_id_unique` — the structural backstop.
    $connB->exec('SET statement_timeout TO 800');
    expect(p6d11Try($connB, 'SELECT * FROM submit_affiliate_application(?)', [$userId]))->not->toBeNull();

    $connA->commit();

    expect(p6d11Try($connB, 'SELECT * FROM submit_affiliate_application(?)', [$userId]))->toBe('23514');

    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliates WHERE user_id = ?', [$userId])->c)->toBe(1)
        ->and(p6d11State($affiliateId)['events'])->toBe(1);
});

/**
 * Approve and reject are the SAME authority acting on the same pending row, so the loser
 * does not need a token: after the winner commits, the row is no longer pending and the
 * state machine refuses on its own.
 */
it('lets only one review decide a pending application', function (string $winner, string $loser, string $finalStatus, int $activeCodes) {
    $adminId = p6d11CUser(UserRole::Admin);
    $affiliateId = p6d11CApply(p6d11CUser());

    $connA = Fx::owner();
    $connB = p6d11Conn();

    $connA->beginTransaction();
    $connA->selectOne('SELECT * FROM review_affiliate_application(?, ?, ?, ?)', [$affiliateId, $winner, $adminId, null]);

    $connB->exec('SET statement_timeout TO 800');
    expect(p6d11Try($connB, 'SELECT * FROM review_affiliate_application(?, ?, ?, ?)', [$affiliateId, $loser, $adminId, null]))
        ->not->toBeNull('the second review was not serialised');

    $connA->commit();

    $connB->exec('SET statement_timeout TO 5000');
    expect(p6d11Try($connB, 'SELECT * FROM review_affiliate_application(?, ?, ?, ?)', [$affiliateId, $loser, $adminId, null]))
        ->toBe('23514');

    $state = p6d11State($affiliateId);

    // One submission plus exactly one review: never two review events.
    expect($state['status'])->toBe($finalStatus)
        ->and($state['events'])->toBe(2)
        ->and($state['active'])->toBe($activeCodes);
})->with([
    'approve wins' => ['approve', 'reject', 'active', 1],
    'reject wins' => ['reject', 'approve', 'rejected', 0],
]);

/**
 * `close` is terminal, so whichever of suspend/close commits first, the loser is refused —
 * `closed → *` is forbidden, and `suspend` requires `active`. No extra token is needed
 * because the state machine already invalidates the stale command.
 */
it('serialises suspend against close and leaves no incoherent code state', function () {
    $adminId = p6d11CUser(UserRole::Admin);
    $affiliateId = p6d11CApply(p6d11CUser());
    p6d11CApprove($affiliateId, $adminId);

    $connA = Fx::owner();
    $connB = p6d11Conn();

    $connA->beginTransaction();
    $connA->selectOne('SELECT * FROM close_affiliate(?, ?, ?)', [$affiliateId, $adminId, null]);

    $connB->exec('SET statement_timeout TO 800');
    expect(p6d11Try($connB, 'SELECT * FROM suspend_affiliate(?, ?, ?)', [$affiliateId, $adminId, null]))->not->toBeNull();

    $connA->commit();

    $connB->exec('SET statement_timeout TO 5000');
    expect(p6d11Try($connB, 'SELECT * FROM suspend_affiliate(?, ?, ?)', [$affiliateId, $adminId, null]))->toBe('23514');

    $state = p6d11State($affiliateId);

    // Never closed with a live code, never active with none.
    expect($state['status'])->toBe('closed')
        ->and($state['active'])->toBe(0)
        ->and($state['events'])->toBe(3);
});

it('serialises reactivate against close on a suspended affiliate', function () {
    $adminId = p6d11CUser(UserRole::Admin);
    $affiliateId = p6d11CApply(p6d11CUser());
    p6d11CApprove($affiliateId, $adminId);
    Fx::owner()->selectOne('SELECT * FROM suspend_affiliate(?, ?, ?)', [$affiliateId, $adminId, null]);

    $connA = Fx::owner();
    $connB = p6d11Conn();

    $connA->beginTransaction();
    $connA->selectOne('SELECT * FROM close_affiliate(?, ?, ?)', [$affiliateId, $adminId, null]);

    $connB->exec('SET statement_timeout TO 800');
    expect(p6d11Try($connB, 'SELECT * FROM reactivate_affiliate(?, ?)', [$affiliateId, $adminId]))->not->toBeNull();

    $connA->commit();

    $connB->exec('SET statement_timeout TO 5000');
    expect(p6d11Try($connB, 'SELECT * FROM reactivate_affiliate(?, ?)', [$affiliateId, $adminId]))->toBe('23514');

    expect(p6d11State($affiliateId))->toBe(['status' => 'closed', 'codes' => 1, 'active' => 0, 'events' => 4]);
});

it('serialises a reapplication against a concurrent admin decision', function () {
    $adminId = p6d11CUser(UserRole::Admin);
    $userId = p6d11CUser();
    $affiliateId = p6d11CApply($userId);
    Fx::owner()->selectOne('SELECT * FROM review_affiliate_application(?, ?, ?, ?)', [$affiliateId, 'reject', $adminId, null]);

    $connA = Fx::owner();
    $connB = p6d11Conn();

    // The customer reapplies while an administrator is looking at the file.
    $connA->beginTransaction();
    $connA->selectOne('SELECT * FROM submit_affiliate_application(?)', [$userId]);

    $connB->exec('SET statement_timeout TO 800');
    expect(p6d11Try($connB, 'SELECT * FROM submit_affiliate_application(?)', [$userId]))->not->toBeNull();

    $connA->commit();

    // The row is pending again, so a second reapplication is refused…
    $connB->exec('SET statement_timeout TO 5000');
    expect(p6d11Try($connB, 'SELECT * FROM submit_affiliate_application(?)', [$userId]))->toBe('23514');

    // …and the administrator can now review the NEW application, exactly once.
    expect(p6d11Try($connB, 'SELECT * FROM review_affiliate_application(?, ?, ?, ?)', [$affiliateId, 'approve', $adminId, null]))
        ->toBeNull();

    $state = p6d11State($affiliateId);

    expect($state['status'])->toBe('active')
        ->and($state['events'])->toBe(4)
        ->and($state['active'])->toBe(1);
});
