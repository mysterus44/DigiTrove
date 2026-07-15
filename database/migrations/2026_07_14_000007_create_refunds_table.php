<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->text('provider_refund_reference')->nullable();
            $table->string('idempotency_key_hash', 64);
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->text('reason_code')->nullable();
            $table->text('reason_note_sanitized')->nullable();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('provider_status')->nullable();
            $table->jsonb('provider_metadata')->nullable();
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('processing_at')->nullable();
            $table->timestampTz('succeeded_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('last_verified_at')->nullable();
            $table->timestampsTz();

            $table->unique('public_id', 'refunds_public_id_unique');
            $table->unique('idempotency_key_hash', 'refunds_idempotency_key_hash_unique');
            $table->index('payment_id', 'refunds_payment_id_index');
            $table->index(['payment_id', 'status'], 'refunds_payment_id_status_index');
        });

        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_provider_format_check CHECK (provider ~ '^[a-z0-9][a-z0-9_-]{0,31}$')");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_idempotency_hash_format_check CHECK (idempotency_key_hash ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_provider_reference_not_blank_check CHECK (provider_refund_reference IS NULL OR length(btrim(provider_refund_reference)) > 0)');
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive_check CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_currency_format_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('pending','processing','succeeded','failed','cancelled'))");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_reason_note_not_blank_check CHECK (reason_note_sanitized IS NULL OR length(btrim(reason_note_sanitized)) > 0)');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_provider_metadata_object_check CHECK (provider_metadata IS NULL OR jsonb_typeof(provider_metadata) = 'object')");
        DB::statement(<<<'SQL'
            ALTER TABLE refunds
            ADD CONSTRAINT refunds_cycle_dates_check
            CHECK (
                (processing_at    IS NULL OR processing_at    >= requested_at) AND
                (succeeded_at     IS NULL OR succeeded_at     >= requested_at) AND
                (failed_at        IS NULL OR failed_at        >= requested_at) AND
                (cancelled_at     IS NULL OR cancelled_at     >= requested_at) AND
                (last_verified_at IS NULL OR last_verified_at >= requested_at)
            )
            SQL);
        // Strict status/date coherence (CASE ... ELSE FALSE END IS TRUE avoids CHECK = UNKNOWN).
        DB::statement(<<<'SQL'
            ALTER TABLE refunds
            ADD CONSTRAINT refunds_status_dates_consistency_check
            CHECK (
                CASE
                    WHEN status = 'pending'    THEN succeeded_at IS NULL AND failed_at IS NULL AND cancelled_at IS NULL
                    WHEN status = 'processing' THEN succeeded_at IS NULL AND failed_at IS NULL AND cancelled_at IS NULL
                    WHEN status = 'succeeded'  THEN succeeded_at IS NOT NULL AND failed_at IS NULL AND cancelled_at IS NULL
                    WHEN status = 'failed'     THEN failed_at IS NOT NULL AND succeeded_at IS NULL AND cancelled_at IS NULL
                    WHEN status = 'cancelled'  THEN cancelled_at IS NOT NULL AND succeeded_at IS NULL AND failed_at IS NULL
                    ELSE FALSE
                END IS TRUE
            )
            SQL);

        // Provider refund reference unique per provider when present.
        DB::statement('CREATE UNIQUE INDEX refunds_provider_reference_unique ON refunds (provider, provider_refund_reference) WHERE provider_refund_reference IS NOT NULL');
        DB::statement('CREATE INDEX refunds_status_requested_index ON refunds (status, requested_at DESC)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_refunds_delete()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'refunds are immutable and cannot be deleted';
            END;
            $$;

            CREATE TRIGGER refunds_prevent_delete_trigger
            BEFORE DELETE ON refunds
            FOR EACH ROW
            EXECUTE FUNCTION prevent_refunds_delete();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_refunds_immutability()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF ROW(
                    NEW.id,
                    NEW.public_id,
                    NEW.payment_id,
                    NEW.provider,
                    NEW.idempotency_key_hash,
                    NEW.amount_minor,
                    NEW.currency,
                    NEW.reason_code,
                    NEW.requested_at,
                    NEW.created_at
                ) IS DISTINCT FROM ROW(
                    OLD.id,
                    OLD.public_id,
                    OLD.payment_id,
                    OLD.provider,
                    OLD.idempotency_key_hash,
                    OLD.amount_minor,
                    OLD.currency,
                    OLD.reason_code,
                    OLD.requested_at,
                    OLD.created_at
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'refunds commercial data is immutable';
                END IF;

                -- The initiator is historical identity: only the nested FK action
                -- triggered by deleting the user may null it. A direct UPDATE runs
                -- at trigger depth 1 and must not impersonate ON DELETE SET NULL.
                IF NOT (
                    NEW.initiated_by_user_id IS NOT DISTINCT FROM OLD.initiated_by_user_id
                    OR (
                        OLD.initiated_by_user_id IS NOT NULL
                        AND NEW.initiated_by_user_id IS NULL
                        AND pg_trigger_depth() > 1
                    )
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'refunds initiator reference may only be nulled';
                END IF;

                -- Provider reference: NULL -> value once, then frozen.
                IF OLD.provider_refund_reference IS NOT NULL
                    AND NEW.provider_refund_reference IS DISTINCT FROM OLD.provider_refund_reference THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'refunds provider reference is immutable once set';
                END IF;

                -- Lifecycle dates: NULL -> value once, then frozen.
                IF (OLD.processing_at IS NOT NULL AND NEW.processing_at IS DISTINCT FROM OLD.processing_at)
                    OR (OLD.succeeded_at IS NOT NULL AND NEW.succeeded_at IS DISTINCT FROM OLD.succeeded_at)
                    OR (OLD.failed_at IS NOT NULL AND NEW.failed_at IS DISTINCT FROM OLD.failed_at)
                    OR (OLD.cancelled_at IS NOT NULL AND NEW.cancelled_at IS DISTINCT FROM OLD.cancelled_at) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'refunds lifecycle dates are immutable once set';
                END IF;

                -- last_verified_at may only move forward.
                IF OLD.last_verified_at IS NOT NULL
                    AND (NEW.last_verified_at IS NULL OR NEW.last_verified_at < OLD.last_verified_at) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'refunds last_verified_at cannot move backwards';
                END IF;

                -- Minimal state machine.
                IF NEW.status IS DISTINCT FROM OLD.status THEN
                    IF NOT (
                        (OLD.status = 'pending'    AND NEW.status IN ('processing','failed','cancelled')) OR
                        (OLD.status = 'processing' AND NEW.status IN ('succeeded','failed','cancelled'))
                    ) THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'refunds status transition is not allowed';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER refunds_enforce_immutability_trigger
            BEFORE UPDATE ON refunds
            FOR EACH ROW
            EXECUTE FUNCTION enforce_refunds_immutability();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_refund_payment_consistency()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                pay_provider VARCHAR(32);
                pay_currency VARCHAR(3);
                pay_status TEXT;
                pay_amount BIGINT;
            BEGIN
                SELECT provider, currency, status, amount_minor
                INTO pay_provider, pay_currency, pay_status, pay_amount
                FROM payments
                WHERE id = NEW.payment_id;

                -- Non-existent payment is left to the foreign key (RESTRICT).
                IF NOT FOUND THEN
                    RETURN NEW;
                END IF;

                IF pay_status <> 'succeeded' THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'refunds require a succeeded payment';
                END IF;

                IF pay_amount <= 0 THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'refunds cannot target a non-positive payment amount';
                END IF;

                IF NEW.provider IS DISTINCT FROM pay_provider THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'refunds provider must match the payment provider';
                END IF;

                IF NEW.currency IS DISTINCT FROM pay_currency THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'refunds currency must match the payment currency';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER refunds_validate_payment_consistency_trigger
            BEFORE INSERT ON refunds
            FOR EACH ROW
            EXECUTE FUNCTION validate_refund_payment_consistency();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_refund_cumulative_cap()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                pay_amount BIGINT;
                pay_status TEXT;
                succeeded_sum BIGINT;
            BEGIN
                -- Only INSERT already succeeded or a transition into succeeded contributes.
                IF NEW.status <> 'succeeded'
                    OR (TG_OP = 'UPDATE' AND OLD.status = 'succeeded') THEN
                    RETURN NEW;
                END IF;

                -- Serialise all refunds of this payment on the payments row.
                SELECT amount_minor, status
                INTO pay_amount, pay_status
                FROM payments
                WHERE id = NEW.payment_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'refunds require an existing payment';
                END IF;

                IF pay_status <> 'succeeded' THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'refunds require a succeeded payment';
                END IF;

                SELECT COALESCE(SUM(amount_minor), 0)::BIGINT
                INTO succeeded_sum
                FROM refunds
                WHERE payment_id = NEW.payment_id
                    AND status = 'succeeded'
                    AND id <> NEW.id;

                IF succeeded_sum + NEW.amount_minor > pay_amount THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'refunds succeeded total exceeds the captured payment amount';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER refunds_enforce_cumulative_cap_trigger
            BEFORE INSERT OR UPDATE OF status ON refunds
            FOR EACH ROW
            EXECUTE FUNCTION enforce_refund_cumulative_cap();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_refund_order_consistency()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                target_order_id BIGINT;
                succeeded_payment_id BIGINT;
                pay_amount BIGINT;
                order_status TEXT;
                succeeded_sum BIGINT;
                expected_status TEXT;
            BEGIN
                IF TG_TABLE_NAME = 'orders' THEN
                    target_order_id := NEW.id;
                ELSE
                    SELECT order_id INTO target_order_id FROM payments WHERE id = NEW.payment_id;
                END IF;

                IF target_order_id IS NULL THEN
                    RETURN NULL;
                END IF;

                -- The unique succeeded payment of the order (P3C-A guarantees at most one).
                SELECT p.id, p.amount_minor, o.status
                INTO succeeded_payment_id, pay_amount, order_status
                FROM orders o
                JOIN payments p ON p.order_id = o.id AND p.status = 'succeeded'
                WHERE o.id = target_order_id;

                -- No succeeded payment (free/unpaid order): refund consistency does not apply.
                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(SUM(amount_minor), 0)::BIGINT
                INTO succeeded_sum
                FROM refunds
                WHERE payment_id = succeeded_payment_id
                    AND status = 'succeeded';

                IF succeeded_sum > pay_amount THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'refunds succeeded total exceeds the captured payment amount';
                END IF;

                IF succeeded_sum = 0 THEN
                    expected_status := 'paid';
                ELSIF succeeded_sum < pay_amount THEN
                    expected_status := 'partially_refunded';
                ELSE
                    expected_status := 'refunded';
                END IF;

                IF order_status IS DISTINCT FROM expected_status THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'refunds total is inconsistent with the order status';
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER refunds_validate_order_consistency_trigger
            AFTER INSERT OR UPDATE OF status, amount_minor ON refunds
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION validate_refund_order_consistency();

            CREATE CONSTRAINT TRIGGER orders_validate_refund_consistency_trigger
            AFTER INSERT OR UPDATE OF status ON orders
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION validate_refund_order_consistency();
            SQL);
    }

    public function down(): void
    {
        // 1. Trigger placed on orders.
        DB::statement('DROP TRIGGER IF EXISTS orders_validate_refund_consistency_trigger ON orders');
        // 2. Triggers placed on refunds.
        DB::statement('DROP TRIGGER IF EXISTS refunds_validate_order_consistency_trigger ON refunds');
        DB::statement('DROP TRIGGER IF EXISTS refunds_enforce_cumulative_cap_trigger ON refunds');
        DB::statement('DROP TRIGGER IF EXISTS refunds_validate_payment_consistency_trigger ON refunds');
        DB::statement('DROP TRIGGER IF EXISTS refunds_enforce_immutability_trigger ON refunds');
        DB::statement('DROP TRIGGER IF EXISTS refunds_prevent_delete_trigger ON refunds');
        // 3. Functions.
        DB::statement('DROP FUNCTION IF EXISTS validate_refund_order_consistency()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_refund_cumulative_cap()');
        DB::statement('DROP FUNCTION IF EXISTS validate_refund_payment_consistency()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_refunds_immutability()');
        DB::statement('DROP FUNCTION IF EXISTS prevent_refunds_delete()');
        // 4. Table.
        Schema::dropIfExists('refunds');
    }
};
