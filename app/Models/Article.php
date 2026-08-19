<?php

namespace App\Models;

use App\Enums\ArticleStatus;
use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P7 blog article (D-070).
 *
 * ⚠️ NO `views_count`. A counter incremented from a public request is forgeable and turns
 * every read into a write; reading metrics belong to the P5 `events` pipeline. See the
 * migration for the full reasoning.
 */
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'slug',
        'title',
        'excerpt',
        'body',
        'cover_image_url',
        'article_category_id',
        'status',
        'author_id',
        'author_name',
        'meta_title',
        'meta_description',
        'canonical_url',
        'reading_minutes',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ArticleStatus::class,
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ArticleCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class, 'article_category_id');
    }

    /**
     * The account that wrote it, when there is one. Legacy articles have a name and no
     * account, and an account can be deleted — which is why `author_name` is a snapshot
     * rather than something derived from this relation.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * The internal mesh: the products this article actually talks about. This is what turns
     * SEO traffic into sales rather than decoration.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'article_product')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /**
     * Publicly visible: `published` AND dated in the past.
     *
     * The date test is not decoration — `published_at` may be set in the future to schedule
     * an article, and without this a scheduled piece would appear the moment it was saved.
     *
     * @param  Builder<Article>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', ArticleStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** The meta title falls back to the title, never to an empty tag. */
    public function seoTitle(): string
    {
        return $this->meta_title ?: $this->title;
    }

    public function seoDescription(): ?string
    {
        return $this->meta_description ?: $this->excerpt;
    }
}
