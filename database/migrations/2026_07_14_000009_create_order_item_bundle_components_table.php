<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_bundle_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->text('child_product_name_snapshot');
            $table->text('child_product_slug_snapshot');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('order_item_id', 'oibc_order_item_id_index');
            $table->index('child_product_id', 'oibc_child_product_id_index');
        });

        // Trimming the full whitespace set (not only spaces, as btrim/1 would) closes
        // the tab/newline-only bypass. `... IS TRUE` keeps the CHECK strict even if the
        // NOT NULL column constraint were ever relaxed.
        DB::statement("ALTER TABLE order_item_bundle_components ADD CONSTRAINT oibc_child_name_not_blank_check CHECK ((length(btrim(child_product_name_snapshot, E' \\t\\n\\r\\f\\v')) > 0) IS TRUE)");
        DB::statement("ALTER TABLE order_item_bundle_components ADD CONSTRAINT oibc_child_slug_not_blank_check CHECK ((length(btrim(child_product_slug_snapshot, E' \\t\\n\\r\\f\\v')) > 0) IS TRUE)");

        // One snapshot per living component and order_item. Historical rows whose
        // product was physically purged (child_product_id NULL) are never blocked,
        // and the same component stays free in another order_item or order.
        DB::statement('CREATE UNIQUE INDEX oibc_order_item_child_unique ON order_item_bundle_components (order_item_id, child_product_id) WHERE child_product_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_order_item_bundle_components_delete()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'order_item_bundle_components are purchase evidence and cannot be deleted';
            END;
            $$;

            CREATE TRIGGER order_item_bundle_components_prevent_delete_trigger
            BEFORE DELETE ON order_item_bundle_components
            FOR EACH ROW
            EXECUTE FUNCTION prevent_order_item_bundle_components_delete();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_order_item_bundle_component_immutability()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF ROW(
                    NEW.id,
                    NEW.order_item_id,
                    NEW.child_product_name_snapshot,
                    NEW.child_product_slug_snapshot,
                    NEW.created_at
                ) IS DISTINCT FROM ROW(
                    OLD.id,
                    OLD.order_item_id,
                    OLD.child_product_name_snapshot,
                    OLD.child_product_slug_snapshot,
                    OLD.created_at
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'order_item_bundle_components purchase snapshot is immutable';
                END IF;

                -- The component reference is historical identity: only the nested FK
                -- action triggered by physically deleting the product may null it. A
                -- direct UPDATE runs at trigger depth 1 and must not impersonate
                -- ON DELETE SET NULL, nor swap the component for another product.
                IF NOT (
                    NEW.child_product_id IS NOT DISTINCT FROM OLD.child_product_id
                    OR (
                        OLD.child_product_id IS NOT NULL
                        AND NEW.child_product_id IS NULL
                        AND pg_trigger_depth() > 1
                    )
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'order_item_bundle_components child product reference may only be nulled by the foreign key action';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER order_item_bundle_components_enforce_immutability_trigger
            BEFORE UPDATE ON order_item_bundle_components
            FOR EACH ROW
            EXECUTE FUNCTION enforce_order_item_bundle_component_immutability();
            SQL);

        // S3 VERIFIES AND REFUSES ONLY: it never copies the pivot, never mutates NEW,
        // and never touches order_items, products or product_bundles. It proves each
        // row individually; snapshot EXHAUSTIVENESS is NOT a database guarantee (see
        // D-029.3): the future OrderService performs a single INSERT ... SELECT in the
        // same transaction as the order_item creation, after locking the bundle product
        // row. The pivot is read here only at copy time — never after COMMIT.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_order_item_bundle_component()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                item_product_id BIGINT;
                item_type_snapshot TEXT;
                component_type TEXT;
            BEGIN
                IF NEW.child_product_id IS NULL THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'order_item_bundle_components require a child product at insert';
                END IF;

                SELECT product_id, product_type_snapshot
                INTO item_product_id, item_type_snapshot
                FROM order_items
                WHERE id = NEW.order_item_id;

                -- Unknown order_item: the foreign key is the most precise authority.
                IF NOT FOUND THEN
                    RETURN NEW;
                END IF;

                IF item_type_snapshot IS DISTINCT FROM 'bundle' THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'order_item_bundle_components require a bundle order_item';
                END IF;

                IF item_product_id IS NULL THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'order_item_bundle_components require a resolvable bundle product';
                END IF;

                SELECT type INTO component_type
                FROM products
                WHERE id = NEW.child_product_id;

                -- Unknown component: the foreign key is the most precise authority.
                IF NOT FOUND THEN
                    RETURN NEW;
                END IF;

                -- Nested bundles are excluded (D-029.3): the pivot only guards direct
                -- self-inclusion and indirect cycles remain unprotected, so flattening
                -- would risk cycles and keeping the nesting would silently under-deliver.
                IF component_type = 'bundle' THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'order_item_bundle_components exclude nested bundle components';
                END IF;

                IF NOT EXISTS (
                    SELECT 1
                    FROM product_bundles
                    WHERE bundle_id = item_product_id
                      AND child_product_id = NEW.child_product_id
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'order_item_bundle_components component must belong to the purchased bundle';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER order_item_bundle_components_validate_insert_trigger
            BEFORE INSERT ON order_item_bundle_components
            FOR EACH ROW
            EXECUTE FUNCTION validate_order_item_bundle_component();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS order_item_bundle_components_validate_insert_trigger ON order_item_bundle_components');
        DB::statement('DROP TRIGGER IF EXISTS order_item_bundle_components_enforce_immutability_trigger ON order_item_bundle_components');
        DB::statement('DROP TRIGGER IF EXISTS order_item_bundle_components_prevent_delete_trigger ON order_item_bundle_components');
        DB::statement('DROP FUNCTION IF EXISTS validate_order_item_bundle_component()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_order_item_bundle_component_immutability()');
        DB::statement('DROP FUNCTION IF EXISTS prevent_order_item_bundle_components_delete()');
        Schema::dropIfExists('order_item_bundle_components');
    }
};
