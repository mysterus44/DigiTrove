<?php

declare(strict_types=1);

use App\Console\Commands\ImportLegacyBlog;
use App\Enums\ArticleStatus;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Redirect;
use App\Models\User;
use App\Support\ArticleContent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

/**
 * P7 — Blog & SEO (D-070).
 *
 * `RefreshDatabase` is correct here: nothing in this gate needs two independent PostgreSQL
 * transactions, no deferred trigger is involved, and no authority refuses an ambient
 * transaction. See `P6D2GuestCheckoutSequenceTest` for the cases where it is NOT.
 */
uses(RefreshDatabase::class);

function p7PublishedArticle(array $attributes = []): Article
{
    return Article::factory()->published()->create($attributes);
}

function p7SellableProduct(string $slug = 'p7-produit'): Product
{
    $product = Product::factory()->create([
        'slug' => $slug,
        'status' => ProductStatus::Published,
        'published_at' => now()->subDay(),
    ]);

    ProductPrice::factory()->create([
        'product_id' => $product->id,
        'currency' => 'XOF',
        'price_minor' => 15_000,
        // Pinned: the factory randomises this and the CHECK demands it exceed the price.
        'compare_at_price_minor' => null,
        'is_active' => true,
    ]);

    return $product->fresh();
}

// ── 1. The frontier ──────────────────────────────────────────────────────────────

it('owns exactly migration 000035 with four tables and one chain guard', function () {
    $root = dirname(__DIR__, 2);

    expect(glob($root.'/database/migrations/*.php'))->toHaveCount(51)
        ->and(glob($root.'/database/migrations/2026_07_14_000035*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000036*.php') ?: [])->toBe([]);

    foreach (['articles', 'article_categories', 'article_product', 'redirects'] as $table) {
        expect(DB::selectOne('SELECT to_regclass(?) AS t', ['public.'.$table])->t)->not->toBeNull();
    }

    // ⚠️ NO `views_count`. A counter incremented from a public request is forgeable and
    // turns every page read into a write; reading metrics belong to the P5 events pipeline.
    $columns = array_map(
        static fn (object $r): string => $r->attname,
        DB::select(<<<'SQL'
            SELECT a.attname FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relname = 'articles' AND a.attnum > 0 AND NOT a.attisdropped
            SQL),
    );

    expect($columns)->not->toContain('views_count')
        ->and($columns)->toContain('reading_minutes');

    // P7 creates no privilege frontier: no executor role, no SECURITY DEFINER authority.
    // Only the chain guard, which is an integrity trigger and not an authority.
    expect(DB::select(<<<'SQL'
        SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
        WHERE n.nspname = 'public' AND p.prosecdef
          AND (p.proname LIKE '%article%' OR p.proname LIKE '%redirect%' OR p.proname LIKE '%blog%')
        SQL))->toBe([]);
});

// ── 2. Visibility, fail-closed ───────────────────────────────────────────────────

it('shows a published article and hides a draft, an archived one and a scheduled one', function () {
    $published = p7PublishedArticle(['title' => 'Article publié']);
    $draft = Article::factory()->create(['title' => 'Brouillon secret']);
    $archived = Article::factory()->archived()->create(['title' => 'Article archivé']);
    // Published, but dated tomorrow. Without the date test in `scopePublished` a scheduled
    // article would appear the instant it was saved.
    $scheduled = Article::factory()->scheduled()->create(['title' => 'Parution programmée']);

    $index = $this->get(route('blog.index'));

    $index->assertOk()
        ->assertSee('Article publié')
        ->assertDontSee('Brouillon secret')
        ->assertDontSee('Article archivé')
        ->assertDontSee('Parution programmée');

    $this->get(route('blog.show', $published))->assertOk()->assertSee('Article publié');

    // Guessing the URL is not a preview.
    foreach ([$draft, $archived, $scheduled] as $hidden) {
        $this->get(route('blog.show', $hidden))->assertNotFound();
    }
});

it('hides a soft-deleted article without freeing its slug', function () {
    $article = p7PublishedArticle(['slug' => 'guide-no-code']);
    $article->delete();

    $this->get(route('blog.show', $article))->assertNotFound();

    // The slug stays taken: a new article inheriting that URL would inherit the SEO — and
    // the backlinks — of a different page.
    expect(fn () => Article::factory()->create(['slug' => 'guide-no-code']))
        ->toThrow(QueryException::class);
});

// ── 3. The Markdown boundary ─────────────────────────────────────────────────────

it('strips every raw tag and unsafe link scheme from article markdown', function () {
    $cases = [
        '<script>alert(1)</script>' => 'alert(1)',
        'Texte <img src=x onerror=alert(1)> suite.' => 'onerror',
        'Avant <b onclick="x">gras</b> après.' => 'onclick',
    ];

    foreach ($cases as $markdown => $forbidden) {
        expect(ArticleContent::toHtml($markdown))->not->toContain($forbidden);
    }

    // `javascript:` and `data:` never become an href — the anchor renders without one.
    foreach (['javascript:alert(1)', 'data:text/html;base64,PHN2Zz4='] as $scheme) {
        expect(ArticleContent::toHtml('[clic]('.$scheme.')'))->not->toContain('href');
    }

    // Legitimate Markdown survives intact, otherwise the sanitizer would be useless.
    $html = ArticleContent::toHtml("## Titre\n\nUn **gras** et un [lien](/products).");

    expect($html)->toContain('<h2>Titre</h2>')
        ->and($html)->toContain('<strong>gras</strong>')
        ->and($html)->toContain('href="/products"');
});

it('renders the sanitized body on the public page, never the raw markdown', function () {
    $article = p7PublishedArticle([
        'body' => "## Section\n\n<script>alert('xss')</script>\n\nTexte visible.",
    ]);

    $response = $this->get(route('blog.show', $article));

    $response->assertOk()
        ->assertSee('<h2>Section</h2>', false)
        ->assertSee('Texte visible')
        ->assertDontSee('<script>alert', false);
});

it('derives reading time from the body, deterministically', function () {
    // 400 words at 200 wpm = 2 minutes. No request, no counter, nothing forgeable.
    expect(ArticleContent::readingMinutes(str_repeat('mot ', 400)))->toBe(2)
        ->and(ArticleContent::readingMinutes('court'))->toBe(1)
        ->and(ArticleContent::readingMinutes(str_repeat('mot ', 400)))
        ->toBe(ArticleContent::readingMinutes(str_repeat('mot ', 400)));
});

// ── 4. SEO output ────────────────────────────────────────────────────────────────

it('emits a canonical, Open Graph tags and valid Article JSON-LD', function () {
    $article = p7PublishedArticle([
        'title' => 'Le guide du no-code',
        'meta_description' => 'Un guide court.',
        'author_name' => 'Kouda Mohamed',
    ]);

    $html = $this->get(route('blog.show', $article))->assertOk()->getContent();

    expect($html)->toContain('<link rel="canonical" href="'.route('blog.show', $article).'"')
        ->and($html)->toContain('property="og:type" content="article"')
        ->and($html)->toContain('property="og:title"');

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    expect($matches)->not->toBeEmpty();

    $jsonLd = json_decode(html_entity_decode($matches[1]), true, 512, JSON_THROW_ON_ERROR);

    expect($jsonLd['@type'])->toBe('Article')
        ->and($jsonLd['headline'])->toBe('Le guide du no-code')
        ->and($jsonLd['author']['name'])->toBe('Kouda Mohamed')
        // ⚠️ NEVER an aggregateRating: no reviews table exists (P2 paused it), so a
        // structured rating would be backed by nothing — a Google penalty, not a feature.
        ->and($jsonLd)->not->toHaveKey('aggregateRating');
});

it('emits Product JSON-LD from the real price, with no rating', function () {
    $product = p7SellableProduct();

    $html = $this->get(route('products.show', $product))->assertOk()->getContent();

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    $jsonLd = json_decode(html_entity_decode($matches[1]), true, 512, JSON_THROW_ON_ERROR);

    expect($jsonLd['@type'])->toBe('Product')
        // The displayed price and its currency, never a hard-coded `XOF`.
        ->and($jsonLd['offers']['price'])->toBe('15000')
        ->and($jsonLd['offers']['priceCurrency'])->toBe('XOF')
        ->and($jsonLd)->not->toHaveKey('aggregateRating')
        ->and($jsonLd)->not->toHaveKey('review');
});

it('escapes a title that would otherwise break out of the JSON-LD', function () {
    $article = p7PublishedArticle(['title' => 'Un "guide" <script>alert(1)</script> malin']);

    $html = $this->get(route('blog.show', $article))->assertOk()->getContent();

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    // The block must still be parseable: an unescaped quote would make this throw, which is
    // precisely how a title becomes an injection vector.
    $jsonLd = json_decode(html_entity_decode($matches[1]), true, 512, JSON_THROW_ON_ERROR);

    expect($jsonLd['headline'])->toBe('Un "guide" <script>alert(1)</script> malin')
        ->and($matches[1])->not->toContain('<script>alert');
});

// ── 5. Sitemap and robots ────────────────────────────────────────────────────────

it('lists only what is genuinely public in the sitemap', function () {
    $published = p7PublishedArticle(['slug' => 'article-public']);
    $draft = Article::factory()->create(['slug' => 'article-brouillon']);
    $archived = Article::factory()->archived()->create(['slug' => 'article-archive']);
    $deleted = p7PublishedArticle(['slug' => 'article-supprime']);
    $deleted->delete();

    $product = p7SellableProduct('produit-publie');

    $xml = $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->getContent();

    expect($xml)->toContain(route('blog.show', $published))
        ->and($xml)->toContain(route('products.show', $product))
        ->and($xml)->toContain(route('blog.index'))
        ->and($xml)->toContain(route('products.index'))
        // Fail-closed: a draft, an archived or a deleted article in a sitemap invites a
        // crawl that returns a 404 and teaches a search engine that the site lies.
        ->and($xml)->not->toContain('article-brouillon')
        ->and($xml)->not->toContain('article-archive')
        ->and($xml)->not->toContain('article-supprime');
});

it('keeps thin category pages out of the sitemap', function () {
    $category = ArticleCategory::factory()->create(['slug' => 'developpement-tech']);
    p7PublishedArticle(['article_category_id' => $category->getKey()]);

    // Measured on the real legacy data: 19 articles carry 19 DISTINCT categories, so every
    // category page would list exactly one article. Submitting nineteen near-empty listings
    // spends crawl budget and dilutes the articles themselves. The pages stay browsable.
    expect($this->get('/sitemap.xml')->getContent())->not->toContain('/blog/categorie/');

    $this->get(route('blog.category', $category))->assertOk();
});

it('serves a robots.txt that points at the sitemap and hides only private surfaces', function () {
    $body = $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->getContent();

    expect($body)->toContain('Sitemap: '.route('seo.sitemap'))
        ->and($body)->toContain('Disallow: /admin')
        ->and($body)->toContain('Disallow: /downloads/')
        // Nothing that must be indexed is disallowed.
        ->and($body)->not->toContain('Disallow: /blog')
        ->and($body)->not->toContain('Disallow: /products');
});

// ── 6. Redirects ─────────────────────────────────────────────────────────────────

it('serves a stored 301 and never lets it shadow a real route', function () {
    $article = p7PublishedArticle(['slug' => 'nouvelle-url']);

    Redirect::query()->create([
        'from_path' => '/page/ancienne-url',
        'to_path' => '/blog/nouvelle-url',
        'status_code' => 301,
    ]);

    $this->get('/page/ancienne-url')->assertStatus(301)->assertRedirect('/blog/nouvelle-url');

    // A redirect whose source is a REAL route never fires: the middleware only sees a 404.
    //
    // The source is `/products`, NOT `/blog/nouvelle-url`: the latter is already the
    // destination above, and pointing away from it would be a 301 chain — which the database
    // refuses, as its own test proves. Two redirects sharing a destination are fine.
    Redirect::query()->create(['from_path' => '/products', 'to_path' => '/blog/nouvelle-url']);

    $this->get(route('products.index'))->assertOk();
    $this->get(route('blog.show', $article))->assertOk()->assertSee($article->title);
});

it('refuses a redirect chain, a self-loop and an external destination', function () {
    Redirect::query()->create(['from_path' => '/a', 'to_path' => '/b']);

    // A → B while B → C would cost crawl budget and link equity on every hop.
    expect(fn () => Redirect::query()->create(['from_path' => '/b', 'to_path' => '/c']))
        ->toThrow(QueryException::class)
        // And the mirror case: C → A while A → B exists.
        ->and(fn () => Redirect::query()->create(['from_path' => '/z', 'to_path' => '/a']))
        ->toThrow(QueryException::class)
        ->and(fn () => Redirect::query()->create(['from_path' => '/self', 'to_path' => '/self']))
        ->toThrow(QueryException::class);

    // `//host` and `/\host` are read as protocol-relative by browsers: an open redirect.
    foreach (['//evil.test', '/\\evil.test', 'https://evil.test'] as $hostile) {
        expect(fn () => Redirect::query()->create(['from_path' => '/depart', 'to_path' => $hostile]))
            ->toThrow(QueryException::class);
    }
});

// ── 7. Legacy import ─────────────────────────────────────────────────────────────

it('imports the legacy blog once, as drafts, and creates nothing on a replay', function () {
    $this->artisan(ImportLegacyBlog::class)->assertSuccessful();

    $created = Article::query()->count();

    expect($created)->toBe(19)
        // Publishing is a human decision in the admin, never a side effect of a command.
        ->and(Article::query()->where('status', ArticleStatus::Published->value)->count())->toBe(0)
        ->and(Article::query()->whereNull('reading_minutes')->count())->toBe(0);

    $this->artisan(ImportLegacyBlog::class)->assertSuccessful();

    expect(Article::query()->count())->toBe($created)
        ->and(ArticleCategory::query()->count())->toBe(19);
});

it('does not resurrect a soft-deleted legacy article on a replay', function () {
    $this->artisan(ImportLegacyBlog::class)->assertSuccessful();

    $article = Article::query()->firstOrFail();
    $slug = $article->slug;
    $article->delete();

    $this->artisan(ImportLegacyBlog::class)->assertSuccessful();

    // The slug is still taken by the trashed row; re-creating it would breach the UNIQUE.
    expect(Article::query()->withTrashed()->where('slug', $slug)->count())->toBe(1)
        ->and(Article::query()->where('slug', $slug)->exists())->toBeFalse();
});

// ── 8. The internal mesh ─────────────────────────────────────────────────────────

it('links an article to the products it talks about', function () {
    $article = p7PublishedArticle();
    $product = p7SellableProduct('produit-lie');

    $article->products()->attach($product->getKey(), ['position' => 0]);

    $this->get(route('blog.show', $article))
        ->assertOk()
        ->assertSee($product->name);

    expect($article->fresh()->products)->toHaveCount(1);
});

// ── 9. The editorial surface is admin-only ───────────────────────────────────────

it('lets only an active administrator reach the blog admin', function () {
    // ⚠️ THIS IS WHY `BlogPolicy` EXISTS. Policies bind PER MODEL: `CatalogPolicy` is
    // registered against `Product`, `Category` and `ProductFile` and grants the blog
    // NOTHING. Without an explicit registration Filament falls back to its default and any
    // AUTHENTICATED user — staff and customer included — could write articles and create
    // 301s pointing anywhere.
    $article = p7PublishedArticle();

    $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
    $staff = User::factory()->create(['role' => UserRole::Staff, 'status' => UserStatus::Active]);
    $customer = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
    $suspended = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Suspended]);

    foreach ([Article::class, ArticleCategory::class, Redirect::class] as $model) {
        expect(Gate::forUser($admin)->allows('viewAny', $model))->toBeTrue()
            ->and(Gate::forUser($staff)->allows('viewAny', $model))->toBeFalse()
            ->and(Gate::forUser($customer)->allows('viewAny', $model))->toBeFalse()
            // An admin who was suspended is not an admin any more.
            ->and(Gate::forUser($suspended)->allows('viewAny', $model))->toBeFalse();
    }

    // Destroying an article frees its slug; nobody may, however senior.
    expect(Gate::forUser($admin)->allows('forceDelete', $article))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $article))->toBeTrue();

    // And a deleted administrator loses it too.
    $admin->delete();
    expect(Gate::forUser($admin->fresh())->allows('viewAny', Article::class))->toBeFalse();
});
