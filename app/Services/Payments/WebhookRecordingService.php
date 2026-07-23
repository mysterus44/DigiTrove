<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\PaymentWebhookEvent;
use App\Support\PostgresConstraintViolation;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Records and deduplicates inbound webhook events on the existing
 * `payment_webhook_events` table (P3-D4, D-034). No migration.
 *
 * The raw body is never stored: only an allowlisted, non-PII `filtered_payload`
 * and a canonical `payload_hash`. The `x-token`/HMAC secret is never persisted.
 * Deduplication is by exact SQLSTATE `23505` + constraint name, never by string
 * matching a message.
 */
final class WebhookRecordingService
{
    /**
     * Persist a signature-verified event, or resolve its replay.
     *
     * @param  array<string, string>  $filteredPayload
     */
    public function recordVerified(
        string $provider,
        string $externalEventId,
        string $payloadHash,
        array $filteredPayload,
        ?string $eventType,
        CarbonImmutable $now,
    ): RecordedWebhook {
        try {
            $event = PaymentWebhookEvent::query()->create([
                'provider' => $provider,
                'external_event_id' => $externalEventId,
                'payment_id' => null,
                'event_type' => $eventType,
                'payload_hash' => $payloadHash,
                'filtered_payload' => $filteredPayload,
                'signature_verified' => true,
                'processing_status' => 'received',
                'received_at' => $now,
            ]);
        } catch (Throwable $exception) {
            if (PostgresConstraintViolation::isUniqueViolationOf(
                $exception,
                'payment_webhook_events_provider_external_event_unique',
            )) {
                return $this->replayOf(
                    PaymentWebhookEvent::query()
                        ->where('provider', $provider)
                        ->where('external_event_id', $externalEventId)
                        ->firstOrFail(),
                );
            }

            throw $exception;
        }

        return new RecordedWebhook($event->id, false, true, 'received');
    }

    /**
     * Persist an invalid-signature event in the strict minimal shape the schema
     * requires, or resolve its replay by (provider, payload_hash).
     */
    public function recordInvalid(
        string $provider,
        string $payloadHash,
        CarbonImmutable $now,
    ): RecordedWebhook {
        try {
            $event = PaymentWebhookEvent::query()->create([
                'provider' => $provider,
                'external_event_id' => null,
                'payment_id' => null,
                'event_type' => null,
                'payload_hash' => $payloadHash,
                'filtered_payload' => null,
                'signature_verified' => false,
                'processing_status' => 'failed',
                'received_at' => $now,
                'failed_at' => $now,
                'processing_error_sanitized' => 'Webhook signature verification failed.',
            ]);
        } catch (Throwable $exception) {
            if (PostgresConstraintViolation::isUniqueViolationOf(
                $exception,
                'payment_webhook_events_provider_payload_hash_unique',
            )) {
                return $this->replayOf(
                    PaymentWebhookEvent::query()
                        ->where('provider', $provider)
                        ->where('payload_hash', $payloadHash)
                        ->where('signature_verified', false)
                        ->firstOrFail(),
                );
            }

            throw $exception;
        }

        return new RecordedWebhook($event->id, false, false, 'failed');
    }

    private function replayOf(PaymentWebhookEvent $event): RecordedWebhook
    {
        return new RecordedWebhook(
            $event->id,
            true,
            (bool) $event->signature_verified,
            $event->processing_status->value,
        );
    }
}
