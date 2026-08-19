<?php

namespace App\Console\Commands;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Support\ArticleContent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * P7 legacy blog import (D-070), mirroring `catalog:import-legacy`.
 *
 * ⚠️ IDEMPOTENT BY SLUG. A replay creates nothing and overwrites nothing: an editor who has
 * since rewritten an imported article must not have their work silently reverted by an
 * operator running the command twice.
 *
 * ⚠️ IMPORTS AS DRAFTS. Nineteen legacy articles appearing live the moment the command runs
 * would be a publication decision taken by a script. Publishing stays a human act in the
 * admin, exactly as the catalogue import decided in D-062.
 *
 * ⚠️ THE LEGACY `tags` STRING IS NOT IMPORTED. No `tags` table exists and creating one was
 * not arbitrated; `legacy/data/blog-articles.json` keeps the data for a later gate.
 */
#[Signature('blog:import-legacy')]
#[Description('Importe une fois les articles legacy en brouillons, sans publier ni dupliquer.')]
class ImportLegacyBlog extends Command
{
    public function handle(): int
    {
        $source = $this->source();

        $counts = [
            'categories_created' => 0,
            'articles_created' => 0,
            'articles_ignored' => 0,
            'tags_skipped' => 0,
        ];

        DB::transaction(function () use ($source, &$counts): void {
            foreach ($source as $position => $legacy) {
                $slug = $this->slug($legacy);

                if ($slug === null) {
                    continue;
                }

                // The idempotency key. `withTrashed()` matters: a soft-deleted article still
                // owns its slug (the UNIQUE has no partial predicate), so re-creating it
                // would fail on the constraint rather than be ignored.
                if (Article::query()->withTrashed()->where('slug', $slug)->exists()) {
                    $counts['articles_ignored']++;

                    continue;
                }

                $category = null;
                $categoryName = trim((string) ($legacy['category'] ?? ''));

                if ($categoryName !== '') {
                    $categorySlug = Str::slug($categoryName);
                    $category = ArticleCategory::query()->where('slug', $categorySlug)->first();

                    if ($category === null) {
                        $category = ArticleCategory::query()->create([
                            'slug' => $categorySlug,
                            'name' => $categoryName,
                            'position' => $position,
                        ]);
                        $counts['categories_created']++;
                    }
                }

                $body = (string) ($legacy['content'] ?? '');

                if (trim($body) === '') {
                    continue;
                }

                Article::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'slug' => $slug,
                    'title' => trim((string) $legacy['title']),
                    'excerpt' => $this->trimmedOrNull($legacy['excerpt'] ?? null),
                    'body' => $body,
                    'cover_image_url' => $this->trimmedOrNull($legacy['image'] ?? null),
                    'article_category_id' => $category?->getKey(),
                    // Drafts. Publishing is a human decision, never a side effect of import.
                    'status' => ArticleStatus::Draft->value,
                    'author_id' => null,
                    'author_name' => $this->trimmedOrNull($legacy['author'] ?? null),
                    // Truncated to the SEO limits the CHECKs enforce, rather than letting the
                    // database refuse a long legacy title and abort the whole import.
                    'meta_title' => $this->clamp($legacy['title'] ?? null, 60),
                    'meta_description' => $this->clamp($legacy['excerpt'] ?? null, 160),
                    'reading_minutes' => ArticleContent::readingMinutes($body),
                    'published_at' => null,
                ]);

                $counts['articles_created']++;

                if (trim((string) ($legacy['tags'] ?? '')) !== '') {
                    $counts['tags_skipped']++;
                }
            }
        });

        foreach ($counts as $key => $value) {
            $this->line($key.'='.$value);
        }

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function source(): array
    {
        $path = base_path('legacy/data/blog-articles.json');

        if (! is_file($path)) {
            throw new RuntimeException('Le fichier legacy des articles est introuvable.');
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('Le fichier legacy des articles est invalide.');
        }

        return array_values(array_filter($decoded, static fn ($row): bool => is_array($row) && isset($row['title'])));
    }

    /** @param  array<string, mixed>  $legacy */
    private function slug(array $legacy): ?string
    {
        // The legacy slug is preferred — it IS the acquired SEO — but it is re-slugged so a
        // stray uppercase or accent can never breach `articles_slug_format_check`.
        $slug = Str::slug((string) ($legacy['slug'] ?? '')) ?: Str::slug((string) ($legacy['title'] ?? ''));

        return $slug === '' ? null : $slug;
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function clamp(mixed $value, int $length): ?string
    {
        $trimmed = $this->trimmedOrNull($value);

        return $trimmed === null ? null : Str::limit($trimmed, $length - 1, '…');
    }
}
