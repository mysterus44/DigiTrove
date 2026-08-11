<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

/**
 * P6-D1 rollback must restore the EXACT `000029` frontier — ownership included — then
 * re-apply cleanly.
 *
 * Every inventory is read from `pg_catalog`. `information_schema` is filtered by
 * privilege, and these tables are invisible through it to any role that holds nothing on
 * them, so a contract written that way would compare two empty lists and pass on nothing.
 */
/**
 * A P6-D1 harness prepared through `000030`, with the authority helpers bound to it.
 *
 * @return array{harness: PhaseMigrationHarness, pdo: PDO, draft: Closure, publish: Closure}
 */
function p6d1Harness(string $suffix): array
{
    $harness = new PhaseMigrationHarness('digitrove_p6d1_'.$suffix.'_'.strtolower(Str::random(8)));
    $harness->create();
    $harness->applyMigrationsThrough('2026_07_14_000029_create_affiliate_schema_foundation.php');
    $harness->applyExactMigrations(['2026_07_14_000030_create_affiliate_policy_governance_authorities.php']);

    $pdo = $harness->ownerPdo();

    return [
        'harness' => $harness,
        'pdo' => $pdo,
        'draft' => static function (int $version) use ($pdo): int {
            $statement = $pdo->prepare('SELECT policy_id FROM create_affiliate_program_policy_draft(?, ?, ?, ?, ?, ?)');
            $statement->execute([$version, 30, 1500, 14, 10_000, 'XOF']);

            return (int) $statement->fetchColumn();
        },
        'publish' => static function (int $id) use ($pdo): void {
            $statement = $pdo->prepare('SELECT policy_id FROM publish_affiliate_program_policy(?)');
            $statement->execute([$id]);
            $statement->fetchAll();
        },
    ];
}

/** Everything a lossy rollback attempt could plausibly damage, in one snapshot. */
function p6d1Snapshot(PDO $pdo): array
{
    return [
        'rows' => $pdo->query('SELECT id, version, status, effective_from, effective_until, updated_at FROM affiliate_program_policies ORDER BY version')->fetchAll(PDO::FETCH_ASSOC),
        'precision' => array_map('intval', $pdo->query(<<<'SQL'
            SELECT information_schema._pg_datetime_precision(a.atttypid, a.atttypmod)
            FROM pg_attribute AS a
            WHERE a.attrelid = 'public.affiliate_program_policies'::regclass
              AND a.attname IN ('effective_from', 'effective_until')
            ORDER BY a.attname
            SQL)->fetchAll(PDO::FETCH_COLUMN)),
        'authorities' => array_map('strval', $pdo->query(<<<'SQL'
            SELECT p.proname FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.proname LIKE '%affiliate_program_polic%' ORDER BY 1
            SQL)->fetchAll(PDO::FETCH_COLUMN)),
        'tableOwners' => array_unique(array_map('strval', $pdo->query(<<<'SQL'
            SELECT pg_get_userbyid(c.relowner) FROM pg_class AS c
            JOIN pg_namespace AS n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind = 'r' AND c.relname LIKE 'affiliate%'
            SQL)->fetchAll(PDO::FETCH_COLUMN))),
        'sequenceOwners' => array_unique(array_map('strval', $pdo->query(<<<'SQL'
            SELECT pg_get_userbyid(c.relowner) FROM pg_class AS c
            JOIN pg_namespace AS n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind = 'S' AND c.relname LIKE 'affiliate%'
            SQL)->fetchAll(PDO::FETCH_COLUMN))),
        'runtimeExecutes' => (int) $pdo->query(<<<'SQL'
            SELECT count(*) FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.proname LIKE '%affiliate_program_polic%'
              AND has_function_privilege('digitrove_runtime', p.oid, 'EXECUTE')
            SQL)->fetchColumn(),
        'triggers' => array_map('strval', $pdo->query(<<<'SQL'
            SELECT t.tgname FROM pg_trigger AS t WHERE NOT t.tgisinternal AND t.tgname LIKE '%affiliate%' ORDER BY 1
            SQL)->fetchAll(PDO::FETCH_COLUMN)),
        'constraints' => (int) $pdo->query("SELECT count(*) FROM pg_constraint WHERE conname LIKE 'affiliate%'")->fetchColumn(),
    ];
}

/**
 * THE arbitrated rule (B+, lossless-only): a downgrade that would rewrite a published
 * chronology is REFUSED BEFORE ANY MUTATION.
 *
 * Narrowing `timestamptz(6)` back to `(0)` rounds every instant to the nearest second. Two
 * transitions less than a second apart then collapse onto the same value — which does not
 * merely break the period CHECK, it falsifies the timeline that explains what every past
 * commission was worth. Refusing honestly beats succeeding dishonestly.
 */
it('refuses to roll back a sub-second chronology, and changes nothing at all', function () {
    ['harness' => $harness, 'pdo' => $pdo, 'draft' => $draft, 'publish' => $publish] = p6d1Harness('lossy');

    try {
        $publish($draft(1));
        $publish($draft(2));
        $publish($draft(3));

        $before = p6d1Snapshot($pdo);

        // The data really is sub-second, otherwise this scenario proves nothing.
        $lossy = (int) $pdo->query(<<<'SQL'
            SELECT count(*) FROM affiliate_program_policies AS p
            WHERE p.effective_from <> p.effective_from::timestamptz(0)
               OR (p.effective_until IS NOT NULL AND p.effective_until <> p.effective_until::timestamptz(0))
            SQL)->fetchColumn();

        expect($before['rows'])->toHaveCount(3)
            ->and($lossy)->toBeGreaterThan(0, 'no sub-second timestamp was produced; the case is untested')
            ->and($before['precision'])->toBe([6, 6]);

        // The rollback must FAIL.
        $refused = false;

        try {
            $harness->rollbackExactMigrations(['2026_07_14_000030_create_affiliate_policy_governance_authorities.php']);
        } catch (Throwable $exception) {
            $refused = str_contains($exception->getMessage(), 'Cannot roll back P6-D1');
        }

        expect($refused)->toBeTrue('a lossy downgrade was allowed to proceed');

        // ── ATOMICITY ── the database is still, exactly, fully P6-D1.
        $after = p6d1Snapshot($pdo);

        expect($after)->toEqual($before)
            ->and($after['rows'])->toEqual($before['rows'])
            ->and($after['precision'])->toBe([6, 6])
            ->and($after['authorities'])->toHaveCount(5)
            ->and($after['tableOwners'])->toBe(['digitrove_affiliate_executor'])
            ->and($after['sequenceOwners'])->toBe(['digitrove_affiliate_executor'])
            ->and($after['runtimeExecutes'])->toBe(5);

        // And the migration is still recorded as applied: no half-rollback.
        expect($harness->ranMigrations())->toHaveCount(46);
    } finally {
        $harness->drop();
    }
});

/**
 * The rule is "refuse when LOSSY", not "refuse when non-empty". A chronology already
 * sitting exactly on the second is perfectly representable by P6-D0 and must roll back
 * cleanly, values untouched.
 *
 * The row here is a DRAFT, whose `effective_from` D-058 explicitly calls a non-authoritative
 * placeholder — so setting it to an exact second invents no history and rewrites nothing.
 */
it('rolls back a populated but lossless chronology, preserving every value', function () {
    ['harness' => $harness, 'pdo' => $pdo, 'draft' => $draft] = p6d1Harness('lossless');

    try {
        $draft(1);

        // A draft's placeholder is mutable by design; the immutability trigger only guards
        // an effective policy.
        $pdo->exec("UPDATE affiliate_program_policies SET effective_from = date_trunc('second', now()) WHERE status = 'draft'");

        $before = $pdo->query('SELECT id, version, status, effective_from, effective_until FROM affiliate_program_policies ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);

        expect($before)->toHaveCount(1)
            ->and((int) $pdo->query(<<<'SQL'
                SELECT count(*) FROM affiliate_program_policies AS p
                WHERE p.effective_from <> p.effective_from::timestamptz(0)
                SQL)->fetchColumn())->toBe(0, 'the fixture is not actually lossless');

        // The downgrade must SUCCEED — the table is not empty, but nothing would change.
        $harness->rollbackExactMigrations(['2026_07_14_000030_create_affiliate_policy_governance_authorities.php']);

        $after = $pdo->query('SELECT id, version, status, effective_from, effective_until FROM affiliate_program_policies ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);

        expect($harness->ranMigrations())->toHaveCount(45)
            // Not one value moved.
            ->and($after)->toEqual($before)
            ->and(array_map('intval', $pdo->query(<<<'SQL'
                SELECT information_schema._pg_datetime_precision(a.atttypid, a.atttypmod)
                FROM pg_attribute AS a
                WHERE a.attrelid = 'public.affiliate_program_policies'::regclass
                  AND a.attname IN ('effective_from', 'effective_until')
                ORDER BY a.attname
                SQL)->fetchAll(PDO::FETCH_COLUMN)))->toBe([0, 0])
            // And the D0 frontier is genuinely back.
            ->and((int) $pdo->query(<<<'SQL'
                SELECT count(*) FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace
                WHERE n.nspname = 'public' AND p.proname LIKE '%affiliate_program_polic%'
                SQL)->fetchColumn())->toBe(0)
            ->and((string) $pdo->query("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE relname = 'affiliate_program_policies'")->fetchColumn())
            ->toBe('digitrove')
            ->and((int) $pdo->query("SELECT count(*) FROM pg_constraint WHERE conname = 'affiliate_program_policies_period_check'")->fetchColumn())
            ->toBe(1);
    } finally {
        $harness->drop();
    }
});

it('rolls back 46 to 45 and back to 46 while restoring ownership exactly', function () {
    $frontier = '2026_07_14_000029_create_affiliate_schema_foundation.php';
    $boundary = '2026_07_14_000030_create_affiliate_policy_governance_authorities.php';

    $harness = new PhaseMigrationHarness('digitrove_p6d1_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($frontier);
        $pdo = $harness->ownerPdo();

        /** @return list<string> */
        $authorities = static fn (): array => array_map('strval', $pdo->query(<<<'SQL'
            SELECT p.proname FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.proname LIKE '%affiliate_program_polic%'
            ORDER BY 1
            SQL)->fetchAll(PDO::FETCH_COLUMN));

        /** @return list<string> */
        $owners = static fn (string $kind): array => array_unique(array_map('strval', $pdo->query(
            "SELECT pg_get_userbyid(c.relowner) FROM pg_class AS c
             JOIN pg_namespace AS n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relkind = '{$kind}' AND c.relname LIKE 'affiliate%'"
        )->fetchAll(PDO::FETCH_COLUMN)));

        $relationCount = static fn (string $kind): int => (int) $pdo->query(
            "SELECT count(*) FROM pg_class AS c JOIN pg_namespace AS n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relkind = '{$kind}' AND c.relname LIKE 'affiliate%'"
        )->fetchColumn();

        /** @return list<string> */
        $d0Triggers = static fn (): array => array_map('strval', $pdo->query(<<<'SQL'
            SELECT t.tgname FROM pg_trigger AS t WHERE NOT t.tgisinternal AND t.tgname LIKE '%affiliate%'
            ORDER BY 1
            SQL)->fetchAll(PDO::FETCH_COLUMN));

        /** @return list<string> */
        $d0Functions = static fn (): array => array_map('strval', $pdo->query(<<<'SQL'
            SELECT p.proname FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.proname LIKE 'enforce_affiliate%'
            ORDER BY 1
            SQL)->fetchAll(PDO::FETCH_COLUMN));

        $constraintCount = static fn (): int => (int) $pdo->query(
            "SELECT count(*) FROM pg_constraint WHERE conname LIKE 'affiliate%'"
        )->fetchColumn();

        $precision = static fn (): array => array_map('intval', $pdo->query(<<<'SQL'
            SELECT information_schema._pg_datetime_precision(a.atttypid, a.atttypmod)
            FROM pg_attribute AS a
            WHERE a.attrelid = 'public.affiliate_program_policies'::regclass
              AND a.attname IN ('effective_from', 'effective_until')
            ORDER BY a.attname
            SQL)->fetchAll(PDO::FETCH_COLUMN));

        $runtimeExecutes = static fn (): int => (int) $pdo->query(<<<'SQL'
            SELECT count(*) FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.proname LIKE '%affiliate_program_polic%'
              AND has_function_privilege('digitrove_runtime', p.oid, 'EXECUTE')
            SQL)->fetchColumn();

        $d0Constraints = $constraintCount();
        $d0TriggerList = $d0Triggers();

        // ── 1. FRONTIER 000029 ── 45 migrations, tables under the SUPERUSER migrator.
        expect($applied)->toHaveCount(45)
            ->and($relationCount('r'))->toBe(9)
            ->and($relationCount('S'))->toBe(9)
            ->and($owners('r'))->toBe(['digitrove'])
            ->and($owners('S'))->toBe(['digitrove'])
            ->and($authorities())->toBe([])
            ->and($precision())->toBe([0, 0])
            ->and($d0TriggerList)->toHaveCount(3)
            ->and($d0Functions())->toHaveCount(3);

        // ── 2. APPLY 000030 ── the frontier closes.
        $result = $harness->applyExactMigrations([$boundary]);

        expect($result)->toContain('2026_07_14_000030_create_affiliate_policy_governance_authorities')
            ->and($harness->ranMigrations())->toHaveCount(46)
            ->and($authorities())->toBe([
                'create_affiliate_program_policy_draft',
                'current_affiliate_program_policy',
                'list_affiliate_program_policies',
                'publish_affiliate_program_policy',
                'update_affiliate_program_policy_draft',
            ])
            ->and($owners('r'))->toBe(['digitrove_affiliate_executor'])
            ->and($owners('S'))->toBe(['digitrove_affiliate_executor'])
            ->and($runtimeExecutes())->toBe(5)
            ->and($precision())->toBe([6, 6])
            // P6-D0 objects are untouched by the transfer.
            ->and($d0Triggers())->toBe($d0TriggerList)
            ->and($d0Functions())->toHaveCount(3)
            ->and($constraintCount())->toBe($d0Constraints);

        // No authority runs as superuser — the whole point of the gate.
        $superuserOwned = (int) $pdo->query(<<<'SQL'
            SELECT count(*) FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.proname LIKE '%affiliate_program_polic%'
              AND p.prosecdef AND pg_get_userbyid(p.proowner) = 'digitrove'
            SQL)->fetchColumn();

        expect($superuserOwned)->toBe(0);

        // ── 3. ROLLBACK ── the 000029 frontier returns, ownership included.
        $output = $harness->rollbackExactMigrations([$boundary]);

        expect($output)->toContain('2026_07_14_000030_create_affiliate_policy_governance_authorities')
            ->and($harness->ranMigrations())->toHaveCount(45)
            ->and($authorities())->toBe([])
            ->and($runtimeExecutes())->toBe(0)
            ->and($owners('r'))->toBe(['digitrove'])
            ->and($owners('S'))->toBe(['digitrove'])
            ->and($precision())->toBe([0, 0])
            // Everything P6-D0 owns survives untouched.
            ->and($relationCount('r'))->toBe(9)
            ->and($relationCount('S'))->toBe(9)
            ->and($d0Triggers())->toBe($d0TriggerList)
            ->and($d0Functions())->toHaveCount(3)
            ->and($constraintCount())->toBe($d0Constraints);

        // The cluster-global role is NOT dropped: other databases may rely on it, and
        // provisioning — not a migration — owns its lifecycle (P4-B0 precedent).
        expect((int) $pdo->query("SELECT count(*) FROM pg_roles WHERE rolname = 'digitrove_affiliate_executor'")->fetchColumn())
            ->toBe(1);

        // ── 4. RE-APPLY ── not a one-way door.
        $harness->applyExactMigrations([$boundary]);

        expect($harness->ranMigrations())->toHaveCount(46)
            ->and($authorities())->toHaveCount(5)
            ->and($owners('r'))->toBe(['digitrove_affiliate_executor'])
            ->and($owners('S'))->toBe(['digitrove_affiliate_executor'])
            ->and($runtimeExecutes())->toBe(5)
            ->and($constraintCount())->toBe($d0Constraints);
    } finally {
        $harness->drop();
    }
});
