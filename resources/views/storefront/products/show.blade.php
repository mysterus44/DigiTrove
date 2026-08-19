@extends('storefront.layouts.app')

@section('title', ($product->meta_title ?: $product->name).' - DigiTrove')
@section('meta_description', $product->meta_description ?: $product->short_description)
@section('og_type', 'product')
@if ($product->coverUrl())
    @section('og_image', $product->coverUrl())
@endif

@php
    // Même contrainte qu'en blog/show : `@json(...)` tronque un tableau multi-lignes, donc le
    // tableau est construit ici et encodé sur une seule ligne. Les drapeaux HEX empêchent un
    // nom de produit contenant une balise fermante de refermer le bloc qui le porte.
    //
    // ⚠️ AUCUN `aggregateRating` : aucune table `reviews` n'existe (P2 l'a mise en pause), donc
    // une note structurée ne serait adossée à rien — une pénalité Google, pas une fonctionnalité.
    //
    // Le prix et la devise viennent du PRIX RÉEL AFFICHÉ (`activeXofPrice`), jamais d'un `XOF`
    // codé en dur : le jour où une seconde devise existe, le balisage suit sans être réécrit.
    $productJsonLd = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->name,
        'description' => $product->meta_description ?: $product->short_description,
        'image' => $product->coverUrl() ? [$product->coverUrl()] : null,
        'sku' => $product->slug,
        'brand' => ['@type' => 'Brand', 'name' => 'DigiTrove'],
        'offers' => [
            '@type' => 'Offer',
            'url' => route('products.show', $product),
            'price' => (string) $product->activeXofPrice->price_minor,
            'priceCurrency' => $product->activeXofPrice->currency,
            'availability' => 'https://schema.org/InStock',
        ],
    ], static fn ($value): bool => $value !== null);
@endphp

@section('json_ld'){!! json_encode($productJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}@endsection

@section('content')
    <section class="product-detail" aria-labelledby="product-title">
        <div class="product-detail-media">
            @if ($product->coverUrl())
                <img src="{{ $product->coverUrl() }}" alt="{{ $product->name }}">
            @else
                <div class="product-placeholder product-placeholder-large" aria-hidden="true">DT</div>
            @endif
        </div>
        <div class="product-detail-copy">
            <a class="text-link" href="{{ route('products.index') }}">Retour au catalogue</a>
            <p class="eyebrow">{{ $product->categories->pluck('name')->join(' · ') ?: ucfirst($product->type->value) }}</p>
            <h1 id="product-title">{{ $product->name }}</h1>
            <p class="product-detail-lede">{{ $product->short_description }}</p>
            <div class="detail-price">
                <strong>{{ number_format($product->activeXofPrice->price_minor, 0, ',', ' ') }} XOF</strong>
                @if ($product->activeXofPrice->compare_at_price_minor !== null && $product->activeXofPrice->compare_at_price_minor > $product->activeXofPrice->price_minor)
                    {{-- <s> porte la semantique « ancien prix » ; un <span> nu se lisait comme un
                         second prix courant. Le libelle visuellement cache nomme ce qu'il est. --}}
                    <s><span class="sr-only">Ancien prix : </span>{{ number_format($product->activeXofPrice->compare_at_price_minor, 0, ',', ' ') }} XOF</s>
                @endif
            </div>

            {{-- Ajout au panier : POST, donc protege par le CSRF web standard. La
                 quantite est fixee a 1 et n'est pas saisissable ; un second clic ne
                 duplique rien, l'unicite (cart_id, product_id) le garantit. --}}
            <form method="POST" action="{{ route('cart.items.store', $product->slug) }}" class="detail-add">
                @csrf
                <button type="submit">Ajouter {{ $product->name }} au panier</button>
            </form>
        </div>
    </section>

    @if ($product->long_description)
        <section class="product-description" aria-labelledby="description-title">
            <p class="eyebrow">A propos</p>
            <h2 id="description-title">Ce que contient cette offre</h2>
            <p>{{ $product->long_description }}</p>
        </section>
    @endif
@endsection
