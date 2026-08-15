{{-- Panier invité. Aucun coupon, aucun checkout, aucune collecte d'identité : ce gate
     s'arrête au panier consultable. Les montants sont des entiers XOF (exposant 0),
     formatés comme dans le catalogue — aucune division, aucun flottant. --}}
@extends('storefront.layouts.app')

@section('title', 'Votre panier - DigiTrove')
@section('meta_description', 'Votre panier DigiTrove.')

@section('content')
    <section class="catalog-header">
        <p class="eyebrow">Panier</p>
        <h1>Votre panier</h1>
    </section>

    @if ($lines === [])
        <section class="cart-empty">
            <p>Votre panier est vide.</p>
            <p><a href="{{ route('products.index') }}">Parcourir le catalogue</a></p>
        </section>
    @else
        <ul class="cart-lines">
            @foreach ($lines as $line)
                <li class="cart-line">
                    @if ($line['available'])
                        <a href="{{ route('products.show', $line['product']->slug) }}">{{ $line['product']->name }}</a>
                        <strong>{{ number_format($line['priceMinor'], 0, ',', ' ') }} {{ $currency }}</strong>
                    @else
                        {{-- Ligne volontairement muette : ni nom, ni prix, ni motif. Une
                             dépublication, une suppression et un prix retiré doivent être
                             indiscernables, sinon le panier deviendrait une fenêtre sur un
                             produit qu'un administrateur a retiré. --}}
                        <span class="cart-line-unavailable">Article indisponible</span>
                    @endif

                    <form method="POST" action="{{ route('cart.items.destroy', $line['slug']) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit">Retirer cet article du panier</button>
                    </form>
                </li>
            @endforeach
        </ul>

        {{-- Les lignes indisponibles ne sont pas comptées : le total ne promet que ce qui
             est réellement achetable aujourd'hui. --}}
        <p class="cart-total"><strong>Total : {{ number_format($totalMinor, 0, ',', ' ') }} {{ $currency }}</strong></p>

        <p><a href="{{ route('checkout.show') }}">Passer la commande</a></p>
        <p><a href="{{ route('products.index') }}">Continuer mes achats</a></p>
    @endif
@endsection
