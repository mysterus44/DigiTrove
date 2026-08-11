<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * P6-D1 (D-058). The only affiliate configuration that exists, and it is fail-closed:
 * an unset or malformed flag refuses rather than defaulting to "on".
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
            throw new RuntimeException('Affiliate policy governance is disabled.');
        }
    }
}
