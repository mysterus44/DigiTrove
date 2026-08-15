<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\View\View;

class CatalogController extends Controller
{
    public function __invoke(): View
    {
        $products = Product::query()
            ->published()
            ->with(['activeXofPrice', 'categories'])
            ->orderByDesc('published_at')
            ->orderBy('id')
            ->limit(5)
            ->get();

        $categories = Category::query()
            ->whereHas('products', fn ($query) => $query->published())
            ->withCount(['products' => fn ($query) => $query->published()])
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        /** @var array{reviews:list<array<string,string>>} $legacy */
        $legacy = require resource_path('storefront/legacy-catalog.php');

        return view('storefront.home', [
            'products' => $products,
            'categories' => $categories,
            'reviews' => $legacy['reviews'],
        ]);
    }
}
