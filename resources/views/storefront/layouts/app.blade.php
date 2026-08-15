<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ trim($__env->yieldContent('meta_description', 'DigiTrove, catalogue autonome de produits digitaux en XOF.')) }}">
    <title>{{ trim($__env->yieldContent('title', 'DigiTrove')) }}</title>

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <style>{!! file_get_contents(resource_path('css/app.css')) !!}</style>
    @endif
</head>
<body>
    <header class="site-header" aria-label="Navigation principale">
        <a class="brand" href="{{ route('storefront.home') }}" aria-label="DigiTrove accueil">
            <img src="{{ asset('images/digitrove/logos/digitrove-2.0.png') }}" alt="" class="brand-mark">
            <span>DigiTrove</span>
        </a>

        <nav class="nav-links" aria-label="Sections">
            <a href="{{ route('products.index') }}">Catalogue</a>
            <a href="{{ route('cart.show') }}">Panier</a>
            <a href="{{ route('storefront.home') }}#avis">Avis</a>
            <a href="{{ route('storefront.home') }}#newsletter">Acces</a>
        </nav>
    </header>

    <main id="top">
        @yield('content')
    </main>
</body>
</html>
