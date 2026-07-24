<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P5-A0 — recalculable, currency-safe daily rollups (D-037).
 *
 * These tables are non-authoritative and intentionally have no foreign keys.
 * No runtime writer or scheduled rollup exists in this gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE daily_sales_stats (
                day DATE NOT NULL,
                currency VARCHAR(3) NOT NULL,
                orders_count BIGINT NOT NULL DEFAULT 0,
                gross_revenue_minor BIGINT NOT NULL DEFAULT 0,
                discount_minor BIGINT NOT NULL DEFAULT 0,
                tax_minor BIGINT NOT NULL DEFAULT 0,
                refunds_minor BIGINT NOT NULL DEFAULT 0,
                net_revenue_minor BIGINT NOT NULL DEFAULT 0,
                average_order_minor BIGINT NOT NULL DEFAULT 0,
                updated_at TIMESTAMPTZ NOT NULL,

                CONSTRAINT daily_sales_stats_pkey PRIMARY KEY (day, currency),
                CONSTRAINT daily_sales_stats_currency_format_check
                    CHECK (currency ~ '^[A-Z]{3}$'),
                CONSTRAINT daily_sales_stats_counts_non_negative_check
                    CHECK (orders_count >= 0),
                CONSTRAINT daily_sales_stats_amounts_check
                    CHECK (
                        gross_revenue_minor >= 0
                        AND discount_minor >= 0
                        AND discount_minor <= gross_revenue_minor
                        AND tax_minor >= 0
                        AND refunds_minor >= 0
                        AND average_order_minor >= 0
                    ),
                CONSTRAINT daily_sales_stats_net_formula_check
                    CHECK (
                        net_revenue_minor =
                            gross_revenue_minor - discount_minor + tax_minor - refunds_minor
                    ),
                CONSTRAINT daily_sales_stats_average_formula_check
                    CHECK (
                        CASE
                            WHEN orders_count = 0 THEN
                                average_order_minor = 0
                                AND gross_revenue_minor = 0
                                AND discount_minor = 0
                                AND tax_minor = 0
                            ELSE
                                average_order_minor =
                                    (gross_revenue_minor - discount_minor + tax_minor) / orders_count
                        END IS TRUE
                    )
            );

            CREATE TABLE daily_product_stats (
                day DATE NOT NULL,
                product_id BIGINT NOT NULL,
                currency VARCHAR(3) NOT NULL,
                views BIGINT NOT NULL DEFAULT 0,
                add_to_carts BIGINT NOT NULL DEFAULT 0,
                purchases BIGINT NOT NULL DEFAULT 0,
                revenue_minor BIGINT NOT NULL DEFAULT 0,
                updated_at TIMESTAMPTZ NOT NULL,

                CONSTRAINT daily_product_stats_pkey PRIMARY KEY (day, product_id, currency),
                CONSTRAINT daily_product_stats_product_id_positive_check
                    CHECK (product_id > 0),
                CONSTRAINT daily_product_stats_currency_format_check
                    CHECK (currency ~ '^[A-Z]{3}$'),
                CONSTRAINT daily_product_stats_metrics_non_negative_check
                    CHECK (
                        views >= 0
                        AND add_to_carts >= 0
                        AND purchases >= 0
                        AND revenue_minor >= 0
                    )
            );

            CREATE TABLE daily_funnel_stats (
                day DATE PRIMARY KEY,
                visitors BIGINT NOT NULL DEFAULT 0,
                sessions BIGINT NOT NULL DEFAULT 0,
                product_views BIGINT NOT NULL DEFAULT 0,
                add_to_carts BIGINT NOT NULL DEFAULT 0,
                checkouts BIGINT NOT NULL DEFAULT 0,
                purchases BIGINT NOT NULL DEFAULT 0,
                new_customers BIGINT NOT NULL DEFAULT 0,
                updated_at TIMESTAMPTZ NOT NULL,

                CONSTRAINT daily_funnel_stats_metrics_non_negative_check
                    CHECK (
                        visitors >= 0
                        AND sessions >= 0
                        AND product_views >= 0
                        AND add_to_carts >= 0
                        AND checkouts >= 0
                        AND purchases >= 0
                        AND new_customers >= 0
                    )
            );
            SQL);

        DB::statement('REVOKE ALL PRIVILEGES ON TABLE daily_sales_stats, daily_product_stats, daily_funnel_stats FROM PUBLIC, digitrove_runtime');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS daily_funnel_stats');
        DB::statement('DROP TABLE IF EXISTS daily_product_stats');
        DB::statement('DROP TABLE IF EXISTS daily_sales_stats');
    }
};
