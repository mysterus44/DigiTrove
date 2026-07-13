# PROGRESS_TRACKER.md — Tableau de Bord Développement
# Mis à jour par l'agent codeur après CHAQUE tâche.

---

## 📊 ÉTAT GLOBAL

```
P0 FONDATIONS       : ██████████  100%
P0.5 ASSAINISSEMENT : ██████████  100%
SITE-00 PREVIEW     : ██████████  100%
P1 IDENTITÉ         : ██████████  100%
P2 CATALOGUE        : ██████████  100% (branche, non mergé)
P3 COMMERCE         : ░░░░░░░░░░  0%
P4 LIVRAISON        : ░░░░░░░░░░  0%
P5 ANALYTIQUE       : ░░░░░░░░░░  0%
P6 CRM & MARKETING  : ░░░░░░░░░░  0%
P7 BLOG & SEO       : ░░░░░░░░░░  0%
```

> Rappel : **aucune logique métier avant que P1→P4 soient migrés et testés.**
> P1 est mergé dans `p0-foundations-laravel13`. P2 est implémenté sur
> `p2-catalog`, non mergé, et reste limité au schéma Catalogue.

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
Statut : ✅ implémenté techniquement sur `p2-catalog`, en attente de review/merge.

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

## P3 — COMMERCE
| Tâche | Statut |
|-------|--------|
| Migrations carts / orders / order_items / payments / coupons / refunds | ⬜ TODO |
| OrderService (snapshot prix + nom) | ⬜ TODO |
| PaymentGateway (interface) + 1 provider | ⬜ TODO |
| Webhook : signature + getStatus + montant + idempotence | ⬜ TODO |
| Event OrderPaid | ⬜ TODO |
| Checkout invité (sans compte) | ⬜ TODO |
| Job expiration commandes pending (30 min) | ⬜ TODO |
| Tests (snapshot, idempotence, montant falsifié) | ⬜ TODO |

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
