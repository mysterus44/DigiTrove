<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class CrmConfig
{
    public static function enabled(): bool
    {
        return self::boolean('crm.foundation_enabled', 'The CRM foundation flag is invalid.');
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

    public static function orderAttributionProcessingEnabled(): bool
    {
        return self::boolean(
            'crm.order_attribution.processing_enabled',
            'The CRM order attribution processing flag is invalid.',
        );
    }

    public static function assertOrderAttributionProcessingEnabled(): void
    {
        self::assertEnabled();

        if (! self::orderAttributionProcessingEnabled()) {
            throw new RuntimeException('CRM order attribution processing is disabled.');
        }
    }

    public static function orderAttributionBatchSize(): int
    {
        $value = config('crm.order_attribution.batch_size', 50);
        $valid = (is_int($value) && ! is_bool($value))
            || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1);

        if (! $valid) {
            throw new RuntimeException('The CRM order attribution batch size is invalid.');
        }

        $batchSize = (int) $value;

        if ($batchSize < 1 || $batchSize > 100) {
            throw new RuntimeException('The CRM order attribution batch size is invalid.');
        }

        return $batchSize;
    }

    public static function commerceRollupRefreshProcessingEnabled(): bool
    {
        return self::boolean(
            'crm.commerce_rollup_refresh.processing_enabled',
            'The CRM commerce rollup refresh processing flag is invalid.',
        );
    }

    public static function assertCommerceRollupRefreshProcessingEnabled(): void
    {
        self::assertEnabled();

        if (! self::commerceRollupRefreshProcessingEnabled()) {
            throw new RuntimeException('CRM commerce rollup refresh processing is disabled.');
        }
    }

    public static function commerceRollupRefreshBatchSize(): int
    {
        $value = config('crm.commerce_rollup_refresh.batch_size', 50);
        $valid = (is_int($value) && ! is_bool($value))
            || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1);

        if (! $valid) {
            throw new RuntimeException('The CRM commerce rollup refresh batch size is invalid.');
        }

        $batchSize = (int) $value;

        if ($batchSize < 1 || $batchSize > 100) {
            throw new RuntimeException('The CRM commerce rollup refresh batch size is invalid.');
        }

        return $batchSize;
    }

    public static function commerceRollupBackfillEnabled(): bool
    {
        return self::boolean(
            'crm.commerce_rollup_backfill.enabled',
            'The CRM commerce rollup backfill flag is invalid.',
        );
    }

    public static function assertCommerceRollupBackfillEnabled(): void
    {
        self::assertEnabled();

        if (! self::commerceRollupBackfillEnabled()) {
            throw new RuntimeException('CRM commerce rollup backfill is disabled.');
        }
    }

    public static function segmentRebuildEnabled(): bool
    {
        return self::boolean('crm.segment_rebuild.enabled', 'The CRM segment rebuild flag is invalid.');
    }

    public static function assertSegmentRebuildEnabled(): void
    {
        self::assertEnabled();

        if (! self::segmentRebuildEnabled()) {
            throw new RuntimeException('CRM segment rebuild is disabled.');
        }
    }

    public static function segmentRebuildProcessingEnabled(): bool
    {
        return self::boolean('crm.segment_rebuild.processing_enabled', 'The CRM segment rebuild processing flag is invalid.');
    }

    public static function assertSegmentRebuildProcessingEnabled(): void
    {
        self::assertEnabled();

        if (! self::segmentRebuildProcessingEnabled()) {
            throw new RuntimeException('CRM segment rebuild processing is disabled.');
        }
    }

    public static function segmentRebuildBatchSize(): int
    {
        $value = config('crm.segment_rebuild.batch_size', 50);
        $valid = (is_int($value) && ! is_bool($value))
            || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1);

        if (! $valid) {
            throw new RuntimeException('The CRM segment rebuild batch size is invalid.');
        }

        $batchSize = (int) $value;

        if ($batchSize < 1 || $batchSize > 100) {
            throw new RuntimeException('The CRM segment rebuild batch size is invalid.');
        }

        return $batchSize;
    }

    // ── P6-B1 exports ────────────────────────────────────────────────────────────

    public static function exportsEnabled(): bool
    {
        return self::boolean('crm.exports.enabled', 'The CRM exports flag is invalid.');
    }

    public static function assertExportsEnabled(): void
    {
        self::assertEnabled();

        if (! self::exportsEnabled()) {
            throw new RuntimeException('CRM exports are disabled.');
        }
    }

    public static function exportProcessingEnabled(): bool
    {
        return self::boolean('crm.exports.processing_enabled', 'The CRM export processing flag is invalid.');
    }

    public static function assertExportProcessingEnabled(): void
    {
        self::assertExportsEnabled();

        if (! self::exportProcessingEnabled()) {
            throw new RuntimeException('CRM export processing is disabled.');
        }
    }

    public static function exportPurgeEnabled(): bool
    {
        return self::boolean('crm.exports.purge_enabled', 'The CRM export purge flag is invalid.');
    }

    public static function assertExportPurgeEnabled(): void
    {
        self::assertEnabled();

        if (! self::exportPurgeEnabled()) {
            throw new RuntimeException('CRM export purge is disabled.');
        }
    }

    /** Bounded 1..50000, validated BEFORE any write so a bad flag never creates a row. */
    public static function exportMaxRows(): int
    {
        return self::boundedInteger('crm.exports.max_rows', 10000, 1, 50000, 'The CRM export row limit is invalid.');
    }

    /** Bounded 1..168 hours (one week). */
    public static function exportTtlHours(): int
    {
        return self::boundedInteger('crm.exports.ttl_hours', 24, 1, 168, 'The CRM export TTL is invalid.');
    }

    /**
     * The export disk MUST be a configured LOCAL, non-public disk. A public disk would
     * publish CRM identity data at a guessable URL, so the check is on the resolved disk
     * configuration rather than on the name.
     */
    public static function exportDisk(): string
    {
        $disk = config('crm.exports.disk', 'private');

        if (! is_string($disk) || preg_match('/\A[a-z0-9_]{1,32}\z/', $disk) !== 1) {
            throw new RuntimeException('The CRM export disk is invalid.');
        }

        $configured = config("filesystems.disks.{$disk}");

        // The property that actually matters is UNREACHABILITY, not the `visibility`
        // key: a local disk with no `url` is not addressable over HTTP at all, whereas a
        // disk carrying a `url` (or literally the `public` disk) publishes whatever is
        // written to it at a guessable address.
        if (! is_array($configured)
            || $disk === 'public'
            || ($configured['driver'] ?? null) !== 'local'
            || array_key_exists('url', $configured)) {
            throw new RuntimeException('The CRM export disk must be a private, non-served local disk.');
        }

        return $disk;
    }

    private static function boundedInteger(string $key, int $default, int $min, int $max, string $message): int
    {
        $value = config($key, $default);
        $valid = (is_int($value) && ! is_bool($value))
            || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1);

        if (! $valid) {
            throw new RuntimeException($message);
        }

        $int = (int) $value;

        if ($int < $min || $int > $max) {
            throw new RuntimeException($message);
        }

        return $int;
    }

    private static function boolean(string $key, string $message): bool
    {
        $value = config($key, false);

        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [0, '0'], true)) {
            return false;
        }

        if (in_array($value, [1, '1'], true)) {
            return true;
        }

        throw new RuntimeException($message);
    }
}
