<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Enums\GrantRevocationReason;
use App\Enums\OrderStatus;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductFile;
use App\Support\DeliveryConfig;
use App\Support\IssuedGrant;
use App\Support\IssuedGrantBatch;
use App\Support\PostgresConstraintViolation;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Issues download grants for a paid order (P4-C1, D-035). It NEVER sends mail.
 *
 * Tokens are CSPRNG values that live only in the returned in-memory batch; only
 * their SHA-256 digest is persisted. The purchase snapshot is the sole authority
 * for bundle contents — the live bundle pivot is never read. A re-run reissues
 * exactly the historical `(order_item_id, product_file_id)` pairs (no implicit
 * upgrade), revoking the stale active grants first.
 */
final class GrantIssuanceService
{
    private const TOKEN_BYTES = 32;

    private const GRANT_WRITE_ATTEMPTS = 5;

    public function __construct(private readonly ?Closure $tokenGenerator = null) {}

    public function issueForOrder(int $orderId, ?CarbonImmutable $at = null): IssuedGrantBatch
    {
        $now = $at ?? CarbonImmutable::now();

        return DB::transaction(function () use ($orderId, $now): IssuedGrantBatch {
            // Global lock order: Order first.
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if ($order === null) {
                throw new RuntimeException('The order could not be resolved for delivery.');
            }

            if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true)) {
                throw new RuntimeException('The order is not deliverable.');
            }

            // Keep the global lock order stable across issuance and refund:
            // Order -> OrderItems -> bundle snapshots -> ProductFiles -> grants.
            $items = OrderItem::query()
                ->where('order_id', $order->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $itemIds = $items->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $snapshots = DB::table('order_item_bundle_components')
                ->whereIn('order_item_id', $itemIds)
                ->orderBy('order_item_id')
                ->orderBy('child_product_id')
                ->lockForUpdate()
                ->get();

            // A non-locking read is safe after the Order lock: every application
            // mutation of grants in this gate takes that same Order lock first.
            $historicalFileIds = DownloadGrant::query()
                ->whereIn('order_item_id', $itemIds)
                ->distinct()
                ->pluck('product_file_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $productIds = $historicalFileIds === []
                ? $this->purchasedProductIds($items, $snapshots)
                : [];

            $filesQuery = ProductFile::query()->orderBy('id')->lockForUpdate();
            if ($historicalFileIds !== []) {
                $filesQuery->whereIn('id', $historicalFileIds);
            } else {
                $filesQuery->whereIn('product_id', $productIds)->where('is_active', true);
            }
            $files = $filesQuery->get();

            $existing = DownloadGrant::query()
                ->whereIn('order_item_id', $itemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $pairs = $existing->isNotEmpty()
                ? $this->reissuePairs($existing, $files, $now)
                : $this->firstIssuePairs($items, $snapshots, $files);

            $grants = [];
            foreach ($pairs as $pair) {
                $grants[] = $this->createGrant($order, $pair['order_item_id'], $pair['product_file_id'], $pair['file_name'], $now);
            }

            $this->forceDeferredConstraints();

            return new IssuedGrantBatch(
                orderId: $order->id,
                customerEmail: (string) $order->customer_email,
                grants: $grants,
            );
        });
    }

    /**
     * Revoke the grants of a batch that are still active (used on delivery
     * failure). Never deletes; never touches downloads_count.
     */
    public function revokeIssuedBatch(IssuedGrantBatch $batch, GrantRevocationReason $reason, ?CarbonImmutable $at = null): void
    {
        $now = $at ?? CarbonImmutable::now();
        $ids = $batch->grantIds();

        if ($ids === []) {
            return;
        }

        DB::transaction(function () use ($batch, $ids, $reason, $now): void {
            $order = Order::query()->whereKey($batch->orderId)->lockForUpdate()->first();
            if ($order === null) {
                throw new RuntimeException('The delivery order could not be resolved.');
            }

            $orderItemIds = OrderItem::query()
                ->where('order_id', $order->id)
                ->select('id');

            $grants = DownloadGrant::query()
                ->whereIn('id', $ids)
                ->whereIn('order_item_id', $orderItemIds)
                ->whereNull('revoked_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($grants as $grant) {
                $grant->forceFill([
                    'revoked_at' => $now,
                    'revoked_reason_code' => $reason->value,
                ])->save();
            }
        });
    }

    /**
     * Reissue path: the authoritative pairs are the historical distinct
     * `(order_item_id, product_file_id)` couples. Active stale grants are
     * revoked (uncertain reissue) before fresh grants are created.
     *
     * @param  Collection<int, DownloadGrant>  $existing
     * @param  Collection<int, ProductFile>  $files
     * @return list<array{order_item_id:int, product_file_id:int, file_name:string}>
     */
    private function reissuePairs($existing, $files, CarbonImmutable $now): array
    {
        foreach ($existing as $grant) {
            if ($grant->revoked_at === null) {
                $grant->forceFill([
                    'revoked_at' => $now,
                    'revoked_reason_code' => GrantRevocationReason::DeliveryUncertainReissue->value,
                ])->save();
            }
        }

        $filesById = $files->keyBy('id');
        $seen = [];
        $pairs = [];
        foreach ($existing as $grant) {
            $key = $grant->order_item_id.':'.$grant->product_file_id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $file = $filesById->get($grant->product_file_id);
            if (! $file instanceof ProductFile) {
                throw new RuntimeException('A historical delivery file could not be resolved.');
            }

            $pairs[] = [
                'order_item_id' => (int) $grant->order_item_id,
                'product_file_id' => (int) $grant->product_file_id,
                'file_name' => (string) $file->original_name,
            ];
        }

        return $pairs;
    }

    /**
     * First issue: compute deliverable pairs from the purchase. Bundle contents
     * come EXCLUSIVELY from order_item_bundle_components, never the live pivot.
     *
     * @param  Collection<int, OrderItem>  $items
     * @param  Collection<int, object>  $snapshots
     * @param  Collection<int, ProductFile>  $files
     * @return list<array{order_item_id:int, product_file_id:int, file_name:string}>
     */
    private function firstIssuePairs($items, $snapshots, $files): array
    {
        $childrenByItem = $snapshots->groupBy('order_item_id');
        $filesByProduct = $files->groupBy('product_id');
        $pairs = [];

        foreach ($items as $item) {
            $productIds = [$item->product_id];

            if ($item->product_type_snapshot === 'bundle') {
                $childIds = $childrenByItem->get($item->id, collect())
                    ->pluck('child_product_id')
                    ->all();
                $productIds = array_merge($productIds, $childIds);
            }

            $productIds = array_values(array_filter(array_unique($productIds), static fn ($id): bool => $id !== null));

            foreach ($productIds as $productId) {
                foreach ($filesByProduct->get($productId, collect()) as $file) {
                    $pairs[] = [
                        'order_item_id' => (int) $item->id,
                        'product_file_id' => (int) $file->id,
                        'file_name' => (string) $file->original_name,
                    ];
                }
            }
        }

        return $pairs;
    }

    private function createGrant(Order $order, int $orderItemId, int $productFileId, string $fileName, CarbonImmutable $now): IssuedGrant
    {
        $expiresAt = $now->addMinutes(DeliveryConfig::grantTtlMinutes());
        $maxDownloads = DeliveryConfig::grantMaxDownloads();

        for ($attempt = 1; $attempt <= self::GRANT_WRITE_ATTEMPTS; $attempt++) {
            $rawToken = $this->generateToken();
            $publicId = (string) Str::uuid();

            try {
                // Nested transaction = SAVEPOINT: a token collision (astronomically
                // unlikely) does not abort the whole issuance.
                $grant = DB::transaction(fn (): DownloadGrant => DownloadGrant::query()->create([
                    'public_id' => $publicId,
                    'order_item_id' => $orderItemId,
                    'product_file_id' => $productFileId,
                    'user_id' => $order->user_id,
                    'token_hash' => hash('sha256', $rawToken),
                    'expires_at' => $expiresAt,
                    'max_downloads' => $maxDownloads,
                    'downloads_count' => 0,
                ]));
            } catch (Throwable $exception) {
                if (PostgresConstraintViolation::isUniqueViolationOf($exception, 'download_grants_token_hash_unique')) {
                    if ($attempt < self::GRANT_WRITE_ATTEMPTS) {
                        continue;
                    }

                    throw new RuntimeException('A secure download credential could not be issued.');
                }

                if (PostgresConstraintViolation::isUniqueViolationOf($exception, 'download_grants_active_pair_unique')) {
                    $this->revokeConflictingActivePair($orderItemId, $productFileId, $now);

                    if ($attempt < self::GRANT_WRITE_ATTEMPTS) {
                        continue;
                    }

                    throw new RuntimeException('A secure download grant could not be issued.');
                }

                throw $exception;
            }

            return new IssuedGrant(
                grantId: $grant->id,
                grantPublicId: $publicId,
                orderItemId: $orderItemId,
                productFileId: $productFileId,
                rawToken: $rawToken,
                fileName: $fileName,
            );
        }

        throw new RuntimeException('A secure download grant could not be issued.');
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @param  Collection<int, object>  $snapshots
     * @return list<int>
     */
    private function purchasedProductIds($items, $snapshots): array
    {
        $ids = $items->pluck('product_id')
            ->merge($snapshots->pluck('child_product_id'))
            ->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        return $ids->all();
    }

    private function revokeConflictingActivePair(int $orderItemId, int $productFileId, CarbonImmutable $now): void
    {
        $grant = DownloadGrant::query()
            ->where('order_item_id', $orderItemId)
            ->where('product_file_id', $productFileId)
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->first();

        if ($grant === null) {
            return;
        }

        $grant->forceFill([
            'revoked_at' => $now,
            'revoked_reason_code' => GrantRevocationReason::DeliveryUncertainReissue->value,
        ])->save();
    }

    private function forceDeferredConstraints(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    /** 256-bit CSPRNG token, base64url without padding. Raw value never stored. */
    private function generateToken(): string
    {
        $token = $this->tokenGenerator === null
            ? rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=')
            : ($this->tokenGenerator)();

        if (! is_string($token) || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) !== 1) {
            throw new RuntimeException('The secure token generator returned an invalid value.');
        }

        return $token;
    }
}
