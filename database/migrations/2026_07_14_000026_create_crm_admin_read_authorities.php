<?php

use Illuminate\Support\Facades\DB;

/**
 * P6-B0.1 — CRM Admin Read Authorities.
 *
 * D-051 expected P6-B0 to need no migration. The audit proved otherwise: the runtime
 * holds NO SELECT on any crm_* table, and while every SEGMENT reader already exists
 * (P6-A2), there is no authority at all to browse contacts, search one by exact
 * e-mail, read a consent timeline, read per-currency commerce facts, list a contact's
 * current segment memberships, or list a segment's version history.
 *
 * The admin UI therefore cannot be built without either (a) breaking the read boundary
 * with direct runtime SELECTs — forbidden — or (b) adding the missing bounded
 * authorities. This migration does (b): the MINIMUM set of read-only, keyset-bounded,
 * EXECUTE-only authorities the CRM admin views need.
 *
 * Every function is STABLE (read-only), SECURITY DEFINER, owned by
 * digitrove_crm_executor, with a pinned search_path and qualified objects. None of
 * them can mutate anything, and none exposes a raw e-mail for an anonymized contact —
 * the P6-A0 state CHECK already guarantees `status = 'anonymized' => email IS NULL`,
 * so the old address is physically absent, never merely hidden.
 */
return new class extends \Illuminate\Database\Migrations\Migration
{
    private const LIST_CONTACTS_SIGNATURE = 'public.list_crm_contacts(bigint, character varying, character varying, integer)';

    private const GET_CONTACT_SIGNATURE = 'public.get_crm_contact(bigint)';

    private const FIND_BY_EMAIL_SIGNATURE = 'public.find_crm_contact_by_exact_email(character varying)';

    private const LIST_CONSENT_SIGNATURE = 'public.list_crm_contact_consent_events(bigint, bigint, integer)';

    private const LIST_ROLLUPS_SIGNATURE = 'public.list_crm_contact_commerce_rollups(bigint)';

    private const LIST_MEMBERSHIPS_SIGNATURE = 'public.list_crm_contact_segment_memberships(bigint, bigint, integer)';

    private const LIST_VERSIONS_SIGNATURE = 'public.list_crm_segment_versions(bigint, integer, integer)';

    /**
     * THE canonical inventory of every function this migration creates. Ownership,
     * lockdown and rollback all iterate it, so a function cannot be created without
     * also being owned, revoked and dropped (the P6-A2 lesson).
     *
     * @return list<string>
     */
    private function functionSignatures(): array
    {
        return [
            self::LIST_CONTACTS_SIGNATURE,
            self::GET_CONTACT_SIGNATURE,
            self::FIND_BY_EMAIL_SIGNATURE,
            self::LIST_CONSENT_SIGNATURE,
            self::LIST_ROLLUPS_SIGNATURE,
            self::LIST_MEMBERSHIPS_SIGNATURE,
            self::LIST_VERSIONS_SIGNATURE,
        ];
    }

    public function up(): void
    {
        $this->assertExecutorProvisioned();
        $this->createFunctions();
        $this->lockDownPrivileges();
    }

    public function down(): void
    {
        foreach ($this->functionSignatures() as $signature) {
            DB::statement('REVOKE EXECUTE ON FUNCTION '.$signature.' FROM digitrove_runtime');
            DB::statement('DROP FUNCTION IF EXISTS '.$signature);
        }
    }

    private function createFunctions(): void
    {
        DB::unprepared(<<<'SQL'
            -- Bounded contact browsing. Filters are ALLOWLISTED enum values, never free
            -- text, and NULL means "no filter".
            CREATE OR REPLACE FUNCTION public.list_crm_contacts(
                p_after_contact_id BIGINT,
                p_status CHARACTER VARYING,
                p_origin CHARACTER VARYING,
                p_limit INTEGER
            )
            RETURNS TABLE(
                contact_id BIGINT,
                public_id UUID,
                email CHARACTER VARYING,
                status CHARACTER VARYING,
                origin CHARACTER VARYING,
                created_at TIMESTAMPTZ
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM contact page size is invalid';
                END IF;

                IF p_status IS NOT NULL AND p_status NOT IN ('active', 'anonymized') THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid contact status filter';
                END IF;

                IF p_origin IS NOT NULL AND p_origin NOT IN ('guest_order', 'verified_account') THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid contact origin filter';
                END IF;

                RETURN QUERY
                SELECT c.id, c.public_id, c.email::varchar, c.status::varchar, c.origin::varchar, c.created_at
                FROM public.crm_contacts AS c
                WHERE (p_after_contact_id IS NULL OR c.id > p_after_contact_id)
                  AND (p_status IS NULL OR c.status = p_status)
                  AND (p_origin IS NULL OR c.origin = p_origin)
                ORDER BY c.id
                LIMIT p_limit;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.get_crm_contact(p_contact_id BIGINT)
            RETURNS TABLE(
                contact_id BIGINT,
                public_id UUID,
                email CHARACTER VARYING,
                status CHARACTER VARYING,
                origin CHARACTER VARYING,
                anonymized_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_contact_id IS NULL OR p_contact_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid contact identifier';
                END IF;

                RETURN QUERY
                SELECT c.id, c.public_id, c.email::varchar, c.status::varchar, c.origin::varchar,
                       c.anonymized_at, c.created_at
                FROM public.crm_contacts AS c
                WHERE c.id = p_contact_id;
            END;
            $$;

            -- EXACT normalised e-mail only (P6-A0 contract: lower(btrim(...)) over a
            -- CITEXT column). No LIKE, no ILIKE pattern, no fuzzy matching, no alias
            -- folding. An anonymized contact has email IS NULL and can therefore never
            -- be found through its former address.
            CREATE OR REPLACE FUNCTION public.find_crm_contact_by_exact_email(p_email CHARACTER VARYING)
            RETURNS TABLE(
                contact_id BIGINT,
                public_id UUID,
                email CHARACTER VARYING,
                status CHARACTER VARYING,
                origin CHARACTER VARYING,
                created_at TIMESTAMPTZ
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                v_email TEXT;
            BEGIN
                v_email := lower(btrim(COALESCE(p_email, '')));

                IF char_length(v_email) < 3 OR char_length(v_email) > 254 THEN
                    RETURN;
                END IF;

                RETURN QUERY
                SELECT c.id, c.public_id, c.email::varchar, c.status::varchar, c.origin::varchar, c.created_at
                FROM public.crm_contacts AS c
                WHERE c.email IS NOT NULL
                  AND c.email = v_email::public.citext
                ORDER BY c.id
                LIMIT 1;
            END;
            $$;

            -- Append-only consent ledger, paginated by event id.
            CREATE OR REPLACE FUNCTION public.list_crm_contact_consent_events(
                p_contact_id BIGINT,
                p_after_event_id BIGINT,
                p_limit INTEGER
            )
            RETURNS TABLE(
                event_id BIGINT,
                public_id UUID,
                channel CHARACTER VARYING,
                purpose CHARACTER VARYING,
                action CHARACTER VARYING,
                source CHARACTER VARYING,
                policy_version CHARACTER VARYING,
                recorded_at TIMESTAMPTZ
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_contact_id IS NULL OR p_contact_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid contact identifier';
                END IF;

                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM consent page size is invalid';
                END IF;

                RETURN QUERY
                SELECT e.id, e.public_id, e.channel::varchar, e.purpose::varchar, e.action::varchar,
                       e.source::varchar, e.policy_version::varchar, e.recorded_at
                FROM public.crm_marketing_consent_events AS e
                WHERE e.contact_id = p_contact_id
                  AND (p_after_event_id IS NULL OR e.id > p_after_event_id)
                ORDER BY e.id
                LIMIT p_limit;
            END;
            $$;

            -- One row PER CURRENCY. The caller never sums them: there is no cross-currency
            -- total anywhere in this gate.
            CREATE OR REPLACE FUNCTION public.list_crm_contact_commerce_rollups(p_contact_id BIGINT)
            RETURNS TABLE(
                currency CHARACTER VARYING,
                acquired_orders_count BIGINT,
                gross_revenue_minor BIGINT,
                refunded_amount_minor BIGINT,
                net_revenue_minor BIGINT,
                first_acquired_at TIMESTAMPTZ,
                last_acquired_at TIMESTAMPTZ,
                last_refunded_at TIMESTAMPTZ,
                refreshed_at TIMESTAMPTZ
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_contact_id IS NULL OR p_contact_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid contact identifier';
                END IF;

                RETURN QUERY
                SELECT r.currency::varchar, r.acquired_orders_count, r.gross_revenue_minor,
                       r.refunded_amount_minor, r.net_revenue_minor, r.first_acquired_at,
                       r.last_acquired_at, r.last_refunded_at, r.refreshed_at
                FROM public.crm_contact_commerce_rollups AS r
                WHERE r.contact_id = p_contact_id
                ORDER BY r.currency;
            END;
            $$;

            -- A contact's CURRENT memberships: only segments whose published current
            -- generation contains it. A superseded generation is never a membership.
            CREATE OR REPLACE FUNCTION public.list_crm_contact_segment_memberships(
                p_contact_id BIGINT,
                p_after_segment_id BIGINT,
                p_limit INTEGER
            )
            RETURNS TABLE(
                segment_id BIGINT,
                name CHARACTER VARYING,
                generation_id BIGINT,
                generation_published_at TIMESTAMPTZ
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_contact_id IS NULL OR p_contact_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid contact identifier';
                END IF;

                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM membership page size is invalid';
                END IF;

                RETURN QUERY
                SELECT s.id, s.name::varchar, g.id, g.published_at
                FROM public.crm_segments AS s
                JOIN public.crm_segment_generations AS g
                    ON g.id = s.current_generation_id AND g.status = 'published'
                JOIN public.crm_segment_generation_members AS m
                    ON m.generation_id = g.id AND m.contact_id = p_contact_id
                WHERE (p_after_segment_id IS NULL OR s.id > p_after_segment_id)
                ORDER BY s.id
                LIMIT p_limit;
            END;
            $$;

            -- Version history of one segment. The definition is returned so the admin UI
            -- can render it through the structured builder — never as an editable blob.
            CREATE OR REPLACE FUNCTION public.list_crm_segment_versions(
                p_segment_id BIGINT,
                p_after_version_number INTEGER,
                p_limit INTEGER
            )
            RETURNS TABLE(
                version_id BIGINT,
                version_number INTEGER,
                definition_schema_version SMALLINT,
                definition JSONB,
                status CHARACTER VARYING,
                published_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_segment_id IS NULL OR p_segment_id < 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '22023', MESSAGE = 'invalid segment identifier';
                END IF;

                IF p_limit IS NULL OR p_limit < 1 OR p_limit > 100 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'CRM segment version page size is invalid';
                END IF;

                RETURN QUERY
                SELECT v.id, v.version_number, v.definition_schema_version, v.definition,
                       v.status::varchar, v.published_at, v.created_at
                FROM public.crm_segment_versions AS v
                WHERE v.segment_id = p_segment_id
                  AND (p_after_version_number IS NULL OR v.version_number > p_after_version_number)
                ORDER BY v.version_number
                LIMIT p_limit;
            END;
            $$;
            SQL);

        foreach ($this->functionSignatures() as $signature) {
            DB::statement('ALTER FUNCTION '.$signature.' OWNER TO digitrove_crm_executor');
        }
    }

    private function lockDownPrivileges(): void
    {
        foreach ($this->functionSignatures() as $signature) {
            DB::statement('REVOKE ALL ON FUNCTION '.$signature.' FROM PUBLIC');
            DB::statement('REVOKE ALL ON FUNCTION '.$signature.' FROM digitrove_runtime');
            // Read-only, bounded authorities: the admin UI's ONLY way into CRM data.
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
            throw new RuntimeException('P6-B0.1 migration: digitrove_crm_executor must be a restricted NOLOGIN NOINHERIT role.');
        }
    }
};
