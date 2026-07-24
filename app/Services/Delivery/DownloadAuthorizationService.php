<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Models\DownloadGrant;
use App\Models\DownloadLog;
use App\Models\Order;
use App\Models\ProductFile;
use App\Support\AuthorizedDownloadAttempt;
use App\Support\DeliveryConfig;
use App\Support\DownloadAccessDenied;
use App\Support\DownloadRequestContext;
use App\Support\PostgresConstraintViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DownloadAuthorizationService
{
    private const TOKEN_BYTES = 32;

    private const TOKEN_ATTEMPTS = 3;

    public function __construct(private readonly PrivateFileLocator $files) {}

    public function authorize(
        string $grantPublicId,
        string $rawGrantToken,
        DownloadRequestContext $context,
        ?CarbonImmutable $at = null,
    ): AuthorizedDownloadAttempt {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Download authorization cannot join an ambient transaction.');
        }

        $this->assertCredentialShape($grantPublicId, $rawGrantToken);
        $now = $at ?? CarbonImmutable::now();
        $grantDigest = hash('sha256', $rawGrantToken);

        $candidate = DB::table('download_grants as dg')
            ->join('order_items as oi', 'oi.id', '=', 'dg.order_item_id')
            ->where('dg.public_id', $grantPublicId)
            ->first(['dg.id', 'dg.token_hash', 'oi.order_id']);

        if ($candidate === null || ! hash_equals((string) $candidate->token_hash, $grantDigest)) {
            throw new DownloadAccessDenied;
        }

        for ($attempt = 1; $attempt <= self::TOKEN_ATTEMPTS; $attempt++) {
            $rawAttemptToken = $this->newToken();
            $attemptDigest = hash('sha256', $rawAttemptToken);

            try {
                $authorized = DB::transaction(function () use (
                    $candidate,
                    $grantPublicId,
                    $grantDigest,
                    $rawAttemptToken,
                    $attemptDigest,
                    $context,
                    $now,
                ): ?AuthorizedDownloadAttempt {
                    $order = Order::query()->whereKey($candidate->order_id)->lockForUpdate()->first();
                    $grant = DownloadGrant::query()->whereKey($candidate->id)->lockForUpdate()->first();

                    if ($order === null || $grant === null || $grant->public_id !== $grantPublicId
                        || ! hash_equals((string) $grant->token_hash, $grantDigest)) {
                        return null;
                    }

                    $reason = $this->refusalReason($grant, (string) $order->status->value, $now);
                    if ($reason !== null) {
                        $this->recordDirectDenial($grant, $reason, $context, $now);

                        return null;
                    }

                    $existingAttempt = DownloadLog::query()
                        ->where('download_grant_id', $grant->id)
                        ->where('quota_consumed', true)
                        ->where('attempt_expires_at', '>', $now)
                        ->exists();
                    if ($existingAttempt) {
                        return null;
                    }

                    $file = ProductFile::query()->whereKey($grant->product_file_id)->first();
                    if ($file === null) {
                        $this->recordDirectDenial($grant, 'product_file_unavailable', $context, $now);

                        return null;
                    }

                    try {
                        $this->files->assertResolvable($file);
                    } catch (DownloadAccessDenied) {
                        $this->recordDirectDenial($grant, 'product_file_unavailable', $context, $now);

                        return null;
                    }

                    $expiresAt = $now->addSeconds(DeliveryConfig::attemptTtlSeconds());
                    DownloadLog::query()->create([
                        'public_id' => (string) Str::uuid(),
                        'download_grant_id' => $grant->id,
                        'status' => 'started',
                        'quota_consumed' => true,
                        'attempt_token_hash' => $attemptDigest,
                        'attempt_expires_at' => $expiresAt,
                        'denial_reason_code' => null,
                        'ip_hash' => $context->ipHash,
                        'ip_hash_key_version' => $context->ipHashKeyVersion,
                        'user_agent' => $context->userAgent,
                        'bytes_sent' => null,
                        'terminal_at' => null,
                        'retention_until' => $now->addDays(DeliveryConfig::logRetentionDays()),
                        'created_at' => $now,
                    ]);

                    return new AuthorizedDownloadAttempt($grantPublicId, $rawAttemptToken, $expiresAt);
                }, attempts: 1);
            } catch (Throwable $exception) {
                if ($attempt < self::TOKEN_ATTEMPTS
                    && PostgresConstraintViolation::isUniqueViolationOf($exception, 'download_logs_attempt_token_hash_unique')) {
                    continue;
                }

                throw new DownloadAccessDenied;
            }

            if ($authorized === null) {
                throw new DownloadAccessDenied;
            }

            return $authorized;
        }

        throw new DownloadAccessDenied;
    }

    private function assertCredentialShape(string $publicId, string $rawToken): void
    {
        // Compute one digest even for malformed credentials to reduce avoidable
        // timing differences at the public boundary.
        hash('sha256', $rawToken);

        if (! Str::isUuid($publicId) || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $rawToken) !== 1) {
            throw new DownloadAccessDenied;
        }
    }

    private function refusalReason(DownloadGrant $grant, string $orderStatus, CarbonImmutable $now): ?string
    {
        return match (true) {
            $grant->revoked_at !== null => 'grant_revoked',
            $grant->expires_at->lte($now) => 'grant_expired',
            $grant->downloads_count >= $grant->max_downloads => 'quota_exhausted',
            ! in_array($orderStatus, ['paid', 'partially_refunded'], true) => 'order_not_deliverable',
            default => null,
        };
    }

    private function recordDirectDenial(
        DownloadGrant $grant,
        string $reason,
        DownloadRequestContext $context,
        CarbonImmutable $now,
    ): void {
        DownloadLog::query()->create([
            'public_id' => (string) Str::uuid(),
            'download_grant_id' => $grant->id,
            'status' => 'denied',
            'quota_consumed' => false,
            'attempt_token_hash' => null,
            'attempt_expires_at' => null,
            'denial_reason_code' => $reason,
            'ip_hash' => $context->ipHash,
            'ip_hash_key_version' => $context->ipHashKeyVersion,
            'user_agent' => $context->userAgent,
            'bytes_sent' => null,
            'terminal_at' => $now,
            'retention_until' => $now->addDays(DeliveryConfig::logRetentionDays()),
            'created_at' => $now,
        ]);
    }

    private function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }
}
