<?php

use Illuminate\Support\Facades\DB;

it('provisions sterile worker identities and three pinned security definer authorities', function () {
    $owner = DB::connection('pgsql_migration');
    $roles = $owner->select(<<<'SQL'
        SELECT rolname, rolsuper, rolcanlogin, rolcreatedb, rolcreaterole,
               rolreplication, rolbypassrls, rolinherit
        FROM pg_roles
        WHERE rolname IN ('digitrove_analytics_worker', 'digitrove_analytics_rollup_executor')
        ORDER BY rolname
        SQL);
    $functions = $owner->select(<<<'SQL'
        SELECT p.proname, p.prosecdef, owner.rolname AS owner,
               array_to_string(p.proconfig, ',') AS settings
        FROM pg_proc AS p
        JOIN pg_roles AS owner ON owner.oid = p.proowner
        WHERE p.proname IN (
            'refresh_authoritative_daily_analytics',
            'ensure_analytics_events_month_partition',
            'audit_analytics_event_partitions'
        )
        ORDER BY p.proname
        SQL);

    expect($roles)->toHaveCount(2)
        ->and($roles[0]->rolname)->toBe('digitrove_analytics_rollup_executor')
        ->and($roles[0]->rolcanlogin)->toBeFalse()
        ->and($roles[1]->rolname)->toBe('digitrove_analytics_worker')
        ->and($roles[1]->rolcanlogin)->toBeTrue();

    foreach ($roles as $role) {
        expect($role->rolsuper)->toBeFalse()
            ->and($role->rolcreatedb)->toBeFalse()
            ->and($role->rolcreaterole)->toBeFalse()
            ->and($role->rolreplication)->toBeFalse()
            ->and($role->rolbypassrls)->toBeFalse()
            ->and($role->rolinherit)->toBeFalse();
    }

    expect($functions)->toHaveCount(3)
        ->and(collect($functions)->pluck('prosecdef')->unique()->all())->toBe([true])
        ->and(collect($functions)->firstWhere('proname', 'refresh_authoritative_daily_analytics')->owner)
        ->toBe('digitrove_analytics_rollup_executor')
        ->and(collect($functions)->firstWhere('proname', 'refresh_authoritative_daily_analytics')->settings)
        ->toContain('search_path=pg_catalog, public')
        ->toContain('TimeZone=UTC');
});

it('keeps the rollup function formula-only and the partition authority bounded', function () {
    $owner = DB::connection('pgsql_migration');
    $rollup = $owner->selectOne(
        "SELECT pg_get_functiondef('public.refresh_authoritative_daily_analytics(date)'::regprocedure) AS definition",
    )->definition;
    $partition = $owner->selectOne(
        "SELECT pg_get_functiondef('public.ensure_analytics_events_month_partition(date)'::regprocedure) AS definition",
    )->definition;

    expect($rollup)->toContain('oi.purchased_product_id')
        ->toContain("e.event_name = 'product_view'")
        ->toContain('FULL OUTER JOIN succeeded_refunds')
        ->toContain('pg_advisory_xact_lock')
        ->not->toContain('UPDATE public.orders')
        ->not->toContain('UPDATE public.events')
        ->and($partition)->toContain('events_default')
        ->toContain('60 months')
        ->toContain('pg_advisory_xact_lock')
        ->toContain('REVOKE ALL PRIVILEGES')
        ->not->toContain('DETACH PARTITION')
        ->not->toContain('DROP TABLE');
});
