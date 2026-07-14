<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_payment_reference', 255)->nullable();
            $table->string('idempotency_key_hash', 64);
            $table->integer('attempt_number');
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->string('provider_status', 100)->nullable();
            $table->string('provider_method', 64)->nullable();
            $table->jsonb('provider_metadata')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message_sanitized')->nullable();
            $table->timestampTz('initiated_at')->useCurrent();
            $table->timestampTz('processing_at')->nullable();
            $table->timestampTz('succeeded_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampTz('last_verified_at')->nullable();
            $table->timestampsTz();

            $table->unique('public_id', 'payments_public_id_unique');
            $table->unique('idempotency_key_hash', 'payments_idempotency_key_hash_unique');
            $table->unique(['order_id', 'attempt_number'], 'payments_order_id_attempt_number_unique');
            // order_id lookups are served by the leftmost column of the composite unique
            // above; a standalone order_id index would be redundant with it.
            $table->index(['status', 'initiated_at'], 'payments_status_initiated_at_index');
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_provider_format_check CHECK (provider ~ '^[a-z0-9][a-z0-9_-]{0,31}$')");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_idempotency_hash_format_check CHECK (idempotency_key_hash ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_provider_reference_not_blank_check CHECK (provider_payment_reference IS NULL OR length(btrim(provider_payment_reference)) > 0)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_attempt_number_positive_check CHECK (attempt_number >= 1)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive_check CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_currency_format_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_provider_metadata_object_check CHECK (provider_metadata IS NULL OR jsonb_typeof(provider_metadata) = 'object')");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending','processing','requires_review','succeeded','failed','cancelled','expired'))");
        DB::statement(<<<'SQL'
            ALTER TABLE payments
            ADD CONSTRAINT payments_cycle_dates_after_initiated_check
            CHECK (
                (processing_at    IS NULL OR processing_at    >= initiated_at) AND
                (succeeded_at     IS NULL OR succeeded_at     >= initiated_at) AND
                (failed_at        IS NULL OR failed_at        >= initiated_at) AND
                (cancelled_at     IS NULL OR cancelled_at     >= initiated_at) AND
                (expired_at       IS NULL OR expired_at       >= initiated_at) AND
                (last_verified_at IS NULL OR last_verified_at >= initiated_at)
            )
            SQL);

        // A single successful capture and a single manual review per order.
        DB::statement("CREATE UNIQUE INDEX payments_one_succeeded_per_order ON payments (order_id) WHERE status = 'succeeded'");
        DB::statement("CREATE UNIQUE INDEX payments_one_requires_review_per_order ON payments (order_id) WHERE status = 'requires_review'");
        // Provider reference unique per provider when present.
        DB::statement('CREATE UNIQUE INDEX payments_provider_reference_unique ON payments (provider, provider_payment_reference) WHERE provider_payment_reference IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_payments_delete()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'payments are immutable and cannot be deleted';
            END;
            $$;

            CREATE TRIGGER payments_prevent_delete_trigger
            BEFORE DELETE ON payments
            FOR EACH ROW
            EXECUTE FUNCTION prevent_payments_delete();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_payments_immutability()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                -- Commercial identity and money are frozen for the life of the row.
                IF ROW(
                    NEW.id,
                    NEW.public_id,
                    NEW.order_id,
                    NEW.provider,
                    NEW.idempotency_key_hash,
                    NEW.attempt_number,
                    NEW.amount_minor,
                    NEW.currency,
                    NEW.initiated_at,
                    NEW.created_at
                ) IS DISTINCT FROM ROW(
                    OLD.id,
                    OLD.public_id,
                    OLD.order_id,
                    OLD.provider,
                    OLD.idempotency_key_hash,
                    OLD.attempt_number,
                    OLD.amount_minor,
                    OLD.currency,
                    OLD.initiated_at,
                    OLD.created_at
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payments commercial data is immutable';
                END IF;

                -- Provider reference: NULL -> value once, then frozen.
                IF OLD.provider_payment_reference IS NOT NULL
                    AND NEW.provider_payment_reference IS DISTINCT FROM OLD.provider_payment_reference THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payments provider reference is immutable once set';
                END IF;

                -- Cycle dates: NULL -> value once, never cleared nor replaced.
                IF (OLD.processing_at IS NOT NULL AND NEW.processing_at IS DISTINCT FROM OLD.processing_at)
                    OR (OLD.succeeded_at IS NOT NULL AND NEW.succeeded_at IS DISTINCT FROM OLD.succeeded_at)
                    OR (OLD.failed_at IS NOT NULL AND NEW.failed_at IS DISTINCT FROM OLD.failed_at)
                    OR (OLD.cancelled_at IS NOT NULL AND NEW.cancelled_at IS DISTINCT FROM OLD.cancelled_at)
                    OR (OLD.expired_at IS NOT NULL AND NEW.expired_at IS DISTINCT FROM OLD.expired_at) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payments cycle dates are immutable once set';
                END IF;

                -- last_verified_at may only move forward.
                IF OLD.last_verified_at IS NOT NULL
                    AND (NEW.last_verified_at IS NULL OR NEW.last_verified_at < OLD.last_verified_at) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payments last_verified_at cannot move backwards';
                END IF;

                -- Minimal state machine (only checked when status changes).
                IF NEW.status IS DISTINCT FROM OLD.status THEN
                    IF NOT (
                        (OLD.status = 'pending'         AND NEW.status IN ('processing','requires_review','failed','cancelled','expired')) OR
                        (OLD.status = 'processing'      AND NEW.status IN ('succeeded','requires_review','failed','cancelled','expired')) OR
                        (OLD.status = 'failed'          AND NEW.status = 'requires_review') OR
                        (OLD.status = 'cancelled'       AND NEW.status = 'requires_review') OR
                        (OLD.status = 'expired'         AND NEW.status = 'requires_review') OR
                        (OLD.status = 'requires_review' AND NEW.status IN ('succeeded','failed','cancelled','expired'))
                    ) THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'payments status transition is not allowed';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payments_enforce_immutability_trigger
            BEFORE UPDATE ON payments
            FOR EACH ROW
            EXECUTE FUNCTION enforce_payments_immutability();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_payment_order_amount()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                order_total BIGINT;
                order_currency VARCHAR(3);
            BEGIN
                SELECT total_minor, currency
                INTO order_total, order_currency
                FROM orders
                WHERE id = NEW.order_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payments require an existing order';
                END IF;

                IF order_total = 0 THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payments are not allowed on a free order';
                END IF;

                IF NEW.amount_minor IS DISTINCT FROM order_total THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payments amount must equal orders total';
                END IF;

                IF NEW.currency IS DISTINCT FROM order_currency THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payments currency must match orders currency';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payments_validate_order_amount_trigger
            BEFORE INSERT ON payments
            FOR EACH ROW
            EXECUTE FUNCTION validate_payment_order_amount();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_payment_order_consistency()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                target_order_id BIGINT;
                order_total BIGINT;
                order_status TEXT;
                payment_count BIGINT;
                succeeded_count BIGINT;
                review_count BIGINT;
            BEGIN
                IF TG_TABLE_NAME = 'orders' THEN
                    target_order_id := NEW.id;
                ELSE
                    target_order_id := NEW.order_id;
                END IF;

                SELECT total_minor, status
                INTO order_total, order_status
                FROM orders
                WHERE id = target_order_id;

                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                SELECT
                    COUNT(*),
                    COUNT(*) FILTER (WHERE status = 'succeeded'),
                    COUNT(*) FILTER (WHERE status = 'requires_review')
                INTO payment_count, succeeded_count, review_count
                FROM payments
                WHERE order_id = target_order_id;

                IF order_total = 0 THEN
                    IF payment_count <> 0 THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'free orders must not have any payment';
                    END IF;
                    RETURN NULL;
                END IF;

                IF order_status IN ('paid', 'partially_refunded', 'refunded') THEN
                    IF succeeded_count <> 1 OR review_count <> 0 THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'paid orders require exactly one succeeded payment';
                    END IF;
                ELSIF order_status = 'payment_review' THEN
                    IF review_count <> 1 OR succeeded_count <> 0 THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'payment_review orders require exactly one requires_review payment';
                    END IF;
                ELSE
                    IF succeeded_count <> 0 OR review_count <> 0 THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'unpaid orders must not have succeeded or review payments';
                    END IF;
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER payments_validate_order_consistency_trigger
            AFTER INSERT OR UPDATE OF status ON payments
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION validate_payment_order_consistency();

            CREATE CONSTRAINT TRIGGER orders_validate_payment_consistency_trigger
            AFTER INSERT OR UPDATE OF status ON orders
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION validate_payment_order_consistency();
            SQL);
    }

    public function down(): void
    {
        // 1. Trigger placed on orders.
        DB::statement('DROP TRIGGER IF EXISTS orders_validate_payment_consistency_trigger ON orders');
        // 2. Triggers placed on payments.
        DB::statement('DROP TRIGGER IF EXISTS payments_validate_order_consistency_trigger ON payments');
        DB::statement('DROP TRIGGER IF EXISTS payments_validate_order_amount_trigger ON payments');
        DB::statement('DROP TRIGGER IF EXISTS payments_enforce_immutability_trigger ON payments');
        DB::statement('DROP TRIGGER IF EXISTS payments_prevent_delete_trigger ON payments');
        // 3. Functions.
        DB::statement('DROP FUNCTION IF EXISTS validate_payment_order_consistency()');
        DB::statement('DROP FUNCTION IF EXISTS validate_payment_order_amount()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_payments_immutability()');
        DB::statement('DROP FUNCTION IF EXISTS prevent_payments_delete()');
        // 4. Table.
        Schema::dropIfExists('payments');
    }
};
