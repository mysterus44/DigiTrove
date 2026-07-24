<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P5-A1 — the sole first-party analytics write authority (D-038).
 *
 * The general runtime retains zero direct analytics DML. It may execute one
 * audited SECURITY DEFINER function owned by a dedicated NOLOGIN identity.
 * Session mutation and event append happen atomically inside that function.
 */
return new class extends Migration
{
    private const FUNCTION_NAME = 'ingest_first_party_analytics_event';

    private const FUNCTION_ARGUMENTS = 'uuid, uuid, bigint, uuid, character varying, character varying, bigint, jsonb, character varying, character varying, character varying, character varying, character varying, character varying, character varying, character varying, smallint, integer, integer, integer';

    public function up(): void
    {
        $this->assertExecutorProvisioned();
        $database = $this->quoteIdentifier((string) DB::selectOne('SELECT current_database() AS name')->name);

        // The executor is deliberately sterile before receiving the exact
        // analytics capabilities below.
        DB::statement("REVOKE TEMPORARY ON DATABASE {$database} FROM digitrove_analytics_executor");
        DB::statement('REVOKE CREATE ON SCHEMA public FROM digitrove_analytics_executor');
        DB::statement('REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM digitrove_analytics_executor');
        DB::statement('REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public FROM digitrove_analytics_executor');
        DB::statement('GRANT USAGE ON SCHEMA public TO digitrove_analytics_executor');
        DB::statement('GRANT INSERT ON TABLE public.events TO digitrove_analytics_executor');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.events_id_seq TO digitrove_analytics_executor');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON TABLE public.analytics_sessions TO digitrove_analytics_executor');

        // P4-B0 default privileges grant DML to the runtime on future tables.
        // Reassert the analytics darkness before exposing only the function.
        DB::statement('REVOKE ALL PRIVILEGES ON TABLE public.events, public.events_default, public.analytics_sessions FROM PUBLIC, digitrove_runtime');
        DB::statement('REVOKE ALL PRIVILEGES ON SEQUENCE public.events_id_seq FROM PUBLIC, digitrove_runtime');

        $this->dropResidualFunction();
        DB::statement('GRANT CREATE ON SCHEMA public TO digitrove_analytics_executor');
        DB::statement('SET ROLE digitrove_analytics_executor');

        try {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION public.ingest_first_party_analytics_event(
                    p_visitor_id UUID,
                    p_requested_session_id UUID,
                    p_authenticated_user_id BIGINT,
                    p_event_public_id UUID,
                    p_event_name VARCHAR,
                    p_entity_type VARCHAR,
                    p_entity_id BIGINT,
                    p_properties JSONB,
                    p_page_path VARCHAR,
                    p_referrer_host VARCHAR,
                    p_utm_source VARCHAR,
                    p_utm_medium VARCHAR,
                    p_utm_campaign VARCHAR,
                    p_device_type VARCHAR,
                    p_country_code VARCHAR,
                    p_ip_hash VARCHAR,
                    p_ip_hash_key_version SMALLINT,
                    p_session_ttl_minutes INTEGER,
                    p_session_max_hours INTEGER,
                    p_properties_max_bytes INTEGER
                )
                RETURNS UUID
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = pg_catalog, public, pg_temp
                AS $$
                DECLARE
                    v_occurred_at TIMESTAMPTZ := clock_timestamp();
                    v_effective_session_id UUID;
                    v_session_found BOOLEAN := FALSE;
                BEGIN
                    IF p_visitor_id IS NULL OR p_event_public_id IS NULL THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'analytics ingestion requires server-generated visitor and event identifiers';
                    END IF;

                    IF p_session_ttl_minutes < 1
                        OR p_session_ttl_minutes > 1440
                        OR p_session_max_hours < 1
                        OR p_session_max_hours > 168
                        OR p_session_ttl_minutes > (p_session_max_hours * 60)
                    THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'analytics ingestion session bounds are invalid';
                    END IF;

                    IF p_properties_max_bytes < 2 OR p_properties_max_bytes > 4096 THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'analytics ingestion properties bound is invalid';
                    END IF;

                    IF p_properties IS NULL
                        OR jsonb_typeof(p_properties) <> 'object'
                        OR octet_length(p_properties::text) > p_properties_max_bytes
                    THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'analytics ingestion properties are invalid';
                    END IF;

                    IF p_page_path IS NULL
                        OR length(p_page_path) > 2048
                        OR left(p_page_path, 1) <> '/'
                        OR left(p_page_path, 2) = '//'
                        OR strpos(p_page_path, '://') > 0
                        OR strpos(p_page_path, '?') > 0
                        OR strpos(p_page_path, '#') > 0
                        OR strpos(p_page_path, E'\r') > 0
                        OR strpos(p_page_path, E'\n') > 0
                    THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'analytics ingestion page path is invalid';
                    END IF;

                    IF NOT (
                        CASE
                            WHEN p_event_name = 'page_view'
                                AND p_entity_type IS NULL
                                AND p_entity_id IS NULL
                                AND p_properties = '{}'::jsonb
                            THEN TRUE
                            WHEN p_event_name = 'product_view'
                                AND p_entity_type = 'product'
                                AND p_entity_id IS NOT NULL
                                AND p_entity_id > 0
                                AND p_properties ? 'placement'
                                AND (SELECT count(*) FROM jsonb_object_keys(p_properties)) = 1
                                AND p_properties->>'placement' IN ('catalog', 'search', 'recommendation', 'direct')
                            THEN TRUE
                            ELSE FALSE
                        END IS TRUE
                    ) THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'analytics ingestion event contract is invalid';
                    END IF;

                    IF NOT (
                        CASE
                            WHEN p_ip_hash IS NULL AND p_ip_hash_key_version IS NULL THEN TRUE
                            WHEN p_ip_hash ~ '^[0-9a-f]{64}$'
                                AND p_ip_hash_key_version IS NOT NULL
                                AND p_ip_hash_key_version > 0
                            THEN TRUE
                            ELSE FALSE
                        END IS TRUE
                    ) THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'analytics ingestion IP digest is invalid';
                    END IF;

                    -- One visitor-scoped advisory lock closes the "two first
                    -- requests, no session cookie yet" race without touching a
                    -- Commerce row or creating a global lock.
                    PERFORM pg_advisory_xact_lock(hashtextextended(p_visitor_id::text, 0));

                    IF p_requested_session_id IS NOT NULL THEN
                        SELECT s.id
                        INTO v_effective_session_id
                        FROM public.analytics_sessions AS s
                        WHERE s.id = p_requested_session_id
                          AND s.visitor_id = p_visitor_id
                          AND s.ended_at IS NULL
                          AND s.last_seen_at > v_occurred_at - make_interval(mins => p_session_ttl_minutes)
                          AND s.started_at > v_occurred_at - make_interval(hours => p_session_max_hours)
                          AND (
                              s.user_id IS NULL
                              OR p_authenticated_user_id IS NULL
                              OR s.user_id = p_authenticated_user_id
                          )
                        FOR UPDATE;

                        v_session_found := FOUND;
                    END IF;

                    IF NOT v_session_found THEN
                        SELECT s.id
                        INTO v_effective_session_id
                        FROM public.analytics_sessions AS s
                        WHERE s.visitor_id = p_visitor_id
                          AND s.ended_at IS NULL
                          AND s.last_seen_at > v_occurred_at - make_interval(mins => p_session_ttl_minutes)
                          AND s.started_at > v_occurred_at - make_interval(hours => p_session_max_hours)
                          AND (
                              s.user_id IS NULL
                              OR p_authenticated_user_id IS NULL
                              OR s.user_id = p_authenticated_user_id
                          )
                        ORDER BY s.last_seen_at DESC, s.id
                        LIMIT 1
                        FOR UPDATE;

                        v_session_found := FOUND;
                    END IF;

                    IF v_session_found THEN
                        UPDATE public.analytics_sessions
                        SET user_id = COALESCE(user_id, p_authenticated_user_id),
                            last_seen_at = GREATEST(last_seen_at, v_occurred_at),
                            exit_path = p_page_path,
                            page_views = page_views + CASE WHEN p_event_name = 'page_view' THEN 1 ELSE 0 END,
                            updated_at = GREATEST(updated_at, v_occurred_at)
                        WHERE id = v_effective_session_id;
                    ELSE
                        v_effective_session_id := gen_random_uuid();

                        INSERT INTO public.analytics_sessions (
                            id,
                            visitor_id,
                            user_id,
                            started_at,
                            last_seen_at,
                            ended_at,
                            entry_path,
                            exit_path,
                            page_views,
                            utm_source,
                            utm_medium,
                            utm_campaign,
                            device_type,
                            country_code,
                            created_at,
                            updated_at
                        ) VALUES (
                            v_effective_session_id,
                            p_visitor_id,
                            p_authenticated_user_id,
                            v_occurred_at,
                            v_occurred_at,
                            NULL,
                            p_page_path,
                            p_page_path,
                            CASE WHEN p_event_name = 'page_view' THEN 1 ELSE 0 END,
                            p_utm_source,
                            p_utm_medium,
                            p_utm_campaign,
                            p_device_type,
                            p_country_code,
                            v_occurred_at,
                            v_occurred_at
                        );
                    END IF;

                    INSERT INTO public.events (
                        public_id,
                        occurred_at,
                        visitor_id,
                        user_id,
                        session_id,
                        event_name,
                        entity_type,
                        entity_id,
                        properties,
                        page_path,
                        referrer_host,
                        utm_source,
                        utm_medium,
                        utm_campaign,
                        device_type,
                        country_code,
                        ip_hash,
                        ip_hash_key_version,
                        created_at
                    ) VALUES (
                        p_event_public_id,
                        v_occurred_at,
                        p_visitor_id,
                        p_authenticated_user_id,
                        v_effective_session_id,
                        p_event_name,
                        p_entity_type,
                        p_entity_id,
                        p_properties,
                        p_page_path,
                        p_referrer_host,
                        p_utm_source,
                        p_utm_medium,
                        p_utm_campaign,
                        p_device_type,
                        p_country_code,
                        p_ip_hash,
                        p_ip_hash_key_version,
                        v_occurred_at
                    );

                    RETURN v_effective_session_id;
                END;
                $$;
                SQL);

            DB::statement(sprintf(
                'REVOKE EXECUTE ON FUNCTION public.%s(%s) FROM PUBLIC',
                self::FUNCTION_NAME,
                self::FUNCTION_ARGUMENTS,
            ));
            DB::statement(sprintf(
                'GRANT EXECUTE ON FUNCTION public.%s(%s) TO digitrove_runtime',
                self::FUNCTION_NAME,
                self::FUNCTION_ARGUMENTS,
            ));
        } finally {
            DB::statement('RESET ROLE');
            DB::statement('REVOKE CREATE ON SCHEMA public FROM digitrove_analytics_executor');
        }

        DB::statement('REVOKE ALL PRIVILEGES ON TABLE public.events, public.events_default, public.analytics_sessions FROM digitrove_runtime');
        DB::statement('REVOKE ALL PRIVILEGES ON SEQUENCE public.events_id_seq FROM digitrove_runtime');
    }

    public function down(): void
    {
        $signature = sprintf('public.%s(%s)', self::FUNCTION_NAME, self::FUNCTION_ARGUMENTS);

        DB::statement("REVOKE EXECUTE ON FUNCTION {$signature} FROM digitrove_runtime");
        DB::statement('SET ROLE digitrove_analytics_executor');

        try {
            DB::statement("DROP FUNCTION IF EXISTS {$signature}");
        } finally {
            DB::statement('RESET ROLE');
        }

        DB::statement('REVOKE ALL PRIVILEGES ON TABLE public.events, public.events_default, public.analytics_sessions FROM digitrove_analytics_executor');
        DB::statement('REVOKE ALL PRIVILEGES ON SEQUENCE public.events_id_seq FROM digitrove_analytics_executor');
        DB::statement('REVOKE USAGE ON SCHEMA public FROM digitrove_analytics_executor');

        // Preserve the P5-A0 darkness after rolling back only this gate.
        DB::statement('REVOKE ALL PRIVILEGES ON TABLE public.events, public.events_default, public.analytics_sessions FROM PUBLIC, digitrove_runtime');
        DB::statement('REVOKE ALL PRIVILEGES ON SEQUENCE public.events_id_seq FROM PUBLIC, digitrove_runtime');
    }

    private function assertExecutorProvisioned(): void
    {
        $executor = DB::selectOne(<<<'SQL'
            SELECT rolsuper, rolcanlogin, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls
            FROM pg_roles
            WHERE rolname = 'digitrove_analytics_executor'
            SQL);

        if ($executor === null) {
            throw new RuntimeException('P5-A1 migration: digitrove_analytics_executor is not provisioned. Run `php artisan db:provision-runtime-roles` first.');
        }

        if ($executor->rolsuper
            || $executor->rolcanlogin
            || $executor->rolcreatedb
            || $executor->rolcreaterole
            || $executor->rolreplication
            || $executor->rolbypassrls) {
            throw new RuntimeException('P5-A1 migration: digitrove_analytics_executor must be a restricted NOLOGIN role.');
        }

        $setPath = DB::selectOne(<<<'SQL'
            SELECT m.set_option
            FROM pg_auth_members m
            JOIN pg_roles member ON member.oid = m.member
            JOIN pg_roles granted ON granted.oid = m.roleid
            WHERE member.rolname = 'digitrove'
              AND granted.rolname = 'digitrove_analytics_executor'
            LIMIT 1
            SQL);

        if ($setPath === null || ! $setPath->set_option) {
            throw new RuntimeException('P5-A1 migration: digitrove must hold SET-only membership to digitrove_analytics_executor.');
        }
    }

    private function dropResidualFunction(): void
    {
        $residual = DB::selectOne(
            "SELECT pg_get_userbyid(proowner) AS owner FROM pg_proc WHERE proname = ? AND pronamespace = 'public'::regnamespace",
            [self::FUNCTION_NAME],
        );

        if ($residual === null) {
            return;
        }

        $signature = sprintf('public.%s(%s)', self::FUNCTION_NAME, self::FUNCTION_ARGUMENTS);

        if ($residual->owner === 'digitrove_analytics_executor') {
            DB::statement('SET ROLE digitrove_analytics_executor');

            try {
                DB::statement("DROP FUNCTION IF EXISTS {$signature}");
            } finally {
                DB::statement('RESET ROLE');
            }

            return;
        }

        DB::statement("DROP FUNCTION IF EXISTS {$signature}");
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
