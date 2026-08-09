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
