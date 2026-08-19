@extends('storefront.layouts.app')

@section('title', $article->seoTitle().' - DigiTrove')
@section('meta_description', $article->seoDescription())
@section('canonical', $article->canonical_url ?: route('blog.show', $article))
@section('og_type', 'article')
@if ($article->cover_image_url)
    @section('og_image', $article->cover_image_url)
@endif

@php
    // ⚠️ BUILT IN A @php BLOCK, NOT IN `@json(...)`. Blade's directive-argument parser does
    // not balance brackets across lines: a multi-line array passed to `@json()` is silently
    // TRUNCATED mid-literal and the compiled view stops parsing. Measured, not assumed.
    //
    // The HEX flags are the security boundary: `<`, `>`, `&`, `'` and `"` are emitted as
    // unicode escapes, so a title containing a closing script tag cannot close the block it
    // lives in. Nulls are dropped rather than emitted — `"author": null` is invalid
    // structured data, not a neutral value.
    $articleJsonLd = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $article->title,
        'description' => $article->seoDescription(),
        'image' => $article->cover_image_url ? [$article->cover_image_url] : null,
        'datePublished' => $article->published_at?->toAtomString(),
        'dateModified' => $article->updated_at?->toAtomString(),
        'author' => $article->author_name ? ['@type' => 'Person', 'name' => $article->author_name] : null,
        'publisher' => ['@type' => 'Organization', 'name' => 'DigiTrove'],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => route('blog.show', $article)],
    ], static fn ($value): bool => $value !== null);

    // ⚠️ NEVER an `aggregateRating`: no reviews table exists — P2 paused it — so a structured
    // rating would be backed by nothing. That is a Google penalty, not a feature.
@endphp

@section('json_ld'){!! json_encode($articleJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}@endsection

@section('content')
    <article class="article-detail">
        <header class="catalog-header">
            <p class="eyebrow">
                @if ($article->category)
                    <a href="{{ route('blog.category', $article->category) }}">{{ $article->category->name }}</a>
                @else
                    Article
                @endif
            </p>
            <h1>{{ $article->title }}</h1>
            <p class="article-meta">
                @if ($article->author_name)
                    <span>Par {{ $article->author_name }}</span> ·
                @endif
                <time datetime="{{ $article->published_at?->toDateString() }}">
                    {{ $article->published_at?->translatedFormat('d F Y') }}
                </time>
                @if ($article->reading_minutes)
                    · <span>{{ $article->reading_minutes }} min de lecture</span>
                @endif
            </p>
        </header>

        @if ($article->cover_image_url)
            <img src="{{ $article->cover_image_url }}" alt="" class="article-hero">
        @endif

        {{-- The ONLY unescaped output on the public site. It is safe because the HTML was
             produced by CommonMark with `html_input: strip` and `allow_unsafe_links: false`
             from stored MARKDOWN: no raw tag survives the render, and no `javascript:` href
             is ever attached. Rendering at read time rather than storing HTML means a future
             sanitizer fix ships in one deploy instead of a rewrite of every row. --}}
        <div class="article-body">{!! \App\Support\ArticleContent::toHtml($article->body) !!}</div>

        @if ($article->products->isNotEmpty())
            <section class="catalogue-section" aria-label="Produits lies a cet article">
                <h2>Les produits dont parle cet article</h2>
                <div class="product-grid">
                    @foreach ($article->products as $product)
                        @include('storefront.components.product-card', ['product' => $product])
                    @endforeach
                </div>
            </section>
        @endif

        @if ($related->isNotEmpty())
            <section class="catalogue-section" aria-label="Articles lies">
                <h2>A lire aussi</h2>
                <ul class="blog-related-list">
                    @foreach ($related as $item)
                        <li><a href="{{ route('blog.show', $item) }}">{{ $item->title }}</a></li>
                    @endforeach
                </ul>
            </section>
        @endif
    </article>
@endsection
