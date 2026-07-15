# PROGRESS_TRACKER.md — Tableau de Bord Développement
# Mis à jour par l'agent codeur après CHAQUE tâche.

---

## 📊 ÉTAT GLOBAL

```
P0 FONDATIONS       : ██████████  100%
P0.5 ASSAINISSEMENT : ██████████  100%
SITE-00 PREVIEW     : ██████████  100%
P1 IDENTITÉ         : ██████████  100%
P2 CATALOGUE        : ██████████  100% (mergé PR #3 → aff4d05)
P3 COMMERCE         : █████████░  P3A+P3B+P3C-A mergés ; P3C-B/P3C-C à venir
P4 LIVRAISON        : ░░░░░░░░░░  0%
P5 ANALYTIQUE       : ░░░░░░░░░░  0%
P6 CRM & MARKETING  : ░░░░░░░░░░  0%
P7 BLOG & SEO       : ░░░░░░░░░░  0%
```

> Rappel : **aucune logique métier avant que P1→P4 soient migrés et testés.**
> P1, P2, P3A et P3B sont mergés dans `p0-foundations-laravel13` (P3B via PR #5 →
> `f07d225`).
> P3C Paiements : plan BDD finalisé (D-028, 1A–5A). **P3C-A `payments` mergé via PR #6
> (`4a077db`)** ; P3C-B webhooks et P3C-C refunds non démarrés.
> Point d'entrée Claude Code `CLAUDE.md` créé (miroir d'`AGENTS.md`, D-023).

---

## P0 — FONDATIONS
Statut : ✅ terminé techniquement sur la branche dédiée `p0-foundations-laravel13`.
Schéma BDD v1 validé officiellement avant P1.

| Tâche | Statut |
|-------|--------|
| Laravel 13.19 installé (PHP 8.3+) — D-012 | ✅ DONE |
| PostgreSQL 16 branché | ✅ DONE |
| docker-compose (Postgres + Redis) | ✅ DONE |
| .env.example complet · .env ignoré par git | ✅ DONE |
| Argon2id activé (config/hashing.php) | ✅ DONE |
| Filament 5 installé — D-012 | ✅ DONE |
| Pest + Pint configurés | ✅ DONE |
| CI (GitHub Actions) | ✅ DONE |

Vérifications P0 passées :
- `docker compose up -d` : PostgreSQL 16 + Redis 7 healthy
- `php artisan --version` : Laravel Framework 13.19.0
- `php artisan test` : 2 tests passés, 2 assertions
- `./vendor/bin/pint --test` : PASS, 25 fichiers Laravel

Prochaine phase : validation humaine du plan P2 Catalogue.

## P0.5 — ASSAINISSEMENT PRÉ-P1
Statut : ✅ terminé techniquement sur `p0-foundations-laravel13`.

| Tâche | Statut |
|-------|--------|
| Diagnostic Git initial + `git fsck --full` | ✅ DONE |
| Récupération de l'objet manquant `images/offres/tools.png` via `git fetch --refetch origin` | ✅ DONE |
| Schéma BDD v1 aligné Laravel 13.19 + `citext` + SoftDeletes | ✅ DONE |
| Ordre futur des migrations documenté | ✅ DONE |
| Legacy isolé sous `legacy/` sans suppression volontaire | ✅ DONE |
| `.codex/` ignoré comme outillage local | ✅ DONE |
| P1 maintenu bloqué jusqu'à validation humaine du schéma corrigé | ✅ DONE |
| Décisions finales multi-devises, checkout invité et affiliation future loggées — D-014 | ✅ DONE |

Vérifications P0.5 passées :
- `docker run ... php artisan test` : 2 tests passés, 2 assertions
- `docker run ... ./vendor/bin/pint --test` : PASS, 25 fichiers Laravel
- `docker run ... php artisan --version` : Laravel Framework 13.19.0
- `git fsck --full` : OK, aucun `missing blob` ; seulement des `dangling tree`

Schéma BDD v1 : validé officiellement avant P1 après logging des décisions
multi-devises, checkout invité et affiliation future.

## SITE-00 — VITRINE STATIQUE DE PRÉVISUALISATION
Statut : ✅ terminé techniquement et mergé dans `p0-foundations-laravel13`.

Objectif : prévisualiser l'expérience front-office DigiTrove avec contenu marketing
legacy, sans backend métier et sans flux transactionnel.

| Tâche | Statut |
|-------|--------|
| Landing/boutique statique mobile-first | ✅ DONE |
| Produits legacy affichés avec prix XOF | ✅ DONE |
| Sections hero, bénéfices, catalogue, catégories, avis, FAQ, blog, CTA | ✅ DONE |
| Images marketing sûres copiées vers `public/images/digitrove/` | ✅ DONE |
| CTA non transactionnels uniquement | ✅ DONE |
| Tests HTTP et garde-fous anti checkout/legacy/fichiers publics | ✅ DONE |
| PR #1 mergée dans `p0-foundations-laravel13` (`83b6b0c`) | ✅ DONE |
| Branche locale `site-00-static-preview` supprimée après merge confirmé | ✅ DONE |
| Branche distante `origin/site-00-static-preview` conservée | ✅ DONE |
| `origin/main` confirmé intact, sans SITE-00 | ✅ DONE |

Vérifications SITE-00 passées :
- `php artisan test` via `digitrove-php:dev` : 5 tests passés, 20 assertions
- `./vendor/bin/pint --test` via `digitrove-php:dev` : PASS, 26 fichiers
- `npm run build` : PASS

Vérifications post-merge sur `p0-foundations-laravel13` :
- `npm run build` : PASS
- `php artisan test` via `digitrove-php:dev` : 5 tests passés, 20 assertions
- `./vendor/bin/pint --test` via `digitrove-php:dev` : PASS, 26 fichiers

Limites confirmées : aucune migration, aucune table, aucun modèle métier, aucun
contrôleur métier, aucun checkout, aucun panier, aucun paiement, aucun lien de
livraison ou fichier digital public. P1 reste bloqué jusqu'à validation humaine du
schéma BDD v1.

## P1 — IDENTITÉ
Statut : ✅ implémenté et mergé dans `p0-foundations-laravel13`.
Périmètre strict : extension `citext`, `users`, `customer_profiles`, `visitors`.

| Tâche | Statut |
|-------|--------|
| Migration extension PostgreSQL `citext` | ✅ DONE |
| Migration `users` | ✅ DONE |
| Migration `customer_profiles` | ✅ DONE |
| Migration `visitors` | ✅ DONE |
| Modèles + relations + casts (enums) | ✅ DONE |
| Factories P1 | ✅ DONE |
| Tests Pest/PostgreSQL : extension, contraintes, relations, SoftDeletes, visiteurs | ✅ DONE |
| Middleware de tracking visiteur (cookie UUID) | ⏸️ PAUSED — hors première livraison P1 |
| Stitching visitor → user au login | ⏸️ PAUSED — préparé par FK nullable, logique hors P1 |
| Seeder admin (lit .env, jamais de mot de passe en dur) | ⏸️ PAUSED — non livré en P1 |

Vérifications P1 passées :
- `php artisan migrate:fresh --env=testing` via `digitrove-php:dev` + PostgreSQL
  réel `digitrove_testing` : PASS, 4 migrations P1
- `php artisan test` via `digitrove-php:dev` + PostgreSQL réel : PASS,
  17 tests, 57 assertions
- `./vendor/bin/pint --test` via `digitrove-php:dev` : PASS, 38 fichiers

Vérifications post-merge P1 sur `p0-foundations-laravel13` :
- Merge GitHub : `3f9d132 Merge pull request #2 from mysterus44/p1-identity`
- `origin/main` confirmé intact à `1e41b92 DigiTrove V2`
- Branche locale `p1-identity` supprimée après merge confirmé
- Branche distante `origin/p1-identity` conservée
- `php artisan migrate:fresh --env=testing` via `digitrove-php:dev` + PostgreSQL
  réel : PASS, tables applicatives uniquement `users`, `customer_profiles`,
  `visitors`
- `php artisan test` via `digitrove-php:dev` + PostgreSQL réel : PASS,
  17 tests, 57 assertions
- `./vendor/bin/pint --test` via `digitrove-php:dev` : PASS, 38 fichiers

Limites confirmées : aucune table catalogue, produit, panier, commande, paiement,
téléchargement, affiliation, segment marketing avancé ou analytics. Aucun checkout
invité, aucun middleware/stitching avancé, aucun front métier.

## P2 — CATALOGUE
Statut : ✅ implémenté et **mergé** dans `p0-foundations-laravel13` via PR #3
(`aff4d05 Merge pull request #3 from mysterus44/p2-catalog`).

| Tâche | Statut |
|-------|--------|
| Plan BDD P2 Catalogue | ✅ DONE — baseline publiée |
| Migrations categories / products / product_prices / product_files / product_category / product_bundles | ✅ DONE |
| Index FK inverses categories / product_category / product_bundles | ✅ DONE — D-021 |
| Prix fixes par devise via `product_prices` | ✅ DECIDED — D-018 |
| Bundles avec prix commercial propre via `product_prices` | ✅ DECIDED — D-018 |
| product_price_history | ⏸️ PAUSED — reporté hors P2 |
| Références fichiers sur disque **privé** | ✅ DONE — aucune URL publique, aucun upload réel |
| checksum_sha256 contraint | ✅ DONE |
| Reviews + modération + verified_purchase | ⬜ TODO — probablement hors première livraison P2 |
| Filament ProductResource | ⏸️ PAUSED — hors P2 |
| Tests catalogue et garde-fous sécurité | ✅ DONE |

Décisions P2 validées :
- `products` ne porte pas de montant ni devise.
- `product_prices` porte les prix fixes par devise, avec `currency VARCHAR(3)`
  contraint longueur 3 + majuscules, montants en `BIGINT`, jamais `FLOAT`.
- Les bundles sont des produits `type = bundle` avec prix propre dans
  `product_prices`, indépendant de la somme des produits enfants.
- Hors P2 : `product_price_history`, conversion automatique, taux de change,
  promotions avancées, checkout, commandes, paiements, download grants.
- Aucune donnée legacy importée, aucun fichier digital copié, aucun Filament/admin,
  aucune route/API et aucune logique transactionnelle ajoutés.
- Les cycles indirects de bundles ne sont pas bloqués par la BDD et restent à
  traiter avant toute exposition d'écriture métier.
- `categories.created_at` et `categories.updated_at` sont conservés officiellement
  pour audit, imports legacy futurs, Filament ultérieur et cohérence Eloquent.
- `product_files.storage_path` est durci en BDD : chemin relatif privé non vide,
  sans URL, chemin absolu Unix/Windows, segment `public` ou traversée `..`.

Vérifications P2 passées :
- `php artisan migrate:fresh --env=testing` via `digitrove-php:dev` + PostgreSQL
  réel : PASS, migrations P1 + 6 migrations P2
- `php artisan test` via `digitrove-php:dev` + PostgreSQL réel : PASS,
  29 tests, 173 assertions
- `./vendor/bin/pint --test` via `digitrove-php:dev` : PASS, 55 fichiers
- `git diff --check` : PASS

Vérifications post-merge P2 sur `p0-foundations-laravel13` (`aff4d05`) :
- Merge GitHub : `aff4d05 Merge pull request #3 from mysterus44/p2-catalog`
  (commits `d43751d` + `fbaa33a`, base `9a11791`)
- `origin/main` confirmé intact à `1e41b92 DigiTrove V2` (P2 absent de `main`)
- Branche locale `p2-catalog` supprimée (`git branch -d`, merge confirmé) ;
  `origin/p2-catalog` conservée
- `main` local réaligné (pointeur seul) sur `1e41b92`
- `php artisan migrate:fresh --env=testing` via `digitrove-php:dev` + PostgreSQL
  réel `digitrove_testing` : PASS, 10 migrations ; tables applicatives = `users`,
  `customer_profiles`, `visitors`, `categories`, `products`, `product_prices`,
  `product_files`, `product_category`, `product_bundles` ; extension `citext`
  présente ; aucune table commerce/paiement/téléchargement/affiliation/analytics
- `php artisan test` via `digitrove-php:dev` + PostgreSQL réel : PASS,
  29 tests, 173 assertions
- `./vendor/bin/pint --test` via `digitrove-php:dev` : PASS, 55 fichiers
- `git diff --check` : PASS

## P3 — COMMERCE
Statut : ✅ **P3A Coupons et Paniers** et **P3B Commandes** mergés dans
`p0-foundations-laravel13` via PR #4 (`234e303`) et PR #5 (`f07d225`). **P3C
Paiements & Remboursements : plan BDD finalisé (D-028, choix 1A–5A), 0 % code** —
tables, index partiels et triggers T1–T11 documentés dans `DigiTrove_Schema_BDD_v1.md`.
Implémentation à faire sur une branche dédiée, exécution séparée.

Ordre de migration révisé (D-024/D-027) : `coupons` → `coupon_currency_rules` →
`coupon_products` → `coupon_categories` → `carts` → `cart_items` → `orders` →
`order_items` → `coupon_redemptions` → `payments` → `payment_webhook_events` →
`refunds`. La table de consommations est créée en P3B mais reste vide jusqu'à P3C.

| Tâche | Statut |
|-------|--------|
| Plan BDD P3 + décisions de schéma (D-024) | ✅ DONE — validé pour P3A |
| Schéma commerce réécrit dans `DigiTrove_Schema_BDD_v1.md` (VARCHAR(3), snapshot étendu, idempotence) | ✅ DONE |
| Migrations P3A (`coupons` → `cart_items`, 6 tables) | ✅ DONE — mergé PR #4 |
| Modèles/enums/factories P3A | ✅ DONE — sans logique métier |
| Tests PostgreSQL P3A (contraintes, relations, sécurité) | ✅ DONE — 15 tests, 147 assertions |
| Plan d'implémentation P3B Commandes | ✅ DONE — D-027 validée humainement |
| Migrations P3B (`orders`, `order_items`, `coupon_redemptions`) | ✅ DONE — mergé PR #5 |
| Modèles/enums/factories P3B | ✅ DONE — sans logique checkout/paiement |
| Tests PostgreSQL P3B (contraintes, immutabilité, cohérence différée) | ✅ DONE — 18 tests, 337 assertions |
| Plan BDD P3C + décisions d'intégrité (D-028) | ✅ DONE — validé (1A–5A) |
| P3C-A `payments` (migration + enum + modèle + factory + 4 fn / 5 triggers) | ✅ DONE — **mergé PR #6 → `4a077db`**, 16 tests / 225 assertions |
| P3C-A tests de rollback isolés par frontière (`tests/Support/PhaseMigrationHarness`) | ✅ DONE — `migrate --path` jusqu'au gate + `migrate:rollback --path` du gate seul ; plus de `--step` |
| P3C-B `payment_webhook_events` | ⬜ TODO — branche dédiée après merge P3C-A |
| P3C-C `refunds` (cumul par trigger immédiat + verrou) | ⬜ TODO — branche dédiée |
| OrderService (snapshot prix + nom) | ⬜ TODO |
| CouponService (règle par devise, plafonds, verrou transactionnel) | ⬜ TODO |
| PaymentGateway (interface) + 1 provider | ⬜ TODO |
| Webhook : signature + getStatus + montant + idempotence + dédup `payment_webhook_events` | ⬜ TODO |
| RefundService + trigger cumul ≤ capturé (+ tests concurrence) | ⬜ TODO |
| Event OrderPaid | ⬜ TODO |
| Accès applicatif au panier invité (cookie + UUID + secret haché) | ⬜ TODO — schéma seulement en P3A |
| Job expiration paniers (7 j) + commandes pending (30 min) | ⬜ TODO |
| Tests (snapshot, idempotence, montant falsifié, double webhook, remboursement partiel) | ⬜ TODO |

Décisions bloquantes tranchées (D-024) : prix panier dynamique, coupon fixe par devise,
`product_id` nullable + snapshot, quantité ≥ 1, panier invité UUID+hash, un seul coupon.
Durcissements P3A (D-025) : limites d'usage nulles ou strictement positives,
`secret_hash` obligatoire/unique au format SHA-256 minuscule, `expires_at` obligatoire,
suppression physique d'un produit référencé par un panier refusée.
P3B implémenté selon D-027 : commandes et lignes immuables par triggers PostgreSQL,
nullifications FK strictement encadrées, une ligne par produit via index unique partiel,
cohérence comptable par constraint triggers différés, coupon consommé uniquement après
paiement serveur confirmé, identité client HMAC versionnée et remises P3 limitées aux
coupons. `orders.expires_at` reste immuable ; aucune réservation de quota pendant
`pending`. Correctif post-review : la contrainte
`orders_coupon_snapshot_consistency_check` ferme le cas PostgreSQL `CHECK = UNKNOWN`,
les tests ciblent SQLSTATE + contrainte/message, les scénarios coupon NULL sont couverts
et le rollback des trois migrations P3B est automatisé en base isolée. Les trois
migrations sont réversibles et rejouables via `migrate:fresh`.
Couverture de régression : cascades réelles `carts` → `cart_items` et `coupons` →
`coupon_currency_rules`/pivots testées ; produits et catégories préservés ; types
PostgreSQL `citext`, `uuid` et `varchar(3)` verrouillés par introspection. Suite
complète P3A : 44 tests, 322 assertions. P3A et P3B sont mergés ; P3C reste non
démarré.
Décisions non bloquantes à confirmer à l'implémentation : durées d'expiration (7 j / 30 min),
anonymisation invité, paiement tardif `requires_review`.

Vérifications post-merge P3A sur `p0-foundations-laravel13` (`234e303`) :
- PR #4 : commits `81f32fc` + `1c0d5a2`, base `2288a63`
- `origin/main` confirmé intact à `1e41b92` et sans P3A
- branche locale `p3a-coupons-carts` supprimée ; branche distante conservée
- `php artisan migrate:fresh --env=testing` : PASS, 16 migrations ; tables
  applicatives strictement P1 + P2 + six tables P3A
- `php artisan test --filter=P3ACouponsCartsSchemaTest` : PASS, 15 tests,
  147 assertions
- `php artisan test` : PASS, 44 tests, 322 assertions
- `./vendor/bin/pint --test` : PASS, 72 fichiers ; `git diff --check` : PASS

Validation documentaire D-027 : suite PostgreSQL inchangée et verte (44 tests,
322 assertions), Pint vert (72 fichiers), diff-check vert. Aucun artefact P3B/P3C.

Vérifications post-merge P3B sur `p0-foundations-laravel13` (`f07d225`) :
- [PR #5](https://github.com/mysterus44/DigiTrove/pull/5) : commits `b42371b` +
  `499e2bd`, base `6f7578e`
- `origin/main` confirmé intact à `1e41b92` et sans P3B
- branche locale `p3b-orders` supprimée ; branche distante conservée
- `php artisan migrate:fresh --env=testing` : PASS, 19 migrations (P1 + P2 + P3A + P3B)
- rollback automatisé des trois migrations P3B : PASS ; tables, fonctions et triggers
  P3B absents après rollback isolé
- `php artisan test --filter=P3BOrdersSchemaTest` : PASS, 18 tests, 337 assertions
- `php artisan test` : PASS, 62 tests, 650 assertions
- `./vendor/bin/pint --test` : PASS, 83 fichiers ; `git diff --check` : PASS
- tables P3B uniquement : `orders`, `order_items`, `coupon_redemptions`
- six fonctions et huit triggers P3B présents ; quatre constraint triggers confirmés
  `DEFERRABLE INITIALLY DEFERRED`
- `orders_coupon_snapshot_consistency_check` présent avec expression stricte
  `CASE ... ELSE FALSE END IS TRUE`
- aucune table P3C/P4/P5, aucun paiement, webhook, checkout ou téléchargement

## P4 — LIVRAISON (⚠️ cœur sécurité)
| Tâche | Statut |
|-------|--------|
| Migrations download_grants / download_logs | ⬜ TODO |
| Listener IssueDownloadGrants (sur OrderPaid) | ⬜ TODO |
| DownloadService (token haché, expiration, quota atomique) | ⬜ TODO |
| DownloadController + rate limiting | ⬜ TODO |
| Stratégie gros fichiers (X-Accel-Redirect ou URL S3 pré-signée) | ⬜ TODO |
| E-mail de livraison (double canal : écran + e-mail) | ⬜ TODO |
| Révocation sur remboursement | ⬜ TODO |
| Détection de partage de lien (> 3 IP / 24h) | ⬜ TODO |
| Tests sécurité (lien expiré, quota, révoqué, 404 générique) | ⬜ TODO |

## P5 — ANALYTIQUE
| Tâche | Statut |
|-------|--------|
| Migration `events` PARTITIONNÉE + index GIN | ⬜ TODO |
| Job de création des partitions à l'avance | ⬜ TODO |
| AnalyticsService + job d'écriture asynchrone | ⬜ TODO |
| analytics_sessions | ⬜ TODO |
| Rollups (daily_sales / daily_product / daily_funnel) | ⬜ TODO |
| Campaigns + attribution (first touch / last touch) | ⬜ TODO |
| Tests | ⬜ TODO |

## P6 — CRM & MARKETING
| Tâche | Statut |
|-------|--------|
| customer_segments + membres (définition JSONB) | ⬜ TODO |
| Rollups CRM maintenus par événement (orders_count, LTV) | ⬜ TODO |
| Paniers abandonnés + relance | ⬜ TODO |
| Widgets Filament (CA, tunnel, top produits) | ⬜ TODO |
| Export CSV des segments | ⬜ TODO |

## P7 — BLOG & SEO
| Tâche | Statut |
|-------|--------|
| Migrations articles / tags / article_product | ⬜ TODO |
| Rendu article + maillage interne | ⬜ TODO |
| sitemap.xml + robots.txt | ⬜ TODO |
| Données structurées (Product, Article) | ⬜ TODO |
| Core Web Vitals vérifiés | ⬜ TODO |

---

## LÉGENDE
`⬜ TODO` · `🔄 IN_PROGRESS` · `✅ DONE` · `❌ BLOCKED` · `⏸️ PAUSED`
