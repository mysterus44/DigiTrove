<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P4-B0 — PostgreSQL runtime privilege boundary (D-029.6).
 *
 * Object-level ACLs applied by the migrator/owner. The two cluster roles
 * (digitrove_runtime, digitrove_download_executor) are provisioned separately by
 * docker/postgres/provision-runtime-roles.sql (or `php artisan
 * db:provision-runtime-roles`); this migration is fail-closed if they are absent
 * or mis-attributed. It reproduces the boundary in every database via
 * migrate:fresh, so the test suite exercises the real runtime identity.
 *
 * The boundary closes the proven bypass: the runtime cannot obtain TEMP, CREATE,
 * TRIGGER, EXECUTE on the trigger functions, nor any write to
 * download_grants.downloads_count — so it can neither forge a trigger nor touch
 * the counter. Only the NOLOGIN executor (future owner of G5, SECURITY DEFINER)
 * may increment it, and the runtime cannot become that role.
 */
return new class extends Migration
{
    /** Columns of download_grants the runtime may ever update (never downloads_count). */
    private const RUNTIME_GRANT_COLUMNS = ['revoked_at', 'revoked_reason_code', 'updated_at'];

    public function up(): void
    {
        $this->assertRolesProvisioned();

        $database = $this->quoteIdentifier($this->currentDatabase());

        // --- Database: close the TEMP vector; connect stays explicit ---
        DB::statement("REVOKE TEMPORARY ON DATABASE {$database} FROM PUBLIC");
        DB::statement("REVOKE TEMPORARY ON DATABASE {$database} FROM digitrove_runtime");
        DB::statement("REVOKE TEMPORARY ON DATABASE {$database} FROM digitrove_download_executor");
        DB::statement("GRANT CONNECT ON DATABASE {$database} TO digitrove_runtime");

        // --- Schema public: no writable schema for the runtime ---
        DB::statement('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
        DB::statement('REVOKE CREATE ON SCHEMA public FROM digitrove_runtime');
        DB::statement('REVOKE CREATE ON SCHEMA public FROM digitrove_download_executor');
        DB::statement('GRANT USAGE ON SCHEMA public TO digitrove_runtime');
        DB::statement('GRANT USAGE ON SCHEMA public TO digitrove_download_executor');

        // --- Runtime DML: explicit verbs (never ALL PRIVILEGES / TRIGGER / TRUNCATE) ---
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO digitrove_runtime');
        DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO digitrove_runtime');

        // Restrict download_grants: no table-level UPDATE (it would nullify the
        // column restriction), no direct write to downloads_count, no delete
        // (grants are revoked, never deleted — G1).
        DB::statement('REVOKE UPDATE ON download_grants FROM digitrove_runtime');
        DB::statement('REVOKE DELETE ON download_grants FROM digitrove_runtime');
        $columns = implode(', ', self::RUNTIME_GRANT_COLUMNS);
        DB::statement("GRANT UPDATE ({$columns}) ON download_grants TO digitrove_runtime");

        // --- Executor: minimal rights for the future G5 (SECURITY DEFINER) ---
        // SELECT on what G5 revalidates, UPDATE only on the counter and its clock.
        DB::statement('GRANT SELECT ON orders, order_items, download_grants, product_files TO digitrove_download_executor');
        DB::statement('GRANT UPDATE (downloads_count, updated_at) ON download_grants TO digitrove_download_executor');

        // --- Functions: no EXECUTE to PUBLIC or runtime on our trigger functions ---
        // Extension functions (citext, etc.) are left untouched — only trigger
        // functions in public are targeted. Triggers still fire without EXECUTE.
        DB::statement(<<<'SQL'
            DO $$
            DECLARE
                fn regprocedure;
            BEGIN
                FOR fn IN
                    SELECT p.oid::regprocedure
                    FROM pg_proc p
                    WHERE p.pronamespace = 'public'::regnamespace
                      AND p.prorettype = 'pg_catalog.trigger'::regtype
                LOOP
                    EXECUTE format('REVOKE EXECUTE ON FUNCTION %s FROM PUBLIC', fn);
                    EXECUTE format('REVOKE EXECUTE ON FUNCTION %s FROM digitrove_runtime', fn);
                END LOOP;
            END
            $$;
        SQL);

        // --- Default privileges: keep future objects consistent with the boundary ---
        // Future migrator-created tables/sequences auto-grant the runtime the DML
        // it needs (this is what will cover download_logs when P4-B lands). The
        // schema-scoped form is correct here: it ADDS a grant for future objects.
        DB::statement('ALTER DEFAULT PRIVILEGES FOR ROLE digitrove IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO digitrove_runtime');
        DB::statement('ALTER DEFAULT PRIVILEGES FOR ROLE digitrove IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO digitrove_runtime');

        // Future functions must NOT be born executable by PUBLIC. Two things,
        // measured on PostgreSQL 16.14:
        //   1. The GLOBAL form (no IN SCHEMA) is required to strip the built-in
        //      EXECUTE that PUBLIC receives on a new function. The `IN SCHEMA
        //      public` form records NO default-ACL entry and leaves `=X/owner` in
        //      place — that scoped form can only cancel a schema-level GRANT it
        //      previously added, never the built-in global grant.
        //   2. Default privileges apply to the role that ACTUALLY creates the
        //      object, with no inheritance from its memberships. G5 will be created
        //      under digitrove_download_executor, so the executor needs its OWN
        //      default. A non-superuser migrator cannot set another role's defaults
        //      directly (permission denied), so it does so through SET ROLE — the
        //      exact SET-only membership provisioned for the G5 ownership dance.
        DB::statement('ALTER DEFAULT PRIVILEGES FOR ROLE digitrove REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC');
        DB::statement('SET ROLE digitrove_download_executor');
        DB::statement('ALTER DEFAULT PRIVILEGES FOR ROLE digitrove_download_executor REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC');
        DB::statement('RESET ROLE');
    }

    /**
     * Fail-closed rollback (D-029.6): the boundary is NEVER re-opened. down()
     * removes the runtime/executor object grants but leaves the PUBLIC hardening
     * (TEMP/CREATE/EXECUTE) in place, never restores the insecure defaults, and
     * never drops the cluster roles or any data.
     */
    public function down(): void
    {
        // Remove what up() granted to the boundary roles — but do NOT re-grant
        // TEMP/CREATE/EXECUTE to PUBLIC (runtime CONNECT and schema USAGE are
        // left in place; they are harmless without CREATE/TEMP).
        DB::statement('ALTER DEFAULT PRIVILEGES FOR ROLE digitrove IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM digitrove_runtime');
        DB::statement('ALTER DEFAULT PRIVILEGES FOR ROLE digitrove IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM digitrove_runtime');

        DB::statement('REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM digitrove_runtime');
        DB::statement('REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public FROM digitrove_runtime');
        DB::statement('REVOKE ALL PRIVILEGES ON orders FROM digitrove_download_executor');
        DB::statement('REVOKE ALL PRIVILEGES ON order_items FROM digitrove_download_executor');
        DB::statement('REVOKE ALL PRIVILEGES ON download_grants FROM digitrove_download_executor');
        DB::statement('REVOKE ALL PRIVILEGES ON product_files FROM digitrove_download_executor');
        DB::statement('REVOKE USAGE ON SCHEMA public FROM digitrove_download_executor');

        // USAGE on schema public for the runtime is kept: without CREATE it is
        // harmless and removing it would strand any concurrent session. CONNECT is
        // also kept. The PUBLIC-side hardening is deliberately NOT reverted — in
        // particular the global default-privileges REVOKE EXECUTE (migrator and
        // executor) stays in force, so a rolled-back database never lets a future
        // function be born executable by PUBLIC again.
    }

    private function assertRolesProvisioned(): void
    {
        $runtime = DB::selectOne("SELECT rolsuper, rolcanlogin, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls FROM pg_roles WHERE rolname = 'digitrove_runtime'");
        $executor = DB::selectOne("SELECT rolsuper, rolcanlogin FROM pg_roles WHERE rolname = 'digitrove_download_executor'");

        if ($runtime === null || $executor === null) {
            throw new RuntimeException('P4-B0 migration: the runtime roles are not provisioned. Run `php artisan db:provision-runtime-roles` (or the SQL script) first.');
        }

        if ($runtime->rolsuper || $runtime->rolcreatedb || $runtime->rolcreaterole || $runtime->rolreplication || $runtime->rolbypassrls) {
            throw new RuntimeException('P4-B0 migration: digitrove_runtime has forbidden attributes (must be a plain restricted LOGIN role).');
        }

        if (! $runtime->rolcanlogin) {
            throw new RuntimeException('P4-B0 migration: digitrove_runtime must be able to log in.');
        }

        if ($executor->rolsuper || $executor->rolcanlogin) {
            throw new RuntimeException('P4-B0 migration: digitrove_download_executor must be a NOLOGIN, non-superuser role.');
        }

        // The runtime must not be a member of the migrator or the executor.
        $dangerousMembership = DB::selectOne(<<<'SQL'
            SELECT 1
            FROM pg_auth_members m
            JOIN pg_roles member ON member.oid = m.member
            JOIN pg_roles granted ON granted.oid = m.roleid
            WHERE member.rolname = 'digitrove_runtime'
              AND granted.rolname IN ('digitrove', 'digitrove_download_executor')
            LIMIT 1
        SQL);

        if ($dangerousMembership !== null) {
            throw new RuntimeException('P4-B0 migration: digitrove_runtime must not be a member of the migrator or executor role.');
        }

        // The migrator must be able to SET ROLE to the executor (the sole path
        // that lets a non-superuser migrator create and own G5 later).
        $setPath = DB::selectOne(<<<'SQL'
            SELECT m.set_option
            FROM pg_auth_members m
            JOIN pg_roles member ON member.oid = m.member
            JOIN pg_roles granted ON granted.oid = m.roleid
            WHERE member.rolname = 'digitrove'
              AND granted.rolname = 'digitrove_download_executor'
            LIMIT 1
        SQL);

        if ($setPath === null || ! $setPath->set_option) {
            throw new RuntimeException('P4-B0 migration: digitrove must hold the SET-only membership to digitrove_download_executor.');
        }
    }

    private function currentDatabase(): string
    {
        return (string) DB::selectOne('SELECT current_database() AS name')->name;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
