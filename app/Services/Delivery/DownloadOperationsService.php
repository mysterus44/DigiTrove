<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Enums\GrantRevocationReason;
use App\Models\DownloadGrant;
use App\Models\DownloadLog;
use App\Models\Order;
use App\Support\DeliveryConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DownloadOperationsService
{
    /**
     * Reconcile stale attempts in bounded transactions. A consumed quota unit is
     * historical and is never returned.
     */
    public function reconcileStarted(?CarbonImmutable $at = null): int
    {
        $now = $at ?? CarbonImmutable::now();
        $ids = DownloadLog::query()
            ->where('status', 'started')
            ->where('created_at', '<=', $now->subMinutes(DeliveryConfig::startedReconcileMinutes()))
            ->orderBy('id')
            ->limit(DeliveryConfig::operationsBatchSize())
            ->pluck('id');

        $changed = 0;
        foreach ($ids as $id) {
            $changed += DB::transaction(function () use ($id, $now): int {
                $candidate = DB::table('download_logs as dl')
                    ->join('download_grants as dg', 'dg.id', '=', 'dl.download_grant_id')
                    ->join('order_items as oi', 'oi.id', '=', 'dg.order_item_id')
                    ->where('dl.id', $id)
                    ->first(['dg.id AS grant_id', 'oi.order_id']);
                if ($candidate === null) {
                    return 0;
                }

                Order::query()->whereKey($candidate->order_id)->lockForUpdate()->first();
                DownloadGrant::query()->whereKey($candidate->grant_id)->lockForUpdate()->first();
                $log = DownloadLog::query()->whereKey($id)->lockForUpdate()->first();

                if ($log === null || $log->status !== 'started'
                    || $log->created_at->gt($now->subMinutes(DeliveryConfig::startedReconcileMinutes()))) {
                    return 0;
                }

                $log->forceFill([
                    'status' => 'denied',
                    'denial_reason_code' => 'delivery_interrupted',
                    'terminal_at' => $now,
                ])->save();

                return 1;
            });
        }

        return $changed;
    }

    /**
     * @return list<array{grant_id:int, distinct_ip_count:int}>
     */
    public function detectAbuse(?CarbonImmutable $at = null): array
    {
        $now = $at ?? CarbonImmutable::now();
        $rows = DB::table('download_logs')
            ->selectRaw('download_grant_id, COUNT(DISTINCT ip_hash) AS distinct_ip_count')
            ->whereNotNull('ip_hash')
            ->where('created_at', '>=', $now->subHours(DeliveryConfig::abuseWindowHours()))
            ->groupBy('download_grant_id')
            ->havingRaw('COUNT(DISTINCT ip_hash) > ?', [DeliveryConfig::abuseDistinctIpThreshold()])
            ->orderBy('download_grant_id')
            ->limit(DeliveryConfig::operationsBatchSize())
            ->get();

        return $rows->map(static fn (object $row): array => [
            'grant_id' => (int) $row->download_grant_id,
            'distinct_ip_count' => (int) $row->distinct_ip_count,
        ])->all();
    }

    public function revokeGrant(int $grantId, string $reasonCode, ?CarbonImmutable $at = null): bool
    {
        if ($reasonCode !== GrantRevocationReason::ManualSecurityReissue->value) {
            return false;
        }

        $now = $at ?? CarbonImmutable::now();
        $candidate = DB::table('download_grants as dg')
            ->join('order_items as oi', 'oi.id', '=', 'dg.order_item_id')
            ->where('dg.id', $grantId)
            ->first(['oi.order_id']);
        if ($candidate === null) {
            return false;
        }

        return DB::transaction(function () use ($grantId, $reasonCode, $now, $candidate): bool {
            Order::query()->whereKey($candidate->order_id)->lockForUpdate()->first();
            $grant = DownloadGrant::query()->whereKey($grantId)->lockForUpdate()->first();
            if ($grant === null) {
                return false;
            }

            if ($grant->revoked_at !== null) {
                return $grant->revoked_reason_code === $reasonCode;
            }

            $grant->forceFill([
                'revoked_at' => $now,
                'revoked_reason_code' => $reasonCode,
            ])->save();

            return true;
        });
    }

    public function purgeLogs(bool $dryRun = false, ?CarbonImmutable $at = null): int
    {
        $now = $at ?? CarbonImmutable::now();
        $ids = DownloadLog::query()
            ->whereIn('status', ['completed', 'denied'])
            ->where('retention_until', '<=', $now)
            ->orderBy('id')
            ->limit(DeliveryConfig::operationsBatchSize())
            ->pluck('id');

        if ($dryRun || $ids->isEmpty()) {
            return $ids->count();
        }

        return DownloadLog::query()->whereIn('id', $ids)->delete();
    }

    /**
     * @return array<string, int>
     */
    public function metrics(): array
    {
        $statusCounts = DownloadLog::query()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'logs_started' => (int) ($statusCounts['started'] ?? 0),
            'logs_completed' => (int) ($statusCounts['completed'] ?? 0),
            'logs_denied' => (int) ($statusCounts['denied'] ?? 0),
            'active_grants' => DownloadGrant::query()->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
            'revoked_grants' => DownloadGrant::query()->whereNotNull('revoked_at')->count(),
        ];
    }
}
