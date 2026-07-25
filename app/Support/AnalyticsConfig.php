<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use RuntimeException;

final class AnalyticsConfig
{
    public const CONSENT_COOKIE = 'dt_analytics_consent';

    public const VISITOR_COOKIE = 'dt_analytics_visitor';

    public const SESSION_COOKIE = 'dt_analytics_session';

    public static function enabled(): bool
    {
        $value = config('analytics.ingestion.enabled', false);

        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [0, '0'], true)) {
            return false;
        }

        if (in_array($value, [1, '1'], true)) {
            return true;
        }

        throw new RuntimeException('The analytics ingestion flag is invalid.');
    }

    public static function assertReady(Request $request): void
    {
        self::consentVersion();
        self::sessionTtlMinutes();
        self::sessionMaxHours();
        self::rateLimitPerMinute();
        self::propertiesMaxBytes();
        self::ipHashKey();
        self::ipHashKeyVersion();

        if (! in_array((string) config('app.env'), ['local', 'testing'], true) && ! $request->isSecure()) {
            throw new RuntimeException('Analytics ingestion requires HTTPS.');
        }
    }

    public static function consentVersion(): int
    {
        return self::boundedInteger(config('analytics.consent.version'), 1, 32_767, 'analytics.consent.version');
    }

    public static function sessionTtlMinutes(): int
    {
        $ttl = self::boundedInteger(config('analytics.session.ttl_minutes'), 1, 1_440, 'analytics.session.ttl_minutes');

        if ($ttl > self::sessionMaxHours() * 60) {
            throw new RuntimeException('The analytics session TTL exceeds its maximum age.');
        }

        return $ttl;
    }

    public static function sessionMaxHours(): int
    {
        return self::boundedInteger(config('analytics.session.max_hours'), 1, 168, 'analytics.session.max_hours');
    }

    public static function rateLimitPerMinute(): int
    {
        return self::boundedInteger(config('analytics.rate_limit.per_minute'), 1, 600, 'analytics.rate_limit.per_minute');
    }

    public static function propertiesMaxBytes(): int
    {
        return self::boundedInteger(config('analytics.properties.max_bytes'), 2, 4_096, 'analytics.properties.max_bytes');
    }

    public static function ipHashKey(): string
    {
        $key = config('analytics.ip_hash.key');

        if (! is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('The analytics IP HMAC key is not configured.');
        }

        return $key;
    }

    public static function ipHashKeyVersion(): int
    {
        return self::boundedInteger(config('analytics.ip_hash.key_version'), 1, 32_767, 'analytics.ip_hash.key_version');
    }

    public static function cookieSecure(): bool
    {
        return ! in_array((string) config('app.env'), ['local', 'testing'], true);
    }

    public static function sessionCookieMinutes(): int
    {
        return self::sessionMaxHours() * 60;
    }

    private static function boundedInteger(mixed $value, int $minimum, int $maximum, string $key): int
    {
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
