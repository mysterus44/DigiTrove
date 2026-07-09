<?php

namespace Tests\Feature;

use Tests\TestCase;

class StorefrontPreviewTest extends TestCase
{
    public function test_homepage_returns_successful_static_storefront_preview(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('DigiTrove');
        $response->assertSee('SITE-00 - Vitrine statique de previsualisation');
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

    public function test_static_preview_does_not_expose_transactional_or_legacy_links(): void
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
