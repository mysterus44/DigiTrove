<?php

declare(strict_types=1);

use App\Enums\CartStatus;
use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Visitor;
use App\Services\Checkout\CheckoutException;
use App\Services\Checkout\CheckoutRefusalReason;
use App\Services\Checkout\OrderService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

function p6a10CheckoutEmail(int $length): string
{
    $domainLength = $length - 65;
    $labels = [];

    while ($domainLength > 63) {
        $labels[] = str_repeat(chr(98 + count($labels)), 63);
        $domainLength -= 64;
    }

    $labels[] = str_repeat(chr(98 + count($labels)), $domainLength);

    return str_repeat('a', 64).'@'.implode('.', $labels);
}

/** @return array{Visitor, Cart} */
function p6a10CheckoutFixture(): array
{
    $visitor = Visitor::factory()->create();
    $product = Product::factory()->create([
        'status' => ProductStatus::Published,
        'published_at' => now()->subDay(),
    ]);
    ProductPrice::factory()->create([
        'product_id' => $product->id,
        'currency' => 'XOF',
        'price_minor' => 5_000,
        'compare_at_price_minor' => null,
        'is_active' => true,
    ]);
    $cart = Cart::factory()->create(['visitor_id' => $visitor->id, 'user_id' => null]);
    CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);

    return [$visitor, $cart->fresh()];
}

function p6a10HistoricalCheckoutOrder(Visitor $visitor, Cart $cart, string $email, string $idempotencyKey): Order
{
    return DB::transaction(function () use ($visitor, $cart, $email, $idempotencyKey): Order {
        $productId = (int) $cart->items()->sole()->product_id;
        $order = Order::factory()->pending()->create([
            'cart_id' => $cart->id,
            'visitor_id' => $visitor->id,
            'user_id' => null,
            'checkout_idempotency_hash' => hash('sha256', $idempotencyKey),
            'customer_email' => $email,
            'subtotal_minor' => 5_000,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 5_000,
            'currency' => 'XOF',
        ]);

        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'product_id' => $productId,
            'purchased_product_id' => $productId,
            'product_name_snapshot' => 'Historical replay fixture',
            'product_slug_snapshot' => 'historical-replay-fixture',
            'product_type_snapshot' => 'ebook',
            'unit_price_minor' => 5_000,
            'quantity' => 1,
            'line_subtotal_minor' => 5_000,
            'line_discount_minor' => 0,
            'line_total_minor' => 5_000,
            'currency' => 'XOF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cart->forceFill(['status' => CartStatus::Converted])->save();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        return $order;
    });
}

function p6a10ExpectCheckoutRefusal(Closure $callback, CheckoutRefusalReason $reason): void
{
    $exception = null;

    try {
        $callback();
    } catch (CheckoutException $checkoutException) {
        $exception = $checkoutException;
    }

    expect($exception)->not->toBeNull()
        ->and($exception->reason)->toBe($reason);
}

it('accepts an exact 254 character checkout email without truncation', function () {
    [$visitor, $cart] = p6a10CheckoutFixture();
    $email = p6a10CheckoutEmail(254);

    $order = app(OrderService::class)->checkout(
        $visitor,
        $cart->public_id,
        'XOF',
        str_repeat('a', 40),
        $email,
    );

    expect(strlen($email))->toBe(254)
        ->and($order->customer_email)->toBe($email)
        ->and(strlen($order->customer_email))->toBe(254);
});

it('refuses an exact 255 character checkout email before creating an order', function () {
    [$visitor, $cart] = p6a10CheckoutFixture();
    $email = p6a10CheckoutEmail(255);

    p6a10ExpectCheckoutRefusal(
        fn () => app(OrderService::class)->checkout(
            $visitor,
            $cart->public_id,
            'XOF',
            str_repeat('b', 40),
            $email,
        ),
        CheckoutRefusalReason::InvalidEmail,
    );

    expect(strlen($email))->toBe(255)
        ->and(DB::table('orders')->where('cart_id', $cart->id)->doesntExist())->toBeTrue()
        ->and($cart->fresh()->status->value)->toBe('active');
});

it('replays an exact historical extended email without creating or mutating commerce', function (int $length) {
    [$visitor, $cart] = p6a10CheckoutFixture();
    $email = p6a10CheckoutEmail($length);
    $idempotencyKey = str_repeat('h', 40);
    $historical = p6a10HistoricalCheckoutOrder($visitor, $cart, $email, $idempotencyKey);
    $cartBeforeReplay = $cart->fresh();
    $cartUpdatedAt = $cartBeforeReplay->getRawOriginal('updated_at');
    $orderItemCount = DB::table('order_items')->where('order_id', $historical->id)->count();

    $replayed = app(OrderService::class)->checkout(
        $visitor,
        $cart->public_id,
        'XOF',
        $idempotencyKey,
        $email,
    );

    expect(strlen($email))->toBe($length)
        ->and($replayed->is($historical))->toBeTrue()
        ->and($replayed->customer_email)->toBe($email)
        ->and(strlen($replayed->customer_email))->toBe($length)
        ->and(Order::query()->where('cart_id', $cart->id)->count())->toBe(1)
        ->and(DB::table('order_items')->where('order_id', $historical->id)->count())->toBe($orderItemCount)
        ->and($cart->fresh()->status)->toBe(CartStatus::Converted)
        ->and($cart->fresh()->getRawOriginal('updated_at'))->toBe($cartUpdatedAt)
        ->and(collect(DB::select('SELECT * FROM public.list_due_crm_order_attributions(100)'))
            ->pluck('order_id')->map(fn ($orderId): int => (int) $orderId)->all())
        ->not->toContain($historical->id);

    p6a10ExpectCheckoutRefusal(
        fn () => app(OrderService::class)->checkout(
            $visitor,
            $cart->public_id,
            'XOF',
            $idempotencyKey,
            'different@example.test',
        ),
        CheckoutRefusalReason::IdempotencyConflict,
    );

    expect(Order::query()->where('cart_id', $cart->id)->count())->toBe(1)
        ->and($historical->fresh()->customer_email)->toBe($email);
})->with([
    '255 characters' => 255,
    'historical maximum 320 characters' => 320,
]);

it('refuses an email above the historical envelope before cart lookup', function () {
    $visitor = Visitor::factory()->create();
    $email = p6a10CheckoutEmail(320).'x';
    $orderCountBefore = Order::query()->count();

    p6a10ExpectCheckoutRefusal(
        fn () => app(OrderService::class)->checkout(
            $visitor,
            '00000000-0000-0000-0000-000000000000',
            'XOF',
            str_repeat('z', 40),
            $email,
        ),
        CheckoutRefusalReason::InvalidEmail,
    );

    expect(strlen($email))->toBe(321)
        ->and(Order::query()->count())->toBe($orderCountBefore);
});
