<?php

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

#[Signature('catalog:import-legacy')]
#[Description('Importe une fois le catalogue SITE-00 en brouillons, sans publier ni dupliquer.')]
class ImportLegacyCatalog extends Command
{
    public function handle(): int
    {
        $source = $this->source();
        $counts = [
            'categories_created' => 0,
            'categories_ignored' => 0,
            'products_created' => 0,
            'products_ignored' => 0,
            'prices_created' => 0,
            'reviews_ignored' => count($source['reviews']),
        ];

        DB::transaction(function () use ($source, &$counts): void {
            $categories = [];

            foreach ($source['categories'] as $position => $legacyCategory) {
                $slug = Str::slug($legacyCategory['name']);
                $category = Category::query()->where('slug', $slug)->first();

                if ($category === null) {
                    $category = Category::query()->create([
                        'slug' => $slug,
                        'name' => $legacyCategory['name'],
                        'position' => $position,
                    ]);
                    $counts['categories_created']++;
                } else {
                    $counts['categories_ignored']++;
                }

                $categories[$legacyCategory['name']] = $category;
            }

            foreach ($source['products'] as $legacyProduct) {
                $slug = Str::slug($legacyProduct['name']);

                if (Product::withTrashed()->where('slug', $slug)->exists()) {
                    $counts['products_ignored']++;

                    continue;
                }

                $mapping = $this->productMapping($legacyProduct['type']);
                $product = Product::query()->create([
                    'slug' => $slug,
                    'name' => $legacyProduct['name'],
                    'type' => $mapping['type'],
                    'status' => ProductStatus::Draft,
                    'short_description' => $legacyProduct['summary'],
                    'long_description' => $legacyProduct['summary'],
                    'cover_image_path' => $legacyProduct['image'],
                    'sales_count' => $this->integerFromLegacy($legacyProduct['sales']),
                    'rating_avg' => 0,
                    'rating_count' => 0,
                    'published_at' => null,
                ]);

                $product->prices()->create([
                    'currency' => 'XOF',
                    'price_minor' => $this->integerFromLegacy($legacyProduct['price']),
                    'compare_at_price_minor' => $this->integerFromLegacy($legacyProduct['compare_at']),
                    'is_active' => true,
                ]);

                $product->categories()->attach($categories[$mapping['category']]);
                $counts['products_created']++;
                $counts['prices_created']++;
            }
        });

        foreach ($counts as $key => $value) {
            $this->line("{$key}={$value}");
        }

        $this->warn('reviews_ignored_reason=reviews schema is not migrated; legacy reviews remain unpersisted');
        $this->info('publication=none; every imported product remains draft');

        return self::SUCCESS;
    }

    /** @return array{products:list<array<string,string>>,categories:list<array<string,string>>,reviews:list<array<string,string>>} */
    private function source(): array
    {
        $source = require resource_path('storefront/legacy-catalog.php');

        if (! is_array($source)
            || ! is_array($source['products'] ?? null)
            || ! is_array($source['categories'] ?? null)
            || ! is_array($source['reviews'] ?? null)) {
            throw new RuntimeException('The audited legacy catalogue source is invalid.');
        }

        return $source;
    }

    /** @return array{type:ProductType,category:string} */
    private function productMapping(string $legacyType): array
    {
        return match ($legacyType) {
            'Livre' => ['type' => ProductType::Ebook, 'category' => 'Ebooks'],
            'Formation' => ['type' => ProductType::Course, 'category' => 'Formations'],
            'Logiciel' => ['type' => ProductType::Software, 'category' => 'Logiciels'],
            'Ressource' => ['type' => ProductType::Template, 'category' => 'Ressources'],
            default => throw new RuntimeException("Unsupported legacy product type: {$legacyType}"),
        };
    }

    private function integerFromLegacy(string $value): int
    {
        $digits = preg_replace('/\D+/', '', $value);

        if (! is_string($digits) || $digits === '') {
            throw new RuntimeException("Legacy numeric value is invalid: {$value}");
        }

        return (int) $digits;
    }
}
