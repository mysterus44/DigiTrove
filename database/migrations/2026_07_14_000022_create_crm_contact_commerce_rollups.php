<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE crm_contact_commerce_rollups (
                contact_id BIGINT NOT NULL,
                currency VARCHAR(3) NOT NULL,
                acquired_orders_count BIGINT NOT NULL,
                gross_revenue_minor BIGINT NOT NULL,
                refunded_amount_minor BIGINT NOT NULL,
                net_revenue_minor BIGINT GENERATED ALWAYS AS (gross_revenue_minor - refunded_amount_minor) STORED,
                first_acquired_at TIMESTAMPTZ NOT NULL,
                last_acquired_at TIMESTAMPTZ NOT NULL,
                last_refunded_at TIMESTAMPTZ NULL,
                calculation_version SMALLINT NOT NULL,
                refreshed_at TIMESTAMPTZ NOT NULL,
                
                PRIMARY KEY (contact_id, currency),
                CONSTRAINT crm_contact_commerce_rollups_contact_fk FOREIGN KEY (contact_id) REFERENCES crm_contacts(id) ON DELETE RESTRICT,
                CONSTRAINT crm_contact_commerce_rollups_currency_check CHECK (currency ~ '^[A-Z]{3}$'),
                CONSTRAINT crm_contact_commerce_rollups_orders_count_check CHECK (acquired_orders_count > 0),
                CONSTRAINT crm_contact_commerce_rollups_gross_revenue_check CHECK (gross_revenue_minor >= 0),
                CONSTRAINT crm_contact_commerce_rollups_refunded_amount_check CHECK (refunded_amount_minor >= 0 AND refunded_amount_minor <= gross_revenue_minor),
                CONSTRAINT crm_contact_commerce_rollups_calculation_version_check CHECK (calculation_version > 0),
                CONSTRAINT crm_contact_commerce_rollups_dates_check CHECK (first_acquired_at <= last_acquired_at)
            );

            DROP FUNCTION IF EXISTS refresh_crm_contact_commerce_rollup(BIGINT, VARCHAR);

            CREATE OR REPLACE FUNCTION refresh_crm_contact_commerce_rollup(
                p_contact_id BIGINT,
                p_currency VARCHAR
            )
            RETURNS TABLE (
                contact_id BIGINT,
                currency VARCHAR,
                exists_after_refresh BOOLEAN,
                acquired_orders_count BIGINT,
                gross_revenue_minor BIGINT,
                refunded_amount_minor BIGINT,
                net_revenue_minor BIGINT,
                first_acquired_at TIMESTAMPTZ,
                last_acquired_at TIMESTAMPTZ,
                last_refunded_at TIMESTAMPTZ,
                calculation_version SMALLINT,
                refreshed_at TIMESTAMPTZ
            )
            SECURITY DEFINER
            SET search_path = public, pg_temp
            LANGUAGE plpgsql
            AS $$
            DECLARE
                v_lock_key BIGINT;
                v_calc_version SMALLINT := 1;
                v_count BIGINT;
                v_gross_num NUMERIC;
                v_refunded_num NUMERIC;
                v_gross BIGINT;
                v_refunded BIGINT;
                v_first_acquired TIMESTAMPTZ;
                v_last_acquired TIMESTAMPTZ;
                v_last_refunded TIMESTAMPTZ;
            BEGIN
                IF p_contact_id IS NULL OR p_contact_id <= 0 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid contact_id';
                END IF;

                IF p_currency IS NULL OR p_currency !~ '^[A-Z]{3}$' THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid currency';
                END IF;

                IF NOT EXISTS (SELECT 1 FROM public.crm_contacts AS cc WHERE cc.id = p_contact_id) THEN
                    RAISE EXCEPTION USING ERRCODE = '23503', MESSAGE = 'contact does not exist';
                END IF;

                -- Deterministic advisory lock based on contact_id and hash of currency
                v_lock_key := ('x' || substr(md5(p_contact_id::text || p_currency), 1, 16))::bit(64)::bigint;
                PERFORM pg_advisory_xact_lock(v_lock_key);

                WITH eligible_orders AS (
                    SELECT o.id AS order_id, o.total_minor AS order_total, o.paid_at AS order_paid_at
                    FROM public.crm_order_attributions AS coa
                    INNER JOIN public.orders AS o ON o.id = coa.order_id
                    WHERE coa.contact_id = p_contact_id
                      AND o.currency = p_currency
                      AND o.status IN ('paid', 'partially_refunded', 'refunded')
                      AND o.paid_at IS NOT NULL
                ),
                succeeded_refunds_by_order AS (
                    SELECT 
                        p.order_id AS refund_order_id,
                        SUM(r.amount_minor)::numeric AS refunded_amount_num,
                        MAX(r.succeeded_at) AS last_succeeded_at
                    FROM eligible_orders AS eo
                    INNER JOIN public.payments AS p ON p.order_id = eo.order_id AND p.status = 'succeeded'
                    INNER JOIN public.refunds AS r ON r.payment_id = p.id AND r.status = 'succeeded'
                    GROUP BY p.order_id
                )
                SELECT 
                    COUNT(eo.order_id),
                    COALESCE(SUM(eo.order_total::numeric), 0),
                    COALESCE(SUM(sro.refunded_amount_num), 0),
                    MIN(eo.order_paid_at),
                    MAX(eo.order_paid_at),
                    MAX(sro.last_succeeded_at)
                INTO
                    v_count,
                    v_gross_num,
                    v_refunded_num,
                    v_first_acquired,
                    v_last_acquired,
                    v_last_refunded
                FROM eligible_orders AS eo
                LEFT JOIN succeeded_refunds_by_order AS sro ON sro.refund_order_id = eo.order_id;

                IF v_count = 0 THEN
                    DELETE FROM public.crm_contact_commerce_rollups AS ccr
                    WHERE ccr.contact_id = p_contact_id 
                      AND ccr.currency = p_currency;
                      
                    RETURN QUERY SELECT 
                        p_contact_id, p_currency::varchar, FALSE, 
                        NULL::BIGINT, NULL::BIGINT, NULL::BIGINT, NULL::BIGINT, 
                        NULL::TIMESTAMPTZ, NULL::TIMESTAMPTZ, NULL::TIMESTAMPTZ, 
                        NULL::SMALLINT, NULL::TIMESTAMPTZ;
                    RETURN;
                END IF;

                -- Overflow checks
                IF v_gross_num < 0 OR v_gross_num > 9223372036854775807 THEN
                    RAISE EXCEPTION USING ERRCODE = '22003', MESSAGE = 'gross revenue overflow';
                END IF;
                IF v_refunded_num < 0 OR v_refunded_num > 9223372036854775807 THEN
                    RAISE EXCEPTION USING ERRCODE = '22003', MESSAGE = 'refunded amount overflow';
                END IF;
                IF v_refunded_num > v_gross_num THEN
                    RAISE EXCEPTION USING ERRCODE = '22003', MESSAGE = 'refunded amount exceeds gross revenue';
                END IF;

                v_gross := v_gross_num::BIGINT;
                v_refunded := v_refunded_num::BIGINT;

                INSERT INTO public.crm_contact_commerce_rollups (
                    contact_id,
                    currency,
                    acquired_orders_count,
                    gross_revenue_minor,
                    refunded_amount_minor,
                    first_acquired_at,
                    last_acquired_at,
                    last_refunded_at,
                    calculation_version,
                    refreshed_at
                ) VALUES (
                    p_contact_id,
                    p_currency,
                    v_count,
                    v_gross,
                    v_refunded,
                    v_first_acquired,
                    v_last_acquired,
                    v_last_refunded,
                    v_calc_version,
                    clock_timestamp()
                )
                ON CONFLICT ON CONSTRAINT crm_contact_commerce_rollups_pkey DO UPDATE SET
                    acquired_orders_count = EXCLUDED.acquired_orders_count,
                    gross_revenue_minor = EXCLUDED.gross_revenue_minor,
                    refunded_amount_minor = EXCLUDED.refunded_amount_minor,
                    first_acquired_at = EXCLUDED.first_acquired_at,
                    last_acquired_at = EXCLUDED.last_acquired_at,
                    last_refunded_at = EXCLUDED.last_refunded_at,
                    calculation_version = EXCLUDED.calculation_version,
                    refreshed_at = EXCLUDED.refreshed_at;
                    
                RETURN QUERY SELECT 
                    ccr.contact_id, 
                    ccr.currency::varchar, 
                    TRUE, 
                    ccr.acquired_orders_count, 
                    ccr.gross_revenue_minor, 
                    ccr.refunded_amount_minor, 
                    ccr.net_revenue_minor, 
                    ccr.first_acquired_at, 
                    ccr.last_acquired_at, 
                    ccr.last_refunded_at, 
                    ccr.calculation_version, 
                    ccr.refreshed_at
                FROM public.crm_contact_commerce_rollups AS ccr
                WHERE ccr.contact_id = p_contact_id AND ccr.currency = p_currency;
            END;
            $$;

            ALTER TABLE crm_contact_commerce_rollups OWNER TO digitrove_crm_executor;
            ALTER FUNCTION refresh_crm_contact_commerce_rollup(BIGINT, VARCHAR) OWNER TO digitrove_crm_executor;

            REVOKE ALL ON TABLE crm_contact_commerce_rollups FROM PUBLIC;
            REVOKE ALL ON FUNCTION refresh_crm_contact_commerce_rollup(BIGINT, VARCHAR) FROM PUBLIC;

            REVOKE ALL ON TABLE crm_contact_commerce_rollups FROM digitrove_runtime;
            REVOKE ALL ON FUNCTION refresh_crm_contact_commerce_rollup(BIGINT, VARCHAR) FROM digitrove_runtime;

            GRANT SELECT ON payments TO digitrove_crm_executor;
            GRANT SELECT ON refunds TO digitrove_crm_executor;
        SQL);
    }

    public function down(): void
    {
        // Rollback restores the exact 000021 privilege boundary: every GRANT issued
        // by up() must be revoked here, otherwise digitrove_crm_executor keeps SELECT
        // on payments/refunds after the phase is rolled back. Dropping the function
        // and table alone would leave those P6-A1.1 privileges behind (D-046.1).
        DB::unprepared(<<<'SQL'
            REVOKE SELECT ON TABLE public.payments FROM digitrove_crm_executor;
            REVOKE SELECT ON TABLE public.refunds FROM digitrove_crm_executor;

            DROP FUNCTION IF EXISTS public.refresh_crm_contact_commerce_rollup(BIGINT, VARCHAR);
            DROP TABLE IF EXISTS public.crm_contact_commerce_rollups;
        SQL);
    }
};
