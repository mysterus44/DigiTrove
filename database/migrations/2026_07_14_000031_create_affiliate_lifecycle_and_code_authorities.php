<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6-D1.1 — Affiliate lifecycle and code governance (D-059).
 *
 * THE DECISION THIS MIGRATION ENCODES: a snapshot cannot carry history.
 *
 * `affiliates` holds ONE row per user with the CURRENT state, and its `*_at` columns have
 * exactly one slot each — after active → suspended → active → suspended only one
 * suspension date survives. So every transition is recorded in an append-only ledger,
 * `affiliate_lifecycle_events`, and the snapshot answers only "what is the state now?".
 *
 * Two invariants that `000029` left open are closed here: at most ONE active code per
 * affiliate, and `is_active` / `deactivated_at` agreeing in both directions.
 *
 * Both directions are guarded. `up()` refuses BEFORE mutating if existing data already
 * violates the new invariants — no silent repair. `down()` refuses BEFORE mutating if the
 * ledger holds history, because P6-D1 cannot represent it and dropping the table would
 * destroy the only record of how each affiliate reached its state.
 */
return new class extends Migration
{
    private const SUBMIT = 'public.submit_affiliate_application(bigint)';

    private const REVIEW = 'public.review_affiliate_application(bigint, character varying, bigint, character varying)';

    private const SUSPEND = 'public.suspend_affiliate(bigint, bigint, character varying)';

    private const REACTIVATE = 'public.reactivate_affiliate(bigint, bigint)';

    private const CLOSE = 'public.close_affiliate(bigint, bigint, character varying)';

    private const ROTATE = 'public.rotate_affiliate_code(bigint, bigint, bigint)';

    private const LIST_AFFILIATES = 'public.list_affiliates(character varying, integer, bigint)';

    private const GET_AFFILIATE = 'public.get_affiliate(bigint)';

    private const LIST_EVENTS = 'public.list_affiliate_lifecycle_events(bigint, integer, bigint)';

    private const LIST_CODES = 'public.list_affiliate_codes(bigint, integer)';

    /**
     * Authorities the RUNTIME may execute. The code generator is deliberately absent: it
     * is an internal effect of approve, reactivate and rotate, never a callable that could
     * mint a code for a pending or closed affiliate (D-059).
     *
     * @return list<string>
     */
    private function runtimeAuthorities(): array
    {
        return [
            self::SUBMIT, self::REVIEW, self::SUSPEND, self::REACTIVATE, self::CLOSE,
            self::ROTATE, self::LIST_AFFILIATES, self::GET_AFFILIATE, self::LIST_EVENTS,
            self::LIST_CODES,
        ];
    }

    /** Internal helpers: owned by the executor, never executable by the runtime. */
    private function internalFunctions(): array
    {
        return [
            'public.generate_affiliate_code()',
            'public.assert_affiliate_actor_is_admin(bigint)',
            'public.enforce_affiliate_lifecycle_append_only()',
        ];
    }

    /** Minimal columns needed to decide eligibility. Nothing else, and no DML. */
    private function userColumns(): string
    {
        return 'id, role, status, deleted_at';
    }

    public function up(): void
    {
        $this->assertExecutorProvisioned();
        $this->assertCodeDataFitsNewInvariants();

        $this->createLifecycleLedger();
        $this->hardenCodeInvariants();
        $this->grantUserEligibilityRead();
        $this->createAuthorities();
        $this->lockDownPrivileges();
    }

    public function down(): void
    {
        // FIRST, before a single object is touched.
        $this->assertLosslessDowngrade();

        foreach ($this->runtimeAuthorities() as $signature) {
            DB::statement('REVOKE EXECUTE ON FUNCTION '.$signature.' FROM digitrove_runtime');
            DB::statement('DROP FUNCTION IF EXISTS '.$signature);
        }

        DB::statement('DROP TRIGGER IF EXISTS affiliate_lifecycle_events_append_only_trigger ON public.affiliate_lifecycle_events');
        Schema::dropIfExists('affiliate_lifecycle_events');

        foreach ($this->internalFunctions() as $signature) {
            DB::statement('DROP FUNCTION IF EXISTS '.$signature);
        }

        // Restore the exact `000030` frontier.
        DB::statement('REVOKE SELECT ('.$this->userColumns().') ON public.users FROM digitrove_affiliate_executor');
        DB::statement('DROP INDEX IF EXISTS public.affiliate_codes_single_active');
        DB::statement('ALTER TABLE public.affiliate_codes DROP CONSTRAINT IF EXISTS affiliate_codes_activation_coherence_check');
    }

    // ── Guards ───────────────────────────────────────────────────────────────────

    /**
     * `000029` allowed several active codes per affiliate, and allowed an active code to
     * carry a `deactivated_at`. Real rows in either shape would make the new invariants
     * unsatisfiable, so the migration refuses rather than choosing a row to sacrifice.
     */
    private function assertCodeDataFitsNewInvariants(): void
    {
        $multiple = DB::selectOne(<<<'SQL'
            SELECT count(*) AS offenders FROM (
                SELECT c.affiliate_id FROM public.affiliate_codes AS c
                WHERE c.is_active GROUP BY c.affiliate_id HAVING count(*) > 1
            ) AS d
            SQL);

        if ($multiple !== null && (int) $multiple->offenders > 0) {
            throw new RuntimeException(
                'P6-D1.1 migration: '.(int) $multiple->offenders.' affiliate(s) already hold more than one '
                .'active code. Deciding which one survives is a business call, not a migration; no change was made.',
            );
        }

        $incoherent = DB::selectOne(<<<'SQL'
            SELECT count(*) AS offenders FROM public.affiliate_codes AS c
            WHERE (c.is_active AND c.deactivated_at IS NOT NULL)
               OR (NOT c.is_active AND c.deactivated_at IS NULL)
            SQL);

        if ($incoherent !== null && (int) $incoherent->offenders > 0) {
            throw new RuntimeException(
                'P6-D1.1 migration: '.(int) $incoherent->offenders.' affiliate code(s) disagree with their '
                .'own deactivation timestamp. Rewriting them would falsify history; no change was made.',
            );
        }

        $strayActive = DB::selectOne(<<<'SQL'
            SELECT count(*) AS offenders
            FROM public.affiliate_codes AS c
            JOIN public.affiliates AS a ON a.id = c.affiliate_id
            WHERE c.is_active AND a.status <> 'active'
            SQL);

        if ($strayActive !== null && (int) $strayActive->offenders > 0) {
            throw new RuntimeException(
                'P6-D1.1 migration: '.(int) $strayActive->offenders.' active code(s) belong to an affiliate that '
                .'is not active. Deactivating them silently would rewrite history; no change was made.',
            );
        }
    }

    /**
     * The ledger is the ONLY record of how an affiliate reached its state — the snapshot
     * physically cannot hold it. Dropping a populated ledger is therefore destructive, and
     * the downgrade refuses rather than pretending P6-D1 can represent this history.
     */
    private function assertLosslessDowngrade(): void
    {
        if (! Schema::hasTable('affiliate_lifecycle_events')) {
            return;
        }

        $events = DB::selectOne('SELECT count(*) AS c FROM public.affiliate_lifecycle_events');

        if ($events !== null && (int) $events->c > 0) {
            throw new RuntimeException(
                'Cannot roll back P6-D1.1: the affiliate lifecycle ledger holds '.(int) $events->c
                .' transition(s) that P6-D1 cannot represent. Rolling back would destroy the only record of '
                .'how each affiliate reached its state, so no change was made.',
            );
        }
    }

    private function assertExecutorProvisioned(): void
    {
        $executor = DB::selectOne(<<<'SQL'
            SELECT rolsuper, rolcanlogin, rolinherit
            FROM pg_roles WHERE rolname = 'digitrove_affiliate_executor'
            SQL);

        if ($executor === null || $executor->rolsuper || $executor->rolcanlogin || $executor->rolinherit) {
            throw new RuntimeException('P6-D1.1 migration: digitrove_affiliate_executor must be a restricted NOLOGIN NOINHERIT role.');
        }
    }

    // ── The ledger ───────────────────────────────────────────────────────────────

    private function createLifecycleLedger(): void
    {
        Schema::create('affiliate_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            // RESTRICT: a lifecycle history must never vanish because its affiliate did.
            $table->foreignId('affiliate_id')->constrained('affiliates')->restrictOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->string('event_kind', 32);
            // SET NULL: erasing an administrator must not erase the transition they made.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Structured, allowlisted at the application boundary. No free text: an audit
            // trail is where PII leaks in, and D-059 requires none.
            $table->string('reason_code', 32)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['affiliate_id', 'id'], 'affiliate_lifecycle_events_affiliate_index');
        });

        DB::statement("ALTER TABLE public.affiliate_lifecycle_events ADD CONSTRAINT affiliate_lifecycle_events_kind_check CHECK (event_kind IN ('application_submitted', 'application_reapplied', 'application_approved', 'application_rejected', 'affiliate_suspended', 'affiliate_reactivated', 'affiliate_closed'))");
        DB::statement("ALTER TABLE public.affiliate_lifecycle_events ADD CONSTRAINT affiliate_lifecycle_events_from_status_check CHECK (from_status IS NULL OR from_status IN ('pending', 'active', 'suspended', 'rejected', 'closed'))");
        DB::statement("ALTER TABLE public.affiliate_lifecycle_events ADD CONSTRAINT affiliate_lifecycle_events_to_status_check CHECK (to_status IN ('pending', 'active', 'suspended', 'rejected', 'closed'))");

        // The ledger cannot claim a transition the state machine forbids. Without this, an
        // event could record `application_approved: rejected → closed` and the audit trail
        // would be fiction.
        DB::statement(<<<'SQL'
            ALTER TABLE public.affiliate_lifecycle_events
            ADD CONSTRAINT affiliate_lifecycle_events_transition_check
            CHECK (
                (event_kind = 'application_submitted'  AND from_status IS NULL     AND to_status = 'pending')
             OR (event_kind = 'application_reapplied'  AND from_status = 'rejected' AND to_status = 'pending')
             OR (event_kind = 'application_approved'   AND from_status = 'pending'  AND to_status = 'active')
             OR (event_kind = 'application_rejected'   AND from_status = 'pending'  AND to_status = 'rejected')
             OR (event_kind = 'affiliate_suspended'    AND from_status = 'active'   AND to_status = 'suspended')
             OR (event_kind = 'affiliate_reactivated'  AND from_status = 'suspended' AND to_status = 'active')
             OR (event_kind = 'affiliate_closed'       AND from_status IN ('active', 'suspended') AND to_status = 'closed')
            )
            SQL);

        // Append-only at the storage layer, mirroring `affiliate_commission_entries`.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enforce_affiliate_lifecycle_append_only()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                RAISE EXCEPTION USING ERRCODE = '23514',
                    MESSAGE = 'affiliate lifecycle events are append-only';
            END;
            $$;

            CREATE TRIGGER affiliate_lifecycle_events_append_only_trigger
            BEFORE UPDATE OR DELETE ON public.affiliate_lifecycle_events
            FOR EACH ROW EXECUTE FUNCTION public.enforce_affiliate_lifecycle_append_only();
            SQL);
    }

    // ── Code invariants `000029` left open ───────────────────────────────────────

    private function hardenCodeInvariants(): void
    {
        // At most ONE active code per affiliate. Without this, D2 would have to arbitrate
        // "which code won", and the question belongs to the schema, not to a resolver.
        DB::statement('CREATE UNIQUE INDEX affiliate_codes_single_active ON public.affiliate_codes (affiliate_id) WHERE is_active');

        // `000029` only guaranteed one direction. The reverse matters just as much: an
        // active code carrying a deactivation date is a lie about its own history.
        DB::statement('ALTER TABLE public.affiliate_codes ADD CONSTRAINT affiliate_codes_activation_coherence_check CHECK (is_active = (deactivated_at IS NULL))');
    }

    /**
     * COLUMN-LEVEL grant, not a table grant. Eligibility needs the role, the status and the
     * soft-delete marker — nothing else — and the executor must never be able to read a
     * password hash, an e-mail or a remember token.
     */
    private function grantUserEligibilityRead(): void
    {
        DB::statement('GRANT SELECT ('.$this->userColumns().') ON public.users TO digitrove_affiliate_executor');
    }

    // ── Authorities ──────────────────────────────────────────────────────────────

    private function createAuthorities(): void
    {
        // ── Internal helpers ─────────────────────────────────────────────────────
        //
        // The generator uses `gen_random_uuid()`, which is BUILT IN since PostgreSQL 13 and
        // backed by `pg_strong_random()`. pgcrypto is not installed, and installing an
        // extension for this would put a shared cluster object behind a gate rollback —
        // the same objection that kept `btree_gist` out of P6-D1.
        //
        // 12 uppercase hex characters: inside the `^[A-Z0-9]{4,32}$` format `000029`
        // already enforces, 16^12 ≈ 2.8e14 values, and no personal data is derivable from
        // it — the code carries no name, e-mail or user id.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.generate_affiliate_code()
            RETURNS CHARACTER VARYING
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_candidate CHARACTER VARYING;
                v_attempt INTEGER := 0;
            BEGIN
                LOOP
                    v_attempt := v_attempt + 1;

                    v_candidate := substring(upper(replace(gen_random_uuid()::text, '-', '')) FROM 1 FOR 12);

                    IF NOT EXISTS (SELECT 1 FROM public.affiliate_codes AS c WHERE c.code = v_candidate) THEN
                        RETURN v_candidate;
                    END IF;

                    -- Bounded retry: a persistent collision means something is wrong, and
                    -- looping for ever would hold the affiliate lock indefinitely.
                    IF v_attempt >= 8 THEN
                        RAISE EXCEPTION USING ERRCODE = '53400',
                            MESSAGE = 'could not generate a unique affiliate code';
                    END IF;
                END LOOP;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.assert_affiliate_actor_is_admin(p_actor_user_id BIGINT)
            RETURNS VOID
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_ok BOOLEAN;
            BEGIN
                -- An actor id arriving from the application is never trusted on its face.
                SELECT (u.role = 'admin' AND u.status = 'active' AND u.deleted_at IS NULL)
                INTO v_ok
                FROM public.users AS u
                WHERE u.id = p_actor_user_id;

                IF v_ok IS NOT TRUE THEN
                    RAISE EXCEPTION USING ERRCODE = '42501',
                        MESSAGE = 'the acting user is not an active administrator';
                END IF;
            END;
            $$;
            SQL);

        // ── 1. Submit / reapply ──────────────────────────────────────────────────
        //
        // ONE authority for both, because they are the same business act seen from two
        // starting points, and splitting them would let a caller race the two paths.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.submit_affiliate_application(p_user_id BIGINT)
            RETURNS TABLE(affiliate_id BIGINT, affiliate_status CHARACTER VARYING, event_kind CHARACTER VARYING)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_existing public.affiliates%ROWTYPE;
                v_eligible BOOLEAN;
                v_id BIGINT;
                v_at TIMESTAMPTZ;
                v_kind CHARACTER VARYING;
            BEGIN
                IF p_user_id IS NULL OR p_user_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid user identifier';
                END IF;

                -- Eligibility is read HERE, inside the transaction: a customer who was
                -- eligible yesterday may be blocked today.
                SELECT (u.role = 'customer' AND u.status = 'active' AND u.deleted_at IS NULL)
                INTO v_eligible
                FROM public.users AS u
                WHERE u.id = p_user_id;

                IF v_eligible IS NOT TRUE THEN
                    RAISE EXCEPTION USING ERRCODE = '42501',
                        MESSAGE = 'this account is not eligible to become an affiliate';
                END IF;

                v_at := now();

                SELECT a.* INTO v_existing FROM public.affiliates AS a
                WHERE a.user_id = p_user_id FOR UPDATE;

                IF v_existing.id IS NULL THEN
                    INSERT INTO public.affiliates (public_id, user_id, status, applied_at, created_at, updated_at)
                    VALUES (gen_random_uuid(), p_user_id, 'pending', v_at, v_at, v_at)
                    RETURNING id INTO v_id;

                    v_kind := 'application_submitted';

                    INSERT INTO public.affiliate_lifecycle_events
                        (affiliate_id, from_status, to_status, event_kind, occurred_at, created_at)
                    VALUES (v_id, NULL, 'pending', v_kind, v_at, v_at);
                ELSE
                    IF v_existing.status <> 'rejected' THEN
                        RAISE EXCEPTION USING ERRCODE = '23514',
                            MESSAGE = 'only a rejected application can be resubmitted';
                    END IF;

                    v_id := v_existing.id;
                    v_kind := 'application_reapplied';

                    -- `rejected_at` is deliberately preserved: it is the LAST refusal, and
                    -- erasing it would falsify the record. `reviewed_by_user_id` is cleared
                    -- because the new application has not been reviewed by anyone yet.
                    UPDATE public.affiliates AS a
                    SET status = 'pending',
                        applied_at = v_at,
                        reviewed_by_user_id = NULL,
                        updated_at = v_at
                    WHERE a.id = v_id;

                    INSERT INTO public.affiliate_lifecycle_events
                        (affiliate_id, from_status, to_status, event_kind, occurred_at, created_at)
                    VALUES (v_id, 'rejected', 'pending', v_kind, v_at, v_at);
                END IF;

                RETURN QUERY SELECT v_id, 'pending'::character varying, v_kind;
            END;
            $$;
            SQL);

        // ── 2. Review (approve | reject) ─────────────────────────────────────────
        //
        // ONE authority: reviewing is a single decision on a pending file. Two functions
        // would duplicate the transition and create a genuine approve/reject race.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.review_affiliate_application(
                p_affiliate_id BIGINT,
                p_action CHARACTER VARYING,
                p_actor_user_id BIGINT,
                p_reason_code CHARACTER VARYING
            )
            RETURNS TABLE(affiliate_id BIGINT, affiliate_status CHARACTER VARYING, issued_code CHARACTER VARYING)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_affiliate public.affiliates%ROWTYPE;
                v_eligible BOOLEAN;
                v_at TIMESTAMPTZ;
                v_code CHARACTER VARYING;
            BEGIN
                IF p_action IS NULL OR p_action NOT IN ('approve', 'reject') THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid review action';
                END IF;

                PERFORM public.assert_affiliate_actor_is_admin(p_actor_user_id);

                SELECT a.* INTO v_affiliate FROM public.affiliates AS a
                WHERE a.id = p_affiliate_id FOR UPDATE;

                IF v_affiliate.id IS NULL THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'unknown affiliate';
                END IF;

                IF v_affiliate.status <> 'pending' THEN
                    RAISE EXCEPTION USING ERRCODE = '23514',
                        MESSAGE = 'only a pending application can be reviewed';
                END IF;

                v_at := now();

                IF p_action = 'reject' THEN
                    UPDATE public.affiliates AS a
                    SET status = 'rejected', rejected_at = v_at,
                        reviewed_by_user_id = p_actor_user_id, updated_at = v_at
                    WHERE a.id = p_affiliate_id;

                    INSERT INTO public.affiliate_lifecycle_events
                        (affiliate_id, from_status, to_status, event_kind, actor_user_id, reason_code, occurred_at, created_at)
                    VALUES (p_affiliate_id, 'pending', 'rejected', 'application_rejected', p_actor_user_id, p_reason_code, v_at, v_at);

                    RETURN QUERY SELECT p_affiliate_id, 'rejected'::character varying, NULL::character varying;
                    RETURN;
                END IF;

                -- Approval re-reads eligibility: the account may have been blocked between
                -- application and review.
                SELECT (u.role = 'customer' AND u.status = 'active' AND u.deleted_at IS NULL)
                INTO v_eligible
                FROM public.users AS u WHERE u.id = v_affiliate.user_id;

                IF v_eligible IS NOT TRUE THEN
                    RAISE EXCEPTION USING ERRCODE = '42501',
                        MESSAGE = 'this account is no longer eligible to become an affiliate';
                END IF;

                v_code := public.generate_affiliate_code();

                UPDATE public.affiliates AS a
                SET status = 'active', approved_at = v_at,
                    reviewed_by_user_id = p_actor_user_id, updated_at = v_at
                WHERE a.id = p_affiliate_id;

                -- Same transaction: an affiliate is never active without a code.
                INSERT INTO public.affiliate_codes (affiliate_id, code, is_active, created_at, updated_at)
                VALUES (p_affiliate_id, v_code, true, v_at, v_at);

                INSERT INTO public.affiliate_lifecycle_events
                    (affiliate_id, from_status, to_status, event_kind, actor_user_id, reason_code, occurred_at, created_at)
                VALUES (p_affiliate_id, 'pending', 'active', 'application_approved', p_actor_user_id, p_reason_code, v_at, v_at);

                RETURN QUERY SELECT p_affiliate_id, 'active'::character varying, v_code;
            END;
            $$;
            SQL);

        // ── 3. Suspend ───────────────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.suspend_affiliate(
                p_affiliate_id BIGINT, p_actor_user_id BIGINT, p_reason_code CHARACTER VARYING
            )
            RETURNS TABLE(affiliate_id BIGINT, affiliate_status CHARACTER VARYING)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_affiliate public.affiliates%ROWTYPE;
                v_at TIMESTAMPTZ;
                v_deactivated INTEGER;
            BEGIN
                PERFORM public.assert_affiliate_actor_is_admin(p_actor_user_id);

                SELECT a.* INTO v_affiliate FROM public.affiliates AS a
                WHERE a.id = p_affiliate_id FOR UPDATE;

                IF v_affiliate.id IS NULL THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'unknown affiliate';
                END IF;

                IF v_affiliate.status <> 'active' THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'only an active affiliate can be suspended';
                END IF;

                v_at := now();

                UPDATE public.affiliate_codes AS c
                SET is_active = false, deactivated_at = v_at, updated_at = v_at
                WHERE c.affiliate_id = p_affiliate_id AND c.is_active;

                GET DIAGNOSTICS v_deactivated = ROW_COUNT;

                -- Fail closed: an active affiliate holds exactly one active code. Anything
                -- else means the invariant was already broken, and repairing it here would
                -- hide that.
                IF v_deactivated <> 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514',
                        MESSAGE = 'an active affiliate must hold exactly one active code';
                END IF;

                UPDATE public.affiliates AS a
                SET status = 'suspended', suspended_at = v_at, updated_at = v_at
                WHERE a.id = p_affiliate_id;

                INSERT INTO public.affiliate_lifecycle_events
                    (affiliate_id, from_status, to_status, event_kind, actor_user_id, reason_code, occurred_at, created_at)
                VALUES (p_affiliate_id, 'active', 'suspended', 'affiliate_suspended', p_actor_user_id, p_reason_code, v_at, v_at);

                RETURN QUERY SELECT p_affiliate_id, 'suspended'::character varying;
            END;
            $$;
            SQL);

        // ── 4. Reactivate ────────────────────────────────────────────────────────
        //
        // A NEW code, never the old one: reactivating a deactivated code would make its
        // own `deactivated_at` a lie. `approved_at` is NOT rewritten — it records the
        // original approval, not this reinstatement (D-059).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.reactivate_affiliate(p_affiliate_id BIGINT, p_actor_user_id BIGINT)
            RETURNS TABLE(affiliate_id BIGINT, affiliate_status CHARACTER VARYING, issued_code CHARACTER VARYING)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_affiliate public.affiliates%ROWTYPE;
                v_eligible BOOLEAN;
                v_at TIMESTAMPTZ;
                v_code CHARACTER VARYING;
            BEGIN
                PERFORM public.assert_affiliate_actor_is_admin(p_actor_user_id);

                SELECT a.* INTO v_affiliate FROM public.affiliates AS a
                WHERE a.id = p_affiliate_id FOR UPDATE;

                IF v_affiliate.id IS NULL THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'unknown affiliate';
                END IF;

                IF v_affiliate.status <> 'suspended' THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'only a suspended affiliate can be reactivated';
                END IF;

                SELECT (u.role = 'customer' AND u.status = 'active' AND u.deleted_at IS NULL)
                INTO v_eligible
                FROM public.users AS u WHERE u.id = v_affiliate.user_id;

                IF v_eligible IS NOT TRUE THEN
                    RAISE EXCEPTION USING ERRCODE = '42501',
                        MESSAGE = 'this account is no longer eligible to be an affiliate';
                END IF;

                IF EXISTS (SELECT 1 FROM public.affiliate_codes AS c WHERE c.affiliate_id = p_affiliate_id AND c.is_active) THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'a suspended affiliate must hold no active code';
                END IF;

                v_at := now();
                v_code := public.generate_affiliate_code();

                UPDATE public.affiliates AS a
                SET status = 'active', updated_at = v_at
                WHERE a.id = p_affiliate_id;

                INSERT INTO public.affiliate_codes (affiliate_id, code, is_active, created_at, updated_at)
                VALUES (p_affiliate_id, v_code, true, v_at, v_at);

                INSERT INTO public.affiliate_lifecycle_events
                    (affiliate_id, from_status, to_status, event_kind, actor_user_id, occurred_at, created_at)
                VALUES (p_affiliate_id, 'suspended', 'active', 'affiliate_reactivated', p_actor_user_id, v_at, v_at);

                RETURN QUERY SELECT p_affiliate_id, 'active'::character varying, v_code;
            END;
            $$;
            SQL);

        // ── 5. Close — terminal ──────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.close_affiliate(
                p_affiliate_id BIGINT, p_actor_user_id BIGINT, p_reason_code CHARACTER VARYING
            )
            RETURNS TABLE(affiliate_id BIGINT, affiliate_status CHARACTER VARYING)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_affiliate public.affiliates%ROWTYPE;
                v_at TIMESTAMPTZ;
            BEGIN
                PERFORM public.assert_affiliate_actor_is_admin(p_actor_user_id);

                SELECT a.* INTO v_affiliate FROM public.affiliates AS a
                WHERE a.id = p_affiliate_id FOR UPDATE;

                IF v_affiliate.id IS NULL THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'unknown affiliate';
                END IF;

                IF v_affiliate.status NOT IN ('active', 'suspended') THEN
                    RAISE EXCEPTION USING ERRCODE = '23514',
                        MESSAGE = 'only an active or suspended affiliate can be closed';
                END IF;

                v_at := now();

                -- Codes are deactivated, never deleted: they are referenced by touches and
                -- attributions later, and the FKs are RESTRICT anyway.
                UPDATE public.affiliate_codes AS c
                SET is_active = false, deactivated_at = v_at, updated_at = v_at
                WHERE c.affiliate_id = p_affiliate_id AND c.is_active;

                UPDATE public.affiliates AS a
                SET status = 'closed', closed_at = v_at, updated_at = v_at
                WHERE a.id = p_affiliate_id;

                INSERT INTO public.affiliate_lifecycle_events
                    (affiliate_id, from_status, to_status, event_kind, actor_user_id, reason_code, occurred_at, created_at)
                VALUES (p_affiliate_id, v_affiliate.status, 'closed', 'affiliate_closed', p_actor_user_id, p_reason_code, v_at, v_at);

                RETURN QUERY SELECT p_affiliate_id, 'closed'::character varying;
            END;
            $$;
            SQL);

        // ── 6. Rotate ────────────────────────────────────────────────────────────
        //
        // Rotation does NOT change the lifecycle state, so it writes no lifecycle event:
        // recording `active → active` would falsify a transition that never happened. The
        // `affiliate_codes` rows already ARE the rotation history — old code inactive with
        // its `deactivated_at`, new code active, both kept for ever.
        //
        // COMPARE-AND-SWAP. The caller passes the id of the code it BELIEVES is active, so
        // rotation means "replace this code, if it is still the active one". A row lock
        // alone would not be enough: it orders two concurrent requests, it does not tell
        // the second one that the code it meant to replace is already gone — and that
        // request would otherwise perform a second, unintended rotation.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.rotate_affiliate_code(
                p_affiliate_id BIGINT, p_expected_code_id BIGINT, p_actor_user_id BIGINT
            )
            RETURNS TABLE(affiliate_id BIGINT, issued_code CHARACTER VARYING)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_affiliate public.affiliates%ROWTYPE;
                v_at TIMESTAMPTZ;
                v_code CHARACTER VARYING;
                v_deactivated INTEGER;
            BEGIN
                PERFORM public.assert_affiliate_actor_is_admin(p_actor_user_id);

                SELECT a.* INTO v_affiliate FROM public.affiliates AS a
                WHERE a.id = p_affiliate_id FOR UPDATE;

                IF v_affiliate.id IS NULL THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'unknown affiliate';
                END IF;

                IF v_affiliate.status <> 'active' THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'only an active affiliate can rotate its code';
                END IF;

                v_at := now();

                -- The compare, after the lock: the request names the code it intends to
                -- replace, and a request whose code has already been rotated away is stale.
                UPDATE public.affiliate_codes AS c
                SET is_active = false, deactivated_at = v_at, updated_at = v_at
                WHERE c.affiliate_id = p_affiliate_id AND c.is_active AND c.id = p_expected_code_id;

                GET DIAGNOSTICS v_deactivated = ROW_COUNT;

                -- A DigiTrove-specific SQLSTATE, not a standard one. `40001` already means
                -- "a policy was published concurrently" in this domain, and it also invites
                -- a blind retry — which is wrong here: replaying the same expected code can
                -- only fail again. `23514` is likewise taken, by "not a draft". A stale
                -- rotation is its own condition and gets its own, non-retryable code.
                IF v_deactivated <> 1 THEN
                    RAISE EXCEPTION USING ERRCODE = 'AF001',
                        MESSAGE = 'stale affiliate code rotation';
                END IF;

                v_code := public.generate_affiliate_code();

                INSERT INTO public.affiliate_codes (affiliate_id, code, is_active, created_at, updated_at)
                VALUES (p_affiliate_id, v_code, true, v_at, v_at);

                RETURN QUERY SELECT p_affiliate_id, v_code;
            END;
            $$;
            SQL);

        // ── 7–10. Bounded reads ──────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.list_affiliates(
                p_status CHARACTER VARYING, p_limit INTEGER, p_after_id BIGINT
            )
            RETURNS TABLE(
                affiliate_id BIGINT, public_id UUID, user_id BIGINT, affiliate_status CHARACTER VARYING,
                applied_at TIMESTAMPTZ, approved_at TIMESTAMPTZ, rejected_at TIMESTAMPTZ,
                suspended_at TIMESTAMPTZ, closed_at TIMESTAMPTZ, active_code CHARACTER VARYING
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid page size';
                END IF;

                IF p_status IS NOT NULL AND p_status NOT IN ('pending', 'active', 'suspended', 'rejected', 'closed') THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid status filter';
                END IF;

                RETURN QUERY
                SELECT a.id, a.public_id, a.user_id, a.status,
                       a.applied_at, a.approved_at, a.rejected_at, a.suspended_at, a.closed_at,
                       (SELECT c.code FROM public.affiliate_codes AS c
                        WHERE c.affiliate_id = a.id AND c.is_active LIMIT 1)
                FROM public.affiliates AS a
                WHERE (p_status IS NULL OR a.status = p_status)
                  AND (p_after_id IS NULL OR a.id > p_after_id)
                ORDER BY a.id
                LIMIT p_limit;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.get_affiliate(p_affiliate_id BIGINT)
            RETURNS TABLE(
                affiliate_id BIGINT, public_id UUID, user_id BIGINT, affiliate_status CHARACTER VARYING,
                applied_at TIMESTAMPTZ, approved_at TIMESTAMPTZ, rejected_at TIMESTAMPTZ,
                suspended_at TIMESTAMPTZ, closed_at TIMESTAMPTZ,
                reviewed_by_user_id BIGINT,
                active_code_id BIGINT, active_code CHARACTER VARYING
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                -- `active_code_id` is the compare-and-swap token `rotate_affiliate_code`
                -- demands. It is read here, in the same snapshot as the value the
                -- administrator will actually look at, so that rotating can prove the
                -- intent was formed against THIS code — never against whatever happens to
                -- be active at the moment the button is pressed.
                --
                -- A LATERAL join rather than two scalar subqueries: both columns then come
                -- from one row by construction. Two independent `LIMIT 1` lookups would be
                -- correct only because of the single-active-code index, which makes the
                -- pairing an accident of an invariant declared elsewhere instead of a
                -- property of this query. `LEFT` keeps affiliates with no active code in
                -- the result, returning NULL for both columns rather than no row at all.
                RETURN QUERY
                SELECT a.id, a.public_id, a.user_id, a.status,
                       a.applied_at, a.approved_at, a.rejected_at, a.suspended_at, a.closed_at,
                       a.reviewed_by_user_id,
                       active.id, active.code
                FROM public.affiliates AS a
                LEFT JOIN LATERAL (
                    SELECT c.id, c.code
                    FROM public.affiliate_codes AS c
                    WHERE c.affiliate_id = a.id AND c.is_active
                    LIMIT 1
                ) AS active ON true
                WHERE a.id = p_affiliate_id;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.list_affiliate_lifecycle_events(
                p_affiliate_id BIGINT, p_limit INTEGER, p_before_id BIGINT
            )
            RETURNS TABLE(
                event_id BIGINT, from_status CHARACTER VARYING, to_status CHARACTER VARYING,
                event_kind CHARACTER VARYING, actor_user_id BIGINT, reason_code CHARACTER VARYING,
                occurred_at TIMESTAMPTZ
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid page size';
                END IF;

                RETURN QUERY
                SELECT e.id, e.from_status, e.to_status, e.event_kind, e.actor_user_id,
                       e.reason_code, e.occurred_at
                FROM public.affiliate_lifecycle_events AS e
                WHERE e.affiliate_id = p_affiliate_id
                  AND (p_before_id IS NULL OR e.id < p_before_id)
                ORDER BY e.id DESC
                LIMIT p_limit;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.list_affiliate_codes(p_affiliate_id BIGINT, p_limit INTEGER)
            RETURNS TABLE(
                code_id BIGINT, code CHARACTER VARYING, is_active BOOLEAN,
                deactivated_at TIMESTAMPTZ, created_at TIMESTAMPTZ
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid page size';
                END IF;

                RETURN QUERY
                SELECT c.id, c.code, c.is_active, c.deactivated_at, c.created_at
                FROM public.affiliate_codes AS c
                WHERE c.affiliate_id = p_affiliate_id
                ORDER BY c.id DESC
                LIMIT p_limit;
            END;
            $$;
            SQL);

        foreach ([...$this->runtimeAuthorities(), ...$this->internalFunctions()] as $signature) {
            DB::statement('ALTER FUNCTION '.$signature.' OWNER TO digitrove_affiliate_executor');
        }
    }

    private function lockDownPrivileges(): void
    {
        // The ledger follows the P6-D1 boundary exactly: owned by the executor, untouchable
        // by the runtime, unreadable by PUBLIC.
        DB::statement('ALTER TABLE public.affiliate_lifecycle_events OWNER TO digitrove_affiliate_executor');
        DB::statement('ALTER SEQUENCE public.affiliate_lifecycle_events_id_seq OWNER TO digitrove_affiliate_executor');
        DB::statement('REVOKE ALL ON TABLE public.affiliate_lifecycle_events FROM PUBLIC');
        DB::statement('REVOKE ALL ON TABLE public.affiliate_lifecycle_events FROM digitrove_runtime');
        DB::statement('REVOKE ALL ON SEQUENCE public.affiliate_lifecycle_events_id_seq FROM PUBLIC');
        DB::statement('REVOKE ALL ON SEQUENCE public.affiliate_lifecycle_events_id_seq FROM digitrove_runtime');

        foreach ([...$this->runtimeAuthorities(), ...$this->internalFunctions()] as $signature) {
            DB::statement('REVOKE ALL ON FUNCTION '.$signature.' FROM PUBLIC');
            DB::statement('REVOKE ALL ON FUNCTION '.$signature.' FROM digitrove_runtime');
        }

        // Only the bounded authorities are callable. The generator and the actor check stay
        // internal: exposing the generator would let a caller mint a code for a pending,
        // rejected or closed affiliate.
        foreach ($this->runtimeAuthorities() as $signature) {
            DB::statement('GRANT EXECUTE ON FUNCTION '.$signature.' TO digitrove_runtime');
        }
    }
};
