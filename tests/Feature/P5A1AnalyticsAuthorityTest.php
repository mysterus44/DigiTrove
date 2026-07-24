<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

const P5A1_FUNCTION_SIGNATURE = 'public.ingest_first_party_analytics_event(uuid,uuid,bigint,uuid,character varying,character varying,bigint,jsonb,character varying,character varying,character varying,character varying,character varying,character varying,character varying,character varying,smallint,integer,integer,integer)';

function callP5A1Authority(
    string $visitorId,
    ?string $requestedSessionId = null,
    string $eventName = 'page_view',
    ?string $entityType = null,
    ?int $entityId = null,
    array $properties = [],
): string {
    $result = DB::selectOne(
        <<<'SQL'
            SELECT public.ingest_first_party_analytics_event(
                ?::uuid,
                ?::uuid,
                ?::bigint,
                ?::uuid,
                ?::varchar,
                ?::varchar,
                ?::bigint,
                ?::jsonb,
                ?::varchar,
                ?::varchar,
                ?::varchar,
                ?::varchar,
                ?::varchar,
                ?::varchar,
                ?::varchar,
                ?::varchar,
                ?::smallint,
                ?::integer,
                ?::integer,
                ?::integer
            ) AS effective_session_id
            SQL,
        [
            $visitorId,
            $requestedSessionId,
            null,
            (string) Str::uuid(),
            $eventName,
            $entityType,
            $entityId,
            json_encode((object) $properties, JSON_THROW_ON_ERROR),
            '/catalog',
            'example.test',
            'newsletter',
            'email',
            'summer',
            'desktop',
            null,
            hash('sha256', '127.0.0.1'),
            1,
            30,
            24,
            4096,
        ],
    );

    return (string) $result->effective_session_id;
}

function expectP5A1RuntimeDenied(string $sql): void
{
    $exception = null;

    try {
        DB::statement($sql);
    } catch (QueryException $queryException) {
        $exception = $queryException;
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe('42501');
}

it('provisions a sterile NOLOGIN analytics executor with a SET-only migrator path', function () {
    $owner = DB::connection('pgsql_migration');
    $role = $owner->selectOne(<<<'SQL'
        SELECT rolsuper, rolcanlogin, rolcreatedb, rolcreaterole, rolreplication,
               rolbypassrls, rolinherit
        FROM pg_roles
        WHERE rolname = 'digitrove_analytics_executor'
        SQL);
    $membership = $owner->selectOne(<<<'SQL'
        SELECT m.admin_option, m.inherit_option, m.set_option
        FROM pg_auth_members m
        JOIN pg_roles member ON member.oid = m.member
        JOIN pg_roles granted ON granted.oid = m.roleid
        WHERE member.rolname = 'digitrove'
          AND granted.rolname = 'digitrove_analytics_executor'
        SQL);

    expect($role)->not->toBeNull()
        ->and($role->rolsuper)->toBeFalse()
        ->and($role->rolcanlogin)->toBeFalse()
        ->and($role->rolcreatedb)->toBeFalse()
        ->and($role->rolcreaterole)->toBeFalse()
        ->and($role->rolreplication)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse()
        ->and($role->rolinherit)->toBeFalse()
        ->and($membership->admin_option)->toBeFalse()
        ->and($membership->inherit_option)->toBeFalse()
        ->and($membership->set_option)->toBeTrue()
        ->and($owner->selectOne("SELECT has_database_privilege('digitrove_analytics_executor', current_database(), 'TEMP') AS allowed")->allowed)->toBeFalse()
        ->and($owner->selectOne("SELECT has_schema_privilege('digitrove_analytics_executor', 'public', 'CREATE') AS allowed")->allowed)->toBeFalse();
});

it('owns one pinned SECURITY DEFINER function with no dynamic SQL or commerce reads', function () {
    $function = DB::connection('pgsql_migration')->selectOne(<<<'SQL'
        SELECT p.prosecdef,
               r.rolname AS owner,
               p.proconfig,
               pg_get_functiondef(p.oid) AS definition
        FROM pg_proc p
        JOIN pg_roles r ON r.oid = p.proowner
        WHERE p.oid = ?::regprocedure
        SQL, [P5A1_FUNCTION_SIGNATURE]);
    $definition = strtolower((string) $function->definition);

    expect($function)->not->toBeNull()
        ->and($function->prosecdef)->toBeTrue()
        ->and($function->owner)->toBe('digitrove_analytics_executor')
        ->and($function->proconfig)->toContain('search_path=pg_catalog, public, pg_temp')
        ->and($definition)->toContain('public.analytics_sessions')
        ->and($definition)->toContain('public.events')
        ->and($definition)->toContain('for update')
        ->and($definition)->not->toContain('execute ')
        ->and($definition)->not->toContain('public.orders')
        ->and($definition)->not->toContain('public.products')
        ->and($definition)->not->toContain('public.payments')
        ->and($definition)->not->toContain('public.download_');
});

it('grants runtime only EXECUTE while keeping direct analytics DML dark', function () {
    $owner = DB::connection('pgsql_migration');

    expect($owner->selectOne("SELECT has_function_privilege('digitrove_runtime', ?, 'EXECUTE') AS allowed", [P5A1_FUNCTION_SIGNATURE])->allowed)->toBeTrue()
        ->and($owner->selectOne("SELECT has_function_privilege('public', ?, 'EXECUTE') AS allowed", [P5A1_FUNCTION_SIGNATURE])->allowed)->toBeFalse();

    foreach (['events', 'events_default', 'analytics_sessions'] as $table) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            expect($owner->selectOne(
                "SELECT has_table_privilege('digitrove_runtime', ?, ?) AS allowed",
                ["public.{$table}", $privilege],
            )->allowed)->toBeFalse("runtime unexpectedly has {$privilege} on {$table}");
        }
    }

    expectP5A1RuntimeDenied('SELECT * FROM public.events LIMIT 1');
    expectP5A1RuntimeDenied("INSERT INTO public.events (public_id, occurred_at, event_name, properties, created_at) VALUES (gen_random_uuid(), now(), 'page_view', '{}'::jsonb, now())");
    expectP5A1RuntimeDenied('UPDATE public.analytics_sessions SET page_views = page_views + 1');
});

it('limits executor rights to event append and session management', function () {
    $owner = DB::connection('pgsql_migration');

    expect($owner->selectOne("SELECT has_table_privilege('digitrove_analytics_executor', 'public.events', 'INSERT') AS allowed")->allowed)->toBeTrue()
        ->and($owner->selectOne("SELECT has_table_privilege('digitrove_analytics_executor', 'public.events', 'SELECT') AS allowed")->allowed)->toBeFalse()
        ->and($owner->selectOne("SELECT has_table_privilege('digitrove_analytics_executor', 'public.events', 'UPDATE') AS allowed")->allowed)->toBeFalse()
        ->and($owner->selectOne("SELECT has_table_privilege('digitrove_analytics_executor', 'public.analytics_sessions', 'SELECT, INSERT, UPDATE') AS allowed")->allowed)->toBeTrue()
        ->and($owner->selectOne("SELECT has_table_privilege('digitrove_analytics_executor', 'public.analytics_sessions', 'DELETE') AS allowed")->allowed)->toBeFalse()
        ->and($owner->selectOne("SELECT has_sequence_privilege('digitrove_analytics_executor', 'public.events_id_seq', 'USAGE, SELECT') AS allowed")->allowed)->toBeTrue()
        ->and($owner->selectOne("SELECT has_table_privilege('digitrove_analytics_executor', 'public.orders', 'SELECT') AS allowed")->allowed)->toBeFalse()
        ->and($owner->selectOne("SELECT has_table_privilege('digitrove_analytics_executor', 'public.payments', 'SELECT') AS allowed")->allowed)->toBeFalse()
        ->and($owner->selectOne("SELECT has_table_privilege('digitrove_analytics_executor', 'public.download_grants', 'SELECT') AS allowed")->allowed)->toBeFalse();
});

it('appends a valid event and atomically reuses its effective session', function () {
    $visitorId = (string) Str::uuid();
    $sessionId = callP5A1Authority($visitorId);
    $reused = callP5A1Authority($visitorId, $sessionId, 'product_view', 'product', 1, ['placement' => 'catalog']);
    $owner = DB::connection('pgsql_migration');

    expect($sessionId)->toBeUuid()
        ->and($reused)->toBe($sessionId)
        ->and((int) $owner->table('analytics_sessions')->where('id', $sessionId)->value('page_views'))->toBe(1)
        ->and($owner->table('events')->where('session_id', $sessionId)->count())->toBe(2);
});

it('rejects public financial and malformed event contracts inside PostgreSQL itself', function (string $event, ?string $entityType, ?int $entityId, array $properties) {
    $exception = null;

    try {
        callP5A1Authority((string) Str::uuid(), null, $event, $entityType, $entityId, $properties);
    } catch (QueryException $queryException) {
        $exception = $queryException;
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe('23514')
        ->and($exception->getMessage())->toContain('analytics ingestion event contract is invalid');
})->with([
    'purchase' => ['purchase', null, null, []],
    'arbitrary event' => ['anything_goes', null, null, []],
    'page properties' => ['page_view', null, null, ['amount' => 100]],
    'product without placement' => ['product_view', 'product', 1, []],
    'product with extra property' => ['product_view', 'product', 1, ['placement' => 'catalog', 'revenue' => 100]],
]);

it('rolls back only P5-A1 while preserving the P5-A0 foundation and earlier phases', function () {
    $migration = '2026_07_14_000017_create_analytics_ingestion_authority.php';
    $harness = new PhaseMigrationHarness('digitrove_p5a1_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($migration);

        expect($applied)->toHaveCount(33)
            ->and(end($applied))->toBe('2026_07_14_000017_create_analytics_ingestion_authority')
            ->and($harness->countFunctions(['ingest_first_party_analytics_event']))->toBe(1)
            ->and($harness->hasTable('events'))->toBeTrue()
            ->and($harness->hasTable('analytics_sessions'))->toBeTrue();

        expect($harness->rollbackExactMigrations([$migration]))->toBe([
            '2026_07_14_000017_create_analytics_ingestion_authority',
        ])
            ->and($harness->countFunctions(['ingest_first_party_analytics_event']))->toBe(0)
            ->and($harness->hasTable('events'))->toBeTrue()
            ->and($harness->hasTable('events_default'))->toBeTrue()
            ->and($harness->hasTable('analytics_sessions'))->toBeTrue()
            ->and($harness->hasTable('daily_sales_stats'))->toBeTrue()
            ->and($harness->hasTable('orders'))->toBeTrue()
            ->and($harness->hasTable('download_logs'))->toBeTrue()
            ->and($harness->ranMigrations())->toHaveCount(32);

        $runtime = $harness->runtimePdo();
        expect((bool) $runtime->query("SELECT has_table_privilege(current_user, 'public.events', 'INSERT')")->fetchColumn())->toBeFalse()
            ->and((bool) $runtime->query("SELECT has_table_privilege(current_user, 'public.analytics_sessions', 'UPDATE')")->fetchColumn())->toBeFalse();
    } finally {
        $harness->drop();
    }

    expect(DB::connection('pgsql_migration')->table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});
