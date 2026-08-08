<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6-A1.2 — Durable Rollup Refresh Orchestration & Reconciliation.
 *
 * P6-A1.1 (000022) owns the financial authority `refresh_crm_contact_commerce_rollup`.
 * This gate never recomputes money; it durably ORCHESTRATES calls to that authority:
 *
 *   attribution INSERT / refund → succeeded  (PostgreSQL triggers)
 *        → enqueue authority                 (coalesced outbox, generation++)
 *        → list_due authority                (bounded, runtime EXECUTE-only)
 *        → process authority                 (generation-safe, calls refresh)
 *
 * The outbox is keyed by (contact_id, currency) and coalesces bursts through a
 * monotonic generation counter (requested_generation >= processed_generation), so
 * a thousand refunds for one contact never fan out to a thousand rows. No PII, no
 * money, no backfill (historical reconstruction is P6-A1.3). Runtime may EXECUTE
 * only list_due/process; it can never read the outbox nor call enqueue/refresh.
 */
return new class extends Migration
{
    private const ENQUEUE_SIGNATURE = 'public.enqueue_crm_commerce_rollup_refresh(bigint, character varying)';

    private const FROM_ATTRIBUTION_SIGNATURE = 'public.enqueue_crm_commerce_rollup_refresh_from_attribution()';

    private const FROM_REFUND_SIGNATURE = 'public.enqueue_crm_commerce_rollup_refresh_from_refund()';

    private const LIST_DUE_SIGNATURE = 'public.list_due_crm_commerce_rollup_refreshes(integer)';

    private const PROCESS_SIGNATURE = 'public.process_crm_commerce_rollup_refresh(bigint, character varying)';

    public function up(): void
    {
        $this->assertExecutorProvisioned();

        Schema::create('crm_commerce_rollup_refresh_outbox', function (Blueprint $table): void {
            $table->foreignId('contact_id')->constrained('crm_contacts')->restrictOnDelete();
            $table->string('currency', 3);
            // Monotonic coalescing: every source event bumps requested_generation;
            // process advances processed_generation only up to the value it observed
            // under the row lock, so a concurrent event's increment is never lost.
            $table->bigInteger('requested_generation');
            $table->bigInteger('processed_generation')->default(0);
            $table->unsignedInteger('attempt_count')->default(0);
            // TIMESTAMPTZ(6): TIMESTAMPTZ(0) rounds up sub-second values and can hide
            // a newly-due row from the dispatcher until the wall clock catches up.
            $table->timestampTz('available_at', 6)->useCurrent();
            $table->string('last_error_code', 5)->nullable();
            $table->timestampTz('terminal_at', 6)->nullable();
            $table->string('terminal_reason', 32)->nullable();
            $table->timestampsTz();

            $table->primary(['contact_id', 'currency'], 'crm_commerce_rollup_refresh_outbox_pkey');
        });

        DB::statement("ALTER TABLE public.crm_commerce_rollup_refresh_outbox ADD CONSTRAINT crm_commerce_rollup_refresh_outbox_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE public.crm_commerce_rollup_refresh_outbox ADD CONSTRAINT crm_commerce_rollup_refresh_outbox_requested_check CHECK (requested_generation >= 1)');
        DB::statement('ALTER TABLE public.crm_commerce_rollup_refresh_outbox ADD CONSTRAINT crm_commerce_rollup_refresh_outbox_processed_check CHECK (processed_generation >= 0)');
        DB::statement('ALTER TABLE public.crm_commerce_rollup_refresh_outbox ADD CONSTRAINT crm_commerce_rollup_refresh_outbox_generation_order_check CHECK (requested_generation >= processed_generation)');
        DB::statement('ALTER TABLE public.crm_commerce_rollup_refresh_outbox ADD CONSTRAINT crm_commerce_rollup_refresh_outbox_attempt_check CHECK (attempt_count >= 0)');
        DB::statement("ALTER TABLE public.crm_commerce_rollup_refresh_outbox ADD CONSTRAINT crm_commerce_rollup_refresh_outbox_error_code_check CHECK (last_error_code IS NULL OR last_error_code ~ '^[0-9A-Z]{5}$')");
        DB::statement("ALTER TABLE public.crm_commerce_rollup_refresh_outbox ADD CONSTRAINT crm_commerce_rollup_refresh_outbox_terminal_reason_check CHECK (terminal_reason IS NULL OR terminal_reason IN ('transient_exhausted', 'value_overflow', 'contact_missing', 'invalid_input', 'unexpected'))");
        DB::statement('ALTER TABLE public.crm_commerce_rollup_refresh_outbox ADD CONSTRAINT crm_commerce_rollup_refresh_outbox_terminal_pairing_check CHECK ((terminal_at IS NULL) = (terminal_reason IS NULL))');

        DB::statement('ALTER TABLE public.crm_commerce_rollup_refresh_outbox OWNER TO digitrove_crm_executor');

        $this->createFunctions();
        $this->lockDownPrivileges();
        $this->createTriggers();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS crm_order_attributions_rollup_refresh_trigger ON public.crm_order_attributions');
        DB::statement('DROP TRIGGER IF EXISTS refunds_rollup_refresh_trigger ON public.refunds');

        DB::statement('REVOKE EXECUTE ON FUNCTION '.self::LIST_DUE_SIGNATURE.' FROM digitrove_runtime');
        DB::statement('REVOKE EXECUTE ON FUNCTION '.self::PROCESS_SIGNATURE.' FROM digitrove_runtime');

        DB::statement('DROP FUNCTION IF EXISTS '.self::FROM_ATTRIBUTION_SIGNATURE);
        DB::statement('DROP FUNCTION IF EXISTS '.self::FROM_REFUND_SIGNATURE);
        DB::statement('DROP FUNCTION IF EXISTS '.self::LIST_DUE_SIGNATURE);
        DB::statement('DROP FUNCTION IF EXISTS '.self::PROCESS_SIGNATURE);
        DB::statement('DROP FUNCTION IF EXISTS '.self::ENQUEUE_SIGNATURE);

        Schema::dropIfExists('crm_commerce_rollup_refresh_outbox');
    }

    private function createFunctions(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enqueue_crm_commerce_rollup_refresh(
                p_contact_id BIGINT,
                p_currency VARCHAR
            )
            RETURNS void
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_contact_id IS NULL OR p_contact_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid contact_id';
                END IF;

                IF p_currency IS NULL OR p_currency !~ '^[A-Z]{3}$' THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid currency';
                END IF;

                INSERT INTO public.crm_commerce_rollup_refresh_outbox (
                    contact_id, currency, requested_generation, processed_generation,
                    attempt_count, available_at, last_error_code, terminal_at,
                    terminal_reason, created_at, updated_at
                ) VALUES (
                    p_contact_id, p_currency, 1, 0,
                    0, clock_timestamp(), NULL, NULL,
                    NULL, clock_timestamp(), clock_timestamp()
                )
                ON CONFLICT ON CONSTRAINT crm_commerce_rollup_refresh_outbox_pkey DO UPDATE SET
                    requested_generation = public.crm_commerce_rollup_refresh_outbox.requested_generation + 1,
                    attempt_count = 0,
                    available_at = clock_timestamp(),
                    last_error_code = NULL,
                    terminal_at = NULL,
                    terminal_reason = NULL,
                    updated_at = clock_timestamp();
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.enqueue_crm_commerce_rollup_refresh_from_attribution()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_currency VARCHAR(3);
            BEGIN
                SELECT o.currency
                INTO v_currency
                FROM public.orders AS o
                WHERE o.id = NEW.order_id;

                IF FOUND AND v_currency IS NOT NULL THEN
                    PERFORM public.enqueue_crm_commerce_rollup_refresh(NEW.contact_id, v_currency);
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.enqueue_crm_commerce_rollup_refresh_from_refund()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_contact_id BIGINT;
                v_currency VARCHAR(3);
            BEGIN
                -- Only a first entry into the authoritative 'succeeded' state matters.
                -- succeeded is terminal/immutable in Commerce, so false -> true suffices.
                IF NEW.status <> 'succeeded' THEN
                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.status = 'succeeded' THEN
                    RETURN NEW;
                END IF;

                -- Contact comes ONLY from an existing immutable attribution; never from
                -- the refund/order email. If no attribution exists yet, do nothing: the
                -- later attribution INSERT enqueues and refresh includes this refund.
                SELECT coa.contact_id, o.currency
                INTO v_contact_id, v_currency
                FROM public.payments AS p
                JOIN public.orders AS o ON o.id = p.order_id
                JOIN public.crm_order_attributions AS coa ON coa.order_id = o.id
                WHERE p.id = NEW.payment_id;

                IF FOUND THEN
                    PERFORM public.enqueue_crm_commerce_rollup_refresh(v_contact_id, v_currency);
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.list_due_crm_commerce_rollup_refreshes(p_limit INTEGER)
            RETURNS TABLE(
                contact_id BIGINT,
                currency VARCHAR,
                requested_generation BIGINT
            )
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM rollup refresh batch size is invalid';
                END IF;

                RETURN QUERY
                SELECT o.contact_id, o.currency::varchar, o.requested_generation
                FROM public.crm_commerce_rollup_refresh_outbox AS o
                WHERE o.terminal_at IS NULL
                  AND o.processed_generation < o.requested_generation
                  AND o.available_at <= clock_timestamp()
                ORDER BY o.available_at, o.contact_id, o.currency
                LIMIT p_limit;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.process_crm_commerce_rollup_refresh(
                p_contact_id BIGINT,
                p_currency VARCHAR
            )
            RETURNS TABLE(
                contact_id BIGINT,
                currency VARCHAR,
                status VARCHAR,
                requested_generation BIGINT,
                processed_generation BIGINT
            )
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_row public.crm_commerce_rollup_refresh_outbox%ROWTYPE;
                v_target BIGINT;
                v_sqlstate TEXT;
                v_new_attempt INTEGER;
                v_backoff INTEGER;
                v_reason VARCHAR(32);
                v_max_attempts CONSTANT INTEGER := 5;
            BEGIN
                IF p_contact_id IS NULL OR p_contact_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid contact_id';
                END IF;

                IF p_currency IS NULL OR p_currency !~ '^[A-Z]{3}$' THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid currency';
                END IF;

                -- The row lock is the serialization point: a concurrent enqueue (which
                -- upserts the same row) blocks here until we commit, so the generation
                -- it will add cannot be lost.
                SELECT o.*
                INTO v_row
                FROM public.crm_commerce_rollup_refresh_outbox AS o
                WHERE o.contact_id = p_contact_id AND o.currency = p_currency
                FOR UPDATE;

                IF NOT FOUND THEN
                    RETURN QUERY SELECT p_contact_id, p_currency::varchar, 'not_found'::varchar,
                        NULL::BIGINT, NULL::BIGINT;
                    RETURN;
                END IF;

                IF v_row.terminal_at IS NOT NULL THEN
                    RETURN QUERY SELECT p_contact_id, p_currency::varchar, 'terminal'::varchar,
                        v_row.requested_generation, v_row.processed_generation;
                    RETURN;
                END IF;

                v_target := v_row.requested_generation;

                IF v_row.processed_generation >= v_target THEN
                    RETURN QUERY SELECT p_contact_id, p_currency::varchar, 'noop'::varchar,
                        v_row.requested_generation, v_row.processed_generation;
                    RETURN;
                END IF;

                BEGIN
                    PERFORM public.refresh_crm_contact_commerce_rollup(p_contact_id, p_currency);

                    UPDATE public.crm_commerce_rollup_refresh_outbox AS o
                    SET processed_generation = v_target,
                        attempt_count = 0,
                        available_at = clock_timestamp(),
                        last_error_code = NULL,
                        updated_at = clock_timestamp()
                    WHERE o.contact_id = p_contact_id AND o.currency = p_currency;

                    RETURN QUERY SELECT p_contact_id, p_currency::varchar, 'refreshed'::varchar,
                        v_target, v_target;
                    RETURN;
                EXCEPTION
                    WHEN serialization_failure OR deadlock_detected OR lock_not_available THEN
                        GET STACKED DIAGNOSTICS v_sqlstate = RETURNED_SQLSTATE;
                        v_new_attempt := v_row.attempt_count + 1;

                        IF v_new_attempt >= v_max_attempts THEN
                            UPDATE public.crm_commerce_rollup_refresh_outbox AS o
                            SET attempt_count = v_new_attempt,
                                terminal_at = clock_timestamp(),
                                terminal_reason = 'transient_exhausted',
                                last_error_code = v_sqlstate,
                                updated_at = clock_timestamp()
                            WHERE o.contact_id = p_contact_id AND o.currency = p_currency;

                            RETURN QUERY SELECT p_contact_id, p_currency::varchar, 'terminal'::varchar,
                                v_target, v_row.processed_generation;
                            RETURN;
                        END IF;

                        v_backoff := LEAST(300, 30 * (2 ^ LEAST(v_new_attempt - 1, 8))::integer);

                        UPDATE public.crm_commerce_rollup_refresh_outbox AS o
                        SET attempt_count = v_new_attempt,
                            available_at = clock_timestamp() + make_interval(secs => v_backoff),
                            last_error_code = v_sqlstate,
                            updated_at = clock_timestamp()
                        WHERE o.contact_id = p_contact_id AND o.currency = p_currency;

                        RETURN QUERY SELECT p_contact_id, p_currency::varchar, 'retry'::varchar,
                            v_target, v_row.processed_generation;
                        RETURN;
                    WHEN OTHERS THEN
                        GET STACKED DIAGNOSTICS v_sqlstate = RETURNED_SQLSTATE;
                        v_reason := CASE v_sqlstate
                            WHEN '22003' THEN 'value_overflow'
                            WHEN '23503' THEN 'contact_missing'
                            WHEN '22023' THEN 'invalid_input'
                            ELSE 'unexpected'
                        END;

                        UPDATE public.crm_commerce_rollup_refresh_outbox AS o
                        SET attempt_count = o.attempt_count + 1,
                            terminal_at = clock_timestamp(),
                            terminal_reason = v_reason,
                            last_error_code = v_sqlstate,
                            updated_at = clock_timestamp()
                        WHERE o.contact_id = p_contact_id AND o.currency = p_currency;

                        RETURN QUERY SELECT p_contact_id, p_currency::varchar, 'terminal'::varchar,
                            v_target, v_row.processed_generation;
                        RETURN;
                END;
            END;
            $$;
            SQL);

        DB::statement('ALTER FUNCTION '.self::ENQUEUE_SIGNATURE.' OWNER TO digitrove_crm_executor');
        DB::statement('ALTER FUNCTION '.self::FROM_ATTRIBUTION_SIGNATURE.' OWNER TO digitrove_crm_executor');
        DB::statement('ALTER FUNCTION '.self::FROM_REFUND_SIGNATURE.' OWNER TO digitrove_crm_executor');
        DB::statement('ALTER FUNCTION '.self::LIST_DUE_SIGNATURE.' OWNER TO digitrove_crm_executor');
        DB::statement('ALTER FUNCTION '.self::PROCESS_SIGNATURE.' OWNER TO digitrove_crm_executor');
    }

    private function lockDownPrivileges(): void
    {
        // Deny everyone by default, then hand the runtime EXECUTE on the two drain
        // authorities only. Runtime never touches the outbox, enqueue, or refresh.
        DB::statement('REVOKE ALL ON TABLE public.crm_commerce_rollup_refresh_outbox FROM PUBLIC');
        DB::statement('REVOKE ALL ON TABLE public.crm_commerce_rollup_refresh_outbox FROM digitrove_runtime');

        foreach ([
            self::ENQUEUE_SIGNATURE,
            self::FROM_ATTRIBUTION_SIGNATURE,
            self::FROM_REFUND_SIGNATURE,
            self::LIST_DUE_SIGNATURE,
            self::PROCESS_SIGNATURE,
        ] as $signature) {
            DB::statement('REVOKE ALL ON FUNCTION '.$signature.' FROM PUBLIC');
            DB::statement('REVOKE ALL ON FUNCTION '.$signature.' FROM digitrove_runtime');
        }

        DB::statement('GRANT EXECUTE ON FUNCTION '.self::LIST_DUE_SIGNATURE.' TO digitrove_runtime');
        DB::statement('GRANT EXECUTE ON FUNCTION '.self::PROCESS_SIGNATURE.' TO digitrove_runtime');
    }

    private function createTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER crm_order_attributions_rollup_refresh_trigger
            AFTER INSERT ON public.crm_order_attributions
            FOR EACH ROW
            EXECUTE FUNCTION public.enqueue_crm_commerce_rollup_refresh_from_attribution();

            CREATE TRIGGER refunds_rollup_refresh_trigger
            AFTER INSERT OR UPDATE OF status ON public.refunds
            FOR EACH ROW
            EXECUTE FUNCTION public.enqueue_crm_commerce_rollup_refresh_from_refund();
            SQL);
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
            throw new RuntimeException('P6-A1.2 migration: digitrove_crm_executor must be a restricted NOLOGIN NOINHERIT role.');
        }
    }
};
