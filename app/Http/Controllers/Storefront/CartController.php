<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Models\Product;
use App\Services\Cart\GuestCartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The guest cart surface: three routes, no checkout, no coupon, no account.
 *
 * Products are resolved by slug rather than by route-model binding, because the two
 * directions need different authorities. Adding demands the PUBLIC purchasability rule —
 * the same `Product::published()` the catalogue uses, so a forged slug cannot smuggle a
 * draft, an archive, a future publication, a soft-deleted row or a product whose XOF
 * price was withdrawn into a cart. Removing must work on ANY product the cart already
 * holds, including one that has since been taken down; otherwise a visitor would be stuck
 * with a line they can see and cannot delete.
 */
final class CartController
{
    public function __construct(private readonly GuestCartService $cart) {}

    /** Read only. Nothing here writes, not even for an expired cart or a withdrawn product. */
    public function show(): View
    {
        return view('storefront.cart.show', $this->cart->view());
    }

    public function store(string $slug): RedirectResponse
    {
        $product = Product::query()->published()->where('slug', $slug)->first();

        if ($product === null) {
            throw new NotFoundHttpException;
        }

        $this->cart->add($product);

        return redirect()->route('cart.show');
    }

    public function destroy(string $slug): RedirectResponse
    {
        // Soft-deleted included on purpose: a product removed from the catalogue after it
        // was added must still be removable from the cart that holds it.
        $product = Product::withTrashed()->where('slug', $slug)->first();

        if ($product !== null) {
            $this->cart->remove($product);
        }

        // Uniform outcome whether the line existed, belonged to someone else, or never
        // existed at all: a distinct response would answer a question about other
        // people's carts.
        return redirect()->route('cart.show');
    }
}
