@extends('storefront.layouts.app')

@section('title', $category ? $category->name.' - Blog DigiTrove' : 'Blog - DigiTrove')
@section('meta_description', $category
    ? 'Articles DigiTrove de la categorie '.$category->name.'.'
    : 'Guides, analyses et retours d\'experience DigiTrove sur les produits digitaux.')

@section('content')
    <section class="catalog-header">
        <p class="eyebrow">Blog</p>
        {{-- A single <h1> per page: the category name when filtering, the blog name otherwise. --}}
        <h1>{{ $category?->name ?? 'Le blog DigiTrove' }}</h1>
        <p>Guides et analyses sur les produits digitaux.</p>
    </section>

    @if ($categories->isNotEmpty())
        <nav class="catalogue-section" aria-label="Categories du blog">
            <ul class="blog-category-list">
                <li>
                    <a href="{{ route('blog.index') }}" @if (! $category) aria-current="page" @endif>Tous</a>
                </li>
                @foreach ($categories as $item)
                    <li>
                        <a href="{{ route('blog.category', $item) }}"
                           @if ($category && $category->is($item)) aria-current="page" @endif>{{ $item->name }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif

    <section class="catalogue-section catalog-list" aria-label="Articles publies">
        <div class="article-grid">
            @forelse ($articles as $article)
                <article class="article-card">
                    @if ($article->cover_image_url)
                        <img src="{{ $article->cover_image_url }}" alt="" class="article-cover" loading="lazy">
                    @endif
                    <p class="eyebrow">{{ $article->category?->name ?? 'Article' }}</p>
                    <h2><a href="{{ route('blog.show', $article) }}">{{ $article->title }}</a></h2>
                    @if ($article->excerpt)
                        <p>{{ $article->excerpt }}</p>
                    @endif
                    <p class="article-meta">
                        <time datetime="{{ $article->published_at?->toDateString() }}">
                            {{ $article->published_at?->translatedFormat('d F Y') }}
                        </time>
                        @if ($article->reading_minutes)
                            <span>· {{ $article->reading_minutes }} min de lecture</span>
                        @endif
                    </p>
                </article>
            @empty
                <div class="empty-state">Aucun article n'est publie pour le moment.</div>
            @endforelse
        </div>

        <div class="pagination-wrap">{{ $articles->links() }}</div>
    </section>
@endsection
