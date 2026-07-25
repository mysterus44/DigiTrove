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
    }

    public function down(): void
    {
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
};
