<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Builds payment attempts without any provider call or confirmation logic.
 * Hashes are fake but structurally valid; no raw idempotency key is ever produced.
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'order_id' => Order::factory(),
            'provider' => 'powerpay',
            'provider_payment_reference' => null,
            'idempotency_key_hash' => hash('sha256', 'payment-fixture-'.Str::uuid()),
            'attempt_number' => 1,
            'amount_minor' => 10000,
            'currency' => 'XOF',
            'status' => PaymentStatus::Pending->value,
            'provider_status' => null,
            'provider_method' => null,
            'provider_metadata' => null,
            'failure_code' => null,
            'failure_message_sanitized' => null,
            'initiated_at' => now(),
            'processing_at' => null,
            'succeeded_at' => null,
            'failed_at' => null,
            'cancelled_at' => null,
            'expired_at' => null,
            'last_verified_at' => null,
        ];
    }

    /**
     * Bind the payment to an existing order and mirror its money snapshot so the
     * immediate amount trigger accepts it. Does not modify the order.
     */
    public function forOrder(Order $order): static
    {
        return $this->state(fn (array $attributes): array => [
            'order_id' => $order->getKey(),
            'amount_minor' => $order->total_minor,
            'currency' => $order->currency,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Processing->value,
            'processing_at' => now(),
        ]);
    }

    public function requiresReview(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::RequiresReview->value,
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Succeeded->value,
            'succeeded_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Failed->value,
            'failed_at' => now(),
            'failure_code' => 'provider_declined',
            'failure_message_sanitized' => 'The payment was declined by the provider.',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Cancelled->value,
            'cancelled_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Expired->value,
            'expired_at' => now(),
        ]);
    }
}
