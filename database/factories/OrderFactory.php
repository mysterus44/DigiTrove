<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Persist orders and their items inside one database transaction so deferred
 * integrity triggers can validate the complete commercial snapshot.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    private const CROCKFORD_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function definition(): array
    {
        $placedAt = now();

        return [
            'public_id' => (string) Str::uuid(),
            'order_number' => sprintf('DGT-%s-%s', $placedAt->format('Y'), $this->crockfordSuffix()),
            'cart_id' => null,
            'checkout_idempotency_hash' => hash('sha256', 'fixture-'.Str::uuid()),
            'user_id' => null,
            'visitor_id' => null,
            'customer_email' => fake()->safeEmail(),
            'customer_name_snapshot' => null,
            'billing_country_code' => null,
            'coupon_id' => null,
            'coupon_code_snapshot' => null,
            'coupon_discount_type_snapshot' => null,
            'coupon_percent_basis_points_snapshot' => null,
            'coupon_fixed_amount_minor_snapshot' => null,
            'subtotal_minor' => 10000,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 10000,
            'currency' => 'XOF',
            'status' => OrderStatus::Pending,
            'placed_at' => $placedAt,
            'expires_at' => $placedAt->copy()->addMinutes(30),
            'paid_at' => null,
            'cancelled_at' => null,
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
            'referrer_host' => null,
            'ip_hash' => null,
        ];
    }

    public function guest(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
        ]);
    }

    public function forUser(?User $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user?->getKey() ?? User::factory(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Pending,
            'paid_at' => null,
            'cancelled_at' => null,
        ]);
    }

    public function paymentReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::PaymentReview,
            'paid_at' => null,
            'cancelled_at' => null,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
            'cancelled_at' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Expired,
            'placed_at' => now()->subHours(2),
            'expires_at' => now()->subMinutes(90),
            'paid_at' => null,
            'cancelled_at' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Cancelled,
            'paid_at' => null,
            'cancelled_at' => now(),
        ]);
    }

    public function withPercentCoupon(
        ?Coupon $coupon = null,
        int $basisPoints = 1000,
        int $discountMinor = 1000,
    ): static {
        if ($basisPoints < 1 || $basisPoints > 10000 || $discountMinor < 1) {
            throw new InvalidArgumentException('Percent coupon factory values must satisfy P3B constraints.');
        }

        $code = $coupon?->code ?? strtoupper(fake()->unique()->bothify('ORDER-####-????'));
        $subtotalMinor = max(10000, $discountMinor);

        return $this->state(fn (array $attributes) => [
            'coupon_id' => $coupon?->getKey() ?? Coupon::factory()->percent($basisPoints)->state(['code' => $code]),
            'coupon_code_snapshot' => $code,
            'coupon_discount_type_snapshot' => 'percent',
            'coupon_percent_basis_points_snapshot' => $basisPoints,
            'coupon_fixed_amount_minor_snapshot' => null,
            'subtotal_minor' => $subtotalMinor,
            'discount_minor' => $discountMinor,
            'total_minor' => $subtotalMinor - $discountMinor,
        ]);
    }

    public function withFixedCoupon(
        ?Coupon $coupon = null,
        int $fixedAmountMinor = 1000,
        int $discountMinor = 1000,
    ): static {
        if ($fixedAmountMinor < 1 || $discountMinor < 1) {
            throw new InvalidArgumentException('Fixed coupon factory values must satisfy P3B constraints.');
        }

        $code = $coupon?->code ?? strtoupper(fake()->unique()->bothify('ORDER-####-????'));
        $subtotalMinor = max(10000, $discountMinor);

        return $this->state(fn (array $attributes) => [
            'coupon_id' => $coupon?->getKey() ?? Coupon::factory()->fixed()->state(['code' => $code]),
            'coupon_code_snapshot' => $code,
            'coupon_discount_type_snapshot' => 'fixed',
            'coupon_percent_basis_points_snapshot' => null,
            'coupon_fixed_amount_minor_snapshot' => $fixedAmountMinor,
            'subtotal_minor' => $subtotalMinor,
            'discount_minor' => $discountMinor,
            'total_minor' => $subtotalMinor - $discountMinor,
        ]);
    }

    private function crockfordSuffix(): string
    {
        $suffix = '';
        $lastIndex = strlen(self::CROCKFORD_ALPHABET) - 1;

        for ($position = 0; $position < 10; $position++) {
            $suffix .= self::CROCKFORD_ALPHABET[random_int(0, $lastIndex)];
        }

        return $suffix;
    }
}
