<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->string('order_number', 19);
            $table->foreignId('cart_id')->nullable()->constrained()->nullOnDelete();
            $table->string('checkout_idempotency_hash', 64);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('visitor_id')->nullable()->constrained()->nullOnDelete();
            $table->text('customer_email');
            $table->text('customer_name_snapshot')->nullable();
            $table->string('billing_country_code', 2)->nullable();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->text('coupon_code_snapshot')->nullable();
            $table->text('coupon_discount_type_snapshot')->nullable();
            $table->integer('coupon_percent_basis_points_snapshot')->nullable();
            $table->bigInteger('coupon_fixed_amount_minor_snapshot')->nullable();
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->string('currency', 3);
            $table->text('status')->default('pending');
            $table->timestampTz('placed_at')->useCurrent();
            $table->timestampTz('expires_at');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('referrer_host', 253)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestampsTz();

            $table->unique('public_id', 'orders_public_id_unique');
            $table->unique('order_number', 'orders_order_number_unique');
            $table->unique('cart_id', 'orders_cart_id_unique');
            $table->unique('checkout_idempotency_hash', 'orders_checkout_idempotency_hash_unique');
            $table->index(['status', 'expires_at'], 'orders_status_expires_at_index');
            $table->index(['status', 'placed_at'], 'orders_status_placed_at_index');
            $table->index(['user_id', 'placed_at'], 'orders_user_id_placed_at_index');
            $table->index(['visitor_id', 'placed_at'], 'orders_visitor_id_placed_at_index');
            $table->index(['customer_email', 'placed_at'], 'orders_customer_email_placed_at_index');
            $table->index(['coupon_id', 'placed_at'], 'orders_coupon_id_placed_at_index');
        });

        DB::statement('ALTER TABLE orders ALTER COLUMN customer_email TYPE CITEXT');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_order_number_format_check CHECK (order_number ~ '^DGT-[0-9]{4}-[0-9A-HJKMNP-TV-Z]{10}$')");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_checkout_idempotency_hash_format_check CHECK (checkout_idempotency_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_ip_hash_format_check CHECK (ip_hash IS NULL OR ip_hash ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_customer_email_format_check CHECK (length(btrim(customer_email::text)) > 0 AND char_length(customer_email::text) <= 320)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_customer_name_not_blank_check CHECK (customer_name_snapshot IS NULL OR length(btrim(customer_name_snapshot)) > 0)');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_billing_country_code_format_check CHECK (billing_country_code IS NULL OR billing_country_code ~ '^[A-Z]{2}$')");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_subtotal_non_negative_check CHECK (subtotal_minor >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_discount_non_negative_check CHECK (discount_minor >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_tax_non_negative_check CHECK (tax_minor >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_non_negative_check CHECK (total_minor >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_discount_not_above_subtotal_check CHECK (discount_minor <= subtotal_minor)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_formula_check CHECK (total_minor = subtotal_minor - discount_minor + tax_minor)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_currency_format_check CHECK (char_length(currency) = 3 AND currency = upper(currency))');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'payment_review', 'paid', 'partially_refunded', 'refunded', 'cancelled', 'expired'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_expiration_after_placement_check CHECK (expires_at > placed_at)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_paid_at_after_placement_check CHECK (paid_at IS NULL OR paid_at >= placed_at)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_cancelled_at_after_placement_check CHECK (cancelled_at IS NULL OR cancelled_at >= placed_at)');
        DB::statement(<<<'SQL'
            ALTER TABLE orders
            ADD CONSTRAINT orders_coupon_snapshot_consistency_check
            CHECK (
                CASE
                    WHEN coupon_code_snapshot IS NULL
                        AND coupon_discount_type_snapshot IS NULL
                        AND coupon_percent_basis_points_snapshot IS NULL
                        AND coupon_fixed_amount_minor_snapshot IS NULL
                        AND discount_minor = 0
                    THEN TRUE
                    WHEN coupon_code_snapshot IS NOT NULL
                        AND length(btrim(coupon_code_snapshot)) > 0
                        AND coupon_discount_type_snapshot IS NOT NULL
                        AND coupon_discount_type_snapshot = 'percent'
                        AND coupon_percent_basis_points_snapshot IS NOT NULL
                        AND coupon_percent_basis_points_snapshot BETWEEN 1 AND 10000
                        AND coupon_fixed_amount_minor_snapshot IS NULL
                        AND discount_minor > 0
                    THEN TRUE
                    WHEN coupon_code_snapshot IS NOT NULL
                        AND length(btrim(coupon_code_snapshot)) > 0
                        AND coupon_discount_type_snapshot IS NOT NULL
                        AND coupon_discount_type_snapshot = 'fixed'
                        AND coupon_fixed_amount_minor_snapshot IS NOT NULL
                        AND coupon_fixed_amount_minor_snapshot > 0
                        AND coupon_percent_basis_points_snapshot IS NULL
                        AND discount_minor > 0
                    THEN TRUE
                    ELSE FALSE
                END IS TRUE
            )
            SQL);
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_coupon_reference_requires_snapshot_check CHECK (coupon_id IS NULL OR coupon_code_snapshot IS NOT NULL)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_orders_delete()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'orders are immutable and cannot be deleted';
            END;
            $$;

            CREATE TRIGGER orders_prevent_delete_trigger
            BEFORE DELETE ON orders
            FOR EACH ROW
            EXECUTE FUNCTION prevent_orders_delete();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_orders_immutability()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF ROW(
                    NEW.id,
                    NEW.public_id,
                    NEW.order_number,
                    NEW.checkout_idempotency_hash,
                    NEW.customer_email,
                    NEW.customer_name_snapshot,
                    NEW.billing_country_code,
                    NEW.coupon_code_snapshot,
                    NEW.coupon_discount_type_snapshot,
                    NEW.coupon_percent_basis_points_snapshot,
                    NEW.coupon_fixed_amount_minor_snapshot,
                    NEW.subtotal_minor,
                    NEW.discount_minor,
                    NEW.tax_minor,
                    NEW.total_minor,
                    NEW.currency,
                    NEW.placed_at,
                    NEW.expires_at,
                    NEW.utm_source,
                    NEW.utm_medium,
                    NEW.utm_campaign,
                    NEW.referrer_host,
                    NEW.ip_hash,
                    NEW.created_at
                ) IS DISTINCT FROM ROW(
                    OLD.id,
                    OLD.public_id,
                    OLD.order_number,
                    OLD.checkout_idempotency_hash,
                    OLD.customer_email,
                    OLD.customer_name_snapshot,
                    OLD.billing_country_code,
                    OLD.coupon_code_snapshot,
                    OLD.coupon_discount_type_snapshot,
                    OLD.coupon_percent_basis_points_snapshot,
                    OLD.coupon_fixed_amount_minor_snapshot,
                    OLD.subtotal_minor,
                    OLD.discount_minor,
                    OLD.tax_minor,
                    OLD.total_minor,
                    OLD.currency,
                    OLD.placed_at,
                    OLD.expires_at,
                    OLD.utm_source,
                    OLD.utm_medium,
                    OLD.utm_campaign,
                    OLD.referrer_host,
                    OLD.ip_hash,
                    OLD.created_at
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'orders commercial data is immutable';
                END IF;

                IF NOT (
                    NEW.cart_id IS NOT DISTINCT FROM OLD.cart_id
                    OR (OLD.cart_id IS NOT NULL AND NEW.cart_id IS NULL)
                ) OR NOT (
                    NEW.user_id IS NOT DISTINCT FROM OLD.user_id
                    OR (OLD.user_id IS NOT NULL AND NEW.user_id IS NULL)
                ) OR NOT (
                    NEW.visitor_id IS NOT DISTINCT FROM OLD.visitor_id
                    OR (OLD.visitor_id IS NOT NULL AND NEW.visitor_id IS NULL)
                ) OR NOT (
                    NEW.coupon_id IS NOT DISTINCT FROM OLD.coupon_id
                    OR (OLD.coupon_id IS NOT NULL AND NEW.coupon_id IS NULL)
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'orders provenance references may only be nulled';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER orders_enforce_immutability_trigger
            BEFORE UPDATE ON orders
            FOR EACH ROW
            EXECUTE FUNCTION enforce_orders_immutability();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS orders_enforce_immutability_trigger ON orders');
        DB::statement('DROP TRIGGER IF EXISTS orders_prevent_delete_trigger ON orders');
        DB::statement('DROP FUNCTION IF EXISTS enforce_orders_immutability()');
        DB::statement('DROP FUNCTION IF EXISTS prevent_orders_delete()');
        Schema::dropIfExists('orders');
    }
};
