<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P6-D3 — commission accrual, payable promotion and refund reversal authorities.
 *
 * NO TABLE, NO COLUMN, NO INDEX: the schema has existed since `000029`. This migration adds
 * only the three bounded write authorities the runtime needs, because the D-058 frontier is
 * absolute — `digitrove_runtime` holds NOTHING on `affiliate_commissions` or
 * `affiliate_commission_entries`, not even `SELECT`. That frontier is never lifted by
 * loosening a GRANT on the tables; it is respected by passing through narrow functions,
 * exactly as `000032` did for capture and attribution.
 *
 * Money rules fixed here, all integer arithmetic — no float, no `round()`, no NUMERIC:
 *
 *   • The rate comes from the ATTRIBUTION'S policy, never the currently active one. That is
 *     the entire point of versioned policies: a sale is explained by the rules in force when
 *     it happened, and publishing a new version tomorrow must not rewrite yesterday.
 *   • `payable_at = orders.paid_at + payable_delay_days`. Anchored to when the money came in,
 *     never to when the worker ran — a queue delay must not move a financial deadline.
 *   • A commission whose amount would be ZERO produces NO ROW. The ledger forbids
 *     `amount_minor = 0`, so such a row could never receive its `accrual` and would sit
 *     forever in a state with no legal transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Accrual ───────────────────────────────────────────────────────────────
        //
        // One commission per commissionable order line, plus its single `accrual` ledger
        // entry, in one statement. Idempotent: a replayed worker gets `already_accrued`,
        // and even if it raced past that check the partial unique index
        // `affiliate_commission_entries_single_accrual` refuses the second accrual.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.accrue_affiliate_commissions(p_order_id BIGINT)
            RETURNS TEXT
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_paid_at TIMESTAMP WITH TIME ZONE;
                v_attribution_id BIGINT;
                v_affiliate_id BIGINT;
                v_policy_id BIGINT;
                v_rate_bps INTEGER;
                v_delay_days INTEGER;
                v_created INTEGER;
            BEGIN
                SELECT o.paid_at INTO v_paid_at
                FROM public.orders o
                WHERE o.id = p_order_id;

                IF NOT FOUND THEN
                    RETURN 'no_such_order';
                END IF;

                -- `paid_at` is the anchor of every deadline below, so it must exist.
                IF v_paid_at IS NULL THEN
                    RETURN 'not_paid';
                END IF;

                SELECT a.id, a.affiliate_id, a.policy_id
                INTO v_attribution_id, v_affiliate_id, v_policy_id
                FROM public.affiliate_attributions a
                WHERE a.order_id = p_order_id;

                IF NOT FOUND THEN
                    RETURN 'no_attribution';
                END IF;

                -- Serialise concurrent accruals for this order before the existence check,
                -- otherwise two workers could both pass it and race into the unique index.
                PERFORM 1 FROM public.affiliate_attributions WHERE id = v_attribution_id FOR UPDATE;

                IF EXISTS (SELECT 1 FROM public.affiliate_commissions WHERE order_id = p_order_id) THEN
                    RETURN 'already_accrued';
                END IF;

                SELECT pol.default_commission_bps, pol.payable_delay_days
                INTO v_rate_bps, v_delay_days
                FROM public.affiliate_program_policies pol
                WHERE pol.id = v_policy_id;

                IF NOT FOUND THEN
                    RETURN 'no_such_policy';
                END IF;

                WITH commissionable AS (
                    SELECT
                        oi.id AS order_item_id,
                        oi.line_total_minor,
                        oi.currency,
                        -- Integer division, truncating. The rate is capped at 5000 bps by
                        -- `affiliate_program_policies_rate_check`, so the result can never
                        -- breach `affiliate_commissions_amount_within_base_check`.
                        (oi.line_total_minor * v_rate_bps) / 10000 AS amount_minor
                    FROM public.order_items oi
                    WHERE oi.order_id = p_order_id
                ),
                inserted AS (
                    INSERT INTO public.affiliate_commissions (
                        public_id, affiliate_id, order_id, order_item_id, attribution_id,
                        policy_id, status, rate_bps_snapshot, base_kind_snapshot,
                        base_amount_minor_snapshot, amount_minor, currency,
                        payable_delay_days_snapshot, payable_at, created_at, updated_at
                    )
                    SELECT
                        gen_random_uuid(), v_affiliate_id, p_order_id, c.order_item_id,
                        v_attribution_id, v_policy_id, 'pending', v_rate_bps,
                        'line_total_after_discount', c.line_total_minor, c.amount_minor,
                        c.currency, v_delay_days,
                        v_paid_at + (v_delay_days || ' days')::interval,
                        CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    FROM commissionable c
                    -- A zero commission creates NO ROW: see the class comment.
                    WHERE c.amount_minor > 0
                    RETURNING id, affiliate_id, amount_minor, currency
                )
                INSERT INTO public.affiliate_commission_entries (
                    affiliate_id, commission_id, entry_type, amount_minor, currency,
                    occurred_at, created_at
                )
                SELECT i.affiliate_id, i.id, 'accrual', i.amount_minor, i.currency,
                       CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                FROM inserted i;

                GET DIAGNOSTICS v_created = ROW_COUNT;

                IF v_created = 0 THEN
                    RETURN 'no_commissionable_line';
                END IF;

                RETURN 'accrued';
            END;
            $$;
        SQL);
        DB::statement('ALTER FUNCTION public.accrue_affiliate_commissions(BIGINT) OWNER TO digitrove_affiliate_executor');
        DB::statement('REVOKE ALL ON FUNCTION public.accrue_affiliate_commissions(BIGINT) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION public.accrue_affiliate_commissions(BIGINT) TO digitrove_runtime');

        // ── 2. Payable promotion ─────────────────────────────────────────────────────
        //
        // A scheduled sweep, never a computation at read time. The transition then carries a
        // real, auditable timestamp (`updated_at`), which a derived status could never have.
        //
        // ⚠️ It writes NO ledger entry. `release` is a POSITIVE entry type, and the balance
        // of an affiliate is `SUM(amount_minor)` over the ledger: emitting a `release` on top
        // of the `accrual` would credit the same money twice. Becoming payable is a change of
        // STATE on the commission, not a movement of money. `release` stays unused until a
        // gate defines a semantic for it that does not double the balance.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.promote_affiliate_commissions_to_payable(p_limit INTEGER)
            RETURNS INTEGER
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_promoted INTEGER;
            BEGIN
                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 1000 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'limit must be between 1 and 1000';
                END IF;

                WITH due AS (
                    SELECT c.id
                    FROM public.affiliate_commissions c
                    WHERE c.status = 'pending'
                      AND c.payable_at <= CURRENT_TIMESTAMP
                    ORDER BY c.payable_at, c.id
                    LIMIT p_limit
                    -- SKIP LOCKED so two sweepers share the work instead of blocking.
                    FOR UPDATE SKIP LOCKED
                )
                UPDATE public.affiliate_commissions c
                SET status = 'payable', updated_at = CURRENT_TIMESTAMP
                FROM due
                WHERE c.id = due.id;

                GET DIAGNOSTICS v_promoted = ROW_COUNT;

                RETURN v_promoted;
            END;
            $$;
        SQL);
        DB::statement('ALTER FUNCTION public.promote_affiliate_commissions_to_payable(INTEGER) OWNER TO digitrove_affiliate_executor');
        DB::statement('REVOKE ALL ON FUNCTION public.promote_affiliate_commissions_to_payable(INTEGER) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION public.promote_affiliate_commissions_to_payable(INTEGER) TO digitrove_runtime');

        // ── 3. Refund reversal ───────────────────────────────────────────────────────
        //
        // The split of a refund across order lines is computed in PHP by
        // `App\Services\Pricing\DiscountAllocator` — the ONE Hamilton implementation in this
        // repository (D-030 Q3) — and arrives here as `{order_item_id: allocated_minor}`.
        // This function never re-splits anything; it turns an allocation into signed ledger
        // movements.
        //
        // Two regimes, and the difference matters:
        //
        //   • FULL refund (`orders.status = 'refunded'`): every commission of the order is
        //     reversed by its ENTIRE remaining balance. Truncating division across several
        //     partial refunds could otherwise leave an affiliate holding a few minor units of
        //     a sale that was returned in full.
        //   • PARTIAL refund: `(allocated * rate_bps) / 10000`, the same truncating rule that
        //     produced the accrual, capped at the remaining balance so a reversal can never
        //     exceed what was credited.
        //
        // A commission whose balance reaches zero becomes `cancelled`: it must never be
        // picked up by a future payout.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.apply_affiliate_refund_reversal(
                p_refund_id BIGINT,
                p_allocation JSONB
            )
            RETURNS TEXT
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_refund_status TEXT;
                v_order_id BIGINT;
                v_order_status TEXT;
                v_full BOOLEAN;
                v_row RECORD;
                v_balance BIGINT;
                v_allocated BIGINT;
                v_reversal BIGINT;
                v_written INTEGER := 0;
            BEGIN
                IF p_allocation IS NULL OR jsonb_typeof(p_allocation) <> 'object' THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'allocation must be a json object';
                END IF;

                SELECT r.status, o.id, o.status
                INTO v_refund_status, v_order_id, v_order_status
                FROM public.refunds r
                JOIN public.payments p ON p.id = r.payment_id
                JOIN public.orders o ON o.id = p.order_id
                WHERE r.id = p_refund_id;

                IF NOT FOUND THEN
                    RETURN 'no_such_refund';
                END IF;

                -- Only money that actually moved reverses a commission.
                IF v_refund_status <> 'succeeded' THEN
                    RETURN 'refund_not_succeeded';
                END IF;

                IF NOT EXISTS (SELECT 1 FROM public.affiliate_commissions WHERE order_id = v_order_id) THEN
                    RETURN 'no_commissions';
                END IF;

                v_full := (v_order_status = 'refunded');

                FOR v_row IN
                    SELECT c.id, c.affiliate_id, c.order_item_id, c.currency, c.rate_bps_snapshot
                    FROM public.affiliate_commissions c
                    WHERE c.order_id = v_order_id
                    ORDER BY c.id
                    -- Serialises concurrent reversals on the same order: the balance read
                    -- below must not move under us between SELECT and INSERT.
                    FOR UPDATE
                LOOP
                    IF EXISTS (
                        SELECT 1 FROM public.affiliate_commission_entries e
                        WHERE e.commission_id = v_row.id
                          AND e.refund_id = p_refund_id
                          AND e.entry_type = 'refund_reversal'
                    ) THEN
                        CONTINUE;
                    END IF;

                    -- The ledger IS the balance: accrual (+) plus every reversal (−).
                    SELECT COALESCE(SUM(e.amount_minor), 0)::BIGINT INTO v_balance
                    FROM public.affiliate_commission_entries e
                    WHERE e.commission_id = v_row.id;

                    IF v_balance <= 0 THEN
                        CONTINUE;
                    END IF;

                    IF v_full THEN
                        v_reversal := v_balance;
                    ELSE
                        v_allocated := COALESCE((p_allocation ->> v_row.order_item_id::text)::BIGINT, 0);

                        IF v_allocated < 0 THEN
                            RAISE EXCEPTION USING ERRCODE = '22023',
                                MESSAGE = 'an allocated amount cannot be negative';
                        END IF;

                        v_reversal := LEAST((v_allocated * v_row.rate_bps_snapshot) / 10000, v_balance);
                    END IF;

                    -- A zero movement is refused by the ledger and says nothing: skip it.
                    IF v_reversal <= 0 THEN
                        CONTINUE;
                    END IF;

                    INSERT INTO public.affiliate_commission_entries (
                        affiliate_id, commission_id, entry_type, amount_minor, currency,
                        refund_id, occurred_at, created_at
                    ) VALUES (
                        v_row.affiliate_id, v_row.id, 'refund_reversal', -v_reversal,
                        v_row.currency, p_refund_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    );

                    v_written := v_written + 1;

                    IF v_balance - v_reversal = 0 THEN
                        UPDATE public.affiliate_commissions
                        SET status = 'cancelled',
                            cancelled_at = CURRENT_TIMESTAMP,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = v_row.id;
                    END IF;
                END LOOP;

                IF v_written = 0 THEN
                    RETURN 'already_reversed';
                END IF;

                RETURN 'reversed';
            END;
            $$;
        SQL);
        DB::statement('ALTER FUNCTION public.apply_affiliate_refund_reversal(BIGINT, JSONB) OWNER TO digitrove_affiliate_executor');
        DB::statement('REVOKE ALL ON FUNCTION public.apply_affiliate_refund_reversal(BIGINT, JSONB) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION public.apply_affiliate_refund_reversal(BIGINT, JSONB) TO digitrove_runtime');

        // The accrual and reversal authorities read Commerce, which the affiliate executor
        // owns nothing of. Granted per COLUMN and only what the functions actually name —
        // never `customer_email`, never a coupon snapshot. `down()` revokes exactly these,
        // restoring the `000032` frontier. Precedent: `000022` for the CRM executor.
        DB::statement('GRANT SELECT (id, order_id, line_total_minor, currency) ON public.order_items TO digitrove_affiliate_executor');
        DB::statement('GRANT SELECT (id, paid_at, status) ON public.orders TO digitrove_affiliate_executor');
        DB::statement('GRANT SELECT (id, payment_id, status) ON public.refunds TO digitrove_affiliate_executor');
        DB::statement('GRANT SELECT (id, order_id) ON public.payments TO digitrove_affiliate_executor');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.apply_affiliate_refund_reversal(BIGINT, JSONB)');
        DB::statement('DROP FUNCTION IF EXISTS public.promote_affiliate_commissions_to_payable(INTEGER)');
        DB::statement('DROP FUNCTION IF EXISTS public.accrue_affiliate_commissions(BIGINT)');

        DB::statement('REVOKE SELECT (id, order_id) ON public.payments FROM digitrove_affiliate_executor');
        DB::statement('REVOKE SELECT (id, payment_id, status) ON public.refunds FROM digitrove_affiliate_executor');
        DB::statement('REVOKE SELECT (id, paid_at, status) ON public.orders FROM digitrove_affiliate_executor');
        DB::statement('REVOKE SELECT (id, order_id, line_total_minor, currency) ON public.order_items FROM digitrove_affiliate_executor');
    }
};
