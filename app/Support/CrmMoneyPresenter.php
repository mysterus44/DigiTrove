<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Presentation-only formatting of CRM money. It NEVER computes: no addition, no
 * conversion, no rounding, no float — the value arrives already computed by the
 * P6-A1.1 authority and leaves as a string.
 *
 * WHY MINOR UNITS ARE SHOWN VERBATIM — the repository has no currency-exponent table.
 * XOF has exponent 0 (no subunit) while USD has exponent 2, so dividing by 100 would
 * silently misstate every XOF amount by two orders of magnitude. Until an audited
 * exponent map exists, the honest rendering is the exact integer plus its currency.
 * A wrong number on a CRM screen is worse than a verbose one.
 */
final class CrmMoneyPresenter
{
    /** Render an amount stored in minor units, with its currency always explicit. */
    public static function format(int $minor, string $currency): string
    {
        Money::assertValidCurrency($currency);

        // Group digits for readability only; the value itself is untouched.
        $digits = number_format((float) abs($minor), 0, '.', ' ');
        $sign = $minor < 0 ? '-' : '';

        return $sign.$digits.' '.$currency.' (unités mineures)';
    }

    /**
     * Label for a currency-scoped row. There is deliberately no `total()` helper:
     * summing across currencies is meaningless and is not offered anywhere.
     */
    public static function currencyLabel(string $currency): string
    {
        Money::assertValidCurrency($currency);

        return $currency;
    }
}
