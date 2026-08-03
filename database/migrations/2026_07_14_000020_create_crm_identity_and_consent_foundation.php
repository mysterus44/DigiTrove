<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RESOLVE_SIGNATURE = 'public.resolve_crm_contact(character varying, character varying, bigint, bigint)';

    private const RECORD_SIGNATURE = 'public.record_crm_marketing_consent(uuid, character varying, character varying, bigint, bigint, character varying, character varying)';

    private const STATUS_SIGNATURE = 'public.has_current_marketing_consent(uuid)';

    public function up(): void
    {
        $this->assertExecutorProvisioned();

        Schema::create('crm_contacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->text('email')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('origin', 32);
            $table->string('status', 16)->default('active');
            $table->timestampTz('anonymized_at')->nullable();
            $table->timestampsTz();

            $table->unique('public_id', 'crm_contacts_public_id_unique');
            $table->index('user_id', 'crm_contacts_user_id_index');
        });

        DB::statement('ALTER TABLE public.crm_contacts ALTER COLUMN email TYPE CITEXT');
        DB::statement("ALTER TABLE public.crm_contacts ADD CONSTRAINT crm_contacts_email_format_check CHECK (email IS NULL OR (email::text = lower(btrim(email::text)) AND char_length(email::text) BETWEEN 3 AND 254 AND email::text ~ '^[^[:space:]@]+@[^[:space:]@]+\\.[^[:space:]@]+$'))");
        DB::statement("ALTER TABLE public.crm_contacts ADD CONSTRAINT crm_contacts_origin_check CHECK (origin IN ('guest_order', 'verified_account'))");
        DB::statement("ALTER TABLE public.crm_contacts ADD CONSTRAINT crm_contacts_status_check CHECK (status IN ('active', 'anonymized'))");
        DB::statement("ALTER TABLE public.crm_contacts ADD CONSTRAINT crm_contacts_state_consistency_check CHECK ((CASE WHEN status = 'active' THEN email IS NOT NULL AND anonymized_at IS NULL WHEN status = 'anonymized' THEN email IS NULL AND user_id IS NULL AND anonymized_at IS NOT NULL ELSE FALSE END) IS TRUE)");
        DB::statement('CREATE UNIQUE INDEX crm_contacts_active_email_unique ON public.crm_contacts (email) WHERE email IS NOT NULL');

        Schema::create('crm_marketing_consent_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->foreignId('contact_id')->constrained('crm_contacts')->restrictOnDelete();
            $table->string('channel', 16);
            $table->string('purpose', 32);
            $table->string('action', 16);
            $table->string('source', 32);
            $table->string('policy_version', 64);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('idempotency_hash', 64);
            $table->timestampTz('recorded_at')->useCurrent();

            $table->unique('public_id', 'crm_marketing_consent_events_public_id_unique');
            $table->unique('idempotency_hash', 'crm_marketing_consent_events_idempotency_hash_unique');
            $table->index(
                ['contact_id', 'channel', 'purpose', 'id'],
                'crm_marketing_consent_events_current_index',
            );
        });

        DB::statement("ALTER TABLE public.crm_marketing_consent_events ADD CONSTRAINT crm_marketing_consent_events_channel_check CHECK (channel = 'email')");
        DB::statement("ALTER TABLE public.crm_marketing_consent_events ADD CONSTRAINT crm_marketing_consent_events_purpose_check CHECK (purpose = 'promotional')");
        DB::statement("ALTER TABLE public.crm_marketing_consent_events ADD CONSTRAINT crm_marketing_consent_events_action_check CHECK (action IN ('granted', 'withdrawn'))");
        DB::statement("ALTER TABLE public.crm_marketing_consent_events ADD CONSTRAINT crm_marketing_consent_events_source_check CHECK (source IN ('checkout', 'account_settings'))");
        DB::statement("ALTER TABLE public.crm_marketing_consent_events ADD CONSTRAINT crm_marketing_consent_events_policy_version_check CHECK (policy_version = btrim(policy_version) AND char_length(policy_version) BETWEEN 1 AND 64 AND policy_version <> 'unknown' AND policy_version ~ '^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$')");
        DB::statement("ALTER TABLE public.crm_marketing_consent_events ADD CONSTRAINT crm_marketing_consent_events_idempotency_hash_format_check CHECK (idempotency_hash ~ '^[0-9a-f]{64}$')");
        // account_settings.user_id may later be nulled only by its SET NULL FK;
        // the insert authority requires the verified active user at record time.
        DB::statement("ALTER TABLE public.crm_marketing_consent_events ADD CONSTRAINT crm_marketing_consent_events_source_context_check CHECK ((CASE WHEN source = 'checkout' THEN order_id IS NOT NULL AND action = 'granted' WHEN source = 'account_settings' THEN order_id IS NULL ELSE FALSE END) IS TRUE)");

        $this->installIntegrityTriggers();
        $this->installAuthorities();
        $this->lockDownPrivileges();
    }

    public function down(): void
    {
        DB::statement('REVOKE EXECUTE ON FUNCTION '.self::RESOLVE_SIGNATURE.' FROM digitrove_runtime');
        DB::statement('REVOKE EXECUTE ON FUNCTION '.self::RECORD_SIGNATURE.' FROM digitrove_runtime');
        DB::statement('REVOKE EXECUTE ON FUNCTION '.self::STATUS_SIGNATURE.' FROM digitrove_runtime');

        DB::statement('DROP TRIGGER IF EXISTS crm_marketing_consent_events_append_only_trigger ON public.crm_marketing_consent_events');
        DB::statement('DROP TRIGGER IF EXISTS crm_contacts_integrity_trigger ON public.crm_contacts');

        DB::statement('SET ROLE digitrove_crm_executor');

        try {
            DB::statement('DROP FUNCTION IF EXISTS '.self::STATUS_SIGNATURE);
            DB::statement('DROP FUNCTION IF EXISTS '.self::RECORD_SIGNATURE);
            DB::statement('DROP FUNCTION IF EXISTS '.self::RESOLVE_SIGNATURE);
        } finally {
            DB::statement('RESET ROLE');
        }

        DB::statement('DROP FUNCTION IF EXISTS public.prevent_crm_marketing_consent_event_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS public.enforce_crm_contacts_integrity()');

        DB::statement('REVOKE ALL PRIVILEGES ON TABLE public.crm_marketing_consent_events, public.crm_contacts FROM digitrove_crm_executor');
        DB::statement('REVOKE ALL PRIVILEGES ON SEQUENCE public.crm_marketing_consent_events_id_seq, public.crm_contacts_id_seq FROM digitrove_crm_executor');
        DB::statement('REVOKE USAGE ON SCHEMA public FROM digitrove_crm_executor');

        Schema::dropIfExists('crm_marketing_consent_events');
        Schema::dropIfExists('crm_contacts');
    }

    private function installIntegrityTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enforce_crm_contacts_integrity()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'CRM contacts cannot be deleted';
                END IF;

                IF OLD.status = 'anonymized' THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'anonymized CRM contacts are immutable';
                END IF;

                IF ROW(NEW.id, NEW.public_id, NEW.origin, NEW.created_at)
                    IS DISTINCT FROM ROW(OLD.id, OLD.public_id, OLD.origin, OLD.created_at)
                THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'CRM contact identity is immutable';
                END IF;

                IF NEW.status = 'anonymized' THEN
                    IF NEW.email IS NOT NULL OR NEW.user_id IS NOT NULL OR NEW.anonymized_at IS NULL THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'CRM contact anonymization is incomplete';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.status <> 'active'
                    OR NEW.email IS DISTINCT FROM OLD.email
                    OR NEW.anonymized_at IS NOT NULL
                THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'active CRM contact identity is immutable';
                END IF;

                IF NEW.user_id IS DISTINCT FROM OLD.user_id THEN
                    IF OLD.user_id IS NULL AND NEW.user_id IS NOT NULL THEN
                        IF NOT EXISTS (
                            SELECT 1
                            FROM public.users AS u
                            WHERE u.id = NEW.user_id
                              AND u.deleted_at IS NULL
                              AND u.email_verified_at IS NOT NULL
                              AND u.email = NEW.email
                        ) THEN
                            RAISE EXCEPTION USING
                                ERRCODE = '23514',
                                MESSAGE = 'CRM contact user link is invalid';
                        END IF;
                    ELSIF OLD.user_id IS NOT NULL AND NEW.user_id IS NULL AND pg_trigger_depth() > 1 THEN
                        NULL;
                    ELSE
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'CRM contact user link is immutable';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER crm_contacts_integrity_trigger
            BEFORE UPDATE OR DELETE ON public.crm_contacts
            FOR EACH ROW
            EXECUTE FUNCTION public.enforce_crm_contacts_integrity();

            CREATE OR REPLACE FUNCTION public.prevent_crm_marketing_consent_event_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF TG_OP = 'UPDATE'
                    AND OLD.user_id IS NOT NULL
                    AND NEW.user_id IS NULL
                    AND pg_trigger_depth() > 1
                    AND ROW(
                        NEW.id, NEW.public_id, NEW.contact_id, NEW.channel,
                        NEW.purpose, NEW.action, NEW.source, NEW.policy_version,
                        NEW.order_id, NEW.idempotency_hash, NEW.recorded_at
                    ) IS NOT DISTINCT FROM ROW(
                        OLD.id, OLD.public_id, OLD.contact_id, OLD.channel,
                        OLD.purpose, OLD.action, OLD.source, OLD.policy_version,
                        OLD.order_id, OLD.idempotency_hash, OLD.recorded_at
                    )
                THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'CRM marketing consent events are append-only';
            END;
            $$;

            CREATE TRIGGER crm_marketing_consent_events_append_only_trigger
            BEFORE UPDATE OR DELETE ON public.crm_marketing_consent_events
            FOR EACH ROW
            EXECUTE FUNCTION public.prevent_crm_marketing_consent_event_mutation();
            SQL);
    }

    private function installAuthorities(): void
    {
        DB::statement('REVOKE TEMPORARY ON DATABASE '.$this->quoteIdentifier($this->currentDatabase()).' FROM digitrove_crm_executor');
        DB::statement('REVOKE CREATE ON SCHEMA public FROM digitrove_crm_executor');
        DB::statement('REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM digitrove_crm_executor');
        DB::statement('REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public FROM digitrove_crm_executor');
        DB::statement('GRANT USAGE ON SCHEMA public TO digitrove_crm_executor');
        DB::statement('GRANT SELECT ON TABLE public.users, public.orders TO digitrove_crm_executor');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON TABLE public.crm_contacts TO digitrove_crm_executor');
        DB::statement('GRANT SELECT, INSERT ON TABLE public.crm_marketing_consent_events TO digitrove_crm_executor');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.crm_contacts_id_seq, public.crm_marketing_consent_events_id_seq TO digitrove_crm_executor');
        DB::statement('GRANT CREATE ON SCHEMA public TO digitrove_crm_executor');
        DB::statement('SET ROLE digitrove_crm_executor');

        try {
            $this->createResolveAuthority();
            $this->createConsentAuthority();
            $this->createConsentStatusAuthority();

            DB::statement('REVOKE EXECUTE ON FUNCTION '.self::RESOLVE_SIGNATURE.' FROM PUBLIC');
            DB::statement('REVOKE EXECUTE ON FUNCTION '.self::RECORD_SIGNATURE.' FROM PUBLIC');
            DB::statement('REVOKE EXECUTE ON FUNCTION '.self::STATUS_SIGNATURE.' FROM PUBLIC');
            DB::statement('GRANT EXECUTE ON FUNCTION '.self::RESOLVE_SIGNATURE.' TO digitrove_runtime');
            DB::statement('GRANT EXECUTE ON FUNCTION '.self::RECORD_SIGNATURE.' TO digitrove_runtime');
            DB::statement('GRANT EXECUTE ON FUNCTION '.self::STATUS_SIGNATURE.' TO digitrove_runtime');
        } finally {
            DB::statement('RESET ROLE');
            DB::statement('REVOKE CREATE ON SCHEMA public FROM digitrove_crm_executor');
        }
    }

    private function createResolveAuthority(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.resolve_crm_contact(
                p_email VARCHAR,
                p_origin VARCHAR,
                p_user_id BIGINT,
                p_order_id BIGINT
            )
            RETURNS TABLE(contact_id BIGINT, public_id UUID)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_email CITEXT := lower(btrim(p_email));
                v_contact public.crm_contacts%ROWTYPE;
            BEGIN
                IF p_email IS NULL
                    OR char_length(v_email::text) NOT BETWEEN 3 AND 254
                    OR v_email::text !~ '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$'
                THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM contact evidence is invalid';
                END IF;

                IF p_origin = 'guest_order' THEN
                    IF p_order_id IS NULL OR p_user_id IS NOT NULL OR NOT EXISTS (
                        SELECT 1 FROM public.orders AS o
                        WHERE o.id = p_order_id AND o.customer_email = v_email
                    ) THEN
                        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM guest order evidence is invalid';
                    END IF;
                ELSIF p_origin = 'verified_account' THEN
                    IF p_user_id IS NULL OR p_order_id IS NOT NULL OR NOT EXISTS (
                        SELECT 1 FROM public.users AS u
                        WHERE u.id = p_user_id
                          AND u.deleted_at IS NULL
                          AND u.email_verified_at IS NOT NULL
                          AND u.email = v_email
                    ) THEN
                        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM verified account evidence is invalid';
                    END IF;
                ELSE
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM contact origin is invalid';
                END IF;

                PERFORM pg_advisory_xact_lock(hashtextextended('crm-contact:' || v_email::text, 0));

                SELECT c.* INTO v_contact
                FROM public.crm_contacts AS c
                WHERE c.email = v_email AND c.status = 'active'
                FOR UPDATE;

                IF FOUND THEN
                    IF p_origin = 'verified_account' THEN
                        IF v_contact.user_id IS NULL THEN
                            UPDATE public.crm_contacts
                            SET user_id = p_user_id, updated_at = clock_timestamp()
                            WHERE id = v_contact.id
                            RETURNING * INTO v_contact;
                        ELSIF v_contact.user_id <> p_user_id THEN
                            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM contact already belongs to another account';
                        END IF;
                    END IF;
                ELSE
                    INSERT INTO public.crm_contacts (
                        public_id, email, user_id, origin, status, anonymized_at, created_at, updated_at
                    ) VALUES (
                        gen_random_uuid(), v_email,
                        CASE WHEN p_origin = 'verified_account' THEN p_user_id ELSE NULL END,
                        p_origin, 'active', NULL, clock_timestamp(), clock_timestamp()
                    ) RETURNING * INTO v_contact;
                END IF;

                RETURN QUERY SELECT v_contact.id, v_contact.public_id;
            END;
            $$;
            SQL);
    }

    private function createConsentAuthority(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.record_crm_marketing_consent(
                p_contact_public_id UUID,
                p_action VARCHAR,
                p_source VARCHAR,
                p_user_id BIGINT,
                p_order_id BIGINT,
                p_policy_version VARCHAR,
                p_idempotency_hash VARCHAR
            )
            RETURNS TABLE(event_public_id UUID, inserted BOOLEAN)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_contact public.crm_contacts%ROWTYPE;
                v_existing public.crm_marketing_consent_events%ROWTYPE;
                v_event_id UUID;
            BEGIN
                IF p_policy_version IS NULL
                    OR p_policy_version <> btrim(p_policy_version)
                    OR char_length(p_policy_version) NOT BETWEEN 1 AND 64
                    OR p_policy_version = 'unknown'
                    OR p_policy_version !~ '^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$'
                    OR p_idempotency_hash !~ '^[0-9a-f]{64}$'
                THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM consent contract is invalid';
                END IF;

                SELECT c.* INTO v_contact
                FROM public.crm_contacts AS c
                WHERE c.public_id = p_contact_public_id
                FOR UPDATE;

                IF NOT FOUND OR v_contact.status <> 'active' OR v_contact.email IS NULL THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM consent contact is not active';
                END IF;

                IF p_source = 'checkout' THEN
                    IF p_action <> 'granted' OR p_order_id IS NULL OR NOT EXISTS (
                        SELECT 1 FROM public.orders AS o
                        WHERE o.id = p_order_id
                          AND o.customer_email = v_contact.email
                          AND (p_user_id IS NULL OR o.user_id = p_user_id)
                    ) THEN
                        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM checkout consent evidence is invalid';
                    END IF;
                ELSIF p_source = 'account_settings' THEN
                    IF p_user_id IS NULL OR p_order_id IS NOT NULL OR p_action NOT IN ('granted', 'withdrawn') OR NOT EXISTS (
                        SELECT 1 FROM public.users AS u
                        WHERE u.id = p_user_id
                          AND u.status = 'active'
                          AND u.deleted_at IS NULL
                          AND u.email_verified_at IS NOT NULL
                          AND u.email = v_contact.email
                    ) THEN
                        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM account consent evidence is invalid';
                    END IF;
                ELSE
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM consent source is invalid';
                END IF;

                PERFORM pg_advisory_xact_lock(hashtextextended('crm-consent:' || p_idempotency_hash, 0));

                SELECT e.* INTO v_existing
                FROM public.crm_marketing_consent_events AS e
                WHERE e.idempotency_hash = p_idempotency_hash;

                IF FOUND THEN
                    IF v_existing.contact_id <> v_contact.id
                        OR v_existing.action <> p_action
                        OR v_existing.source <> p_source
                        OR v_existing.user_id IS DISTINCT FROM p_user_id
                        OR v_existing.order_id IS DISTINCT FROM p_order_id
                        OR v_existing.policy_version <> p_policy_version
                    THEN
                        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM consent idempotency key was reused';
                    END IF;

                    RETURN QUERY SELECT v_existing.public_id, FALSE;
                    RETURN;
                END IF;

                v_event_id := gen_random_uuid();
                INSERT INTO public.crm_marketing_consent_events (
                    public_id, contact_id, channel, purpose, action, source,
                    policy_version, user_id, order_id, idempotency_hash, recorded_at
                ) VALUES (
                    v_event_id, v_contact.id, 'email', 'promotional', p_action, p_source,
                    p_policy_version, p_user_id, p_order_id, p_idempotency_hash, clock_timestamp()
                );

                RETURN QUERY SELECT v_event_id, TRUE;
            END;
            $$;
            SQL);
    }

    private function createConsentStatusAuthority(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.has_current_marketing_consent(p_contact_public_id UUID)
            RETURNS BOOLEAN
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
                SELECT COALESCE((
                    SELECT e.action = 'granted'
                    FROM public.crm_contacts AS c
                    LEFT JOIN LATERAL (
                        SELECT ce.action
                        FROM public.crm_marketing_consent_events AS ce
                        WHERE ce.contact_id = c.id
                          AND ce.channel = 'email'
                          AND ce.purpose = 'promotional'
                        ORDER BY ce.id DESC
                        LIMIT 1
                    ) AS e ON TRUE
                    WHERE c.public_id = p_contact_public_id
                      AND c.status = 'active'
                      AND c.email IS NOT NULL
                ), FALSE)
            $$;
            SQL);
    }

    private function lockDownPrivileges(): void
    {
        DB::statement('REVOKE ALL PRIVILEGES ON TABLE public.crm_contacts, public.crm_marketing_consent_events FROM PUBLIC, digitrove_runtime');
        DB::statement('REVOKE ALL PRIVILEGES ON SEQUENCE public.crm_contacts_id_seq, public.crm_marketing_consent_events_id_seq FROM PUBLIC, digitrove_runtime');
        DB::statement('REVOKE EXECUTE ON FUNCTION public.enforce_crm_contacts_integrity() FROM PUBLIC, digitrove_runtime');
        DB::statement('REVOKE EXECUTE ON FUNCTION public.prevent_crm_marketing_consent_event_mutation() FROM PUBLIC, digitrove_runtime');
    }

    private function assertExecutorProvisioned(): void
    {
        $executor = DB::selectOne(<<<'SQL'
            SELECT rolsuper, rolcanlogin, rolcreatedb, rolcreaterole,
                   rolreplication, rolbypassrls, rolinherit
            FROM pg_roles
            WHERE rolname = 'digitrove_crm_executor'
            SQL);

        if ($executor === null) {
            throw new RuntimeException('P6-A0 migration: digitrove_crm_executor is not provisioned. Run `php artisan db:provision-runtime-roles` first.');
        }

        if ($executor->rolsuper
            || $executor->rolcanlogin
            || $executor->rolcreatedb
            || $executor->rolcreaterole
            || $executor->rolreplication
            || $executor->rolbypassrls
            || $executor->rolinherit) {
            throw new RuntimeException('P6-A0 migration: digitrove_crm_executor must be a restricted NOLOGIN NOINHERIT role.');
        }

        $setPath = DB::selectOne(<<<'SQL'
            SELECT m.set_option
            FROM pg_auth_members m
            JOIN pg_roles member ON member.oid = m.member
            JOIN pg_roles granted ON granted.oid = m.roleid
            WHERE member.rolname = 'digitrove'
              AND granted.rolname = 'digitrove_crm_executor'
            LIMIT 1
            SQL);

        if ($setPath === null || ! $setPath->set_option) {
            throw new RuntimeException('P6-A0 migration: digitrove must hold SET-only membership to digitrove_crm_executor.');
        }
    }

    private function currentDatabase(): string
    {
        return (string) DB::selectOne('SELECT current_database() AS name')->name;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
