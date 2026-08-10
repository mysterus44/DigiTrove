<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Reprise du panier</title>
    <style nonce="{{ $nonce }}">
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: sans-serif; background: #f7f7f5; color: #171717; }
        main { width: min(32rem, calc(100% - 2rem)); }
        p { line-height: 1.5; }
    </style>
</head>
<body>
<main aria-live="polite">
    <h1>Reprise de votre panier</h1>
    <p id="status">Vérification de votre lien…</p>
</main>
{{-- Fragment bridge. The capability lives in `location.hash`, which the browser never
     puts in the request line and never sends in `Referer`. It is erased from history
     BEFORE anything else happens, then handed to the server exactly once in a POST
     body — never a query string, never any browser storage. --}}
<script nonce="{{ $nonce }}">
(() => {
    'use strict';

    const cart = @json($cartPublicId);
    const fragment = window.location.hash.slice(1);

    // Erase FIRST: from here on the capability exists only in this closure, so a later
    // failure cannot leave it in the address bar or in session history.
    window.history.replaceState(null, '', window.location.pathname);

    const status = document.getElementById('status');
    const unavailable = () => { status.textContent = 'Ce lien de reprise n’est plus valable.'; };

    let capability = null;
    try {
        capability = new URLSearchParams(fragment).get('c');
    } catch (e) {
        capability = null;
    }

    // Bounded shape check before anything leaves the browser.
    if (typeof capability !== 'string' || !/^[0-9a-f]{64}$/.test(capability)) {
        return unavailable();
    }

    const token = document.querySelector('meta[name="csrf-token"]');
    const body = new URLSearchParams();
    body.set('cart', cart);
    body.set('c', capability);
    capability = null;

    fetch('/cart/resume', {
        method: 'POST',
        headers: {
            'Accept': 'text/html',
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-TOKEN': token ? token.getAttribute('content') : ''
        },
        body: body.toString(),
        credentials: 'same-origin',
        redirect: 'follow'
    }).then((response) => {
        if (!response.ok) {
            throw new Error('unavailable');
        }
        // A clean, neutral URL. The capability is not part of it.
        window.location.replace(response.url);
    }).catch(unavailable);
})();
</script>
</body>
</html>
