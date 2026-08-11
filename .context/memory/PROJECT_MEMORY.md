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
              ⚠️ owner = digitrove_affiliate_executor (P6-D1) ; runtime EXECUTE-only
              ⚠️ Seule la GOUVERNANCE des politiques est livrée (D-058). Candidature,
                 codes, touches, attribution, commissions et payouts : ABSENTS.
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
STATUS       : P0→P6-D1 MERGÉS · 46 migrations
               suite 1566 tests / 12145 assertions
DERNIÈRE ACTION : P6-D1 mergé (PR #41, merge aeac8a5d, CI SUCCESS, D-058, 000030) —
                  frontière d'autorité PostgreSQL de l'affiliation + gouvernance
                  des politiques versionnées
PROCHAINE ACTION : P6-D1.1 — cycle de vie affilié (candidature → revue admin →
                  activation/suspension/fermeture) + codes affiliés
BLOCAGES     : aucun. La dette superuser est FERMÉE : les 9 tables et 9 séquences
               appartiennent désormais à digitrove_affiliate_executor.
```

⚠️ **Rollback P6-D1 : LOSSLESS-ONLY.** `000030` élargit la période d'effet à
`timestamptz(6)` (en `(0)`, deux publications rapprochées s'écrasent et violent le
CHECK de période). Le `down()` ne rétrécit vers `(0)` **que si aucune valeur ne
change** ; sinon il **refuse avant toute mutation**. Après une publication réelle,
`now()` garde les microsecondes, donc **le refus est le cas normalement attendu** —
le système préfère conserver l'historique exact plutôt que le falsifier.

⚠️ **Suite complète** : `php artisan test` meurt vers ~1 075 tests
(`memory_limit` 128 M par défaut dans l'image). Utiliser
`php -d memory_limit=3G vendor/bin/pest`. La CI n'est pas concernée.
