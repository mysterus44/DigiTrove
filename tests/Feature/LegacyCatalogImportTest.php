<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

it('imports the audited SITE-00 catalog as drafts with integer XOF prices', function () {
    expect(Artisan::call('catalog:import-legacy'))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('categories_created=4')
        ->toContain('products_created=5')
        ->toContain('prices_created=5')
        ->toContain('reviews_ignored=3')
        ->toContain('reviews schema is not migrated')
        ->toContain('publication=none');

    expect(Product::query()->count())->toBe(5)
        ->and(Product::query()->where('status', ProductStatus::Draft)->count())->toBe(5)
        ->and(Product::query()->whereNotNull('published_at')->count())->toBe(0)
        ->and(DB::table('categories')->count())->toBe(4)
        ->and(DB::table('product_prices')->count())->toBe(5)
        ->and(DB::table('product_category')->count())->toBe(5)
        ->and(DB::table('product_prices')->where('currency', 'XOF')->where('is_active', true)->count())->toBe(5)
        ->and(Schema::hasTable('reviews'))->toBeFalse();

    $pack = Product::query()->where('slug', 'pack-livres')->with(['prices', 'categories'])->sole();

    expect($pack->prices->sole()->price_minor)->toBe(3_500)
        ->and($pack->prices->sole()->compare_at_price_minor)->toBe(7_700)
        ->and($pack->categories->sole()->slug)->toBe('ebooks')
        ->and($pack->cover_image_path)->toBe('images/digitrove/products/pack-livres.png');
});

it('is idempotent and reports every existing row as ignored on replay', function () {
    Artisan::call('catalog:import-legacy');
    $before = [
        'products' => Product::query()->count(),
        'categories' => DB::table('categories')->count(),
        'prices' => DB::table('product_prices')->count(),
        'pivots' => DB::table('product_category')->count(),
    ];

    expect(Artisan::call('catalog:import-legacy'))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('categories_created=0')
        ->toContain('categories_ignored=4')
        ->toContain('products_created=0')
        ->toContain('products_ignored=5')
        ->toContain('prices_created=0')
        ->and([
            'products' => Product::query()->count(),
            'categories' => DB::table('categories')->count(),
            'prices' => DB::table('product_prices')->count(),
            'pivots' => DB::table('product_category')->count(),
        ])->toBe($before);
});
