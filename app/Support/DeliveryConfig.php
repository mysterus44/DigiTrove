<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Fail-closed accessor for the secure delivery pipeline configuration
 * (P4-C0, D-035).
 *
 * The pipeline is DISABLED by default. Every getter validates its bound so a
 * misconfiguration can never silently widen access: an out-of-range TTL/quota,
 * or (when enabled) a missing or non-HTTPS download base URL, throws rather than
 * degrading to an unsafe default.
 */
final class DeliveryConfig
{
    private const TTL_MIN = 1;

    private const TTL_MAX = 525_600; // one year in minutes

    private const QUOTA_MIN = 1;

    private const QUOTA_MAX = 100_000;

    public static function enabled(): bool
    {
        return (bool) config('delivery.enabled', false);
    }

    /**
     * Validate every runtime dependency before a job is dispatched or issues a
     * credential. The worker repeats this check because its environment may
     * differ from the web process that dispatched it.
     */
    public static function assertPipelineReady(): void
    {
        self::grantTtlMinutes();
        self::grantMaxDownloads();
        self::jobUniqueSeconds();
        self::downloadBaseUrl();
        self::assertMailerSafe();
    }

    public static function grantTtlMinutes(): int
    {
        $value = (int) config('delivery.grant.ttl_minutes');

        return self::bounded($value, self::TTL_MIN, self::TTL_MAX, 'delivery.grant.ttl_minutes');
    }

    public static function grantMaxDownloads(): int
    {
        $value = (int) config('delivery.grant.max_downloads');

        return self::bounded($value, self::QUOTA_MIN, self::QUOTA_MAX, 'delivery.grant.max_downloads');
    }

    public static function jobUniqueSeconds(): int
    {
        $value = (int) config('delivery.job.unique_seconds');

        return self::bounded($value, 1, 86_400, 'delivery.job.unique_seconds');
    }

    /**
     * The download base URL — required and HTTPS-bound only when the pipeline is
     * actually enabled. Throws fail-closed otherwise.
     */
    public static function downloadBaseUrl(): string
    {
        $value = config('delivery.download_base_url');

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('The delivery download base URL is not configured.');
        }

        $value = rtrim(trim($value), '/');

        if (self::requiresHttps() && ! str_starts_with(strtolower($value), 'https://')) {
            throw new RuntimeException('The delivery download base URL must use HTTPS.');
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('The delivery download base URL is invalid.');
        }

        $parts = parse_url($value);
        if (! is_array($parts)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('The delivery download base URL contains forbidden components.');
        }

        return $value;
    }

    private static function requiresHttps(): bool
    {
        return (bool) (config('delivery.require_https') ?? ! in_array(config('app.env'), ['local', 'testing'], true));
    }

    private static function assertMailerSafe(): void
    {
        $mailer = config('mail.default');

        if (! is_string($mailer) || trim($mailer) === '' || $mailer === 'log') {
            throw new RuntimeException('The delivery mail transport is unsafe.');
        }

        if ($mailer === 'array' && ! in_array(config('app.env'), ['local', 'testing'], true)) {
            throw new RuntimeException('The delivery mail transport cannot deliver messages.');
        }

        if (in_array($mailer, ['failover', 'roundrobin'], true)) {
            $members = config("mail.mailers.{$mailer}.mailers");

            if (! is_array($members) || $members === [] || array_intersect($members, ['log', 'array']) !== []) {
                throw new RuntimeException('The delivery mail transport contains an unsafe fallback.');
            }
        }
    }

    private static function bounded(int $value, int $min, int $max, string $key): int
    {
        if ($value < $min || $value > $max) {
            throw new RuntimeException("The configuration value {$key} is out of range.");
        }

        return $value;
    }
}
