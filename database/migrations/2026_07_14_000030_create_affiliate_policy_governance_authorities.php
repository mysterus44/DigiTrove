<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P6-D1 — Affiliate authority frontier and policy governance (D-058).
 *
 * THIS MIGRATION EXISTS BECAUSE OF ONE DEFECT.
 *
 * P6-D0 left the nine `affiliate_*` tables owned by `digitrove`, the SUPERUSER migrator.
 * A `SECURITY DEFINER` function created in that state executes as superuser — precisely
 * the hole D-029.6 / P4-B0 closed for downloads. So the ownership is corrected FIRST, and
 * only then are the governance authorities created, owned by the restricted executor.
 *
 * `000029` is never rewritten; this migration is purely additive and its down() restores
 * the `000029` frontier exactly — ownership included.
 *
 * WHAT THIS GATE DOES NOT DO: no affiliate application, no code issuance, no touch, no
 * attribution, no commission, no ledger movement, no payout, no scheduler, no public
 * route. Those belong to P6-D1.1 and beyond.
 */
return new class extends Migration
{
    private const CREATE_DRAFT_SIGNATURE = 'public.create_affiliate_program_policy_draft(integer, integer, integer, integer, bigint, character varying)';

    private const UPDATE_DRAFT_SIGNATURE = 'public.update_affiliate_program_policy_draft(bigint, integer, integer, integer, bigint, character varying)';

    private const PUBLISH_SIGNATURE = 'public.publish_affiliate_program_policy(bigint)';

    private const CURRENT_SIGNATURE = 'public.current_affiliate_program_policy()';

    private const LIST_SIGNATURE = 'public.list_affiliate_program_policies(integer, integer)';

    /**
     * The canonical inventory. Ownership, lockdown and rollback all iterate it, so a
     * function cannot be created without also being owned, revoked and dropped.
     *
     * @return list<string>
     */
    private function functionSignatures(): array
    {
        return [
            self::CREATE_DRAFT_SIGNATURE,
            self::UPDATE_DRAFT_SIGNATURE,
            self::PUBLISH_SIGNATURE,
            self::CURRENT_SIGNATURE,
            self::LIST_SIGNATURE,
        ];
    }

    /**
     * The nine tables P6-D0 created. Their sequences are derived, not hard-coded, so a
     * schema that ever diverges cannot leave a sequence behind under the old owner.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        return [
            'affiliate_program_policies',
            'affiliates',
            'affiliate_codes',
            'affiliate_touches',
            'affiliate_attributions',
            'affiliate_commissions',
            'affiliate_commission_entries',
            'affiliate_payouts',
            'affiliate_payout_items',
        ];
    }

    /**
     * Sequences owned by the affiliate tables, read from `pg_catalog` rather than assumed
     * to be exactly one per table.
     *
     * @return list<string>
     */
    private function sequences(): array
    {
        return array_map(
            static fn (object $row): string => (string) $row->relname,
            DB::select(<<<'SQL'
                SELECT c.relname
                FROM pg_class AS c
                JOIN pg_namespace AS n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND c.relkind = 'S' AND c.relname LIKE 'affiliate%'
                ORDER BY 1
                SQL),
        );
    }

    public function up(): void
    {
        $this->assertExecutorProvisioned();
        $this->widenEffectivePeriodPrecision(6);
        $this->transferOwnership('digitrove_affiliate_executor');
        $this->createAuthorities();
        $this->lockDownPrivileges();
    }

    public function down(): void
    {
        // FIRST, before a single object is touched. If the downgrade cannot be lossless,
        // the database must stay exactly as it is — fully P6-D1 — rather than end up
        // halfway through a rollback that is about to falsify a financial chronology.
        $this->assertLosslessDowngrade();

        foreach ($this->functionSignatures() as $signature) {
            DB::statement('REVOKE EXECUTE ON FUNCTION '.$signature.' FROM digitrove_runtime');
            DB::statement('DROP FUNCTION IF EXISTS '.$signature);
        }

        // Restore the exact `000029` frontier.
        $this->transferOwnership('digitrove');
        // Narrowing back is lossy for any sub-second value already stored — that is the
        // faithful inverse of widening, and rolling P6-D1 back is itself a governance
        // decision, not a routine operation.
        $this->widenEffectivePeriodPrecision(0);

        // The role itself is NOT dropped: it is cluster-global and may be referenced by
        // other databases on the same cluster. Provisioning owns its lifecycle, exactly
        // as P4-B0 established for the download and analytics executors.
    }

    /**
     * `000029` created the effective period with Laravel's `timestampTz`, which is
     * `timestamptz(0)` — SECOND precision. That silently breaks atomic publication.
     *
     * Publishing closes the predecessor at exactly the instant the successor opens. At
     * second precision, two publications less than a second apart round to the SAME
     * stored value, so the predecessor ends up with `effective_until = effective_from`
     * and `affiliate_program_policies_period_check` refuses it. A legitimate
     * administrative action would fail on a clock accident, and in tests it is close to
     * certain.
     *
     * Widening here is additive DDL in a NEW migration; `000029` is never rewritten.
     * Rounding the timestamp forward instead was rejected: it would place the successor
     * up to a second ahead of the wall clock, leaving the programme with NO policy in
     * force — a worse failure than the one being fixed.
     */
    private function widenEffectivePeriodPrecision(int $precision): void
    {
        foreach (['effective_from', 'effective_until'] as $column) {
            DB::statement(
                'ALTER TABLE public.affiliate_program_policies ALTER COLUMN '.$column
                .' TYPE TIMESTAMPTZ('.$precision.')',
            );
        }
    }

    private function transferOwnership(string $owner): void
    {
        foreach ($this->tables() as $table) {
            DB::statement('ALTER TABLE public.'.$table.' OWNER TO '.$owner);
        }

        // Sequences must follow. A sequence left under the old owner is an invisible ACL
        // drift: nothing fails today, and the boundary is quietly wrong.
        foreach ($this->sequences() as $sequence) {
            DB::statement('ALTER SEQUENCE public.'.$sequence.' OWNER TO '.$owner);
        }
    }

    private function createAuthorities(): void
    {
        // ── 1. Create a draft ────────────────────────────────────────────────────
        //
        // `p_version` is supplied by the caller ON PURPOSE: it is the natural idempotency
        // identity. A double-clicked form sends the same number twice and the second call
        // hits `affiliate_program_policies_version_unique`, deterministically — no opaque
        // key, no invented token. It also stops a stale screen from creating a version
        // that has already been taken.
        //
        // `attribution_model` and `manual_payout_only` are NOT parameters: the caller
        // cannot smuggle in an unreviewed attribution model or an automatic payout.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.create_affiliate_program_policy_draft(
                p_version INTEGER,
                p_attribution_window_days INTEGER,
                p_default_commission_bps INTEGER,
                p_payable_delay_days INTEGER,
                p_payout_threshold_minor BIGINT,
                p_payout_currency CHARACTER VARYING
            )
            RETURNS TABLE(
                policy_id BIGINT,
                version INTEGER,
                status CHARACTER VARYING
            )
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_expected INTEGER;
                v_id BIGINT;
            BEGIN
                IF p_version IS NULL OR p_version < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid policy version';
                END IF;

                SELECT COALESCE(MAX(p.version), 0) + 1 INTO v_expected
                FROM public.affiliate_program_policies AS p;

                -- A version must continue the sequence: no holes, no going back.
                IF p_version <> v_expected THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'policy version is not the next one';
                END IF;

                INSERT INTO public.affiliate_program_policies (
                    public_id, version, status, attribution_model,
                    attribution_window_days, default_commission_bps, payable_delay_days,
                    payout_threshold_minor, payout_currency, manual_payout_only,
                    effective_from, created_at, updated_at
                )
                VALUES (
                    gen_random_uuid(), p_version, 'draft', 'code_then_last_click',
                    p_attribution_window_days, p_default_commission_bps, p_payable_delay_days,
                    p_payout_threshold_minor, upper(p_payout_currency), true,
                    -- Placeholder only. A draft has no effective date; publication sets it.
                    now(), now(), now()
                )
                RETURNING id INTO v_id;

                RETURN QUERY SELECT v_id, p_version, 'draft'::character varying;
            END;
            $$;
            SQL);

        // ── 2. Update a draft ────────────────────────────────────────────────────
        //
        // Replaying the same values yields the same row: the operation is naturally
        // idempotent, so no retry token is needed. An effective policy is refused here
        // AND by the `000029` immutability trigger — two independent barriers.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.update_affiliate_program_policy_draft(
                p_policy_id BIGINT,
                p_attribution_window_days INTEGER,
                p_default_commission_bps INTEGER,
                p_payable_delay_days INTEGER,
                p_payout_threshold_minor BIGINT,
                p_payout_currency CHARACTER VARYING
            )
            RETURNS TABLE(
                policy_id BIGINT,
                version INTEGER,
                status CHARACTER VARYING
            )
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_policy public.affiliate_program_policies%ROWTYPE;
            BEGIN
                IF p_policy_id IS NULL OR p_policy_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid policy identifier';
                END IF;

                SELECT p.* INTO v_policy
                FROM public.affiliate_program_policies AS p
                WHERE p.id = p_policy_id
                FOR UPDATE;

                IF v_policy.id IS NULL THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'unknown affiliate program policy';
                END IF;

                IF v_policy.status <> 'draft' THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'only a draft policy can be edited';
                END IF;

                UPDATE public.affiliate_program_policies AS p
                SET attribution_window_days = p_attribution_window_days,
                    default_commission_bps = p_default_commission_bps,
                    payable_delay_days = p_payable_delay_days,
                    payout_threshold_minor = p_payout_threshold_minor,
                    payout_currency = upper(p_payout_currency),
                    updated_at = now()
                WHERE p.id = p_policy_id;

                RETURN QUERY SELECT v_policy.id, v_policy.version, 'draft'::character varying;
            END;
            $$;
            SQL);

        // ── 3. Publish ───────────────────────────────────────────────────────────
        //
        // THE invariant of this gate. Closing the predecessor and opening the successor
        // is ONE transition, so the six broken states are unreachable through the normal
        // path: two actives, zero actives after a partial failure, an overlap, a gap, a
        // predecessor closed without a successor, a successor published without closing.
        //
        // `now()` is transaction_timestamp(): it does NOT advance between statements, so
        // the predecessor's `effective_until` and the successor's `effective_from` receive
        // the IDENTICAL value. With a half-open interval [from, until) that is neither a
        // gap nor an overlap, by construction. `publish_crm_segment_version` uses
        // clock_timestamp(); copying it here would manufacture a microsecond hole.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.publish_affiliate_program_policy(p_policy_id BIGINT)
            RETURNS TABLE(
                policy_id BIGINT,
                version INTEGER,
                status CHARACTER VARYING,
                effective_from TIMESTAMPTZ,
                superseded_policy_id BIGINT
            )
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_draft public.affiliate_program_policies%ROWTYPE;
                v_active public.affiliate_program_policies%ROWTYPE;
                v_at TIMESTAMPTZ;
            BEGIN
                IF p_policy_id IS NULL OR p_policy_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid policy identifier';
                END IF;

                -- Lock the candidate first. Two admins publishing the SAME draft serialise
                -- here; the loser then reads a row that is no longer a draft.
                SELECT p.* INTO v_draft
                FROM public.affiliate_program_policies AS p
                WHERE p.id = p_policy_id
                FOR UPDATE;

                IF v_draft.id IS NULL THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'unknown affiliate program policy';
                END IF;

                IF v_draft.status <> 'draft' THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'only a draft policy can be published';
                END IF;

                SELECT p.* INTO v_active
                FROM public.affiliate_program_policies AS p
                WHERE p.status = 'active'
                FOR UPDATE;

                IF v_active.id IS NULL THEN
                    -- Either this is the first publication ever, or a concurrent publisher
                    -- superseded the incumbent while we waited for its lock. READ COMMITTED
                    -- gives this statement a fresh snapshot, so a policy that became active
                    -- meanwhile IS visible here — and that is a lost race, not a first
                    -- publication. Refusing beats silently publishing over someone else.
                    -- `p.status` is qualified on purpose: `status` is also an OUT column
                    -- of this function, so plpgsql would treat a bare `status` as its own
                    -- variable and refuse the reference as ambiguous.
                    IF EXISTS (
                        SELECT 1 FROM public.affiliate_program_policies AS p WHERE p.status = 'active'
                    ) THEN
                        RAISE EXCEPTION USING ERRCODE = '40001',
                            MESSAGE = 'another affiliate policy was published concurrently';
                    END IF;
                ELSIF v_draft.version <= v_active.version THEN
                    RAISE EXCEPTION USING ERRCODE = '23514',
                        MESSAGE = 'published policy versions must increase';
                END IF;

                v_at := now();

                IF v_active.id IS NOT NULL THEN
                    UPDATE public.affiliate_program_policies AS p
                    SET status = 'superseded',
                        effective_until = v_at,
                        updated_at = v_at
                    WHERE p.id = v_active.id;
                END IF;

                UPDATE public.affiliate_program_policies AS p
                SET status = 'active',
                    effective_from = v_at,
                    effective_until = NULL,
                    updated_at = v_at
                WHERE p.id = v_draft.id;

                RETURN QUERY SELECT v_draft.id, v_draft.version, 'active'::character varying, v_at, v_active.id;
            END;
            $$;
            SQL);

        // ── 4. Read the policy in force ──────────────────────────────────────────
        //
        // `status = 'active'` MEANS "in force now" (D-058), so the temporal predicate is
        // asserted as well, not assumed. If the two ever disagreed, returning nothing is
        // the fail-closed answer: no caller should silently pay a rate nobody can explain.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.current_affiliate_program_policy()
            RETURNS TABLE(
                policy_id BIGINT,
                version INTEGER,
                attribution_model CHARACTER VARYING,
                attribution_window_days INTEGER,
                default_commission_bps INTEGER,
                payable_delay_days INTEGER,
                payout_threshold_minor BIGINT,
                payout_currency CHARACTER VARYING,
                effective_from TIMESTAMPTZ
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                RETURN QUERY
                SELECT p.id, p.version, p.attribution_model, p.attribution_window_days,
                       p.default_commission_bps, p.payable_delay_days,
                       p.payout_threshold_minor, p.payout_currency, p.effective_from
                FROM public.affiliate_program_policies AS p
                WHERE p.status = 'active'
                  AND p.effective_from <= now()
                  AND (p.effective_until IS NULL OR now() < p.effective_until);
            END;
            $$;
            SQL);

        // ── 5. Bounded history ───────────────────────────────────────────────────
        //
        // Administration only, keyset-bounded, deterministically ordered. Not an export:
        // no file, no unbounded page, no CSV.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.list_affiliate_program_policies(
                p_limit INTEGER,
                p_before_version INTEGER
            )
            RETURNS TABLE(
                policy_id BIGINT,
                version INTEGER,
                status CHARACTER VARYING,
                attribution_window_days INTEGER,
                default_commission_bps INTEGER,
                payable_delay_days INTEGER,
                payout_threshold_minor BIGINT,
                payout_currency CHARACTER VARYING,
                effective_from TIMESTAMPTZ,
                effective_until TIMESTAMPTZ
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
                SELECT p.id, p.version, p.status, p.attribution_window_days,
                       p.default_commission_bps, p.payable_delay_days,
                       p.payout_threshold_minor, p.payout_currency,
                       p.effective_from, p.effective_until
                FROM public.affiliate_program_policies AS p
                WHERE p_before_version IS NULL OR p.version < p_before_version
                ORDER BY p.version DESC
                LIMIT p_limit;
            END;
            $$;
            SQL);

        // Every authority belongs to the RESTRICTED executor, never to the superuser
        // migrator. This single line is what makes SECURITY DEFINER safe here.
        foreach ($this->functionSignatures() as $signature) {
            DB::statement('ALTER FUNCTION '.$signature.' OWNER TO digitrove_affiliate_executor');
        }
    }

    private function lockDownPrivileges(): void
    {
        foreach ($this->functionSignatures() as $signature) {
            DB::statement('REVOKE ALL ON FUNCTION '.$signature.' FROM PUBLIC');
            DB::statement('REVOKE ALL ON FUNCTION '.$signature.' FROM digitrove_runtime');
            // The bounded authorities are the runtime's ONLY way into the affiliate
            // schema. It still holds no SELECT, INSERT, UPDATE or DELETE on any table.
            DB::statement('GRANT EXECUTE ON FUNCTION '.$signature.' TO digitrove_runtime');
        }
    }

    /**
     * The rollback is LOSSLESS-ONLY.
     *
     * Narrowing `timestamptz(6)` back to `(0)` rounds every stored instant to the nearest
     * second. On a database that actually published policies, two transitions less than a
     * second apart collapse onto the same value — which not only breaks
     * `affiliate_program_policies_period_check`, it REWRITES the timeline that explains
     * what every past commission was worth.
     *
     * So the rule is not "refuse when the table is not empty": a chronology already sitting
     * exactly on the second is perfectly representable and rolls back cleanly. The rule is
     * refuse only when the conversion would CHANGE a value.
     *
     * The test uses PostgreSQL's own cast, not `date_trunc()` or a PHP approximation, so it
     * asks precisely the question `ALTER COLUMN … TYPE TIMESTAMPTZ(0)` is about to ask.
     */
    private function assertLosslessDowngrade(): void
    {
        $lossy = DB::selectOne(<<<'SQL'
            SELECT count(*) AS lossy_rows
            FROM public.affiliate_program_policies AS p
            WHERE p.effective_from <> p.effective_from::timestamptz(0)
               OR (p.effective_until IS NOT NULL AND p.effective_until <> p.effective_until::timestamptz(0))
            SQL);

        if ($lossy !== null && (int) $lossy->lossy_rows > 0) {
            throw new RuntimeException(
                'Cannot roll back P6-D1: the affiliate policy chronology holds sub-second timestamps '
                .'that P6-D0 cannot represent. Rolling back would rewrite the history explaining past '
                .'commissions, so no change was made.',
            );
        }
    }

    private function assertExecutorProvisioned(): void
    {
        $executor = DB::selectOne(<<<'SQL'
            SELECT rolsuper, rolcanlogin, rolcreatedb, rolcreaterole,
                   rolreplication, rolbypassrls, rolinherit
            FROM pg_roles
            WHERE rolname = 'digitrove_affiliate_executor'
            SQL);

        if ($executor === null
            || $executor->rolsuper
            || $executor->rolcanlogin
            || $executor->rolcreatedb
            || $executor->rolcreaterole
            || $executor->rolreplication
            || $executor->rolbypassrls
            || $executor->rolinherit) {
            throw new RuntimeException('P6-D1 migration: digitrove_affiliate_executor must be a restricted NOLOGIN NOINHERIT role. Run db:provision-runtime-roles first.');
        }
    }
};
