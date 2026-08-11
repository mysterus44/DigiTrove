<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

/**
 * An immutable read view of one policy version.
 *
 * The rate stays in BASIS POINTS: that is what PostgreSQL stores and what a commission
 * snapshots. The percentage label is derived for humans with integer arithmetic only —
 * no float ever touches a rate, and no amount is ever divided by 100, because XOF has
 * exponent 0 and such a division would silently invent centimes (the P6-B0 rule).
 */
final readonly class AffiliatePolicy
{
    public function __construct(
        public int $id,
        public int $version,
        public string $status,
        public int $attributionWindowDays,
        public int $defaultCommissionBps,
        public int $payableDelayDays,
        public int $payoutThresholdMinor,
        public string $payoutCurrency,
        public ?string $effectiveFrom = null,
        public ?string $effectiveUntil = null,
    ) {}

    /** Human-readable rate, derived from basis points without any floating point. */
    public function commissionPercentageLabel(): string
    {
        $whole = intdiv($this->defaultCommissionBps, 100);
        $fraction = $this->defaultCommissionBps % 100;

        if ($fraction === 0) {
            return $whole.' %';
        }

        return rtrim(sprintf('%d,%02d', $whole, $fraction), '0').' %';
    }

    /** Minor units with an explicit currency — never divided, never summed across currencies. */
    public function payoutThresholdLabel(): string
    {
        return $this->payoutThresholdMinor.' '.$this->payoutCurrency;
    }

    public function isInForce(): bool
    {
        return $this->status === 'active';
    }
}
