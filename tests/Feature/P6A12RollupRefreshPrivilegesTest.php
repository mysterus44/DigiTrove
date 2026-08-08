<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCrmDatabase;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-A1.2 runtime privilege boundary: the runtime may EXECUTE only the two drain
 * authorities (list_due, process). It can never read/write the outbox, nor call
 * enqueue or the P6-A1.1 refresh authority directly. PUBLIC has nothing.
 */
function p6a12Owner2(): Connection
{
    return DB::connection('pgsql_migration');
}

function p6a12FnPriv(string $role, string $signature): bool
{
    return (bool) p6a12Owner2()->selectOne(
        'SELECT has_function_privilege(?, ?, ?) AS granted',
        [$role, 'public.'.$signature, 'EXECUTE'],
    )->granted;
}

function p6a12TablePriv(string $role, string $priv): bool
{
    return (bool) p6a12Owner2()->selectOne(
        'SELECT has_table_privilege(?, ?, ?) AS granted',
        [$role, 'public.crm_commerce_rollup_refresh_outbox', $priv],
    )->granted;
}

const P6A12_LIST_DUE = 'list_due_crm_commerce_rollup_refreshes(integer)';
const P6A12_PROCESS = 'process_crm_commerce_rollup_refresh(bigint, character varying)';
const P6A12_ENQUEUE = 'enqueue_crm_commerce_rollup_refresh(bigint, character varying)';
const P6A12_FROM_ATTR = 'enqueue_crm_commerce_rollup_refresh_from_attribution()';
const P6A12_FROM_REFUND = 'enqueue_crm_commerce_rollup_refresh_from_refund()';
const P6A12_REFRESH = 'refresh_crm_contact_commerce_rollup(bigint, character varying)';

it('grants runtime EXECUTE on exactly list_due and process', function () {
    expect(p6a12FnPriv('digitrove_runtime', P6A12_LIST_DUE))->toBeTrue()
        ->and(p6a12FnPriv('digitrove_runtime', P6A12_PROCESS))->toBeTrue();
});

it('denies runtime EXECUTE on enqueue, the trigger authorities, and the P6-A1.1 refresh', function () {
    // has_function_privilege for the runtime already accounts for PUBLIC grants,
    // so a false here also proves PUBLIC holds no EXECUTE on these functions.
    expect(p6a12FnPriv('digitrove_runtime', P6A12_ENQUEUE))->toBeFalse()
        ->and(p6a12FnPriv('digitrove_runtime', P6A12_FROM_ATTR))->toBeFalse()
        ->and(p6a12FnPriv('digitrove_runtime', P6A12_FROM_REFUND))->toBeFalse()
        ->and(p6a12FnPriv('digitrove_runtime', P6A12_REFRESH))->toBeFalse();
});

it('denies runtime every direct privilege on the outbox table', function () {
    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $priv) {
        expect(p6a12TablePriv('digitrove_runtime', $priv))->toBeFalse();
    }
});

it('grants PUBLIC no privilege on the outbox table', function () {
    $publicHasGrant = (bool) p6a12Owner2()->selectOne(
        "SELECT EXISTS(SELECT 1 FROM pg_class c CROSS JOIN LATERAL aclexplode(c.relacl) a WHERE c.oid = 'public.crm_commerce_rollup_refresh_outbox'::regclass AND a.grantee = 0) AS present",
    )->present;

    expect($publicHasGrant)->toBeFalse();
});

it('keeps the executor a restricted NOLOGIN NOINHERIT role and adds no new role', function () {
    $executor = p6a12Owner2()->selectOne("SELECT rolcanlogin, rolinherit, rolsuper FROM pg_roles WHERE rolname = 'digitrove_crm_executor'");
    expect($executor)->not->toBeNull()
        ->and((bool) $executor->rolcanlogin)->toBeFalse()
        ->and((bool) $executor->rolinherit)->toBeFalse()
        ->and((bool) $executor->rolsuper)->toBeFalse();

    // No P6-A1.2-specific role was introduced (the analytics rollup executor is P5).
    $introducedRole = (bool) p6a12Owner2()->selectOne(
        "SELECT EXISTS(SELECT 1 FROM pg_roles WHERE rolname ~ 'crm.*(rollup|refresh)|(rollup|refresh).*crm') AS present",
    )->present;
    expect($introducedRole)->toBeFalse();

    // Every P6-A1.2 object is owned by the existing restricted executor.
    $tableOwner = (string) p6a12Owner2()->selectOne(
        "SELECT tableowner FROM pg_tables WHERE tablename = 'crm_commerce_rollup_refresh_outbox'",
    )->tableowner;
    expect($tableOwner)->toBe('digitrove_crm_executor');

    foreach ([P6A12_ENQUEUE, P6A12_FROM_ATTR, P6A12_FROM_REFUND, P6A12_LIST_DUE, P6A12_PROCESS] as $signature) {
        $owner = (string) p6a12Owner2()->selectOne(
            'SELECT pg_get_userbyid(proowner) AS owner FROM pg_proc WHERE oid = ?::regprocedure',
            ['public.'.$signature],
        )->owner;
        expect($owner)->toBe('digitrove_crm_executor');
    }
});

it('lets the runtime actually EXECUTE list_due and process but not touch the outbox', function () {
    $runtime = DB::connection('pgsql');

    // Positive: both drain authorities run under the runtime identity.
    expect($runtime->select('SELECT * FROM public.list_due_crm_commerce_rollup_refreshes(1)'))->toBeArray();
    $processed = $runtime->selectOne('SELECT status FROM public.process_crm_commerce_rollup_refresh(?, ?)', [999999, 'XOF']);
    expect($processed->status)->toBe('not_found');

    // Negative: outbox, enqueue and refresh are all forbidden (42501).
    expect(fn () => $runtime->select('SELECT 1 FROM public.crm_commerce_rollup_refresh_outbox'))
        ->toThrow(QueryException::class);
    expect(fn () => $runtime->select('SELECT public.enqueue_crm_commerce_rollup_refresh(1, ?)', ['XOF']))
        ->toThrow(QueryException::class);
    expect(fn () => $runtime->select('SELECT public.refresh_crm_contact_commerce_rollup(1, ?)', ['XOF']))
        ->toThrow(QueryException::class);
});
