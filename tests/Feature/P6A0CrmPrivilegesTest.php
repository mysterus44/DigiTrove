<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCrmDatabase;

uses(InteractsWithCrmDatabase::class);

const P6A0_RESOLVE_SIGNATURE = 'public.resolve_crm_contact(character varying,character varying,bigint,bigint)';
const P6A0_RECORD_SIGNATURE = 'public.record_crm_marketing_consent(uuid,character varying,character varying,bigint,bigint,character varying,character varying)';
const P6A0_STATUS_SIGNATURE = 'public.has_current_marketing_consent(uuid)';

function p6a0ExpectPrivilegeDenied(Closure $callback): void
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

it('provisions a sterile NOLOGIN CRM executor with no membership inheritance', function () {
    $owner = DB::connection('pgsql_migration');
    $role = $owner->selectOne(<<<'SQL'
        SELECT rolsuper, rolcanlogin, rolinherit, rolcreaterole, rolcreatedb,
               rolreplication, rolbypassrls
        FROM pg_roles WHERE rolname = 'digitrove_crm_executor'
        SQL);

    expect($role)->not->toBeNull()
        ->and($role->rolsuper)->toBeFalse()
        ->and($role->rolcanlogin)->toBeFalse()
        ->and($role->rolinherit)->toBeFalse()
        ->and($role->rolcreaterole)->toBeFalse()
        ->and($role->rolcreatedb)->toBeFalse()
        ->and($role->rolreplication)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse()
        ->and((int) $owner->scalar(<<<'SQL'
            SELECT count(*) FROM pg_auth_members m
            JOIN pg_roles member ON member.oid = m.member
            WHERE member.rolname = 'digitrove_crm_executor'
            SQL))->toBe(0);
});

it('owns three hardened SECURITY DEFINER authorities with fixed search paths', function () {
    $rows = collect(DB::connection('pgsql_migration')->select(<<<'SQL'
        SELECT p.proname, p.prosecdef, p.proconfig, pg_get_userbyid(p.proowner) AS owner
        FROM pg_proc p
        WHERE p.pronamespace = 'public'::regnamespace
          AND p.proname IN (
              'resolve_crm_contact',
              'record_crm_marketing_consent',
              'has_current_marketing_consent'
          )
        ORDER BY p.proname
        SQL))->keyBy('proname');

    expect($rows)->toHaveCount(3);

    foreach ($rows as $row) {
        expect($row->prosecdef)->toBeTrue()
            ->and($row->owner)->toBe('digitrove_crm_executor')
            ->and($row->proconfig)->toContain('search_path=pg_catalog, public, pg_temp');
    }
});

it('gives runtime execute-only access and keeps PUBLIC dark', function () {
    $owner = DB::connection('pgsql_migration');

    foreach (['crm_contacts', 'crm_marketing_consent_events'] as $table) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'] as $privilege) {
            expect((bool) $owner->scalar(
                "SELECT has_table_privilege('digitrove_runtime', ?, ?) AS allowed",
                ["public.{$table}", $privilege],
            ))->toBeFalse("runtime unexpectedly has {$privilege} on {$table}");
        }
    }

    foreach (['crm_contacts_id_seq', 'crm_marketing_consent_events_id_seq'] as $sequence) {
        expect((bool) $owner->scalar(
            "SELECT has_sequence_privilege('digitrove_runtime', ?, 'USAGE') AS allowed",
            ["public.{$sequence}"],
        ))->toBeFalse();
    }

    foreach ([P6A0_RESOLVE_SIGNATURE, P6A0_RECORD_SIGNATURE, P6A0_STATUS_SIGNATURE] as $signature) {
        expect((bool) $owner->scalar(
            "SELECT has_function_privilege('digitrove_runtime', ?, 'EXECUTE') AS allowed",
            [$signature],
        ))->toBeTrue()
            ->and((bool) $owner->scalar(
                <<<'SQL'
                    SELECT NOT EXISTS (
                        SELECT 1
                        FROM pg_proc p,
                             LATERAL aclexplode(COALESCE(p.proacl, acldefault('f', p.proowner))) acl
                        WHERE p.oid = ?::regprocedure
                          AND acl.grantee = 0
                          AND acl.privilege_type = 'EXECUTE'
                    ) AS allowed
                    SQL,
                [$signature],
            ))->toBeTrue();
    }

    p6a0ExpectPrivilegeDenied(fn () => DB::selectOne('SELECT * FROM public.crm_contacts'));
    p6a0ExpectPrivilegeDenied(fn () => DB::statement(<<<'SQL'
        INSERT INTO public.crm_contacts
            (public_id, email, origin, status, created_at, updated_at)
        VALUES (gen_random_uuid(), 'direct@example.test', 'guest_order', 'active', now(), now())
        SQL));
});
