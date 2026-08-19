<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P6-D4 — administrative payout authorities (D-069).
 *
 * NO TABLE, NO COLUMN: the schema has existed since `000029`, and `requested_by_user_id` /
 * `approved_by_user_id` were already there, so the two-administrator rule needs no new
 * column. This migration adds two partial unique indexes, five bounded authorities, and
 * replaces one P6-D3 authority to close a decision that was taken long ago and never met.
 *
 * ⚠️ A PAYOUT MOVES NO MONEY. There is no provider, no transfer, no bank or Mobile Money
 * detail anywhere — `administrative_reference` is a memo (a transfer slip number, an internal
 * reference) recording that a payment happened ELSEWHERE, outside this system. D-057 §11/§12.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Idempotency identities, at the closest point to the effect ───────────────
        //
        // The domain provides the natural keys, as it did for `accrual` and
        // `refund_reversal`. Relying on `affiliate_payout_items`' unique key instead would
        // let a replayed ledger write through: the guard must sit on the table that carries
        // the money movement, not on a neighbouring one.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX affiliate_commission_entries_payout_allocation_once
            ON public.affiliate_commission_entries (commission_id, payout_id)
            WHERE entry_type = 'payout_allocation'
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX affiliate_commission_entries_payout_reversal_once
            ON public.affiliate_commission_entries (commission_id, payout_id)
            WHERE entry_type = 'payout_reversal'
            SQL);

        // ── 1. Candidates ────────────────────────────────────────────────────────────
        //
        // The payable balance is `SUM(entries)`, never `commission.amount_minor`. A partial
        // refund may already have reduced it, and paying the nominal amount would hand over
        // money that was taken back.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.list_affiliate_payout_candidates(p_limit INTEGER)
            RETURNS TABLE (
                affiliate_id BIGINT,
                affiliate_public_id UUID,
                currency VARCHAR,
                commission_count BIGINT,
                payable_total_minor BIGINT,
                threshold_minor BIGINT,
                is_eligible BOOLEAN
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 500 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'limit must be between 1 and 500';
                END IF;

                RETURN QUERY
                WITH balances AS (
                    SELECT c.id, c.affiliate_id, c.currency,
                           COALESCE((SELECT SUM(e.amount_minor)
                                     FROM public.affiliate_commission_entries e
                                     WHERE e.commission_id = c.id), 0)::BIGINT AS balance
                    FROM public.affiliate_commissions c
                    WHERE c.status = 'payable'
                ),
                grouped AS (
                    SELECT b.affiliate_id, b.currency,
                           COUNT(*)::BIGINT AS commission_count,
                           SUM(b.balance)::BIGINT AS payable_total_minor
                    FROM balances b
                    WHERE b.balance > 0
                    GROUP BY b.affiliate_id, b.currency
                )
                SELECT g.affiliate_id, a.public_id, g.currency, g.commission_count,
                       g.payable_total_minor, pol.payout_threshold_minor,
                       -- A threshold in another currency is NOT comparable: no conversion
                       -- exists anywhere in this repository, and inventing one here would be
                       -- the exact multi-currency total P6-A1.1 forbids.
                       COALESCE(pol.payout_currency = g.currency
                                AND g.payable_total_minor >= pol.payout_threshold_minor, false) AS is_eligible
                FROM grouped g
                JOIN public.affiliates a ON a.id = g.affiliate_id
                -- LEFT, not CROSS: with no active policy the candidates must still be
                -- listed (simply never eligible), otherwise the screen would silently go
                -- blank and look like "nobody is owed anything".
                LEFT JOIN LATERAL (
                    SELECT p.payout_threshold_minor, p.payout_currency
                    FROM public.affiliate_program_policies p
                    WHERE p.status = 'active'
                ) pol ON TRUE
                ORDER BY g.payable_total_minor DESC, g.affiliate_id, g.currency
                LIMIT p_limit;
            END;
            $$;
        SQL);

        // ── 2. Request ───────────────────────────────────────────────────────────────
        //
        // The reservation happens HERE, not at approval: two administrators drafting at the
        // same time must not both grab the same commission. `allocated` and its
        // `payout_allocation` are written together with the payout, in one transaction.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.request_affiliate_payout(
                p_affiliate_id BIGINT,
                p_currency VARCHAR(3),
                p_actor_user_id BIGINT
            )
            RETURNS TABLE (payout_status TEXT, payout_id BIGINT)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_policy_id BIGINT;
                v_threshold BIGINT;
                v_policy_currency VARCHAR(3);
                v_total BIGINT;
                v_count INTEGER;
                v_payout_id BIGINT;
            BEGIN
                PERFORM public.assert_affiliate_actor_is_admin(p_actor_user_id);

                -- Serialises two administrators requesting for the same affiliate.
                PERFORM 1 FROM public.affiliates WHERE id = p_affiliate_id FOR UPDATE;

                IF NOT FOUND THEN
                    RETURN QUERY SELECT 'no_such_affiliate'::TEXT, NULL::BIGINT;
                    RETURN;
                END IF;

                -- ⚠️ The affiliate's OWN status is deliberately NOT checked. A commission
                -- already earned stays owed even if the affiliate was later suspended or
                -- closed; refusing here would invent a confiscation policy (D-069 §3).

                SELECT id, payout_threshold_minor, payout_currency
                INTO v_policy_id, v_threshold, v_policy_currency
                FROM public.affiliate_program_policies
                WHERE status = 'active';

                IF NOT FOUND THEN
                    RETURN QUERY SELECT 'no_active_policy'::TEXT, NULL::BIGINT;
                    RETURN;
                END IF;

                IF v_policy_currency <> p_currency THEN
                    RETURN QUERY SELECT 'unsupported_currency'::TEXT, NULL::BIGINT;
                    RETURN;
                END IF;

                -- ⚠️ NO TEMPORARY TABLE. D-029.6 closed `TEMP` on this cluster, and nothing
                -- guarantees the executor holds it; a CTE does the same work without the bet.
                --
                -- Lock first, aggregate second: the rows must not move between the total that
                -- decides the threshold and the rows that end up in the payout.
                PERFORM 1
                FROM public.affiliate_commissions
                WHERE affiliate_id = p_affiliate_id
                  AND currency = p_currency
                  AND status = 'payable'
                FOR UPDATE;

                SELECT COUNT(*), COALESCE(SUM(b.balance), 0)::BIGINT
                INTO v_count, v_total
                FROM (
                    SELECT COALESCE((SELECT SUM(e.amount_minor)
                                     FROM public.affiliate_commission_entries e
                                     WHERE e.commission_id = c.id), 0)::BIGINT AS balance
                    FROM public.affiliate_commissions c
                    WHERE c.affiliate_id = p_affiliate_id
                      AND c.currency = p_currency
                      AND c.status = 'payable'
                ) b
                WHERE b.balance > 0;

                IF v_count = 0 THEN
                    RETURN QUERY SELECT 'nothing_payable'::TEXT, NULL::BIGINT;
                    RETURN;
                END IF;

                IF v_total < v_threshold THEN
                    RETURN QUERY SELECT 'below_threshold'::TEXT, NULL::BIGINT;
                    RETURN;
                END IF;

                INSERT INTO public.affiliate_payouts (
                    public_id, affiliate_id, policy_id, status, amount_minor, currency,
                    threshold_minor_snapshot, requested_by_user_id, requested_at,
                    created_at, updated_at
                ) VALUES (
                    gen_random_uuid(), p_affiliate_id, v_policy_id, 'requested', v_total,
                    p_currency, v_threshold, p_actor_user_id, CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                ) RETURNING id INTO v_payout_id;

                -- Items, allocation entries and the status change in ONE statement: the
                -- entries are built from what the items actually recorded, so the two can
                -- never disagree about an amount.
                WITH selected AS (
                    SELECT c.id AS commission_id,
                           COALESCE((SELECT SUM(e.amount_minor)
                                     FROM public.affiliate_commission_entries e
                                     WHERE e.commission_id = c.id), 0)::BIGINT AS balance
                    FROM public.affiliate_commissions c
                    WHERE c.affiliate_id = p_affiliate_id
                      AND c.currency = p_currency
                      AND c.status = 'payable'
                ),
                inserted_items AS (
                    INSERT INTO public.affiliate_payout_items (
                        payout_id, commission_id, affiliate_id, amount_minor, currency, created_at
                    )
                    SELECT v_payout_id, s.commission_id, p_affiliate_id, s.balance,
                           p_currency, CURRENT_TIMESTAMP
                    FROM selected s
                    WHERE s.balance > 0
                    RETURNING commission_id, amount_minor
                ),
                inserted_entries AS (
                    -- The reservation itself: the balance is drawn down to zero.
                    INSERT INTO public.affiliate_commission_entries (
                        affiliate_id, commission_id, entry_type, amount_minor, currency,
                        payout_id, occurred_at, created_at
                    )
                    SELECT p_affiliate_id, i.commission_id, 'payout_allocation', -i.amount_minor,
                           p_currency, v_payout_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    FROM inserted_items i
                    RETURNING commission_id
                )
                UPDATE public.affiliate_commissions
                SET status = 'allocated', updated_at = CURRENT_TIMESTAMP
                WHERE id IN (SELECT commission_id FROM inserted_entries);

                RETURN QUERY SELECT 'requested'::TEXT, v_payout_id;
            END;
            $$;
        SQL);

        // ── 3 & 4. Reads ─────────────────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.list_affiliate_payouts(
                p_status VARCHAR(16),
                p_limit INTEGER
            )
            RETURNS TABLE (
                payout_id BIGINT,
                public_id UUID,
                affiliate_id BIGINT,
                affiliate_public_id UUID,
                payout_status VARCHAR,
                amount_minor BIGINT,
                currency VARCHAR,
                threshold_minor_snapshot BIGINT,
                item_count BIGINT,
                administrative_reference VARCHAR,
                requested_by_user_id BIGINT,
                approved_by_user_id BIGINT,
                requested_at TIMESTAMPTZ,
                approved_at TIMESTAMPTZ,
                paid_at TIMESTAMPTZ,
                rejected_at TIMESTAMPTZ,
                cancelled_at TIMESTAMPTZ
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 500 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'limit must be between 1 and 500';
                END IF;

                RETURN QUERY
                SELECT p.id, p.public_id, p.affiliate_id, a.public_id, p.status, p.amount_minor,
                       p.currency, p.threshold_minor_snapshot,
                       (SELECT COUNT(*) FROM public.affiliate_payout_items i WHERE i.payout_id = p.id),
                       p.administrative_reference, p.requested_by_user_id, p.approved_by_user_id,
                       p.requested_at, p.approved_at, p.paid_at, p.rejected_at, p.cancelled_at
                FROM public.affiliate_payouts p
                JOIN public.affiliates a ON a.id = p.affiliate_id
                WHERE p_status IS NULL OR p.status = p_status
                ORDER BY p.requested_at DESC, p.id DESC
                LIMIT p_limit;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.list_affiliate_payout_items(p_payout_id BIGINT)
            RETURNS TABLE (
                item_id BIGINT,
                commission_id BIGINT,
                commission_public_id UUID,
                order_id BIGINT,
                order_item_id BIGINT,
                amount_minor BIGINT,
                currency VARCHAR,
                commission_status VARCHAR
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                RETURN QUERY
                SELECT i.id, i.commission_id, c.public_id, c.order_id, c.order_item_id,
                       i.amount_minor, i.currency, c.status
                FROM public.affiliate_payout_items i
                JOIN public.affiliate_commissions c ON c.id = i.commission_id
                WHERE i.payout_id = p_payout_id
                ORDER BY i.id;
            END;
            $$;
        SQL);

        // ── 5. Transition ────────────────────────────────────────────────────────────
        //
        // ONE authority carries the whole state machine, including the release of a
        // cancelled or rejected reservation. Releasing must never be a separate step an
        // administrator could forget: the transition and the ledger movement live in the
        // same transaction or the money is stranded.
        //
        // ⚠️ TWO DISTINCT ADMINISTRATORS. The compare-and-swap below protects against a race
        // between two administrators on the same screen; it protects NOTHING against a single
        // administrator who requests a transfer and approves it himself. On a flow that moves
        // real money, segregation of duties is the minimum control.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.transition_affiliate_payout(
                p_payout_id BIGINT,
                p_expected_status VARCHAR(16),
                p_target_status VARCHAR(16),
                p_actor_user_id BIGINT,
                p_administrative_reference VARCHAR(64)
            )
            RETURNS TEXT
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_status VARCHAR(16);
                v_requested_by BIGINT;
                v_reference VARCHAR(64);
            BEGIN
                PERFORM public.assert_affiliate_actor_is_admin(p_actor_user_id);

                SELECT status, requested_by_user_id
                INTO v_status, v_requested_by
                FROM public.affiliate_payouts
                WHERE id = p_payout_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RETURN 'no_such_payout';
                END IF;

                -- Compare-and-swap on the status the administrator was LOOKING AT. Without it
                -- a decision taken on a stale screen silently overwrites a colleague's.
                IF v_status IS DISTINCT FROM p_expected_status THEN
                    RAISE EXCEPTION USING ERRCODE = 'AF002',
                        MESSAGE = 'the payout changed since it was read';
                END IF;

                -- The state machine, exhaustively. `paid`, `rejected` and `cancelled` have NO
                -- outgoing transition: a paid payout can never be cancelled, which is what
                -- keeps `payout_reversal` scoped to reservations that were never paid.
                IF NOT (
                    (v_status = 'requested' AND p_target_status IN ('approved', 'rejected', 'cancelled'))
                    OR (v_status = 'approved' AND p_target_status IN ('paid', 'cancelled'))
                ) THEN
                    RETURN 'illegal_transition';
                END IF;

                IF p_target_status = 'approved' THEN
                    IF v_requested_by IS NOT NULL AND v_requested_by = p_actor_user_id THEN
                        RETURN 'same_administrator_forbidden';
                    END IF;

                    UPDATE public.affiliate_payouts
                    SET status = 'approved',
                        approved_at = CURRENT_TIMESTAMP,
                        approved_by_user_id = p_actor_user_id,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = p_payout_id;

                    RETURN 'transitioned';
                END IF;

                IF p_target_status = 'paid' THEN
                    v_reference := btrim(COALESCE(p_administrative_reference, ''));

                    -- A domain rule, not a broken call contract: the caller gets a status.
                    IF v_reference = '' THEN
                        RETURN 'missing_administrative_reference';
                    END IF;

                    UPDATE public.affiliate_payouts
                    SET status = 'paid',
                        paid_at = CURRENT_TIMESTAMP,
                        administrative_reference = v_reference,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = p_payout_id;

                    UPDATE public.affiliate_commissions
                    SET status = 'paid', updated_at = CURRENT_TIMESTAMP
                    WHERE id IN (SELECT commission_id FROM public.affiliate_payout_items
                                 WHERE payout_id = p_payout_id);

                    RETURN 'transitioned';
                END IF;

                -- `rejected` / `cancelled`: the reservation is released in the SAME
                -- transaction. The amount is taken from the allocation entry itself — never
                -- recomputed, because a recomputation could diverge from what was withdrawn.
                UPDATE public.affiliate_payouts
                SET status = p_target_status,
                    rejected_at = CASE WHEN p_target_status = 'rejected' THEN CURRENT_TIMESTAMP ELSE rejected_at END,
                    cancelled_at = CASE WHEN p_target_status = 'cancelled' THEN CURRENT_TIMESTAMP ELSE cancelled_at END,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = p_payout_id;

                INSERT INTO public.affiliate_commission_entries (
                    affiliate_id, commission_id, entry_type, amount_minor, currency,
                    payout_id, occurred_at, created_at
                )
                SELECT alloc.affiliate_id, alloc.commission_id, 'payout_reversal',
                       -alloc.amount_minor, alloc.currency, p_payout_id,
                       CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                FROM public.affiliate_commission_entries alloc
                WHERE alloc.payout_id = p_payout_id
                  AND alloc.entry_type = 'payout_allocation';

                UPDATE public.affiliate_commissions
                SET status = 'payable', updated_at = CURRENT_TIMESTAMP
                WHERE id IN (SELECT commission_id FROM public.affiliate_payout_items
                             WHERE payout_id = p_payout_id);

                RETURN 'transitioned';
            END;
            $$;
        SQL);

        // ── 6. D-057 §10 — the hole P6-D3 could not yet meet ─────────────────────────
        //
        // D-057 §10 said it from the start: "commission déjà payée ⇒ solde négatif reporté et
        // auditable". P6-D3 capped every reversal at the LEDGER BALANCE, which was right as
        // long as nothing could draw that balance down — and until P6-D4 nothing could. An
        // allocated or paid commission has a zero balance, so the cap silently reduced its
        // reversal to nothing and the refunded money stayed with the affiliate.
        //
        // The fix is not an exception for allocated/paid rows; it is a correct quantity. The
        // cap belongs on WHAT REMAINS COMMISSIONABLE — the accrual minus what previous
        // refunds already reversed — which is independent of any payout. A reversal can then
        // never exceed what was earned, and an already-paid commission simply carries a
        // NEGATIVE balance, exactly as D-057 §10 requires.
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
                v_reversed_so_far BIGINT;
                v_reversible BIGINT;
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

                IF v_refund_status <> 'succeeded' THEN
                    RETURN 'refund_not_succeeded';
                END IF;

                IF NOT EXISTS (SELECT 1 FROM public.affiliate_commissions WHERE order_id = v_order_id) THEN
                    RETURN 'no_commissions';
                END IF;

                v_full := (v_order_status = 'refunded');

                FOR v_row IN
                    SELECT c.id, c.affiliate_id, c.order_item_id, c.currency,
                           c.rate_bps_snapshot, c.amount_minor, c.status
                    FROM public.affiliate_commissions c
                    WHERE c.order_id = v_order_id
                    ORDER BY c.id
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

                    -- What previous refunds already took back (negative or zero).
                    SELECT COALESCE(SUM(e.amount_minor), 0)::BIGINT INTO v_reversed_so_far
                    FROM public.affiliate_commission_entries e
                    WHERE e.commission_id = v_row.id
                      AND e.entry_type = 'refund_reversal';

                    -- What is still commissionable. NOT the ledger balance: a payout
                    -- allocation drives that to zero without cancelling the debt.
                    v_reversible := v_row.amount_minor + v_reversed_so_far;

                    IF v_reversible <= 0 THEN
                        CONTINUE;
                    END IF;

                    IF v_full THEN
                        v_reversal := v_reversible;
                    ELSE
                        v_allocated := COALESCE((p_allocation ->> v_row.order_item_id::text)::BIGINT, 0);

                        IF v_allocated < 0 THEN
                            RAISE EXCEPTION USING ERRCODE = '22023',
                                MESSAGE = 'an allocated amount cannot be negative';
                        END IF;

                        v_reversal := LEAST((v_allocated * v_row.rate_bps_snapshot) / 10000, v_reversible);
                    END IF;

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

                    -- Cancelling is only meaningful while the commission is still awaiting
                    -- payment. An `allocated` or `paid` one keeps its status and carries the
                    -- negative balance instead: it WAS paid, and pretending otherwise would
                    -- erase the very fact the ledger exists to record.
                    IF v_reversible - v_reversal = 0 AND v_row.status IN ('pending', 'payable') THEN
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

        // ── Ownership and privileges ─────────────────────────────────────────────────
        foreach ([
            'list_affiliate_payout_candidates(INTEGER)',
            'request_affiliate_payout(BIGINT, VARCHAR, BIGINT)',
            'list_affiliate_payouts(VARCHAR, INTEGER)',
            'list_affiliate_payout_items(BIGINT)',
            'transition_affiliate_payout(BIGINT, VARCHAR, VARCHAR, BIGINT, VARCHAR)',
            'apply_affiliate_refund_reversal(BIGINT, JSONB)',
        ] as $signature) {
            DB::statement('ALTER FUNCTION public.'.$signature.' OWNER TO digitrove_affiliate_executor');
            DB::statement('REVOKE ALL ON FUNCTION public.'.$signature.' FROM PUBLIC');
            DB::statement('GRANT EXECUTE ON FUNCTION public.'.$signature.' TO digitrove_runtime');
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.transition_affiliate_payout(BIGINT, VARCHAR, VARCHAR, BIGINT, VARCHAR)');
        DB::statement('DROP FUNCTION IF EXISTS public.list_affiliate_payout_items(BIGINT)');
        DB::statement('DROP FUNCTION IF EXISTS public.list_affiliate_payouts(VARCHAR, INTEGER)');
        DB::statement('DROP FUNCTION IF EXISTS public.request_affiliate_payout(BIGINT, VARCHAR, BIGINT)');
        DB::statement('DROP FUNCTION IF EXISTS public.list_affiliate_payout_candidates(INTEGER)');

        DB::statement('DROP INDEX IF EXISTS public.affiliate_commission_entries_payout_reversal_once');
        DB::statement('DROP INDEX IF EXISTS public.affiliate_commission_entries_payout_allocation_once');

        // `apply_affiliate_refund_reversal` is NOT dropped: it belongs to `000033`. Restoring
        // its P6-D3 body here would re-open the D-057 §10 hole, so the rollback leaves the
        // corrected version in place — the only honest choice, since `000033`'s own `down()`
        // drops the function outright.
    }
};
