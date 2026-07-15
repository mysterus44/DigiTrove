<?php

namespace Database\Factories;

use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Builds refund rows with no provider call and no real refund logic. Persistence must
 * use forPayment() with an existing payment; the factory never creates or mutates a
 * financial aggregate implicitly. Hashes are fake but structurally valid.
 *
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'payment_id' => null,
            'provider' => 'powerpay',
            'provider_refund_reference' => null,
            'idempotency_key_hash' => hash('sha256', 'refund-fixture-'.Str::uuid()),
            'amount_minor' => 1000,
            'currency' => 'XOF',
            'status' => RefundStatus::Pending->value,
            'reason_code' => null,
            'reason_note_sanitized' => null,
            'initiated_by_user_id' => null,
            'provider_status' => null,
            'provider_metadata' => null,
            'requested_at' => now(),
            'processing_at' => null,
            'succeeded_at' => null,
            'failed_at' => null,
            'cancelled_at' => null,
            'last_verified_at' => null,
        ];
    }

    /**
     * Bind the refund to an existing (succeeded) payment, mirroring its canonical
     * provider and currency so the consistency trigger accepts it. Never mutates the payment.
     */
    public function forPayment(Payment $payment): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_id' => $payment->getKey(),
            'provider' => $payment->provider,
            'currency' => $payment->currency,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RefundStatus::Processing->value,
            'processing_at' => now(),
            'succeeded_at' => null,
            'failed_at' => null,
            'cancelled_at' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RefundStatus::Pending->value,
            'processing_at' => null,
            'succeeded_at' => null,
            'failed_at' => null,
            'cancelled_at' => null,
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RefundStatus::Succeeded->value,
            'succeeded_at' => now(),
            'failed_at' => null,
            'cancelled_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RefundStatus::Failed->value,
            'succeeded_at' => null,
            'failed_at' => now(),
            'cancelled_at' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RefundStatus::Cancelled->value,
            'succeeded_at' => null,
            'failed_at' => null,
            'cancelled_at' => now(),
        ]);
    }
}
