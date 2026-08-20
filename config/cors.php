<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (H2.6)
    |--------------------------------------------------------------------------
    |
    | Publié pour REFUSER, pas pour autoriser. Sans ce fichier, les défauts du
    | framework s'appliquaient — `allowed_origins => ['*']` sur `api/*` — et cette
    | permission n'était visible nulle part dans le dépôt.
    |
    | ⚠️ RIEN N'ÉTAIT EXPLOITABLE, ET CE N'EST PAS LA RAISON DE CE FICHIER.
    | Mesuré : `supports_credentials` valait déjà `false` (l'en-tête
    | `Access-Control-Allow-Credentials` est absent des réponses), et le cookie de
    | tentative de téléchargement est posé en `SameSite=Strict`, donc un navigateur
    | ne l'émet jamais en contexte cross-site. Deux barrières indépendantes
    | neutralisaient déjà `*`.
    |
    | Le problème est ailleurs : une permission large qui ne sert à rien reste de
    | la surface qu'il faudrait ré-auditer à chaque changement, et elle ne
    | subsistait que par un accident heureux de configuration. Même principe que
    | `SecurityHeaders` en H2.1 — un plancher explicite, jamais un défaut permissif
    | qu'on laisse ouvert parce que quelque chose d'autre le rattrape.
    |
    | LES CINQ ROUTES `api/*` DU DÉPÔT, ET POURQUOI AUCUNE N'EN A BESOIN :
    |
    |   POST|GET  api/webhooks/payments/cinetpay   \
    |   POST|GET  api/webhooks/payments/geniuspay   > serveur-à-serveur : aucun
    |                                                 navigateur, donc CORS ne les
    |                                                 concerne pas du tout.
    |   POST      api/downloads/{id}/authorize     appelé par le navigateur, mais
    |                                              DEPUIS LA MÊME ORIGINE — vérifié :
    |                                              `resources/views/downloads/exchange.blade.php`
    |                                              utilise un chemin relatif.
    |
    | ⚠️ POINT DE RÉOUVERTURE. Le jour où un frontend séparé, une application
    | mobile ou une intégration tierce doit appeler cette API, c'est ICI que la
    | décision se prend — délibérément, origine par origine. `allowed_origins_patterns`
    | reste vide pour la même raison : un motif est plus facile à élargir par
    | inadvertance qu'une liste nominative.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Aucune origine tierce. Le same-origin n'est pas concerné par CORS.
    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Ne jamais passer à `true` sans rouvrir `allowed_origins` de façon nominative :
    // la combinaison `*` + credentials est refusée par les navigateurs, et une liste
    // nominative + credentials est précisément ce qui rend un vol de session possible
    // depuis un site tiers.
    'supports_credentials' => false,

];
