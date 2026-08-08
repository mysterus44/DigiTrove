<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCrmDatabase;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-A1.3 privilege boundary. The runtime may drive a backfill through the five
 * operator-safe authorities, but can never read/write the run table, call the P6-A1.2
 * enqueue authority, or the P6-A1.1 refresh authority. PUBLIC has nothing.
 */
function p6a13AclOwner(): Connection
{
    return DB::connection('pgsql_migration');
}

function p6a13FnPriv(string $role, string $signature): bool
{
    return (bool) p6a13AclOwner()->selectOne(
        'SELECT has_function_privilege(?, ?, ?) AS granted',
        [$role, 'public.'.$signature, 'EXECUTE'],
    )->granted;
}

const P6A13_HWM = 'current_crm_commerce_rollup_backfill_high_water_mark()';
const P6A13_LIST = 'list_crm_commerce_rollup_backfill_candidates(bigint, bigint, character varying, integer)';
const P6A13_START = 'start_crm_commerce_rollup_backfill(integer)';
const P6A13_GET = 'get_crm_commerce_rollup_backfill_run(bigint)';
const P6A13_PROCESS = 'process_crm_commerce_rollup_backfill_batch(bigint)';
const P6A13_RETRY = 'retry_crm_commerce_rollup_backfill_run(bigint)';
const P6A13_ENQUEUE = 'enqueue_crm_commerce_rollup_refresh(bigint, character varying)';
const P6A13_REFRESH = 'refresh_crm_contact_commerce_rollup(bigint, character varying)';

it('grants the runtime EXECUTE on the five operator-safe backfill authorities', function () {
    foreach ([P6A13_HWM, P6A13_LIST, P6A13_START, P6A13_GET, P6A13_PROCESS, P6A13_RETRY] as $signature) {
        expect(p6a13FnPriv('digitrove_runtime', $signature))->toBeTrue();
    }
});

it('still denies the runtime the P6-A1.2 enqueue and the P6-A1.1 refresh authorities', function () {
    expect(p6a13FnPriv('digitrove_runtime', P6A13_ENQUEUE))->toBeFalse()
        ->and(p6a13FnPriv('digitrove_runtime', P6A13_REFRESH))->toBeFalse();
});

it('denies the runtime every direct privilege on the backfill run table', function () {
    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $priv) {
        expect((bool) p6a13AclOwner()->selectOne(
            'SELECT has_table_privilege(?, ?, ?) AS granted',
            ['digitrove_runtime', 'public.crm_commerce_rollup_backfill_runs', $priv],
        )->granted)->toBeFalse();
    }
});

it('grants PUBLIC no privilege on the backfill run table', function () {
    expect((bool) p6a13AclOwner()->selectOne(
        "SELECT EXISTS(SELECT 1 FROM pg_class c CROSS JOIN LATERAL aclexplode(c.relacl) a WHERE c.oid = 'public.crm_commerce_rollup_backfill_runs'::regclass AND a.grantee = 0) AS present",
    )->present)->toBeFalse();
});

it('owns every backfill object with the existing restricted executor and adds no role', function () {
    expect((string) p6a13AclOwner()->selectOne(
        "SELECT tableowner FROM pg_tables WHERE tablename = 'crm_commerce_rollup_backfill_runs'",
    )->tableowner)->toBe('digitrove_crm_executor');

    foreach ([P6A13_HWM, P6A13_LIST, P6A13_START, P6A13_GET, P6A13_PROCESS, P6A13_RETRY] as $signature) {
        expect((string) p6a13AclOwner()->selectOne(
            'SELECT pg_get_userbyid(proowner) AS owner FROM pg_proc WHERE oid = ?::regprocedure',
            ['public.'.$signature],
        )->owner)->toBe('digitrove_crm_executor');
    }

    $executor = p6a13AclOwner()->selectOne("SELECT rolcanlogin, rolinherit, rolsuper FROM pg_roles WHERE rolname = 'digitrove_crm_executor'");
    expect((bool) $executor->rolcanlogin)->toBeFalse()
        ->and((bool) $executor->rolinherit)->toBeFalse()
        ->and((bool) $executor->rolsuper)->toBeFalse();

    // No backfill-specific role was introduced.
    expect((bool) p6a13AclOwner()->selectOne(
        "SELECT EXISTS(SELECT 1 FROM pg_roles WHERE rolname ~ 'backfill') AS present",
    )->present)->toBeFalse();
});

it('lets the runtime drive the authorities but never touch the table or enqueue directly', function () {
    $runtime = DB::connection('pgsql');

    // Positive: operator-safe authorities work under the runtime identity.
    expect($runtime->select('SELECT * FROM public.list_crm_commerce_rollup_backfill_candidates(0::bigint, NULL::bigint, NULL::varchar, 1::integer)'))->toBeArray();
    expect($runtime->selectOne('SELECT status FROM public.get_crm_commerce_rollup_backfill_run(999999::bigint)'))->toBeNull();
    expect($runtime->selectOne('SELECT status FROM public.process_crm_commerce_rollup_backfill_batch(999999::bigint)')->status)->toBe('not_found');

    // Negative: direct table access, enqueue and refresh remain forbidden (42501).
    expect(fn () => $runtime->select('SELECT 1 FROM public.crm_commerce_rollup_backfill_runs'))
        ->toThrow(QueryException::class);
    expect(fn () => $runtime->select('SELECT public.enqueue_crm_commerce_rollup_refresh(1, ?)', ['XOF']))
        ->toThrow(QueryException::class);
    expect(fn () => $runtime->select('SELECT public.refresh_crm_contact_commerce_rollup(1, ?)', ['XOF']))
        ->toThrow(QueryException::class);
});
