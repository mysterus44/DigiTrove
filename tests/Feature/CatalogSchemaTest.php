<?php

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\ProductPrice;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function expectCatalogConstraintViolation(Closure $callback): void
{
    expect(fn () => DB::transaction($callback))->toThrow(QueryException::class);
}

it('has the six P2 catalog tables and expected columns', function () {
    foreach (['categories', 'products', 'product_prices', 'product_files', 'product_category', 'product_bundles'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Missing P2 table: {$table}");
    }

    expect(Schema::hasColumns('categories', [
        'id',
        'parent_id',
        'slug',
        'name',
        'position',
        'created_at',
        'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('products', [
            'id',
            'slug',
            'name',
            'type',
            'status',
            'short_description',
            'long_description',
            'cover_image_path',
            'meta_title',
            'meta_description',
            'sales_count',
            'rating_avg',
            'rating_count',
            'published_at',
            'deleted_at',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('product_prices', [
            'id',
            'product_id',
            'currency',
            'price_minor',
            'compare_at_price_minor',
            'is_active',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('product_files', [
            'id',
            'product_id',
            'storage_disk',
            'storage_path',
            'original_name',
            'mime_type',
            'size_bytes',
            'checksum_sha256',
            'version',
            'position',
            'is_active',
            'created_at',
        ]))->toBeTrue();

    expect(Schema::hasColumn('products', 'price_minor'))->toBeFalse()
        ->and(Schema::hasColumn('products', 'compare_at_price_minor'))->toBeFalse()
        ->and(Schema::hasColumn('products', 'currency'))->toBeFalse();
});

it('has explicit indexes for reverse catalog lookups', function () {
    $indexNames = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->whereIn('tablename', ['categories', 'product_category', 'product_bundles'])
        ->pluck('indexname');

    expect($indexNames)
        ->toContain('categories_parent_id_index')
        ->toContain('product_category_category_id_index')
        ->toContain('product_bundles_child_product_id_index');
});

it('does not create P3C, payment, delivery, analytics, or affiliation tables', function () {
    $forbiddenTables = [
        'payment_webhook_events',
        'refunds',
        'download_grants',
        'download_logs',
        'affiliate_profiles',
        'affiliate_links',
        'referrals',
        'affiliate_commissions',
        'affiliate_payouts',
        'events',
        'analytics_sessions',
        'campaigns',
        'daily_sales_stats',
        'daily_product_stats',
        'daily_funnel_stats',
    ];

    foreach ($forbiddenTables as $table) {
        expect(Schema::hasTable($table))->toBeFalse("Unexpected out-of-scope table exists: {$table}");
    }
});

it('enforces unique slugs for categories and products', function () {
    Category::factory()->create(['slug' => 'formations']);
    Product::factory()->create(['slug' => 'pack-premium']);

    expectCatalogConstraintViolation(fn () => Category::factory()->create(['slug' => 'formations']));
    expectCatalogConstraintViolation(fn () => Product::factory()->create(['slug' => 'pack-premium']));
});

it('rejects invalid product types and statuses', function () {
    expectCatalogConstraintViolation(fn () => DB::table('products')->insert([
        'slug' => 'invalid-type',
        'name' => 'Invalid type',
        'type' => 'service',
        'status' => ProductStatus::Draft->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    expectCatalogConstraintViolation(fn () => DB::table('products')->insert([
        'slug' => 'invalid-status',
        'name' => 'Invalid status',
        'type' => ProductType::Ebook->value,
        'status' => 'deleted',
        'created_at' => now(),
        'updated_at' => now(),
    ]));
});

it('enforces fixed product prices by uppercase three-letter currency without floating money columns', function () {
    $product = Product::factory()->create();

    ProductPrice::factory()->create([
        'product_id' => $product->id,
        'currency' => 'XOF',
        'price_minor' => 10000,
        'compare_at_price_minor' => 12000,
    ]);

    ProductPrice::factory()->create([
        'product_id' => $product->id,
        'currency' => 'USD',
        'price_minor' => 25,
        'compare_at_price_minor' => null,
    ]);

    expect($product->prices()->count())->toBe(2);

    expectCatalogConstraintViolation(fn () => ProductPrice::factory()->create([
        'product_id' => $product->id,
        'currency' => 'XOF',
    ]));
    expectCatalogConstraintViolation(fn () => ProductPrice::factory()->create([
        'product_id' => $product->id,
        'currency' => 'xof',
    ]));
    expectCatalogConstraintViolation(fn () => ProductPrice::factory()->create([
        'product_id' => $product->id,
        'currency' => 'XO',
    ]));
    expectCatalogConstraintViolation(fn () => ProductPrice::factory()->create([
        'product_id' => $product->id,
        'currency' => 'XOFF',
    ]));
    expectCatalogConstraintViolation(fn () => ProductPrice::factory()->create([
        'product_id' => $product->id,
        'currency' => 'EUR',
        'price_minor' => -1,
    ]));
    expectCatalogConstraintViolation(fn () => ProductPrice::factory()->create([
        'product_id' => $product->id,
        'currency' => 'GBP',
        'price_minor' => 10000,
        'compare_at_price_minor' => 9999,
    ]));

    $moneyColumns = DB::table('information_schema.columns')
        ->select('table_name', 'column_name', 'data_type')
        ->where('table_schema', 'public')
        ->whereIn('table_name', ['product_prices'])
        ->whereIn('column_name', ['price_minor', 'compare_at_price_minor'])
        ->get();

    expect($moneyColumns)->toHaveCount(2);

    foreach ($moneyColumns as $column) {
        expect($column->data_type)->toBe('bigint');
    }
});

it('maps category and product relations', function () {
    $parent = Category::factory()->create();
    $child = Category::factory()->create(['parent_id' => $parent->id]);
    $product = Product::factory()->create();

    $product->categories()->attach($child->id);

    expect($child->parent->is($parent))->toBeTrue()
        ->and($parent->children)->toHaveCount(1)
        ->and($product->categories->first()->is($child))->toBeTrue()
        ->and($child->products->first()->is($product))->toBeTrue();

    expectCatalogConstraintViolation(fn () => $product->categories()->attach($child->id));
    expectCatalogConstraintViolation(fn () => $parent->update(['parent_id' => $parent->id]));
});

it('maps product prices and private product files', function () {
    $product = Product::factory()->create();
    $price = ProductPrice::factory()->create(['product_id' => $product->id, 'currency' => 'XOF']);
    $file = ProductFile::factory()->create(['product_id' => $product->id]);

    expect($product->prices->first()->is($price))->toBeTrue()
        ->and($product->files->first()->is($file))->toBeTrue()
        ->and($price->product->is($product))->toBeTrue()
        ->and($file->product->is($product))->toBeTrue();
});

it('supports bundles with an independent price and rejects duplicates or direct self-inclusion', function () {
    $bundle = Product::factory()->bundle()->create();
    $child = Product::factory()->create(['type' => ProductType::Course]);

    ProductPrice::factory()->create([
        'product_id' => $bundle->id,
        'currency' => 'XOF',
        'price_minor' => 75000,
        'compare_at_price_minor' => null,
    ]);

    $bundle->childProducts()->attach($child->id, ['position' => 1]);

    expect($bundle->type)->toBe(ProductType::Bundle)
        ->and($bundle->prices()->where('currency', 'XOF')->value('price_minor'))->toBe(75000)
        ->and($bundle->childProducts->first()->is($child))->toBeTrue()
        ->and($child->parentBundles->first()->is($bundle))->toBeTrue();

    expectCatalogConstraintViolation(fn () => $bundle->childProducts()->attach($child->id));
    expectCatalogConstraintViolation(fn () => $bundle->childProducts()->attach($bundle->id));
});

it('enforces product file private storage, private path, checksum, and non-negative size', function () {
    $product = Product::factory()->create();

    ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_disk' => 'private',
        'storage_path' => 'products/'.fake()->uuid().'/version-1/file.zip',
        'checksum_sha256' => hash('sha256', 'safe-placeholder'),
        'size_bytes' => 0,
    ]);

    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_disk' => 'public',
    ]));
    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_path' => 'https://example.com/file.zip',
    ]));
    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_path' => 's3://bucket/file.zip',
    ]));
    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_path' => '/absolute/file.zip',
    ]));
    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_path' => '\\absolute\\file.zip',
    ]));
    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_path' => 'C:\\absolute\\file.zip',
    ]));
    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_path' => 'D:/absolute/file.zip',
    ]));
    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_path' => 'public/file.zip',
    ]));
    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_path' => 'public\\file.zip',
    ]));
    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_path' => 'folder/public/file.zip',
    ]));
    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_path' => 'folder\\public\\file.zip',
    ]));
    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_path' => '../secret/file.zip',
    ]));
    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_path' => '..\\secret\\file.zip',
    ]));
    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_path' => 'products/../secret/file.zip',
    ]));
    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_path' => 'products\\..\\secret\\file.zip',
    ]));
    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_path' => '',
    ]));
    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'checksum_sha256' => 'not-a-sha256',
    ]));
    expectCatalogConstraintViolation(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'size_bytes' => -1,
    ]));
});

it('soft deletes products without physical deletion', function () {
    $product = Product::factory()->create(['status' => ProductStatus::Published]);

    $product->delete();

    $this->assertSoftDeleted($product);
    expect(Product::withTrashed()->whereKey($product->id)->exists())->toBeTrue();
});

it('does not expose digital files or download routes publicly in P2', function () {
    $forbiddenExtensions = ['zip', 'pdf', 'rar', '7z', 'tar', 'gz'];
    $publicFiles = collect(File::allFiles(public_path()))
        ->map(fn (SplFileInfo $file) => strtolower($file->getExtension()))
        ->filter();

    foreach ($forbiddenExtensions as $extension) {
        expect($publicFiles)->not->toContain($extension);
    }

    $uris = collect(Route::getRoutes())
        ->map(fn ($route) => $route->uri())
        ->reject(fn (string $uri) => str_starts_with($uri, 'filament/'))
        ->all();

    foreach ($uris as $uri) {
        expect($uri)->not->toContain('download')
            ->and($uri)->not->toContain('checkout');
    }
});
