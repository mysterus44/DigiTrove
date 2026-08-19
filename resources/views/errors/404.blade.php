{{-- 404 aux couleurs du storefront. Le contenu est volontairement IDENTIQUE pour une
     commande qui n'existe pas et pour celle d'un autre acheteur : la page ne doit jamais
     devenir un oracle d'existence (D-064). Aucune donnee de commande, aucun detail
     technique, seulement une sortie. --}}
@extends('storefront.layouts.app')

@section('title', 'Page introuvable - DigiTrove')

{{-- Supprime canonical et og:url : ils porteraient l'URL demandee et rendraient deux
     404 distinguables — exactement l'oracle que ce fichier existe pour fermer. --}}
@section('suppress_canonical', '1')

@section('content')
    <section class="catalog-header">
        <p class="eyebrow">Introuvable</p>
        <h1>Cette page n'existe pas</h1>
    </section>

    <section class="cart-empty">
        <p>Le lien que vous avez suivi ne correspond à aucune page disponible.</p>
        <p><a class="button button-secondary" href="{{ route('products.index') }}">Parcourir le catalogue</a></p>
    </section>
@endsection
