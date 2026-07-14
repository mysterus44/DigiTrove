<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->text('product_name_snapshot');
            $table->text('product_slug_snapshot');
            $table->text('product_type_snapshot');
            $table->bigInteger('unit_price_minor');
            $table->integer('quantity')->default(1);
            $table->bigInteger('line_subtotal_minor');
            $table->bigInteger('line_discount_minor')->default(0);
            $table->bigInteger('line_total_minor');
            $table->string('currency', 3);
            $table->timestampsTz();

            $table->index('order_id', 'order_items_order_id_index');
            $table->index('product_id', 'order_items_product_id_index');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_product_name_not_blank_check CHECK (length(btrim(product_name_snapshot)) > 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_product_slug_not_blank_check CHECK (length(btrim(product_slug_snapshot)) > 0)');
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_product_type_check CHECK (product_type_snapshot IN ('software', 'course', 'ebook', 'bundle', 'template'))");
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_unit_price_non_negative_check CHECK (unit_price_minor >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_positive_check CHECK (quantity >= 1)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_line_subtotal_non_negative_check CHECK (line_subtotal_minor >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_line_discount_non_negative_check CHECK (line_discount_minor >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_line_total_non_negative_check CHECK (line_total_minor >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_line_subtotal_formula_check CHECK (line_subtotal_minor = unit_price_minor * quantity)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_line_discount_not_above_subtotal_check CHECK (line_discount_minor <= line_subtotal_minor)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_line_total_formula_check CHECK (line_total_minor = line_subtotal_minor - line_discount_minor)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_currency_format_check CHECK (char_length(currency) = 3 AND currency = upper(currency))');
        DB::statement('CREATE UNIQUE INDEX order_items_order_id_product_id_unique ON order_items (order_id, product_id) WHERE product_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_order_items_delete()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'order_items are immutable and cannot be deleted';
            END;
            $$;

            CREATE TRIGGER order_items_prevent_delete_trigger
            BEFORE DELETE ON order_items
            FOR EACH ROW
            EXECUTE FUNCTION prevent_order_items_delete();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_order_items_immutability()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.product_id IS NOT NULL
                    AND NEW.product_id IS NULL
                    AND ROW(
                        NEW.id,
                        NEW.order_id,
                        NEW.product_name_snapshot,
                        NEW.product_slug_snapshot,
                        NEW.product_type_snapshot,
                        NEW.unit_price_minor,
                        NEW.quantity,
                        NEW.line_subtotal_minor,
                        NEW.line_discount_minor,
                        NEW.line_total_minor,
                        NEW.currency,
                        NEW.created_at,
                        NEW.updated_at
                    ) IS NOT DISTINCT FROM ROW(
                        OLD.id,
                        OLD.order_id,
                        OLD.product_name_snapshot,
                        OLD.product_slug_snapshot,
                        OLD.product_type_snapshot,
                        OLD.unit_price_minor,
                        OLD.quantity,
                        OLD.line_subtotal_minor,
                        OLD.line_discount_minor,
                        OLD.line_total_minor,
                        OLD.currency,
                        OLD.created_at,
                        OLD.updated_at
                    ) THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'order_items commercial data is immutable';
            END;
            $$;

            CREATE TRIGGER order_items_enforce_immutability_trigger
            BEFORE UPDATE ON order_items
            FOR EACH ROW
            EXECUTE FUNCTION enforce_order_items_immutability();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_order_items_consistency()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                target_order_id BIGINT;
                order_currency VARCHAR(3);
                order_subtotal BIGINT;
                order_discount BIGINT;
                order_tax BIGINT;
                order_total BIGINT;
                item_count BIGINT;
                item_subtotal BIGINT;
                item_discount BIGINT;
                item_total BIGINT;
                has_currency_mismatch BOOLEAN;
            BEGIN
                IF TG_TABLE_NAME = 'orders' THEN
                    target_order_id := NEW.id;
                ELSIF TG_OP = 'DELETE' THEN
                    target_order_id := OLD.order_id;
                ELSE
                    target_order_id := NEW.order_id;
                END IF;

                SELECT currency, subtotal_minor, discount_minor, tax_minor, total_minor
                INTO order_currency, order_subtotal, order_discount, order_tax, order_total
                FROM orders
                WHERE id = target_order_id;

                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                SELECT
                    COUNT(*),
                    COALESCE(SUM(line_subtotal_minor), 0)::BIGINT,
                    COALESCE(SUM(line_discount_minor), 0)::BIGINT,
                    COALESCE(SUM(line_total_minor), 0)::BIGINT,
                    COALESCE(BOOL_OR(currency IS DISTINCT FROM order_currency), false)
                INTO item_count, item_subtotal, item_discount, item_total, has_currency_mismatch
                FROM order_items
                WHERE order_id = target_order_id;

                IF item_count = 0 THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'orders must contain at least one order_item';
                END IF;

                IF has_currency_mismatch THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'order_items currency must match orders currency';
                END IF;

                IF item_subtotal IS DISTINCT FROM order_subtotal
                    OR item_discount IS DISTINCT FROM order_discount
                    OR item_total + order_tax IS DISTINCT FROM order_total THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'order_items totals must match orders totals';
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER orders_validate_items_consistency_trigger
            AFTER INSERT OR UPDATE ON orders
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION validate_order_items_consistency();

            CREATE CONSTRAINT TRIGGER order_items_validate_order_consistency_trigger
            AFTER INSERT OR UPDATE OR DELETE ON order_items
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION validate_order_items_consistency();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS order_items_validate_order_consistency_trigger ON order_items');
        DB::statement('DROP TRIGGER IF EXISTS orders_validate_items_consistency_trigger ON orders');
        DB::statement('DROP TRIGGER IF EXISTS order_items_enforce_immutability_trigger ON order_items');
        DB::statement('DROP TRIGGER IF EXISTS order_items_prevent_delete_trigger ON order_items');
        DB::statement('DROP FUNCTION IF EXISTS validate_order_items_consistency()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_order_items_immutability()');
        DB::statement('DROP FUNCTION IF EXISTS prevent_order_items_delete()');
        Schema::dropIfExists('order_items');
    }
};
