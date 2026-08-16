<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * P6-D1 (D-058). The only affiliate configuration that exists, and it is fail-closed:
 * an unset or malformed flag refuses rather than defaulting to "on".
 *
 * The flag governs the whole affiliate surface, not policies alone: P6-D1.1 lifecycle and
 * code authorities close on the same switch. A second toggle would let one half of the
 * programme run while the other was shut, which is not a state anyone should be able to
 * reach by editing configuration.
 */
final class AffiliateConfig
{
    public static function governanceEnabled(): bool
    {
        $value = config('affiliate.governance_enabled', false);

        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [0, '0'], true)) {
            return false;
        }

        if (in_array($value, [1, '1'], true)) {
            return true;
        }

        throw new RuntimeException('The affiliate governance flag is invalid.');
    }

    public static function assertGovernanceEnabled(): void
    {
        if (! self::governanceEnabled()) {
            throw new RuntimeException('Affiliate governance is disabled.');
        }
    }
}
