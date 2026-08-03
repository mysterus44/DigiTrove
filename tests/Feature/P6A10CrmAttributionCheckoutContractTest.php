<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
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
    $lastLabelLength = $length - 193;

    return str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', $lastLabelLength);
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

    try {
        app(OrderService::class)->checkout(
            $visitor,
            $cart->public_id,
            'XOF',
            str_repeat('b', 40),
            $email,
        );
        throw new RuntimeException('Expected the 255-character email to be refused.');
    } catch (CheckoutException $exception) {
        expect($exception->reason)->toBe(CheckoutRefusalReason::InvalidEmail);
    }

    expect(strlen($email))->toBe(255)
        ->and(DB::table('orders')->count())->toBe(0)
        ->and($cart->fresh()->status->value)->toBe('active');
});
