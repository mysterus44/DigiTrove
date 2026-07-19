<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // P4-B (D-029.5): one commercial download attempt per row. The row is the
        // audit trail of the consumption; the grant counter and the `started` log
        // are paired atomically by G5. No business DEFAULT exists: status, quota
        // marker, attempt window and retention are always explicit.
        Schema::create('download_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->foreignId('download_grant_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->boolean('quota_consumed');
            // SHA-256 digest of a dedicated attempt secret (CSPRNG, distinct from
            // the grant token). The raw secret is NEVER stored, logged or echoed.
            $table->string('attempt_token_hash', 64)->nullable();
            $table->timestampTz('attempt_expires_at')->nullable();
            $table->string('denial_reason_code', 64)->nullable();
            // HMAC-SHA-256 of the client IP (key lives outside the database),
            // paired with the non-secret key version. Never the raw IP.
            $table->string('ip_hash', 64)->nullable();
            $table->smallInteger('ip_hash_key_version')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->bigInteger('bytes_sent')->nullable();
            $table->timestampTz('terminal_at')->nullable();
            // Explicit retention (2A): 365 days is only a recommended application
            // configuration, never a database DEFAULT.
            $table->timestampTz('retention_until');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE download_logs ADD CONSTRAINT download_logs_public_id_unique UNIQUE (public_id)');
        DB::statement("ALTER TABLE download_logs ADD CONSTRAINT download_logs_status_check CHECK (status IN ('started', 'completed', 'denied'))");
        // Strict state machine, hardened against CHECK = UNKNOWN. G5 additionally
        // distinguishes a consuming denial (only reachable from `started`) from a
        // direct non-consuming denial at INSERT time.
        DB::statement(<<<'SQL'
            ALTER TABLE download_logs ADD CONSTRAINT download_logs_state_consistency_check CHECK (
                (CASE
                    WHEN status = 'started' THEN
                        quota_consumed IS TRUE
                        AND attempt_token_hash IS NOT NULL
                        AND attempt_expires_at IS NOT NULL
                        AND denial_reason_code IS NULL
                        AND terminal_at IS NULL
                    WHEN status = 'completed' THEN
                        quota_consumed IS TRUE
                        AND attempt_token_hash IS NOT NULL
                        AND attempt_expires_at IS NOT NULL
                        AND denial_reason_code IS NULL
                        AND terminal_at IS NOT NULL
                    WHEN status = 'denied' THEN
                        denial_reason_code IS NOT NULL
                        AND terminal_at IS NOT NULL
                        AND (
                            (quota_consumed IS TRUE
                                AND attempt_token_hash IS NOT NULL
                                AND attempt_expires_at IS NOT NULL)
                            OR
                            (quota_consumed IS FALSE
                                AND attempt_token_hash IS NULL
                                AND attempt_expires_at IS NULL)
                        )
                    ELSE FALSE
                END) IS TRUE
            )
        SQL);
        // Closed, sanitized denial vocabulary: never a free-text exception, token,
        // digest, email, path or provider message.
        DB::statement(<<<'SQL'
            ALTER TABLE download_logs ADD CONSTRAINT download_logs_denial_reason_code_check CHECK (
                (CASE
                    WHEN denial_reason_code IS NULL THEN status <> 'denied'
                    ELSE denial_reason_code IN (
                        'authorization_denied',
                        'quota_exhausted',
                        'grant_expired',
                        'grant_revoked',
                        'order_not_deliverable',
                        'product_file_unavailable',
                        'attempt_expired',
                        'delivery_interrupted',
                        'storage_failure',
                        'internal_error'
                    )
                END) IS TRUE
            )
        SQL);
        DB::statement("ALTER TABLE download_logs ADD CONSTRAINT download_logs_attempt_hash_format_check CHECK (attempt_token_hash IS NULL OR attempt_token_hash ~ '^[0-9a-f]{64}$')");
        // The attempt secret and its expiry live and die together, inside the
        // audit window: strictly after birth, never beyond retention.
        DB::statement(<<<'SQL'
            ALTER TABLE download_logs ADD CONSTRAINT download_logs_attempt_window_check CHECK (
                (CASE
                    WHEN attempt_token_hash IS NULL THEN attempt_expires_at IS NULL
                    ELSE attempt_expires_at IS NOT NULL
                        AND attempt_expires_at > created_at
                        AND attempt_expires_at <= retention_until
                END) IS TRUE
            )
        SQL);
        DB::statement('ALTER TABLE download_logs ADD CONSTRAINT download_logs_terminal_timestamp_check CHECK (terminal_at IS NULL OR terminal_at >= created_at)');
        DB::statement('ALTER TABLE download_logs ADD CONSTRAINT download_logs_retention_after_created_check CHECK (retention_until > created_at)');
        DB::statement(<<<'SQL'
            ALTER TABLE download_logs ADD CONSTRAINT download_logs_ip_identity_check CHECK (
                (CASE
                    WHEN ip_hash IS NULL THEN ip_hash_key_version IS NULL
                    ELSE ip_hash ~ '^[0-9a-f]{64}$'
                        AND ip_hash_key_version IS NOT NULL
                        AND ip_hash_key_version > 0
                END) IS TRUE
            )
        SQL);
        DB::statement("ALTER TABLE download_logs ADD CONSTRAINT download_logs_user_agent_not_blank_check CHECK (user_agent IS NULL OR length(btrim(user_agent, E' \t\n\r\f\v')) > 0)");
        DB::statement('ALTER TABLE download_logs ADD CONSTRAINT download_logs_bytes_sent_non_negative_check CHECK (bytes_sent IS NULL OR bytes_sent >= 0)');

        // One row per authenticated attempt (R1A): Range segments and retries of
        // the same attempt reuse this digest, so a second consuming row with the
        // same secret is structurally impossible. No index predicate uses now().
        DB::statement('CREATE UNIQUE INDEX download_logs_attempt_token_hash_unique ON download_logs (attempt_token_hash) WHERE attempt_token_hash IS NOT NULL');
        DB::statement('CREATE INDEX download_logs_grant_created_index ON download_logs (download_grant_id, created_at DESC)');
        DB::statement("CREATE INDEX download_logs_active_attempts_index ON download_logs (download_grant_id, attempt_expires_at) WHERE status = 'started'");
        DB::statement("CREATE INDEX download_logs_terminal_retention_index ON download_logs (retention_until) WHERE status IN ('completed', 'denied')");

        // G5 — the single explicitly mutating P4 authority (D-029.5). On INSERT
        // `started` it locks the Order FIRST, then the Grant (global lock order),
        // revalidates everything, and performs the exact +1 so that the log row
        // and the counter can only ever commit or roll back together. It validates
        // application-supplied values and never generates a token, digest, UUID,
        // IP hash or business code itself.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_download_logs_integrity()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                grant_order_item_id BIGINT;
                grant_product_file_id BIGINT;
                grant_token_digest TEXT;
                grant_revoked_at TIMESTAMPTZ;
                grant_expires_at TIMESTAMPTZ;
                grant_downloads_count BIGINT;
                grant_max_downloads BIGINT;
                order_status TEXT;
                file_is_active BOOLEAN;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    -- Bytes only ever progress on an existing `started` row.
                    IF NEW.bytes_sent IS NOT NULL THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_logs are born without bytes progression';
                    END IF;

                    -- `completed` only exists as a transition from `started`.
                    IF NEW.status = 'completed' THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_logs completed can only result from a started attempt';
                    END IF;

                    IF NEW.status = 'denied' THEN
                        -- A direct denial documents a refusal on a KNOWN grant
                        -- before any consumption. A consuming denial is only
                        -- reachable through the started -> denied transition.
                        IF NEW.quota_consumed IS TRUE THEN
                            RAISE EXCEPTION USING
                                ERRCODE = '23514',
                                MESSAGE = 'download_logs direct denials never consume quota';
                        END IF;

                        RETURN NEW;
                    END IF;

                    -- Unknown status: the named CHECK constraint is the authority.
                    IF NEW.status IS DISTINCT FROM 'started' THEN
                        RETURN NEW;
                    END IF;

                    -- Minimal grant read to identify the order. An unknown grant is
                    -- left to the foreign key, the most precise authority.
                    SELECT dg.order_item_id
                    INTO grant_order_item_id
                    FROM download_grants dg
                    WHERE dg.id = NEW.download_grant_id;

                    IF NOT FOUND THEN
                        RETURN NEW;
                    END IF;

                    -- Global lock order: orders FIRST, then download_grants. This
                    -- serialises consumption against refunds and revocations
                    -- without ever crossing locks.
                    SELECT o.status
                    INTO order_status
                    FROM order_items oi
                    JOIN orders o ON o.id = oi.order_id
                    WHERE oi.id = grant_order_item_id
                    FOR UPDATE OF o;

                    SELECT dg.product_file_id, dg.token_hash, dg.revoked_at, dg.expires_at, dg.downloads_count, dg.max_downloads
                    INTO grant_product_file_id, grant_token_digest, grant_revoked_at, grant_expires_at, grant_downloads_count, grant_max_downloads
                    FROM download_grants dg
                    WHERE dg.id = NEW.download_grant_id
                    FOR UPDATE;

                    -- Full revalidation under both locks.
                    IF order_status NOT IN ('paid', 'partially_refunded') THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_logs require a deliverable order';
                    END IF;

                    IF grant_revoked_at IS NOT NULL THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_logs cannot consume a revoked grant';
                    END IF;

                    IF grant_expires_at <= now() THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_logs cannot consume an expired grant';
                    END IF;

                    IF grant_downloads_count >= grant_max_downloads THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_logs cannot consume an exhausted grant quota';
                    END IF;

                    SELECT pf.is_active
                    INTO file_is_active
                    FROM product_files pf
                    WHERE pf.id = grant_product_file_id;

                    IF NOT FOUND OR file_is_active IS NOT TRUE THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_logs require an active product file';
                    END IF;

                    -- The attempt secret is a DEDICATED credential: reusing the
                    -- grant token digest would let one leaked value open both doors.
                    IF NEW.attempt_token_hash IS NOT DISTINCT FROM grant_token_digest THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_logs attempt digest must differ from the grant token digest';
                    END IF;

                    -- The single consumption (1A): exactly +1, atomic with this
                    -- INSERT — either both commit or both roll back. The explicit
                    -- updated_at stays STRICTLY forward even for several
                    -- consumptions inside one transaction or one wall-clock
                    -- second: the column stores whole seconds (timestamptz(0)),
                    -- so one second is the smallest guaranteed forward step. The
                    -- hardened G2 accepts this UPDATE only at trigger depth 2.
                    UPDATE download_grants
                    SET downloads_count = downloads_count + 1,
                        updated_at = GREATEST(clock_timestamp(), updated_at + interval '1 second')
                    WHERE id = NEW.download_grant_id;

                    RETURN NEW;
                END IF;

                -- UPDATE: identity, lineage, quota marker, attempt secret and
                -- minimised audit identity are immutable in every state.
                IF ROW(
                    NEW.id,
                    NEW.public_id,
                    NEW.download_grant_id,
                    NEW.quota_consumed,
                    NEW.attempt_token_hash,
                    NEW.attempt_expires_at,
                    NEW.ip_hash,
                    NEW.ip_hash_key_version,
                    NEW.user_agent,
                    NEW.created_at
                ) IS DISTINCT FROM ROW(
                    OLD.id,
                    OLD.public_id,
                    OLD.download_grant_id,
                    OLD.quota_consumed,
                    OLD.attempt_token_hash,
                    OLD.attempt_expires_at,
                    OLD.ip_hash,
                    OLD.ip_hash_key_version,
                    OLD.user_agent,
                    OLD.created_at
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'download_logs identity, attempt secret and audit fields are immutable';
                END IF;

                -- Retention: extension only, an identical value is accepted.
                IF NEW.retention_until < OLD.retention_until THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'download_logs retention may only be extended';
                END IF;

                IF OLD.status IN ('completed', 'denied') THEN
                    -- Terminal rows never reopen, re-terminalise or rewrite: the
                    -- retention extension above is their single allowed change.
                    IF NEW.status IS DISTINCT FROM OLD.status
                        OR NEW.denial_reason_code IS DISTINCT FROM OLD.denial_reason_code
                        OR NEW.terminal_at IS DISTINCT FROM OLD.terminal_at
                        OR NEW.bytes_sent IS DISTINCT FROM OLD.bytes_sent THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_logs terminal rows only accept a retention extension';
                    END IF;

                    RETURN NEW;
                END IF;

                -- OLD.status = 'started'. bytes_sent is NULL or monotone: it never
                -- shrinks, never resets, and never proves client reception (R3A).
                IF NEW.bytes_sent IS DISTINCT FROM OLD.bytes_sent THEN
                    IF NEW.bytes_sent IS NULL
                        OR (OLD.bytes_sent IS NOT NULL AND NEW.bytes_sent < OLD.bytes_sent) THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_logs bytes_sent may only progress forward';
                    END IF;
                END IF;

                IF NEW.status = 'started' THEN
                    -- started -> started only carries bytes progression and/or a
                    -- retention extension; terminal fields stay untouched.
                    IF NEW.denial_reason_code IS NOT NULL OR NEW.terminal_at IS NOT NULL THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_logs started rows cannot carry terminal fields';
                    END IF;

                    RETURN NEW;
                END IF;

                -- Unknown target status: the named CHECK constraint is the authority.
                IF NEW.status NOT IN ('completed', 'denied') THEN
                    RETURN NEW;
                END IF;

                -- Transition started -> completed | denied. `completed` records the
                -- successful handoff to the delivery mechanism, never full client
                -- reception. Quota is never given back on started -> denied.
                IF NEW.terminal_at IS NULL THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'download_logs transitions must set the terminal timestamp';
                END IF;

                IF NEW.status = 'completed' AND NEW.denial_reason_code IS NOT NULL THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'download_logs completed never carries a denial reason';
                END IF;

                IF NEW.status = 'denied' AND NEW.denial_reason_code IS NULL THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'download_logs denials require a sanitized reason code';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER download_logs_enforce_integrity_trigger
            BEFORE INSERT OR UPDATE ON download_logs
            FOR EACH ROW
            EXECUTE FUNCTION enforce_download_logs_integrity();
            SQL);

        // G6 — controlled purge: download_logs is the ONLY purgeable P4 table
        // (retention-driven, GDPR). A multi-row DELETE containing one ineligible
        // row fails atomically. Purging never decrements the grant counter, never
        // returns quota and never touches Grant/OrderItem/ProductFile.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_download_logs_retention_delete()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.status NOT IN ('completed', 'denied') THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'download_logs may only be purged once terminal';
                END IF;

                IF OLD.retention_until > transaction_timestamp() THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'download_logs may only be purged after retention expires';
                END IF;

                RETURN OLD;
            END;
            $$;

            CREATE TRIGGER download_logs_retention_delete_trigger
            BEFORE DELETE ON download_logs
            FOR EACH ROW
            EXECUTE FUNCTION enforce_download_logs_retention_delete();
            SQL);

        // Controlled in-place replacement of G2 (no grant trigger is added or
        // removed). Every P4-A2.1 protection is preserved verbatim; the single
        // addition closes the last direct write path to the counter: an exact +1
        // is now accepted ONLY from the nested UPDATE issued by G5
        // (pg_trigger_depth() > 1). The runtime role has no DDL privilege to
        // fabricate a competing trigger.
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

                -- Consumption stays strictly +1, bounded and usable-only. Since
                -- P4-B, the real consumption exists: the exact +1 is only accepted
                -- from the nested UPDATE issued by the G5 download log trigger,
                -- which paired it with a `started` log in the same transaction.
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

                    IF pg_trigger_depth() <= 1 THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'download_grants consumption must originate from the download log trigger';
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
    }

    public function down(): void
    {
        // Fail-closed rollback (D-029.5): audit rows are never destroyed and the
        // counter is never rewound. With any row present, the rollback is refused
        // BEFORE any object is dropped or restored.
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM download_logs) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'download_logs rollback refused: audit rows must never be destroyed';
                END IF;
            END
            $$;
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS download_logs_retention_delete_trigger ON download_logs');
        DB::statement('DROP TRIGGER IF EXISTS download_logs_enforce_integrity_trigger ON download_logs');
        DB::statement('DROP FUNCTION IF EXISTS enforce_download_logs_retention_delete()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_download_logs_integrity()');

        // Restore the exact P4-A2.1 G2 definition from migration 000011.
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

        Schema::dropIfExists('download_logs');
    }
};
