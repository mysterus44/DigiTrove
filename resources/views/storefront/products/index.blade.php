@extends('storefront.layouts.app')

@section('title', 'Catalogue - DigiTrove')
@section('meta_description', 'Catalogue DigiTrove de logiciels, formations, ebooks et ressources digitales en XOF.')

@section('content')
    <section class="catalog-header">
        <p class="eyebrow">Catalogue</p>
        <h1>Toutes les offres</h1>
        <p>Des produits digitaux publies, proposes uniquement en XOF.</p>
    </section>

    <section class="catalogue-section catalog-list" aria-label="Produits publies">
        <div class="product-grid">
            @forelse ($products as $product)
                @include('storefront.components.product-card', ['product' => $product])
            @empty
                <div class="empty-state">Aucun produit n'est publie pour le moment.</div>
            @endforelse
        </div>

        <div class="pagination-wrap">{{ $products->links() }}</div>
    </section>
@endsection
