<?php

namespace Database\Factories;

use App\Enums\WebhookProcessingStatus;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Builds webhook audit events with no network call, no signature verification and
 * no reusable secret. Hashes are fake but structurally valid; filtered_payload is a
 * small allowlisted object, never a raw provider payload.
 *
 * @extends Factory<PaymentWebhookEvent>
 */
class PaymentWebhookEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => 'powerpay',
            'external_event_id' => 'evt_'.Str::uuid()->toString(),
            'payment_id' => null,
            'event_type' => 'payment.updated',
            'payload_hash' => hash('sha256', 'webhook-fixture-'.Str::uuid()),
            'filtered_payload' => ['event' => 'payment.updated', 'status' => 'succeeded'],
            'signature_verified' => true,
            'processing_status' => WebhookProcessingStatus::Received->value,
            'received_at' => now(),
            'processed_at' => null,
            'failed_at' => null,
            'retention_until' => now()->addDays(90),
            'processing_error_sanitized' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'processing_status' => WebhookProcessingStatus::Processed->value,
            'processed_at' => now(),
            'failed_at' => null,
        ]);
    }

    public function ignored(): static
    {
        return $this->state(fn (array $attributes): array => [
            'processing_status' => WebhookProcessingStatus::Ignored->value,
            'processed_at' => now(),
            'failed_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'processing_status' => WebhookProcessingStatus::Failed->value,
            'failed_at' => now(),
            'processed_at' => null,
            'processing_error_sanitized' => 'The webhook could not be processed.',
        ]);
    }

    /**
     * Minimal, safe shape for an event whose signature could not be verified:
     * no payload, no payment link, failed with a sanitised generic error.
     */
    public function invalidSignatureMinimal(): static
    {
        return $this->state(fn (array $attributes): array => [
            'signature_verified' => false,
            'external_event_id' => null,
            'processing_status' => WebhookProcessingStatus::Failed->value,
            'filtered_payload' => null,
            'payment_id' => null,
            'failed_at' => now(),
            'processed_at' => null,
            'processing_error_sanitized' => 'Signature verification failed.',
        ]);
    }

    /**
     * Link the event to an existing payment, mirroring its canonical provider so the
     * consistency trigger accepts it. Requires a verified signature. Never mutates the payment.
     */
    public function forPayment(Payment $payment): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_id' => $payment->getKey(),
            'provider' => $payment->provider,
            'signature_verified' => true,
        ]);
    }
}
