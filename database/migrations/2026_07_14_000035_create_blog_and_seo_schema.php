<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P7 — Blog & SEO schema (D-070).
 *
 * Four tables, no PostgreSQL privilege frontier: nothing here is money-adjacent, so there is
 * no executor role, no `SECURITY DEFINER` authority and no ACL work. That is a deliberate
 * scoping call — importing the P4/P6 machinery where it buys nothing would be cargo cult.
 *
 * ⚠️ NO `views_count`. The skill sketched one, and it is exactly the class of defect P4-B
 * cost days to close: a counter incremented by a public request is forgeable and, worse,
 * turns every page view into a write on a table the whole site reads. Reading metrics belong
 * to the P5 `events` pipeline and its rollups. `reading_minutes` stays, because it is
 * DERIVED FROM THE BODY at write time — no user input, no request, no race.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Article taxonomy ──────────────────────────────────────────────────────
        //
        // A single category per article, not a pivot: the legacy carries exactly one
        // (`"category": "Développement & Tech"`), and the PRD makes the pivot optional
        // "selon le legacy". A FK is a strict subset of a future pivot, so the day
        // multi-category is genuinely asked for, the extension is a backfill — not a
        // rewrite. Anticipating it today would be scope nobody requested.
        Schema::create('article_categories', function (Blueprint $table): void {
            $table->id();
            $table->text('slug');
            $table->text('name');
            $table->integer('position')->default(0);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE article_categories ADD CONSTRAINT article_categories_slug_unique UNIQUE (slug)');
        DB::statement("ALTER TABLE article_categories ADD CONSTRAINT article_categories_slug_format_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement('ALTER TABLE article_categories ADD CONSTRAINT article_categories_name_not_blank_check CHECK (length(btrim(name)) > 0)');

        // ── 2. Articles ──────────────────────────────────────────────────────────────
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->text('slug');
            $table->text('title');
            $table->text('excerpt')->nullable();
            // MARKDOWN, never HTML. Rendered server-side through CommonMark with
            // `html_input: strip`, so a raw `<script>` in the source cannot survive to the
            // page even if an editor pastes one.
            $table->text('body');
            $table->text('cover_image_url')->nullable();
            $table->foreignId('article_category_id')->nullable()->constrained('article_categories')->nullOnDelete();
            $table->text('status')->default('draft');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            // Snapshot, exactly like `order_items.product_name_snapshot`: the legacy author is
            // a NAME, not an account, and a byline must survive the account being deleted.
            $table->text('author_name')->nullable();
            $table->text('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('canonical_url')->nullable();
            $table->integer('reading_minutes')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['status', 'published_at'], 'articles_status_published_at_index');
            $table->index('article_category_id', 'articles_article_category_id_index');
        });

        DB::statement('ALTER TABLE articles ADD CONSTRAINT articles_public_id_unique UNIQUE (public_id)');
        // Slug uniqueness must survive a soft delete: a restored article would otherwise
        // collide, and a NEW article could steal the URL of a deleted one mid-restore.
        DB::statement('ALTER TABLE articles ADD CONSTRAINT articles_slug_unique UNIQUE (slug)');
        DB::statement("ALTER TABLE articles ADD CONSTRAINT articles_slug_format_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE articles ADD CONSTRAINT articles_status_check CHECK (status IN ('draft', 'published', 'archived'))");
        DB::statement('ALTER TABLE articles ADD CONSTRAINT articles_title_not_blank_check CHECK (length(btrim(title)) > 0)');
        DB::statement('ALTER TABLE articles ADD CONSTRAINT articles_body_not_blank_check CHECK (length(btrim(body)) > 0)');
        // A published article without a date could never be ordered, paginated or put in a
        // sitemap. The database refuses the state rather than letting the front guess.
        DB::statement("ALTER TABLE articles ADD CONSTRAINT articles_published_at_check CHECK (status <> 'published' OR published_at IS NOT NULL)");
        DB::statement('ALTER TABLE articles ADD CONSTRAINT articles_reading_minutes_check CHECK (reading_minutes IS NULL OR reading_minutes >= 0)');
        // The SEO limits are the reason these columns exist; enforcing them here means a
        // truncated title can never reach a search engine because a form forgot to validate.
        DB::statement('ALTER TABLE articles ADD CONSTRAINT articles_meta_title_length_check CHECK (meta_title IS NULL OR length(meta_title) <= 60)');
        DB::statement('ALTER TABLE articles ADD CONSTRAINT articles_meta_description_length_check CHECK (meta_description IS NULL OR length(meta_description) <= 160)');

        // ── 3. Article ↔ product — where the blog becomes revenue ────────────────────
        //
        // The skill calls this "la table qui transforme le trafic SEO en ventes". It is the
        // internal mesh: an article recommends the products it actually talks about.
        Schema::create('article_product', function (Blueprint $table): void {
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['article_id', 'product_id']);
            $table->index('product_id', 'article_product_product_id_index');
        });

        // ── 4. Redirects — the acquired SEO must survive the rewrite ─────────────────
        Schema::create('redirects', function (Blueprint $table): void {
            $table->id();
            $table->text('from_path');
            $table->text('to_path');
            $table->integer('status_code')->default(301);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE redirects ADD CONSTRAINT redirects_from_path_unique UNIQUE (from_path)');
        // Absolute internal paths only: an open redirect toward another host is exactly the
        // defect P6-D2 closed on `/r/{code}`, and a redirect table is a far easier target.
        DB::statement("ALTER TABLE redirects ADD CONSTRAINT redirects_from_path_format_check CHECK (from_path ~ '^/' AND from_path !~ '^//' AND from_path !~ '^/\\\\')");
        DB::statement("ALTER TABLE redirects ADD CONSTRAINT redirects_to_path_format_check CHECK (to_path ~ '^/' AND to_path !~ '^//' AND to_path !~ '^/\\\\')");
        DB::statement('ALTER TABLE redirects ADD CONSTRAINT redirects_not_self_check CHECK (from_path <> to_path)');
        DB::statement('ALTER TABLE redirects ADD CONSTRAINT redirects_status_code_check CHECK (status_code IN (301, 308))');

        // A CHECK cannot see other rows, and a chain needs exactly that. A trigger is the
        // only place this invariant can live: without it, 301 → 301 → 301 costs crawl budget
        // and link equity on every hop, which is the whole thing this table exists to protect.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enforce_redirect_no_chain()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                -- The destination is itself redirected: A → B while B → C exists.
                IF EXISTS (
                    SELECT 1 FROM public.redirects
                    WHERE from_path = NEW.to_path AND id IS DISTINCT FROM NEW.id
                ) THEN
                    RAISE EXCEPTION USING ERRCODE = '23514',
                        MESSAGE = 'a redirect destination is already redirected elsewhere';
                END IF;

                -- The source is itself a destination: A → B while C → A exists.
                IF EXISTS (
                    SELECT 1 FROM public.redirects
                    WHERE to_path = NEW.from_path AND id IS DISTINCT FROM NEW.id
                ) THEN
                    RAISE EXCEPTION USING ERRCODE = '23514',
                        MESSAGE = 'a redirect source is already the destination of another redirect';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER redirects_no_chain_trigger
            BEFORE INSERT OR UPDATE OF from_path, to_path ON public.redirects
            FOR EACH ROW EXECUTE FUNCTION public.enforce_redirect_no_chain();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS redirects_no_chain_trigger ON public.redirects');
        DB::statement('DROP FUNCTION IF EXISTS public.enforce_redirect_no_chain()');

        Schema::dropIfExists('redirects');
        Schema::dropIfExists('article_product');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('article_categories');
    }
};
