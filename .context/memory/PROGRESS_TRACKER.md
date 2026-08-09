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
P3 COMMERCE         : ██████████  Schéma P3C-C refunds mergé PR #10
P3-D APPLICATIF     : ██████████  P3-D1→D5 TOUS MERGÉS (PR #17→#23) ; P3-D4 + P3-D5 TERMINÉS, MERGÉS ET VALIDÉS (merge a62563fd, CI #27, D-034) ; confirmation serveur + webhook CinetPay + OrderPaid, aucune migration — **couche paiement complète**
P4 LIVRAISON        : ██████████  Schéma COMPLET — P4-A0/A1/A2/A2.1 + P4-B0 + P4-B mergés (PR #16 → 98441014)
P4-C APPLICATIF     : ██████████  P4-C0→C6 TERMINÉS, MERGÉS ET VALIDÉS via PR #24 et PR #25 (`109fde4c`, CI #31, D-036). Aucun I/O stockage sous transaction PostgreSQL ; autorisation non énumérable, cookie de tentative, streaming privé/Range/HEAD, opérations et C1→C6 ; pipeline désactivé par défaut jusqu'à configuration opérationnelle
P5 ANALYTIQUE       : ██████████  100% — P5-A0→A3C TERMINÉS, MERGÉS ET VALIDÉS ; P5-A3D reporté au durcissement préproduction (D-042)
P6 CRM & MARKETING  : █████████░  P6-A0 → P6-A2 TOUS TERMINÉS ET MERGÉS (D-043 → D-050 ; P6-A2 = PR #36, merge 920eb1b9, CI #43) ; P6-B0 CRM Admin Views IMPLÉMENTÉ (D-052, migration 000026, 42 migrations, campagnes ciblées, en attente PR/CI) ; P6-B1 exports NON COMMENCÉ.
P7 BLOG & SEO       : ░░░░░░░░░░  0%
```

> Rappel : **aucune logique métier avant que P1→P4 soient migrés et testés.**
> P1, P2, P3A et P3B sont mergés dans `p0-foundations-laravel13` (P3B via PR #5 →
> `f07d225`).
> P3C Paiements : plan BDD finalisé (D-028, 1A–5A). **P3C-A `payments` mergé via PR #6
> (`4a077db`)**, P3C-B + hardening mergés via PR #8/#9 et P3C-C `refunds` mergé via
> PR #10 (`122332a`).
> **P4 Livraison : plan BDD finalisé et validé (D-029 + D-029.1 B–A–B + D-029.2 +
> D-029.3 + D-029.4 + D-029.5)** — gates isolés dans l'ordre : **P4-A0
> durcissement `product_files` (`000008`) MERGÉ via PR #11 (`a047571`)** → P4-A1
> snapshot `order_item_bundle_components` (`000009`, D-029.3 : S1/S2/S3, bundles
> imbriqués exclus) **MERGÉ via PR #12 (`93d1f17`)** → P4-A2 `download_grants`
> (`000010`, plan finalisé D-029.4 : option A émission immédiate = snapshot
> applicatif, G1–G4) → P4-A2.1 hardening (`000011`) → P4-B `download_logs`
> (`000012`) ; `licenses` exclu.
> **P4-A2 `000010` MERGÉ via PR #13 (`77f3766`)**. **P4-A2.1 `000011` MERGÉ via
> [PR #14](https://github.com/mysterus44/DigiTrove/pull/14) (`2c25e2a`)** : G3
> bénéficiaire null-safe et G2 `updated_at` lié aux transitions, 27 migrations,
> 7 tests / 113 assertions dédiées, suite 158/2301, Pint 112, rollback exact vert.
> **P4-B a été implémenté** sur `p4-b-download-logs` (`8cf24a8`) conformément à
> D-029.5, mais est **BLOQUÉ AU MERGE** : un audit offensif a prouvé que l'autorité
> de consommation `pg_trigger_depth() > 1` de G2 est contournable par un trigger
> temporaire ou permanent (compteur +1 sans `download_logs`). **D-029.6** décide le
> gate préalable **P4-B0** (séparation de rôles PostgreSQL `digitrove` /
> `digitrove_runtime` / `digitrove_download_executor` + G5 `SECURITY DEFINER` +
> vérification d'identité dans G2 + fermeture TEMP/CREATE/EXECUTE). P4-B sera
> renuméroté `000013` après merge de la migration ACL P4-B0 `000012`.
> **P4-B0 est MERGÉ** via [PR #15](https://github.com/mysterus44/DigiTrove/pull/15)
> (merge `6d23e546`, parents `a3eac5e` + `9b69f192`) : migration ACL `000012`,
> provisioning cluster, double connexion, harness dual-identité, CI durcie, 13
> tests sous le vrai rôle runtime. Validation post-merge : 28 migrations, P4-B0
> 13/84, suite 171/2385, Pint 117, provisioning idempotent, identités
> migration/runtime prouvées, zéro résidu.
> **P4-B est TERMINÉ ET MERGÉ** via
> [PR #16](https://github.com/mysterus44/DigiTrove/pull/16) (merge `98441014`,
> parents `d7c53cf` + `49692e25`) : migration `000013`, `download_logs` à 15
> colonnes, G5 `SECURITY DEFINER` possédée par `digitrove_download_executor`
> (search_path épinglé, objets qualifiés), autorité de G2 par `current_user`
> (profondeur en défense secondaire), ACL `download_logs` normalisées. Un trigger
> forgé même par le propriétaire superuser est refusé (23514) ; le runtime est
> arrêté en 42501. Validation post-merge : 29 migrations, P4-B 19/603, suite
> 190/2975, Pint 121, provisioning idempotent, identités migration/runtime
> prouvées, zéro résidu. **Le schéma P4 est complet.**
> **La couche applicative est désormais PLANIFIÉE (D-030, validée KingKouda :
> Q1=A renforcée, Q2=B renforcée, Q3=A)** et non démarrée. Nommage figé : la
> tarification, le checkout et le paiement relèvent de **P3-D** ; la livraison
> commence à **P4-C**. Douze gates, **aucune migration** : `P3-D1` → `P3-D5`
> puis `P4-C0` → `P4-C6`. **Premier gate : `P3-D1 — Pricing & Quote Kernel`**
> (branche `p3-d1-pricing-kernel`). P5 non démarré.
> **P3-D1 est MERGÉ** via [PR #17](https://github.com/mysterus44/DigiTrove/pull/17)
> → merge `78f475e7` (parents `ba48cce1` + `95ab5627`), CI run #18 verte :
> 9 classes (`IntegerMath`, `Money`, `PricingService`, `DiscountAllocator`,
> `PricedQuote`, `PricedLine`, `CouponSnapshot`, `PricingException`,
> `PricingRefusalReason`), **aucune migration**, aucune écriture BDD, `taxMinor`
> explicitement nul, allocation Hamilton déterministe, coupon scopé sans ligne
> éligible refusé.
> **Le merge a précédé la revue contradictoire** : un audit post-merge a démontré
> **quatre défauts de contrat défensif** (A1 devise acceptant `"XOF\n"` · A2
> garde-fou P4-B laissant passer un service de livraison sous namespace neutre ·
> A3 DTO de pricing sans invariants · A4 `line_id` dupliqué écrasé en silence).
> Le calcul de prix lui-même était correct et aucune corruption monétaire n'était
> possible. **`P3-D1.1` ferme ces quatre points et est MERGÉ** via
> [PR #18](https://github.com/mysterus44/DigiTrove/pull/18) → merge `0e18d69d`
> (parents `78f475e7` + `6349fc19`), CI run #19 verte, 12 fichiers exactement.
> Validation post-merge : Unit 94/117, Feature P3-D1 48/167, P4-B 20/616,
> P3A 15/139, P3B 18/357, Catalogue 12/111, suite complète 333/3272, Pint 132,
> 29 migrations inchangées, aucune politique métier modifiée, aucune base
> temporaire résiduelle. Branches locales supprimées, distantes conservées.
> **`P3-D1` ET `P3-D1.1` SONT TERMINÉS ET MERGÉS** ; la prochaine étape est la
> planification de `P3-D2 — Checkout Order Transaction`, non commencée.
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
Statut : ✅ schéma P3 Commerce complet et mergé dans `p0-foundations-laravel13` : P3A
via PR #4 (`234e303`), P3B via PR #5 (`f07d225`), P3C-A via PR #6 (`4a077db`), P3C-B
et son hardening via PR #8/#9, P3C-C Refunds via PR #10 (`122332a`). Les tables, index
et triggers suivent D-028 et `DigiTrove_Schema_BDD_v1.md`.

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
| P3C-B `payment_webhook_events` (migration + enum + modèle + factory + 3 fn / 3 triggers) | ✅ DONE — **mergé PR #8 → `51c4847`**, 13 tests ; rollback isolé par frontière |
| P3C-B.1 durcissement rejeu (index unique réservé aux signés, migration `000006`) | ✅ DONE — **mergé PR #9 → `13932ac`** ; test adversarial |
| P3C-C `refunds` (cumul par trigger immédiat + verrou) | ✅ DONE — **mergé PR #10 → `122332a`**, migration `000007`, 5 fonctions / 6 triggers |
| OrderService (snapshot prix + nom) | ⬜ TODO |
| CouponService (règle par devise, plafonds, verrou transactionnel) | ⬜ TODO |
| PaymentGateway (interface) + 1 provider | ⬜ TODO |
| Webhook : signature + getStatus + montant + idempotence + dédup `payment_webhook_events` | ⬜ TODO |
| RefundService + trigger cumul ≤ capturé (+ tests concurrence) | ⬜ TODO |
| Event OrderPaid | ⬜ TODO |
| Accès applicatif au panier invité (cookie + UUID + secret haché) | ⬜ TODO — schéma seulement en P3A |
| Job expiration paniers (7 j) + commandes pending (30 min) | ⬜ TODO |
| Tests (snapshot, idempotence, montant falsifié, double webhook, remboursement partiel) | ⬜ TODO |

Validation P3C-C post-merge sur PostgreSQL réel : 23 migrations ; tests
P3C-C 16/461 ; régressions P3C-B 13/189, P3C-A 16/221 et P3B 18/359 ; suite complète
107 tests, 1534 assertions ; Pint 100 fichiers. Nullification manuelle de l'initiateur
refusée mais `ON DELETE SET NULL` conservé, states factory composables, matrice différée
complète, rollback isolé `000007` et courses INSERT/transition à deux connexions validés.
Branche locale `p3c-c-refunds` supprimée après preuve du merge ; distante conservée à
`1270c53`. `origin/main` intact à `11130f4`. P4/P5 non démarrés ; prochaine étape : plan P4.

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
complète P3A : 44 tests, 322 assertions. P3A, P3B et les trois gates P3C sont mergés ;
aucune logique fournisseur, livraison ou téléchargement n'est démarrée.
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
Statut : plan BDD finalisé (D-029), audité et validé (D-029.1, choix B–A–B), puis
corrigé pré-implémentation (**D-029.2**) : `product_files.version` FIGÉE avec le
contenu (`original_name` audité = libellé d'affichage, mutable) et gate composite
P4-A ABANDONNÉ au profit de **quatre gates isolés** — une migration, une branche,
une frontière de rollback chacun, merge obligatoire avant le gate suivant. Schéma
cible + catalogue G0–G6/S1–S3 + table de préservation des rollbacks dans le bloc
P4 de `DigiTrove_Schema_BDD_v1.md`. `licenses` exclu de P4 (décision produit
ouverte). **P4-A0 `000008` (durcissement `product_files`, trigger G0) mergé via
PR #11 (`a047571`)** ; **P4-A1 `000009` (snapshot bundle, S1/S2/S3) mergé via PR #12
(`93d1f17`)** ; P4-A2/P4-A2.1 sont mergés et **le plan P4-B D-029.5 est finalisé,
sans implémentation**. Aucune logique applicative P4 (OrderService, contrôleur,
route, job, listener) créée. P5 non démarré.

| Tâche | Statut |
|-------|--------|
| Plan BDD P4 + décision D-029 (unité du grant, token haché, invariants) | ✅ DONE |
| Audit contradictoire + décisions D-029.1 (B–A–B) | ✅ DONE — validé par KingKouda |
| Correction D-029.2 (version immuable, gates isolés P4-A0→P4-B) | ✅ DONE |
| **P4-A0** `p4-a0-product-file-immutability` — migration `000008` trigger G0 (frontière `000008`) | ✅ DONE — **mergé PR #11 → `a047571`** ; 9 tests / 132 assertions, suite complète 116/1666, Pint 102, rollback isolé vert |
| Plan P4-A1 + décisions D-029.3 (Q1=A imbriqués exclus, Q2=A S3 + exhaustivité applicative) | ✅ DONE — validé par KingKouda |
| **P4-A1** `p4-a1-bundle-purchase-snapshots` — migration `000009` snapshot + S1/S2/S3 (frontière `000009`) | ✅ DONE — **mergé PR #12 → `93d1f17`** ; 17 tests / 217 assertions, suite complète 133/1882, Pint 106, rollback isolé vert |
| Plan P4-A2 + décisions D-029.4 (option A : émission immédiate = snapshot applicatif ; 3 findings corrigés) | ✅ DONE — validé par KingKouda |
| **P4-A2** `p4-a2-download-grants` — migration `000010` `download_grants` G1–G4, modèle/factory/relations (frontière `000010`) | ✅ DONE — **mergé PR #13 → `77f3766`** ; 4 fonctions / 5 triggers, G4 différé sur `download_grants` ET `orders` |
| P4-A2 tests PostgreSQL (token, autorisation snapshot bundle, quota, concurrence 2 connexions, refund total différé) | ✅ DONE — 18 tests / 315 assertions ; suite complète 151/2188 (1882 − 9 adaptations + 315), Pint 110, rollback isolé `000010` vert |
| **P4-A2.1** `p4-a2-1-grant-integrity-hardening` — migration additive `000011`, remplacement G2/G3 sans modifier `000010` | ✅ DONE — **mergé PR #14 → `2c25e2a`** ; bénéficiaire G3 null-safe ; `updated_at` G2 lié aux transitions ; 7/113, suite 158/2301, Pint 112, rollback exact vert |
| Plan P4-B + décision D-029.5 (1A/2A/3A + R1A/R2A/R3A, schéma exact, tentative/Range/HEAD/completed, G5/G6, rollback, tests/threat model) | ✅ DONE — validé par KingKouda |
| **P4-B** `p4-b-download-logs` (`8cf24a8`) — migration `000012` `download_logs` G5–G6 + modèle/factory/tests | ⚠️ 1ʳᵉ version BLOQUÉE — audit offensif : autorité `pg_trigger_depth() > 1` de G2 contournable (trigger temporaire/permanent → +1 sans log) |
| **P4-B adapté après P4-B0** — merge de reprise `fca10d9` (stable `d7c53cf`, sans rebase) · migration renumérotée **`000013`** · préconditions fail-closed · **G5 `SECURITY DEFINER` possédée par `digitrove_download_executor`** (search_path épinglé, objets qualifiés) · **autorité G2 par `current_user`** (profondeur secondaire) · ACL `download_logs` normalisées · grant de verrou minimal `UPDATE (updated_at) ON orders` à l'exécuteur | ✅ **MERGÉ PR #16 → `98441014`** (parents `d7c53cf` + `49692e25`) ; validation post-merge : 29 migrations, `download_logs` 15 colonnes, P4-B 19/603, suite complète 190/2975, Pint 121, 6 fonctions / 7 triggers P4, G4 différé, provisioning idempotent, identités migration/runtime prouvées ; trigger forgé par le **propriétaire superuser** refusé en 23514 et runtime en 42501 ; rollback vide restaure G2 post-`000012` à l'octet près, rollback non vide refusé ; branche locale supprimée, distante conservée ; aucun endpoint/streaming/P5 |
| Audit offensif P4-B (sondes trigger temporaire/permanent, rôle superuser) | ✅ DONE — vulnérabilité confirmée ; verdict ANOMALIE ; option A renforcée validée |
| Plan P4-B0 + décision D-029.6 (3 rôles PostgreSQL, G5 `SECURITY DEFINER`, identité effective dans G2, fermeture TEMP/CREATE/EXECUTE, double connexion, provisioning) | ✅ DONE — validé par KingKouda |
| **P4-B0** `p4-b0-postgresql-runtime-privileges` — migration ACL `000012_harden_database_runtime_privileges` + `docker/postgres/provision-runtime-roles.sql` + `db:provision-runtime-roles` + double connexion + harness dual-identité + CI durcie | ✅ **MERGÉ PR #15 → `6d23e546`** (parents `a3eac5e` + `9b69f192`) ; validation post-merge : 13 tests / 84 assertions, suite complète 171/2385, Pint 117, 28 migrations, provisioning idempotent, identités migration/runtime prouvées, 26 fonctions trigger sans EXECUTE runtime, défauts fonctions globaux (ns=0), rollback ACL sans réouverture, zéro résidu |
| Findings P4-B0 (REVOKE EXECUTE réservé au propriétaire · exécuteur exige SELECT · default privileges FONCTIONS : forme GLOBALE efficace / `IN SCHEMA` inefficace, propre au rôle créateur → migrateur ET exécuteur) | ✅ DOCUMENTÉS + testés ; la migration applique les deux défauts globaux + REVOKE explicite sur l'existant ; P4-B devra `GRANT EXECUTE` sur G5 au migrateur pour attacher le trigger |
| Audit de la couche applicative + roadmap D-030 (12 gates, Q1/Q2/Q3 tranchées) | ✅ DONE — validé par KingKouda |
| Listener IssueDownloadGrants / DownloadService / DownloadController / streaming / e-mail / révocation / purge | ➡️ REPLANIFIÉS — voir les sections **P3-D** et **P4-C** ci-dessous (D-030) |
| Phase licences (`licenses`) | ❌ BLOCKED — hors P4, décision produit KingKouda requise |

## P3-D — COUCHE APPLICATIVE COMMERCE (D-030)
Statut : **planifiée, non démarrée**. Aucune migration. Le schéma P3 est complet ;
ces gates n'écrivent que du code applicatif. Décisions figées : **Q3 = A** (coupon
scopé sans ligne éligible ⇒ refus explicite ; allocation **Hamilton**, départage
`résidu décroissant → product_id croissant → id de ligne croissant`, aucun `float`,
aucune division flottante, aucun `round()` sur les montants). Consommation du
coupon **jamais** à la création de la commande : snapshots sur `orders` en P3-D2,
`coupon_redemptions` + `redemptions_count` en P3-D4 seulement, sous verrou du
coupon, dans la même transaction que la transition vers `paid`.

| Gate | Branche future | Objectif unique | Statut |
|------|----------------|-----------------|--------|
| **P3-D1** Pricing & Quote Kernel | `p3-d1-pricing-kernel` | Money value object + `PricedQuote` immuable : prix fixe par devise depuis `product_prices`, validation coupon, remise globale, **allocation Hamilton aux lignes**. Zéro écriture BDD, zéro migration. | ✅ **MERGÉ** — [PR #17](https://github.com/mysterus44/DigiTrove/pull/17) → `78f475e7`, CI #18 verte ; 9 classes, 17 fichiers, 29 migrations inchangées |
| **P3-D1.1** Hardening post-merge | `p3-d1-post-merge-hardening` | Fermeture des 4 anomalies de contrat défensif trouvées par l'audit post-merge (A1 devise `\n` · A2 garde-fou P4-B · A3 invariants DTO · A4 `line_id` dupliqué). Aucune migration, aucune politique métier modifiée. | ✅ **MERGÉ** — [PR #18](https://github.com/mysterus44/DigiTrove/pull/18) → `0e18d69d`, CI #19 verte ; 12 fichiers, Unit **94/117**, P4-B **20/616**, suite complète **333/3272**, Pint **132**, 29 migrations inchangées |
| **P3-D2** Checkout Order Transaction | `p3-d2-checkout-order-transaction` | `Order` + `order_items` + snapshot bundle exhaustif (`INSERT … SELECT` unique après `products FOR UPDATE`) dans **une** transaction ; bundle vide refusé avant l'insertion de la ligne ; idempotence par `checkout_idempotency_hash` ; Cart → `converted` (D-031 Q2=B) ; composant soft-deleted ⇒ refus (D-031 Q1=C). | ✅ **P3-D2 TERMINÉ, MERGÉ ET VALIDÉ** — [PR #19](https://github.com/mysterus44/DigiTrove/pull/19), head `ef758bbb`, merge `4c691864`, **CI #22 success**. 4 classes + `config/checkout.php`, aucune migration. **D-032** : expiration `pending` configurable (défaut 30 min) au lieu d'une constante en dur, et retry `order_number` protégé par un **savepoint** — sans lui le retry recevait `25P02` (transaction avortée), prouvé empiriquement. Concurrence prouvée sous les vraies identités (seed `digitrove`, A/B `digitrove_runtime`, `TEMP` refusé). **Hotfix temporel P3-D1** — [PR #20](https://github.com/mysterus44/DigiTrove/pull/20), head `0d6e95d9`, merge `0854a393`, **CI #23 success** : correction d'un test P3-D1 **préexistant** qui mélangeait une fenêtre de coupon relative à `now()` avec une référence figée (**aucune régression métier P3-D2**). Validation post-merge : P3-D1 **48/167**, P3-D2 **66/323**, P4-B **20/620**, suite complète **399/3599**, Pint **138**, **29 migrations**, aucune `000014` |
| **P3-D2.1** PostgreSQL Constraint Classification Hardening | `p3-d2-1-postgresql-constraint-hardening` | Classer une erreur BDD sur **`SQLSTATE 23505` + nom de contrainte EXACT**, jamais sur un substring de message. Défaut trouvé **après** la clôture P3-D2 ; **aucune régression métier observée**, mais un message usurpé déclenchait un retry `order_number` injustifié et de faux refus `CartAlreadyCheckedOut`/`IdempotencyConflict`. Primitive `App\Support\PostgresConstraintViolation`, aucune migration. | ✅ **TERMINÉ, MERGÉ ET VALIDÉ** — [PR #21](https://github.com/mysterus44/DigiTrove/pull/21), head `3adb2824`, merge `9b0aa924`, **CI #24 success**, 7 fichiers (+403/−9), **aucune migration**. Classification **fail-closed** : `SQLSTATE 23505` **+ nom de contrainte exact**, jamais un substring de message. Validation post-merge : Unit **20/20**, P3-D2 **71/344**, P3-D1 **48/167**, P4-B **20/621**, Pint **140**, 29 migrations. **P3-D3 est désormais autorisé** |
| **P3-D3** Payment Initiation ✅ *TERMINÉ, MERGÉ ET VALIDÉ (D-033)* | `p3-d3-payment-initiation` | Port fournisseur abstrait + ligne `payments` `pending` en deux phases (réservation committée → appel fournisseur **hors transaction** → finalisation de la référence) ; `payment.public_id` comme clé fournisseur, clé brute jamais persistée ni envoyée ; une seule tentative vivante par Order ; classification exacte via `PostgresConstraintViolation` ; aucun webhook, aucune confirmation, aucune livraison. | ✅ **TERMINÉ, MERGÉ ET VALIDÉ** — [PR #22](https://github.com/mysterus44/DigiTrove/pull/22), head `5188e6cc`, merge `70379a02` (parents `6e701a1e` + `5188e6cc`), **CI #26 success** ; périmètre exact **14 fichiers (+2005/-7)**, **aucune migration**. Durcissement pré-merge (5 findings) : garde `transactionLevel = 0`, horloge injectée unique (`isExpired` `>=`), reprise de réponse perdue (rappel fournisseur idempotent même si référence posée), toute exception BDD inconnue ⇒ `IntegrityFailure` sanitizé, preuves C1–C4 **service-level** (connexions runtime indépendantes). Suite non transactionnelle dédiée. Validation post-merge sur la stable `70379a0` : P3-D3 **54/246**, P3-D2.1 **20/20**, P3-D2 **71/344**, P3C-A **16/219**, P4-B **20/628**, suite complète **478/3894**, Pint **149**, **29 migrations**, aucune `000014`. **P3-D4 est le prochain gate autorisé, NON commencé** |
| **P3-D4 + P3-D5** Server-side Confirmation + OrderPaid *(macro-gate, D-034)* | `p3-d4-d5-payment-confirmation` | Webhook CinetPay signé + dédupliqué + **contre-appel fournisseur obligatoire** + montant/devise revérifiés en entiers ; ladder `pending→processing→succeeded` · `Order → paid` · `coupon_redemptions` une fois ; succès incohérent ⇒ `payment_review` ; branche gratuite `total_minor = 0` sans `payments` ; **OrderPaid** (`order_id` seul) après COMMIT ; CinetPay désactivé par défaut, PowerPay scaffold ; rejeu idempotent. | ✅ **TERMINÉ, MERGÉ ET VALIDÉ** — [PR #23](https://github.com/mysterus44/DigiTrove/pull/23), head `8aad4fc`, merge `a62563fd`, **CI #27 success** ; **aucune migration** ; 59 tests (adapter 23, binding 5, confirmation 16 dont C3/C4, webhook 8 dont C1/C2, événement 7 dont C5) ; suite complète **537/4083**, Pint **175**, 29 migrations |

## P4-C — COUCHE APPLICATIVE LIVRAISON (D-030, D-035, D-036)
Statut : **P4-C0→C6 terminés, mergés et validés** via PR #24 et
[PR #25](https://github.com/mysterus44/DigiTrove/pull/25), merge `109fde4c`,
CI #31 success. Aucune migration. Le pipeline reste désactivé par défaut jusqu'à
la configuration opérationnelle de production. Décisions figées :
**Q1 = A renforcée** (token CSPRNG en mémoire vive, SHA-256 en base, jamais
reconstructible ; reprise = **révoquer puis réémettre**, jamais « réessayer avec
l'ancien token » ; sémantique **at-least-once** assumée pour l'e-mail) et
**Q2 = B renforcée** (job queued unique par Order transportant **`order_id`
seul** ; tokens générés dans le worker ; e-mail envoyé **synchroniquement** dans
le même processus ; aucun secret dans Redis, `jobs`, `failed_jobs`, une exception
ou un log).

| Gate | Branche future | Objectif unique | Statut |
|------|----------------|-----------------|--------|
| **P4-C0→C3** Secure Delivery Pipeline *(macro-gate, D-035)* | `p4-c0-c3-secure-delivery-pipeline` | Queue/Mail safety (job unique `order_id` seul, Mailable non-`ShouldQueue` et non sérialisable, transport `log` refusé, `DeliveryConfig` fail-closed) + Grant Issuance (CSPRNG, hash seul, snapshot bundle, no upgrade) + Refund Revocation (partial garde, full révoque atomique G4) + Secure Delivery Job (tokens en mémoire, envoi synchrone, retry révoque/réémet). Pipeline **désactivé par défaut**. | ✅ **TERMINÉ, MERGÉ ET VALIDÉ** — [PR #24](https://github.com/mysterus44/DigiTrove/pull/24), head `1492cd13`, merge `701cfa4f`, CI #29 success ; **aucune migration** ; P4-C **50/165** (C0 16/42, C1 14/36, C2 7/29, C3 9/30, concurrence 4/28 ; C1–C5 PostgreSQL réels) ; suite complète **587/4259**, Pint **191** |
| **P4-C0** Queue & Mail Secret Safety | `p4-c0-queue-mail-secret-safety` | *(fondu dans le macro-gate ci-dessus)* | ✅ inclus dans `p4-c0-c3-secure-delivery-pipeline` |
| **P4-C1** Download Grant Issuance | `GrantIssuanceService` | CSPRNG, hash seul, `orders FOR UPDATE`, G3, unique partiel du couple actif, snapshot bundle, no implicit upgrade. | ✅ inclus dans `p4-c0-c3-secure-delivery-pipeline` |
| **P4-C2** Refund Grant Revocation | `RefundCompletionService` | verrou Payment→Order → révocation de tous les grants actifs → `orders.status = refunded`, **même transaction** (G4 différé). Refund partiel : **aucune** révocation. | ✅ inclus dans `p4-c0-c3-secure-delivery-pipeline` |
| **P4-C3** Secure Secret Delivery Job | `SecureDeliveryJob` | Job queued `order_id` seul, tokens en mémoire, émission ou révocation/réémission au retry, envoi synchrone. | ✅ inclus dans `p4-c0-c3-secure-delivery-pipeline` |
| **P4-C4** Download Authorization | `p4-c4-c6-download-delivery-operations` | Échange fragment → header Bearer ; page DB-free ; préflight stockage hors transaction puis transaction G5 courte ; tentative CSPRNG dédiée, hash seul ; cookie `HttpOnly/SameSite=Strict` ; refus uniforme ; limiter HMAC-IP + public ID hashé. | ✅ TERMINÉ, MERGÉ ET VALIDÉ — PR #25, `de0fbe4` + `5bd86d3`, filtre **18/152** (autorisation + contrat exacts 12/109) |
| **P4-C5** HTTP File Delivery | `p4-c4-c6-download-delivery-operations` | Transaction DB courte → I/O privé hors transaction → finalisation DB courte ; GET/HEAD ; Range strict ; stream borné et fermé ; X-Accel local opt-in fail-closed ; aucune URL objet publique. | ✅ TERMINÉ, MERGÉ ET VALIDÉ — PR #25, `fefb28e` + `5bd86d3`, **13/202** |
| **P4-C6** Delivery Operations | `p4-c4-c6-download-delivery-operations` | Réconciliation sans restitution de quota ; détection d'abus pseudonymisée ; révocation support set-once ; purge G6 ; métriques/commandes/scheduler sans secret. | ✅ TERMINÉ, MERGÉ ET VALIDÉ — PR #25, `fefb28e`, **5/41** + P4C456 **10/72** |

Validation macro-gate après hardening : suite complète **623/4542**, Pint **217
fichiers**, `git diff --check` propre, **29 migrations** jusqu'à `000013`,
aucune `000014`. Niveaux maximum observés : `exists=0`, `size=0`,
`readStream=0`, `xAccelPath=0`, callback stream `=0`. Le kill switch service
coupe aussi les tentatives existantes et les secrets bruts sont marqués
`SensitiveParameter`. Les tests dédiés C1→C6 utilisent des processus et
connexions PostgreSQL runtime indépendants. **Couche Commerce → Paiement →
Livraison applicative complète.** Prochaine tâche : **P5-A0 — Analytics Schema
Foundation**.

### Dettes reconnues par D-030 et leur gate

| Dette mesurée | Gate |
|---|---|
| `DOWNLOAD_LINK_TTL_HOURS` / `DOWNLOAD_MAX_PER_GRANT` hérités dans `.env.example` ne sont pas autoritatifs | ✅ fermée P4-C4 : variables retirées ; `DELIVERY_GRANT_TTL_MINUTES` / `DELIVERY_GRANT_MAX_DOWNLOADS` bornés via `DeliveryConfig` |
| queue par défaut `database` sans table `jobs` / `job_batches` | P4-C0 |
| failed jobs par défaut **`database-uuids`** sans table `failed_jobs` (sous-point **ouvert**, à trancher au gate) | P4-C0 |
| `after_commit = false` sur toutes les connexions de queue | P4-C0 |
| `MAIL_MAILER=log` avec pipeline actif | ✅ fermé P4-C0 : refus fail-closed avant dispatch et avant émission |
| payload de queue contenant un secret | ✅ fermé P4-C0 : payload Laravel réel introspecté, job `order_id` seul ; Mailable queue/sérialisation refusées |
| enums `DownloadLogStatus` / `DownloadDenialReasonCode` prévus par D-029.5, absents de `app/Enums/` | P4-C4 |
| `coupons.redemptions_count` et plafonds coupon entièrement applicatifs (aucun trigger) | P3-D4 |
| aucun `Money` value object malgré `LARAVEL_PATTERNS.md` | P3-D1 |
| G4 rend la révocation obligatoire au remboursement total | P4-C2 |
| `SECURITE_TELECHARGEMENT.md` périmé (UPDATE direct du compteur, `grant_id` inexistante, colonnes P4-B absentes, ni tentative ni G5 ni frontière runtime) | ✅ fermé P4-C4/C6 : guide réécrit selon D-035/D-036 |

## P5 — ANALYTIQUE
| Tâche | Statut |
|-------|--------|
| **P5-A0** migration `000014` : parent `events` RANGE + `events_default`, append-only, index B-tree ciblés, aucun GIN spéculatif | ✅ DONE — mergé PR #26, head `8d9d8cc`, merge `94a8c08`, CI #32 |
| **P5-A0** migration `000015` : `analytics_sessions` sans FK, chemins/temps/attribution contraints | ✅ DONE — aucune ingestion ni session applicative |
| **P5-A0** migration `000016` : `daily_sales_stats`, `daily_product_stats`, `daily_funnel_stats` currency-safe | ✅ DONE — montants et compteurs BIGINT, aucune FK |
| ACL P5-A0 : aucun droit `PUBLIC`/`digitrove_runtime`, y compris partition DEFAULT, séquence et fonction | ✅ DONE — testé sous l'identité runtime réelle |
| Rollbacks isolés `000014`/`000015`/`000016` | ✅ DONE — trois frontières testées, aucune base temporaire résiduelle |
| Validation P5-A0 | ✅ DONE — 19 tests / 256 assertions ; suite complète 642 / 4779 ; Pint 235 ; 32 migrations |
| **P5-A1** First-party Event & Session Ingestion | ✅ DONE — mergé PR #27, head `955cc340`, merge `c699c5b9`, CI #33 ; consentement versionné, autorité SECURITY DEFINER et isolation des contextes d'authentification |
| Autorité P5-A1 `000017` | ✅ DONE — rôle NOLOGIN `digitrove_analytics_executor`, runtime EXECUTE-only, compatibilité session anonyme/même compte imposée dans les deux lookups SQL, rollback isolé ; 33 migrations |
| Validation P5-A1 | ✅ POST-MERGE GREEN — 74 tests / 400 assertions ; Pint 254 ; P5-A0/P4-C/P4-B/P3-B verts |
| **P5-A2** partitions et rollups autoritatifs | ✅ TERMINÉ, MERGÉ ET VALIDÉ — PR #28, head `03063db8`, merge `17aaa4f4`, CI #35, migration unique `000018`, D-039 |
| Correction dimensionnelle produit | ✅ engagement sans devise dans `daily_product_engagement_stats`; commerce currency-safe dans `daily_product_stats`; `purchased_product_id` immuable sans FK |
| Autorité de rollup | ✅ worker LOGIN EXECUTE-only, executor NOLOGIN, SECURITY DEFINER, recalcul UTC atomique/idempotent, commandes et scheduler conditionnel |
| Partitions calendaires contrôlées | ✅ création mensuelle bornée et idempotente, DEFAULT jamais déplacée, audit non destructif, ACL explicites |
| Validation P5-A2 | ✅ POST-MERGE GREEN — 34 migrations, P5-A2 25/198, P5-A1 74/400, P5-A0 19/256, suite 741/5381, Pint 278, rollback/concurrence verts |
| **P5-A3A/B** read boundary, overview et ventes | ✅ TERMINÉ, MERGÉ ET VALIDÉ — PR #29, head `31f986dc`, merge `2bbf2b52`, CI #36, D-040, migration `000019` |
| Autorisation Filament P5-A3 | ✅ admin actif/non supprimé uniquement; Gate `viewGlobalAnalytics`; staff/customer/suspended/blocked refusés |
| Frontière PostgreSQL de lecture | ✅ reader LOGIN dédié, transaction read-only, SELECT uniquement sur quatre rollups; runtime/worker/PUBLIC et données brutes refusés |
| Queries/UI P5-A3A/B | ✅ DTO immuables, cache borné et global, Vue d'ensemble/Ventes, devises séparées, trous/provisoire explicites, aucune API/export/opération |
| Validation P5-A3A/B | ✅ 35 migrations, P5-A3 32/193, suite 773/5575, Pint 302, rollback ACL isolé vert |
| **P5-A3C** produits et tunnel | ✅ TERMINÉ, MERGÉ ET VALIDÉ — [PR #30](https://github.com/mysterus44/DigiTrove/pull/30), head `642f8e35`, merge `87bf8399`, CI #37 success, D-041 |
| Queries/UI P5-A3C | ✅ Produits (`Produit #id`, engagement global + commerce par devise) et Tunnel agrégé non cohorté; trous/provisoire explicites, `add_to_carts` non suivi |
| Cache P5-A3C | ✅ payloads scalaires réhydratés en DTO immuables, compatibles Redis sans désérialisation d'objets |
| Validation P5-A3C | ✅ POST-MERGE — P5-A3C 22/178; P5-A3 agrégé 54/371; P5-A2 25/198; P5-A1 74/400; P5-A0 19/256; suite 795/5753; Pint 318; 35 migrations, aucune `000020` |
| **P5-A3D** | ⏸️ optionnel, reporté au durcissement préproduction; non bloquant pour P6 (D-042) |
| Portée analytique actuelle | ✅ globale uniquement; aucun vendeur, tenant, owner ou dimension propriétaire; aucun dashboard vendeur |
| Campaigns, segments et affiliation | ⬜ P6 — explicitement hors P5-A0 |
| **Clôture P5** | ✅ P5 ANALYTIQUE TERMINÉ, MERGÉ ET VALIDÉ — opérations CLI/scheduler désactivées par défaut, aucune commande opérationnelle Filament |

## P6 — CRM & MARKETING
Statut : **P6-A0 CRM IDENTITY, CONSENT AND AUTHORIZATION FOUNDATION TERMINÉ,
MERGÉ ET VALIDÉ** via PR #31, head `3276fef`, merge `47888d0` (D-043).
**P6-A1.0 DURABLE ORDER-TO-CRM ATTRIBUTION PIPELINE TERMINÉ, MERGÉ ET VALIDÉ**
via PR #32, head `562d83e`, merge `77652f2`, CI #39 success (D-045).
**D-046 COMPLÈTE** — P6-A1.1 audité et prêt à implémenter, aucun code P6-A1.1
créé. L'implémentation P6-A1.1, P6-A1.2+, P6-A2+, P7 et P5-A3D restent non
commencés.

| Tâche | Statut |
|-------|--------|
| **P6-A1.0 merge** | ✅ PR #32 mergée sur `77652f2`; CI #39 succès complet (syntaxe, Pint, tests, PG roles) |
| **P6-A0 merge** | ✅ PR #31, head `3276fef12d94f25e91fe6386e153ae3130424eb1`, merge `47888d0992aa5664e82341e52f6c3a68c4b0b15a`; CI GitHub non observé avant merge, validation locale complète verte |
| **P6-A0 migration `000020`** | ✅ Frontière historique P6-A0 : deux tables uniquement, 36 migrations; `000021` appartient exclusivement au gate P6-A1.0 |
| Identité CRM | ✅ E-mail exact normalisé par `trim` + CITEXT; aucune fusion alias/nom/IP/appareil/cookie/Visitor; `user_id` non autoritatif |
| Achats invités et comptes | ✅ Order invité exact supporté; compte lié seulement s'il est actif, non supprimé, vérifié et de même e-mail; `resolve_crm_contact` et le trigger de liaison refusent `suspended|blocked`; aucune liaison Visitor ni backfill |
| Consentement | ✅ Ledger append-only `email/promotional`; checkout grant seulement; compte vérifié grant/withdraw; policy version et idempotence SHA-256 obligatoires |
| Anonymisation | ✅ État irréversible supporté par le schéma; workflow et durée de rétention différés, aucune purge automatique |
| Autorité PostgreSQL | ✅ `digitrove_crm_executor` NOLOGIN/NOINHERIT; 3 SECURITY DEFINER; runtime EXECUTE-only; PUBLIC sans accès |
| Frontière applicative | ✅ Config fail-closed, 3 services, DTO minimaux, paramètres sensibles, erreurs sanitizées; aucune transaction ambiante |
| Autorisation CRM | ✅ Gate `manageCustomerRelationships`, admin actif/non supprimé uniquement, indépendante de l'analytics |
| Validation P6-A0 | ✅ 40 tests / 235 assertions; rollback isolé et 2 tests de concurrence; suite 835/5988; Pint 347; diff-check propre |
| Profil historique | ✅ `marketing_consent`, `consent_updated_at`, `orders_count` et `lifetime_value_minor` inchangés et non autoritatifs |
| Audit P6-A1 | ✅ D-044 : Commerce autoritatif, acquisition `paid|partially_refunded|refunded`, `orders.paid_at`, `orders.total_minor`, unique Payment réussi et Refund |
| **P6-A1.0 migration `000021`** | ✅ Migration unique; `crm_order_attribution_outbox` durable sans PII + `crm_order_attributions` immuable; 37 migrations, aucune `000022` |
| Capture transactionnelle | ✅ Trigger sur transition `false → true` du prédicat complet `status acquis + paid_at non NULL`, dans les deux ordres de mise à jour; snapshot `contact_id` existant seulement, aucun resolver/contact créé dans la transaction financière; disponibilité `TIMESTAMPTZ(6)` |
| Résolution durable | ✅ `OrderPaid` est un signal faible post-commit; job unique à TTL 3600 s + sweeper borné traitent l'outbox via autorités PostgreSQL; après expiration PostgreSQL garde l'idempotence; invalidité legacy → `unattributable/invalid_email_contract`, conflit → terminal explicite, aucun rollback financier |
| Anonymisation/identité | ✅ Snapshot contact sans PII préserve l'ancien lien après anonymisation; aucun héritage par un nouveau contact au même e-mail; compte seulement actif/non supprimé/vérifié/exact, sinon guest exact; aucun Visitor |
| Autorité P6-A1.0 | ✅ 5 fonctions, 3 triggers, propriétaire `digitrove_crm_executor`; runtime EXECUTE-only sur list/process, aucune lecture/écriture directe ni nouvelle identité PostgreSQL |
| Orchestration P6-A1.0 | ✅ processor/dispatcher fail-closed, job `ShouldBeUnique` payload `orderId` avec `uniqueFor=3600`, listener faible, commande sweeper et scheduler 5 minutes désactivés par défaut |
| Validation P6-A1.0 | ✅ 48 tests / 285 assertions; 2 scénarios de concurrence, rollback isolé, suite complète 883/6273, Pint 367, diff-check propre |
| Rollups CRM P6-A1.1 | ✅ `crm_contact_commerce_rollups`, clé `(contact_id,currency)`, BIGINT, net = brut - remboursé, projection mutable par autorité PostgreSQL uniquement (D-046) ; `acquired_orders_count` inclut les commandes gratuites ; owner `digitrove_crm_executor` ; aucun accès runtime ; aucun modèle/service/job P6-A1.1 |
| Segments | 🚧 IMPLÉMENTÉ P6-A2 (D-050, migration 000025, pré-merge) — ancien plan : ⬜ modèle recommandé C : définition dynamique allowlistée/versionnée + membres matérialisés; aucun SQL, colonne, opérateur, JSONPath ou PHP libre |
| Paniers abandonnés | ⏸️ tables `carts`/`cart_items` présentes et checkout transactionnel existant, mais aucun flux public de création/abandon, aucune identité e-mail sur panier invité, aucun job/consentement/frequency cap |
| Affiliation | ⏸️ **AFFILIATION NON FONDÉE — HORS PREMIER GATE P6**; aucune table/service, D-014 impose plus tard un compte et des tables dédiées |
| Exports | ⏸️ après identité, consentement, segments et membership fiables; futur job privé audité, borné, expirant et protégé contre les formules CSV |
| Découpage | ✅ P6-A0 → ✅ A1.0 attribution → ✅ A1.1 autorité rollup → A1.2 worker/réconciliation → A1.3 backfill explicite → A2 segments → B0 vues → B1 exports → C paniers/relances → D affiliation |
| Vues admin CRM P6-B0 | 🚧 **IMPLÉMENTÉ, VALIDÉ PAR CAMPAGNES CIBLÉES, EN ATTENTE DE PR/CI** (D-052, migration **000026**, **42 migrations**). **7 autorités de lecture** `STABLE`+`SECURITY DEFINER` (owner `digitrove_crm_executor`, runtime EXECUTE-only, aucun nouveau rôle) — D-051 avait annoncé « aucune migration », l'audit a prouvé qu'aucune autorité contact/consentement/commerce/membership/versions n'existait. Verticales Contacts et Segments, constructeur de critères **structuré** (aucun textarea/JSON/SQL), e-mail **exact normalisé dans l'autorité**, anonymisé ⇒ `email IS NULL` irrécupérable, **aucun total multi-devises**, **aucune division par 100**, aucune route publique, aucun envoi, aucun export. **PostgreSQL reste l'autorité finale du DSL** (prouvé hors builder : 7 définitions invalides refusées, 0 version créée). ⚠️ **Aucune suite complète locale B0 exécutée ni revendiquée** — différée au stack B1. P6B0+P6B01 **113 tests / 655 assertions**, Pint **446 fichiers**. |
| Prochain gate | 🚧 P6-A2 TERMINÉ, MERGÉ ET VALIDÉ (PR #36, merge 920eb1b9, CI #43, D-049/D-050). **P6-B0 implémenté (D-052), en attente de PR/CI. P6-B1 — Private Audited CRM Exports = PROCHAIN GATE, NON COMMENCÉ** (migration `000027` attendue). |

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
