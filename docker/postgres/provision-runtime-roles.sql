-- =============================================================================
-- P4-B0 — Cluster-level role provisioning for the PostgreSQL privilege boundary
-- (D-029.6, D-038). Idempotent. Creates ONLY the cluster identities and their
-- SET-only membership paths; it never touches business objects or object ACLs — those live
-- in the Laravel migration 000012_harden_database_runtime_privileges.php so that
-- migrate:fresh reproduces them in every database.
--
-- Roles are cluster-global, so this runs ONCE per cluster, by an administrator,
-- AFTER the container is up (the postgres entrypoint init scripts only run on a
-- fresh data volume, so this must also be invocable explicitly on existing
-- volumes). Two invocation paths, both feeding the runtime password through the
-- session GUC `digitrove.runtime_password` so the secret is never written into
-- this file, into Git, or into the command line of a DDL statement:
--
--   1. Laravel:  php artisan db:provision-runtime-roles
--      (binds the GUC with a parameterized set_config, then runs this file)
--   2. DBA/psql: psql -U digitrove -d postgres \
--        -c "SET digitrove.runtime_password TO '<pw>'" -f provision-runtime-roles.sql
--
-- The password is applied via format('… %L', …) which quotes it safely.
-- =============================================================================

\set ON_ERROR_STOP on

-- --- digitrove_download_executor -------------------------------------------------
-- NOLOGIN identity that will OWN the future G5 (SECURITY DEFINER). It is the only
-- identity allowed to increment download_grants.downloads_count, and it is not
-- assumable by the runtime. NOINHERIT: it relies on its own direct grants only.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'digitrove_download_executor') THEN
        CREATE ROLE digitrove_download_executor
            NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;
    END IF;
END
$$;
-- Enforce attributes even if the role pre-existed with drift.
ALTER ROLE digitrove_download_executor
    NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;

-- --- digitrove_analytics_executor ------------------------------------------------
-- NOLOGIN owner of the audited P5-A1 SECURITY DEFINER ingestion function. It is
-- never assumed by the application runtime and receives only the analytics ACLs
-- installed by migration 000017.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'digitrove_analytics_executor') THEN
        CREATE ROLE digitrove_analytics_executor
            NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;
    END IF;
END
$$;
ALTER ROLE digitrove_analytics_executor
    NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;

-- --- digitrove_analytics_rollup_executor -----------------------------------------
-- NOLOGIN owner of the P5-A2 rollup authority. It receives narrowly scoped
-- source SELECT and projection DML, but is never assumable by an application.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'digitrove_analytics_rollup_executor') THEN
        CREATE ROLE digitrove_analytics_rollup_executor
            NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;
    END IF;
END
$$;
ALTER ROLE digitrove_analytics_rollup_executor
    NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;

-- --- digitrove_crm_executor ------------------------------------------------------
-- NOLOGIN owner of the P6-A0 CRM identity and consent authorities. Direct table
-- access remains unavailable to the application runtime; only the three audited
-- SECURITY DEFINER entry points are executable.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'digitrove_crm_executor') THEN
        CREATE ROLE digitrove_crm_executor
            NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;
    END IF;
END
$$;
ALTER ROLE digitrove_crm_executor
    NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;

-- --- digitrove_analytics_worker --------------------------------------------------
-- Dedicated LOGIN for scheduled P5-A2 operations. It can execute the audited
-- authorities only; migrations revoke all direct source/projection access.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'digitrove_analytics_worker') THEN
        CREATE ROLE digitrove_analytics_worker
            LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;
    END IF;
END
$$;
ALTER ROLE digitrove_analytics_worker
    LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;

-- --- digitrove_analytics_reader --------------------------------------------------
-- Dedicated LOGIN for P5-A3 dashboards. Object ACLs are installed only by
-- migration 000019; this cluster role has no membership path.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'digitrove_analytics_reader') THEN
        CREATE ROLE digitrove_analytics_reader
            LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;
    END IF;
END
$$;
ALTER ROLE digitrove_analytics_reader
    LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;

-- --- digitrove_runtime -----------------------------------------------------------
-- Restricted LOGIN identity for the application, workers and business tests. No
-- superuser, no DDL, no ability to become another role.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'digitrove_runtime') THEN
        CREATE ROLE digitrove_runtime
            LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;
    END IF;
END
$$;
ALTER ROLE digitrove_runtime
    LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;

-- Password from the session GUC, applied through %L (safe quoting). Fail-closed
-- if the caller did not provide it.
DO $$
DECLARE
    pw text := current_setting('digitrove.runtime_password', true);
BEGIN
    IF pw IS NULL OR length(pw) = 0 THEN
        RAISE EXCEPTION 'P4-B0 provisioning: digitrove.runtime_password must be set before running this script';
    END IF;

    EXECUTE format('ALTER ROLE digitrove_runtime PASSWORD %L', pw);
END
$$;

DO $$
DECLARE
    pw text := current_setting('digitrove.analytics_reader_password', true);
BEGIN
    IF pw IS NULL OR length(pw) = 0 THEN
        RAISE EXCEPTION 'P5-A3 provisioning: digitrove.analytics_reader_password must be set before running this script';
    END IF;

    EXECUTE format('ALTER ROLE digitrove_analytics_reader PASSWORD %L', pw);
END
$$;

DO $$
DECLARE
    pw text := current_setting('digitrove.analytics_worker_password', true);
BEGIN
    IF pw IS NULL OR length(pw) = 0 THEN
        RAISE EXCEPTION 'P5-A2 provisioning: digitrove.analytics_worker_password must be set before running this script';
    END IF;

    EXECUTE format('ALTER ROLE digitrove_analytics_worker PASSWORD %L', pw);
END
$$;

-- --- Sole membership path --------------------------------------------------------
-- The migrator/owner (digitrove) may ASSUME the executor identity ONLY via
-- SET ROLE (no inherited privileges, no admin option). This is what lets a
-- non-superuser migrator create/own G5 during the migration, and nothing else.
GRANT digitrove_download_executor TO digitrove WITH INHERIT FALSE, SET TRUE, ADMIN FALSE;
GRANT digitrove_analytics_executor TO digitrove WITH INHERIT FALSE, SET TRUE, ADMIN FALSE;
GRANT digitrove_analytics_rollup_executor TO digitrove WITH INHERIT FALSE, SET TRUE, ADMIN FALSE;
GRANT digitrove_crm_executor TO digitrove WITH INHERIT FALSE, SET TRUE, ADMIN FALSE;

-- --- Fail-closed guardrails ------------------------------------------------------
DO $$
BEGIN
    IF (SELECT rolsuper FROM pg_roles WHERE rolname = 'digitrove_runtime') THEN
        RAISE EXCEPTION 'P4-B0 provisioning: digitrove_runtime must not be superuser';
    END IF;

    IF (SELECT rolcanlogin FROM pg_roles WHERE rolname = 'digitrove_download_executor') THEN
        RAISE EXCEPTION 'P4-B0 provisioning: digitrove_download_executor must be NOLOGIN';
    END IF;

    IF (SELECT rolcanlogin FROM pg_roles WHERE rolname = 'digitrove_analytics_executor') THEN
        RAISE EXCEPTION 'P5-A1 provisioning: digitrove_analytics_executor must be NOLOGIN';
    END IF;

    IF (SELECT rolcanlogin FROM pg_roles WHERE rolname = 'digitrove_analytics_rollup_executor') THEN
        RAISE EXCEPTION 'P5-A2 provisioning: digitrove_analytics_rollup_executor must be NOLOGIN';
    END IF;

    IF (SELECT rolcanlogin FROM pg_roles WHERE rolname = 'digitrove_crm_executor') THEN
        RAISE EXCEPTION 'P6-A0 provisioning: digitrove_crm_executor must be NOLOGIN';
    END IF;

    IF NOT (SELECT rolcanlogin FROM pg_roles WHERE rolname = 'digitrove_analytics_worker') THEN
        RAISE EXCEPTION 'P5-A2 provisioning: digitrove_analytics_worker must be LOGIN';
    END IF;

    IF NOT (SELECT rolcanlogin FROM pg_roles WHERE rolname = 'digitrove_analytics_reader') THEN
        RAISE EXCEPTION 'P5-A3 provisioning: digitrove_analytics_reader must be LOGIN';
    END IF;

    -- The runtime must never be able to become the migrator or the executor.
    IF EXISTS (
        SELECT 1
        FROM pg_auth_members m
        JOIN pg_roles member ON member.oid = m.member
        JOIN pg_roles granted ON granted.oid = m.roleid
        WHERE member.rolname IN ('digitrove_runtime', 'digitrove_analytics_worker', 'digitrove_analytics_reader')
          AND granted.rolname IN (
              'digitrove',
              'digitrove_download_executor',
              'digitrove_analytics_executor',
              'digitrove_analytics_rollup_executor',
              'digitrove_crm_executor'
          )
    ) THEN
        RAISE EXCEPTION 'P5-A2 provisioning: runtime identities must not be members of migrator or executor roles';
    END IF;
END
$$;
