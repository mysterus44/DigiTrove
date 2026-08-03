<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

const P5A3_ROLLUPS = [
    'daily_sales_stats',
    'daily_product_stats',
    'daily_product_engagement_stats',
    'daily_funnel_stats',
];

const P5A3_FORBIDDEN_TABLES = [
    'events',
    'analytics_sessions',
    'users',
    'visitors',
    'products',
    'orders',
    'order_items',
    'payments',
    'refunds',
    'download_grants',
    'download_logs',
];

it('provisions a non-privileged login with no memberships', function () {
    $role = DB::connection('pgsql_migration')->selectOne(<<<'SQL'
        SELECT rolcanlogin, rolsuper, rolcreatedb, rolcreaterole, rolreplication,
               rolbypassrls, rolinherit
        FROM pg_roles
        WHERE rolname = 'digitrove_analytics_reader'
        SQL);

    expect($role)->not->toBeNull()
        ->and($role->rolcanlogin)->toBeTrue()
        ->and($role->rolsuper)->toBeFalse()
        ->and($role->rolcreatedb)->toBeFalse()
        ->and($role->rolcreaterole)->toBeFalse()
        ->and($role->rolreplication)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse()
        ->and($role->rolinherit)->toBeFalse()
        ->and(DB::connection('pgsql_migration')->scalar(<<<'SQL'
            SELECT count(*)
            FROM pg_auth_members m
            JOIN pg_roles member ON member.oid = m.member
            WHERE member.rolname = 'digitrove_analytics_reader'
            SQL))->toBe(0);
});

it('can read exactly the four projections and no raw or operational table', function () {
    $reader = DB::connection('pgsql_analytics_reader');

    expect($reader->scalar('SELECT current_user'))->toBe('digitrove_analytics_reader');

    foreach (P5A3_ROLLUPS as $table) {
        expect($reader->scalar("SELECT count(*) FROM public.{$table}"))->toBeInt();
    }

    foreach (P5A3_FORBIDDEN_TABLES as $table) {
        expectSqlState(
            fn () => $reader->select("SELECT * FROM public.{$table} LIMIT 1"),
            '42501',
        );
    }
});

it('has no write ddl temporary or function execution privilege', function () {
    $reader = DB::connection('pgsql_analytics_reader');

    foreach (P5A3_ROLLUPS as $table) {
        expectSqlState(fn () => $reader->statement("UPDATE public.{$table} SET updated_at = updated_at"), '42501');
    }

    expectSqlState(fn () => $reader->statement('CREATE TEMP TABLE p5a3_forbidden (id integer)'), '42501');
    expectSqlState(fn () => $reader->statement('CREATE TABLE public.p5a3_forbidden (id integer)'), '42501');
    expectSqlState(fn () => $reader->select('SELECT public.audit_analytics_event_partitions()'), '42501');
});

function expectSqlState(Closure $operation, string $state): void
{
    try {
        $operation();
        test()->fail("Expected PostgreSQL SQLSTATE {$state}.");
    } catch (QueryException $exception) {
        expect((string) $exception->getCode())->toBe($state);
    }
}
