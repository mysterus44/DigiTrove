<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class AnalyticsOperationsConfig
{
    public static function enabled(): bool
    {
        return self::boolean('analytics.operations.enabled');
    }

    public static function assertRollupsEnabled(): void
    {
        if (! self::rollupsEnabled()) {
            throw new RuntimeException('Authoritative analytics rollups are disabled.');
        }
    }

    public static function assertPartitionsEnabled(): void
    {
        if (! self::partitionsEnabled()) {
            throw new RuntimeException('Analytics partition operations are disabled.');
        }
    }

    public static function rollupsEnabled(): bool
    {
        return self::enabled() && self::boolean('analytics.operations.rollups_enabled');
    }

    public static function partitionsEnabled(): bool
    {
        return self::enabled() && self::boolean('analytics.operations.partitions_enabled');
    }

    public static function maxBackfillDays(): int
    {
        return self::integer('analytics.operations.max_backfill_days', 1, 366);
    }

    public static function partitionMonthsAhead(): int
    {
        return self::integer('analytics.operations.partition_months_ahead', 0, 24);
    }

    public static function partitionMonthsBehind(): int
    {
        return self::integer('analytics.operations.partition_months_behind', 0, 24);
    }

    public static function statementTimeoutMilliseconds(): int
    {
        return self::integer('analytics.operations.statement_timeout_ms', 1_000, 300_000);
    }

    public static function lockTimeoutMilliseconds(): int
    {
        return self::integer('analytics.operations.lock_timeout_ms', 100, 60_000);
    }

    private static function boolean(string $key): bool
    {
        $value = config($key);

        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [0, '0'], true)) {
            return false;
        }

        if (in_array($value, [1, '1'], true)) {
            return true;
        }

        throw new RuntimeException("The configuration value {$key} is not boolean.");
    }

    private static function integer(string $key, int $minimum, int $maximum): int
    {
        $value = config($key);

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException("The configuration value {$key} is not an integer.");
        }

        $integer = (int) $value;

        if ($integer < $minimum || $integer > $maximum) {
            throw new RuntimeException("The configuration value {$key} is out of range.");
        }

        return $integer;
    }
}
