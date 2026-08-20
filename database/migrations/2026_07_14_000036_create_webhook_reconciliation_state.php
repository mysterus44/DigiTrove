<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H1 — Réconciliation des webhooks non aboutis (dette #6).
 *
 * Ferme le seul risque argent connu et non couvert : un webhook signé qui n'aboutit jamais
 * à une décision financière et que personne ne voit.
 *
 * DEUX FAMILLES, UN SEUL ÉTAT TERMINAL
 *
 *   `received` jamais résolu — la fenêtre P3-D3 : la référence fournisseur n'existe
 *   localement qu'après la seconde transaction d'initiation, donc un webhook arrivé entre
 *   les deux ne trouve rien. Il reste `received` pour toujours.
 *
 *   `failed` jamais réessayé — le contre-appel fournisseur a échoué (timeout, panne). Le
 *   comportement P3-D4 marque l'événement `failed`, qui est TERMINAL : une redélivrance
 *   retombe sur « replay terminal » et répond 200 sans rien retraiter. Un paiement
 *   réellement encaissé peut donc n'être jamais confirmé à cause d'une panne réseau
 *   passagère.
 *
 * ⚠️ CE QUE CE GATE NE FAIT PAS : il ne retente RIEN auprès du fournisseur. Un retry
 * automatique du contre-appel changerait un comportement CinetPay déjà prouvé par un test
 * existant. Ce gate rend le problème VISIBLE ET BORNÉ DANS LE TEMPS, pas résolu tout seul.
 *
 * ⚠️ LA RELAXATION EST CIBLÉE, PAS UN PRINCIPE GÉNÉRAL. `processed` et `ignored` encodent
 * une DÉCISION FINANCIÈRE — argent confirmé, ou rejet délibéré — et restent strictement
 * finaux, sans exception. `failed` encode un ÉCHEC DE TRAITEMENT, pas une décision : c'est
 * la seule catégorie qu'il est juste d'ouvrir. Exactement deux transitions nouvelles
 * existent, et le trigger le dit en toutes lettres.
 *
 * ⚠️ LES ÉVÉNEMENTS À SIGNATURE INVALIDE NE PEUVENT PAS EXPIRER, ET C'EST VOULU.
 * `payment_webhook_events_invalid_minimal_shape_check` (000005) contraint structurellement
 * un événement `signature_verified = false` à rester `failed`. Ce CHECK est laissé INTACT :
 * un webhook dont la signature n'a jamais vérifié n'a pas « échoué à être traité », il a été
 * DÉLIBÉRÉMENT REJETÉ. Le confondre avec un contre-appel qui a planté effacerait la
 * distinction que ce gate existe pour préserver. La commande de réconciliation ne considère
 * donc que les événements signés.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Le nouvel état terminal ───────────────────────────────────────────────
        //
        // AJOUT d'une valeur au CHECK, jamais un retrait : les quatre états d'origine
        // restent valides et aucune ligne existante ne devient illégale.
        DB::statement('ALTER TABLE payment_webhook_events DROP CONSTRAINT payment_webhook_events_processing_status_check');
        DB::statement("ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_processing_status_check CHECK (processing_status IN ('received','processed','ignored','failed','unresolved_expired'))");

        Schema::table('payment_webhook_events', function ($table): void {
            // Colonne DÉDIÉE. `processed_at` n'est jamais réutilisé : un événement expiré
            // n'a rien été « traité », et écraser le sens d'une colonne d'audit pour
            // économiser une migration est exactement ce qui rend un schéma illisible.
            $table->timestampTz('expired_at')->nullable();
            // La provenance a une valeur diagnostique réelle : « jamais résolu » et « le
            // fournisseur était injoignable » appellent des actions différentes.
            $table->text('expired_from_status')->nullable();
        });

        // ── 2. Cohérence des dates ───────────────────────────────────────────────────
        DB::statement('ALTER TABLE payment_webhook_events DROP CONSTRAINT payment_webhook_events_cycle_dates_check');
        DB::statement(<<<'SQL'
            ALTER TABLE payment_webhook_events
            ADD CONSTRAINT payment_webhook_events_cycle_dates_check
            CHECK (
                (processed_at    IS NULL OR processed_at    >= received_at) AND
                (failed_at       IS NULL OR failed_at       >= received_at) AND
                (expired_at      IS NULL OR expired_at      >= received_at) AND
                (retention_until IS NULL OR retention_until >= received_at)
            )
            SQL);

        // ── 3. Cohérence statut / dates ──────────────────────────────────────────────
        //
        // La branche `unresolved_expired` vérifie la provenance DANS LES DEUX SENS :
        // `failed_at` est renseigné si et seulement si l'événement vient de `failed`. Le
        // trigger d'immuabilité fige `failed_at` une fois posé, donc un `failed` qui expire
        // le CONSERVE — et un `received` qui expire ne peut pas en inventer un.
        DB::statement('ALTER TABLE payment_webhook_events DROP CONSTRAINT payment_webhook_events_status_dates_consistency_check');
        DB::statement(<<<'SQL'
            ALTER TABLE payment_webhook_events
            ADD CONSTRAINT payment_webhook_events_status_dates_consistency_check
            CHECK (
                CASE
                    WHEN processing_status = 'received'
                        THEN processed_at IS NULL AND failed_at IS NULL AND expired_at IS NULL
                    WHEN processing_status = 'processed'
                        THEN processed_at IS NOT NULL AND failed_at IS NULL AND expired_at IS NULL
                    WHEN processing_status = 'ignored'
                        THEN processed_at IS NOT NULL AND failed_at IS NULL AND expired_at IS NULL
                    WHEN processing_status = 'failed'
                        THEN failed_at IS NOT NULL AND processed_at IS NULL AND expired_at IS NULL
                    WHEN processing_status = 'unresolved_expired'
                        THEN expired_at IS NOT NULL
                            AND processed_at IS NULL
                            AND expired_from_status IN ('received', 'failed')
                            AND (expired_from_status = 'failed') = (failed_at IS NOT NULL)
                    ELSE FALSE
                END IS TRUE
            )
            SQL);

        // `expired_from_status` n'a de sens que pour un événement expiré : ailleurs il doit
        // être NULL, sinon la colonne devient un champ libre que personne ne lit.
        DB::statement(<<<'SQL'
            ALTER TABLE payment_webhook_events
            ADD CONSTRAINT payment_webhook_events_expired_provenance_check
            CHECK (
                (processing_status = 'unresolved_expired') = (expired_from_status IS NOT NULL)
            )
            SQL);

        // ── 4. La machine à états, réécrite avec exactement deux transitions de plus ──
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

                -- Processing dates: NULL -> value once, then frozen. `expired_at` joins them:
                -- an expiry is stamped once and can never be moved to look more recent.
                IF (OLD.processed_at IS NOT NULL AND NEW.processed_at IS DISTINCT FROM OLD.processed_at)
                    OR (OLD.failed_at IS NOT NULL AND NEW.failed_at IS DISTINCT FROM OLD.failed_at)
                    OR (OLD.expired_at IS NOT NULL AND NEW.expired_at IS DISTINCT FROM OLD.expired_at) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payment_webhook_events processing dates are immutable once set';
                END IF;

                -- Provenance of an expiry: written once with the transition, then frozen.
                -- Without this, a row could be expired from `failed` and later relabelled as
                -- having come from `received`, destroying the only diagnostic this state has.
                IF OLD.expired_from_status IS NOT NULL
                    AND NEW.expired_from_status IS DISTINCT FROM OLD.expired_from_status THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payment_webhook_events expiry provenance is immutable once set';
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

                -- State machine.
                --
                -- THE DECISIONAL TERMINALS ARE FINAL: `processed` and `ignored` mean money was
                -- confirmed, or deliberately rejected. Nothing may follow them, ever.
                --
                -- `failed` is NOT a decision — it means the provider counter-call did not
                -- complete — so it is the one terminal an offline reconciliation may close out
                -- as `unresolved_expired`. That is the whole of the relaxation: two new edges,
                -- both ending in the same terminal, and no path out of it.
                IF NEW.processing_status IS DISTINCT FROM OLD.processing_status THEN
                    IF NOT (
                        (
                            OLD.processing_status = 'received'
                            AND NEW.processing_status IN ('processed', 'ignored', 'failed', 'unresolved_expired')
                        )
                        OR (
                            OLD.processing_status = 'failed'
                            AND NEW.processing_status = 'unresolved_expired'
                        )
                    ) THEN
                        RAISE EXCEPTION USING
                            ERRCODE = '23514',
                            MESSAGE = 'payment_webhook_events processing status transition is not allowed';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);

        // ── 5. Trouver ce qui est bloqué, sans balayer la table ──────────────────────
        //
        // Partiel sur les deux seuls états qu'une réconciliation lit. Il reste minuscule :
        // en régime normal presque tout est `processed`.
        DB::statement(<<<'SQL'
            CREATE INDEX payment_webhook_events_reconciliation_index
            ON payment_webhook_events (processing_status, received_at)
            WHERE processing_status IN ('received', 'failed')
            SQL);
    }

    /**
     * ⚠️ ROLLBACK LOSSLESS-ONLY, contrôlé AVANT toute mutation.
     *
     * Redescendre supprime `unresolved_expired` du CHECK. S'il existe ne serait-ce qu'une
     * ligne dans cet état, la contrainte serait invalide — mais surtout, retirer la colonne
     * `expired_from_status` DÉTRUIRAIT l'information qui distingue « jamais résolu » de
     * « fournisseur injoignable », sur des lignes d'audit financier.
     *
     * La leçon de P6-D1 est désormais une règle : le refus est la PREMIÈRE opération, avant
     * tout DROP, et la base reste entièrement dans son état d'origine. Après un vrai run de
     * réconciliation, ce refus est le cas NORMALEMENT ATTENDU.
     */
    public function down(): void
    {
        $expired = DB::table('payment_webhook_events')
            ->where('processing_status', 'unresolved_expired')
            ->count();

        if ($expired > 0) {
            throw new RuntimeException(
                'Refusing to roll back 000036: '.$expired.' event(s) are in `unresolved_expired`. '
                .'Rolling back would erase the provenance of expired financial audit rows. '
                .'Nothing has been modified.',
            );
        }

        DB::statement('DROP INDEX IF EXISTS payment_webhook_events_reconciliation_index');
        DB::statement('ALTER TABLE payment_webhook_events DROP CONSTRAINT IF EXISTS payment_webhook_events_expired_provenance_check');

        // Restore the exact 000005 state machine: received -> processed|ignored|failed only.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_webhook_event_immutability()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
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

                IF OLD.payment_id IS NOT NULL
                    AND NEW.payment_id IS DISTINCT FROM OLD.payment_id THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payment_webhook_events payment reference is immutable once set';
                END IF;

                IF OLD.event_type IS NOT NULL
                    AND NEW.event_type IS DISTINCT FROM OLD.event_type THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payment_webhook_events event_type is immutable once set';
                END IF;

                IF (OLD.processed_at IS NOT NULL AND NEW.processed_at IS DISTINCT FROM OLD.processed_at)
                    OR (OLD.failed_at IS NOT NULL AND NEW.failed_at IS DISTINCT FROM OLD.failed_at) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payment_webhook_events processing dates are immutable once set';
                END IF;

                IF OLD.retention_until IS NOT NULL
                    AND (NEW.retention_until IS NULL OR NEW.retention_until < OLD.retention_until) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payment_webhook_events retention_until cannot be shortened or cleared';
                END IF;

                IF OLD.processing_error_sanitized IS NOT NULL
                    AND NEW.processing_error_sanitized IS DISTINCT FROM OLD.processing_error_sanitized THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'payment_webhook_events processing_error_sanitized is immutable once set';
                END IF;

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
            SQL);

        DB::statement('ALTER TABLE payment_webhook_events DROP CONSTRAINT payment_webhook_events_status_dates_consistency_check');
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

        DB::statement('ALTER TABLE payment_webhook_events DROP CONSTRAINT payment_webhook_events_cycle_dates_check');
        DB::statement(<<<'SQL'
            ALTER TABLE payment_webhook_events
            ADD CONSTRAINT payment_webhook_events_cycle_dates_check
            CHECK (
                (processed_at    IS NULL OR processed_at    >= received_at) AND
                (failed_at       IS NULL OR failed_at       >= received_at) AND
                (retention_until IS NULL OR retention_until >= received_at)
            )
            SQL);

        Schema::table('payment_webhook_events', function ($table): void {
            $table->dropColumn(['expired_at', 'expired_from_status']);
        });

        DB::statement('ALTER TABLE payment_webhook_events DROP CONSTRAINT payment_webhook_events_processing_status_check');
        DB::statement("ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_processing_status_check CHECK (processing_status IN ('received','processed','ignored','failed'))");
    }
};
