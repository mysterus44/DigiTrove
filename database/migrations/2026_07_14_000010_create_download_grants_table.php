<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_grants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_file_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token_hash', 64);
            $table->timestampTz('expires_at');
            $table->bigInteger('max_downloads');
            $table->bigInteger('downloads_count')->default(0);
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason_code', 100)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('order_item_id', 'download_grants_order_item_id_index');
            $table->index('product_file_id', 'download_grants_product_file_id_index');
            $table->index('user_id', 'download_grants_user_id_index');
        });

        DB::statement('ALTER TABLE download_grants ADD CONSTRAINT download_grants_public_id_unique UNIQUE (public_id)');
        DB::statement('ALTER TABLE download_grants ADD CONSTRAINT download_grants_token_hash_unique UNIQUE (token_hash)');
        // SHA-256 of a CSPRNG token. The raw token is NEVER stored, logged or echoed.
        DB::statement("ALTER TABLE download_grants ADD CONSTRAINT download_grants_token_hash_format_check CHECK (token_hash ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE download_grants ADD CONSTRAINT download_grants_expires_after_created_check CHECK (expires_at > created_at)');
        // No commercial DEFAULT: the service must supply quota and expiry explicitly
        // (D-029.1-B). The database only enforces the technical bounds.
        DB::statement('ALTER TABLE download_grants ADD CONSTRAINT download_grants_max_downloads_positive_check CHECK (max_downloads >= 1)');
        DB::statement('ALTER TABLE download_grants ADD CONSTRAINT download_grants_count_within_quota_check CHECK (downloads_count >= 0 AND downloads_count <= max_downloads)');
        DB::statement(<<<'SQL'
            ALTER TABLE download_grants ADD CONSTRAINT download_grants_revocation_pair_check CHECK (
                (CASE
                    WHEN revoked_at IS NULL THEN revoked_reason_code IS NULL
                    ELSE revoked_reason_code IS NOT NULL AND length(btrim(revoked_reason_code)) > 0
                END) IS TRUE
            )
        SQL);

        // One ACTIVE grant per purchased pair. An EXPIRED but non-revoked grant stays
        // in this predicate and blocks re-issue until it is explicitly revoked
        // (`expired_reissue`) — D-029.4 finding 2. No predicate may use now(): it is
        // not immutable, so a time-based partial index is impossible by design.
        DB::statement('CREATE UNIQUE INDEX download_grants_active_pair_unique ON download_grants (order_item_id, product_file_id) WHERE revoked_at IS NULL');
        DB::statement('CREATE INDEX download_grants_active_expiry_index ON download_grants (expires_at) WHERE revoked_at IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_download_grants_delete()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'download_grants are revoked, never deleted';
            END;
            $$;

            CREATE TRIGGER download_grants_prevent_delete_trigger
            BEFORE DELETE ON download_grants
            FOR EACH ROW
            EXECUTE FUNCTION prevent_download_grants_delete();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_download_grants_immutability()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF ROW(
                    NEW.id,
                    NEW.public_id,
                    NEW.order_item_id,
                    NEW.product_file_id,
                    NEW.token_hash,
                    NEW.expires_at,
                    NEW.max_downloads,
                    NEW.created_at
                ) IS DISTINCT FROM ROW(
                    OLD.id,
                    OLD.public_id,
                    OLD.order_item_id,
                    OLD.product_file_id,
                    OLD.token_hash,
                    OLD.expires_at,
                    OLD.max_downloads,
                    OLD.created_at
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'download_grants identity, token and bounds are immutable';
                END IF;

                -- The buyer reference is an audit denormalisation (D-029.4 finding 3),
                -- never the authority. Only the nested FK action from deleting the user
                -- may null it; a direct UPDATE runs at trigger depth 1 and is refused.
                IF NOT (
                    NEW.user_id IS NOT DISTINCT FROM OLD.user_id
                    OR (
                        OLD.user_id IS NOT NULL
                        AND NEW.user_id IS NULL
                        AND pg_trigger_depth() > 1
                    )
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'download_grants user reference may only be nulled by the foreign key action';
                END IF;

                -- Revocation: set-once, paired with its reason, irreversible.
                IF NEW.revoked_at IS DISTINCT FROM OLD.revoked_at THEN
                    IF OLD.revoked_at IS NOT NULL THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_grants revocation is irreversible';
                    END IF;

                    IF NEW.revoked_at IS NULL THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_grants revocation is irreversible';
                    END IF;

                    IF NEW.downloads_count IS DISTINCT FROM OLD.downloads_count THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_grants consumption and revocation cannot be combined';
                    END IF;
                END IF;

                IF OLD.revoked_at IS NOT NULL
                    AND NEW.revoked_reason_code IS DISTINCT FROM OLD.revoked_reason_code THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'download_grants revocation reason is frozen once revoked';
                END IF;

                -- Consumption: strictly +1, never backwards, and only while the grant is
                -- usable. P4-A2 only creates the structure: no real consumption happens
                -- before P4-B pairs the increment with a download log (D-029.4).
                IF NEW.downloads_count IS DISTINCT FROM OLD.downloads_count THEN
                    IF NEW.downloads_count <> OLD.downloads_count + 1 THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_grants downloads_count may only increase by exactly one';
                    END IF;

                    IF OLD.revoked_at IS NOT NULL THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_grants cannot be consumed once revoked';
                    END IF;

                    IF OLD.expires_at <= now() THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_grants cannot be consumed once expired';
                    END IF;

                    IF OLD.downloads_count >= OLD.max_downloads THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_grants quota is exhausted';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER download_grants_enforce_immutability_trigger
            BEFORE UPDATE ON download_grants
            FOR EACH ROW
            EXECUTE FUNCTION enforce_download_grants_immutability();
            SQL);

        // G3 VERIFIES AND REFUSES ONLY: it never generates a token, never creates
        // another row, never sends anything, and never mutates orders, payments,
        // product_files or the bundle snapshot.
        //
        // Financial authority is orders.status ALONE (D-029.4): the P3C deferred
        // constraint triggers already guarantee at COMMIT that paid/partially_refunded
        // implies a succeeded payment (or a legitimate free order). Re-reading payments
        // here would add nothing.
        //
        // G3 proves LINEAGE, never TEMPORALITY: a product_file added after the purchase
        // would pass. "No implicit upgrade" is an APPLICATION guarantee (the listener
        // only issues at OrderPaid; rotation keeps the same product_file_id) — never a
        // PostgreSQL invariant.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_download_grant_delivery()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                order_status TEXT;
                order_user_id BIGINT;
                item_product_id BIGINT;
                item_type_snapshot TEXT;
                file_product_id BIGINT;
                file_is_active BOOLEAN;
            BEGIN
                IF NEW.downloads_count <> 0 OR NEW.revoked_at IS NOT NULL THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'download_grants are born unconsumed and active';
                END IF;

                -- Lock the order row: serialises issuance against a concurrent refund or
                -- cancellation. Global lock order: orders -> ... -> download_grants.
                SELECT o.status, o.user_id, oi.product_id, oi.product_type_snapshot
                INTO order_status, order_user_id, item_product_id, item_type_snapshot
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                WHERE oi.id = NEW.order_item_id
                FOR UPDATE OF o;

                -- Unknown order_item: the foreign key is the most precise authority.
                IF NOT FOUND THEN
                    RETURN NEW;
                END IF;

                IF order_status NOT IN ('paid', 'partially_refunded') THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'download_grants require a deliverable order';
                END IF;

                IF item_product_id IS NULL THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'download_grants require a resolvable purchased product';
                END IF;

                IF order_user_id IS NOT NULL AND NEW.user_id IS DISTINCT FROM order_user_id THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'download_grants user must match the order buyer';
                END IF;

                SELECT product_id, is_active
                INTO file_product_id, file_is_active
                FROM product_files
                WHERE id = NEW.product_file_id;

                -- Unknown product_file: the foreign key is the most precise authority.
                IF NOT FOUND THEN
                    RETURN NEW;
                END IF;

                IF file_is_active IS NOT TRUE THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'download_grants require an active product file';
                END IF;

                IF item_type_snapshot = 'bundle' THEN
                    -- A totally absent snapshot is detectable and refused. A PARTIAL
                    -- snapshot is NOT detectable (P4-A1 stores no header, no expected
                    -- count, no completeness proof) and comparing to the live pivot is
                    -- forbidden: under-delivery is possible, over-delivery is not.
                    IF NOT EXISTS (
                        SELECT 1 FROM order_item_bundle_components
                        WHERE order_item_id = NEW.order_item_id
                    ) THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_grants require a bundle purchase snapshot';
                    END IF;

                    IF NOT (
                        file_product_id = item_product_id
                        OR EXISTS (
                            SELECT 1 FROM order_item_bundle_components
                            WHERE order_item_id = NEW.order_item_id
                              AND child_product_id = file_product_id
                        )
                    ) THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_grants product_file must belong to the purchased bundle';
                    END IF;
                ELSE
                    IF file_product_id IS DISTINCT FROM item_product_id THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_grants product_file must belong to the purchased product';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER download_grants_validate_delivery_trigger
            BEFORE INSERT ON download_grants
            FOR EACH ROW
            EXECUTE FUNCTION validate_download_grant_delivery();
            SQL);

        // G4: deferred, bidirectional. At COMMIT, any ACTIVE grant implies a deliverable
        // order. A total refund therefore requires the grants to have been revoked in the
        // SAME transaction that moves the order to `refunded`. No trigger on `refunds`
        // ever touches grants, and this function never mutates: it verifies and refuses.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_download_grant_order_consistency()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                offending BIGINT;
            BEGIN
                IF TG_TABLE_NAME = 'orders' THEN
                    SELECT dg.id INTO offending
                    FROM download_grants dg
                    JOIN order_items oi ON oi.id = dg.order_item_id
                    WHERE oi.order_id = NEW.id
                      AND dg.revoked_at IS NULL
                    LIMIT 1;
                ELSE
                    SELECT dg.id INTO offending
                    FROM download_grants dg
                    JOIN order_items oi ON oi.id = dg.order_item_id
                    JOIN orders o ON o.id = oi.order_id
                    WHERE dg.id = NEW.id
                      AND dg.revoked_at IS NULL
                      AND o.status NOT IN ('paid', 'partially_refunded')
                    LIMIT 1;

                    IF offending IS NOT NULL THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'active download_grants require a deliverable order';
                    END IF;

                    RETURN NULL;
                END IF;

                IF offending IS NOT NULL AND NEW.status NOT IN ('paid', 'partially_refunded') THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'active download_grants require a deliverable order';
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER download_grants_validate_order_consistency_trigger
            AFTER INSERT OR UPDATE OF revoked_at ON download_grants
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION validate_download_grant_order_consistency();

            CREATE CONSTRAINT TRIGGER orders_validate_download_consistency_trigger
            AFTER UPDATE OF status ON orders
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION validate_download_grant_order_consistency();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS orders_validate_download_consistency_trigger ON orders');
        DB::statement('DROP TRIGGER IF EXISTS download_grants_validate_order_consistency_trigger ON download_grants');
        DB::statement('DROP TRIGGER IF EXISTS download_grants_validate_delivery_trigger ON download_grants');
        DB::statement('DROP TRIGGER IF EXISTS download_grants_enforce_immutability_trigger ON download_grants');
        DB::statement('DROP TRIGGER IF EXISTS download_grants_prevent_delete_trigger ON download_grants');
        DB::statement('DROP FUNCTION IF EXISTS validate_download_grant_order_consistency()');
        DB::statement('DROP FUNCTION IF EXISTS validate_download_grant_delivery()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_download_grants_immutability()');
        DB::statement('DROP FUNCTION IF EXISTS prevent_download_grants_delete()');
        Schema::dropIfExists('download_grants');
    }
};
