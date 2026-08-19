<?php

namespace App\Models;

use Database\Factories\ArticleCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleCategory extends Model
{
    /** @use HasFactory<ArticleCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'position',
    ];

    /**
     * @return HasMany<Article, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function getRouteKeyName(): string
    {
        // URLs by slug, never by id (SEO_BLOG fundamentals). A slug is stable and readable;
        // an id leaks the publication order and cannot carry a keyword.
        return 'slug';
    }
}
