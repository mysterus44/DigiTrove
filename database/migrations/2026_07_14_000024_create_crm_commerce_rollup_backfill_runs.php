<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6-A1.3 — Explicit Historical Commerce Rollup Backfill.
 *
 * P6-A1.2 refreshes a rollup as soon as a NEW attribution or a NEW succeeded refund
 * happens. It does not reconstruct history that predates those signals. This gate adds
 * an explicit, audited, operator-driven backfill that finds historical
 * (contact_id, currency) pairs and feeds them into the P6-A1.2 pipeline:
 *
 *   crm_order_attributions ⋈ eligible orders  (authoritative source, no email lookup)
 *        → (contact_id, currency)
 *        → enqueue_crm_commerce_rollup_refresh   (P6-A1.2)
 *        → refresh_crm_contact_commerce_rollup   (P6-A1.1, the only money authority)
 *
 * It computes no money, mutates no Order/Refund/rollup, creates no contact and never
 * resolves identity by e-mail. Runs are durable, resumable and bounded.
 *
 * HIGH-WATER MARK, NOT AN MVCC SNAPSHOT — `crm_order_attributions` has NO surrogate
 * `id`: its primary key IS `order_id` (see 000021), and no authoritative insertion
 * marker exists (`attributed_at` is timestamp(0) AND caller-supplied, so it cannot
 * fence insertions). A run therefore freezes
 * `attribution_order_id_high_water_mark = COALESCE(MAX(order_id), 0)`, whose exact
 * meaning is:
 *
 *   every attribution present when the run started satisfies order_id <= HWM.
 *
 * It deliberately does NOT claim the reverse. A LATE attribution inserted on an OLD
 * order also satisfies order_id <= HWM, so the run may or may not see it depending on
 * where the keyset cursor already stands. That is not an anomaly:
 *
 *   - late attribution on a NEW order (order_id > HWM): ignored here, enqueued by the
 *     P6-A1.2 AFTER INSERT trigger;
 *   - late attribution on an OLD order (order_id <= HWM): enqueued by the same trigger
 *     whether or not the backfill also sees it;
 *   - double coverage is harmless: P6-A1.2 coalesces by generation and P6-A1.1
 *     recomputes authoritatively from Commerce, so the money is identical.
 *
 * The system is RACE-SAFE, not snapshot-isolated over the attribution population.
 *
 * FINITENESS — attributions are immutable (trigger blocks UPDATE/DELETE) and there is
 * at most ONE attribution per Order (the PK is order_id). The candidate domain
 * {attributions WHERE order_id <= HWM} is therefore bounded by the Orders that existed
 * when the run started, so the keyset walk always terminates.
 */
return new class extends Migration
{
    private const HIGH_WATER_MARK_SIGNATURE = 'public.current_crm_commerce_rollup_backfill_high_water_mark()';

    private const LIST_CANDIDATES_SIGNATURE = 'public.list_crm_commerce_rollup_backfill_candidates(bigint, bigint, character varying, integer)';

    private const START_SIGNATURE = 'public.start_crm_commerce_rollup_backfill(integer)';

    private const GET_RUN_SIGNATURE = 'public.get_crm_commerce_rollup_backfill_run(bigint)';

    private const PROCESS_BATCH_SIGNATURE = 'public.process_crm_commerce_rollup_backfill_batch(bigint)';

    private const RETRY_SIGNATURE = 'public.retry_crm_commerce_rollup_backfill_run(bigint)';

    public function up(): void
    {
        $this->assertExecutorProvisioned();

        Schema::create('crm_commerce_rollup_backfill_runs', function (Blueprint $table): void {
            $table->id();
            // MAX(crm_order_attributions.order_id) frozen when the run starts.
            $table->bigInteger('attribution_order_id_high_water_mark');
            $table->integer('batch_size');
            // Durable keyset cursor: the last (contact_id, currency) pair processed.
            $table->bigInteger('cursor_contact_id')->nullable();
            $table->string('cursor_currency', 3)->nullable();
            $table->bigInteger('batches_processed_count')->default(0);
            $table->bigInteger('enqueued_pairs_count')->default(0);
            $table->string('status', 16)->default('ready');
            $table->string('last_error_code', 5)->nullable();
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampTz('failed_at', 6)->nullable();
            $table->timestampsTz(6);
        });

        DB::statement('ALTER TABLE public.crm_commerce_rollup_backfill_runs ADD CONSTRAINT crm_commerce_rollup_backfill_runs_high_water_mark_check CHECK (attribution_order_id_high_water_mark >= 0)');
        DB::statement('ALTER TABLE public.crm_commerce_rollup_backfill_runs ADD CONSTRAINT crm_commerce_rollup_backfill_runs_batch_size_check CHECK (batch_size BETWEEN 1 AND 100)');
        DB::statement('ALTER TABLE public.crm_commerce_rollup_backfill_runs ADD CONSTRAINT crm_commerce_rollup_backfill_runs_cursor_contact_check CHECK (cursor_contact_id IS NULL OR cursor_contact_id > 0)');
        DB::statement("ALTER TABLE public.crm_commerce_rollup_backfill_runs ADD CONSTRAINT crm_commerce_rollup_backfill_runs_cursor_currency_check CHECK (cursor_currency IS NULL OR cursor_currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE public.crm_commerce_rollup_backfill_runs ADD CONSTRAINT crm_commerce_rollup_backfill_runs_cursor_pairing_check CHECK ((cursor_contact_id IS NULL) = (cursor_currency IS NULL))');
        DB::statement('ALTER TABLE public.crm_commerce_rollup_backfill_runs ADD CONSTRAINT crm_commerce_rollup_backfill_runs_batches_count_check CHECK (batches_processed_count >= 0)');
        DB::statement('ALTER TABLE public.crm_commerce_rollup_backfill_runs ADD CONSTRAINT crm_commerce_rollup_backfill_runs_pairs_count_check CHECK (enqueued_pairs_count >= 0)');
        DB::statement("ALTER TABLE public.crm_commerce_rollup_backfill_runs ADD CONSTRAINT crm_commerce_rollup_backfill_runs_status_check CHECK (status IN ('ready', 'running', 'completed', 'failed'))");
        DB::statement("ALTER TABLE public.crm_commerce_rollup_backfill_runs ADD CONSTRAINT crm_commerce_rollup_backfill_runs_error_code_check CHECK (last_error_code IS NULL OR last_error_code ~ '^[0-9A-Z]{5}$')");
        // Status/timestamp coherence (CASE ... ELSE FALSE END IS TRUE avoids CHECK = UNKNOWN).
        DB::statement(<<<'SQL'
            ALTER TABLE public.crm_commerce_rollup_backfill_runs
            ADD CONSTRAINT crm_commerce_rollup_backfill_runs_status_dates_check
            CHECK ((
                CASE
                    WHEN status = 'ready'     THEN completed_at IS NULL AND failed_at IS NULL
                    WHEN status = 'running'   THEN started_at IS NOT NULL AND completed_at IS NULL AND failed_at IS NULL
                    WHEN status = 'completed' THEN completed_at IS NOT NULL AND failed_at IS NULL
                    WHEN status = 'failed'    THEN failed_at IS NOT NULL AND completed_at IS NULL
                    ELSE FALSE
                END
            ) IS TRUE)
            SQL);

        // At most ONE active run at a time. A unique index on a constant expression,
        // restricted to the active statuses, makes a second concurrent run impossible
        // at the storage level rather than by application convention.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX crm_commerce_rollup_backfill_runs_single_active
            ON public.crm_commerce_rollup_backfill_runs ((TRUE))
            WHERE status IN ('ready', 'running')
            SQL);

        DB::statement('ALTER TABLE public.crm_commerce_rollup_backfill_runs OWNER TO digitrove_crm_executor');

        $this->createFunctions();
        $this->lockDownPrivileges();
    }

    public function down(): void
    {
        foreach ([
            self::HIGH_WATER_MARK_SIGNATURE,
            self::LIST_CANDIDATES_SIGNATURE,
            self::START_SIGNATURE,
            self::GET_RUN_SIGNATURE,
            self::PROCESS_BATCH_SIGNATURE,
            self::RETRY_SIGNATURE,
        ] as $signature) {
            DB::statement('REVOKE EXECUTE ON FUNCTION '.$signature.' FROM digitrove_runtime');
            DB::statement('DROP FUNCTION IF EXISTS '.$signature);
        }

        Schema::dropIfExists('crm_commerce_rollup_backfill_runs');
    }

    private function createFunctions(): void
    {
        // `migrate:fresh` drops tables but NOT functions, and CREATE OR REPLACE refuses
        // to rename an input parameter. Dropping by signature first (signatures match on
        // argument TYPES, not names) keeps this migration replayable on a dirty database.
        foreach ($this->functionSignatures() as $signature) {
            DB::statement('DROP FUNCTION IF EXISTS '.$signature);
        }

        DB::unprepared(<<<'SQL'
            -- The boundary a run would freeze right now. The runtime has no SELECT on
            -- crm_order_attributions (P6-A1.0 boundary), so it must read it through
            -- this authority rather than by querying the table.
            CREATE OR REPLACE FUNCTION public.current_crm_commerce_rollup_backfill_high_water_mark()
            RETURNS BIGINT
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_high_water_mark BIGINT;
            BEGIN
                SELECT COALESCE(MAX(coa.order_id), 0)
                INTO v_high_water_mark
                FROM public.crm_order_attributions AS coa;

                RETURN v_high_water_mark;
            END;
            $$;

            -- READ-ONLY candidate preview. Powers the dry-run: it can never mutate.
            CREATE OR REPLACE FUNCTION public.list_crm_commerce_rollup_backfill_candidates(
                p_high_water_mark BIGINT,
                p_cursor_contact_id BIGINT,
                p_cursor_currency VARCHAR,
                p_limit INTEGER
            )
            RETURNS TABLE(contact_id BIGINT, currency VARCHAR)
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_high_water_mark IS NULL OR p_high_water_mark < 0 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid snapshot boundary';
                END IF;

                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM rollup backfill batch size is invalid';
                END IF;

                IF (p_cursor_contact_id IS NULL) <> (p_cursor_currency IS NULL) THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid cursor pairing';
                END IF;

                -- Authoritative historical population: an immutable attribution joined to
                -- an ACQUIRED order. Never an e-mail, a resolver, a User or a Visitor.
                RETURN QUERY
                SELECT DISTINCT coa.contact_id, o.currency::varchar
                FROM public.crm_order_attributions AS coa
                JOIN public.orders AS o ON o.id = coa.order_id
                WHERE coa.order_id <= p_high_water_mark
                  AND o.status IN ('paid', 'partially_refunded', 'refunded')
                  AND o.paid_at IS NOT NULL
                  AND (
                        p_cursor_contact_id IS NULL
                        OR ROW(coa.contact_id, o.currency) > ROW(p_cursor_contact_id, p_cursor_currency::varchar(3))
                      )
                ORDER BY 1, 2
                LIMIT p_limit;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.start_crm_commerce_rollup_backfill(p_batch_size INTEGER)
            RETURNS TABLE(
                run_id BIGINT,
                attribution_order_id_high_water_mark BIGINT,
                batch_size INTEGER,
                status VARCHAR
            )
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_high_water_mark BIGINT;
                v_run_id BIGINT;
            BEGIN
                IF p_batch_size IS NULL OR p_batch_size < 1 OR p_batch_size > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM rollup backfill batch size is invalid';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM public.crm_commerce_rollup_backfill_runs AS r
                    WHERE r.status IN ('ready', 'running')
                ) THEN
                    RAISE EXCEPTION USING ERRCODE = '23505', MESSAGE = 'an active CRM rollup backfill run already exists';
                END IF;

                -- Freeze the boundary. No full scan of candidates happens here.
                SELECT COALESCE(MAX(coa.order_id), 0)
                INTO v_high_water_mark
                FROM public.crm_order_attributions AS coa;

                INSERT INTO public.crm_commerce_rollup_backfill_runs (
                    attribution_order_id_high_water_mark, batch_size, cursor_contact_id, cursor_currency,
                    batches_processed_count, enqueued_pairs_count, status, last_error_code,
                    started_at, completed_at, failed_at, created_at, updated_at
                ) VALUES (
                    v_high_water_mark, p_batch_size, NULL, NULL,
                    0, 0, 'ready', NULL,
                    NULL, NULL, NULL, clock_timestamp(), clock_timestamp()
                )
                RETURNING id INTO v_run_id;

                RETURN QUERY SELECT v_run_id, v_high_water_mark, p_batch_size, 'ready'::varchar;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.get_crm_commerce_rollup_backfill_run(p_run_id BIGINT)
            RETURNS TABLE(
                run_id BIGINT,
                attribution_order_id_high_water_mark BIGINT,
                batch_size INTEGER,
                cursor_contact_id BIGINT,
                cursor_currency VARCHAR,
                batches_processed_count BIGINT,
                enqueued_pairs_count BIGINT,
                status VARCHAR,
                last_error_code VARCHAR
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_run_id IS NULL OR p_run_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid run identifier';
                END IF;

                RETURN QUERY
                SELECT r.id, r.attribution_order_id_high_water_mark, r.batch_size, r.cursor_contact_id,
                       r.cursor_currency::varchar, r.batches_processed_count,
                       r.enqueued_pairs_count, r.status::varchar, r.last_error_code::varchar
                FROM public.crm_commerce_rollup_backfill_runs AS r
                WHERE r.id = p_run_id;
            END;
            $$;

            -- One transactional batch. The runtime cannot inject an identity: the pairs
            -- are selected here, from the authoritative source, under the run's snapshot.
            CREATE OR REPLACE FUNCTION public.process_crm_commerce_rollup_backfill_batch(p_run_id BIGINT)
            RETURNS TABLE(
                run_id BIGINT,
                status VARCHAR,
                enqueued_in_batch INTEGER,
                enqueued_pairs_count BIGINT,
                batches_processed_count BIGINT
            )
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_run public.crm_commerce_rollup_backfill_runs%ROWTYPE;
                v_pair RECORD;
                v_count INTEGER := 0;
                v_last_contact_id BIGINT;
                v_last_currency VARCHAR(3);
                v_sqlstate TEXT;
            BEGIN
                IF p_run_id IS NULL OR p_run_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid run identifier';
                END IF;

                SELECT r.* INTO v_run
                FROM public.crm_commerce_rollup_backfill_runs AS r
                WHERE r.id = p_run_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RETURN QUERY SELECT p_run_id, 'not_found'::varchar, 0, NULL::BIGINT, NULL::BIGINT;
                    RETURN;
                END IF;

                IF v_run.status <> 'ready' THEN
                    RETURN QUERY SELECT p_run_id, v_run.status::varchar, 0,
                        v_run.enqueued_pairs_count, v_run.batches_processed_count;
                    RETURN;
                END IF;

                BEGIN
                    FOR v_pair IN
                        SELECT c.contact_id, c.currency
                        FROM public.list_crm_commerce_rollup_backfill_candidates(
                            v_run.attribution_order_id_high_water_mark,
                            v_run.cursor_contact_id,
                            v_run.cursor_currency::varchar,
                            v_run.batch_size
                        ) AS c
                        ORDER BY c.contact_id, c.currency
                    LOOP
                        -- The ONLY write this gate performs: hand the pair to P6-A1.2.
                        PERFORM public.enqueue_crm_commerce_rollup_refresh(v_pair.contact_id, v_pair.currency);
                        v_count := v_count + 1;
                        v_last_contact_id := v_pair.contact_id;
                        v_last_currency := v_pair.currency;
                    END LOOP;

                    IF v_count = 0 THEN
                        UPDATE public.crm_commerce_rollup_backfill_runs AS r
                        SET status = 'completed',
                            completed_at = clock_timestamp(),
                            started_at = COALESCE(r.started_at, clock_timestamp()),
                            updated_at = clock_timestamp()
                        WHERE r.id = p_run_id;

                        RETURN QUERY SELECT p_run_id, 'completed'::varchar, 0,
                            v_run.enqueued_pairs_count, v_run.batches_processed_count;
                        RETURN;
                    END IF;

                    -- Fewer rows than the bound means the keyset is exhausted.
                    UPDATE public.crm_commerce_rollup_backfill_runs AS r
                    SET cursor_contact_id = v_last_contact_id,
                        cursor_currency = v_last_currency,
                        batches_processed_count = r.batches_processed_count + 1,
                        enqueued_pairs_count = r.enqueued_pairs_count + v_count,
                        status = CASE WHEN v_count < v_run.batch_size THEN 'completed' ELSE 'ready' END,
                        started_at = COALESCE(r.started_at, clock_timestamp()),
                        completed_at = CASE WHEN v_count < v_run.batch_size THEN clock_timestamp() ELSE NULL END,
                        last_error_code = NULL,
                        updated_at = clock_timestamp()
                    WHERE r.id = p_run_id;

                    RETURN QUERY SELECT p_run_id,
                        (CASE WHEN v_count < v_run.batch_size THEN 'completed' ELSE 'ready' END)::varchar,
                        v_count,
                        v_run.enqueued_pairs_count + v_count,
                        v_run.batches_processed_count + 1;
                    RETURN;
                EXCEPTION
                    WHEN OTHERS THEN
                        -- The subtransaction rolls back every enqueue and cursor move:
                        -- a failed batch leaves no partial cursor, counter or enqueue.
                        GET STACKED DIAGNOSTICS v_sqlstate = RETURNED_SQLSTATE;

                        UPDATE public.crm_commerce_rollup_backfill_runs AS r
                        SET status = 'failed',
                            failed_at = clock_timestamp(),
                            started_at = COALESCE(r.started_at, clock_timestamp()),
                            last_error_code = v_sqlstate,
                            updated_at = clock_timestamp()
                        WHERE r.id = p_run_id;

                        RETURN QUERY SELECT p_run_id, 'failed'::varchar, 0,
                            v_run.enqueued_pairs_count, v_run.batches_processed_count;
                        RETURN;
                END;
            END;
            $$;

            -- Explicit operator retry. A failed run is never resumed silently.
            CREATE OR REPLACE FUNCTION public.retry_crm_commerce_rollup_backfill_run(p_run_id BIGINT)
            RETURNS TABLE(run_id BIGINT, status VARCHAR)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_run public.crm_commerce_rollup_backfill_runs%ROWTYPE;
            BEGIN
                IF p_run_id IS NULL OR p_run_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid run identifier';
                END IF;

                SELECT r.* INTO v_run
                FROM public.crm_commerce_rollup_backfill_runs AS r
                WHERE r.id = p_run_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RETURN QUERY SELECT p_run_id, 'not_found'::varchar;
                    RETURN;
                END IF;

                IF v_run.status <> 'failed' THEN
                    RETURN QUERY SELECT p_run_id, v_run.status::varchar;
                    RETURN;
                END IF;

                UPDATE public.crm_commerce_rollup_backfill_runs AS r
                SET status = 'ready',
                    failed_at = NULL,
                    updated_at = clock_timestamp()
                WHERE r.id = p_run_id;

                RETURN QUERY SELECT p_run_id, 'ready'::varchar;
            END;
            $$;
            SQL);

        foreach ([
            self::HIGH_WATER_MARK_SIGNATURE,
            self::LIST_CANDIDATES_SIGNATURE,
            self::START_SIGNATURE,
            self::GET_RUN_SIGNATURE,
            self::PROCESS_BATCH_SIGNATURE,
            self::RETRY_SIGNATURE,
        ] as $signature) {
            DB::statement('ALTER FUNCTION '.$signature.' OWNER TO digitrove_crm_executor');
        }
    }

    private function lockDownPrivileges(): void
    {
        DB::statement('REVOKE ALL ON TABLE public.crm_commerce_rollup_backfill_runs FROM PUBLIC');
        DB::statement('REVOKE ALL ON TABLE public.crm_commerce_rollup_backfill_runs FROM digitrove_runtime');

        foreach ([
            self::HIGH_WATER_MARK_SIGNATURE,
            self::LIST_CANDIDATES_SIGNATURE,
            self::START_SIGNATURE,
            self::GET_RUN_SIGNATURE,
            self::PROCESS_BATCH_SIGNATURE,
            self::RETRY_SIGNATURE,
        ] as $signature) {
            DB::statement('REVOKE ALL ON FUNCTION '.$signature.' FROM PUBLIC');
            DB::statement('REVOKE ALL ON FUNCTION '.$signature.' FROM digitrove_runtime');
            // Operator-safe authorities: the runtime may drive a backfill but can never
            // read the table directly, nor hand an arbitrary identity to enqueue.
            DB::statement('GRANT EXECUTE ON FUNCTION '.$signature.' TO digitrove_runtime');
        }
    }

    /** @return list<string> */
    private function functionSignatures(): array
    {
        return [
            self::HIGH_WATER_MARK_SIGNATURE,
            self::LIST_CANDIDATES_SIGNATURE,
            self::START_SIGNATURE,
            self::GET_RUN_SIGNATURE,
            self::PROCESS_BATCH_SIGNATURE,
            self::RETRY_SIGNATURE,
        ];
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
            throw new RuntimeException('P6-A1.3 migration: digitrove_crm_executor must be a restricted NOLOGIN NOINHERIT role.');
        }
    }
};
