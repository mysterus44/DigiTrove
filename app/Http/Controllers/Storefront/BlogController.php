<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleCategory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

/**
 * P7 public blog (D-070).
 *
 * Fail-closed on visibility, exactly as D-062 did for the catalogue: `published()` is applied
 * on EVERY read, including the one that resolves a slug. A draft reached by guessing its URL
 * is a 404, never a preview.
 */
class BlogController extends Controller
{
    public function index(): View
    {
        return view('storefront.blog.index', [
            'articles' => $this->publishedQuery()->paginate(12),
            'categories' => ArticleCategory::query()
                ->whereHas('articles', fn ($query) => $query->published())
                ->orderBy('position')
                ->orderBy('name')
                ->get(),
            'category' => null,
        ]);
    }

    public function category(ArticleCategory $articleCategory): View
    {
        return view('storefront.blog.index', [
            'articles' => $this->publishedQuery()
                ->where('article_category_id', $articleCategory->getKey())
                ->paginate(12),
            'categories' => ArticleCategory::query()
                ->whereHas('articles', fn ($query) => $query->published())
                ->orderBy('position')
                ->orderBy('name')
                ->get(),
            'category' => $articleCategory,
        ]);
    }

    public function show(Article $article): View
    {
        // Route-model binding resolved the slug WITHOUT the visibility scope, so it is
        // re-applied here. Binding alone would serve drafts to anyone who knows the URL.
        $published = Article::query()
            ->published()
            ->with(['category', 'products.activeXofPrice'])
            ->whereKey($article->getKey())
            ->firstOrFail();

        return view('storefront.blog.show', [
            'article' => $published,
            // Same category, never the article itself. Ordered by recency so the mesh points
            // at what is most likely still relevant.
            'related' => $published->article_category_id === null ? collect() : $this->publishedQuery()
                ->where('article_category_id', $published->article_category_id)
                ->whereKeyNot($published->getKey())
                ->limit(3)
                ->get(),
        ]);
    }

    /** @return Builder<Article> */
    private function publishedQuery()
    {
        return Article::query()
            ->published()
            ->with('category')
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }
}
