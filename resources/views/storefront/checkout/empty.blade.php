@extends('storefront.layouts.app')
@section('title', 'Panier vide - DigiTrove')
@section('content')
    <section class="catalog-header"><h1>Votre panier est vide</h1></section>
    <p>Ajoutez un article avant de commander.</p>
    <p><a href="{{ route('products.index') }}">Parcourir le catalogue</a></p>
@endsection
