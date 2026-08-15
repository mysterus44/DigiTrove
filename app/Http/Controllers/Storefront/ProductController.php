<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;

class ProductController extends Controller
{
    public function index(): View
    {
        /** @var LengthAwarePaginator<int, Product> $products */
        $products = Product::query()
            ->published()
            ->with(['activeXofPrice', 'categories'])
            ->orderByDesc('published_at')
            ->orderBy('id')
            ->paginate(12);

        return view('storefront.products.index', compact('products'));
    }

    public function show(Product $product): View
    {
        $published = Product::query()
            ->published()
            ->with(['activeXofPrice', 'categories'])
            ->whereKey($product->getKey())
            ->firstOrFail();

        return view('storefront.products.show', ['product' => $published]);
    }
}
