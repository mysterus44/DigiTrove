<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-B0.1 privilege boundary: the runtime gains EXECUTE on the seven read authorities
 * and NOTHING else. It still has no direct SELECT on any CRM table, so the admin UI
 * physically cannot bypass the boundary.
 */
const P6B01_AUTHORITIES = [
    'list_crm_contacts(bigint, character varying, character varying, integer)',
    'get_crm_contact(bigint)',
    'find_crm_contact_by_exact_email(character varying)',
    'list_crm_contact_consent_events(bigint, bigint, integer)',
    'list_crm_contact_commerce_rollups(bigint)',
    'list_crm_contact_segment_memberships(bigint, bigint, integer)',
    'list_crm_segment_versions(bigint, integer, integer)',
];

const P6B01_CRM_TABLES = [
    'crm_contacts',
    'crm_marketing_consent_events',
    'crm_contact_commerce_rollups',
    'crm_segments',
    'crm_segment_versions',
    'crm_segment_generations',
    'crm_segment_generation_members',
];

it('grants the runtime EXECUTE on the seven read authorities', function () {
    foreach (P6B01_AUTHORITIES as $signature) {
        expect((bool) Fx::owner()->selectOne(
            'SELECT has_function_privilege(?, ?, ?) AS granted',
            ['digitrove_runtime', 'public.'.$signature, 'EXECUTE'],
        )->granted)->toBeTrue();
    }
});

it('still denies the runtime any direct table privilege on CRM data', function () {
    foreach (P6B01_CRM_TABLES as $table) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $priv) {
            expect((bool) Fx::owner()->selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS granted',
                ['digitrove_runtime', 'public.'.$table, $priv],
            )->granted)->toBeFalse();
        }
    }
});

it('grants PUBLIC nothing on the read authorities', function () {
    foreach (P6B01_AUTHORITIES as $signature) {
        expect((bool) Fx::owner()->selectOne(
            "SELECT COALESCE((SELECT bool_or(a.privilege_type = 'EXECUTE') FROM pg_proc p CROSS JOIN LATERAL aclexplode(p.proacl) a WHERE p.oid = to_regprocedure(?) AND a.grantee = 0), FALSE) AS granted",
            ['public.'.$signature],
        )->granted)->toBeFalse();
    }
});

it('owns every read authority with the restricted executor and adds no role', function () {
    foreach (P6B01_AUTHORITIES as $signature) {
        expect((string) Fx::owner()->selectOne(
            'SELECT pg_get_userbyid(proowner) AS owner FROM pg_proc WHERE oid = ?::regprocedure',
            ['public.'.$signature],
        )->owner)->toBe('digitrove_crm_executor');
    }

    $executor = Fx::owner()->selectOne("SELECT rolcanlogin, rolinherit, rolsuper FROM pg_roles WHERE rolname = 'digitrove_crm_executor'");
    expect((bool) $executor->rolcanlogin)->toBeFalse()
        ->and((bool) $executor->rolinherit)->toBeFalse()
        ->and((bool) $executor->rolsuper)->toBeFalse();

    // No admin/read-specific role was introduced by this gate.
    expect((bool) Fx::owner()->selectOne(
        "SELECT EXISTS(SELECT 1 FROM pg_roles WHERE rolname ~ 'admin|reader' AND rolname LIKE 'digitrove%crm%') AS present",
    )->present)->toBeFalse();
});

it('lets the runtime read through the authorities but never touch a CRM table', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', gross: 5000);
    Fx::grantMarketingConsent($contactId);

    $runtime = DB::connection('pgsql');

    // Positive: the admin UI's real path works under the runtime identity.
    expect($runtime->select('SELECT * FROM public.list_crm_contacts(NULL::bigint, NULL::varchar, NULL::varchar, 10::integer)'))->not->toBeEmpty();
    expect($runtime->select('SELECT * FROM public.get_crm_contact(?::bigint)', [$contactId]))->toHaveCount(1);
    expect($runtime->select('SELECT * FROM public.list_crm_contact_consent_events(?::bigint, NULL::bigint, 10::integer)', [$contactId]))->toHaveCount(1);
    expect($runtime->select('SELECT * FROM public.list_crm_contact_commerce_rollups(?::bigint)', [$contactId]))->toHaveCount(1);
    expect($runtime->select('SELECT * FROM public.list_crm_contact_segment_memberships(?::bigint, NULL::bigint, 10::integer)', [$contactId]))->toBeArray();

    // Negative: every CRM table stays forbidden (42501).
    foreach (P6B01_CRM_TABLES as $table) {
        expect(fn () => $runtime->select('SELECT 1 FROM public.'.$table))->toThrow(QueryException::class);
    }

    // Negative: the P6-A2 internal validator/matcher remain out of reach.
    expect(fn () => $runtime->select("SELECT public.crm_segment_contact_matches_v1(1::bigint, '{}'::jsonb)"))
        ->toThrow(QueryException::class);
});
