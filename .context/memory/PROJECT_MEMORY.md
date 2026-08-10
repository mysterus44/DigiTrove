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
| Framework | Laravel 13.19 (PHP 8.3+) | ✅ décidé (D-012) |
| Base de données | PostgreSQL 16 | ✅ décidé |
| Admin | Filament 5 | ✅ décidé (D-012) |
| Front | Blade + Tailwind + Alpine.js | ✅ décidé |
| Cache / Queue | Redis | ✅ décidé |
| Fichiers | Disque privé (local puis S3) | ✅ décidé |
| Tests / style | Pest / Pint | ✅ décidé |
| Paiement | Interface `PaymentGateway` + CinetPay (1er provider) | 🔶 à câbler |

---

## 🗄️ SCHÉMA — les 4 blocs

⚠️ Référence complète et **maintenue** : **`DigiTrove_Schema_BDD_v1.md`** (racine du dépôt).
`.context/architecture/SCHEMA_BDD.md` est une copie **figée et incomplète** (526 lignes
contre plus de 3 300) — ne pas s'y fier pour un gate.

```
A. IDENTITÉ   users · customer_profiles · visitors · customer_segments(+members)
B. CATALOGUE  products · product_files · product_price_history · categories
              product_category · product_bundles · licenses · reviews
C. COMMERCE   carts · cart_items · orders · order_items · payments · refunds
              coupons · download_grants · download_logs
D. ANALYTIQUE events (partitionnée) · analytics_sessions · campaigns
              daily_sales_stats · daily_product_stats · daily_funnel_stats
E. CRM        crm_contacts · crm_marketing_consent_events · crm_order_attributions
              crm_contact_commerce_rollups · crm_segments(+versions/generations)
              crm_exports · cart_reminder_attempts
F. AFFILIATION affiliate_program_policies · affiliates · affiliate_codes
              affiliate_touches · affiliate_attributions · affiliate_commissions
              affiliate_commission_entries · affiliate_payouts · affiliate_payout_items
              ⚠️ DORMANT : schéma seul, aucun flux applicatif (D-057)
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

⚠️ Ce bloc était resté figé sur « NON DÉMARRÉ » alors que le dépôt portait déjà
45 migrations et une suite de 1 524 tests. Corrigé au gel D-058 ; à tenir à jour
à chaque gate, sous peine d'induire en erreur l'agent qui reprend.

```
STATUS       : P0→P6-D0 MERGÉS · 45 migrations · suite 1524 tests / 11835 assertions
DERNIÈRE ACTION : P6-D0 clos et mergé (PR #40, merge dcdc966, D-057) — fondation BDD
                  affiliation DORMANTE ; puis D-058 gelée (architecture P6-D1)
PROCHAINE ACTION : P6-D1 — frontière d'autorité PostgreSQL de l'affiliation
                  + gouvernance des politiques versionnées (D-058), migration 000030
BLOCAGES     : aucun. Dette critique connue et planifiée : les 9 tables affiliate_*
               appartiennent à `digitrove` (superuser) — corrigé par 000030, jamais
               par réécriture de 000029.
```

⚠️ **Suite complète** : `php artisan test` meurt vers ~1 075 tests
(`memory_limit` 128 M par défaut dans l'image). Utiliser
`php -d memory_limit=3G vendor/bin/pest`. La CI n'est pas concernée.
