<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_download_grants_immutability()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                user_fk_nullification BOOLEAN;
                revocation_transition BOOLEAN;
                consumption_transition BOOLEAN;
            BEGIN
                user_fk_nullification := (
                    OLD.user_id IS NOT NULL
                    AND NEW.user_id IS NULL
                    AND pg_trigger_depth() > 1
                );
                revocation_transition := NEW.revoked_at IS DISTINCT FROM OLD.revoked_at;
                consumption_transition := NEW.downloads_count IS DISTINCT FROM OLD.downloads_count;

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

                -- The buyer reference remains an audit denormalisation. Only the nested
                -- ON DELETE SET NULL action may clear it; direct updates run at depth 1.
                IF NOT (
                    NEW.user_id IS NOT DISTINCT FROM OLD.user_id
                    OR user_fk_nullification
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'download_grants user reference may only be nulled by the foreign key action';
                END IF;

                -- Revocation: set-once, paired with its reason, irreversible.
                IF revocation_transition THEN
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

                    IF consumption_transition THEN
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

                -- Consumption remains strictly +1, bounded and usable-only.
                IF consumption_transition THEN
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

                -- updated_at is audit evidence, not a freely writable field. It may
                -- advance only alongside a transition already validated above. The FK
                -- action remains valid when it leaves updated_at unchanged.
                IF NEW.updated_at IS DISTINCT FROM OLD.updated_at THEN
                    IF NEW.updated_at <= OLD.updated_at THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_grants updated_at must move strictly forward';
                    END IF;

                    IF NOT (
                        consumption_transition
                        OR revocation_transition
                        OR user_fk_nullification
                    ) THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_grants updated_at may only change with a valid lifecycle transition';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);

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

                -- Null-safe equality closes the guest-order gap as well as the
                -- authenticated-order mismatch cases.
                IF NOT (NEW.user_id IS NOT DISTINCT FROM order_user_id) THEN
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
                    -- snapshot is NOT detectable and the live pivot is never a fallback.
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
            SQL);
    }

    public function down(): void
    {
        // Restore the exact P4-A2 G2 definition from migration 000010.
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
            SQL);

        // Restore the exact P4-A2 G3 definition from migration 000010.
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
            SQL);
    }
};
