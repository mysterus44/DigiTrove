<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Product;
use Illuminate\Http\Response;

/**
 * P7 dynamic sitemap and robots.txt (D-070).
 *
 * ⚠️ FAIL-CLOSED, same discipline as D-062. Only what is genuinely public gets in:
 * `published` articles that are not soft-deleted, published products with an active price,
 * and the catalogue pages already served. A draft, an archived article or an unpriced
 * product in a sitemap is worse than an omission — it invites a crawl, returns a 404, and
 * spends crawl budget teaching a search engine that the site lies.
 */
class SitemapController extends Controller
{
    public function sitemap(): Response
    {
        $urls = [
            ['loc' => route('storefront.home'), 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => route('products.index'), 'changefreq' => 'daily', 'priority' => '0.9'],
            ['loc' => route('blog.index'), 'changefreq' => 'daily', 'priority' => '0.9'],
        ];

        foreach (Product::query()->published()->orderBy('id')->cursor() as $product) {
            $urls[] = [
                'loc' => route('products.show', $product),
                'lastmod' => $product->updated_at?->toAtomString(),
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ];
        }

        // ⚠️ CATEGORY PAGES ARE DELIBERATELY ABSENT. Measured on the real legacy data: 19
        // articles carry 19 DISTINCT free-text categories, so every category page would list
        // exactly one article. Advertising nineteen near-empty listing pages is thin content
        // — it spends crawl budget and dilutes the articles themselves, which is the opposite
        // of what this gate exists for. The pages remain reachable and useful for a human
        // browsing; they are simply not submitted. Once an editor consolidates the taxonomy
        // into real categories, adding them back is three lines.

        foreach (Article::query()->published()->orderBy('id')->cursor() as $article) {
            $urls[] = [
                'loc' => route('blog.show', $article),
                'lastmod' => $article->updated_at?->toAtomString(),
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ];
        }

        return response()
            ->view('storefront.seo.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(): Response
    {
        // Nothing that must be indexed is disallowed. The private surfaces named here are
        // the ones that would waste crawl budget or expose an operator flow: the admin
        // panel, download links, checkout and the analytics endpoints.
        $lines = [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /downloads/',
            'Disallow: /checkout',
            'Disallow: /cart',
            'Disallow: /analytics/',
            'Disallow: /r/',
            '',
            'Sitemap: '.route('seo.sitemap'),
            '',
        ];

        return response(implode("\n", $lines))
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
