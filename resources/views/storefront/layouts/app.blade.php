<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ trim($__env->yieldContent('meta_description', 'DigiTrove, catalogue autonome de produits digitaux en XOF.')) }}">
    <title>{{ trim($__env->yieldContent('title', 'DigiTrove')) }}</title>

    {{-- P7 SEO. Canonical on EVERY page: without it, pagination, tracking parameters and
         trailing-slash variants each look like a separate page to a crawler and split the
         ranking of one piece of content across several URLs. The default is the current URL
         WITHOUT its query string, which is the canonical form for every public page here. --}}
    {{-- ⚠️ RIEN QUI DÉPENDE DE L'URL DEMANDÉE SUR UNE PAGE D'ERREUR. Le contrat D-064 exige
         qu'un 404 pour la commande d'autrui soit IDENTIQUE OCTET POUR OCTET à celui d'une
         commande inexistante : la page ne doit jamais devenir un oracle d'existence. Un
         canonical construit sur `url()->current()` y réinjecte l'identifiant demandé et
         casse cette garantie.
         Et c'est aussi la bonne réponse SEO : déclarer canonique une URL qui n'existe pas
         invite un crawler à l'indexer. Une page d'erreur n'a pas de canonique. --}}
    @unless ($__env->yieldContent('suppress_canonical'))
        <link rel="canonical" href="{{ trim($__env->yieldContent('canonical', url()->current())) }}">
    @endunless

    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:title" content="{{ trim($__env->yieldContent('title', 'DigiTrove')) }}">
    <meta property="og:description" content="{{ trim($__env->yieldContent('meta_description', 'DigiTrove, catalogue autonome de produits digitaux en XOF.')) }}">
    @unless ($__env->yieldContent('suppress_canonical'))
        <meta property="og:url" content="{{ trim($__env->yieldContent('canonical', url()->current())) }}">
    @endunless
    <meta property="og:site_name" content="DigiTrove">
    @hasSection('og_image')
        <meta property="og:image" content="@yield('og_image')">
    @endif
    <meta name="twitter:card" content="summary_large_image">

    {{-- Structured data, rendered server-side and escaped. Never `{!! !!}`: a title with a
         quote would otherwise break out of the JSON and inject script content. --}}
    @hasSection('json_ld')
        <script type="application/ld+json">@yield('json_ld')</script>
    @endif

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
            <a href="{{ route('blog.index') }}">Blog</a>
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
