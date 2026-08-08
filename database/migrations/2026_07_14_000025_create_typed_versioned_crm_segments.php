<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6-A2 — Typed Versioned CRM Segments (D-049 architecture → D-050 implementation).
 *
 * A segment is a TYPED, ALLOWLISTED, VERSIONED definition whose membership is
 * materialised into IMMUTABLE generations and published atomically:
 *
 *   crm_segments            identity + current version/generation pointers
 *   crm_segment_versions    immutable typed definition (JSONB, strictly validated)
 *   crm_segment_generations one bounded materialisation run of one version
 *   crm_segment_generation_members  contact ids only, no PII
 *
 * The definition is stored as JSONB ONLY because it is validated field-by-field
 * against a closed allowlist and is NEVER interpreted as SQL. No dynamic SQL, no
 * EXECUTE of user input, no format(%I), no JSONPath, no callable.
 *
 * CURRENCY SAFETY — `crm_contact_commerce_rollups` is keyed (contact_id, currency),
 * so EVERY commerce criterion carries an explicit currency and reads exactly one row.
 * No FX, no cross-currency sum, no global LTV. A missing rollup row evaluates FALSE
 * for every operator (an acquired FREE order still has a row with money = 0, so
 * "net_revenue_minor = 0" correctly targets free acquirers without being confused
 * with "never acquired").
 *
 * GENERATION SEMANTICS — a generation freezes `contact_id_high_water_mark =
 * MAX(crm_contacts.id)` at start, which bounds the CONTACT population it walks. It is
 * NOT an MVCC snapshot of CRM/commerce facts: those may change during the build
 * window. What IS atomic is the VISIBILITY of the published membership — readers go
 * through `crm_segments.current_generation_id` and therefore see the previous
 * generation in full until the new one is published in full. No half-membership.
 *
 * CONSENT — membership is computed from crm_contacts + crm_contact_commerce_rollups
 * ONLY. `crm_marketing_consent_events` is never read: segment membership is not send
 * eligibility, which stays a separate policy applied at marketing time.
 */
return new class extends Migration
{
    private const VALIDATE_SIGNATURE = 'public.validate_crm_segment_definition_v1(jsonb)';

    private const VALIDATE_INT_SIGNATURE = 'public.validate_crm_segment_definition_v1_int(jsonb)';

    private const VALIDATE_TS_SIGNATURE = 'public.validate_crm_segment_definition_v1_ts(jsonb)';

    private const MATCHES_SIGNATURE = 'public.crm_segment_contact_matches_v1(bigint, jsonb)';

    private const CREATE_SEGMENT_SIGNATURE = 'public.create_crm_segment(character varying)';

    private const CREATE_VERSION_SIGNATURE = 'public.create_crm_segment_version(bigint, jsonb)';

    private const PUBLISH_VERSION_SIGNATURE = 'public.publish_crm_segment_version(bigint)';

    private const GET_SEGMENT_SIGNATURE = 'public.get_crm_segment(bigint)';

    private const LIST_SEGMENTS_SIGNATURE = 'public.list_crm_segments(bigint, integer)';

    private const START_GENERATION_SIGNATURE = 'public.start_crm_segment_generation(bigint, integer)';

    private const PROCESS_BATCH_SIGNATURE = 'public.process_crm_segment_generation_batch(bigint)';

    private const RETRY_GENERATION_SIGNATURE = 'public.retry_crm_segment_generation(bigint)';

    private const GET_GENERATION_SIGNATURE = 'public.get_crm_segment_generation(bigint)';

    private const LIST_DUE_SIGNATURE = 'public.list_due_crm_segment_generations(integer)';

    private const LIST_MEMBERS_SIGNATURE = 'public.list_crm_segment_current_members(bigint, bigint, integer)';

    /** Authorities the runtime may EXECUTE. The validator and matcher are NOT here. */
    private const RUNTIME_SIGNATURES = [
        self::CREATE_SEGMENT_SIGNATURE,
        self::CREATE_VERSION_SIGNATURE,
        self::PUBLISH_VERSION_SIGNATURE,
        self::GET_SEGMENT_SIGNATURE,
        self::LIST_SEGMENTS_SIGNATURE,
        self::START_GENERATION_SIGNATURE,
        self::PROCESS_BATCH_SIGNATURE,
        self::RETRY_GENERATION_SIGNATURE,
        self::GET_GENERATION_SIGNATURE,
        self::LIST_DUE_SIGNATURE,
        self::LIST_MEMBERS_SIGNATURE,
    ];

    /**
     * Internal authorities: never granted to the runtime. The two typing helpers are
     * part of this set — they are real P6-A2 objects and must be owned, locked down and
     * dropped exactly like the validator that calls them.
     *
     * Order matters for DROP: the callers come before the helpers they call.
     */
    private const INTERNAL_SIGNATURES = [
        self::MATCHES_SIGNATURE,
        self::VALIDATE_SIGNATURE,
        self::VALIDATE_INT_SIGNATURE,
        self::VALIDATE_TS_SIGNATURE,
    ];

    private const TRIGGER_FUNCTION_SIGNATURES = [
        'public.enforce_crm_segment_version_immutability()',
        'public.enforce_crm_segment_generation_immutability()',
        'public.enforce_crm_segment_generation_member_immutability()',
    ];

    /**
     * THE canonical inventory of every function this migration creates (18 total:
     * 11 runtime authorities + 4 internal + 3 trigger functions). Ownership, lockdown
     * and rollback all iterate this single source of truth, so a function can never be
     * created without also being owned, revoked and dropped.
     *
     * @return list<string>
     */
    private function allFunctionSignatures(): array
    {
        return [
            ...self::RUNTIME_SIGNATURES,
            ...self::INTERNAL_SIGNATURES,
            ...self::TRIGGER_FUNCTION_SIGNATURES,
        ];
    }

    public function up(): void
    {
        $this->assertExecutorProvisioned();
        $this->createTables();
        $this->createConstraints();
        $this->createFunctions();
        $this->createTriggers();
        $this->lockDownPrivileges();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS crm_segment_versions_immutable_trigger ON public.crm_segment_versions');
        DB::statement('DROP TRIGGER IF EXISTS crm_segment_generations_immutable_trigger ON public.crm_segment_generations');
        DB::statement('DROP TRIGGER IF EXISTS crm_segment_generation_members_immutable_trigger ON public.crm_segment_generation_members');

        foreach (self::RUNTIME_SIGNATURES as $signature) {
            DB::statement('REVOKE EXECUTE ON FUNCTION '.$signature.' FROM digitrove_runtime');
        }

        // Every function this migration created — no exception, no CASCADE.
        foreach ($this->allFunctionSignatures() as $signature) {
            DB::statement('DROP FUNCTION IF EXISTS '.$signature);
        }

        // crm_segments points BACK at versions/generations through composite FKs, so
        // those constraints must go first. Dropped explicitly — never with CASCADE,
        // which could silently reach objects belonging to earlier phases.
        DB::statement('ALTER TABLE IF EXISTS public.crm_segments DROP CONSTRAINT IF EXISTS crm_segments_current_version_same_segment_foreign');
        DB::statement('ALTER TABLE IF EXISTS public.crm_segments DROP CONSTRAINT IF EXISTS crm_segments_current_generation_same_segment_foreign');
        DB::statement('ALTER TABLE IF EXISTS public.crm_segment_generations DROP CONSTRAINT IF EXISTS crm_segment_generations_version_same_segment_foreign');

        Schema::dropIfExists('crm_segment_generation_members');
        Schema::dropIfExists('crm_segment_generations');
        Schema::dropIfExists('crm_segment_versions');
        Schema::dropIfExists('crm_segments');
    }

    private function createTables(): void
    {
        Schema::create('crm_segments', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('status', 16)->default('active');
            $table->bigInteger('current_version_id')->nullable();
            $table->bigInteger('current_generation_id')->nullable();
            $table->timestampsTz(6);
        });

        Schema::create('crm_segment_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('segment_id')->constrained('crm_segments')->restrictOnDelete();
            $table->integer('version_number');
            $table->smallInteger('definition_schema_version');
            $table->jsonb('definition');
            $table->string('status', 16)->default('draft');
            $table->timestampTz('published_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['segment_id', 'version_number'], 'crm_segment_versions_segment_number_unique');
            // Enables the composite FK that keeps a segment pointer inside its own segment.
            $table->unique(['id', 'segment_id'], 'crm_segment_versions_id_segment_unique');
        });

        Schema::create('crm_segment_generations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('segment_id')->constrained('crm_segments')->restrictOnDelete();
            $table->bigInteger('segment_version_id');
            $table->bigInteger('contact_id_high_water_mark');
            $table->integer('batch_size');
            $table->bigInteger('cursor_contact_id')->nullable();
            $table->string('status', 16)->default('ready');
            $table->bigInteger('members_count')->default(0);
            $table->string('last_error_code', 5)->nullable();
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampTz('published_at', 6)->nullable();
            $table->timestampTz('failed_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['id', 'segment_id'], 'crm_segment_generations_id_segment_unique');
        });

        Schema::create('crm_segment_generation_members', function (Blueprint $table): void {
            $table->foreignId('generation_id')->constrained('crm_segment_generations')->restrictOnDelete();
            $table->foreignId('contact_id')->constrained('crm_contacts')->restrictOnDelete();

            $table->primary(['generation_id', 'contact_id'], 'crm_segment_generation_members_pkey');
        });
    }

    private function createConstraints(): void
    {
        // ── crm_segments ──────────────────────────────────────────────────────────
        DB::statement('ALTER TABLE public.crm_segments ADD CONSTRAINT crm_segments_name_check CHECK (char_length(btrim(name)) BETWEEN 1 AND 120 AND name = btrim(name))');
        DB::statement("ALTER TABLE public.crm_segments ADD CONSTRAINT crm_segments_status_check CHECK (status IN ('active', 'archived'))");

        // ── crm_segment_versions ──────────────────────────────────────────────────
        DB::statement('ALTER TABLE public.crm_segment_versions ADD CONSTRAINT crm_segment_versions_number_check CHECK (version_number >= 1)');
        DB::statement('ALTER TABLE public.crm_segment_versions ADD CONSTRAINT crm_segment_versions_schema_version_check CHECK (definition_schema_version = 1)');
        DB::statement("ALTER TABLE public.crm_segment_versions ADD CONSTRAINT crm_segment_versions_status_check CHECK (status IN ('draft', 'published'))");
        DB::statement(<<<'SQL'
            ALTER TABLE public.crm_segment_versions
            ADD CONSTRAINT crm_segment_versions_status_dates_check
            CHECK ((
                CASE
                    WHEN status = 'draft'     THEN published_at IS NULL
                    WHEN status = 'published' THEN published_at IS NOT NULL
                    ELSE FALSE
                END
            ) IS TRUE)
            SQL);
        DB::statement("ALTER TABLE public.crm_segment_versions ADD CONSTRAINT crm_segment_versions_definition_object_check CHECK (jsonb_typeof(definition) = 'object')");
        // Bounded payload: a definition can never become an unbounded blob.
        DB::statement('ALTER TABLE public.crm_segment_versions ADD CONSTRAINT crm_segment_versions_definition_size_check CHECK (octet_length(definition::text) <= 32768)');

        // ── crm_segment_generations ───────────────────────────────────────────────
        DB::statement('ALTER TABLE public.crm_segment_generations ADD CONSTRAINT crm_segment_generations_hwm_check CHECK (contact_id_high_water_mark >= 0)');
        DB::statement('ALTER TABLE public.crm_segment_generations ADD CONSTRAINT crm_segment_generations_batch_size_check CHECK (batch_size BETWEEN 1 AND 100)');
        DB::statement('ALTER TABLE public.crm_segment_generations ADD CONSTRAINT crm_segment_generations_cursor_check CHECK (cursor_contact_id IS NULL OR cursor_contact_id > 0)');
        DB::statement('ALTER TABLE public.crm_segment_generations ADD CONSTRAINT crm_segment_generations_members_count_check CHECK (members_count >= 0)');
        DB::statement("ALTER TABLE public.crm_segment_generations ADD CONSTRAINT crm_segment_generations_status_check CHECK (status IN ('ready', 'running', 'published', 'failed'))");
        DB::statement("ALTER TABLE public.crm_segment_generations ADD CONSTRAINT crm_segment_generations_error_code_check CHECK (last_error_code IS NULL OR last_error_code ~ '^[0-9A-Z]{5}$')");
        DB::statement(<<<'SQL'
            ALTER TABLE public.crm_segment_generations
            ADD CONSTRAINT crm_segment_generations_status_dates_check
            CHECK ((
                CASE
                    WHEN status = 'ready'     THEN published_at IS NULL AND failed_at IS NULL AND completed_at IS NULL
                    WHEN status = 'running'   THEN started_at IS NOT NULL AND published_at IS NULL AND failed_at IS NULL
                    WHEN status = 'published' THEN completed_at IS NOT NULL AND published_at IS NOT NULL AND failed_at IS NULL
                    WHEN status = 'failed'    THEN failed_at IS NOT NULL AND published_at IS NULL
                    ELSE FALSE
                END
            ) IS TRUE)
            SQL);

        // The generation's version must belong to the SAME segment.
        DB::statement(<<<'SQL'
            ALTER TABLE public.crm_segment_generations
            ADD CONSTRAINT crm_segment_generations_version_same_segment_foreign
            FOREIGN KEY (segment_version_id, segment_id)
            REFERENCES public.crm_segment_versions (id, segment_id)
            ON DELETE RESTRICT
            SQL);

        // A segment can only ever point at ITS OWN version / generation. Enforced by
        // composite foreign keys, not by application code.
        DB::statement(<<<'SQL'
            ALTER TABLE public.crm_segments
            ADD CONSTRAINT crm_segments_current_version_same_segment_foreign
            FOREIGN KEY (current_version_id, id)
            REFERENCES public.crm_segment_versions (id, segment_id)
            ON DELETE RESTRICT
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE public.crm_segments
            ADD CONSTRAINT crm_segments_current_generation_same_segment_foreign
            FOREIGN KEY (current_generation_id, id)
            REFERENCES public.crm_segment_generations (id, segment_id)
            ON DELETE RESTRICT
            SQL);

        // At most ONE active (ready|running) generation per segment.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX crm_segment_generations_single_active
            ON public.crm_segment_generations (segment_id)
            WHERE status IN ('ready', 'running')
            SQL);

        foreach ([
            'crm_segments',
            'crm_segment_versions',
            'crm_segment_generations',
            'crm_segment_generation_members',
        ] as $table) {
            DB::statement('ALTER TABLE public.'.$table.' OWNER TO digitrove_crm_executor');
        }
    }

    private function createFunctions(): void
    {
        $this->createValidator();
        $this->createMatcher();
        $this->createImmutabilityFunctions();
        $this->createLifecycleAuthorities();
        $this->createGenerationAuthorities();
        $this->createReadAuthorities();

        foreach ($this->allFunctionSignatures() as $signature) {
            DB::statement('ALTER FUNCTION '.$signature.' OWNER TO digitrove_crm_executor');
        }
    }

    private function createValidator(): void
    {
        DB::unprepared(<<<'SQL'
            -- Strict, fail-closed structural validation of a definition. Every key, field,
            -- operator and value type is checked against a CLOSED allowlist. Nothing here
            -- builds or executes SQL from the definition.
            CREATE OR REPLACE FUNCTION public.validate_crm_segment_definition_v1(p_definition JSONB)
            RETURNS void
            LANGUAGE plpgsql
            IMMUTABLE
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_criterion JSONB;
                v_keys TEXT[];
                v_field TEXT;
                v_operator TEXT;
                v_kind TEXT;
                v_expected TEXT[];
                v_values JSONB;
                v_count INTEGER;
                v_allowed_enum TEXT[];
                v_element TEXT;
            BEGIN
                IF p_definition IS NULL OR jsonb_typeof(p_definition) <> 'object' THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'definition must be an object';
                END IF;

                -- Exactly three top-level keys, no more, no less.
                SELECT array_agg(k ORDER BY k) INTO v_keys FROM jsonb_object_keys(p_definition) AS k;
                IF v_keys IS DISTINCT FROM ARRAY['criteria', 'match', 'schema_version'] THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'definition has unexpected top-level keys';
                END IF;

                IF jsonb_typeof(p_definition -> 'schema_version') <> 'number'
                    OR (p_definition ->> 'schema_version') <> '1' THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'unsupported definition schema_version';
                END IF;

                IF jsonb_typeof(p_definition -> 'match') <> 'string'
                    OR (p_definition ->> 'match') NOT IN ('all', 'any') THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid match mode';
                END IF;

                IF jsonb_typeof(p_definition -> 'criteria') <> 'array' THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'criteria must be an array';
                END IF;

                v_count := jsonb_array_length(p_definition -> 'criteria');
                IF v_count < 1 OR v_count > 50 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'criteria count out of range';
                END IF;

                FOR v_criterion IN SELECT jsonb_array_elements(p_definition -> 'criteria')
                LOOP
                    IF jsonb_typeof(v_criterion) <> 'object' THEN
                        RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'criterion must be an object';
                    END IF;

                    IF jsonb_typeof(v_criterion -> 'field') <> 'string'
                        OR jsonb_typeof(v_criterion -> 'operator') <> 'string' THEN
                        RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'criterion field/operator must be strings';
                    END IF;

                    v_field := v_criterion ->> 'field';
                    v_operator := v_criterion ->> 'operator';

                    -- Closed field allowlist. An unknown field is never "ignored".
                    v_kind := CASE v_field
                        WHEN 'commerce.net_revenue_minor' THEN 'commerce_number'
                        WHEN 'commerce.gross_revenue_minor' THEN 'commerce_number'
                        WHEN 'commerce.refunded_amount_minor' THEN 'commerce_number'
                        WHEN 'commerce.acquired_orders_count' THEN 'commerce_number'
                        WHEN 'commerce.first_acquired_at' THEN 'commerce_date'
                        WHEN 'commerce.last_acquired_at' THEN 'commerce_date'
                        WHEN 'contact.created_at' THEN 'contact_date'
                        WHEN 'contact.status' THEN 'contact_enum'
                        WHEN 'contact.origin' THEN 'contact_enum'
                        ELSE NULL
                    END;

                    IF v_kind IS NULL THEN
                        RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'unknown criterion field';
                    END IF;

                    SELECT array_agg(k ORDER BY k) INTO v_keys FROM jsonb_object_keys(v_criterion) AS k;

                    IF v_kind IN ('commerce_number') THEN
                        IF v_operator IN ('eq', 'neq', 'gt', 'gte', 'lt', 'lte') THEN
                            v_expected := ARRAY['currency', 'field', 'operator', 'value'];
                        ELSIF v_operator = 'between' THEN
                            v_expected := ARRAY['currency', 'field', 'lower', 'operator', 'upper'];
                        ELSE
                            RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid numeric operator';
                        END IF;
                    ELSIF v_kind = 'commerce_date' THEN
                        IF v_operator IN ('before', 'after') THEN
                            v_expected := ARRAY['currency', 'field', 'operator', 'value'];
                        ELSIF v_operator = 'between' THEN
                            v_expected := ARRAY['currency', 'field', 'lower', 'operator', 'upper'];
                        ELSE
                            RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid date operator';
                        END IF;
                    ELSIF v_kind = 'contact_date' THEN
                        IF v_operator IN ('before', 'after') THEN
                            v_expected := ARRAY['field', 'operator', 'value'];
                        ELSIF v_operator = 'between' THEN
                            v_expected := ARRAY['field', 'lower', 'operator', 'upper'];
                        ELSE
                            RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid date operator';
                        END IF;
                    ELSE -- contact_enum
                        IF v_operator NOT IN ('in', 'not_in') THEN
                            RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid enum operator';
                        END IF;
                        v_expected := ARRAY['field', 'operator', 'values'];
                    END IF;

                    -- EXACT key set: any extra key (sql, column, raw, path, ...) is fatal.
                    IF v_keys IS DISTINCT FROM v_expected THEN
                        RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'criterion has unexpected keys';
                    END IF;

                    IF v_kind IN ('commerce_number', 'commerce_date') THEN
                        IF jsonb_typeof(v_criterion -> 'currency') <> 'string'
                            OR (v_criterion ->> 'currency') !~ '^[A-Z]{3}$' THEN
                            RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid currency';
                        END IF;
                    END IF;

                    IF v_kind = 'commerce_number' THEN
                        IF v_operator = 'between' THEN
                            PERFORM public.validate_crm_segment_definition_v1_int(v_criterion -> 'lower');
                            PERFORM public.validate_crm_segment_definition_v1_int(v_criterion -> 'upper');
                            IF (v_criterion ->> 'lower')::NUMERIC > (v_criterion ->> 'upper')::NUMERIC THEN
                                RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'between bounds are inverted';
                            END IF;
                        ELSE
                            PERFORM public.validate_crm_segment_definition_v1_int(v_criterion -> 'value');
                        END IF;
                    ELSIF v_kind IN ('commerce_date', 'contact_date') THEN
                        IF v_operator = 'between' THEN
                            PERFORM public.validate_crm_segment_definition_v1_ts(v_criterion -> 'lower');
                            PERFORM public.validate_crm_segment_definition_v1_ts(v_criterion -> 'upper');
                            IF (v_criterion ->> 'lower')::TIMESTAMPTZ > (v_criterion ->> 'upper')::TIMESTAMPTZ THEN
                                RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'between bounds are inverted';
                            END IF;
                        ELSE
                            PERFORM public.validate_crm_segment_definition_v1_ts(v_criterion -> 'value');
                        END IF;
                    ELSE
                        v_values := v_criterion -> 'values';
                        IF jsonb_typeof(v_values) <> 'array' THEN
                            RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'values must be an array';
                        END IF;

                        v_count := jsonb_array_length(v_values);
                        IF v_count < 1 OR v_count > 32 THEN
                            RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'values count out of range';
                        END IF;

                        -- Real repository values only (crm_contacts CHECK constraints).
                        v_allowed_enum := CASE v_field
                            WHEN 'contact.status' THEN ARRAY['active', 'anonymized']
                            ELSE ARRAY['guest_order', 'verified_account']
                        END;

                        IF EXISTS (
                            SELECT 1 FROM jsonb_array_elements(v_values) AS e
                            WHERE jsonb_typeof(e) <> 'string'
                        ) THEN
                            RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'values must be strings';
                        END IF;

                        IF (SELECT count(DISTINCT e #>> '{}') FROM jsonb_array_elements(v_values) AS e) <> v_count THEN
                            RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'values contain duplicates';
                        END IF;

                        FOR v_element IN SELECT e #>> '{}' FROM jsonb_array_elements(v_values) AS e
                        LOOP
                            IF NOT (v_element = ANY (v_allowed_enum)) THEN
                                RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'unknown enum value';
                            END IF;
                        END LOOP;
                    END IF;
                END LOOP;
            END;
            $$;

            -- Exact signed BIGINT: JSON number, integral digits only, within range.
            -- Rejects 1.2, 1e100, "100", true, null, NaN, Infinity.
            CREATE OR REPLACE FUNCTION public.validate_crm_segment_definition_v1_int(p_value JSONB)
            RETURNS void
            LANGUAGE plpgsql
            IMMUTABLE
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_text TEXT;
            BEGIN
                IF p_value IS NULL OR jsonb_typeof(p_value) <> 'number' THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'value must be a JSON number';
                END IF;

                v_text := p_value #>> '{}';

                IF v_text !~ '^-?[0-9]+$' THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'value must be an integer';
                END IF;

                IF v_text::NUMERIC < -9223372036854775808::NUMERIC
                    OR v_text::NUMERIC > 9223372036854775807::NUMERIC THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'value out of BIGINT range';
                END IF;
            END;
            $$;

            -- Absolute UTC RFC3339 only. Rejects "30 days ago", naive datetimes and offsets.
            CREATE OR REPLACE FUNCTION public.validate_crm_segment_definition_v1_ts(p_value JSONB)
            RETURNS void
            LANGUAGE plpgsql
            IMMUTABLE
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_text TEXT;
            BEGIN
                IF p_value IS NULL OR jsonb_typeof(p_value) <> 'string' THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'timestamp must be a string';
                END IF;

                v_text := p_value #>> '{}';

                IF v_text !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,6})?Z$' THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'timestamp must be absolute UTC RFC3339';
                END IF;

                PERFORM v_text::TIMESTAMPTZ;
            END;
            $$;
            SQL);

        // Ownership and ACL for the helpers are applied by the canonical inventory in
        // createFunctions()/lockDownPrivileges() — deliberately NOT here, so there is
        // exactly one source of truth and nothing can be created without being dropped.
    }

    private function createMatcher(): void
    {
        DB::unprepared(<<<'SQL'
            -- Static evaluator. Every branch is hard-coded against the allowlist; the
            -- definition only selects WHICH branch runs, never WHAT SQL is built.
            --
            -- Sources: crm_contacts and crm_contact_commerce_rollups ONLY. Never orders,
            -- payments, refunds, analytics, users, visitors — and never marketing consent.
            CREATE OR REPLACE FUNCTION public.crm_segment_contact_matches_v1(
                p_contact_id BIGINT,
                p_definition JSONB
            )
            RETURNS boolean
            LANGUAGE plpgsql
            STABLE
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_match_all BOOLEAN;
                v_criterion JSONB;
                v_field TEXT;
                v_operator TEXT;
                v_currency VARCHAR(3);
                v_result BOOLEAN;
                v_number BIGINT;
                v_moment TIMESTAMPTZ;
                v_text TEXT;
                v_found BOOLEAN;
                v_rollup public.crm_contact_commerce_rollups%ROWTYPE;
                v_contact public.crm_contacts%ROWTYPE;
            BEGIN
                v_match_all := (p_definition ->> 'match') = 'all';

                SELECT c.* INTO v_contact
                FROM public.crm_contacts AS c
                WHERE c.id = p_contact_id;

                IF NOT FOUND THEN
                    RETURN FALSE;
                END IF;

                FOR v_criterion IN SELECT jsonb_array_elements(p_definition -> 'criteria')
                LOOP
                    v_field := v_criterion ->> 'field';
                    v_operator := v_criterion ->> 'operator';
                    v_result := FALSE;

                    IF v_field LIKE 'commerce.%' THEN
                        v_currency := (v_criterion ->> 'currency')::VARCHAR(3);

                        SELECT r.* INTO v_rollup
                        FROM public.crm_contact_commerce_rollups AS r
                        WHERE r.contact_id = p_contact_id
                          AND r.currency = v_currency;

                        v_found := FOUND;

                        IF NOT v_found THEN
                            -- Missing rollup row => FALSE for EVERY operator, including neq.
                            v_result := FALSE;
                        ELSIF v_field IN (
                            'commerce.net_revenue_minor',
                            'commerce.gross_revenue_minor',
                            'commerce.refunded_amount_minor',
                            'commerce.acquired_orders_count'
                        ) THEN
                            v_number := CASE v_field
                                WHEN 'commerce.net_revenue_minor' THEN v_rollup.net_revenue_minor
                                WHEN 'commerce.gross_revenue_minor' THEN v_rollup.gross_revenue_minor
                                WHEN 'commerce.refunded_amount_minor' THEN v_rollup.refunded_amount_minor
                                ELSE v_rollup.acquired_orders_count
                            END;

                            v_result := CASE v_operator
                                WHEN 'eq' THEN v_number = (v_criterion ->> 'value')::BIGINT
                                WHEN 'neq' THEN v_number <> (v_criterion ->> 'value')::BIGINT
                                WHEN 'gt' THEN v_number > (v_criterion ->> 'value')::BIGINT
                                WHEN 'gte' THEN v_number >= (v_criterion ->> 'value')::BIGINT
                                WHEN 'lt' THEN v_number < (v_criterion ->> 'value')::BIGINT
                                WHEN 'lte' THEN v_number <= (v_criterion ->> 'value')::BIGINT
                                ELSE v_number BETWEEN (v_criterion ->> 'lower')::BIGINT
                                                  AND (v_criterion ->> 'upper')::BIGINT
                            END;
                        ELSE
                            v_moment := CASE v_field
                                WHEN 'commerce.first_acquired_at' THEN v_rollup.first_acquired_at
                                ELSE v_rollup.last_acquired_at
                            END;

                            IF v_moment IS NULL THEN
                                v_result := FALSE;
                            ELSE
                                v_result := CASE v_operator
                                    WHEN 'before' THEN v_moment < (v_criterion ->> 'value')::TIMESTAMPTZ
                                    WHEN 'after' THEN v_moment > (v_criterion ->> 'value')::TIMESTAMPTZ
                                    ELSE v_moment BETWEEN (v_criterion ->> 'lower')::TIMESTAMPTZ
                                                      AND (v_criterion ->> 'upper')::TIMESTAMPTZ
                                END;
                            END IF;
                        END IF;
                    ELSIF v_field = 'contact.created_at' THEN
                        -- crm_contacts.created_at is nullable: a NULL is an explicit FALSE,
                        -- never an SQL-NULL leaking into the boolean algebra.
                        IF v_contact.created_at IS NULL THEN
                            v_result := FALSE;
                        ELSE
                            v_result := CASE v_operator
                                WHEN 'before' THEN v_contact.created_at < (v_criterion ->> 'value')::TIMESTAMPTZ
                                WHEN 'after' THEN v_contact.created_at > (v_criterion ->> 'value')::TIMESTAMPTZ
                                ELSE v_contact.created_at BETWEEN (v_criterion ->> 'lower')::TIMESTAMPTZ
                                                              AND (v_criterion ->> 'upper')::TIMESTAMPTZ
                            END;
                        END IF;
                    ELSE
                        v_text := CASE v_field
                            WHEN 'contact.status' THEN v_contact.status
                            ELSE v_contact.origin
                        END;

                        v_found := EXISTS (
                            SELECT 1 FROM jsonb_array_elements(v_criterion -> 'values') AS e
                            WHERE e #>> '{}' = v_text
                        );

                        v_result := CASE v_operator
                            WHEN 'in' THEN v_found
                            ELSE NOT v_found
                        END;
                    END IF;

                    v_result := COALESCE(v_result, FALSE);

                    IF v_match_all AND NOT v_result THEN
                        RETURN FALSE;
                    END IF;

                    IF NOT v_match_all AND v_result THEN
                        RETURN TRUE;
                    END IF;
                END LOOP;

                RETURN v_match_all;
            END;
            $$;
            SQL);
    }

    private function createImmutabilityFunctions(): void
    {
        DB::unprepared(<<<'SQL'
            -- A version's CONTENT is immutable from INSERT (D-049 K3). The only allowed
            -- transition is draft -> published, which stamps published_at.
            CREATE OR REPLACE FUNCTION public.enforce_crm_segment_version_immutability()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM segment versions cannot be deleted';
                END IF;

                IF ROW(NEW.segment_id, NEW.version_number, NEW.definition_schema_version, NEW.definition, NEW.created_at)
                    IS DISTINCT FROM
                   ROW(OLD.segment_id, OLD.version_number, OLD.definition_schema_version, OLD.definition, OLD.created_at) THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM segment version content is immutable';
                END IF;

                IF NOT (OLD.status = 'draft' AND NEW.status = 'published') THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM segment version transition is invalid';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.enforce_crm_segment_generation_immutability()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM segment generations cannot be deleted';
                END IF;

                IF OLD.status = 'published' THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'a published CRM segment generation is immutable';
                END IF;

                IF ROW(NEW.segment_id, NEW.segment_version_id, NEW.contact_id_high_water_mark, NEW.batch_size, NEW.created_at)
                    IS DISTINCT FROM
                   ROW(OLD.segment_id, OLD.segment_version_id, OLD.contact_id_high_water_mark, OLD.batch_size, OLD.created_at) THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM segment generation evidence is immutable';
                END IF;

                RETURN NEW;
            END;
            $$;

            -- Membership is append-only while building and frozen once published.
            CREATE OR REPLACE FUNCTION public.enforce_crm_segment_generation_member_immutability()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_status TEXT;
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM segment membership rows are immutable';
                END IF;

                SELECT g.status INTO v_status
                FROM public.crm_segment_generations AS g
                WHERE g.id = NEW.generation_id;

                IF v_status IS DISTINCT FROM 'ready' AND v_status IS DISTINCT FROM 'running' THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'membership can only be written while the generation is building';
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);
    }

    private function createLifecycleAuthorities(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.create_crm_segment(p_name VARCHAR)
            RETURNS TABLE(segment_id BIGINT, name VARCHAR, status VARCHAR)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_name VARCHAR(120);
                v_id BIGINT;
            BEGIN
                v_name := btrim(COALESCE(p_name, ''));

                IF char_length(v_name) < 1 OR char_length(v_name) > 120 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid segment name';
                END IF;

                INSERT INTO public.crm_segments (name, status, created_at, updated_at)
                VALUES (v_name, 'active', clock_timestamp(), clock_timestamp())
                RETURNING id INTO v_id;

                RETURN QUERY SELECT v_id, v_name, 'active'::varchar;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.create_crm_segment_version(
                p_segment_id BIGINT,
                p_definition JSONB
            )
            RETURNS TABLE(version_id BIGINT, segment_id BIGINT, version_number INTEGER, status VARCHAR)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_segment public.crm_segments%ROWTYPE;
                v_next INTEGER;
                v_id BIGINT;
            BEGIN
                IF p_segment_id IS NULL OR p_segment_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid segment identifier';
                END IF;

                -- Serialise version numbering on the segment row.
                SELECT s.* INTO v_segment
                FROM public.crm_segments AS s
                WHERE s.id = p_segment_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'unknown segment';
                END IF;

                IF v_segment.status <> 'active' THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'segment is not active';
                END IF;

                PERFORM public.validate_crm_segment_definition_v1(p_definition);

                SELECT COALESCE(MAX(v.version_number), 0) + 1
                INTO v_next
                FROM public.crm_segment_versions AS v
                WHERE v.segment_id = p_segment_id;

                INSERT INTO public.crm_segment_versions (
                    segment_id, version_number, definition_schema_version, definition,
                    status, published_at, created_at, updated_at
                ) VALUES (
                    p_segment_id, v_next, 1, p_definition,
                    'draft', NULL, clock_timestamp(), clock_timestamp()
                )
                RETURNING id INTO v_id;

                RETURN QUERY SELECT v_id, p_segment_id, v_next, 'draft'::varchar;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.publish_crm_segment_version(p_version_id BIGINT)
            RETURNS TABLE(version_id BIGINT, segment_id BIGINT, version_number INTEGER, status VARCHAR)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_version public.crm_segment_versions%ROWTYPE;
                v_segment public.crm_segments%ROWTYPE;
                v_current_number INTEGER;
            BEGIN
                IF p_version_id IS NULL OR p_version_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid version identifier';
                END IF;

                SELECT v.* INTO v_version
                FROM public.crm_segment_versions AS v
                WHERE v.id = p_version_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'unknown segment version';
                END IF;

                SELECT s.* INTO v_segment
                FROM public.crm_segments AS s
                WHERE s.id = v_version.segment_id
                FOR UPDATE;

                IF v_version.status <> 'draft' THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'only a draft version can be published';
                END IF;

                -- A build in flight pins its version: publishing a new one is refused
                -- until the generation finishes (D-049 K5).
                IF EXISTS (
                    SELECT 1 FROM public.crm_segment_generations AS g
                    WHERE g.segment_id = v_version.segment_id
                      AND g.status IN ('ready', 'running')
                ) THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'an active generation is building for this segment';
                END IF;

                -- Never republish an older version over a newer published one.
                IF v_segment.current_version_id IS NOT NULL THEN
                    SELECT v.version_number INTO v_current_number
                    FROM public.crm_segment_versions AS v
                    WHERE v.id = v_segment.current_version_id;

                    IF v_version.version_number <= v_current_number THEN
                        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'published version numbers must increase';
                    END IF;
                END IF;

                UPDATE public.crm_segment_versions AS v
                SET status = 'published',
                    published_at = clock_timestamp(),
                    updated_at = clock_timestamp()
                WHERE v.id = p_version_id;

                UPDATE public.crm_segments AS s
                SET current_version_id = p_version_id,
                    updated_at = clock_timestamp()
                WHERE s.id = v_version.segment_id;

                RETURN QUERY SELECT p_version_id, v_version.segment_id, v_version.version_number, 'published'::varchar;
            END;
            $$;
            SQL);
    }

    private function createGenerationAuthorities(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.start_crm_segment_generation(
                p_segment_id BIGINT,
                p_batch_size INTEGER
            )
            RETURNS TABLE(
                generation_id BIGINT,
                segment_id BIGINT,
                segment_version_id BIGINT,
                contact_id_high_water_mark BIGINT,
                status VARCHAR
            )
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_segment public.crm_segments%ROWTYPE;
                v_hwm BIGINT;
                v_id BIGINT;
            BEGIN
                IF p_segment_id IS NULL OR p_segment_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid segment identifier';
                END IF;

                IF p_batch_size IS NULL OR p_batch_size < 1 OR p_batch_size > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM segment batch size is invalid';
                END IF;

                SELECT s.* INTO v_segment
                FROM public.crm_segments AS s
                WHERE s.id = p_segment_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'unknown segment';
                END IF;

                IF v_segment.status <> 'active' THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'segment is not active';
                END IF;

                IF v_segment.current_version_id IS NULL THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'segment has no published version';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM public.crm_segment_generations AS g
                    WHERE g.segment_id = p_segment_id
                      AND g.status IN ('ready', 'running')
                ) THEN
                    RAISE EXCEPTION USING ERRCODE = '23505', MESSAGE = 'an active generation already exists for this segment';
                END IF;

                -- Bounds the CONTACT population this generation walks.
                SELECT COALESCE(MAX(c.id), 0) INTO v_hwm FROM public.crm_contacts AS c;

                INSERT INTO public.crm_segment_generations (
                    segment_id, segment_version_id, contact_id_high_water_mark, batch_size,
                    cursor_contact_id, status, members_count, last_error_code,
                    started_at, completed_at, published_at, failed_at, created_at, updated_at
                ) VALUES (
                    p_segment_id, v_segment.current_version_id, v_hwm, p_batch_size,
                    NULL, 'ready', 0, NULL,
                    NULL, NULL, NULL, NULL, clock_timestamp(), clock_timestamp()
                )
                RETURNING id INTO v_id;

                RETURN QUERY SELECT v_id, p_segment_id, v_segment.current_version_id, v_hwm, 'ready'::varchar;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.process_crm_segment_generation_batch(p_generation_id BIGINT)
            RETURNS TABLE(
                generation_id BIGINT,
                status VARCHAR,
                scanned_in_batch INTEGER,
                matched_in_batch INTEGER,
                members_count BIGINT
            )
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_generation public.crm_segment_generations%ROWTYPE;
                v_definition JSONB;
                v_contact RECORD;
                v_scanned INTEGER := 0;
                v_matched INTEGER := 0;
                v_last_scanned BIGINT;
                v_sqlstate TEXT;
            BEGIN
                IF p_generation_id IS NULL OR p_generation_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid generation identifier';
                END IF;

                SELECT g.* INTO v_generation
                FROM public.crm_segment_generations AS g
                WHERE g.id = p_generation_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RETURN QUERY SELECT p_generation_id, 'not_found'::varchar, 0, 0, NULL::BIGINT;
                    RETURN;
                END IF;

                IF v_generation.status NOT IN ('ready', 'running') THEN
                    RETURN QUERY SELECT p_generation_id, v_generation.status::varchar, 0, 0, v_generation.members_count;
                    RETURN;
                END IF;

                SELECT v.definition INTO v_definition
                FROM public.crm_segment_versions AS v
                WHERE v.id = v_generation.segment_version_id;

                BEGIN
                    UPDATE public.crm_segment_generations AS g
                    SET status = 'running',
                        started_at = COALESCE(g.started_at, clock_timestamp()),
                        updated_at = clock_timestamp()
                    WHERE g.id = p_generation_id;

                    FOR v_contact IN
                        SELECT c.id
                        FROM public.crm_contacts AS c
                        WHERE c.id > COALESCE(v_generation.cursor_contact_id, 0)
                          AND c.id <= v_generation.contact_id_high_water_mark
                        ORDER BY c.id
                        LIMIT v_generation.batch_size
                    LOOP
                        v_scanned := v_scanned + 1;
                        -- The cursor tracks the last SCANNED contact, never the last
                        -- MATCHED one: a batch with zero matches must still advance.
                        v_last_scanned := v_contact.id;

                        IF public.crm_segment_contact_matches_v1(v_contact.id, v_definition) THEN
                            INSERT INTO public.crm_segment_generation_members (generation_id, contact_id)
                            VALUES (p_generation_id, v_contact.id)
                            ON CONFLICT DO NOTHING;

                            IF FOUND THEN
                                v_matched := v_matched + 1;
                            END IF;
                        END IF;
                    END LOOP;

                    IF v_scanned = 0 THEN
                        -- Nothing left to scan: publish this generation atomically.
                        UPDATE public.crm_segment_generations AS g
                        SET status = 'published',
                            completed_at = clock_timestamp(),
                            published_at = clock_timestamp(),
                            started_at = COALESCE(g.started_at, clock_timestamp()),
                            updated_at = clock_timestamp()
                        WHERE g.id = p_generation_id;

                        UPDATE public.crm_segments AS s
                        SET current_generation_id = p_generation_id,
                            updated_at = clock_timestamp()
                        WHERE s.id = v_generation.segment_id;

                        RETURN QUERY SELECT p_generation_id, 'published'::varchar, 0, 0, v_generation.members_count;
                        RETURN;
                    END IF;

                    UPDATE public.crm_segment_generations AS g
                    SET cursor_contact_id = v_last_scanned,
                        members_count = g.members_count + v_matched,
                        status = 'ready',
                        last_error_code = NULL,
                        updated_at = clock_timestamp()
                    WHERE g.id = p_generation_id;

                    RETURN QUERY SELECT p_generation_id, 'ready'::varchar, v_scanned, v_matched,
                        v_generation.members_count + v_matched;
                    RETURN;
                EXCEPTION
                    WHEN OTHERS THEN
                        -- The subtransaction rolls back every membership row, the cursor
                        -- and the counters written by THIS batch.
                        GET STACKED DIAGNOSTICS v_sqlstate = RETURNED_SQLSTATE;

                        UPDATE public.crm_segment_generations AS g
                        SET status = 'failed',
                            failed_at = clock_timestamp(),
                            started_at = COALESCE(g.started_at, clock_timestamp()),
                            last_error_code = v_sqlstate,
                            updated_at = clock_timestamp()
                        WHERE g.id = p_generation_id;

                        RETURN QUERY SELECT p_generation_id, 'failed'::varchar, 0, 0, v_generation.members_count;
                        RETURN;
                END;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.retry_crm_segment_generation(p_generation_id BIGINT)
            RETURNS TABLE(generation_id BIGINT, status VARCHAR)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_generation public.crm_segment_generations%ROWTYPE;
            BEGIN
                IF p_generation_id IS NULL OR p_generation_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid generation identifier';
                END IF;

                SELECT g.* INTO v_generation
                FROM public.crm_segment_generations AS g
                WHERE g.id = p_generation_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RETURN QUERY SELECT p_generation_id, 'not_found'::varchar;
                    RETURN;
                END IF;

                IF v_generation.status <> 'failed' THEN
                    RETURN QUERY SELECT p_generation_id, v_generation.status::varchar;
                    RETURN;
                END IF;

                -- Resume from the last committed batch: members already written stay.
                UPDATE public.crm_segment_generations AS g
                SET status = 'ready',
                    failed_at = NULL,
                    updated_at = clock_timestamp()
                WHERE g.id = p_generation_id;

                RETURN QUERY SELECT p_generation_id, 'ready'::varchar;
            END;
            $$;
            SQL);
    }

    private function createReadAuthorities(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.get_crm_segment(p_segment_id BIGINT)
            RETURNS TABLE(
                segment_id BIGINT,
                name VARCHAR,
                status VARCHAR,
                current_version_id BIGINT,
                current_version_number INTEGER,
                current_generation_id BIGINT,
                current_members_count BIGINT,
                generation_published_at TIMESTAMPTZ
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_segment_id IS NULL OR p_segment_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid segment identifier';
                END IF;

                RETURN QUERY
                SELECT s.id, s.name::varchar, s.status::varchar, s.current_version_id,
                       v.version_number, s.current_generation_id, g.members_count, g.published_at
                FROM public.crm_segments AS s
                LEFT JOIN public.crm_segment_versions AS v ON v.id = s.current_version_id
                LEFT JOIN public.crm_segment_generations AS g ON g.id = s.current_generation_id
                WHERE s.id = p_segment_id;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.list_crm_segments(
                p_after_segment_id BIGINT,
                p_limit INTEGER
            )
            RETURNS TABLE(
                segment_id BIGINT,
                name VARCHAR,
                status VARCHAR,
                current_version_number INTEGER,
                current_generation_id BIGINT,
                current_members_count BIGINT,
                generation_published_at TIMESTAMPTZ
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM segment page size is invalid';
                END IF;

                RETURN QUERY
                SELECT s.id, s.name::varchar, s.status::varchar, v.version_number,
                       s.current_generation_id, g.members_count, g.published_at
                FROM public.crm_segments AS s
                LEFT JOIN public.crm_segment_versions AS v ON v.id = s.current_version_id
                LEFT JOIN public.crm_segment_generations AS g ON g.id = s.current_generation_id
                WHERE p_after_segment_id IS NULL OR s.id > p_after_segment_id
                ORDER BY s.id
                LIMIT p_limit;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.get_crm_segment_generation(p_generation_id BIGINT)
            RETURNS TABLE(
                generation_id BIGINT,
                segment_id BIGINT,
                segment_version_id BIGINT,
                contact_id_high_water_mark BIGINT,
                batch_size INTEGER,
                cursor_contact_id BIGINT,
                status VARCHAR,
                members_count BIGINT,
                last_error_code VARCHAR
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

                RETURN QUERY
                SELECT g.id, g.segment_id, g.segment_version_id, g.contact_id_high_water_mark,
                       g.batch_size, g.cursor_contact_id, g.status::varchar, g.members_count,
                       g.last_error_code::varchar
                FROM public.crm_segment_generations AS g
                WHERE g.id = p_generation_id;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.list_due_crm_segment_generations(p_limit INTEGER)
            RETURNS TABLE(generation_id BIGINT)
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM segment batch size is invalid';
                END IF;

                RETURN QUERY
                SELECT g.id
                FROM public.crm_segment_generations AS g
                WHERE g.status IN ('ready', 'running')
                ORDER BY g.id
                LIMIT p_limit;
            END;
            $$;

            -- Readers only ever see the PUBLISHED current generation: a generation being
            -- built is invisible until its final transaction flips the pointer.
            CREATE OR REPLACE FUNCTION public.list_crm_segment_current_members(
                p_segment_id BIGINT,
                p_after_contact_id BIGINT,
                p_limit INTEGER
            )
            RETURNS TABLE(contact_id BIGINT)
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_segment_id IS NULL OR p_segment_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid segment identifier';
                END IF;

                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM segment page size is invalid';
                END IF;

                RETURN QUERY
                SELECT m.contact_id
                FROM public.crm_segments AS s
                JOIN public.crm_segment_generations AS g
                    ON g.id = s.current_generation_id AND g.status = 'published'
                JOIN public.crm_segment_generation_members AS m
                    ON m.generation_id = g.id
                WHERE s.id = p_segment_id
                  AND (p_after_contact_id IS NULL OR m.contact_id > p_after_contact_id)
                ORDER BY m.contact_id
                LIMIT p_limit;
            END;
            $$;
            SQL);
    }

    private function createTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER crm_segment_versions_immutable_trigger
            BEFORE UPDATE OR DELETE ON public.crm_segment_versions
            FOR EACH ROW
            EXECUTE FUNCTION public.enforce_crm_segment_version_immutability();

            CREATE TRIGGER crm_segment_generations_immutable_trigger
            BEFORE UPDATE OR DELETE ON public.crm_segment_generations
            FOR EACH ROW
            EXECUTE FUNCTION public.enforce_crm_segment_generation_immutability();

            CREATE TRIGGER crm_segment_generation_members_immutable_trigger
            BEFORE INSERT OR UPDATE OR DELETE ON public.crm_segment_generation_members
            FOR EACH ROW
            EXECUTE FUNCTION public.enforce_crm_segment_generation_member_immutability();
            SQL);
    }

    private function lockDownPrivileges(): void
    {
        foreach ([
            'crm_segments',
            'crm_segment_versions',
            'crm_segment_generations',
            'crm_segment_generation_members',
        ] as $table) {
            DB::statement('REVOKE ALL ON TABLE public.'.$table.' FROM PUBLIC');
            DB::statement('REVOKE ALL ON TABLE public.'.$table.' FROM digitrove_runtime');
        }

        foreach ($this->allFunctionSignatures() as $signature) {
            DB::statement('REVOKE ALL ON FUNCTION '.$signature.' FROM PUBLIC');
            DB::statement('REVOKE ALL ON FUNCTION '.$signature.' FROM digitrove_runtime');
        }

        // The runtime drives the lifecycle through bounded authorities only. It never
        // gets EXECUTE on the validator or the matcher, and no direct table access.
        foreach (self::RUNTIME_SIGNATURES as $signature) {
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
            throw new RuntimeException('P6-A2 migration: digitrove_crm_executor must be a restricted NOLOGIN NOINHERIT role.');
        }
    }
};
