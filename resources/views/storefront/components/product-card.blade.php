@php($price = $product->activeXofPrice)

<article class="product-card">
    <a class="product-media" href="{{ route('products.show', $product) }}">
        @if ($product->coverUrl())
            <img src="{{ $product->coverUrl() }}" alt="{{ $product->name }}">
        @else
            <div class="product-placeholder" aria-hidden="true">DT</div>
        @endif
        <span>{{ ucfirst($product->type->value) }}</span>
    </a>
    <div class="product-body">
        <p class="product-type">{{ $product->categories->pluck('name')->join(' · ') ?: ucfirst($product->type->value) }}</p>
        <h3><a href="{{ route('products.show', $product) }}">{{ $product->name }}</a></h3>
        <p>{{ $product->short_description }}</p>
        <div class="price-row">
            <strong>{{ number_format($price->price_minor, 0, ',', ' ') }} XOF</strong>
            @if ($price->compare_at_price_minor !== null && $price->compare_at_price_minor > $price->price_minor)
                <span>{{ number_format($price->compare_at_price_minor, 0, ',', ' ') }} XOF</span>
            @endif
        </div>
        <div class="card-footer">
            <small>{{ number_format($product->sales_count, 0, ',', ' ') }} ventes</small>
            <a class="button button-secondary button-compact" href="{{ route('products.show', $product) }}">Voir le produit</a>
        </div>
    </div>
</article>
