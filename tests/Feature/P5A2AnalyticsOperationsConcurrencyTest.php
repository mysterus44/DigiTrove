<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\PhaseMigrationHarness;

function p5a2RollupCall(PDO $pdo, string $day): string
{
    $statement = $pdo->prepare(
        'SELECT public.refresh_authoritative_daily_analytics(:day::date)::text',
    );
    $statement->execute(['day' => $day]);

    return (string) $statement->fetchColumn();
}

function p5a2RollupChild(string $database, string $day): Process
{
    $worker = config('database.connections.pgsql_analytics_worker');
    $code = <<<'PHP'
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('TEST_DB_HOST'), getenv('TEST_DB_PORT'), getenv('TEST_DB_NAME')),
            getenv('TEST_DB_USER'),
            getenv('TEST_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $lock = $pdo->prepare("SELECT pg_advisory_lock(hashtextextended('digitrove:analytics-rollup:' || :day, 0))");
        $lock->execute(['day' => getenv('TEST_DAY')]);
        $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ');
        $error = null;
        try {
            $statement = $pdo->prepare('SELECT public.refresh_authoritative_daily_analytics(:day::date)::text');
            $statement->execute(['day' => getenv('TEST_DAY')]);
            $result = $statement->fetchColumn();
            $pdo->commit();
            echo $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getCode().':'.$exception->getMessage();
        } finally {
            $unlock = $pdo->prepare("SELECT pg_advisory_unlock(hashtextextended('digitrove:analytics-rollup:' || :day, 0))");
            $unlock->execute(['day' => getenv('TEST_DAY')]);
        }
        if ($error !== null) {
            fwrite(STDERR, $error);
            exit(1);
        }
        PHP;

    return new Process([PHP_BINARY, '-r', $code], base_path(), [
        'TEST_DB_HOST' => (string) $worker['host'],
        'TEST_DB_PORT' => (string) ($worker['port'] ?? 5432),
        'TEST_DB_NAME' => $database,
        'TEST_DB_USER' => (string) $worker['username'],
        'TEST_DB_PASSWORD' => (string) $worker['password'],
        'TEST_DAY' => $day,
    ]);
}

it('serialises one rollup day without imposing a global analytics lock', function () {
    $harness = new PhaseMigrationHarness('digitrove_p5a2_concurrency_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $harness->applyMigrationsThrough('2026_07_14_000018_create_analytics_operations_authority.php');
        $first = $harness->analyticsWorkerPdo();
        $other = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                config('database.connections.pgsql_analytics_worker.host'),
                config('database.connections.pgsql_analytics_worker.port'),
                $harness->databaseName(),
            ),
            config('database.connections.pgsql_analytics_worker.username'),
            config('database.connections.pgsql_analytics_worker.password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $day = '2026-07-10';

        $first->query("SELECT pg_advisory_lock(hashtextextended('digitrove:analytics-rollup:{$day}', 0))");
        $first->exec('BEGIN ISOLATION LEVEL REPEATABLE READ');
        p5a2RollupCall($first, $day);

        $child = p5a2RollupChild($harness->databaseName(), $day);
        $child->setTimeout(15);
        $child->start();
        usleep(500_000);
        expect($child->isRunning())->toBeTrue('A second rollup of the same day did not wait on the advisory lock.');

        $other->query("SELECT pg_advisory_lock(hashtextextended('digitrove:analytics-rollup:2026-07-11', 0))");
        $other->exec('BEGIN ISOLATION LEVEL REPEATABLE READ');
        $otherResult = p5a2RollupCall($other, '2026-07-11');
        $other->commit();
        $other->query("SELECT pg_advisory_unlock(hashtextextended('digitrove:analytics-rollup:2026-07-11', 0))");
        expect(json_decode($otherResult, true, flags: JSON_THROW_ON_ERROR)['day'])->toBe('2026-07-11');

        $first->commit();
        $first->query("SELECT pg_advisory_unlock(hashtextextended('digitrove:analytics-rollup:{$day}', 0))");
        $child->wait();

        expect($child->isSuccessful())->toBeTrue($child->getErrorOutput())
            ->and(json_decode(trim($child->getOutput()), true, flags: JSON_THROW_ON_ERROR)['day'])->toBe($day)
            ->and((int) $harness->ownerPdo()->query(
                "SELECT count(*) FROM daily_funnel_stats WHERE day IN (DATE '2026-07-10', DATE '2026-07-11')",
            )->fetchColumn())->toBe(2);
    } finally {
        if (isset($first) && $first->inTransaction()) {
            $first->rollBack();
        }
        if (isset($first)) {
            $first->query("SELECT pg_advisory_unlock(hashtextextended('digitrove:analytics-rollup:2026-07-10', 0))");
        }
        if (isset($other) && $other->inTransaction()) {
            $other->rollBack();
        }
        if (isset($other)) {
            $other->query("SELECT pg_advisory_unlock(hashtextextended('digitrove:analytics-rollup:2026-07-11', 0))");
        }
        if (isset($child) && $child->isRunning()) {
            $child->stop(1);
        }
        $harness->drop();
    }

    expect(DB::connection('pgsql_migration')->table('pg_database')
        ->where('datname', $harness->databaseName())->exists())->toBeFalse();
});
