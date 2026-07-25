<?php

namespace Tests\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class P5A2RollupFixture
{
    public static function beginRepeatableRead(): void
    {
        DB::connection('pgsql_migration')->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }

    /**
     * @return array{order: Order, item: OrderItem, payment: Payment}
     */
    public static function paidOrder(
        CarbonImmutable $paidAt,
        Product $product,
        string $currency = 'XOF',
        int $quantity = 1,
        int $unitPriceMinor = 10_000,
        int $taxMinor = 0,
        ?User $user = null,
    ): array {
        $subtotal = $unitPriceMinor * $quantity;
        $order = Order::factory()->paid()->create([
            'user_id' => $user?->getKey(),
            'placed_at' => $paidAt->subMinutes(10),
            'expires_at' => $paidAt->addMinutes(20),
            'paid_at' => $paidAt,
            'subtotal_minor' => $subtotal,
            'discount_minor' => 0,
            'tax_minor' => $taxMinor,
            'total_minor' => $subtotal + $taxMinor,
            'currency' => $currency,
        ]);
        $item = OrderItem::factory()->forOrder($order)->forProduct($product)->create([
            'unit_price_minor' => $unitPriceMinor,
            'quantity' => $quantity,
            'line_subtotal_minor' => $subtotal,
            'line_discount_minor' => 0,
            'line_total_minor' => $subtotal,
            'currency' => $currency,
        ]);
        $payment = Payment::factory()->forOrder($order)->succeeded()->create([
            'initiated_at' => $paidAt->subMinutes(5),
            'succeeded_at' => $paidAt,
        ]);

        return compact('order', 'item', 'payment');
    }

    public static function productView(
        CarbonImmutable $occurredAt,
        int $productId,
        string $visitorId,
        string $sessionId,
    ): void {
        DB::connection('pgsql_migration')->table('events')->insert([
            'public_id' => (string) Str::uuid(),
            'occurred_at' => $occurredAt,
            'visitor_id' => $visitorId,
            'user_id' => null,
            'session_id' => $sessionId,
            'event_name' => 'product_view',
            'entity_type' => 'product',
            'entity_id' => $productId,
            'properties' => json_encode(['placement' => 'catalog'], JSON_THROW_ON_ERROR),
            'page_path' => '/products/example',
            'created_at' => $occurredAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rollup(CarbonImmutable $day): array
    {
        $connection = DB::connection('pgsql_migration');
        $connection->statement('SET ROLE digitrove_analytics_rollup_executor');

        try {
            $result = $connection->selectOne(
                'SELECT public.refresh_authoritative_daily_analytics(?::date)::text AS result',
                [$day->toDateString()],
            );
        } finally {
            $connection->statement('RESET ROLE');
        }

        return json_decode($result->result, true, flags: JSON_THROW_ON_ERROR);
    }
}
