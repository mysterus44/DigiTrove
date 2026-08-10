<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6-C — Cart Abandonment & Reminder infrastructure (D-055 architecture → D-056 contract).
 *
 * THE ACTIVITY SIGNAL LIVES IN THE DATABASE, ON PURPOSE. `CartItem` declares no
 * Eloquent `$touches`, so adding or removing an item would never move `carts.updated_at`
 * — building abandonment on that column would be a heuristic that is simply wrong the
 * day a storefront lands. `carts.last_activity_at` is therefore maintained by a trigger
 * on `cart_items`, at the one layer no worker, command or raw statement can bypass.
 * `updated_at` is never overloaded: the abandonment transition must not count itself as
 * activity.
 *
 * THE LEDGER IS APPEND-ONLY IN THE SENSE THAT MATTERS (D-056 option B): a reminder
 * attempt has an immutable identity `(cart_id, step)` and only monotonic, bounded state
 * transitions `pending → claimed → sent | suppressed | failed`. No terminal field is
 * ever rewritten, no status ever moves backwards, and nothing is deleted outside the
 * retention purge. It carries NO PII: no address, no name, no content, no raw secret,
 * no link, no provider message, no stack trace.
 */
return new class extends Migration
{
    private const TOUCH_FN = 'public.touch_cart_last_activity()';

    private const MARK_ABANDONED = 'public.mark_abandoned_carts(integer, integer)';

    private const LIST_CANDIDATES = 'public.list_cart_reminder_candidates(bigint, integer, integer)';

    private const ENQUEUE = 'public.enqueue_cart_reminder(bigint, smallint)';

    private const LIST_DUE = 'public.list_due_cart_reminders(integer)';

    private const CLAIM = 'public.claim_cart_reminder(bigint)';

    private const ATTACH_SECRET = 'public.attach_cart_reminder_secret(bigint, character varying)';

    private const COMPLETE = 'public.complete_cart_reminder(bigint)';

    private const SUPPRESS = 'public.suppress_cart_reminder(bigint, character varying)';

    private const FAIL = 'public.fail_cart_reminder(bigint, character varying)';

    private const RESOLVE_SECRET = 'public.resolve_cart_reminder_by_secret(character varying, integer)';

    private const PURGE = 'public.purge_cart_reminders(integer, integer)';

    /**
     * THE canonical inventory. Ownership, lockdown and rollback all iterate it, so a
     * function cannot be created without also being owned, revoked and dropped.
     *
     * @return list<string>
     */
    private function runtimeSignatures(): array
    {
        return [
            self::MARK_ABANDONED,
            self::LIST_CANDIDATES,
            self::ENQUEUE,
            self::LIST_DUE,
            self::CLAIM,
            self::ATTACH_SECRET,
            self::COMPLETE,
            self::SUPPRESS,
            self::FAIL,
            self::RESOLVE_SECRET,
            self::PURGE,
        ];
    }

    public function up(): void
    {
        $this->assertExecutorProvisioned();
        $this->grantCommerceReads();
        $this->addActivitySignal();
        $this->createLedger();
        $this->createFunctions();
        $this->lockDownPrivileges();
    }

    /**
     * The authorities are SECURITY DEFINER and therefore execute as
     * digitrove_crm_executor — a role provisioned for CRM tables, which owns nothing in
     * Commerce. Without these grants every authority fails with 42501.
     *
     * The set is the MINIMUM each authority genuinely needs, and `down()` revokes
     * exactly it, so the `000027` privilege boundary is restored to the byte. Note that
     * `carts` needs UPDATE (the abandonment transition and the activity trigger), while
     * everything else is read-only: P6-C never writes Commerce.
     */
    private function grantCommerceReads(): void
    {
        DB::statement('GRANT SELECT, UPDATE ON TABLE public.carts TO digitrove_crm_executor');

        foreach (['users', 'cart_items', 'orders', 'order_items'] as $table) {
            DB::statement('GRANT SELECT ON TABLE public.'.$table.' TO digitrove_crm_executor');
        }
    }

    public function down(): void
    {
        foreach ($this->runtimeSignatures() as $signature) {
            DB::statement('REVOKE EXECUTE ON FUNCTION '.$signature.' FROM digitrove_runtime');
            DB::statement('DROP FUNCTION IF EXISTS '.$signature);
        }

        DB::statement('DROP TRIGGER IF EXISTS cart_items_touch_cart_activity_trigger ON public.cart_items');
        DB::statement('DROP FUNCTION IF EXISTS '.self::TOUCH_FN);

        Schema::dropIfExists('cart_reminder_attempts');

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropColumn('last_activity_at');
        });

        // Restore the exact 000027 privilege boundary: the executor must leave Commerce
        // as unreachable as it found it.
        DB::statement('REVOKE SELECT, UPDATE ON TABLE public.carts FROM digitrove_crm_executor');

        foreach (['users', 'cart_items', 'orders', 'order_items'] as $table) {
            DB::statement('REVOKE SELECT ON TABLE public.'.$table.' FROM digitrove_crm_executor');
        }
    }

    private function addActivitySignal(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->timestampTz('last_activity_at')->nullable();
        });

        // Backfill from the best signal that exists today, then make it mandatory.
        DB::statement('UPDATE public.carts SET last_activity_at = COALESCE(updated_at, created_at, now()) WHERE last_activity_at IS NULL');
        DB::statement('ALTER TABLE public.carts ALTER COLUMN last_activity_at SET NOT NULL');
        DB::statement('ALTER TABLE public.carts ALTER COLUMN last_activity_at SET DEFAULT now()');

        // Only an ACTIVE cart can be scanned for abandonment, so the index is partial.
        DB::statement('CREATE INDEX carts_active_last_activity_index ON public.carts (last_activity_at, id) WHERE status = \'active\'');

        DB::unprepared(<<<SQL
            -- Item churn IS cart activity. Living in the database rather than in an
            -- Eloquent \$touches means no worker, command or raw statement can bypass it.
            CREATE OR REPLACE FUNCTION {$this->touchFunctionDefinition()}
            RETURNS TRIGGER
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS \$\$
            DECLARE
                v_cart_id BIGINT;
            BEGIN
                v_cart_id := COALESCE(NEW.cart_id, OLD.cart_id);

                IF v_cart_id IS NULL THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;

                -- Only an active cart has a meaningful activity clock; touching a
                -- converted or abandoned cart would resurrect it.
                UPDATE public.carts AS c
                SET last_activity_at = clock_timestamp()
                WHERE c.id = v_cart_id
                  AND c.status = 'active';

                RETURN COALESCE(NEW, OLD);
            END;
            \$\$;

            CREATE TRIGGER cart_items_touch_cart_activity_trigger
            AFTER INSERT OR UPDATE OR DELETE ON public.cart_items
            FOR EACH ROW EXECUTE FUNCTION public.touch_cart_last_activity();
            SQL);

        DB::statement('ALTER FUNCTION '.self::TOUCH_FN.' OWNER TO digitrove_crm_executor');
        DB::statement('REVOKE ALL ON FUNCTION '.self::TOUCH_FN.' FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION '.self::TOUCH_FN.' FROM digitrove_runtime');
    }

    private function touchFunctionDefinition(): string
    {
        return 'public.touch_cart_last_activity()';
    }

    private function createLedger(): void
    {
        Schema::create('cart_reminder_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->restrictOnDelete();
            $table->smallInteger('step');
            $table->string('status', 16)->default('pending');
            // SHA-256 of a secret that exists in memory only. The raw value is never
            // stored, queued, logged or reconstructible.
            $table->string('secret_hash', 64)->nullable();
            $table->string('terminal_reason', 40)->nullable();
            $table->string('last_error_code', 5)->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('suppressed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();

            // Immutable attempt identity: one attempt per (cart, step), for ever.
            $table->unique(['cart_id', 'step'], 'cart_reminder_attempts_cart_step_unique');
            $table->unique('secret_hash', 'cart_reminder_attempts_secret_hash_unique');
            $table->index(['status', 'id'], 'cart_reminder_attempts_status_id_index');
        });

        DB::statement("ALTER TABLE public.cart_reminder_attempts ADD CONSTRAINT cart_reminder_attempts_status_check CHECK (status IN ('pending', 'claimed', 'sent', 'suppressed', 'failed'))");
        DB::statement('ALTER TABLE public.cart_reminder_attempts ADD CONSTRAINT cart_reminder_attempts_step_check CHECK (step BETWEEN 1 AND 10)');
        DB::statement("ALTER TABLE public.cart_reminder_attempts ADD CONSTRAINT cart_reminder_attempts_secret_format_check CHECK (secret_hash IS NULL OR secret_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE public.cart_reminder_attempts ADD CONSTRAINT cart_reminder_attempts_error_code_check CHECK (last_error_code IS NULL OR last_error_code ~ '^[0-9A-Z]{5}$')");

        // Terminal reasons are an ALLOWLIST, never free text: a free-text column is how
        // PII and provider messages leak into an audit trail.
        DB::statement(<<<'SQL'
            ALTER TABLE public.cart_reminder_attempts
            ADD CONSTRAINT cart_reminder_attempts_terminal_reason_check
            CHECK (terminal_reason IS NULL OR terminal_reason IN (
                'cart_converted', 'cart_not_abandoned', 'order_covers_cart',
                'consent_withdrawn', 'contact_anonymized', 'user_ineligible',
                'cooldown_active', 'attempt_cap_reached', 'sending_disabled',
                'mail_transport_unsafe', 'transport_failure'
            ))
            SQL);

        // Each status implies exactly its own timestamp, and none of the others.
        DB::statement(<<<'SQL'
            ALTER TABLE public.cart_reminder_attempts
            ADD CONSTRAINT cart_reminder_attempts_status_timestamps_check
            CHECK (
                (status <> 'claimed'    OR claimed_at IS NOT NULL)
                AND (status <> 'sent'      OR (sent_at IS NOT NULL AND claimed_at IS NOT NULL))
                AND (status <> 'suppressed' OR (suppressed_at IS NOT NULL AND terminal_reason IS NOT NULL))
                AND (status <> 'failed'    OR failed_at IS NOT NULL)
                AND (status <> 'pending'   OR (sent_at IS NULL AND suppressed_at IS NULL AND failed_at IS NULL))
            )
            SQL);

        DB::statement('ALTER TABLE public.cart_reminder_attempts OWNER TO digitrove_crm_executor');
        DB::statement('REVOKE ALL ON TABLE public.cart_reminder_attempts FROM PUBLIC');
        DB::statement('REVOKE ALL ON TABLE public.cart_reminder_attempts FROM digitrove_runtime');
        DB::statement('REVOKE ALL ON SEQUENCE public.cart_reminder_attempts_id_seq FROM PUBLIC');
        DB::statement('REVOKE ALL ON SEQUENCE public.cart_reminder_attempts_id_seq FROM digitrove_runtime');
    }

    private function createFunctions(): void
    {
        DB::unprepared(<<<'SQL'
            -- active -> abandoned, bounded and idempotent. NEVER touches converted or
            -- expired carts: an abandoned cart is a cart that stopped, not one that
            -- finished or timed out.
            CREATE OR REPLACE FUNCTION public.mark_abandoned_carts(
                p_inactive_minutes INTEGER,
                p_limit INTEGER
            )
            RETURNS TABLE(cart_id BIGINT)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_inactive_minutes IS NULL OR p_inactive_minutes < 1 OR p_inactive_minutes > 525600 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'cart inactivity window is invalid';
                END IF;

                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'cart abandonment batch size is invalid';
                END IF;

                RETURN QUERY
                WITH due AS (
                    SELECT c.id
                    FROM public.carts AS c
                    WHERE c.status = 'active'
                      AND c.last_activity_at < clock_timestamp() - make_interval(mins => p_inactive_minutes)
                    ORDER BY c.last_activity_at, c.id
                    LIMIT p_limit
                    FOR UPDATE SKIP LOCKED
                )
                UPDATE public.carts AS c
                SET status = 'abandoned',
                    -- Written exactly once: an already-abandoned cart is never rescanned.
                    abandoned_at = clock_timestamp(),
                    updated_at = clock_timestamp()
                FROM due
                WHERE c.id = due.id
                RETURNING c.id;
            END;
            $$;

            -- Abandoned carts that may receive a given step, by keyset. Identity is
            -- filtered HERE so an ineligible cart never even reaches the application:
            -- a real user_id, active, not soft-deleted, with a verified e-mail.
            CREATE OR REPLACE FUNCTION public.list_cart_reminder_candidates(
                p_after_cart_id BIGINT,
                p_step INTEGER,
                p_limit INTEGER
            )
            RETURNS TABLE(cart_id BIGINT, user_id BIGINT)
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_step IS NULL OR p_step < 1 OR p_step > 10 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid reminder step';
                END IF;

                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'cart reminder batch size is invalid';
                END IF;

                RETURN QUERY
                SELECT c.id, c.user_id
                FROM public.carts AS c
                JOIN public.users AS u ON u.id = c.user_id
                WHERE c.status = 'abandoned'
                  AND c.user_id IS NOT NULL
                  AND u.deleted_at IS NULL
                  AND u.status = 'active'
                  AND u.email_verified_at IS NOT NULL
                  AND (p_after_cart_id IS NULL OR c.id > p_after_cart_id)
                  AND NOT EXISTS (
                      SELECT 1 FROM public.cart_reminder_attempts AS a
                      WHERE a.cart_id = c.id AND a.step = p_step::smallint
                  )
                ORDER BY c.id
                LIMIT p_limit;
            END;
            $$;

            -- Idempotent by construction: the (cart_id, step) unique index IS the
            -- idempotency key, so a replay returns the existing attempt.
            CREATE OR REPLACE FUNCTION public.enqueue_cart_reminder(
                p_cart_id BIGINT,
                p_step SMALLINT
            )
            RETURNS TABLE(attempt_id BIGINT, status CHARACTER VARYING, created BOOLEAN)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_existing public.cart_reminder_attempts%ROWTYPE;
                v_id BIGINT;
            BEGIN
                IF p_cart_id IS NULL OR p_cart_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid cart identifier';
                END IF;

                IF p_step IS NULL OR p_step < 1 OR p_step > 10 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid reminder step';
                END IF;

                SELECT a.* INTO v_existing
                FROM public.cart_reminder_attempts AS a
                WHERE a.cart_id = p_cart_id AND a.step = p_step;

                IF FOUND THEN
                    RETURN QUERY SELECT v_existing.id, v_existing.status::varchar, false;
                    RETURN;
                END IF;

                INSERT INTO public.cart_reminder_attempts (cart_id, step, status, created_at, updated_at)
                VALUES (p_cart_id, p_step, 'pending', clock_timestamp(), clock_timestamp())
                RETURNING id INTO v_id;

                RETURN QUERY SELECT v_id, 'pending'::varchar, true;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.list_due_cart_reminders(p_limit INTEGER)
            RETURNS TABLE(attempt_id BIGINT)
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'cart reminder sweep size is invalid';
                END IF;

                RETURN QUERY
                SELECT a.id
                FROM public.cart_reminder_attempts AS a
                WHERE a.status = 'pending'
                ORDER BY a.id
                LIMIT p_limit;
            END;
            $$;

            -- Atomic pending -> claimed. FOR UPDATE is the serialisation point, so two
            -- workers can never both send the same reminder.
            CREATE OR REPLACE FUNCTION public.claim_cart_reminder(p_attempt_id BIGINT)
            RETURNS TABLE(attempt_id BIGINT, status CHARACTER VARYING, cart_id BIGINT, step SMALLINT)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_attempt public.cart_reminder_attempts%ROWTYPE;
            BEGIN
                IF p_attempt_id IS NULL OR p_attempt_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid attempt identifier';
                END IF;

                SELECT a.* INTO v_attempt
                FROM public.cart_reminder_attempts AS a
                WHERE a.id = p_attempt_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RETURN QUERY SELECT p_attempt_id, 'not_found'::varchar, NULL::bigint, NULL::smallint;
                    RETURN;
                END IF;

                -- Monotonic: anything already claimed or terminal is reported, never reset.
                IF v_attempt.status <> 'pending' THEN
                    RETURN QUERY SELECT v_attempt.id, v_attempt.status::varchar, v_attempt.cart_id, v_attempt.step;
                    RETURN;
                END IF;

                UPDATE public.cart_reminder_attempts AS a
                SET status = 'claimed', claimed_at = clock_timestamp(), updated_at = clock_timestamp()
                WHERE a.id = p_attempt_id;

                RETURN QUERY SELECT v_attempt.id, 'claimed'::varchar, v_attempt.cart_id, v_attempt.step;
            END;
            $$;

            -- Store ONLY the hash of a secret that lives in memory. A retry rotates it:
            -- the previous hash is overwritten, so a lost secret can never be reused.
            CREATE OR REPLACE FUNCTION public.attach_cart_reminder_secret(
                p_attempt_id BIGINT,
                p_secret_hash CHARACTER VARYING
            )
            RETURNS TABLE(attempt_id BIGINT, status CHARACTER VARYING)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_status TEXT;
            BEGIN
                IF p_attempt_id IS NULL OR p_attempt_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid attempt identifier';
                END IF;

                IF p_secret_hash IS NULL OR p_secret_hash !~ '^[0-9a-f]{64}$' THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid secret digest';
                END IF;

                SELECT a.status INTO v_status
                FROM public.cart_reminder_attempts AS a
                WHERE a.id = p_attempt_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RETURN QUERY SELECT p_attempt_id, 'not_found'::varchar;
                    RETURN;
                END IF;

                IF v_status <> 'claimed' THEN
                    RETURN QUERY SELECT p_attempt_id, v_status::varchar;
                    RETURN;
                END IF;

                UPDATE public.cart_reminder_attempts AS a
                SET secret_hash = p_secret_hash, updated_at = clock_timestamp()
                WHERE a.id = p_attempt_id;

                RETURN QUERY SELECT p_attempt_id, 'claimed'::varchar;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.complete_cart_reminder(p_attempt_id BIGINT)
            RETURNS TABLE(attempt_id BIGINT, status CHARACTER VARYING)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_status TEXT;
            BEGIN
                IF p_attempt_id IS NULL OR p_attempt_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid attempt identifier';
                END IF;

                SELECT a.status INTO v_status
                FROM public.cart_reminder_attempts AS a
                WHERE a.id = p_attempt_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RETURN QUERY SELECT p_attempt_id, 'not_found'::varchar;
                    RETURN;
                END IF;

                IF v_status <> 'claimed' THEN
                    RETURN QUERY SELECT p_attempt_id, v_status::varchar;
                    RETURN;
                END IF;

                UPDATE public.cart_reminder_attempts AS a
                SET status = 'sent', sent_at = clock_timestamp(), updated_at = clock_timestamp()
                WHERE a.id = p_attempt_id;

                RETURN QUERY SELECT p_attempt_id, 'sent'::varchar;
            END;
            $$;

            -- Suppression is a NORMAL outcome, not a failure: consent withdrawn, cart
            -- converted, order covering the cart. The reason is allowlisted; the secret
            -- is destroyed so a suppressed attempt leaves no usable capability.
            CREATE OR REPLACE FUNCTION public.suppress_cart_reminder(
                p_attempt_id BIGINT,
                p_reason CHARACTER VARYING
            )
            RETURNS TABLE(attempt_id BIGINT, status CHARACTER VARYING)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_status TEXT;
            BEGIN
                IF p_attempt_id IS NULL OR p_attempt_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid attempt identifier';
                END IF;

                IF p_reason IS NULL OR p_reason NOT IN (
                    'cart_converted', 'cart_not_abandoned', 'order_covers_cart',
                    'consent_withdrawn', 'contact_anonymized', 'user_ineligible',
                    'cooldown_active', 'attempt_cap_reached', 'sending_disabled',
                    'mail_transport_unsafe'
                ) THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'unknown suppression reason';
                END IF;

                SELECT a.status INTO v_status
                FROM public.cart_reminder_attempts AS a
                WHERE a.id = p_attempt_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RETURN QUERY SELECT p_attempt_id, 'not_found'::varchar;
                    RETURN;
                END IF;

                IF v_status NOT IN ('pending', 'claimed') THEN
                    RETURN QUERY SELECT p_attempt_id, v_status::varchar;
                    RETURN;
                END IF;

                UPDATE public.cart_reminder_attempts AS a
                SET status = 'suppressed',
                    suppressed_at = clock_timestamp(),
                    terminal_reason = p_reason,
                    secret_hash = NULL,
                    updated_at = clock_timestamp()
                WHERE a.id = p_attempt_id;

                RETURN QUERY SELECT p_attempt_id, 'suppressed'::varchar;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.fail_cart_reminder(
                p_attempt_id BIGINT,
                p_error_code CHARACTER VARYING
            )
            RETURNS TABLE(attempt_id BIGINT, status CHARACTER VARYING)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_status TEXT;
            BEGIN
                IF p_attempt_id IS NULL OR p_attempt_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid attempt identifier';
                END IF;

                IF p_error_code IS NOT NULL AND p_error_code !~ '^[0-9A-Z]{5}$' THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'error code must be a SQLSTATE';
                END IF;

                SELECT a.status INTO v_status
                FROM public.cart_reminder_attempts AS a
                WHERE a.id = p_attempt_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RETURN QUERY SELECT p_attempt_id, 'not_found'::varchar;
                    RETURN;
                END IF;

                IF v_status NOT IN ('pending', 'claimed') THEN
                    RETURN QUERY SELECT p_attempt_id, v_status::varchar;
                    RETURN;
                END IF;

                UPDATE public.cart_reminder_attempts AS a
                SET status = 'failed',
                    failed_at = clock_timestamp(),
                    last_error_code = p_error_code,
                    terminal_reason = 'transport_failure',
                    secret_hash = NULL,
                    updated_at = clock_timestamp()
                WHERE a.id = p_attempt_id;

                RETURN QUERY SELECT p_attempt_id, 'failed'::varchar;
            END;
            $$;

            -- Resume lookup by digest. Returns nothing for an unknown, non-sent or
            -- non-abandoned target, so the endpoint cannot distinguish those cases.
            CREATE OR REPLACE FUNCTION public.resolve_cart_reminder_by_secret(
                p_secret_hash CHARACTER VARYING,
                p_ttl_minutes INTEGER
            )
            RETURNS TABLE(attempt_id BIGINT, cart_id BIGINT, cart_public_id UUID)
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_ttl_minutes IS NULL OR p_ttl_minutes < 1 OR p_ttl_minutes > 43200 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'capability TTL is invalid';
                END IF;

                -- A malformed digest returns EMPTY rather than raising, so the endpoint
                -- cannot tell "wrong shape" from "no such capability".
                IF p_secret_hash IS NULL OR p_secret_hash !~ '^[0-9a-f]{64}$' THEN
                    RETURN;
                END IF;

                RETURN QUERY
                SELECT a.id, c.id, c.public_id
                FROM public.cart_reminder_attempts AS a
                JOIN public.carts AS c ON c.id = a.cart_id
                WHERE a.secret_hash = p_secret_hash
                  AND a.status = 'sent'
                  -- The TTL is enforced HERE, not in PHP: the runtime cannot widen it.
                  AND a.sent_at > clock_timestamp() - make_interval(mins => p_ttl_minutes)
                  -- Revocation is the digest being cleared; a converted or expired cart
                  -- is no longer resumable either.
                  AND c.status = 'abandoned'
                LIMIT 1;
            END;
            $$;

            -- Bounded, idempotent retention purge. Only terminal attempts older than the
            -- window are removed; a pending or claimed attempt is never destroyed,
            -- because deleting it would silently reset the idempotency key.
            CREATE OR REPLACE FUNCTION public.purge_cart_reminders(
                p_retention_days INTEGER,
                p_limit INTEGER
            )
            RETURNS TABLE(attempt_id BIGINT)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_retention_days IS NULL OR p_retention_days < 1 OR p_retention_days > 3650 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'cart reminder retention is invalid';
                END IF;

                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'cart reminder purge size is invalid';
                END IF;

                RETURN QUERY
                WITH due AS (
                    SELECT a.id
                    FROM public.cart_reminder_attempts AS a
                    WHERE a.status IN ('sent', 'suppressed', 'failed')
                      AND a.updated_at < clock_timestamp() - make_interval(days => p_retention_days)
                    ORDER BY a.id
                    LIMIT p_limit
                    FOR UPDATE SKIP LOCKED
                )
                DELETE FROM public.cart_reminder_attempts AS a
                USING due
                WHERE a.id = due.id
                RETURNING a.id;
            END;
            $$;
            SQL);

        foreach ($this->runtimeSignatures() as $signature) {
            DB::statement('ALTER FUNCTION '.$signature.' OWNER TO digitrove_crm_executor');
        }
    }

    private function lockDownPrivileges(): void
    {
        foreach ($this->runtimeSignatures() as $signature) {
            DB::statement('REVOKE ALL ON FUNCTION '.$signature.' FROM PUBLIC');
            DB::statement('REVOKE ALL ON FUNCTION '.$signature.' FROM digitrove_runtime');
            // The reminder pipeline's ONLY way in. The runtime never touches the ledger.
            DB::statement('GRANT EXECUTE ON FUNCTION '.$signature.' TO digitrove_runtime');
        }
    }

    private function assertExecutorProvisioned(): void
    {
        $executor = DB::selectOne(<<<'SQL'
            SELECT rolsuper, rolcanlogin, rolcreatedb, rolcreaterole,
                   rolreplication, rolbypassrls, rolinherit
            FROM pg_roles
            WHERE rolname = 'digitrove_crm_executor'
            SQL);

        if ($executor === null
            || $executor->rolsuper
            || $executor->rolcanlogin
            || $executor->rolcreatedb
            || $executor->rolcreaterole
            || $executor->rolreplication
            || $executor->rolbypassrls
            || $executor->rolinherit) {
            throw new RuntimeException('P6-C migration: digitrove_crm_executor must be a restricted NOLOGIN NOINHERIT role.');
        }
    }
};
