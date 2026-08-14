@extends('storefront.layouts.app')

@section('title', 'DigiTrove - Produits digitaux en XOF')

@section('content')
    <section class="hero-section" aria-labelledby="hero-title">
        <div class="hero-copy">
            <p class="eyebrow">Boutique digitale autonome</p>
            <h1 id="hero-title">DigiTrove</h1>
            <p class="hero-lede">Des logiciels, formations, ebooks et ressources utiles, presentes clairement et proposes en XOF.</p>
            <div class="hero-actions">
                <a class="button button-primary" href="{{ route('products.index') }}">Explorer le catalogue</a>
            </div>
            <dl class="hero-metrics" aria-label="Indicateurs catalogue">
                <div><dt>{{ $products->count() }}</dt><dd>offres publiees</dd></div>
                <div><dt>{{ number_format($products->sum('sales_count'), 0, ',', ' ') }}</dt><dd>ventes historisees</dd></div>
                <div><dt>XOF</dt><dd>devise unique</dd></div>
            </dl>
        </div>

        <div class="hero-showcase" aria-label="Apercu produits">
            @forelse ($products->take(3) as $product)
                <a class="showcase-card" href="{{ route('products.show', $product) }}">
                    @if ($product->coverUrl())
                        <img src="{{ $product->coverUrl() }}" alt="{{ $product->name }}">
                    @else
                        <span class="product-placeholder" aria-hidden="true">DT</span>
                    @endif
                    <div>
                        <p>{{ ucfirst($product->type->value) }}</p>
                        <strong>{{ $product->name }}</strong>
                        <span>{{ number_format($product->activeXofPrice->price_minor, 0, ',', ' ') }} XOF</span>
                    </div>
                </a>
            @empty
                <div class="empty-state">Le catalogue publie sera bientot disponible.</div>
            @endforelse
        </div>
    </section>

    <section class="benefits-section" aria-labelledby="benefits-title">
        <div class="section-heading">
            <p class="eyebrow">Produits digitaux</p>
            <h2 id="benefits-title">Choisir vite, comprendre exactement ce que vous achetez</h2>
        </div>
        <div class="benefit-grid">
            <article><span class="icon-pill">01</span><h3>Offres lisibles</h3><p>Chaque fiche presente le type, le contenu et le prix sans conversion cachee.</p></article>
            <article><span class="icon-pill">02</span><h3>Prix en XOF</h3><p>Une seule devise pour cette premiere boutique, affichee en entiers sans approximation.</p></article>
            <article><span class="icon-pill">03</span><h3>Livrables prives</h3><p>Les fichiers restent hors du web public et ne sont jamais exposes par le catalogue.</p></article>
        </div>
    </section>

    <section id="catalogue" class="catalogue-section" aria-labelledby="catalogue-title">
        <div class="section-heading split-heading">
            <div><p class="eyebrow">Selection</p><h2 id="catalogue-title">Produits publies</h2></div>
            <a class="text-link" href="{{ route('products.index') }}">Voir tout le catalogue</a>
        </div>
        <div class="product-grid">
            @forelse ($products as $product)
                @include('storefront.components.product-card', ['product' => $product])
            @empty
                <div class="empty-state">Aucun produit n'est publie pour le moment.</div>
            @endforelse
        </div>
    </section>

    @if ($categories->isNotEmpty())
        <section class="categories-section" aria-labelledby="categories-title">
            <div class="section-heading"><p class="eyebrow">Categories</p><h2 id="categories-title">Explorer par univers</h2></div>
            <div class="category-grid">
                @foreach ($categories as $category)
                    <article class="category-tile">
                        <div class="category-monogram" aria-hidden="true">{{ strtoupper(substr($category->name, 0, 1)) }}</div>
                        <div><h3>{{ $category->name }}</h3><p>{{ $category->products_count }} {{ $category->products_count > 1 ? 'produits' : 'produit' }}</p></div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <section id="avis" class="reviews-section" aria-labelledby="reviews-title">
        <div class="section-heading"><p class="eyebrow">Paroles clients historiques</p><h2 id="reviews-title">Des ressources qui servent vraiment</h2></div>
        <div class="review-grid">
            @foreach ($reviews as $review)
                <article><p class="stars" aria-label="5 etoiles">★★★★★</p><blockquote>{{ $review['quote'] }}</blockquote><div><strong>{{ $review['name'] }}</strong><span>{{ $review['role'] }}</span></div></article>
            @endforeach
        </div>
    </section>

    <section id="newsletter" class="cta-section" aria-labelledby="cta-title">
        <div><p class="eyebrow">DigiTrove</p><h2 id="cta-title">Trouvez votre prochaine ressource digitale</h2><p>Comparez les offres, explorez leur contenu et choisissez celle qui correspond a votre projet.</p></div>
        <a class="button button-primary" href="{{ route('products.index') }}">Parcourir les produits</a>
    </section>
@endsection
