# Audit de l'application Laravel actuelle

**Phase 0 — Livrable B**

**Date de constat :** 21 août 2026

**Branche canonique :** `p0-foundations-laravel13`

**HEAD :** `2f1f6e28bbcc7e3b232c94e316317d9338a800c7`

## 1. Baseline vérifiée

| Élément | Constat |
|---|---|
| Git | branche locale alignée sur `origin/p0-foundations-laravel13` (`0` ahead, `0` behind) |
| État non suivi préexistant | `DigiTrove-Ancien/`, `_to_delete/`, `PROMPT_CODEX_REFONTE.md` |
| Framework | Laravel **13.19.0**, PHP 8.3+ |
| Admin | Filament 5 |
| Base | PostgreSQL 16, conteneur sain, 53 migrations appliquées |
| Cache / files | Redis 7 sain ; stockage privé configuré pour les livrables |
| Serveur audité | `http://localhost:8000`, conteneur `digitrove-php:dev` |
| Routes | 72 au total, dont 29 sous `/admin` et 5 sous `/api` |
| Inventaire applicatif | 33 modèles, 87 fichiers de services, 17 contrôleurs, 12 vues storefront |
| Tests | 208 fichiers PHP, 1 625 déclarations Pest avant expansion de datasets |

La suite Pest complète est lancée dans un processus Docker séparé avec `memory_limit=3G`, conformément au protocole de passation. Son résultat final est ajouté à la section 9 dès la fin du run.

## 2. Architecture générale

Le dépôt est une vraie application Laravel orientée services :

```text
routes/web.php + routes/api.php
        ↓
contrôleurs minces / Form Requests ciblées / Policies
        ↓
services métier (prix, commande, paiement, livraison, CRM, analytics, affiliation)
        ↓
Eloquent + autorités PostgreSQL + jobs/events/listeners
        ↓
Filament 5 et Blade storefront
```

Les points structurants du projet sont effectivement présents : argent en `BIGINT`, prix et noms figés dans les lignes de commande, tokens de téléchargement hachés, fichiers privés, webhooks signés et contre-vérifiés, événements analytiques sans FK chaude, rollups, Policies et traitements asynchrones.

## 3. Base de données et modèles

### 3.1 Migrations par domaine

Les 53 migrations appliquées couvrent :

| Domaine | Tables / autorités principales |
|---|---|
| Identité | `users`, `customer_profiles`, `visitors`, extension `citext` |
| Catalogue | `categories`, `products`, `product_prices`, `product_files`, pivots catégories et bundles |
| Commerce | coupons, paniers, commandes, lignes, snapshots bundle, paiements, webhooks, remboursements |
| Livraison | `download_grants`, `download_logs`, privilèges PostgreSQL dédiés |
| Analytique | `events` partitionnée, sessions, rollups quotidiens, fonctions et rôles d'exécution |
| CRM | contacts, consentements append-only, attribution commandes, rollups par devise, segments versionnés, exports privés |
| Paniers dormants | abandon, rappels, tentatives et liens de reprise |
| Affiliation | politiques, affiliés, codes, touches, attributions, commissions, ledger, payouts, cycle de vie |
| Blog / SEO | catégories d'articles, articles, relations produits, redirections |
| Opérations | réconciliation webhooks et `failed_jobs` |

### 3.2 Modèles

Les 33 modèles matérialisent bien les agrégats catalogue, commerce, livraison, CRM, affiliation et blog. Les relations sensibles sont explicites et la logique financière reste dans les services. Les modèles publics `Product` et `Article` possèdent des scopes de publication fermés ; un slug de brouillon n'est pas servi directement.

### 3.3 État de la base de développement au moment de l'audit

| Table | Lignes constatées avant le scénario panier |
|---|---:|
| `users` | 3 |
| `visitors` | 6 |
| `products` | 5 |
| `product_prices` | 5 |
| `categories` | 4 |
| `carts` | 10 |
| `orders` | 7 |
| `payments` | 4 |
| `articles` | 0 |
| `article_categories` | 0 |
| `affiliates` | 0 |

Cette base est une base locale de travail, pas une source de vérité métier. Le parcours réel produit → panier → checkout réalisé pour l'audit a ensuite créé/réutilisé un panier invité de session ; aucun checkout n'a été soumis et aucun fournisseur n'a été appelé.

Deux divergences de contenu sont visibles :

- `Pack Livres` vaut **300 XOF** en base alors que la source d'import et le legacy autoritaire valent **3 500 XOF** ;
- `Pack +200 logiciels` affiche 192 ventes dans la source Laravel contre **193** dans `DigiTrove-Ancien`.

Elles doivent être arbitrées et corrigées par un import/rejeu contrôlé, jamais par une édition silencieuse en production.

## 4. Front-office Blade actuel

### 4.1 Routes et contrôleurs

| Surface | Routes | Contrôleur / autorité |
|---|---|---|
| Accueil | `GET /` | `CatalogController` |
| Catalogue | `GET /products`, `GET /products/{slug}` | `ProductController`, scope `published()` |
| Panier invité | `GET /cart`, `POST /cart/items/{slug}`, `DELETE /cart/items/{slug}` | `CartController` + `GuestCartService` |
| Checkout invité | `GET/POST /checkout`, retour, statut | `CheckoutController` + orchestrateur, session propriétaire |
| Blog | index, catégorie, article | `BlogController`, publication fermée |
| SEO | `/sitemap.xml`, `/robots.txt` | `SitemapController` |
| Affiliation | `/r/{code}`, `POST /affiliate/code` | capture uniforme et throttlée |
| Analytics | consentement + ingestion événement | mêmes origines, consentement courant, throttle |
| Reprise panier | bootstrap fragment + échange POST | capacité hachée et réponse uniforme |
| Téléchargement | échange, autorisation API, streaming GET/HEAD | grants privés et quota atomique |

Le front est serveur, léger et sans framework JavaScript applicatif : `resources/js/app.js` est vide. C'est positif pour la performance, mais aucun composant ne pilote actuellement le consentement analytics, le menu mobile, la recherche catalogue ou les états avancés.

### 4.2 Vues

Les 12 vues storefront couvrent l'accueil, le catalogue, la fiche produit, le panier, trois états checkout, le blog, l'article, le composant carte, le layout et le sitemap.

Forces :

- Blade échappe les contenus ;
- pagination serveur ;
- eager loading sur produits/catégories/prix ;
- canonical, Open Graph et JSON-LD produit/article ;
- brouillons et produits non tarifés exclus ;
- formulaires panier/checkout protégés par CSRF ;
- retour navigateur de paiement explicitement non autoritatif ;
- statut de commande lié à la session et non mis en cache.

Manques fonctionnels ou éditoriaux :

- aucune page À propos, FAQ, CGV, confidentialité, livraison ou remboursement ;
- aucun footer public ;
- aucune navigation mobile : les liens desktop disparaissent sans menu de remplacement ;
- aucun compte client public ;
- aucune recherche, catégorie cliquable, filtre ou tri catalogue ;
- aucun contact, avis, newsletter ni consentement marketing visible ;
- aucun article publié dans la base locale, donc blog vide malgré le moteur complet ;
- seulement 3 avis historiques codés dans `resources/storefront/legacy-catalog.php`, contre 6 dans le JSON autoritaire ;
- nombreuses chaînes françaises sans accents (`presentes`, `proposes`, `publiees`, `Acces`, etc.).

## 5. Filament 5

### 5.1 Ressources CRUD

| Ressource | Écrans |
|---|---|
| Produits | liste, création, édition |
| Fichiers produits | liste, création, édition |
| Catégories | liste, création, édition |
| Articles | liste, création, édition |
| Catégories d'articles | liste, création, édition |
| Redirections SEO | liste, création, édition |

### 5.2 Pages métier

- dashboard analytique et widgets funnel/produits/ventes ;
- contacts CRM, segments et exports privés ;
- cycle de vie affiliés, politique du programme et versements administratifs.

### 5.3 Écart d'administration

Le domaine métier est beaucoup plus riche que l'interface : aucune ressource Filament ne gère directement commandes, lignes, paiements, remboursements, grants, journaux de téléchargement, utilisateurs ou consentements. L'ancien back-office n'offrait pas mieux pour les commandes, mais la refonte admin attendue ne peut pas être considérée terminée tant que ces opérations essentielles restent sans surface cohérente.

L'écran de connexion Filament est responsive et protégé. Aucun identifiant n'a été utilisé pendant cet audit ; les pages authentifiées sont donc cartographiées par le code, les routes et les tests, pas par une session opérateur.

## 6. Paiement, livraison et intégrations

| Intégration | État | Sécurité / limite |
|---|---|---|
| CinetPay | adaptateur réel, désactivé sans sélection | init, vérification serveur, webhook signé, montant revérifié |
| GeniusPay | adaptateur réel, sandbox-first, désactivé sans sélection | HMAC sur corps brut, fenêtre anti-rejeu, contre-appel ; remboursement total seulement faute de montant partiel documenté |
| PowerPay | scaffold documentaire uniquement | driver refusé avant tout HTTP |
| E-mail | transport gardé et désactivable | secrets par environnement, aucun marketing implicite |
| Stockage privé | implémenté | chemins privés, contrôleur, grants hachés, Range/HEAD, quotas et journaux |
| Refund provider | intake et autorités internes | pas d'invention d'API fournisseur non documentée |

`PAYMENT_DRIVER` est vide par défaut. Les deux webhooks existent simultanément, mais le driver non sélectionné échoue de manière fermée. Aucun secret n'est fourni par `.env.example`.

## 7. Sécurité observée

Points solides :

- Policies catalogue, blog, analytics, CRM et affiliation ;
- admin limité aux comptes admin actifs ;
- `SecurityHeaders` sur toutes les réponses, avec CSP plus stricte sur les capacités sensibles ;
- throttles webhooks, analytics, reprise panier et téléchargements ;
- fichiers privés et tokens seulement hachés ;
- paiement confirmé uniquement par webhook signé + contre-appel ;
- idempotence et collisions concurrentes testées ;
- exports CRM privés, audités et autorisés ;
- frontières PostgreSQL dédiées et inventaires fail-closed.

Points à garder sous surveillance :

- la CSP admin a besoin de `'unsafe-eval'` pour Alpine/Filament ; elle est correctement limitée à `/admin` ;
- la CSP publique garde `'unsafe-inline'`, donc n'est pas un filet anti-XSS ;
- l'analytics est techniquement présent mais sans UI de consentement ni émission front actuelle ;
- le login public client n'existe pas encore ;
- les surfaces administratives financières manquantes encourageraient sinon des opérations SQL manuelles.

## 8. Audit visuel actuel

Les 14 captures sont sous [`captures/laravel`](./captures/laravel/).

| Écran | Desktop | Mobile |
|---|---|---|
| Accueil | [`accueil-1440.png`](./captures/laravel/desktop/accueil-1440.png) | [`accueil-375.png`](./captures/laravel/mobile/accueil-375.png) |
| Catalogue | [`catalogue-1440.png`](./captures/laravel/desktop/catalogue-1440.png) | [`catalogue-375.png`](./captures/laravel/mobile/catalogue-375.png) |
| Produit | [`produit-1440.png`](./captures/laravel/desktop/produit-1440.png) | [`produit-375.png`](./captures/laravel/mobile/produit-375.png) |
| Blog | [`blog-1440.png`](./captures/laravel/desktop/blog-1440.png) | [`blog-375.png`](./captures/laravel/mobile/blog-375.png) |
| Panier | [`panier-1440.png`](./captures/laravel/desktop/panier-1440.png) | [`panier-375.png`](./captures/laravel/mobile/panier-375.png) |
| Checkout | [`checkout-1440.png`](./captures/laravel/desktop/checkout-1440.png) | [`checkout-375.png`](./captures/laravel/mobile/checkout-375.png) |
| Login admin | [`admin-anonyme-1440.png`](./captures/laravel/desktop/admin-anonyme-1440.png) | [`admin-anonyme-375.png`](./captures/laravel/mobile/admin-anonyme-375.png) |

### Critique design

Première impression : le Laravel actuel est propre, calme et crédible, mais il ressemble à une vitrine générique de démonstration. Le visiteur comprend l'offre, sans retrouver l'énergie, la proximité ou la personnalité bleu/orange de DigiTrove.

| Constat | Sévérité | Recommandation future |
|---|---|---|
| Lecture et CTA principal clairs | Positif | conserver la simplicité du premier écran |
| Logo presque illisible à petite taille | Modérée | déclinaison de marque adaptée au header |
| Grand espace mort vertical desktop | Modérée | rythme plus dense, preuve et réassurance au-dessus de la ligne de flottaison |
| Navigation absente sur mobile | Critique | menu accessible avec cible ≥ 44 px |
| Checkout visuellement cassé | Critique | réaligner l'artefact CSS, grille récap/formulaire, erreurs et confiance paiement |
| Textes sans accents | Modérée | révision éditoriale française complète |
| Boutons cartes à ~38 px de haut | Modérée | cibles tactiles d'au moins 44 px |
| Aucun footer ni repère légal | Critique conversion | footer institutionnel, support, garanties et liens légaux |
| Blog vide | Critique contenu/SEO | importer en brouillons, relire puis publier humainement |

## 9. Baseline de tests

- **Inventaire :** 208 fichiers de tests ; environ 1 625 déclarations `it()` / `test()` et 3 244 appels d'assertion repérés statiquement.
- **Commande de référence :** `php -d memory_limit=3G vendor/bin/pest` dans un conteneur isolé relié au PostgreSQL et Redis de test.
- **Résultat du 21 août 2026 :** **2 024 tests passés, 14 070 assertions, 0 échec**
  en 4 669,49 s (77 min 49 s), sur PostgreSQL et Redis réels de test ; sortie d'erreur vide.

La couverture est exceptionnellement profonde sur les contraintes PostgreSQL, rollbacks, rôles, transactions concurrentes, idempotence, paiement, livraison, analytics, CRM et affiliation. Elle est plus faible sur le rendu visuel réel : un test HTTP ne voit ni un bundle CSS périmé, ni une navigation mobile absente, ni Alpine bloqué par CSP.

## 10. Dettes prioritaires

### P0 — à traiter avant toute refonte visuelle

1. **Artefact Vite périmé.** `resources/css/app.css` a été modifié le 16 août, mais `public/build/manifest.json` et `app-C0LcnMwo.css` datent du 14 août. Le CSS construit (9 479 octets) ne contient pas les sélecteurs checkout présents dans la source (16 666 octets). Comme le layout privilégie le manifest dès qu'il existe, le checkout est servi sans ses styles.
2. **Intégrité du contenu catalogue.** Prix de `Pack Livres` à 300 XOF en base et compteur logiciels à 192, en désaccord avec la référence autoritaire.
3. **Blog vide.** Le schéma et l'importeur existent, mais les 19 articles n'ont pas été importés dans la base locale. L'import crée volontairement des brouillons.
4. **Régression d'origine checkout.** Le bug signalé (`form-action 'self'` bloquant une action sans port) n'est pas reproductible aujourd'hui : page et action valent toutes deux `http://localhost:8000`. Il reste à couvrir le cas `APP_URL`/proxy/cache de config mal aligné, idéalement en générant une action relative.

### P1 — parité visiteur

5. navigation mobile, footer, pages institutionnelles et états 404 ;
6. recherche/filtres catalogue et catégories navigables ;
7. vraie gestion des 6 avis, contact et consentement newsletter ;
8. compte client et récupération durable d'achats, si validés dans le lot identité front ;
9. UI analytics/consentement et instrumentation événementielle ;
10. cohérence typographique, accents, design tokens et accessibilité.

### P2 — administration

11. surfaces Filament commandes/paiements/remboursements/livraison ;
12. utilisateurs et autorisations opérationnelles ;
13. dashboard métier priorisé et navigation admin consolidée ;
14. tests navigateur du panel et de ses composants critiques.

## 11. Conclusion

Le cœur Laravel n'est pas une maquette : commerce, paiement, livraison, CRM, analytics, affiliation et SEO sont déjà conçus avec une profondeur de sécurité rare. Le problème est principalement **l'exposition produit** : contenu incomplet, bundle CSS périmé, design public générique et back-office qui n'ouvre pas encore toutes les autorités métier. La refonte doit donc préserver le domaine et ses contrats, puis reconstruire les surfaces visiteur et admin sans réécrire le noyau.
