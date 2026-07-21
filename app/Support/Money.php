<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * An exact amount of money, held in minor units (D-005).
 *
 * XOF has no minor unit, so 1 minor = 1 FCFA; other currencies keep their own
 * scale. The amount is ALWAYS an `int`: no float, no division, no rounding ever
 * touches money in this codebase.
 *
 * The currency must already be in its canonical form (three uppercase letters),
 * matching the `currency = upper(currency)` CHECK carried by every monetary
 * table. A lowercase code is REFUSED rather than normalised, so that an upstream
 * bug surfaces here instead of being masked.
 *
 * Formatting for display is deliberately out of scope for P3-D1.
 */
final readonly class Money
{
    private const CURRENCY_PATTERN = '/^[A-Z]{3}$/';

    private function __construct(
        public int $minor,
        public string $currency,
    ) {}

    public static function of(int $minor, string $currency): self
    {
        if (preg_match(self::CURRENCY_PATTERN, $currency) !== 1) {
            throw new InvalidArgumentException(
                "Currency must be three uppercase letters, got [{$currency}]."
            );
        }

        return new self($minor, $currency);
    }

    public static function zero(string $currency): self
    {
        return self::of(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(IntegerMath::add($this->minor, $other->minor), $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(IntegerMath::subtract($this->minor, $other->minor), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor < $other->minor;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot combine [{$this->currency}] with [{$other->currency}]: no automatic conversion exists (D-018)."
            );
        }
    }
}
