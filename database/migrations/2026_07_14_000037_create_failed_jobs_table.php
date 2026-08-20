<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H2.7 — `failed_jobs`, pour qu'un job mort cesse de disparaître sans trace.
 *
 * ⚠️ LE DÉFAUT FERMÉ (dette D-030). `config/queue.php` déclarait déjà `'table' =>
 * 'failed_jobs'` et **la table n'a jamais existé**. `.env.example` contournait le problème
 * avec `QUEUE_FAILED_DRIVER=file`, qui écrit dans un fichier que personne ne regarde. Un job
 * de livraison sécurisée, de réconciliation CRM ou d'attribution d'affiliation qui mourait
 * partait donc **sans trace exploitable** — le silence, encore, comme mode de défaillance.
 *
 * ⚠️ CE QUE CETTE TABLE STOCKE, ET CE QUI LA REND SÛRE. `payload` contient les arguments
 * sérialisés du job, `exception` sa trace. Cette table n'est acceptable que parce que la
 * discipline **ID-ONLY** de D-030 Q2 est tenue partout : chaque job du dépôt ne transporte
 * qu'un identifiant — `order_id`, `contact_id`, `refund_id` — jamais un token, un hash, un
 * `storage_path`, une IP ni une clé HMAC. **Un futur job qui transporterait un secret le
 * publierait ici**, en clair, dans une table lue par toute personne qui débogue une queue.
 * La règle ne se relâche pas parce qu'une table d'échecs existe désormais.
 *
 * Forme volontairement STANDARD (stub Laravel) : rien d'inventé, rien de propre au projet.
 * Ni `jobs` ni `job_batches` ne sont créées — `QUEUE_CONNECTION=redis` et le dépôt ne fait
 * aucun batching, donc les créer serait du schéma mort. Elles restent des dettes nommées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            // `database-uuids` retrouve un échec par cet uuid ; il est donc unique.
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestampTz('failed_at')->useCurrent();
        });

        // Le runtime doit pouvoir ÉCRIRE ici : c'est le worker qui enregistre l'échec.
        // `000012` pose `ALTER DEFAULT PRIVILEGES`, donc les droits sont hérités — ce GRANT
        // explicite est une ceinture, pas une bretelle : si quelqu'un rétablit un jour ces
        // default privileges à autre chose, une table d'échecs muette serait exactement la
        // panne silencieuse que cette migration existe pour supprimer.
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON public.failed_jobs TO digitrove_runtime');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.failed_jobs_id_seq TO digitrove_runtime');
    }

    public function down(): void
    {
        // Pas de garde lossless-only ici, et c'est délibéré : `failed_jobs` est un journal
        // d'exploitation, pas un registre d'audit financier. Aucune ligne n'y explique un
        // mouvement d'argent, contrairement à `payment_webhook_events` (000036).
        Schema::dropIfExists('failed_jobs');
    }
};
