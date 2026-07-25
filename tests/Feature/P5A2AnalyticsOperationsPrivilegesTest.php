<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function expectP5A2PrivilegeDenied(Closure $callback): void
{
    $exception = null;

    try {
        $callback();
    } catch (QueryException $queryException) {
        $exception = $queryException;
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe('42501');
}

it('gives the analytics worker execute-only access and no direct table capability', function () {
    $worker = DB::connection('pgsql_analytics_worker');
    $identity = $worker->selectOne('SELECT current_user AS current_role, session_user AS session_role');

    expect($identity->current_role)->toBe('digitrove_analytics_worker')
        ->and($identity->session_role)->toBe('digitrove_analytics_worker')
        ->and($worker->selectOne('SELECT public.audit_analytics_event_partitions()::text AS audit')->audit)
        ->toBeString();

    expectP5A2PrivilegeDenied(fn () => $worker->selectOne('SELECT count(*) FROM public.events'));
    expectP5A2PrivilegeDenied(fn () => $worker->statement(
        "INSERT INTO public.daily_funnel_stats (day, updated_at) VALUES (DATE '2020-01-01', now())",
    ));
    expectP5A2PrivilegeDenied(fn () => $worker->statement('CREATE TEMP TABLE p5a2_forbidden(id integer)'));
});

it('keeps the general runtime outside every P5-A2 authority', function () {
    expectP5A2PrivilegeDenied(fn () => DB::selectOne(
        'SELECT public.refresh_authoritative_daily_analytics(current_date)',
    ));
    expectP5A2PrivilegeDenied(fn () => DB::selectOne(
        'SELECT public.ensure_analytics_events_month_partition(current_date)',
    ));
    expectP5A2PrivilegeDenied(fn () => DB::selectOne(
        'SELECT public.audit_analytics_event_partitions()',
    ));
});
