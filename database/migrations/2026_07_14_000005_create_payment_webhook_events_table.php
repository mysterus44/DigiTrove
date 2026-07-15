<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('external_event_id', 255)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_type', 100)->nullable();
            $table->string('payload_hash', 64);
            $table->jsonb('filtered_payload')->nullable();
            $table->boolean('signature_verified');
            $table->string('processing_status', 20)->default('received');
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('retention_until')->nullable();
            $table->text('processing_error_sanitized')->nullable();
            $table->timestampsTz();

            $table->index('payment_id', 'payment_webhook_events_payment_id_index');
            $table->index(['processing_status', 'received_at'], 'payment_webhook_events_processing_received_index');
            $table->index('retention_until', 'payment_webhook_events_retention_until_index');
        });

        DB::statement("ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_provider_format_check CHECK (provider ~ '^[a-z0-9][a-z0-9_-]{0,31}$')");
        DB::statement('ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_external_id_not_blank_check CHECK (external_event_id IS NULL OR length(btrim(external_event_id)) > 0)');
        DB::statement('ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_event_type_not_blank_check CHECK (event_type IS NULL OR length(btrim(event_type)) > 0)');
        DB::statement("ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_payload_hash_format_check CHECK (payload_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_filtered_payload_object_check CHECK (filtered_payload IS NULL OR jsonb_typeof(filtered_payload) = 'object')");
        DB::statement("ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_processing_status_check CHECK (processing_status IN ('received','processed','ignored','failed'))");
        DB::statement(<<<'SQL'
            ALTER TABLE payment_webhook_events
            ADD CONSTRAINT payment_webhook_events_cycle_dates_check
            CHECK (
                (processed_at    IS NULL OR processed_at    >= received_at) AND
                (failed_at       IS NULL OR failed_at       >= received_at) AND
                (retention_until IS NULL OR retention_until >= received_at)
            )
            SQL);
        // Strict status/date coherence — CASE ... ELSE FALSE END IS TRUE avoids CHECK = UNKNOWN.
        DB::statement(<<<'SQL'
            ALTER TABLE payment_webhook_events
            ADD CONSTRAINT payment_webhook_events_status_dates_consistency_check
            CHECK (
                CASE
                    WHEN processing_status = 'received'
                        THEN processed_at IS NULL AND failed_at IS NULL
                    WHEN processing_status = 'processed'
                        THEN processed_at IS NOT NULL AND failed_at IS NULL
                    WHEN processing_status = 'ignored'
                        THEN processed_at IS NOT NULL AND failed_at IS NULL
                    WHEN processing_status = 'failed'
                        THEN failed_at IS NOT NULL AND processed_at IS NULL
                    ELSE FALSE
                END IS TRUE
            )
            SQL);
        // A signature-verified event must carry a non-blank external id.
        DB::statement('ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_signed_requires_external_id_check CHECK (signature_verified = false OR (external_event_id IS NOT NULL AND length(btrim(external_event_id)) > 0))');
        // An invalid-signature event is kept in a strict minimal shape (D-028.4). IS TRUE guards against UNKNOWN.
        DB::statement(<<<'SQL'
            ALTER TABLE payment_webhook_events
            ADD CONSTRAINT payment_webhook_events_invalid_minimal_shape_check
            CHECK (
                (
                    signature_verified = true
                    OR (
                        processing_status = 'failed'
                        AND filtered_payload IS NULL
                        AND payment_id IS NULL
                        AND failed_at IS NOT NULL
                        AND processed_at IS NULL
                        AND processing_error_sanitized IS NOT NULL
                        AND length(btrim(processing_error_sanitized)) > 0
                    )
                ) IS TRUE
            )
            SQL);

        // Anti double-webhook signé (rejeu -> ligne existante, réponse idempotente au niveau service).
        DB::statement('CREATE UNIQUE INDEX payment_webhook_events_provider_external_event_unique ON payment_webhook_events (provider, external_event_id) WHERE external_event_id IS NOT NULL');
        // Dédup des invalides sans identifiant externe fiable.
        DB::statement('CREATE UNIQUE INDEX payment_webhook_events_provider_payload_hash_unique ON payment_webhook_events (provider, payload_hash) WHERE signature_verified = false');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_webhook_event_immutability()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                -- Audit identity and the filtered snapshot are frozen for the life of the row.
                IF ROW(
                    NEW.id,
                    NEW.provider,
                    NEW.external_event_id,
                    NEW.payload_hash,
                    NEW.signature_verified,
                    NEW.filtered_payload,
                    NEW.received_at,
                    NEW.created_at
                ) IS DISTINCT FROM ROW(
                    OLD.id,
                    OLD.provider,
                    OLD.external_event_id,
                    OLD.payload_hash,
                    OLD.signature_verified,
                    OLD.filtered_payload,
                    OLD.received_at,
                    OLD.created_at
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payment_webhook_events audit data is immutable';
                END IF;

                -- Payment link: NULL -> value once, then frozen.
                IF OLD.payment_id IS NOT NULL
                    AND NEW.payment_id IS DISTINCT FROM OLD.payment_id THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payment_webhook_events payment reference is immutable once set';
                END IF;

                -- event_type: NULL -> value once, then frozen.
                IF OLD.event_type IS NOT NULL
                    AND NEW.event_type IS DISTINCT FROM OLD.event_type THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payment_webhook_events event_type is immutable once set';
                END IF;

                -- Processing dates: NULL -> value once, then frozen.
                IF (OLD.processed_at IS NOT NULL AND NEW.processed_at IS DISTINCT FROM OLD.processed_at)
                    OR (OLD.failed_at IS NOT NULL AND NEW.failed_at IS DISTINCT FROM OLD.failed_at) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payment_webhook_events processing dates are immutable once set';
                END IF;

                -- retention_until: NULL -> value, extension only; never shortened nor cleared.
                IF OLD.retention_until IS NOT NULL
                    AND (NEW.retention_until IS NULL OR NEW.retention_until < OLD.retention_until) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payment_webhook_events retention_until cannot be shortened or cleared';
                END IF;

                -- processing_error_sanitized: NULL -> value once, then frozen.
                IF OLD.processing_error_sanitized IS NOT NULL
                    AND NEW.processing_error_sanitized IS DISTINCT FROM OLD.processing_error_sanitized THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payment_webhook_events processing_error_sanitized is immutable once set';
                END IF;

                -- Minimal state machine: received -> processed|ignored|failed ; terminals are final.
                IF NEW.processing_status IS DISTINCT FROM OLD.processing_status THEN
                    IF NOT (
                        OLD.processing_status = 'received'
                        AND NEW.processing_status IN ('processed', 'ignored', 'failed')
                    ) THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'payment_webhook_events processing status transition is not allowed';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payment_webhook_events_enforce_immutability_trigger
            BEFORE UPDATE ON payment_webhook_events
            FOR EACH ROW
            EXECUTE FUNCTION enforce_webhook_event_immutability();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_webhook_payment_consistency()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                payment_provider VARCHAR(32);
            BEGIN
                IF NEW.payment_id IS NOT NULL THEN
                    IF NEW.signature_verified = false THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'payment_webhook_events cannot link an unsigned event to a payment';
                    END IF;

                    SELECT provider INTO payment_provider
                    FROM payments
                    WHERE id = NEW.payment_id;

                    -- Existence of the payment is left to the foreign key (RESTRICT).
                    IF FOUND AND payment_provider IS DISTINCT FROM NEW.provider THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'payment_webhook_events provider must match the linked payment provider';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payment_webhook_events_validate_payment_consistency_trigger
            BEFORE INSERT OR UPDATE OF payment_id ON payment_webhook_events
            FOR EACH ROW
            EXECUTE FUNCTION validate_webhook_payment_consistency();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_webhook_event_retention_delete()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                -- The trigger only authorises or refuses; it never deletes by itself.
                IF NOT (
                    OLD.processing_status IN ('processed', 'ignored', 'failed')
                    AND OLD.retention_until IS NOT NULL
                    AND OLD.retention_until <= CURRENT_TIMESTAMP
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payment_webhook_events may only be deleted after retention on a terminal status';
                END IF;

                RETURN OLD;
            END;
            $$;

            CREATE TRIGGER payment_webhook_events_enforce_retention_delete_trigger
            BEFORE DELETE ON payment_webhook_events
            FOR EACH ROW
            EXECUTE FUNCTION enforce_webhook_event_retention_delete();
            SQL);
    }

    public function down(): void
    {
        // 1. Triggers.
        DB::statement('DROP TRIGGER IF EXISTS payment_webhook_events_enforce_retention_delete_trigger ON payment_webhook_events');
        DB::statement('DROP TRIGGER IF EXISTS payment_webhook_events_validate_payment_consistency_trigger ON payment_webhook_events');
        DB::statement('DROP TRIGGER IF EXISTS payment_webhook_events_enforce_immutability_trigger ON payment_webhook_events');
        // 2. Functions.
        DB::statement('DROP FUNCTION IF EXISTS enforce_webhook_event_retention_delete()');
        DB::statement('DROP FUNCTION IF EXISTS validate_webhook_payment_consistency()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_webhook_event_immutability()');
        // 3. Table.
        Schema::dropIfExists('payment_webhook_events');
    }
};
