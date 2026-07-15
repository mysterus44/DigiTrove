<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->smallInteger('customer_key_version')->default(1);
            $table->string('customer_key_hash', 64);
            $table->text('coupon_code_snapshot');
            $table->text('discount_type_snapshot');
            $table->bigInteger('discount_minor');
            $table->string('currency', 3);
            $table->timestampTz('redeemed_at')->useCurrent();

            $table->unique('order_id', 'coupon_redemptions_order_id_unique');
            $table->index(
                ['coupon_id', 'customer_key_version', 'customer_key_hash'],
                'coupon_redemptions_coupon_customer_index'
            );
            $table->index(['coupon_id', 'redeemed_at'], 'coupon_redemptions_coupon_redeemed_at_index');
            $table->index('redeemed_at', 'coupon_redemptions_redeemed_at_index');
        });

        DB::statement('ALTER TABLE coupon_redemptions ADD CONSTRAINT coupon_redemptions_customer_key_version_positive_check CHECK (customer_key_version > 0)');
        DB::statement("ALTER TABLE coupon_redemptions ADD CONSTRAINT coupon_redemptions_customer_key_hash_format_check CHECK (customer_key_hash ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE coupon_redemptions ADD CONSTRAINT coupon_redemptions_coupon_code_not_blank_check CHECK (length(btrim(coupon_code_snapshot)) > 0)');
        DB::statement("ALTER TABLE coupon_redemptions ADD CONSTRAINT coupon_redemptions_discount_type_check CHECK (discount_type_snapshot IN ('percent', 'fixed'))");
        DB::statement('ALTER TABLE coupon_redemptions ADD CONSTRAINT coupon_redemptions_discount_non_negative_check CHECK (discount_minor >= 0)');
        DB::statement('ALTER TABLE coupon_redemptions ADD CONSTRAINT coupon_redemptions_currency_format_check CHECK (char_length(currency) = 3 AND currency = upper(currency))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_coupon_redemption_consistency()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                target_order_id BIGINT;
                order_coupon_id BIGINT;
                order_coupon_code TEXT;
                order_discount_type TEXT;
                order_discount BIGINT;
                order_currency VARCHAR(3);
                order_status TEXT;
                redemption_coupon_id BIGINT;
                redemption_coupon_code TEXT;
                redemption_discount_type TEXT;
                redemption_discount BIGINT;
                redemption_currency VARCHAR(3);
            BEGIN
                IF TG_TABLE_NAME = 'orders' THEN
                    target_order_id := NEW.id;
                ELSIF TG_OP = 'DELETE' THEN
                    target_order_id := OLD.order_id;
                ELSE
                    target_order_id := NEW.order_id;
                END IF;

                SELECT
                    orders.coupon_id,
                    orders.coupon_code_snapshot,
                    orders.coupon_discount_type_snapshot,
                    orders.discount_minor,
                    orders.currency,
                    orders.status,
                    coupon_redemptions.coupon_id,
                    coupon_redemptions.coupon_code_snapshot,
                    coupon_redemptions.discount_type_snapshot,
                    coupon_redemptions.discount_minor,
                    coupon_redemptions.currency
                INTO
                    order_coupon_id,
                    order_coupon_code,
                    order_discount_type,
                    order_discount,
                    order_currency,
                    order_status,
                    redemption_coupon_id,
                    redemption_coupon_code,
                    redemption_discount_type,
                    redemption_discount,
                    redemption_currency
                FROM orders
                INNER JOIN coupon_redemptions
                    ON coupon_redemptions.order_id = orders.id
                WHERE orders.id = target_order_id;

                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                IF order_coupon_code IS NULL
                    OR order_discount_type IS NULL
                    OR order_coupon_id IS DISTINCT FROM redemption_coupon_id
                    OR order_coupon_code IS DISTINCT FROM redemption_coupon_code
                    OR order_discount_type IS DISTINCT FROM redemption_discount_type
                    OR order_discount IS DISTINCT FROM redemption_discount
                    OR order_currency IS DISTINCT FROM redemption_currency
                    OR order_status NOT IN ('paid', 'partially_refunded', 'refunded') THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'coupon_redemptions must match a paid order coupon snapshot';
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER coupon_redemptions_validate_order_consistency_trigger
            AFTER INSERT OR UPDATE OR DELETE ON coupon_redemptions
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION validate_coupon_redemption_consistency();

            CREATE CONSTRAINT TRIGGER orders_validate_redemption_consistency_trigger
            AFTER INSERT OR UPDATE ON orders
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION validate_coupon_redemption_consistency();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS orders_validate_redemption_consistency_trigger ON orders');
        DB::statement('DROP TRIGGER IF EXISTS coupon_redemptions_validate_order_consistency_trigger ON coupon_redemptions');
        DB::statement('DROP FUNCTION IF EXISTS validate_coupon_redemption_consistency()');
        Schema::dropIfExists('coupon_redemptions');
    }
};
