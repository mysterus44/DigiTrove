<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Models\DownloadGrant;
use App\Models\DownloadLog;
use App\Models\Order;
use App\Models\ProductFile;
use App\Support\DeliveryConfig;
use App\Support\DownloadAccessDenied;
use App\Support\DownloadFileSnapshot;
use App\Support\PreparedDownload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DownloadFileService
{
    public function __construct(
        private readonly PrivateFileLocator $files,
        private readonly ByteRangeParser $ranges,
    ) {}

    public function prepare(
        string $grantPublicId,
        #[\SensitiveParameter]
        string $rawAttemptToken,
        ?string $rangeHeader,
        bool $head,
        ?CarbonImmutable $at = null,
    ): PreparedDownload {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Download file preparation cannot join an ambient transaction.');
        }

        if (! DeliveryConfig::enabled()) {
            throw new DownloadAccessDenied;
        }

        $this->assertCredentialShape($grantPublicId, $rawAttemptToken);
        $phaseAt = $at ?? CarbonImmutable::now();
        $attemptDigest = hash('sha256', $rawAttemptToken);

        $candidate = DB::table('download_logs as dl')
            ->join('download_grants as dg', 'dg.id', '=', 'dl.download_grant_id')
            ->join('order_items as oi', 'oi.id', '=', 'dg.order_item_id')
            ->where('dg.public_id', $grantPublicId)
            ->where('dl.attempt_token_hash', $attemptDigest)
            ->first(['dl.id AS log_id', 'dg.id AS grant_id', 'oi.order_id']);

        if ($candidate === null) {
            throw new DownloadAccessDenied;
        }

        $snapshot = DB::transaction(function () use (
            $candidate,
            $grantPublicId,
            $attemptDigest,
            $phaseAt,
        ): ?DownloadFileSnapshot {
            $order = Order::query()->whereKey($candidate->order_id)->lockForUpdate()->first();
            $grant = DownloadGrant::query()->whereKey($candidate->grant_id)->lockForUpdate()->first();
            $log = DownloadLog::query()->whereKey($candidate->log_id)->lockForUpdate()->first();

            if ($order === null || $grant === null || $log === null
                || $grant->public_id !== $grantPublicId
                || ! hash_equals((string) $log->attempt_token_hash, $attemptDigest)
                || $log->download_grant_id !== $grant->id
                || ! in_array($log->status, ['started', 'completed'], true)) {
                throw new DownloadAccessDenied;
            }

            $reason = match (true) {
                $log->attempt_expires_at->lte($phaseAt) => 'attempt_expired',
                $grant->revoked_at !== null => 'grant_revoked',
                $grant->expires_at->lte($phaseAt) => 'grant_expired',
                ! in_array($order->status->value, ['paid', 'partially_refunded'], true) => 'order_not_deliverable',
                default => null,
            };
            if ($reason !== null) {
                $this->denyIfStarted($log, $reason, $phaseAt);

                return null;
            }

            $file = ProductFile::query()->whereKey($grant->product_file_id)->first();
            if ($file === null || ! $file->is_active) {
                $this->denyIfStarted($log, 'product_file_unavailable', $phaseAt);

                return null;
            }

            return new DownloadFileSnapshot(
                orderId: (int) $order->id,
                grantId: (int) $grant->id,
                logId: (int) $log->id,
                productFileId: (int) $file->id,
                storageDisk: (string) $file->storage_disk,
                storagePath: (string) $file->storage_path,
                sizeBytes: (int) $file->size_bytes,
                fileName: (string) $file->original_name,
                mimeType: (string) $file->mime_type,
                checksum: (string) $file->checksum_sha256,
            );
        });

        if ($snapshot === null) {
            throw new DownloadAccessDenied;
        }

        $file = $this->productFileFromSnapshot($snapshot);
        $stream = null;
        $xAccelPath = null;

        try {
            $this->files->assertResolvable($file);
            $size = $this->files->size($file);
        } catch (Throwable) {
            $this->finalizeStorageFailure($snapshot, $attemptDigest, $at ?? CarbonImmutable::now());

            throw new DownloadAccessDenied;
        }

        $range = $this->ranges->parse($rangeHeader, $size);
        $start = $range?->start ?? 0;
        $end = $range?->end ?? $size - 1;

        try {
            $fileDriver = DeliveryConfig::fileDriver();
            $chunkBytes = DeliveryConfig::streamChunkBytes();

            if (! $head) {
                if ($fileDriver === 'x_accel') {
                    if ($size < DeliveryConfig::xAccelMinBytes()) {
                        throw new DownloadAccessDenied;
                    }
                    $xAccelPath = $this->files->xAccelPath($file);
                } else {
                    $stream = $this->files->readStream($file);
                }
            }
        } catch (Throwable) {
            $this->closeStream($stream);
            $this->finalizeStorageFailure($snapshot, $attemptDigest, $at ?? CarbonImmutable::now());

            throw new DownloadAccessDenied;
        }

        if (! DeliveryConfig::enabled()) {
            $this->closeStream($stream);

            throw new DownloadAccessDenied;
        }

        try {
            $finalized = $this->finalizePreparation(
                $snapshot,
                $grantPublicId,
                $attemptDigest,
                $head,
                $at ?? CarbonImmutable::now(),
            );
        } catch (Throwable) {
            $this->closeStream($stream);

            throw new DownloadAccessDenied;
        }

        if (! $finalized || DB::transactionLevel() !== 0 || ! DeliveryConfig::enabled()) {
            $this->closeStream($stream);

            throw new DownloadAccessDenied;
        }

        return new PreparedDownload(
            stream: $stream,
            xAccelPath: $xAccelPath,
            fileName: $snapshot->fileName,
            mimeType: $snapshot->mimeType,
            checksum: $snapshot->checksum,
            size: $size,
            start: $start,
            end: $end,
            partial: $range !== null,
            head: $head,
            chunkBytes: $chunkBytes,
        );
    }

    private function assertCredentialShape(
        string $publicId,
        #[\SensitiveParameter] string $rawToken,
    ): void {
        hash('sha256', $rawToken);
        if (! Str::isUuid($publicId) || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $rawToken) !== 1) {
            throw new DownloadAccessDenied;
        }
    }

    private function denyIfStarted(DownloadLog $log, string $reason, CarbonImmutable $at): void
    {
        if ($log->status !== 'started') {
            return;
        }

        $log->forceFill([
            'status' => 'denied',
            'denial_reason_code' => $reason,
            'terminal_at' => $at,
        ])->save();
    }

    private function finalizePreparation(
        DownloadFileSnapshot $snapshot,
        string $grantPublicId,
        string $attemptDigest,
        bool $head,
        CarbonImmutable $at,
    ): bool {
        return DB::transaction(function () use (
            $snapshot,
            $grantPublicId,
            $attemptDigest,
            $head,
            $at,
        ): bool {
            if (! DeliveryConfig::enabled()) {
                return false;
            }

            $order = Order::query()->whereKey($snapshot->orderId)->lockForUpdate()->first();
            $grant = DownloadGrant::query()->whereKey($snapshot->grantId)->lockForUpdate()->first();
            $log = DownloadLog::query()->whereKey($snapshot->logId)->lockForUpdate()->first();

            if ($order === null || $grant === null || $log === null
                || $grant->public_id !== $grantPublicId
                || ! hash_equals((string) $log->attempt_token_hash, $attemptDigest)
                || $log->download_grant_id !== $grant->id
                || $grant->product_file_id !== $snapshot->productFileId
                || ! in_array($log->status, ['started', 'completed'], true)) {
                return false;
            }

            $reason = match (true) {
                $log->attempt_expires_at->lte($at) => 'attempt_expired',
                $grant->revoked_at !== null => 'grant_revoked',
                $grant->expires_at->lte($at) => 'grant_expired',
                ! in_array($order->status->value, ['paid', 'partially_refunded'], true) => 'order_not_deliverable',
                default => null,
            };
            if ($reason !== null) {
                $this->denyIfStarted($log, $reason, $at);

                return false;
            }

            $file = ProductFile::query()->whereKey($snapshot->productFileId)->first();
            if ($file === null || ! $file->is_active || ! $this->matchesSnapshot($file, $snapshot)) {
                $this->denyIfStarted($log, 'product_file_unavailable', $at);

                return false;
            }

            if (! $head) {
                $this->completeIfStarted($log, $at);
            }

            return true;
        }, attempts: 1);
    }

    private function finalizeStorageFailure(
        DownloadFileSnapshot $snapshot,
        string $attemptDigest,
        CarbonImmutable $at,
    ): void {
        try {
            DB::transaction(function () use ($snapshot, $attemptDigest, $at): void {
                Order::query()->whereKey($snapshot->orderId)->lockForUpdate()->first();
                $grant = DownloadGrant::query()->whereKey($snapshot->grantId)->lockForUpdate()->first();
                $log = DownloadLog::query()->whereKey($snapshot->logId)->lockForUpdate()->first();

                if ($grant !== null && $log !== null
                    && $log->download_grant_id === $grant->id
                    && hash_equals((string) $log->attempt_token_hash, $attemptDigest)) {
                    $this->denyIfStarted($log, 'storage_failure', $at);
                }
            }, attempts: 1);
        } catch (Throwable) {
            // The public boundary remains uniform; stale started logs are
            // recovered by the reconciliation command without restoring quota.
        }
    }

    private function productFileFromSnapshot(DownloadFileSnapshot $snapshot): ProductFile
    {
        $file = new ProductFile;
        $file->forceFill([
            'id' => $snapshot->productFileId,
            'storage_disk' => $snapshot->storageDisk,
            'storage_path' => $snapshot->storagePath,
            'size_bytes' => $snapshot->sizeBytes,
            'original_name' => $snapshot->fileName,
            'mime_type' => $snapshot->mimeType,
            'checksum_sha256' => $snapshot->checksum,
            'is_active' => true,
        ]);
        $file->exists = true;

        return $file;
    }

    private function matchesSnapshot(ProductFile $file, DownloadFileSnapshot $snapshot): bool
    {
        return (int) $file->id === $snapshot->productFileId
            && (string) $file->storage_disk === $snapshot->storageDisk
            && (string) $file->storage_path === $snapshot->storagePath
            && (int) $file->size_bytes === $snapshot->sizeBytes
            && (string) $file->original_name === $snapshot->fileName
            && (string) $file->mime_type === $snapshot->mimeType
            && (string) $file->checksum_sha256 === $snapshot->checksum;
    }

    /**
     * @param  resource|null  $stream
     */
    private function closeStream(mixed $stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    private function completeIfStarted(DownloadLog $log, CarbonImmutable $at): void
    {
        if ($log->status !== 'started') {
            return;
        }

        $log->forceFill([
            'status' => 'completed',
            'terminal_at' => $at,
        ])->save();
    }
}
