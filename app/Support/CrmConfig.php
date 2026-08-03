<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class CrmConfig
{
    public static function enabled(): bool
    {
        $value = config('crm.foundation_enabled', false);

        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [0, '0'], true)) {
            return false;
        }

        if (in_array($value, [1, '1'], true)) {
            return true;
        }

        throw new RuntimeException('The CRM foundation flag is invalid.');
    }

    public static function assertEnabled(): void
    {
        if (! self::enabled()) {
            throw new RuntimeException('The CRM foundation is disabled.');
        }
    }

    public static function marketingPolicyVersion(): string
    {
        $version = config('crm.marketing_policy_version');

        if (! is_string($version)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $version) !== 1
            || $version === 'unknown') {
            throw new RuntimeException('The CRM marketing policy version is not configured.');
        }

        return $version;
    }
}
