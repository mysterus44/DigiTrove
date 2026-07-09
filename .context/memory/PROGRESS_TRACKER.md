# PROGRESS_TRACKER.md — Tableau de Bord Développement
# Mis à jour par l'agent codeur après CHAQUE tâche.

---

## 📊 ÉTAT GLOBAL

```
P0 FONDATIONS       : ██████████  100%
P0.5 ASSAINISSEMENT : ██████████  100%
P1 IDENTITÉ         : ░░░░░░░░░░  0%
P2 CATALOGUE        : ░░░░░░░░░░  0%
P3 COMMERCE         : ░░░░░░░░░░  0%
P4 LIVRAISON        : ░░░░░░░░░░  0%
P5 ANALYTIQUE       : ░░░░░░░░░░  0%
P6 CRM & MARKETING  : ░░░░░░░░░░  0%
P7 BLOG & SEO       : ░░░░░░░░░░  0%
```

> Rappel : **aucune logique métier avant que P1→P4 soient migrés et testés.**
> P1 est non démarré et reste bloqué jusqu'à validation humaine finale du schéma BDD v1.

---

## P0 — FONDATIONS
Statut : ✅ terminé techniquement sur la branche dédiée `p0-foundations-laravel13`.
Validation humaine encore attendue avant P1 : schéma BDD v1.

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

Prochaine phase : P1 Identité, seulement après validation humaine du schéma BDD.

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

Schéma BDD v1 : prêt pour validation humaine finale après logging des décisions
multi-devises, checkout invité et affiliation future. P1 reste non démarré.

## P1 — IDENTITÉ
Statut : ⬜ non démarré. Bloqué jusqu'à validation humaine finale du schéma BDD v1.
Périmètre strict : extension `citext`, `users`, `customer_profiles`, `visitors`.

| Tâche | Statut |
|-------|--------|
| Migration `users` | ⬜ TODO |
| Migration `customer_profiles` | ⬜ TODO |
| Migration `visitors` | ⬜ TODO |
| Modèles + relations + casts (enums) | ⬜ TODO |
| Middleware de tracking visiteur (cookie UUID) | ⬜ TODO |
| Stitching visitor → user au login | ⬜ TODO |
| Seeder admin (lit .env, jamais de mot de passe en dur) | ⬜ TODO |
| Tests Pest (hash Argon2id, relations, stitching) | ⬜ TODO |

## P2 — CATALOGUE
| Tâche | Statut |
|-------|--------|
| Migrations products / product_files / categories / bundles | ⬜ TODO |
| product_price_history | ⬜ TODO |
| Upload fichiers sur disque **privé** | ⬜ TODO |
| checksum_sha256 calculé à l'upload | ⬜ TODO |
| Reviews + modération + verified_purchase | ⬜ TODO |
| Filament ProductResource | ⬜ TODO |
| Tests | ⬜ TODO |

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
