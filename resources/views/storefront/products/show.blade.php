@extends('storefront.layouts.app')

@section('title', ($product->meta_title ?: $product->name).' - DigiTrove')
@section('meta_description', $product->meta_description ?: $product->short_description)

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
                    <span>{{ number_format($product->activeXofPrice->compare_at_price_minor, 0, ',', ' ') }} XOF</span>
                @endif
            </div>
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
