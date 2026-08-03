<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LIST_DUE_SIGNATURE = 'public.list_due_crm_order_attributions(integer)';

    private const PROCESS_SIGNATURE = 'public.process_crm_order_attribution(bigint)';

    public function up(): void
    {
        $this->assertExecutorProvisioned();

        Schema::create('crm_order_attribution_outbox', function (Blueprint $table): void {
            $table->foreignId('order_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignId('contact_id_snapshot')->nullable()->constrained('crm_contacts')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('reason', 32)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE public.crm_order_attribution_outbox ADD CONSTRAINT crm_order_attribution_outbox_status_check CHECK (status IN ('pending', 'attributed', 'unattributable'))");
        DB::statement("ALTER TABLE public.crm_order_attribution_outbox ADD CONSTRAINT crm_order_attribution_outbox_reason_check CHECK (reason IS NULL OR reason IN ('invalid_email_contract', 'attribution_conflict'))");
        DB::statement(<<<'SQL'
            ALTER TABLE public.crm_order_attribution_outbox
            ADD CONSTRAINT crm_order_attribution_outbox_state_check
            CHECK ((
                CASE
                    WHEN status = 'pending' THEN reason IS NULL
                    WHEN status = 'attributed' THEN reason IS NULL
                    WHEN status = 'unattributable' THEN reason IS NOT NULL
                    ELSE FALSE
                END
            ) IS TRUE)
            SQL);
        DB::statement('ALTER TABLE public.crm_order_attribution_outbox ADD CONSTRAINT crm_order_attribution_outbox_attempt_count_check CHECK (attempt_count >= 0)');

        Schema::create('crm_order_attributions', function (Blueprint $table): void {
            $table->foreignId('order_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained('crm_contacts')->restrictOnDelete();
            $table->string('source', 32);
            $table->timestampTz('attributed_at')->useCurrent();

            $table->index(['contact_id', 'order_id'], 'crm_order_attributions_contact_order_index');
        });

        DB::statement("ALTER TABLE public.crm_order_attributions ADD CONSTRAINT crm_order_attributions_source_check CHECK (source IN ('existing_contact_snapshot', 'verified_account_resolution', 'guest_order_resolution'))");

        $this->grantExecutorPrivileges();
        $this->createFunctions();
        $this->createTriggers();
        $this->lockDownPrivileges();
    }

    public function down(): void
    {
        DB::statement('REVOKE EXECUTE ON FUNCTION '.self::LIST_DUE_SIGNATURE.' FROM digitrove_runtime');
        DB::statement('REVOKE EXECUTE ON FUNCTION '.self::PROCESS_SIGNATURE.' FROM digitrove_runtime');

        DB::statement('DROP TRIGGER IF EXISTS orders_enqueue_crm_attribution_trigger ON public.orders');
        DB::statement('DROP TRIGGER IF EXISTS crm_order_attributions_immutable_trigger ON public.crm_order_attributions');
        DB::statement('DROP TRIGGER IF EXISTS crm_order_attribution_outbox_integrity_trigger ON public.crm_order_attribution_outbox');

        DB::statement('SET ROLE digitrove_crm_executor');

        try {
            DB::statement('DROP FUNCTION IF EXISTS '.self::PROCESS_SIGNATURE);
            DB::statement('DROP FUNCTION IF EXISTS '.self::LIST_DUE_SIGNATURE);
            DB::statement('DROP FUNCTION IF EXISTS public.enqueue_crm_order_attribution()');
            DB::statement('DROP FUNCTION IF EXISTS public.prevent_crm_order_attribution_mutation()');
            DB::statement('DROP FUNCTION IF EXISTS public.enforce_crm_order_attribution_outbox_integrity()');
        } finally {
            DB::statement('RESET ROLE');
        }

        DB::statement('REVOKE ALL PRIVILEGES ON TABLE public.crm_order_attributions, public.crm_order_attribution_outbox FROM digitrove_crm_executor');
        DB::statement('REVOKE UPDATE (id) ON TABLE public.orders FROM digitrove_crm_executor');

        Schema::dropIfExists('crm_order_attributions');
        Schema::dropIfExists('crm_order_attribution_outbox');
    }

    private function grantExecutorPrivileges(): void
    {
        DB::statement('REVOKE TEMPORARY ON DATABASE '.$this->quoteIdentifier($this->currentDatabase()).' FROM digitrove_crm_executor');
        DB::statement('REVOKE CREATE ON SCHEMA public FROM digitrove_crm_executor');
        DB::statement('GRANT USAGE ON SCHEMA public TO digitrove_crm_executor');
        DB::statement('GRANT SELECT ON TABLE public.users, public.orders, public.crm_contacts TO digitrove_crm_executor');
        // PostgreSQL row locks require UPDATE privilege. Limit it to the immutable
        // primary key: the authority can lock an Order but exposes no mutation.
        DB::statement('GRANT UPDATE (id) ON TABLE public.orders TO digitrove_crm_executor');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON TABLE public.crm_order_attribution_outbox TO digitrove_crm_executor');
        DB::statement('GRANT SELECT, INSERT ON TABLE public.crm_order_attributions TO digitrove_crm_executor');
        DB::statement('GRANT CREATE ON SCHEMA public TO digitrove_crm_executor');
    }

    private function createFunctions(): void
    {
        DB::statement('SET ROLE digitrove_crm_executor');

        try {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.enforce_crm_order_attribution_outbox_integrity()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'CRM order attribution outbox rows cannot be deleted';
                    END IF;

                    IF ROW(
                        NEW.order_id,
                        NEW.contact_id_snapshot,
                        NEW.available_at,
                        NEW.created_at
                    ) IS DISTINCT FROM ROW(
                        OLD.order_id,
                        OLD.contact_id_snapshot,
                        OLD.available_at,
                        OLD.created_at
                    ) THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'CRM order attribution outbox evidence is immutable';
                    END IF;

                    IF OLD.status <> 'pending'
                        OR NEW.status NOT IN ('attributed', 'unattributable')
                        OR NEW.attempt_count <> OLD.attempt_count + 1
                        OR NEW.updated_at < OLD.updated_at
                    THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'CRM order attribution outbox transition is invalid';
                    END IF;

                    RETURN NEW;
                END;
                $$;

                CREATE OR REPLACE FUNCTION public.prevent_crm_order_attribution_mutation()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $$
                BEGIN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'CRM order attributions are immutable';
                END;
                $$;

                CREATE OR REPLACE FUNCTION public.enqueue_crm_order_attribution()
                RETURNS trigger
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = pg_catalog, public, pg_temp
                AS $$
                DECLARE
                    v_contact_id BIGINT;
                BEGIN
                    IF NEW.status NOT IN ('paid', 'partially_refunded', 'refunded')
                        OR NEW.paid_at IS NULL
                    THEN
                        RETURN NEW;
                    END IF;

                    IF TG_OP = 'UPDATE'
                        AND OLD.status IN ('paid', 'partially_refunded', 'refunded')
                    THEN
                        RETURN NEW;
                    END IF;

                    SELECT c.id
                    INTO v_contact_id
                    FROM public.crm_contacts AS c
                    WHERE c.status = 'active'
                      AND c.email = btrim(NEW.customer_email::text)::public.citext
                    ORDER BY c.id
                    LIMIT 1
                    FOR SHARE;

                    INSERT INTO public.crm_order_attribution_outbox (
                        order_id,
                        contact_id_snapshot,
                        status,
                        reason,
                        attempt_count,
                        available_at,
                        created_at,
                        updated_at
                    ) VALUES (
                        NEW.id,
                        v_contact_id,
                        'pending',
                        NULL,
                        0,
                        clock_timestamp(),
                        clock_timestamp(),
                        clock_timestamp()
                    )
                    ON CONFLICT (order_id) DO NOTHING;

                    RETURN NEW;
                END;
                $$;

                CREATE OR REPLACE FUNCTION public.list_due_crm_order_attributions(p_limit INTEGER)
                RETURNS TABLE(order_id BIGINT)
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = pg_catalog, public, pg_temp
                AS $$
                BEGIN
                    IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'CRM order attribution batch size is invalid';
                    END IF;

                    RETURN QUERY
                    SELECT o.order_id
                    FROM public.crm_order_attribution_outbox AS o
                    WHERE o.status = 'pending'
                      AND o.available_at <= clock_timestamp()
                    ORDER BY o.available_at, o.order_id
                    LIMIT p_limit;
                END;
                $$;

                CREATE OR REPLACE FUNCTION public.process_crm_order_attribution(p_order_id BIGINT)
                RETURNS TABLE(
                    order_id BIGINT,
                    status VARCHAR,
                    reason VARCHAR,
                    contact_public_id UUID,
                    source VARCHAR
                )
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = pg_catalog, public, pg_temp
                AS $$
                DECLARE
                    v_outbox public.crm_order_attribution_outbox%ROWTYPE;
                    v_order public.orders%ROWTYPE;
                    v_existing public.crm_order_attributions%ROWTYPE;
                    v_email public.citext;
                    v_target_contact_id BIGINT;
                    v_contact_public_id UUID;
                    v_source VARCHAR(32);
                    v_verified_account BOOLEAN;
                    v_resolved RECORD;
                BEGIN
                    IF p_order_id IS NULL OR p_order_id < 1 THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'CRM order attribution identifier is invalid';
                    END IF;

                    SELECT o.*
                    INTO v_outbox
                    FROM public.crm_order_attribution_outbox AS o
                    WHERE o.order_id = p_order_id
                    FOR UPDATE;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'CRM order attribution outbox row is unavailable';
                    END IF;

                    IF v_outbox.status = 'unattributable' THEN
                        RETURN QUERY SELECT p_order_id, v_outbox.status, v_outbox.reason,
                            NULL::UUID, NULL::VARCHAR;
                        RETURN;
                    END IF;

                    IF v_outbox.status = 'attributed' THEN
                        RETURN QUERY
                        SELECT a.order_id, v_outbox.status, NULL::VARCHAR, c.public_id, a.source
                        FROM public.crm_order_attributions AS a
                        JOIN public.crm_contacts AS c ON c.id = a.contact_id
                        WHERE a.order_id = p_order_id;
                        RETURN;
                    END IF;

                    SELECT o.*
                    INTO v_order
                    FROM public.orders AS o
                    WHERE o.id = p_order_id
                    FOR UPDATE;

                    IF NOT FOUND
                        OR v_order.status NOT IN ('paid', 'partially_refunded', 'refunded')
                        OR v_order.paid_at IS NULL
                    THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'CRM order attribution order is not acquired';
                    END IF;

                    IF v_outbox.contact_id_snapshot IS NOT NULL THEN
                        v_target_contact_id := v_outbox.contact_id_snapshot;
                        v_source := 'existing_contact_snapshot';

                        SELECT c.public_id
                        INTO v_contact_public_id
                        FROM public.crm_contacts AS c
                        WHERE c.id = v_target_contact_id;

                        IF NOT FOUND THEN
                            RAISE EXCEPTION USING
                                ERRCODE = '23514',
                                MESSAGE = 'CRM order attribution contact snapshot is unavailable';
                        END IF;
                    ELSE
                        v_email := lower(btrim(v_order.customer_email::text))::public.citext;

                        IF char_length(v_email::text) NOT BETWEEN 3 AND 254
                            OR v_email::text !~ '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$'
                        THEN
                            UPDATE public.crm_order_attribution_outbox
                            SET status = 'unattributable',
                                reason = 'invalid_email_contract',
                                attempt_count = attempt_count + 1,
                                updated_at = clock_timestamp()
                            WHERE crm_order_attribution_outbox.order_id = p_order_id;

                            RETURN QUERY SELECT p_order_id, 'unattributable'::VARCHAR,
                                'invalid_email_contract'::VARCHAR, NULL::UUID, NULL::VARCHAR;
                            RETURN;
                        END IF;

                        SELECT EXISTS (
                            SELECT 1
                            FROM public.users AS u
                            WHERE u.id = v_order.user_id
                              AND u.deleted_at IS NULL
                              AND u.status = 'active'
                              AND u.email_verified_at IS NOT NULL
                              AND u.email = v_email
                        )
                        INTO v_verified_account;

                        IF v_verified_account THEN
                            SELECT r.contact_id, r.public_id
                            INTO v_resolved
                            FROM public.resolve_crm_contact(
                                v_email::VARCHAR,
                                'verified_account'::VARCHAR,
                                v_order.user_id,
                                NULL::BIGINT
                            ) AS r;
                            v_source := 'verified_account_resolution';
                        ELSE
                            SELECT r.contact_id, r.public_id
                            INTO v_resolved
                            FROM public.resolve_crm_contact(
                                v_email::VARCHAR,
                                'guest_order'::VARCHAR,
                                NULL::BIGINT,
                                v_order.id
                            ) AS r;
                            v_source := 'guest_order_resolution';
                        END IF;

                        v_target_contact_id := v_resolved.contact_id;
                        v_contact_public_id := v_resolved.public_id;
                    END IF;

                    SELECT a.*
                    INTO v_existing
                    FROM public.crm_order_attributions AS a
                    WHERE a.order_id = p_order_id;

                    IF FOUND THEN
                        IF v_existing.contact_id = v_target_contact_id THEN
                            UPDATE public.crm_order_attribution_outbox
                            SET status = 'attributed',
                                reason = NULL,
                                attempt_count = attempt_count + 1,
                                updated_at = clock_timestamp()
                            WHERE crm_order_attribution_outbox.order_id = p_order_id;

                            SELECT c.public_id INTO v_contact_public_id
                            FROM public.crm_contacts AS c
                            WHERE c.id = v_existing.contact_id;

                            RETURN QUERY SELECT p_order_id, 'attributed'::VARCHAR, NULL::VARCHAR,
                                v_contact_public_id, v_existing.source;
                        ELSE
                            UPDATE public.crm_order_attribution_outbox
                            SET status = 'unattributable',
                                reason = 'attribution_conflict',
                                attempt_count = attempt_count + 1,
                                updated_at = clock_timestamp()
                            WHERE crm_order_attribution_outbox.order_id = p_order_id;

                            RETURN QUERY SELECT p_order_id, 'unattributable'::VARCHAR,
                                'attribution_conflict'::VARCHAR, NULL::UUID, NULL::VARCHAR;
                        END IF;

                        RETURN;
                    END IF;

                    INSERT INTO public.crm_order_attributions (order_id, contact_id, source, attributed_at)
                    VALUES (p_order_id, v_target_contact_id, v_source, clock_timestamp());

                    UPDATE public.crm_order_attribution_outbox
                    SET status = 'attributed',
                        reason = NULL,
                        attempt_count = attempt_count + 1,
                        updated_at = clock_timestamp()
                    WHERE crm_order_attribution_outbox.order_id = p_order_id;

                    RETURN QUERY SELECT p_order_id, 'attributed'::VARCHAR, NULL::VARCHAR,
                        v_contact_public_id, v_source;
                END;
                $$;
                SQL);

            DB::statement('REVOKE EXECUTE ON FUNCTION public.enforce_crm_order_attribution_outbox_integrity() FROM PUBLIC');
            DB::statement('REVOKE EXECUTE ON FUNCTION public.prevent_crm_order_attribution_mutation() FROM PUBLIC');
            DB::statement('REVOKE EXECUTE ON FUNCTION public.enqueue_crm_order_attribution() FROM PUBLIC');
            DB::statement('REVOKE EXECUTE ON FUNCTION '.self::LIST_DUE_SIGNATURE.' FROM PUBLIC');
            DB::statement('REVOKE EXECUTE ON FUNCTION '.self::PROCESS_SIGNATURE.' FROM PUBLIC');
            DB::statement('GRANT EXECUTE ON FUNCTION '.self::LIST_DUE_SIGNATURE.' TO digitrove_runtime');
            DB::statement('GRANT EXECUTE ON FUNCTION '.self::PROCESS_SIGNATURE.' TO digitrove_runtime');
        } finally {
            DB::statement('RESET ROLE');
            DB::statement('REVOKE CREATE ON SCHEMA public FROM digitrove_crm_executor');
        }
    }

    private function createTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER crm_order_attribution_outbox_integrity_trigger
            BEFORE UPDATE OR DELETE ON public.crm_order_attribution_outbox
            FOR EACH ROW
            EXECUTE FUNCTION public.enforce_crm_order_attribution_outbox_integrity();

            CREATE TRIGGER crm_order_attributions_immutable_trigger
            BEFORE UPDATE OR DELETE ON public.crm_order_attributions
            FOR EACH ROW
            EXECUTE FUNCTION public.prevent_crm_order_attribution_mutation();

            CREATE TRIGGER orders_enqueue_crm_attribution_trigger
            AFTER INSERT OR UPDATE OF status, paid_at ON public.orders
            FOR EACH ROW
            EXECUTE FUNCTION public.enqueue_crm_order_attribution();
            SQL);
    }

    private function lockDownPrivileges(): void
    {
        DB::statement('REVOKE ALL PRIVILEGES ON TABLE public.crm_order_attribution_outbox, public.crm_order_attributions FROM PUBLIC, digitrove_runtime');
        DB::statement('REVOKE EXECUTE ON FUNCTION public.enforce_crm_order_attribution_outbox_integrity() FROM digitrove_runtime');
        DB::statement('REVOKE EXECUTE ON FUNCTION public.prevent_crm_order_attribution_mutation() FROM digitrove_runtime');
        DB::statement('REVOKE EXECUTE ON FUNCTION public.enqueue_crm_order_attribution() FROM digitrove_runtime');
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
            throw new RuntimeException('P6-A1.0 migration: digitrove_crm_executor must be a restricted NOLOGIN NOINHERIT role.');
        }

        $setPath = DB::selectOne(<<<'SQL'
            SELECT m.set_option
            FROM pg_auth_members m
            JOIN pg_roles member ON member.oid = m.member
            JOIN pg_roles granted ON granted.oid = m.roleid
            WHERE member.rolname = 'digitrove'
              AND granted.rolname = 'digitrove_crm_executor'
            LIMIT 1
            SQL);

        if ($setPath === null || ! $setPath->set_option) {
            throw new RuntimeException('P6-A1.0 migration: digitrove must hold SET-only membership to digitrove_crm_executor.');
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
