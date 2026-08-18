<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P6-D2 — touch capture and order attribution authorities.
 *
 * Two bounded authorities, NO TRIGGER. Attribution is invoked EXPLICITLY from the
 * `OrderPaid` listener: `orders` is shared by P1/P3/P4, and a fault inside affiliate
 * logic must never be able to break order creation for code that has nothing to do
 * with affiliation.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Touch capture ─────────────────────────────────────────────────────────
        //
        // Returns a status the caller logs INTERNALLY. The HTTP response stays silent
        // either way — a visitor must never learn whether a code exists — but a policy
        // that was never activated would otherwise stay invisible until an affiliate
        // complains about never being credited.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.record_affiliate_touch(
                p_visitor_id UUID,
                p_user_id BIGINT,
                p_code VARCHAR(32),
                p_source VARCHAR(16)
            )
            RETURNS TEXT
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_code VARCHAR(32);
                v_affiliate_id BIGINT;
                v_code_id BIGINT;
                v_policy_id BIGINT;
                v_window_days INTEGER;
                v_expires_at TIMESTAMP WITH TIME ZONE;
                v_inserted INTEGER;
            BEGIN
                IF p_source NOT IN ('code', 'link') THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid source';
                END IF;

                IF p_visitor_id IS NULL AND p_user_id IS NULL THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'touch requires visitor or user identity';
                END IF;

                -- Codes are generated `upper(...)` (000031) and `affiliate_codes_format_check`
                -- pins the stored form to `^[A-Z0-9]{4,32}$`. Normalising the PARAMETER alone is
                -- therefore exact AND keeps `affiliate_codes_code_unique` usable — wrapping the
                -- COLUMN in `upper()` would only disable the index.
                v_code := upper(btrim(p_code));

                SELECT c.id, c.affiliate_id INTO v_code_id, v_affiliate_id
                FROM public.affiliate_codes c
                JOIN public.affiliates a ON c.affiliate_id = a.id
                WHERE c.code = v_code
                  AND c.is_active = true
                  AND a.status = 'active';

                IF NOT FOUND THEN
                    RETURN 'no_such_code';
                END IF;

                SELECT id, attribution_window_days INTO v_policy_id, v_window_days
                FROM public.affiliate_program_policies
                WHERE status = 'active';

                IF NOT FOUND THEN
                    RETURN 'no_active_policy';
                END IF;

                v_expires_at := CURRENT_TIMESTAMP + (v_window_days || ' days')::interval;

                -- Idempotence guard. Without it a page-refresh loop writes an unbounded number
                -- of touches for one visitor. Single-statement `WHERE NOT EXISTS` so the check
                -- and the write share one snapshot; `IS NOT DISTINCT FROM` because a guest touch
                -- has `user_id IS NULL` on both sides and `=` would never match there.
                --
                -- Deliberately NOT a unique constraint (arbitrated): under READ COMMITTED two
                -- simultaneous requests can still both insert. That residue is harmless — the
                -- resolver takes `LIMIT 1` and both rows name the same affiliate and code.
                INSERT INTO public.affiliate_touches (
                    affiliate_id, affiliate_code_id, visitor_id, user_id,
                    source, occurred_at, expires_at, created_at, updated_at
                )
                SELECT
                    v_affiliate_id, v_code_id, p_visitor_id, p_user_id,
                    p_source, CURRENT_TIMESTAMP, v_expires_at, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM public.affiliate_touches t
                    WHERE t.visitor_id IS NOT DISTINCT FROM p_visitor_id
                      AND t.user_id    IS NOT DISTINCT FROM p_user_id
                      AND t.affiliate_code_id = v_code_id
                      AND t.source = p_source
                      AND t.expires_at > CURRENT_TIMESTAMP
                );

                GET DIAGNOSTICS v_inserted = ROW_COUNT;

                IF v_inserted = 0 THEN
                    RETURN 'already_active';
                END IF;

                RETURN 'recorded';
            END;
            $$;
        SQL);
        DB::statement('ALTER FUNCTION public.record_affiliate_touch(UUID, BIGINT, VARCHAR, VARCHAR) OWNER TO digitrove_affiliate_executor');
        DB::statement('REVOKE ALL ON FUNCTION public.record_affiliate_touch(UUID, BIGINT, VARCHAR, VARCHAR) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION public.record_affiliate_touch(UUID, BIGINT, VARCHAR, VARCHAR) TO digitrove_runtime');

        // ── 2. Order attribution ─────────────────────────────────────────────────────
        //
        // An ORDINARY function taking an order id, not a trigger. It reads the order's own
        // identity and `placed_at` itself, so the eligibility window stays anchored to WHEN
        // THE ORDER WAS PLACED, never to when the listener happened to run — a queue delay
        // must not change who gets credited.
        //
        // That read is a NEW privilege requirement, measured rather than assumed: the trigger
        // form received `NEW` for free, so it needed nothing on `orders`. `digitrove_affiliate_
        // executor` owns the affiliate block and NOTHING else, so without this the authority
        // fails `42501 permission denied for table orders`. Granted per COLUMN, and only the
        // four the function actually names — never the customer e-mail, never an amount.
        // Precedent: `000022` grants `SELECT` on `payments`/`refunds` to the CRM executor and
        // revokes it in `down()`.
        DB::statement('GRANT SELECT (id, visitor_id, user_id, placed_at) ON public.orders TO digitrove_affiliate_executor');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.resolve_affiliate_attribution(p_order_id BIGINT)
            RETURNS TEXT
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_visitor_id UUID;
                v_user_id BIGINT;
                v_placed_at TIMESTAMP WITH TIME ZONE;
                v_policy_id BIGINT;
                v_match_id BIGINT;
                v_affiliate_id BIGINT;
                v_code_id BIGINT;
                v_matched_by VARCHAR(16);
                v_inserted INTEGER;
            BEGIN
                SELECT o.visitor_id, o.user_id, o.placed_at
                INTO v_visitor_id, v_user_id, v_placed_at
                FROM public.orders o
                WHERE o.id = p_order_id;

                IF NOT FOUND THEN
                    RETURN 'no_such_order';
                END IF;

                -- `affiliate_attributions_order_id_unique` already makes a second attribution
                -- impossible, but the listener is at-least-once: checking first lets a
                -- redelivery report honestly instead of looking like a fresh attribution.
                IF EXISTS (SELECT 1 FROM public.affiliate_attributions WHERE order_id = p_order_id) THEN
                    RETURN 'already_attributed';
                END IF;

                SELECT id INTO v_policy_id
                FROM public.affiliate_program_policies
                WHERE status = 'active';

                IF NOT FOUND THEN
                    RETURN 'no_active_policy';
                END IF;

                -- `code_then_last_click`: an explicitly typed code outranks any click, then the
                -- most recent touch wins, `id` breaking an exact timestamp tie.
                SELECT t.id, t.affiliate_id, t.affiliate_code_id,
                       CASE WHEN t.source = 'code' THEN 'code' ELSE 'last_click' END
                INTO v_match_id, v_affiliate_id, v_code_id, v_matched_by
                FROM public.affiliate_touches t
                JOIN public.affiliates a ON t.affiliate_id = a.id
                WHERE (
                    (v_visitor_id IS NOT NULL AND t.visitor_id = v_visitor_id)
                    OR
                    (v_user_id    IS NOT NULL AND t.user_id    = v_user_id)
                )
                  AND t.occurred_at <= v_placed_at
                  AND t.expires_at  >= v_placed_at
                  AND a.status = 'active'
                ORDER BY
                  CASE WHEN t.source = 'code' THEN 1 ELSE 2 END ASC,
                  t.occurred_at DESC,
                  t.id DESC
                LIMIT 1;

                IF NOT FOUND THEN
                    RETURN 'no_match';
                END IF;

                INSERT INTO public.affiliate_attributions (
                    order_id, affiliate_id, affiliate_code_id, affiliate_touch_id,
                    policy_id, matched_by, attributed_at, created_at, updated_at
                ) VALUES (
                    p_order_id, v_affiliate_id, v_code_id,
                    -- A click-matched row must name its touch (`..._touch_required_check`); a
                    -- typed code needs none, the code itself being the evidence.
                    CASE WHEN v_matched_by = 'last_click' THEN v_match_id ELSE NULL END,
                    v_policy_id, v_matched_by,
                    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
                ON CONFLICT ON CONSTRAINT affiliate_attributions_order_id_unique DO NOTHING;

                GET DIAGNOSTICS v_inserted = ROW_COUNT;

                IF v_inserted = 0 THEN
                    RETURN 'already_attributed';
                END IF;

                RETURN 'attributed';
            END;
            $$;
        SQL);
        DB::statement('ALTER FUNCTION public.resolve_affiliate_attribution(BIGINT) OWNER TO digitrove_affiliate_executor');
        DB::statement('REVOKE ALL ON FUNCTION public.resolve_affiliate_attribution(BIGINT) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION public.resolve_affiliate_attribution(BIGINT) TO digitrove_runtime');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.resolve_affiliate_attribution(BIGINT)');
        DB::statement('DROP FUNCTION IF EXISTS public.record_affiliate_touch(UUID, BIGINT, VARCHAR, VARCHAR)');

        // Restores the `000031` frontier exactly: the affiliate executor reads nothing
        // outside its own block again.
        DB::statement('REVOKE SELECT (id, visitor_id, user_id, placed_at) ON public.orders FROM digitrove_affiliate_executor');
    }
};
