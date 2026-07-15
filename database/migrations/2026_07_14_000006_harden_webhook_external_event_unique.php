<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Security hardening (follow-up to P3C-B). The replay-uniqueness on
 * (provider, external_event_id) must only bind SIGNED events: an unsigned webhook
 * carries an attacker-controlled external_event_id and must never be able to
 * reserve that slot and block a later legitimate, signature-verified event.
 * external_event_id is still retained on invalid events for audit; those remain
 * deduplicated by (provider, payload_hash) WHERE signature_verified = false.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS payment_webhook_events_provider_external_event_unique');
        DB::statement('CREATE UNIQUE INDEX payment_webhook_events_provider_external_event_unique ON payment_webhook_events (provider, external_event_id) WHERE external_event_id IS NOT NULL AND signature_verified = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payment_webhook_events_provider_external_event_unique');
        DB::statement('CREATE UNIQUE INDEX payment_webhook_events_provider_external_event_unique ON payment_webhook_events (provider, external_event_id) WHERE external_event_id IS NOT NULL');
    }
};
