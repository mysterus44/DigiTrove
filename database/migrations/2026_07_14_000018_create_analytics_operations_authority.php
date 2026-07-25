<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P5-A2 — dimensionally correct product analytics and operations authority.
 *
 * The structural half separates currency-free engagement from commercial
 * product metrics and preserves the purchased product identity independently
 * from the nullable live catalogue reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertStructuralBackfillsAreSafe();
        $this->createProductEngagementRollup();
        $this->correctCommercialProductRollup();
        $this->addPurchasedProductIdentity();
        $this->installAnalyticsOperationsAuthority();
    }

    public function down(): void
    {
        $this->removeAnalyticsOperationsAuthority();

        DB::statement('DROP TRIGGER IF EXISTS order_items_00_prevent_purchased_product_mutation_trigger ON public.order_items');
        DB::statement('DROP FUNCTION IF EXISTS public.prevent_order_item_product_snapshot_mutation()');
        DB::statement('ALTER TABLE public.order_items DROP CONSTRAINT IF EXISTS order_items_purchased_product_id_positive_check');
        DB::statement('ALTER TABLE public.order_items DROP COLUMN IF EXISTS purchased_product_id');

        DB::statement('ALTER TABLE public.daily_product_stats DROP CONSTRAINT IF EXISTS daily_product_stats_metrics_non_negative_check');
        DB::statement('ALTER TABLE public.daily_product_stats ADD COLUMN views BIGINT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE public.daily_product_stats ADD COLUMN add_to_carts BIGINT NOT NULL DEFAULT 0');
        DB::statement(<<<'SQL'
            ALTER TABLE public.daily_product_stats
            ADD CONSTRAINT daily_product_stats_metrics_non_negative_check
            CHECK (
                views >= 0
                AND add_to_carts >= 0
                AND purchases >= 0
                AND revenue_minor >= 0
            )
            SQL);

        DB::statement('DROP TABLE IF EXISTS public.daily_product_engagement_stats');
    }

    private function assertStructuralBackfillsAreSafe(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM public.order_items
                    WHERE product_id IS NULL
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'P5-A2 cannot backfill purchased product identity from NULL product_id';
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM public.daily_product_stats
                    WHERE views <> 0 OR add_to_carts <> 0
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'P5-A2 cannot discard populated currency-bound engagement metrics';
                END IF;
            END
            $$;
            SQL);
    }

    private function createProductEngagementRollup(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.daily_product_engagement_stats (
                day DATE NOT NULL,
                product_id BIGINT NOT NULL,
                views BIGINT NOT NULL DEFAULT 0,
                add_to_carts BIGINT NOT NULL DEFAULT 0,
                updated_at TIMESTAMPTZ NOT NULL,

                CONSTRAINT daily_product_engagement_stats_pkey
                    PRIMARY KEY (day, product_id),
                CONSTRAINT daily_product_engagement_stats_product_id_positive_check
                    CHECK (product_id > 0),
                CONSTRAINT daily_product_engagement_stats_metrics_non_negative_check
                    CHECK (views >= 0 AND add_to_carts >= 0)
            );
            SQL);

        // P4-B0 grants the runtime DML on future tables by default. Analytics
        // projections stay dark and are writable only through the P5-A2 authority.
        DB::statement('REVOKE ALL PRIVILEGES ON TABLE public.daily_product_engagement_stats FROM PUBLIC, digitrove_runtime');
    }

    private function correctCommercialProductRollup(): void
    {
        DB::statement('ALTER TABLE public.daily_product_stats DROP CONSTRAINT daily_product_stats_metrics_non_negative_check');
        DB::statement('ALTER TABLE public.daily_product_stats DROP COLUMN views');
        DB::statement('ALTER TABLE public.daily_product_stats DROP COLUMN add_to_carts');
        DB::statement(<<<'SQL'
            ALTER TABLE public.daily_product_stats
            ADD CONSTRAINT daily_product_stats_metrics_non_negative_check
            CHECK (purchases >= 0 AND revenue_minor >= 0)
            SQL);
    }

    private function addPurchasedProductIdentity(): void
    {
        DB::statement('ALTER TABLE public.order_items ADD COLUMN purchased_product_id BIGINT');

        // The P3B trigger intentionally rejects every commercial UPDATE. Drop
        // and recreate it inside this migration transaction so the one-time,
        // prevalidated backfill can run without opening a committed gap.
        DB::statement('DROP TRIGGER order_items_enforce_immutability_trigger ON public.order_items');
        DB::statement('UPDATE public.order_items SET purchased_product_id = product_id');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement(<<<'SQL'
            CREATE TRIGGER order_items_enforce_immutability_trigger
            BEFORE UPDATE ON public.order_items
            FOR EACH ROW
            EXECUTE FUNCTION public.enforce_order_items_immutability()
            SQL);

        DB::statement('ALTER TABLE public.order_items ALTER COLUMN purchased_product_id SET NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE public.order_items
            ADD CONSTRAINT order_items_purchased_product_id_positive_check
            CHECK (purchased_product_id > 0)
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.prevent_order_item_product_snapshot_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
                IF NEW.purchased_product_id IS DISTINCT FROM OLD.purchased_product_id THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'order_items purchased product identity is immutable';
                END IF;

                RETURN NEW;
            END;
            $$;

            -- PostgreSQL executes same-kind triggers alphabetically. The `00`
            -- prefix makes this focused diagnostic run before the older broad
            -- order_items immutability trigger.
            CREATE TRIGGER order_items_00_prevent_purchased_product_mutation_trigger
            BEFORE UPDATE OF purchased_product_id ON public.order_items
            FOR EACH ROW
            EXECUTE FUNCTION public.prevent_order_item_product_snapshot_mutation();
            SQL);

        DB::statement('REVOKE EXECUTE ON FUNCTION public.prevent_order_item_product_snapshot_mutation() FROM PUBLIC, digitrove_runtime');
    }

    private function installAnalyticsOperationsAuthority(): void
    {
        $this->assertOperationsRolesProvisioned();

        $database = $this->quoteIdentifier((string) DB::selectOne('SELECT current_database() AS name')->name);
        DB::statement("GRANT CONNECT ON DATABASE {$database} TO digitrove_analytics_worker");
        DB::statement("REVOKE TEMPORARY ON DATABASE {$database} FROM digitrove_analytics_worker, digitrove_analytics_rollup_executor");
        DB::statement('REVOKE CREATE ON SCHEMA public FROM digitrove_analytics_worker, digitrove_analytics_rollup_executor');
        DB::statement('GRANT USAGE ON SCHEMA public TO digitrove_analytics_worker, digitrove_analytics_rollup_executor');
        DB::statement('REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM digitrove_analytics_worker');
        DB::statement('REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public FROM digitrove_analytics_worker');
        DB::statement('REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM digitrove_analytics_rollup_executor');
        DB::statement('REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public FROM digitrove_analytics_rollup_executor');

        DB::statement(<<<'SQL'
            GRANT SELECT ON TABLE
                public.orders,
                public.order_items,
                public.payments,
                public.refunds,
                public.events
            TO digitrove_analytics_rollup_executor
            SQL);
        DB::statement(<<<'SQL'
            GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE
                public.daily_sales_stats,
                public.daily_product_stats,
                public.daily_product_engagement_stats,
                public.daily_funnel_stats
            TO digitrove_analytics_rollup_executor
            SQL);

        $this->createAuthoritativeRollupFunction();
        $this->createPartitionOperationsFunctions();

        DB::statement('REVOKE ALL PRIVILEGES ON TABLE public.daily_product_engagement_stats FROM PUBLIC, digitrove_runtime, digitrove_analytics_worker');
        DB::statement('REVOKE EXECUTE ON FUNCTION public.refresh_authoritative_daily_analytics(date) FROM PUBLIC, digitrove_runtime');
        DB::statement('REVOKE EXECUTE ON FUNCTION public.ensure_analytics_events_month_partition(date) FROM PUBLIC, digitrove_runtime');
        DB::statement('REVOKE EXECUTE ON FUNCTION public.audit_analytics_event_partitions() FROM PUBLIC, digitrove_runtime');
        DB::statement('GRANT EXECUTE ON FUNCTION public.refresh_authoritative_daily_analytics(date) TO digitrove_analytics_worker');
        DB::statement('GRANT EXECUTE ON FUNCTION public.ensure_analytics_events_month_partition(date) TO digitrove_analytics_worker');
        DB::statement('GRANT EXECUTE ON FUNCTION public.audit_analytics_event_partitions() TO digitrove_analytics_worker');
    }

    private function createAuthoritativeRollupFunction(): void
    {
        DB::statement('GRANT CREATE ON SCHEMA public TO digitrove_analytics_rollup_executor');
        DB::statement('SET ROLE digitrove_analytics_rollup_executor');

        try {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.refresh_authoritative_daily_analytics(p_day DATE)
                RETURNS JSONB
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = pg_catalog, public
                SET timezone = 'UTC'
                AS $$
                DECLARE
                    v_start TIMESTAMPTZ;
                    v_end TIMESTAMPTZ;
                    v_now TIMESTAMPTZ := clock_timestamp();
                    v_sales_rows BIGINT;
                    v_product_rows BIGINT;
                    v_engagement_rows BIGINT;
                    v_funnel_rows BIGINT;
                BEGIN
                    IF p_day IS NULL OR p_day > CURRENT_DATE THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'analytics rollup day must be present and not in the future';
                    END IF;

                    IF current_setting('transaction_isolation') <> 'repeatable read' THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '25001',
                            MESSAGE = 'analytics rollup requires a repeatable read transaction';
                    END IF;

                    v_start := p_day::TIMESTAMP AT TIME ZONE 'UTC';
                    v_end := (p_day + 1)::TIMESTAMP AT TIME ZONE 'UTC';

                    PERFORM pg_advisory_xact_lock(hashtextextended('digitrove:analytics-rollup:' || p_day::TEXT, 0));

                    DELETE FROM public.daily_sales_stats WHERE day = p_day;
                    WITH paid_orders AS (
                        SELECT
                            o.currency,
                            count(*)::BIGINT AS orders_count,
                            coalesce(sum(o.subtotal_minor), 0)::BIGINT AS gross_revenue_minor,
                            coalesce(sum(o.discount_minor), 0)::BIGINT AS discount_minor,
                            coalesce(sum(o.tax_minor), 0)::BIGINT AS tax_minor
                        FROM public.orders AS o
                        WHERE o.status IN ('paid', 'partially_refunded', 'refunded')
                          AND o.paid_at >= v_start
                          AND o.paid_at < v_end
                        GROUP BY o.currency
                    ),
                    succeeded_refunds AS (
                        SELECT
                            p.currency,
                            coalesce(sum(r.amount_minor), 0)::BIGINT AS refunds_minor
                        FROM public.refunds AS r
                        JOIN public.payments AS p ON p.id = r.payment_id
                        WHERE r.status = 'succeeded'
                          AND r.succeeded_at >= v_start
                          AND r.succeeded_at < v_end
                        GROUP BY p.currency
                    )
                    INSERT INTO public.daily_sales_stats (
                        day,
                        currency,
                        orders_count,
                        gross_revenue_minor,
                        discount_minor,
                        tax_minor,
                        refunds_minor,
                        net_revenue_minor,
                        average_order_minor,
                        updated_at
                    )
                    SELECT
                        p_day,
                        coalesce(o.currency, r.currency),
                        coalesce(o.orders_count, 0),
                        coalesce(o.gross_revenue_minor, 0),
                        coalesce(o.discount_minor, 0),
                        coalesce(o.tax_minor, 0),
                        coalesce(r.refunds_minor, 0),
                        coalesce(o.gross_revenue_minor, 0)
                            - coalesce(o.discount_minor, 0)
                            + coalesce(o.tax_minor, 0)
                            - coalesce(r.refunds_minor, 0),
                        CASE
                            WHEN coalesce(o.orders_count, 0) = 0 THEN 0
                            ELSE (
                                o.gross_revenue_minor - o.discount_minor + o.tax_minor
                            ) / o.orders_count
                        END,
                        v_now
                    FROM paid_orders AS o
                    FULL OUTER JOIN succeeded_refunds AS r USING (currency)
                    ON CONFLICT (day, currency) DO UPDATE SET
                        orders_count = EXCLUDED.orders_count,
                        gross_revenue_minor = EXCLUDED.gross_revenue_minor,
                        discount_minor = EXCLUDED.discount_minor,
                        tax_minor = EXCLUDED.tax_minor,
                        refunds_minor = EXCLUDED.refunds_minor,
                        net_revenue_minor = EXCLUDED.net_revenue_minor,
                        average_order_minor = EXCLUDED.average_order_minor,
                        updated_at = EXCLUDED.updated_at;
                    GET DIAGNOSTICS v_sales_rows = ROW_COUNT;

                    DELETE FROM public.daily_product_stats WHERE day = p_day;
                    INSERT INTO public.daily_product_stats (
                        day,
                        product_id,
                        currency,
                        purchases,
                        revenue_minor,
                        updated_at
                    )
                    SELECT
                        p_day,
                        oi.purchased_product_id,
                        o.currency,
                        sum(oi.quantity)::BIGINT,
                        sum(oi.line_total_minor)::BIGINT,
                        v_now
                    FROM public.orders AS o
                    JOIN public.order_items AS oi ON oi.order_id = o.id
                    WHERE o.status IN ('paid', 'partially_refunded', 'refunded')
                      AND o.paid_at >= v_start
                      AND o.paid_at < v_end
                    GROUP BY oi.purchased_product_id, o.currency
                    ON CONFLICT (day, product_id, currency) DO UPDATE SET
                        purchases = EXCLUDED.purchases,
                        revenue_minor = EXCLUDED.revenue_minor,
                        updated_at = EXCLUDED.updated_at;
                    GET DIAGNOSTICS v_product_rows = ROW_COUNT;

                    DELETE FROM public.daily_product_engagement_stats WHERE day = p_day;
                    INSERT INTO public.daily_product_engagement_stats (
                        day,
                        product_id,
                        views,
                        add_to_carts,
                        updated_at
                    )
                    SELECT
                        p_day,
                        e.entity_id,
                        count(*)::BIGINT,
                        0,
                        v_now
                    FROM public.events AS e
                    WHERE e.event_name = 'product_view'
                      AND e.entity_type = 'product'
                      AND e.entity_id IS NOT NULL
                      AND e.occurred_at >= v_start
                      AND e.occurred_at < v_end
                    GROUP BY e.entity_id
                    ON CONFLICT (day, product_id) DO UPDATE SET
                        views = EXCLUDED.views,
                        add_to_carts = EXCLUDED.add_to_carts,
                        updated_at = EXCLUDED.updated_at;
                    GET DIAGNOSTICS v_engagement_rows = ROW_COUNT;

                    DELETE FROM public.daily_funnel_stats WHERE day = p_day;
                    INSERT INTO public.daily_funnel_stats (
                        day,
                        visitors,
                        sessions,
                        product_views,
                        add_to_carts,
                        checkouts,
                        purchases,
                        new_customers,
                        updated_at
                    )
                    SELECT
                        p_day,
                        (
                            SELECT count(DISTINCT e.visitor_id)::BIGINT
                            FROM public.events AS e
                            WHERE e.visitor_id IS NOT NULL
                              AND e.occurred_at >= v_start
                              AND e.occurred_at < v_end
                        ),
                        (
                            SELECT count(DISTINCT e.session_id)::BIGINT
                            FROM public.events AS e
                            WHERE e.session_id IS NOT NULL
                              AND e.occurred_at >= v_start
                              AND e.occurred_at < v_end
                        ),
                        (
                            SELECT count(*)::BIGINT
                            FROM public.events AS e
                            WHERE e.event_name = 'product_view'
                              AND e.occurred_at >= v_start
                              AND e.occurred_at < v_end
                        ),
                        0,
                        (
                            SELECT count(*)::BIGINT
                            FROM public.orders AS o
                            WHERE o.placed_at >= v_start
                              AND o.placed_at < v_end
                        ),
                        (
                            SELECT count(*)::BIGINT
                            FROM public.orders AS o
                            WHERE o.status IN ('paid', 'partially_refunded', 'refunded')
                              AND o.paid_at >= v_start
                              AND o.paid_at < v_end
                        ),
                        (
                            SELECT count(*)::BIGINT
                            FROM (
                                SELECT o.user_id
                                FROM public.orders AS o
                                WHERE o.user_id IS NOT NULL
                                  AND o.status IN ('paid', 'partially_refunded', 'refunded')
                                  AND o.paid_at IS NOT NULL
                                GROUP BY o.user_id
                                HAVING min(o.paid_at) >= v_start
                                   AND min(o.paid_at) < v_end
                            ) AS first_paid_orders
                        ),
                        v_now
                    ON CONFLICT (day) DO UPDATE SET
                        visitors = EXCLUDED.visitors,
                        sessions = EXCLUDED.sessions,
                        product_views = EXCLUDED.product_views,
                        add_to_carts = EXCLUDED.add_to_carts,
                        checkouts = EXCLUDED.checkouts,
                        purchases = EXCLUDED.purchases,
                        new_customers = EXCLUDED.new_customers,
                        updated_at = EXCLUDED.updated_at;
                    GET DIAGNOSTICS v_funnel_rows = ROW_COUNT;

                    RETURN jsonb_build_object(
                        'day', p_day,
                        'daily_sales_rows', v_sales_rows,
                        'daily_product_rows', v_product_rows,
                        'daily_product_engagement_rows', v_engagement_rows,
                        'daily_funnel_rows', v_funnel_rows
                    );
                END;
                $$;
                SQL);
        } finally {
            DB::statement('RESET ROLE');
            DB::statement('REVOKE CREATE ON SCHEMA public FROM digitrove_analytics_rollup_executor');
        }
    }

    private function createPartitionOperationsFunctions(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.ensure_analytics_events_month_partition(p_month DATE)
            RETURNS JSONB
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            SET timezone = 'UTC'
            AS $$
            DECLARE
                v_month DATE;
                v_next DATE;
                v_name TEXT;
                v_qualified REGCLASS;
                v_bound TEXT;
                v_expected_bound TEXT;
                v_default_rows BIGINT;
            BEGIN
                IF p_month IS NULL OR p_month <> date_trunc('month', p_month)::DATE THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'analytics partition month must be the first day of a month';
                END IF;

                v_month := p_month;
                v_next := (p_month + INTERVAL '1 month')::DATE;

                IF v_month < (date_trunc('month', CURRENT_DATE) - INTERVAL '60 months')::DATE
                    OR v_month > (date_trunc('month', CURRENT_DATE) + INTERVAL '60 months')::DATE
                THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'analytics partition month is outside the bounded operations window';
                END IF;

                v_name := format('events_y%sm%s', to_char(v_month, 'YYYY'), to_char(v_month, 'MM'));
                PERFORM pg_advisory_xact_lock(hashtextextended('digitrove:events-partition:' || v_month::TEXT, 0));
                v_qualified := to_regclass(format('public.%I', v_name));

                IF v_qualified IS NOT NULL THEN
                    SELECT pg_get_expr(c.relpartbound, c.oid)
                    INTO v_bound
                    FROM pg_class AS c
                    JOIN pg_inherits AS i ON i.inhrelid = c.oid
                    WHERE c.oid = v_qualified
                      AND i.inhparent = 'public.events'::REGCLASS;

                    IF v_bound IS NULL THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'analytics partition name is occupied by an incompatible relation';
                    END IF;

                    v_expected_bound := format(
                        'FOR VALUES FROM (%L) TO (%L)',
                        (v_month::TIMESTAMP AT TIME ZONE 'UTC'),
                        (v_next::TIMESTAMP AT TIME ZONE 'UTC')
                    );

                    IF v_bound <> v_expected_bound THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'analytics partition exists with an unexpected bound';
                    END IF;

                    RETURN jsonb_build_object('partition', v_name, 'created', FALSE, 'bound', v_bound);
                END IF;

                SELECT count(*)::BIGINT
                INTO v_default_rows
                FROM public.events_default
                WHERE occurred_at >= (v_month::TIMESTAMP AT TIME ZONE 'UTC')
                  AND occurred_at < (v_next::TIMESTAMP AT TIME ZONE 'UTC');

                IF v_default_rows > 0 THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'analytics default partition contains rows for the requested month';
                END IF;

                EXECUTE format(
                    'CREATE TABLE public.%I PARTITION OF public.events FOR VALUES FROM (%L) TO (%L)',
                    v_name,
                    (v_month::TIMESTAMP AT TIME ZONE 'UTC'),
                    (v_next::TIMESTAMP AT TIME ZONE 'UTC')
                );
                EXECUTE format(
                    'REVOKE ALL PRIVILEGES ON TABLE public.%I FROM PUBLIC, digitrove_runtime, digitrove_analytics_worker',
                    v_name
                );

                SELECT pg_get_expr(c.relpartbound, c.oid)
                INTO v_bound
                FROM pg_class AS c
                WHERE c.oid = to_regclass(format('public.%I', v_name));

                RETURN jsonb_build_object('partition', v_name, 'created', TRUE, 'bound', v_bound);
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.audit_analytics_event_partitions()
            RETURNS JSONB
            LANGUAGE sql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            SET timezone = 'UTC'
            AS $$
                SELECT jsonb_build_object(
                    'default_rows', (SELECT count(*)::BIGINT FROM public.events_default),
                    'partitions', coalesce(
                        (
                            SELECT jsonb_agg(
                                jsonb_build_object(
                                    'name', child.relname,
                                    'bound', pg_get_expr(child.relpartbound, child.oid)
                                )
                                ORDER BY child.relname
                            )
                            FROM pg_inherits AS inheritance
                            JOIN pg_class AS child ON child.oid = inheritance.inhrelid
                            WHERE inheritance.inhparent = 'public.events'::REGCLASS
                              AND child.relname <> 'events_default'
                        ),
                        '[]'::JSONB
                    )
                )
            $$;
            SQL);
    }

    private function removeAnalyticsOperationsAuthority(): void
    {
        DB::statement('REVOKE EXECUTE ON FUNCTION public.refresh_authoritative_daily_analytics(date) FROM digitrove_analytics_worker');
        DB::statement('REVOKE EXECUTE ON FUNCTION public.ensure_analytics_events_month_partition(date) FROM digitrove_analytics_worker');
        DB::statement('REVOKE EXECUTE ON FUNCTION public.audit_analytics_event_partitions() FROM digitrove_analytics_worker');
        DB::statement('DROP FUNCTION IF EXISTS public.ensure_analytics_events_month_partition(date)');
        DB::statement('DROP FUNCTION IF EXISTS public.audit_analytics_event_partitions()');

        DB::statement('SET ROLE digitrove_analytics_rollup_executor');

        try {
            DB::statement('DROP FUNCTION IF EXISTS public.refresh_authoritative_daily_analytics(date)');
        } finally {
            DB::statement('RESET ROLE');
        }

        DB::statement(<<<'SQL'
            REVOKE ALL PRIVILEGES ON TABLE
                public.orders,
                public.order_items,
                public.payments,
                public.refunds,
                public.events,
                public.daily_sales_stats,
                public.daily_product_stats,
                public.daily_product_engagement_stats,
                public.daily_funnel_stats
            FROM digitrove_analytics_rollup_executor
            SQL);
        DB::statement('REVOKE USAGE ON SCHEMA public FROM digitrove_analytics_worker, digitrove_analytics_rollup_executor');
    }

    private function assertOperationsRolesProvisioned(): void
    {
        $roles = DB::select(<<<'SQL'
            SELECT rolname, rolsuper, rolcanlogin, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls
            FROM pg_roles
            WHERE rolname IN ('digitrove_analytics_worker', 'digitrove_analytics_rollup_executor')
            ORDER BY rolname
            SQL);

        if (count($roles) !== 2) {
            throw new RuntimeException('P5-A2 migration: analytics worker roles are not provisioned. Run `php artisan db:provision-runtime-roles` first.');
        }

        foreach ($roles as $role) {
            $mustLogin = $role->rolname === 'digitrove_analytics_worker';

            if ($role->rolsuper
                || (bool) $role->rolcanlogin !== $mustLogin
                || $role->rolcreatedb
                || $role->rolcreaterole
                || $role->rolreplication
                || $role->rolbypassrls) {
                throw new RuntimeException("P5-A2 migration: {$role->rolname} has unsafe cluster attributes.");
            }
        }

        $membership = DB::selectOne(<<<'SQL'
            SELECT m.set_option
            FROM pg_auth_members AS m
            JOIN pg_roles AS member ON member.oid = m.member
            JOIN pg_roles AS granted ON granted.oid = m.roleid
            WHERE member.rolname = 'digitrove'
              AND granted.rolname = 'digitrove_analytics_rollup_executor'
            SQL);

        if ($membership === null || ! $membership->set_option) {
            throw new RuntimeException('P5-A2 migration: digitrove must hold SET-only membership to digitrove_analytics_rollup_executor.');
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
