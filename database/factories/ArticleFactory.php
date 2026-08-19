<?php

namespace Database\Factories;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Support\ArticleContent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    public function definition(): array
    {
        $title = ucfirst(fake()->unique()->words(5, true));
        $body = '## '.fake()->sentence()."\n\n".fake()->paragraphs(4, true);

        return [
            'public_id' => (string) Str::uuid(),
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => $title,
            'excerpt' => fake()->sentence(12),
            'body' => $body,
            'cover_image_url' => null,
            'article_category_id' => null,
            // Draft by default: a factory must never make an article publicly visible by
            // accident, or a visibility test would pass for the wrong reason.
            'status' => ArticleStatus::Draft->value,
            'author_id' => null,
            'author_name' => fake()->name(),
            'meta_title' => Str::limit($title, 59, ''),
            'meta_description' => Str::limit(fake()->sentence(14), 159, ''),
            'canonical_url' => null,
            'reading_minutes' => ArticleContent::readingMinutes($body),
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => ArticleStatus::Published->value,
            'published_at' => now()->subDay(),
        ]);
    }

    /** Published, but dated in the future: scheduled, and therefore still invisible. */
    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => ArticleStatus::Published->value,
            'published_at' => now()->addWeek(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'status' => ArticleStatus::Archived->value,
            'published_at' => now()->subMonth(),
        ]);
    }
}
