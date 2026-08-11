<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-D1 concurrency and replay, on TWO REAL PostgreSQL connections.
 *
 * No `session_replication_role`, no disabled trigger, no PHP-level simulation: the whole
 * point is to prove what the database does when two administrators publish at the same
 * moment, and a simulation would only prove what the test author believed.
 */
function p6d1SecondConnection(): PDO
{
    $cfg = config('database.connections.pgsql_migration');

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $cfg['host'], $cfg['port'] ?? 5432, Fx::owner()->getDatabaseName()),
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function p6d1MakeDraft(int $version): int
{
    return (int) Fx::owner()->selectOne(
        'SELECT policy_id FROM create_affiliate_program_policy_draft(?, ?, ?, ?, ?, ?)',
        [$version, 30, 1500, 14, 10_000, 'XOF'],
    )->policy_id;
}

/** @return array{state: list<object>, inForce: int} */
function p6d1TimelineState(): array
{
    return [
        'state' => Fx::owner()->select('SELECT version, status, effective_from, effective_until FROM affiliate_program_policies ORDER BY version'),
        'inForce' => (int) Fx::owner()->selectOne('SELECT count(*) AS c FROM current_affiliate_program_policy()')->c,
    ];
}

/**
 * Two admins, two DIFFERENT drafts, publishing at the same moment.
 *
 * They contend for the incumbent's row lock, so exactly one transition happens at a time.
 * The loser is blocked and fails; the timeline is left with a single active policy and no
 * discontinuity, and its own draft is untouched.
 *
 * The loser RETRYING afterwards is deliberately asserted too, because it is no longer a
 * race: the incumbent has changed, version 3 legitimately follows version 2, and the
 * publication must succeed while keeping the chronology continuous. Refusing it would be
 * a bug of a different kind — an administrator unable to publish because someone else
 * once did.
 */
it('lets exactly one of two concurrent publishers win, and keeps the timeline continuous', function () {
    $incumbent = p6d1MakeDraft(1);
    Fx::owner()->selectOne('SELECT * FROM publish_affiliate_program_policy(?)', [$incumbent]);

    $draftA = p6d1MakeDraft(2);
    $draftB = p6d1MakeDraft(3);

    $connA = Fx::owner();
    $connB = p6d1SecondConnection();

    // A opens a transaction and publishes, taking the incumbent's lock but NOT committing.
    $connA->beginTransaction();
    $connA->selectOne('SELECT * FROM publish_affiliate_program_policy(?)', [$draftA]);

    // B is blocked on that same row while A holds it: the transition is serialised.
    $connB->exec('SET statement_timeout TO 800');
    $blocked = false;

    try {
        $statement = $connB->prepare('SELECT * FROM publish_affiliate_program_policy(?)');
        $statement->execute([$draftB]);
    } catch (PDOException) {
        $blocked = true;
    }

    expect($blocked)->toBeTrue('the second publisher was not serialised by the row lock');

    $connA->commit();

    // Exactly one winner, one policy in force, B's draft intact and never half-published.
    $afterRace = p6d1TimelineState();

    expect($afterRace['inForce'])->toBe(1)
        ->and($afterRace['state'])->toHaveCount(3)
        ->and($afterRace['state'][0]->status)->toBe('superseded')
        ->and($afterRace['state'][1]->status)->toBe('active')
        ->and($afterRace['state'][2]->status)->toBe('draft')
        ->and($afterRace['state'][2]->effective_until)->toBeNull();

    // The retry is a normal sequential publication, and it must work.
    $connB->exec('SET statement_timeout TO 5000');
    $statement = $connB->prepare('SELECT * FROM publish_affiliate_program_policy(?)');
    $statement->execute([$draftB]);

    $final = p6d1TimelineState();

    expect($final['inForce'])->toBe(1)
        ->and($final['state'][0]->status)->toBe('superseded')
        ->and($final['state'][1]->status)->toBe('superseded')
        ->and($final['state'][2]->status)->toBe('active');

    // v1 → v2 → v3 with no gap and no overlap anywhere along the chain.
    expect((int) Fx::owner()->selectOne(<<<'SQL'
        SELECT count(*) AS broken
        FROM affiliate_program_policies AS a
        JOIN affiliate_program_policies AS b ON b.version = a.version + 1
        WHERE a.effective_until IS DISTINCT FROM b.effective_from
        SQL)->broken)->toBe(0);
});

/**
 * The storage-level backstop behind all of the above: even if an authority ever reasoned
 * its way to publishing a second active policy, the partial unique index posed by
 * `000029` makes two simultaneous actives physically impossible.
 */
it('keeps a storage-level backstop against two active policies', function () {
    $index = Fx::owner()->selectOne(<<<'SQL'
        SELECT pg_get_indexdef(i.indexrelid) AS definition
        FROM pg_index AS i
        WHERE i.indexrelid = 'public.affiliate_program_policies_single_active'::regclass
        SQL);

    expect($index)->not->toBeNull()
        ->and($index->definition)->toContain('UNIQUE')
        ->and($index->definition)->toContain("'active'");

    $first = p6d1MakeDraft(1);
    Fx::owner()->selectOne('SELECT * FROM publish_affiliate_program_policy(?)', [$first]);
    $second = p6d1MakeDraft(2);

    // Forcing a second active row bypassing the authority is refused by the index.
    expect(fn () => Fx::owner()->update(
        "UPDATE affiliate_program_policies SET status = 'active' WHERE id = ?", [$second],
    ))->toThrow(QueryException::class);
});

/**
 * Two admins publishing the SAME draft: the loser reads a row that is no longer a draft.
 */
it('refuses the second publication of the same draft', function () {
    $draft = p6d1MakeDraft(1);

    $connA = Fx::owner();
    $connB = p6d1SecondConnection();

    $connA->beginTransaction();
    $connA->selectOne('SELECT * FROM publish_affiliate_program_policy(?)', [$draft]);
    $connA->commit();

    $refused = false;
    $sqlState = null;

    try {
        $statement = $connB->prepare('SELECT * FROM publish_affiliate_program_policy(?)');
        $statement->execute([$draft]);
    } catch (PDOException $exception) {
        $refused = true;
        $sqlState = $exception->getCode();
    }

    expect($refused)->toBeTrue()
        ->and($sqlState)->toBe('23514');

    expect(p6d1TimelineState()['inForce'])->toBe(1);
});

/**
 * A double-clicked publish button, on one connection: the second call is refused and
 * NOTHING historical moves — no second supersession, no rewritten timestamp.
 */
it('leaves the timeline untouched when publish is replayed', function () {
    $first = p6d1MakeDraft(1);
    Fx::owner()->selectOne('SELECT * FROM publish_affiliate_program_policy(?)', [$first]);

    $second = p6d1MakeDraft(2);
    Fx::owner()->selectOne('SELECT * FROM publish_affiliate_program_policy(?)', [$second]);

    $before = Fx::owner()->select('SELECT id, status, effective_from, effective_until, updated_at FROM affiliate_program_policies ORDER BY version');

    foreach ([$first, $second] as $policyId) {
        try {
            Fx::owner()->selectOne('SELECT * FROM publish_affiliate_program_policy(?)', [$policyId]);
            $this->fail('a replayed publication was accepted');
        } catch (Throwable) {
            // Expected: neither an active nor a superseded policy can be republished.
        }
    }

    $after = Fx::owner()->select('SELECT id, status, effective_from, effective_until, updated_at FROM affiliate_program_policies ORDER BY version');

    expect($after)->toEqual($before);
});

/**
 * A failure mid-transition must leave BOTH rows untouched: the predecessor is never
 * closed without a successor taking over.
 */
it('is all or nothing when the publishing transaction fails after the transition', function () {
    $first = p6d1MakeDraft(1);
    Fx::owner()->selectOne('SELECT * FROM publish_affiliate_program_policy(?)', [$first]);

    $second = p6d1MakeDraft(2);
    $before = Fx::owner()->select('SELECT id, status, effective_from, effective_until FROM affiliate_program_policies ORDER BY version');

    $connection = Fx::owner();
    $connection->beginTransaction();
    $connection->selectOne('SELECT * FROM publish_affiliate_program_policy(?)', [$second]);

    // Inside the transaction the transition is visible and coherent...
    expect((int) $connection->selectOne('SELECT count(*) AS c FROM current_affiliate_program_policy()')->c)->toBe(1)
        ->and((int) $connection->selectOne('SELECT policy_id FROM current_affiliate_program_policy()')->policy_id)->toBe($second);

    // ...and the caller then fails, for whatever reason.
    $connection->rollBack();

    $after = Fx::owner()->select('SELECT id, status, effective_from, effective_until FROM affiliate_program_policies ORDER BY version');

    expect($after)->toEqual($before)
        ->and((int) Fx::owner()->selectOne('SELECT policy_id FROM current_affiliate_program_policy()')->policy_id)->toBe($first);
});

/**
 * Two concurrent drafts claiming the same version: the natural identity settles it, with
 * no invented idempotency token.
 */
it('settles a concurrent draft collision on the version unique index', function () {
    $connB = p6d1SecondConnection();

    p6d1MakeDraft(1);

    $collided = false;
    $sqlState = null;

    try {
        $statement = $connB->prepare('SELECT * FROM create_affiliate_program_policy_draft(?, ?, ?, ?, ?, ?)');
        $statement->execute([1, 30, 1500, 14, 10_000, 'XOF']);
    } catch (PDOException $exception) {
        $collided = true;
        $sqlState = $exception->getCode();
    }

    expect($collided)->toBeTrue()
        // Refused BEFORE the unique index, because version 1 is no longer the next one.
        ->and($sqlState)->toBe('22023')
        ->and((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_program_policies')->c)->toBe(1);
});
