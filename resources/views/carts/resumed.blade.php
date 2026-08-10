<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Panier repris</title>
</head>
<body>
<main>
    @if ($resumed)
        <h1>Votre panier a été retrouvé</h1>
        {{-- Deliberately says nothing about the cart's contents, its owner, or the
             reminder that produced the link. The storefront that would display and
             modify the cart does not exist yet (D-056 precondition). --}}
        <p>La reprise est validée pour cette session.</p>
    @else
        <h1>Reprise indisponible</h1>
        <p>Ce lien de reprise n’est plus valable.</p>
    @endif
</main>
</body>
</html>
