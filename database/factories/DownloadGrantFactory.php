<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds only issuable grants.
 *
 * The default state creates a real deliverable purchase (paid order + succeeded
 * payment + direct order_item + active product file) so the row satisfies G3.
 * The factory NEVER persists a raw token: it derives the digest from a throwaway
 * random value and discards the token itself, exactly as the future issuing
 * service will. It never invents a payment for an unpaid order, never bypasses
 * the lineage, and never calls the network.
 *
 * @extends Factory<DownloadGrant>
 */
class DownloadGrantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'order_item_id' => fn (): int => $this->newDeliverableOrderItem()->getKey(),
            'product_file_id' => fn (array $attributes): int => $this->resolveOwnedProductFileId((int) $attributes['order_item_id']),
            'user_id' => fn (array $attributes): ?int => $this->resolveBuyerId((int) $attributes['order_item_id']),
            // Digest of a CSPRNG token; the raw token is generated, hashed and dropped.
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'expires_at' => now()->addHours(72),
            'max_downloads' => 5,
            'downloads_count' => 0,
            'revoked_at' => null,
            'revoked_reason_code' => null,
        ];
    }

    /**
     * Issue against an existing order_item. The caller owns the lineage.
     */
    public function forOrderItem(OrderItem $orderItem): static
    {
        return $this->state(fn (array $attributes) => [
            'order_item_id' => $orderItem->getKey(),
        ]);
    }

    public function forProductFile(ProductFile $productFile): static
    {
        return $this->state(fn (array $attributes) => [
            'product_file_id' => $productFile->getKey(),
        ]);
    }

    /**
     * An already-expired grant. created_at is pushed back so the
     * `expires_at > created_at` bound still holds.
     *
     * There is deliberately no revoked() state: G3 requires a grant to be born
     * active, so revocation is only ever reachable through an explicit UPDATE.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * A deliverable direct purchase: paid order, succeeded payment, active file.
     */
    private function newDeliverableOrderItem(): OrderItem
    {
        return DB::transaction(function (): OrderItem {
            $product = Product::factory()->create();
            ProductFile::factory()->create(['product_id' => $product->getKey()]);

            $order = Order::factory()->paid()->create();
            $item = OrderItem::factory()->forOrder($order)->forProduct($product)->create();
            Payment::factory()->forOrder($order)->succeeded()->create();

            return $item;
        });
    }

    private function resolveOwnedProductFileId(int $orderItemId): int
    {
        $orderItem = OrderItem::query()->findOrFail($orderItemId);

        $fileId = DB::table('product_files')
            ->where('product_id', $orderItem->product_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        if ($fileId === null) {
            throw new \RuntimeException(
                'DownloadGrantFactory needs an active product_file on the purchased product; '
                .'create one first or pass forProductFile().',
            );
        }

        return (int) $fileId;
    }

    private function resolveBuyerId(int $orderItemId): ?int
    {
        $userId = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.id', $orderItemId)
            ->value('orders.user_id');

        return $userId === null ? null : (int) $userId;
    }

    /**
     * Guard rail for readers: a grant is only issuable on a deliverable order.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (DownloadGrant $grant): void {
            $status = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.id', $grant->order_item_id)
                ->value('orders.status');

            if ($status !== null && ! in_array($status, [OrderStatus::Paid->value, OrderStatus::PartiallyRefunded->value], true)) {
                throw new \RuntimeException(
                    "DownloadGrantFactory refuses to build a grant for a non-deliverable order (status: {$status}).",
                );
            }
        });
    }
}
