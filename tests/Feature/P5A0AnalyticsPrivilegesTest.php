<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

function expectP5A0RuntimeDenied(string $sql, array $bindings = []): void
{
    $exception = null;

    try {
        DB::transaction(fn () => DB::statement($sql, $bindings));
    } catch (QueryException $queryException) {
        $exception = $queryException;
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe('42501');
}

it('runs ACL probes under the real restricted runtime identity', function () {
    $identity = DB::selectOne('SELECT session_user, current_user');

    expect($identity->session_user)->toBe('digitrove_runtime')
        ->and($identity->current_user)->toBe('digitrove_runtime')
        ->and(DB::selectOne('SELECT rolsuper FROM pg_roles WHERE rolname = current_user')->rolsuper)->toBeFalse();
});

it('withholds every analytics table and sequence privilege from runtime and PUBLIC', function () {
    $tables = [
        'events',
        'events_default',
        'analytics_sessions',
        'daily_sales_stats',
        'daily_product_stats',
        'daily_funnel_stats',
    ];

    $owner = DB::connection('pgsql_migration');

    foreach ($tables as $table) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            expect($owner->selectOne(
                "SELECT has_table_privilege('digitrove_runtime', ?, ?) AS allowed",
                ["public.{$table}", $privilege],
            )->allowed)->toBeFalse("runtime unexpectedly has {$privilege} on {$table}");
        }
    }

    $publicGrants = $owner->selectOne(<<<'SQL'
        SELECT COUNT(*) AS count
        FROM pg_class c
        CROSS JOIN LATERAL aclexplode(COALESCE(c.relacl, acldefault(CASE WHEN c.relkind = 'S' THEN 'S'::"char" ELSE 'r'::"char" END, c.relowner))) acl
        WHERE c.relnamespace = 'public'::regnamespace
          AND c.relname IN (
              'events', 'events_default', 'events_id_seq', 'analytics_sessions',
              'daily_sales_stats', 'daily_product_stats', 'daily_funnel_stats'
          )
          AND acl.grantee = 0
        SQL);

    expect((int) $publicGrants->count)->toBe(0)
        ->and($owner->selectOne("SELECT has_sequence_privilege('digitrove_runtime', 'public.events_id_seq', 'USAGE') AS allowed")->allowed)->toBeFalse()
        ->and($owner->selectOne("SELECT has_sequence_privilege('digitrove_runtime', 'public.events_id_seq', 'SELECT') AS allowed")->allowed)->toBeFalse()
        ->and($owner->selectOne("SELECT has_function_privilege('digitrove_runtime', 'public.prevent_analytics_events_mutation()', 'EXECUTE') AS allowed")->allowed)->toBeFalse();
});

it('denies runtime reads and writes while the migrator can append a valid event', function () {
    expectP5A0RuntimeDenied('SELECT * FROM events LIMIT 1');
    expectP5A0RuntimeDenied("INSERT INTO events (public_id, occurred_at, event_name, properties, created_at) VALUES (gen_random_uuid(), now(), 'page_view', '{}'::jsonb, now())");
    expectP5A0RuntimeDenied("UPDATE events SET event_name = 'cart_view'");
    expectP5A0RuntimeDenied('DELETE FROM events');
    expectP5A0RuntimeDenied('SELECT * FROM analytics_sessions LIMIT 1');
    expectP5A0RuntimeDenied('INSERT INTO daily_funnel_stats (day, updated_at) VALUES (CURRENT_DATE, now())');

    $owner = DB::connection('pgsql_migration');
    $owner->statement(
        "INSERT INTO events (public_id, occurred_at, event_name, properties, created_at) VALUES (gen_random_uuid(), now(), 'page_view', '{}'::jsonb, now())"
    );

    expect($owner->table('events')->count())->toBe(1);
});
