<?php

namespace App\Support;

use RuntimeException;

final class AnalyticsDashboardConfig
{
    public const CONNECTION = 'pgsql_analytics_reader';

    public const READER_ROLE = 'digitrove_analytics_reader';

    public static function enabled(): bool
    {
        return (bool) config('analytics.dashboard.enabled', false);
    }

    public static function available(): bool
    {
        return self::enabled()
            && config('database.connections.'.self::CONNECTION.'.username') === self::READER_ROLE
            && is_string(config('database.connections.'.self::CONNECTION.'.password'))
            && config('database.connections.'.self::CONNECTION.'.password') !== '';
    }

    public static function assertAvailable(): void
    {
        if (! self::available()) {
            throw new RuntimeException('Analytics dashboard is unavailable.');
        }
    }

    public static function defaultDays(): int
    {
        $days = (int) config('analytics.dashboard.default_days', 30);

        return max(1, min($days, self::maxDays()));
    }

    public static function maxDays(): int
    {
        return max(1, min((int) config('analytics.dashboard.max_days', 366), 366));
    }

    public static function cacheSeconds(): int
    {
        return max(0, min((int) config('analytics.dashboard.cache_seconds', 60), 300));
    }

    public static function statementTimeoutMs(): int
    {
        return max(1, (int) config('analytics.dashboard.statement_timeout_ms', 5000));
    }

    public static function lockTimeoutMs(): int
    {
        return max(1, (int) config('analytics.dashboard.lock_timeout_ms', 1000));
    }
}
