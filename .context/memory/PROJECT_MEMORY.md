# PROJECT_MEMORY.md — Mémoire technique du projet
# Dernière mise à jour : (à maintenir par l'agent codeur)

---

## 🎯 EN UNE PHRASE

DigiTrove est un écosystème e-commerce + CRM/ERP **100 % autonome** de vente de
produits digitaux, sans aucune plateforme tierce.

---

## 🏗️ STACK EFFECTIVE

| Couche | Choix | Statut |
|--------|-------|--------|
| Framework | Laravel 13 (PHP 8.3+) | ✅ décidé (D-012) |
| Base de données | PostgreSQL 16 | ✅ décidé |
| Admin | Filament 5 | ✅ décidé (D-012) |
| Front | Blade + Tailwind + Alpine.js | ✅ décidé |
| Cache / Queue | Redis | ✅ décidé |
| Fichiers | Disque privé (local puis S3) | ✅ décidé |
| Tests / style | Pest / Pint | ✅ décidé |
| Paiement | Interface `PaymentGateway` + CinetPay (1er provider) | 🔶 à câbler |

---

## 🗄️ SCHÉMA — les 4 blocs

Référence complète : `.context/architecture/SCHEMA_BDD.md`

```
A. IDENTITÉ   users · customer_profiles · visitors · customer_segments(+members)
B. CATALOGUE  products · product_files · product_price_history · categories
              product_category · product_bundles · licenses · reviews
C. COMMERCE   carts · cart_items · orders · order_items · payments · refunds
              coupons · download_grants · download_logs
D. ANALYTIQUE events (partitionnée) · analytics_sessions · campaigns
              daily_sales_stats · daily_product_stats · daily_funnel_stats
```

---

## 🔑 LES 5 RÈGLES QUI STRUCTURENT TOUT

1. La BDD avant la logique.
2. Snapshot du prix et du nom dans `order_items`.
3. `events` append-only, partitionnée, sans FK vers les tables chaudes.
4. `visitors` : sans elle, pas d'attribution marketing.
5. Livraison : token **haché**, disque privé, expiration + quota + révocation.

---

## 🧪 COMMANDES

```bash
# Dev
php artisan serve
docker-compose up -d              # Postgres + Redis

# Base de données
php artisan migrate
php artisan migrate:fresh --seed
php artisan db:seed --class=AdminSeeder

# Qualité
php artisan test                  # Pest
./vendor/bin/pint                 # style
php artisan optimize              # prod

# Files d'attente
php artisan queue:work
php artisan schedule:work         # rollups, expirations, partitions
```

---

## 🔄 ÉTAT ACTUEL

```
STATUS       : NON DÉMARRÉ (aucun code Laravel)
DERNIÈRE ACTION : schéma BDD v1 conçu, décisions figées, audit legacy réalisé
PROCHAINE ACTION : PRD_00_FONDATIONS (installer Laravel + PostgreSQL + Filament)
BLOCAGES     : schéma v1 en attente de validation finale de KingKouda
```
