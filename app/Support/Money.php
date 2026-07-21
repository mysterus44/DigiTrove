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
    /**
     * `\A` and `\z` are ABSOLUTE anchors.
     *
     * `^...$` was not: in PCRE, `$` also matches just before a FINAL newline,
     * so `/^[A-Z]{3}$/` accepted "XOF\n" (P3-D1.1 / A1). Never reintroduce `$`
     * here without the `D` modifier.
     */
    private const CURRENCY_PATTERN = '/\A[A-Z]{3}\z/';

    private function __construct(
        public int $minor,
        public string $currency,
    ) {}

    public static function of(int $minor, string $currency): self
    {
        self::assertValidCurrency($currency);

        return new self($minor, $currency);
    }

    /**
     * Single source of truth for the currency contract: exactly three ASCII
     * uppercase letters, matching the `currency = upper(currency)` and
     * `char_length(currency) = 3` CHECK constraints carried by every monetary
     * table. Reused by the pricing DTOs so one rule governs the whole kernel.
     *
     * @throws InvalidArgumentException
     */
    public static function assertValidCurrency(string $currency): void
    {
        if (preg_match(self::CURRENCY_PATTERN, $currency) !== 1) {
            throw new InvalidArgumentException(
                'Currency must be exactly three uppercase ASCII letters, got ['.addcslashes($currency, "\0..\37").'].'
            );
        }
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
