{{-- État lu en base. Cette page n'appelle AUCUNE logique de confirmation : seuls le
     webhook signé et le contre-appel fournisseur peuvent faire passer une commande à
     `paid` (D-034). Les paramètres de retour du navigateur ne sont jamais autoritatifs. --}}
@extends('storefront.layouts.app')

@section('title', 'Votre commande - DigiTrove')

@section('content')
    <section class="catalog-header">
        <p class="eyebrow">Commande</p>
        {{-- `order_number` est affiché, jamais mis dans une URL : il est lisible et
             dictable par téléphone, donc devinable. --}}
        <h1>Commande {{ $order->order_number }}</h1>
    </section>

    <p class="checkout-status">{{ $status }}</p>
    <p><a href="{{ route('products.index') }}">Retour au catalogue</a></p>
@endsection
