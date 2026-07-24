<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Models\DownloadGrant;
use App\Models\DownloadLog;
use App\Models\Order;
use App\Models\ProductFile;
use App\Support\DeliveryConfig;
use App\Support\DownloadAccessDenied;
use App\Support\PreparedDownload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class DownloadFileService
{
    public function __construct(
        private readonly PrivateFileLocator $files,
        private readonly ByteRangeParser $ranges,
    ) {}

    public function prepare(
        string $grantPublicId,
        string $rawAttemptToken,
        ?string $rangeHeader,
        bool $head,
        ?CarbonImmutable $at = null,
    ): PreparedDownload {
        $this->assertCredentialShape($grantPublicId, $rawAttemptToken);
        $now = $at ?? CarbonImmutable::now();
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

        $prepared = DB::transaction(function () use (
            $candidate,
            $grantPublicId,
            $attemptDigest,
            $rangeHeader,
            $head,
            $now,
        ): ?PreparedDownload {
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
                $log->attempt_expires_at->lte($now) => 'attempt_expired',
                $grant->revoked_at !== null => 'grant_revoked',
                $grant->expires_at->lte($now) => 'grant_expired',
                ! in_array($order->status->value, ['paid', 'partially_refunded'], true) => 'order_not_deliverable',
                default => null,
            };
            if ($reason !== null) {
                $this->denyIfStarted($log, $reason, $now);

                return null;
            }

            $file = ProductFile::query()->whereKey($grant->product_file_id)->first();
            if ($file === null || ! $file->is_active) {
                $this->denyIfStarted($log, 'product_file_unavailable', $now);

                return null;
            }

            try {
                $this->files->assertResolvable($file);
                $size = $this->files->size($file);
            } catch (Throwable) {
                $this->denyIfStarted($log, 'storage_failure', $now);

                return null;
            }

            $range = $this->ranges->parse($rangeHeader, $size);
            $start = $range?->start ?? 0;
            $end = $range?->end ?? $size - 1;
            $stream = null;
            $xAccelPath = null;

            if (! $head) {
                try {
                    if (DeliveryConfig::fileDriver() === 'x_accel') {
                        if ($size < DeliveryConfig::xAccelMinBytes()) {
                            throw new DownloadAccessDenied;
                        }
                        $xAccelPath = $this->files->xAccelPath($file);
                    } else {
                        $stream = $this->files->readStream($file);
                    }
                } catch (Throwable) {
                    $this->denyIfStarted($log, 'storage_failure', $now);

                    return null;
                }

                $this->completeIfStarted($log, $now);
            }

            return new PreparedDownload(
                stream: $stream,
                xAccelPath: $xAccelPath,
                fileName: (string) $file->original_name,
                mimeType: (string) $file->mime_type,
                checksum: (string) $file->checksum_sha256,
                size: $size,
                start: $start,
                end: $end,
                partial: $range !== null,
                head: $head,
                chunkBytes: DeliveryConfig::streamChunkBytes(),
            );
        });

        if ($prepared === null) {
            throw new DownloadAccessDenied;
        }

        return $prepared;
    }

    private function assertCredentialShape(string $publicId, string $rawToken): void
    {
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
