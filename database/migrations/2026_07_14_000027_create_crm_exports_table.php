<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6-B1 — Private Audited CRM Exports (D-053).
 *
 * One table, `crm_exports`, plus the bounded authorities that drive it. The runtime
 * holds neither SELECT nor DML on the table: it calls EXECUTE-only SECURITY DEFINER
 * functions owned by digitrove_crm_executor, exactly like every CRM gate before it.
 *
 * THE GENERATION SNAPSHOT IS THE CORE INVARIANT. An export of a segment's current
 * members freezes `crm_segments.current_generation_id` AT CREATION and stores it. Every
 * page then reads that frozen id — never `current_generation_id` again. If a rebuild
 * publishes G2 while G1 is being written, the file stays 100 % G1 rather than becoming
 * a silent mixture of two generations, which is the one corruption a paginated export
 * of a moving target can produce.
 */
return new class extends Migration
{
    private const CREATE_SIGNATURE = 'public.create_crm_export(character varying, bigint, bigint, integer, integer)';

    private const CLAIM_SIGNATURE = 'public.claim_crm_export(bigint)';

    private const COMPLETE_SIGNATURE = 'public.complete_crm_export(bigint, bigint, character varying, character varying, bigint, character varying)';

    private const FAIL_SIGNATURE = 'public.fail_crm_export(bigint, character varying, character varying)';

    private const GET_SIGNATURE = 'public.get_crm_export(bigint)';

    private const LIST_SIGNATURE = 'public.list_crm_exports(bigint, integer)';

    private const LIST_DUE_SIGNATURE = 'public.list_due_crm_exports(integer)';

    private const EXPIRE_SIGNATURE = 'public.expire_crm_exports(integer)';

    private const CONTACT_ROWS_SIGNATURE = 'public.list_crm_export_contact_rows(bigint, integer)';

    private const MEMBER_ROWS_SIGNATURE = 'public.list_crm_export_member_rows(bigint, bigint, integer)';

    /**
     * THE canonical inventory of every function this migration creates. Ownership,
     * lockdown and rollback all iterate it, so a function cannot be created without
     * also being owned, revoked and dropped (the P6-A2 lesson).
     *
     * @return list<string>
     */
    private function functionSignatures(): array
    {
        return [
            self::CREATE_SIGNATURE,
            self::CLAIM_SIGNATURE,
            self::COMPLETE_SIGNATURE,
            self::FAIL_SIGNATURE,
            self::GET_SIGNATURE,
            self::LIST_SIGNATURE,
            self::LIST_DUE_SIGNATURE,
            self::EXPIRE_SIGNATURE,
            self::CONTACT_ROWS_SIGNATURE,
            self::MEMBER_ROWS_SIGNATURE,
        ];
    }

    public function up(): void
    {
        $this->assertExecutorProvisioned();
        $this->createTable();
        $this->createFunctions();
        $this->lockDownPrivileges();
    }

    public function down(): void
    {
        foreach ($this->functionSignatures() as $signature) {
            DB::statement('REVOKE EXECUTE ON FUNCTION '.$signature.' FROM digitrove_runtime');
            DB::statement('DROP FUNCTION IF EXISTS '.$signature);
        }

        Schema::dropIfExists('crm_exports');
    }

    private function createTable(): void
    {
        Schema::create('crm_exports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('status', 16)->default('queued');
            $table->foreignId('segment_id')->nullable()->constrained('crm_segments')->restrictOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained('crm_segment_generations')->restrictOnDelete();
            $table->integer('row_limit');
            $table->bigInteger('row_count')->nullable();
            $table->string('storage_disk', 32)->nullable();
            $table->string('storage_path', 255)->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('last_error_code', 5)->nullable();
            $table->string('terminal_reason', 32)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampsTz();

            $table->unique('public_id', 'crm_exports_public_id_unique');
            $table->index(['status', 'id'], 'crm_exports_status_id_index');
            $table->index(['requested_by_user_id', 'id'], 'crm_exports_requester_id_index');
        });

        DB::statement("ALTER TABLE public.crm_exports ADD CONSTRAINT crm_exports_kind_check CHECK (kind IN ('crm_contacts', 'segment_current_members'))");
        DB::statement("ALTER TABLE public.crm_exports ADD CONSTRAINT crm_exports_status_check CHECK (status IN ('queued', 'running', 'completed', 'failed', 'expired'))");
        DB::statement('ALTER TABLE public.crm_exports ADD CONSTRAINT crm_exports_row_limit_check CHECK (row_limit BETWEEN 1 AND 50000)');
        DB::statement('ALTER TABLE public.crm_exports ADD CONSTRAINT crm_exports_row_count_check CHECK (row_count IS NULL OR row_count >= 0)');
        DB::statement('ALTER TABLE public.crm_exports ADD CONSTRAINT crm_exports_size_check CHECK (size_bytes IS NULL OR size_bytes >= 0)');
        DB::statement("ALTER TABLE public.crm_exports ADD CONSTRAINT crm_exports_checksum_check CHECK (checksum_sha256 IS NULL OR checksum_sha256 ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE public.crm_exports ADD CONSTRAINT crm_exports_error_code_check CHECK (last_error_code IS NULL OR last_error_code ~ '^[0-9A-Z]{5}$')");
        DB::statement("ALTER TABLE public.crm_exports ADD CONSTRAINT crm_exports_terminal_reason_check CHECK (terminal_reason IS NULL OR terminal_reason IN ('row_limit_exceeded', 'storage_unavailable', 'integrity_failure', 'generation_missing'))");

        // A member export is bound to ONE frozen generation; a contact export is bound to
        // none. Neither shape can borrow the other's columns.
        DB::statement(<<<'SQL'
            ALTER TABLE public.crm_exports
            ADD CONSTRAINT crm_exports_kind_scope_check
            CHECK ((
                kind = 'segment_current_members'
                AND segment_id IS NOT NULL
                AND generation_id IS NOT NULL
            ) OR (
                kind = 'crm_contacts'
                AND segment_id IS NULL
                AND generation_id IS NULL
            ))
            SQL);

        // A completed export MUST carry a complete, verifiable artefact description:
        // without the checksum and size, "completed" would be an unfalsifiable claim.
        DB::statement(<<<'SQL'
            ALTER TABLE public.crm_exports
            ADD CONSTRAINT crm_exports_status_payload_check
            CHECK (
                (status <> 'completed' OR (
                    storage_disk IS NOT NULL AND storage_path IS NOT NULL
                    AND size_bytes IS NOT NULL AND checksum_sha256 IS NOT NULL
                    AND row_count IS NOT NULL AND completed_at IS NOT NULL
                ))
                AND (status <> 'failed' OR failed_at IS NOT NULL)
                AND (status <> 'expired' OR expired_at IS NOT NULL)
                AND (status NOT IN ('running', 'completed', 'failed') OR started_at IS NOT NULL)
            )
            SQL);

        DB::statement('ALTER TABLE public.crm_exports OWNER TO digitrove_crm_executor');
        DB::statement('REVOKE ALL ON TABLE public.crm_exports FROM PUBLIC');
        DB::statement('REVOKE ALL ON TABLE public.crm_exports FROM digitrove_runtime');
        DB::statement('REVOKE ALL ON SEQUENCE public.crm_exports_id_seq FROM PUBLIC');
        DB::statement('REVOKE ALL ON SEQUENCE public.crm_exports_id_seq FROM digitrove_runtime');
    }

    private function createFunctions(): void
    {
        DB::unprepared(<<<'SQL'
            -- Create a queued export. For a member export the CURRENT published generation
            -- is captured HERE and stored; every later page reads that frozen id.
            CREATE OR REPLACE FUNCTION public.create_crm_export(
                p_kind CHARACTER VARYING,
                p_requested_by_user_id BIGINT,
                p_segment_id BIGINT,
                p_row_limit INTEGER,
                p_ttl_hours INTEGER
            )
            RETURNS TABLE(export_id BIGINT, public_id UUID, status CHARACTER VARYING, generation_id BIGINT, expires_at TIMESTAMPTZ)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_generation_id BIGINT;
                v_segment_id BIGINT;
                v_export public.crm_exports%ROWTYPE;
                v_public_id UUID;
            BEGIN
                IF p_kind IS NULL OR p_kind NOT IN ('crm_contacts', 'segment_current_members') THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'unknown export kind';
                END IF;

                IF p_requested_by_user_id IS NULL OR p_requested_by_user_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid requester';
                END IF;

                IF p_row_limit IS NULL OR p_row_limit < 1 OR p_row_limit > 50000 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'export row limit is invalid';
                END IF;

                IF p_ttl_hours IS NULL OR p_ttl_hours < 1 OR p_ttl_hours > 168 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'export TTL is invalid';
                END IF;

                IF p_kind = 'segment_current_members' THEN
                    IF p_segment_id IS NULL OR p_segment_id < 1 THEN
                        RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid segment identifier';
                    END IF;

                    -- Freeze the generation NOW. Reading current_generation_id again on a
                    -- later page would silently mix two generations into one file.
                    SELECT s.id, s.current_generation_id INTO v_segment_id, v_generation_id
                    FROM public.crm_segments AS s
                    WHERE s.id = p_segment_id;

                    IF v_segment_id IS NULL THEN
                        RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'segment not found';
                    END IF;

                    -- A segment with no published generation has no members to export.
                    -- Refusing here is honest; an empty file would look like "no members".
                    IF v_generation_id IS NULL THEN
                        RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'segment has no published generation';
                    END IF;
                ELSE
                    IF p_segment_id IS NOT NULL THEN
                        RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'contact export takes no segment';
                    END IF;

                    v_segment_id := NULL;
                    v_generation_id := NULL;
                END IF;

                v_public_id := gen_random_uuid();

                INSERT INTO public.crm_exports (
                    public_id, requested_by_user_id, kind, status, segment_id, generation_id,
                    row_limit, expires_at, created_at, updated_at
                )
                VALUES (
                    v_public_id, p_requested_by_user_id, p_kind, 'queued', v_segment_id, v_generation_id,
                    p_row_limit, clock_timestamp() + make_interval(hours => p_ttl_hours),
                    clock_timestamp(), clock_timestamp()
                )
                RETURNING * INTO v_export;

                RETURN QUERY SELECT v_export.id, v_export.public_id, v_export.status::varchar,
                                    v_export.generation_id, v_export.expires_at;
            END;
            $$;

            -- Atomic queued -> running claim. FOR UPDATE is the serialisation point, so
            -- two concurrent workers can never both produce a file for one export.
            CREATE OR REPLACE FUNCTION public.claim_crm_export(p_export_id BIGINT)
            RETURNS TABLE(export_id BIGINT, status CHARACTER VARYING, kind CHARACTER VARYING, generation_id BIGINT, row_limit INTEGER)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_export public.crm_exports%ROWTYPE;
            BEGIN
                IF p_export_id IS NULL OR p_export_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid export identifier';
                END IF;

                SELECT e.* INTO v_export
                FROM public.crm_exports AS e
                WHERE e.id = p_export_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RETURN QUERY SELECT p_export_id, 'not_found'::varchar, NULL::varchar, NULL::bigint, NULL::integer;
                    RETURN;
                END IF;

                -- Already claimed, finished or expired: report the state, never re-run.
                IF v_export.status <> 'queued' THEN
                    RETURN QUERY SELECT v_export.id, v_export.status::varchar, v_export.kind::varchar,
                                        v_export.generation_id, v_export.row_limit;
                    RETURN;
                END IF;

                -- An export whose TTL elapsed before it ran is expired, not run late.
                IF v_export.expires_at <= clock_timestamp() THEN
                    UPDATE public.crm_exports AS e
                    SET status = 'expired', expired_at = clock_timestamp(), updated_at = clock_timestamp()
                    WHERE e.id = p_export_id;

                    RETURN QUERY SELECT v_export.id, 'expired'::varchar, v_export.kind::varchar,
                                        v_export.generation_id, v_export.row_limit;
                    RETURN;
                END IF;

                UPDATE public.crm_exports AS e
                SET status = 'running', started_at = clock_timestamp(), updated_at = clock_timestamp()
                WHERE e.id = p_export_id;

                RETURN QUERY SELECT v_export.id, 'running'::varchar, v_export.kind::varchar,
                                    v_export.generation_id, v_export.row_limit;
            END;
            $$;

            -- Finalise a successful export. Only a running export may complete.
            CREATE OR REPLACE FUNCTION public.complete_crm_export(
                p_export_id BIGINT,
                p_row_count BIGINT,
                p_storage_disk CHARACTER VARYING,
                p_storage_path CHARACTER VARYING,
                p_size_bytes BIGINT,
                p_checksum CHARACTER VARYING
            )
            RETURNS TABLE(export_id BIGINT, status CHARACTER VARYING)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_status TEXT;
            BEGIN
                IF p_export_id IS NULL OR p_export_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid export identifier';
                END IF;

                IF p_row_count IS NULL OR p_row_count < 0
                    OR p_size_bytes IS NULL OR p_size_bytes < 0
                    OR p_storage_disk IS NULL OR p_storage_path IS NULL
                    OR p_checksum IS NULL OR p_checksum !~ '^[0-9a-f]{64}$' THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'incomplete export artefact';
                END IF;

                SELECT e.status INTO v_status
                FROM public.crm_exports AS e
                WHERE e.id = p_export_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RETURN QUERY SELECT p_export_id, 'not_found'::varchar;
                    RETURN;
                END IF;

                IF v_status <> 'running' THEN
                    RETURN QUERY SELECT p_export_id, v_status::varchar;
                    RETURN;
                END IF;

                UPDATE public.crm_exports AS e
                SET status = 'completed',
                    row_count = p_row_count,
                    storage_disk = p_storage_disk,
                    storage_path = p_storage_path,
                    size_bytes = p_size_bytes,
                    checksum_sha256 = p_checksum,
                    completed_at = clock_timestamp(),
                    updated_at = clock_timestamp()
                WHERE e.id = p_export_id;

                RETURN QUERY SELECT p_export_id, 'completed'::varchar;
            END;
            $$;

            -- Terminal failure. The reason is an ALLOWLISTED token and the code a
            -- SQLSTATE: no driver sentence, no PII, ever reaches this row.
            CREATE OR REPLACE FUNCTION public.fail_crm_export(
                p_export_id BIGINT,
                p_error_code CHARACTER VARYING,
                p_terminal_reason CHARACTER VARYING
            )
            RETURNS TABLE(export_id BIGINT, status CHARACTER VARYING)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_status TEXT;
            BEGIN
                IF p_export_id IS NULL OR p_export_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid export identifier';
                END IF;

                IF p_terminal_reason IS NULL
                    OR p_terminal_reason NOT IN ('row_limit_exceeded', 'storage_unavailable', 'integrity_failure', 'generation_missing') THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'unknown terminal reason';
                END IF;

                IF p_error_code IS NOT NULL AND p_error_code !~ '^[0-9A-Z]{5}$' THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'error code must be a SQLSTATE';
                END IF;

                SELECT e.status INTO v_status
                FROM public.crm_exports AS e
                WHERE e.id = p_export_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RETURN QUERY SELECT p_export_id, 'not_found'::varchar;
                    RETURN;
                END IF;

                IF v_status NOT IN ('queued', 'running') THEN
                    RETURN QUERY SELECT p_export_id, v_status::varchar;
                    RETURN;
                END IF;

                UPDATE public.crm_exports AS e
                SET status = 'failed',
                    last_error_code = p_error_code,
                    terminal_reason = p_terminal_reason,
                    started_at = COALESCE(e.started_at, clock_timestamp()),
                    failed_at = clock_timestamp(),
                    updated_at = clock_timestamp()
                WHERE e.id = p_export_id;

                RETURN QUERY SELECT p_export_id, 'failed'::varchar;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.get_crm_export(p_export_id BIGINT)
            RETURNS TABLE(
                export_id BIGINT, public_id UUID, requested_by_user_id BIGINT,
                kind CHARACTER VARYING, status CHARACTER VARYING,
                segment_id BIGINT, generation_id BIGINT,
                row_limit INTEGER, row_count BIGINT,
                storage_disk CHARACTER VARYING, storage_path CHARACTER VARYING,
                size_bytes BIGINT, checksum_sha256 CHARACTER VARYING,
                last_error_code CHARACTER VARYING, terminal_reason CHARACTER VARYING,
                expires_at TIMESTAMPTZ, created_at TIMESTAMPTZ, completed_at TIMESTAMPTZ
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_export_id IS NULL OR p_export_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid export identifier';
                END IF;

                RETURN QUERY
                SELECT e.id, e.public_id, e.requested_by_user_id, e.kind::varchar, e.status::varchar,
                       e.segment_id, e.generation_id, e.row_limit, e.row_count,
                       e.storage_disk::varchar, e.storage_path::varchar, e.size_bytes,
                       e.checksum_sha256::varchar, e.last_error_code::varchar, e.terminal_reason::varchar,
                       e.expires_at, e.created_at, e.completed_at
                FROM public.crm_exports AS e
                WHERE e.id = p_export_id;
            END;
            $$;

            -- Newest first: an operator looks at what they just requested. Keyset walks
            -- DOWN from the cursor, so there is still no OFFSET.
            CREATE OR REPLACE FUNCTION public.list_crm_exports(p_before_export_id BIGINT, p_limit INTEGER)
            RETURNS TABLE(
                export_id BIGINT, public_id UUID, requested_by_user_id BIGINT,
                kind CHARACTER VARYING, status CHARACTER VARYING,
                segment_id BIGINT, row_count BIGINT,
                expires_at TIMESTAMPTZ, created_at TIMESTAMPTZ, completed_at TIMESTAMPTZ
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM export page size is invalid';
                END IF;

                RETURN QUERY
                SELECT e.id, e.public_id, e.requested_by_user_id, e.kind::varchar, e.status::varchar,
                       e.segment_id, e.row_count, e.expires_at, e.created_at, e.completed_at
                FROM public.crm_exports AS e
                WHERE (p_before_export_id IS NULL OR e.id < p_before_export_id)
                ORDER BY e.id DESC
                LIMIT p_limit;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.list_due_crm_exports(p_limit INTEGER)
            RETURNS TABLE(export_id BIGINT)
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM export sweep size is invalid';
                END IF;

                RETURN QUERY
                SELECT e.id
                FROM public.crm_exports AS e
                WHERE e.status = 'queued'
                  AND e.expires_at > clock_timestamp()
                ORDER BY e.id
                LIMIT p_limit;
            END;
            $$;

            -- Bounded, idempotent purge. Returns the ids it expired so the caller can
            -- delete the corresponding files; running it twice expires nothing new.
            CREATE OR REPLACE FUNCTION public.expire_crm_exports(p_limit INTEGER)
            RETURNS TABLE(export_id BIGINT, storage_disk CHARACTER VARYING, storage_path CHARACTER VARYING)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM export purge size is invalid';
                END IF;

                RETURN QUERY
                WITH due AS (
                    SELECT e.id
                    FROM public.crm_exports AS e
                    WHERE e.status IN ('queued', 'running', 'completed')
                      AND e.expires_at <= clock_timestamp()
                    ORDER BY e.id
                    LIMIT p_limit
                    FOR UPDATE
                )
                UPDATE public.crm_exports AS e
                SET status = 'expired',
                    expired_at = clock_timestamp(),
                    updated_at = clock_timestamp()
                FROM due
                WHERE e.id = due.id
                RETURNING e.id, e.storage_disk::varchar, e.storage_path::varchar;
            END;
            $$;

            -- Export row source for `crm_contacts`. Keyset, bounded, and an anonymized
            -- contact yields a NULL e-mail because the address is physically absent.
            CREATE OR REPLACE FUNCTION public.list_crm_export_contact_rows(p_after_contact_id BIGINT, p_limit INTEGER)
            RETURNS TABLE(
                contact_id BIGINT, public_id UUID, email CHARACTER VARYING,
                status CHARACTER VARYING, origin CHARACTER VARYING, created_at TIMESTAMPTZ
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM export page size is invalid';
                END IF;

                RETURN QUERY
                SELECT c.id, c.public_id, c.email::varchar, c.status::varchar, c.origin::varchar, c.created_at
                FROM public.crm_contacts AS c
                WHERE (p_after_contact_id IS NULL OR c.id > p_after_contact_id)
                ORDER BY c.id
                LIMIT p_limit;
            END;
            $$;

            -- Export row source for `segment_current_members`, bound to the FROZEN
            -- generation id — never to crm_segments.current_generation_id. The join to
            -- crm_contacts happens here so the caller never issues one query per member.
            CREATE OR REPLACE FUNCTION public.list_crm_export_member_rows(
                p_generation_id BIGINT,
                p_after_contact_id BIGINT,
                p_limit INTEGER
            )
            RETURNS TABLE(
                contact_id BIGINT, public_id UUID, email CHARACTER VARYING,
                status CHARACTER VARYING, origin CHARACTER VARYING, created_at TIMESTAMPTZ
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_generation_id IS NULL OR p_generation_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid generation identifier';
                END IF;

                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM export page size is invalid';
                END IF;

                RETURN QUERY
                SELECT c.id, c.public_id, c.email::varchar, c.status::varchar, c.origin::varchar, c.created_at
                FROM public.crm_segment_generation_members AS m
                JOIN public.crm_contacts AS c ON c.id = m.contact_id
                WHERE m.generation_id = p_generation_id
                  AND (p_after_contact_id IS NULL OR m.contact_id > p_after_contact_id)
                ORDER BY m.contact_id
                LIMIT p_limit;
            END;
            $$;
            SQL);

        foreach ($this->functionSignatures() as $signature) {
            DB::statement('ALTER FUNCTION '.$signature.' OWNER TO digitrove_crm_executor');
        }
    }

    private function lockDownPrivileges(): void
    {
        foreach ($this->functionSignatures() as $signature) {
            DB::statement('REVOKE ALL ON FUNCTION '.$signature.' FROM PUBLIC');
            DB::statement('REVOKE ALL ON FUNCTION '.$signature.' FROM digitrove_runtime');
            // The admin export layer's ONLY way in. The runtime never touches the table.
            DB::statement('GRANT EXECUTE ON FUNCTION '.$signature.' TO digitrove_runtime');
        }
    }

    private function assertExecutorProvisioned(): void
    {
        $executor = DB::selectOne(<<<'SQL'
            SELECT rolsuper, rolcanlogin, rolcreatedb, rolcreaterole,
                   rolreplication, rolbypassrls, rolinherit
            FROM pg_roles
            WHERE rolname = 'digitrove_crm_executor'
            SQL);

        if ($executor === null
            || $executor->rolsuper
            || $executor->rolcanlogin
            || $executor->rolcreatedb
            || $executor->rolcreaterole
            || $executor->rolreplication
            || $executor->rolbypassrls
            || $executor->rolinherit) {
            throw new RuntimeException('P6-B1 migration: digitrove_crm_executor must be a restricted NOLOGIN NOINHERIT role.');
        }
    }
};
