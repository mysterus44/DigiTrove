<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\ProductPrice;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

function storefrontProduct(array $attributes = [], array $price = []): Product
{
    $product = Product::factory()->create(array_merge([
        'status' => ProductStatus::Published,
        'published_at' => now()->subMinute(),
    ], $attributes));

    ProductPrice::factory()->create(array_merge([
        'product_id' => $product->id,
        'currency' => 'XOF',
        'is_active' => true,
    ], $price));

    return $product;
}

it('shows only published products with an active XOF price', function () {
    $published = storefrontProduct(['name' => 'Visible XOF', 'slug' => 'visible-xof'], ['price_minor' => 4_900]);
    storefrontProduct(['name' => 'Draft secret', 'slug' => 'draft-secret', 'status' => ProductStatus::Draft, 'published_at' => null]);
    storefrontProduct(['name' => 'Archived secret', 'slug' => 'archived-secret', 'status' => ProductStatus::Archived]);
    storefrontProduct(['name' => 'Future secret', 'slug' => 'future-secret', 'published_at' => now()->addDay()]);
    storefrontProduct(['name' => 'Inactive XOF', 'slug' => 'inactive-xof'], ['is_active' => false]);

    $response = $this->get('/')->assertOk();

    $response->assertSee($published->name)
        ->assertSee('4 900 XOF')
        ->assertDontSee('Draft secret')
        ->assertDontSee('Archived secret')
        ->assertDontSee('Future secret')
        ->assertDontSee('Inactive XOF')
        ->assertDontSee('selecteur de devise');
});

it('paginates the public catalog and never exposes private product file metadata', function () {
    $products = collect();

    foreach (range(1, 13) as $index) {
        $products->push(storefrontProduct([
            'name' => "Produit public {$index}",
            'slug' => "produit-public-{$index}",
            'published_at' => now()->subMinutes($index),
        ]));
    }

    ProductFile::factory()->create([
        'product_id' => $products->first()->id,
        'storage_path' => 'products/secret/internal.zip',
        'checksum_sha256' => str_repeat('a', 64),
    ]);

    $firstPage = $this->get('/products')->assertOk();
    $secondPage = $this->get('/products?page=2')->assertOk();

    $firstPage->assertSee('Produit public 1')
        ->assertDontSee('Produit public 13')
        ->assertDontSee('products/secret/internal.zip')
        ->assertDontSee(str_repeat('a', 64));
    $secondPage->assertSee('Produit public 13');
});

it('returns a product detail only for published and currently visible records', function () {
    $visible = storefrontProduct([
        'name' => 'Produit detail',
        'slug' => 'produit-detail',
        'short_description' => 'Description courte.',
        'long_description' => '<script>alert("xss")</script> contenu sur.',
        'meta_title' => '</title><script>TITLE_XSS</script>',
        'meta_description' => '"><img src=x onerror=META_XSS>',
    ]);
    $draft = storefrontProduct(['slug' => 'produit-draft', 'status' => ProductStatus::Draft, 'published_at' => null]);
    $archived = storefrontProduct(['slug' => 'produit-archive', 'status' => ProductStatus::Archived]);
    $deleted = storefrontProduct(['slug' => 'produit-supprime']);
    $deleted->delete();

    $response = $this->get(route('products.show', $visible))->assertOk();
    $content = $response->getContent();

    $response->assertSee('Produit detail')
        ->assertSee('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt; contenu sur.', false)
        ->assertSee('TITLE_XSS', false)
        ->assertSee('META_XSS', false);
    expect($content)->not->toContain('<script>alert("xss")</script>')
        ->not->toContain('</title><script>TITLE_XSS</script>')
        ->not->toContain('"><img src=x onerror=META_XSS>')
        ->not->toContain('storage_path')
        ->not->toContain('checksum_sha256');

    $this->get('/products/'.$draft->slug)->assertNotFound();
    $this->get('/products/'.$archived->slug)->assertNotFound();
    $this->get('/products/'.$deleted->slug)->assertNotFound();
    $this->get('/products/inconnu')->assertNotFound();
});

it('does not expose products lacking a usable XOF price', function () {
    $product = Product::factory()->create([
        'status' => ProductStatus::Published,
        'published_at' => now()->subMinute(),
        'slug' => 'usd-only',
    ]);
    ProductPrice::factory()->create([
        'product_id' => $product->id,
        'currency' => 'USD',
        'is_active' => true,
    ]);

    $this->get('/products')->assertDontSee($product->name);
    $this->get('/products/'.$product->slug)->assertNotFound();
});
