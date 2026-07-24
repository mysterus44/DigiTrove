<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\PhaseMigrationHarness;

function p5a1ConcurrencyCall(PDO $pdo, string $visitorId, ?string $sessionId, string $eventId, string $path): string
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT public.ingest_first_party_analytics_event(
            :visitor::uuid,
            :session::uuid,
            NULL::bigint,
            :event::uuid,
            'page_view'::varchar,
            NULL::varchar,
            NULL::bigint,
            '{}'::jsonb,
            :path::varchar,
            NULL::varchar,
            NULL::varchar,
            NULL::varchar,
            NULL::varchar,
            'desktop'::varchar,
            NULL::varchar,
            :ip_hash::varchar,
            1::smallint,
            30::integer,
            24::integer,
            4096::integer
        )
        SQL);
    $statement->execute([
        'visitor' => $visitorId,
        'session' => $sessionId,
        'event' => $eventId,
        'path' => $path,
        'ip_hash' => hash('sha256', $visitorId),
    ]);

    return (string) $statement->fetchColumn();
}

function p5a1ConcurrencyPdo(string $database, bool $runtime = true): PDO
{
    $connection = config('database.connections.'.($runtime ? 'pgsql' : 'pgsql_migration'));

    return new PDO(
        sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $connection['host'],
            $connection['port'] ?? 5432,
            $database,
        ),
        $connection['username'],
        $connection['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function p5a1ConcurrencyChild(string $database, string $visitorId, ?string $sessionId, string $path): Process
{
    $code = <<<'PHP'
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('TEST_DB_HOST'), getenv('TEST_DB_PORT'), getenv('TEST_DB_NAME')),
            getenv('TEST_DB_USER'),
            getenv('TEST_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(<<<'SQL'
                SELECT public.ingest_first_party_analytics_event(
                    :visitor::uuid, :session::uuid, NULL::bigint, :event::uuid,
                    'page_view'::varchar, NULL::varchar, NULL::bigint, '{}'::jsonb,
                    :path::varchar, NULL::varchar, NULL::varchar, NULL::varchar,
                    NULL::varchar, 'desktop'::varchar, NULL::varchar, :ip_hash::varchar,
                    1::smallint, 30::integer, 24::integer, 4096::integer
                )
                SQL);
            $stmt->execute([
                'visitor' => getenv('TEST_VISITOR_ID'),
                'session' => getenv('TEST_SESSION_ID') ?: null,
                'event' => getenv('TEST_EVENT_ID'),
                'path' => getenv('TEST_PATH'),
                'ip_hash' => hash('sha256', getenv('TEST_VISITOR_ID')),
            ]);
            $session = $stmt->fetchColumn();
            $pdo->commit();
            echo $session;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fwrite(STDERR, $exception->getCode().':'.$exception->getMessage());
            exit(1);
        }
        PHP;
    $runtime = config('database.connections.pgsql');

    return new Process([PHP_BINARY, '-r', $code], base_path(), [
        'TEST_DB_HOST' => (string) $runtime['host'],
        'TEST_DB_PORT' => (string) ($runtime['port'] ?? 5432),
        'TEST_DB_NAME' => $database,
        'TEST_DB_USER' => (string) $runtime['username'],
        'TEST_DB_PASSWORD' => (string) $runtime['password'],
        'TEST_VISITOR_ID' => $visitorId,
        'TEST_SESSION_ID' => $sessionId ?? '',
        'TEST_EVENT_ID' => (string) Str::uuid(),
        'TEST_PATH' => $path,
    ]);
}

it('serialises concurrent events on one session without losing a page view', function () {
    $migration = '2026_07_14_000017_create_analytics_ingestion_authority.php';
    $harness = new PhaseMigrationHarness('digitrove_p5a1_concurrency_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $harness->applyMigrationsThrough($migration);
        $first = $harness->runtimePdo();
        $owner = p5a1ConcurrencyPdo($harness->databaseName(), false);
        $visitorId = (string) Str::uuid();
        $sessionId = p5a1ConcurrencyCall($first, $visitorId, null, (string) Str::uuid(), '/concurrent/initial');

        $first->beginTransaction();
        p5a1ConcurrencyCall($first, $visitorId, $sessionId, (string) Str::uuid(), '/concurrent/a');
        $child = p5a1ConcurrencyChild($harness->databaseName(), $visitorId, $sessionId, '/concurrent/b');
        $child->setTimeout(15);
        $child->start();
        usleep(500_000);

        expect($child->isRunning())->toBeTrue('The second analytics ingestion did not wait on the visitor/session lock.');

        $first->commit();
        $child->wait();

        expect($child->isSuccessful())->toBeTrue($child->getErrorOutput())
            ->and(trim($child->getOutput()))->toBe($sessionId)
            ->and((int) $owner->query("SELECT page_views FROM analytics_sessions WHERE id = '{$sessionId}'")->fetchColumn())->toBe(3)
            ->and((int) $owner->query("SELECT count(*) FROM events WHERE session_id = '{$sessionId}'")->fetchColumn())->toBe(3);
    } finally {
        if (isset($first) && $first->inTransaction()) {
            $first->rollBack();
        }
        if (isset($child) && $child->isRunning()) {
            $child->stop(1);
        }
        $harness->drop();
    }

    expect(DB::connection('pgsql_migration')->table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});

it('serialises first requests per visitor but leaves distinct visitors lock-free', function () {
    $migration = '2026_07_14_000017_create_analytics_ingestion_authority.php';
    $harness = new PhaseMigrationHarness('digitrove_p5a1_first_session_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $harness->applyMigrationsThrough($migration);
        $first = $harness->runtimePdo();
        $second = p5a1ConcurrencyPdo($harness->databaseName());
        $owner = p5a1ConcurrencyPdo($harness->databaseName(), false);
        $visitorId = (string) Str::uuid();

        $first->beginTransaction();
        $sessionId = p5a1ConcurrencyCall($first, $visitorId, null, (string) Str::uuid(), '/first/a');
        $child = p5a1ConcurrencyChild($harness->databaseName(), $visitorId, null, '/first/b');
        $child->setTimeout(15);
        $child->start();
        usleep(500_000);
        expect($child->isRunning())->toBeTrue();

        // A different visitor does not share the advisory lock.
        $second->exec("SET LOCAL lock_timeout = '250ms'");
        $otherVisitor = (string) Str::uuid();
        $otherSession = p5a1ConcurrencyCall($second, $otherVisitor, null, (string) Str::uuid(), '/first/other');
        expect($otherSession)->toBeUuid();

        $first->commit();
        $child->wait();

        expect($child->isSuccessful())->toBeTrue($child->getErrorOutput())
            ->and(trim($child->getOutput()))->toBe($sessionId)
            ->and((int) $owner->query("SELECT count(*) FROM analytics_sessions WHERE visitor_id = '{$visitorId}'")->fetchColumn())->toBe(1)
            ->and((int) $owner->query("SELECT page_views FROM analytics_sessions WHERE id = '{$sessionId}'")->fetchColumn())->toBe(2);
    } finally {
        if (isset($first) && $first->inTransaction()) {
            $first->rollBack();
        }
        if (isset($child) && $child->isRunning()) {
            $child->stop(1);
        }
        $harness->drop();
    }

    expect(DB::connection('pgsql_migration')->table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});

it('uses advisory and row locks inside the single audited authority', function () {
    $definition = DB::connection('pgsql_migration')->selectOne(<<<'SQL'
        SELECT pg_get_functiondef(p.oid) AS definition
        FROM pg_proc p
        WHERE p.proname = 'ingest_first_party_analytics_event'
          AND p.pronamespace = 'public'::regnamespace
        SQL);

    expect($definition)->not->toBeNull()
        ->and($definition->definition)->toContain('pg_advisory_xact_lock')
        ->and($definition->definition)->toContain('FOR UPDATE')
        ->and($definition->definition)->toContain('page_views = page_views +');
});
