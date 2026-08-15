<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\RefreshesDatabaseAsMigrator;
use Tests\TestCase;

class StorefrontPreviewTest extends TestCase
{
    use RefreshesDatabaseAsMigrator;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('catalog:import-legacy');
        Product::query()->update([
            'status' => ProductStatus::Published,
            'published_at' => now()->subMinute(),
        ]);
    }

    public function test_homepage_returns_successful_dynamic_storefront(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('DigiTrove');
        $response->assertSee('Boutique digitale autonome');
    }

    public function test_homepage_displays_legacy_marketing_products_and_xof_prices(): void
    {
        $response = $this->get('/');

        $response->assertSee('Pack Livres');
        $response->assertSee('Pack De Formation Bureautique');
        $response->assertSee('Pack +200 logiciels Pro + Bonus');
        $response->assertSee('3 500 XOF');
        $response->assertSee('9 900 XOF');
    }

    public function test_storefront_does_not_expose_transactional_or_legacy_links(): void
    {
        $content = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('/checkout', $content);
        $this->assertStringNotContainsString('legacy/', $content);
        $this->assertStringNotContainsString('../uploads', $content);
        $this->assertStringNotContainsString('href="/download', $content);
        $this->assertStringNotContainsString('href="/storage', $content);
        $this->assertStringNotContainsString('.zip', $content);
        $this->assertStringNotContainsString('.pdf', $content);
        $this->assertStringNotContainsString('download_grants', $content);
        $this->assertStringNotContainsString('product_files', $content);
    }
}
