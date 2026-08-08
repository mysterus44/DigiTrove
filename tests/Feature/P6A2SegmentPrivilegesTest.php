<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-A2 privilege boundary: the runtime drives the lifecycle through bounded
 * authorities only — never direct table access, never the internal validator or
 * matcher. PUBLIC has nothing, and no new role was introduced.
 */
function p6a2FnPriv(string $role, string $signature): bool
{
    return (bool) Fx::owner()->selectOne(
        'SELECT has_function_privilege(?, ?, ?) AS granted',
        [$role, 'public.'.$signature, 'EXECUTE'],
    )->granted;
}

const P6A2_RUNTIME_AUTHORITIES = [
    'create_crm_segment(character varying)',
    'create_crm_segment_version(bigint, jsonb)',
    'publish_crm_segment_version(bigint)',
    'get_crm_segment(bigint)',
    'list_crm_segments(bigint, integer)',
    'start_crm_segment_generation(bigint, integer)',
    'process_crm_segment_generation_batch(bigint)',
    'retry_crm_segment_generation(bigint)',
    'get_crm_segment_generation(bigint)',
    'list_due_crm_segment_generations(integer)',
    'list_crm_segment_current_members(bigint, bigint, integer)',
];

const P6A2_INTERNAL_AUTHORITIES = [
    'validate_crm_segment_definition_v1(jsonb)',
    'crm_segment_contact_matches_v1(bigint, jsonb)',
];

const P6A2_TABLES = [
    'crm_segments',
    'crm_segment_versions',
    'crm_segment_generations',
    'crm_segment_generation_members',
];

it('grants the runtime EXECUTE on every bounded segment authority', function () {
    foreach (P6A2_RUNTIME_AUTHORITIES as $signature) {
        expect(p6a2FnPriv('digitrove_runtime', $signature))->toBeTrue();
    }
});

it('denies the runtime the internal validator and matcher', function () {
    foreach (P6A2_INTERNAL_AUTHORITIES as $signature) {
        expect(p6a2FnPriv('digitrove_runtime', $signature))->toBeFalse();
    }
});

it('denies the runtime every direct privilege on the four segment tables', function () {
    foreach (P6A2_TABLES as $table) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $priv) {
            expect((bool) Fx::owner()->selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS granted',
                ['digitrove_runtime', 'public.'.$table, $priv],
            )->granted)->toBeFalse();
        }
    }
});

it('grants PUBLIC no privilege on any segment table', function () {
    foreach (P6A2_TABLES as $table) {
        expect((bool) Fx::owner()->selectOne(
            "SELECT EXISTS(SELECT 1 FROM pg_class c CROSS JOIN LATERAL aclexplode(c.relacl) a WHERE c.oid = 'public.".$table."'::regclass AND a.grantee = 0) AS present",
        )->present)->toBeFalse();
    }
});

it('keeps the executor restricted and introduces no segment role', function () {
    $executor = Fx::owner()->selectOne("SELECT rolcanlogin, rolinherit, rolsuper FROM pg_roles WHERE rolname = 'digitrove_crm_executor'");
    expect((bool) $executor->rolcanlogin)->toBeFalse()
        ->and((bool) $executor->rolinherit)->toBeFalse()
        ->and((bool) $executor->rolsuper)->toBeFalse();

    expect((bool) Fx::owner()->selectOne("SELECT EXISTS(SELECT 1 FROM pg_roles WHERE rolname ~ 'segment') AS present")->present)->toBeFalse();

    foreach ([...P6A2_RUNTIME_AUTHORITIES, ...P6A2_INTERNAL_AUTHORITIES] as $signature) {
        expect((string) Fx::owner()->selectOne(
            'SELECT pg_get_userbyid(proowner) AS owner FROM pg_proc WHERE oid = ?::regprocedure',
            ['public.'.$signature],
        )->owner)->toBe('digitrove_crm_executor');
    }
});

it('lets the runtime drive the lifecycle but never touch a table, the validator or the matcher', function () {
    $runtime = DB::connection('pgsql');

    // Positive: the bounded authorities run under the runtime identity.
    $segmentId = (int) $runtime->selectOne('SELECT * FROM public.create_crm_segment(?::varchar)', ['Runtime segment'])->segment_id;
    expect($segmentId)->toBeGreaterThan(0);

    $definition = json_encode(Fx::definition([
        'field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 1,
    ]), JSON_THROW_ON_ERROR);
    $versionId = (int) $runtime->selectOne(
        'SELECT * FROM public.create_crm_segment_version(?::bigint, ?::jsonb)',
        [$segmentId, $definition],
    )->version_id;
    $runtime->selectOne('SELECT * FROM public.publish_crm_segment_version(?::bigint)', [$versionId]);
    expect($runtime->select('SELECT * FROM public.list_crm_segment_current_members(?::bigint, NULL::bigint, 10::integer)', [$segmentId]))->toBeArray();

    // Negative: direct table access is forbidden (42501).
    foreach (P6A2_TABLES as $table) {
        expect(fn () => $runtime->select('SELECT 1 FROM public.'.$table))->toThrow(QueryException::class);
    }

    // Negative: the internal validator and matcher are not callable by the runtime.
    expect(fn () => $runtime->select('SELECT public.validate_crm_segment_definition_v1(?::jsonb)', [$definition]))
        ->toThrow(QueryException::class);
    expect(fn () => $runtime->select('SELECT public.crm_segment_contact_matches_v1(1::bigint, ?::jsonb)', [$definition]))
        ->toThrow(QueryException::class);
});
