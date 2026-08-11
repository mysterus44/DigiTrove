# CLAUDE.md — Point d'entrée Claude Code (miroir d'`AGENTS.md`)

Tu es **ARIA-DEV** sur **DigiTrove** : e-commerce + CRM/ERP 100 % autonome de vente de
produits digitaux (logiciels, packs de formation, e-books). Vision : indépendance
absolue, aucune plateforme tierce. Réécriture complète (Laravel 13.19 / PostgreSQL 16 /
Filament 5), pas un refactor du legacy PHP/SQLite.

Claude Code et Codex sont **le même développeur** qui change d'outil. Jamais en
parallèle. La continuité passe par `HANDOFF.md` + git.

## 📂 À LIRE EN PRIORITÉ (dans l'ordre, à chaque session)

1. `HANDOFF.md` — carnet de passation : où le binôme s'est arrêté + PROCHAINE TÂCHE
2. `PROJECT_CONTEXT.md` — contexte produit + stack
3. `DigiTrove_Schema_BDD_v1.md` — schéma relationnel de référence (avant toute migration)
4. `.context/CO_CODING_PROTOCOL.md` — rituel de co-codage anti-conflit
5. `.context/memory/PROGRESS_TRACKER.md` — avancement détaillé
6. `.context/memory/DECISIONS_LOG.md` — décisions figées (ne jamais re-décider)
7. Le skill pertinent dans `.context/skills/`

➡️ **Règles détaillées communes : voir `AGENTS.md`** (identité, conventions Laravel,
sécurité tolérance zéro, interdictions, processus 8 étapes). Ne pas dupliquer ici.

## 🔑 GARDE-FOUS NON NÉGOCIABLES

- **Plan avant code.** Annonce le plan, attends validation. **Une feature à la fois.**
- **La BDD avant la logique.** Aucune feature codée avant sa migration testée.
- **Aucun secret** dans le code ni un commit (`.env` jamais committé).
- **Argent en `BIGINT`** (unités mineures), devise explicite — jamais FLOAT/REAL/
  DOUBLE/DECIMAL/NUMERIC. Snapshot prix + nom dans `order_items`.
- Fichiers digitaux : disque **privé**, jamais `public/`. Token de téléchargement **haché**.
- **Aucun push direct sur `main`.** La suite passe par `p0-foundations-laravel13`.
- **Tests sur PostgreSQL réel** (image `digitrove-php:dev` + Postgres du `docker-compose`),
  jamais SQLite. `php artisan test` + `./vendor/bin/pint --test` verts avant commit.
- **Arrêt obligatoire** dès qu'une validation humaine (KingKouda) est requise — surtout
  avant toute migration d'une nouvelle phase.

## 📌 ÉTAT (résumé — détail dans PROGRESS_TRACKER.md)

- **P0 Fondations** ✅ terminé · **SITE-00** ✅ mergé · **P1 Identité** ✅ mergé
- **P2 Catalogue** ✅ mergé (PR #3 → `aff4d05`)
- **P3 Commerce** ✅ schéma P3A/P3B/P3C mergé · P3C plan finalisé (D-028, 1A–5A) ·
  **P3C-B `payment_webhook_events` mergé** (PR #8 → `51c4847`) + **durcissement P3C-B.1**
  (PR #9 → `13932ac`, index de rejeu réservé aux signés) · **P3C-C `refunds` mergé**
  (PR #10 → `122332a`, commit final `1270c53`)

- **Plan P4 Livraison** ✅ finalisé, validé et corrigé (**D-029 + D-029.1 B–A–B +
  D-029.2 + D-029.3 + D-029.4 + D-029.5**) : gates isolés dans l'ordre — **P4-A0
  durcissement `product_files` (`000008`, trigger G0, `version` FIGÉE avec le
  contenu) MERGÉ (PR #11 → `a047571`)** → **P4-A1 snapshot
  `order_item_bundle_components` (`000009`, D-029.3 : S1/S2/S3, bundles imbriqués
  exclus, exhaustivité applicative) MERGÉ (PR #12 → `93d1f17`)** → **P4-A2
  `download_grants` (`000010`, **D-029.4** : option A émission immédiate = snapshot
  applicatif, G1–G4, aucun DEFAULT commercial) MERGÉ (PR #13 → `77f3766`)** →
  **P4-A2.1 hardening `download_grants` (`000011`, bénéficiaire G3 null-safe et
  `updated_at` G2 lié au cycle de vie) MERGÉ (PR #14 → `2c25e2a`)** → **P4-B0
  frontière de privilèges runtime (`000012` ACL, D-029.6) MERGÉ (PR #15 →
  `6d23e546`)** → **P4-B `download_logs` (`000013`) MERGÉ (PR #16 →
  `98441014`)** — **SCHÉMA P4 COMPLET** ;
  `licenses` exclu de P4. Une migration, une branche, une frontière de rollback par
  gate ; migration N+1 jamais créée avant merge du gate N. Détail dans le bloc P4 de
  `DigiTrove_Schema_BDD_v1.md`.

P4-A2.1 est **terminé et mergé** via
[PR #14](https://github.com/mysterus44/DigiTrove/pull/14), merge `2c25e2a` : le
hotfix remplace uniquement G2/G3, garde 4 fonctions / 5 triggers et préserve
`000010` immuable. Validation post-merge : 27 migrations ; P4-A2.1 7/113 ; suite
158/2301 ; Pint 112 ; rollback isolé `000011` restaurant exactement G2/G3 d'origine.
**P4-B a été implémenté** sur `p4-b-download-logs` (`8cf24a8`, poussée) selon
D-029.5 — mais est **BLOQUÉ AU MERGE**. Un audit offensif a prouvé que l'autorité
de consommation de G2, `pg_trigger_depth() > 1`, démontre seulement l'imbrication,
jamais l'origine : un trigger temporaire ou permanent créé par le rôle applicatif
incrémente `downloads_count` **sans** créer de `download_logs`. Le rôle unique
`digitrove` est superuser, propriétaire, migrateur ET runtime — aggravant le risque.

**Correctif décidé — D-029.6, gate préalable P4-B0** (option A renforcée) :
séparation de rôles PostgreSQL (`digitrove` migrateur/propriétaire ·
`digitrove_runtime` restreint sans TEMP/DDL/UPDATE compteur · `digitrove_download_
executor` NOLOGIN propriétaire de G5), G5 `SECURITY DEFINER` avec `search_path`
épinglé et objets qualifiés, G2 exigeant `current_user = digitrove_download_executor`
comme preuve d'origine principale, fermeture explicite de TEMP/CREATE/EXECUTE à
PUBLIC, double connexion Laravel (`pgsql` runtime + `pgsql_migration` migrateur),
provisioning cluster par script idempotent
(`docker/postgres/provision-runtime-roles.sql`) + migration ACL `000012`.

**P4-B0 est TERMINÉ ET MERGÉ** (PR #15 → `6d23e546`, parents `a3eac5e` +
`9b69f192`) : frontière de privilèges PostgreSQL runtime active sur la stable.
Faisabilité prouvée avant tout code (la danse `SET LOCAL ROLE` donne la propriété
de G5 à l'exécuteur sans CREATE permanent ; un trigger `SECURITY DEFINER` se
déclenche même sans EXECUTE pour le rôle déclencheur, avec `session_user=runtime`
/ `current_user=executor`). Validation post-merge : provisioning idempotent,
identités migration/runtime prouvées, 26 fonctions trigger sans EXECUTE runtime,
suite P4-B0 13/84, suite complète 171/2385, Pint 117, 28 migrations, zéro résidu.
**Trois pièges
retenus pour P4-B** : un `REVOKE EXECUTE` par fonction doit venir du propriétaire ;
l'exécuteur exige `SELECT` en plus de `UPDATE` ; pour les default privileges des
FONCTIONS, la forme GLOBALE `ALTER DEFAULT PRIVILEGES FOR ROLE r REVOKE EXECUTE ON
FUNCTIONS FROM PUBLIC` fonctionne (la forme `IN SCHEMA public` non), elle est propre
au rôle créateur (migrateur ET exécuteur via `SET ROLE`) — la migration `000012`
les pose, donc G5 naît verrouillée mais reçoit `GRANT EXECUTE … TO digitrove` le
temps d'attacher son trigger.

**P4-B est TERMINÉ ET MERGÉ** via
[PR #16](https://github.com/mysterus44/DigiTrove/pull/16), merge `98441014`
(parents `d7c53cf` + `49692e25`) : migration **`000013`**, `download_logs` à 15
colonnes. Le contrat D-029.5 est intact (CHECK, tentative dédiée, Range/retries
sur une seule ligne, HEAD hors gate, `completed` = remise au mécanisme, rétention,
HMAC IP versionné). Durcissement D-029.6 : préconditions fail-closed, **G5
`SECURITY DEFINER` possédée par `digitrove_download_executor`** (search_path
épinglé, objets qualifiés, sans EXECUTE PUBLIC/runtime), **autorité de G2 par
`current_user = digitrove_download_executor`** (profondeur en défense secondaire),
ACL `download_logs` normalisées, et `UPDATE (updated_at) ON orders` accordé à
l'exécuteur — privilège minimal exigé par `FOR UPDATE OF orders` (SELECT seul
refusé, mesuré). **La vulnérabilité est fermée** : un trigger forgé même par le
PROPRIÉTAIRE superuser est refusé en 23514 ; le runtime est arrêté en 42501.
Validation post-merge : 29 migrations, P4-B 19/603, suite complète 190/2975,
Pint 121, provisioning idempotent, identités migration/runtime prouvées,
6 fonctions / 7 triggers P4, G4 différé, zéro résidu.

**Le schéma P4 Livraison est COMPLET** (29 migrations). La couche applicative
P4-C0→C6 est terminée, mergée et validée via PR #24 et
[PR #25](https://github.com/mysterus44/DigiTrove/pull/25), merge `109fde4c`,
CI #31 success (D-036). Aucune migration `000014` : autorisation, fichier HTTP
et opérations utilisent exclusivement `download_grants`, `download_logs`,
G1→G6 et le disque privé existants. Le hardening `5bd86d3` garantit qu'aucun I/O
stockage privé ni callback stream ne s'exécute sous transaction PostgreSQL ; la
livraison suit transaction DB courte → I/O hors transaction → finalisation DB
courte. Le pipeline reste désactivé par défaut et son kill switch coupe aussi les
tentatives déjà émises.

- **Couche applicative planifiée** ✅ **D-030 FINALISÉE ET VALIDÉE**
  (KingKouda : **Q1 = A renforcée**, **Q2 = B renforcée**, **Q3 = A**) —
  **12 gates, AUCUNE migration** :
  `P3-D1` Pricing & Quote Kernel → `P3-D2` Checkout Order Transaction →
  `P3-D3` Payment Initiation → `P3-D4` Server-side Payment Confirmation →
  `P3-D5` OrderPaid Domain Event → **`P4-C0` Queue & Mail Secret Safety** →
  `P4-C1` Download Grant Issuance → (`P4-C2` Refund Grant Revocation ∥
  `P4-C3` Secure Secret Delivery Job) → `P4-C4` Download Authorization →
  `P4-C5` HTTP File Delivery → `P4-C6` Delivery Operations.
  **Nommage** : tarification/checkout/paiement = **P3-D** ; livraison = **P4-C**.
- **Q1 = A renforcée** : token CSPRNG, mémoire vive seulement, SHA-256 en base,
  **jamais reconstructible** (ni dérivation, ni outbox, ni stockage temporaire).
  Panne après COMMIT ⇒ **révoquer puis réémettre**, jamais « réessayer avec
  l'ancien token » (l'unique partiel actif l'impose physiquement). Sémantique
  **at-least-once** assumée pour l'e-mail — aucun exactly-once promis.
- **Q2 = B renforcée** : **job queued unique par Order portant `order_id` SEUL**.
  Tokens générés dans le worker, e-mail composé et envoyé **synchroniquement**
  dans le même processus. Aucun token/hash/lien/`storage_path`/IP/clé HMAC dans
  Redis, `jobs`, `failed_jobs`, une exception ou un log.
- **Q3 = A** : coupon scopé sans ligne éligible ⇒ **refus explicite** (jamais de
  retrait silencieux) ; remise allouée par **Hamilton (plus grand reste)**,
  départage `résidu ↓ → product_id ↑ → id de ligne ↑` ; aucun `float`, aucune
  division flottante, aucun `round()` sur les montants.
- **Ordonnancement contraint** : `P4-C0` bloque tous les gates de livraison ;
  **`P4-C2` doit être mergé avant l'activation réelle de `P4-C3`** (G4 différé sur
  `orders` rend les remboursements impossibles sans révocation) ; `P4-C1` peut
  vivre comme service non câblé ; **aucun téléchargement public avant `P4-C5`**.
- **Dettes reconnues (D-030)** : `config/queue.php` défauts `database` /
  **`database-uuids`** / `job_batches` **sans aucune table** ; `after_commit =
  false` partout ; `MAIL_MAILER` par défaut `log` (fuite de token dans
  `storage/logs`) ; `phpunit.xml` en `sync` ⇒ sérialisation jamais prouvée ;
  `DOWNLOAD_*` de `.env.example` lues par aucun `config/` ; enums `DownloadLog*`
  absents ; `coupons.redemptions_count` 100 % applicatif ; pas de `Money`.
- ✅ **`.context/skills/SECURITE_TELECHARGEMENT.md` est aligné sur
  D-035/D-036** : G5/G6, tentative authentifiée, disque privé, frontières
  runtime et séparation stricte entre transactions PostgreSQL et I/O stockage.

- **`P3-D1 — Pricing & Quote Kernel` ✅ TERMINÉ ET MERGÉ** via
  [PR #17](https://github.com/mysterus44/DigiTrove/pull/17), merge `78f475e7`
  (parents `ba48cce1` + `95ab5627`), CI #18 verte, 17 fichiers exactement :
  **9 classes, AUCUNE migration, AUCUNE écriture BDD** —
  `App\Support\{IntegerMath, Money}` et
  `App\Services\Pricing\{PricingService, DiscountAllocator, PricedQuote,
  PricedLine, CouponSnapshot, PricingException, PricingRefusalReason}`.
  Prix lus exclusivement dans `product_prices` sur `(product_id, currency)` +
  `is_active` ; produit **fail-closed** (`published` seul) ; **aucun repli de
  devise** ; fenêtre coupon inclusive aux deux bornes sur un instant unique ;
  portée produit ∪ catégorie (**union**) ; `min_order_minor` mesuré sur le panier
  entier, remise sur le sous-total éligible ; plafonds puis refus si remise nulle ;
  **allocation Hamilton** (résidu ↓ → `product_id` ↑ → id de ligne ↑) ;
  `taxMinor` explicitement `0` (aucune politique fiscale) ; `coupon_redemptions`
  et `redemptions_count` restent P3-D4. Unit **36/55**, Feature **48/167** sous
  `digitrove_runtime`, suite complète **274/3206**, Pint **132**.

- **`P3-D1.1 — Hardening post-merge` ✅ TERMINÉ ET MERGÉ** via
  [PR #18](https://github.com/mysterus44/DigiTrove/pull/18), merge `0e18d69d`
  (parents `78f475e7` + `6349fc19`), CI #19 verte, **12 fichiers exactement**.
  Le merge de la PR #17 ayant précédé la revue contradictoire, l'audit post-merge
  a démontré **quatre défauts de contrat défensif**, tous fermés — **le calcul de
  prix était correct et aucune corruption monétaire n'était possible** :
  **A1** `Money::of(100, "XOF\n")` accepté (le `$` de PCRE matche avant un saut
  de ligne final) → ancres `/\A[A-Z]{3}\z/` + `Money::assertValidCurrency()`
  source unique ; **A2** garde-fou P4-B laissant passer
  `Services/Fulfilment/GrantIssuer.php` → **allowlist fail-closed** des 7 fichiers
  autorisés sous `app/Services` (**chaque gate futur doit l'élargir
  explicitement**) ; **A3** DTO de pricing sans invariants → constructeurs en
  miroir des CHECK `orders`/`order_items` ; **A4** `line_id` dupliqué écrasé en
  silence → refus explicite. Unit **94/117**, P4-B **20/616**, suite complète
  **333/3272**, Pint **132**, 29 migrations inchangées, aucune politique métier
  modifiée.

  ⚠️ **`P4B_ALLOWED_SERVICE_FILES` est une frontière historique fail-closed** :
  tout gate ajoutant un fichier sous `app/Services` doit l'**élargir
  explicitement**, sinon le garde-fou P4-B échoue — c'est voulu.

**`P3-D1` ET `P3-D1.1` SONT TERMINÉS ET MERGÉS.** Validation post-merge : Unit
94/117, Feature P3-D1 48/167, P4-B 20/616, P3A 15/139, P3B 18/357, Catalogue
12/111, suite complète **333/3272**, Pint **132**, 29 migrations inchangées,
aucune politique métier modifiée, branches locales supprimées et distantes
conservées, `origin/main` intact.

- **`P3-D2 — Checkout Order Transaction` ✅ TERMINÉ, MERGÉ ET VALIDÉ** via
  [PR #19](https://github.com/mysterus44/DigiTrove/pull/19), head `ef758bbb`,
  merge `4c691864`, CI #22 verte, 13 fichiers
  (**D-031** + **D-032**, aucune migration) :
  3 classes `App\Services\Checkout\{OrderService, CheckoutException,
  CheckoutRefusalReason}`. Transaction unique Order `pending` + `order_items` +
  snapshot bundle exhaustif + Cart → `converted`.
  **Q1 = C** : composant de bundle soft-deleted (ou bundle imbriqué) ⇒ checkout
  **refusé**, jamais de filtrage silencieux ni de snapshot partiel.
  **Q2 = B** : Cart converti dans la même transaction, jamais sur rejeu.
  Verrouillage `carts` → `products` du panier → `product_bundles` → **produits
  enfants** (ce dernier verrou rend Q1=C applicable, `55P03` prouvé).
  Idempotence : digest SHA-256 seul, rejeu avant toute règle d'état, égalité par
  comparaison de colonnes, `orders_cart_id_unique` en backstop, chaque `23505`
  traduit par contrainte. Order gratuite `pending`, **aucune** consommation de
  coupon (P3-D4), aucun Payment.
- **`D-032` — finalisation pré-publication de P3-D2** : (1) l'expiration des
  commandes `pending` n'est plus codée en dur — `config/checkout.php` +
  `CHECKOUT_PENDING_TTL_MINUTES`, **défaut 30 min**, minutes entières ≥ 1,
  valeur invalide ⇒ échec **avant toute écriture**, rejeu conservant
  l'`expires_at` d'origine ; (2) le retry d'`order_number` est protégé par un
  **savepoint** (transaction Laravel imbriquée) — sans lui, un `23505` avorte
  toute la transaction et le retry ne peut recevoir que **`25P02`**, prouvé
  empiriquement. Primitive `App\Support\OrderNumberGenerator` extraite (hors
  `app/Services`, allowlist P4-B inchangée).
- **Hotfix temporel P3-D1** ✅ mergé via
  [PR #20](https://github.com/mysterus44/DigiTrove/pull/20), head `0d6e95d9`,
  merge `0854a393`, CI #23 verte : un test P3-D1 **préexistant** mélangeait une
  fenêtre de coupon relative à `now()` avec une référence figée au
  2026-07-21 12:00 et est devenu rouge au changement de date.
  **Aucune régression métier P3-D2.** Un seul fichier de test corrigé.

**`P3-D1`, `P3-D1.1` ET `P3-D2` SONT TERMINÉS, MERGÉS ET VALIDÉS.** Validation
post-merge sur la stable `0854a393` : P3-D1 **48/167**, P3-D2 **66/323**, P4-B
**20/620**, suite complète **399/3599**, Pint **138**, **29 migrations**, aucune
`000014`. Concurrence prouvée sous les vraies identités (seed `digitrove`, A/B
`digitrove_runtime`, `TEMP` refusé) : `55P03` ×2, `23505` sur
`orders_cart_id_unique`, aucun `42501`.

- **`P3-D2.1 — PostgreSQL Constraint Classification Hardening` ✅ TERMINÉ, MERGÉ
  ET VALIDÉ** via [PR #21](https://github.com/mysterus44/DigiTrove/pull/21),
  head `3adb2824`, merge `9b0aa924`, CI #24 verte, 7 fichiers, aucune migration. `OrderService` classait des erreurs BDD sur la **présence d'un nom
  de contrainte dans le message** d'un `Throwable`, sans exiger le SQLSTATE
  `23505` : un message usurpé provoquait un **retry `order_number` injustifié**
  et de faux `CartAlreadyCheckedOut` / `IdempotencyConflict`. **Aucune régression
  métier observée** — défaut purement défensif. Correctif : primitive
  `App\Support\PostgresConstraintViolation` (SQLSTATE lu dans `errorInfo[0]`,
  nom de contrainte extrait **après** confirmation du `23505`, **égalité
  exacte**). Unit **20/20**, P3-D2 **71/344**, suite **424/3641**, Pint **140**.
  ⚠️ **`PostgresConstraintViolation` est obligatoire en P3-D3** pour toute
  classification de contrainte `payments` — mais **uniquement** là où le contrat
  exige une paire structurée SQLSTATE + nom exact : un échec fournisseur, un
  timeout ou un `40001` ne sont pas des violations d'unicité.

- **`P3-D3 — Payment Initiation` ✅ TERMINÉ, MERGÉ ET VALIDÉ** via
  [PR #22](https://github.com/mysterus44/DigiTrove/pull/22), head
  `5188e6cc5c82358cdc1e72efe3e8e3345babf930`, merge
  `70379a02e1f220e6dac4f53552b8a5712c6e4815` (parents `6e701a1` + `5188e6cc`),
  **CI #26 success**, 14 fichiers exactement (+2005/-7 ; **D-033**, aucune
  migration, aucun adaptateur réel) : port fournisseur abstrait
  `App\Contracts\Payments\*` + `App\Services\Payments\*`. Initiation en **deux
  phases** — réservation `payments` `pending` committée, appel du port **hors
  transaction**, finalisation de la référence en seconde transaction.
  `payment.public_id` = clé d'idempotence fournisseur ; **clé brute jamais
  persistée, loguée ni envoyée** ; une seule tentative vivante par Order ;
  timeout ambigu ⇒ tentative laissée `pending` ; **aucune confirmation** (Order
  reste `pending`, aucun `succeeded`, aucun coupon consommé, aucun
  webhook/`OrderPaid`/grant) ; classification des `23505` via
  `PostgresConstraintViolation`. **Durci en revue pré-merge (5 findings)** :
  garde `transactionLevel = 0`, horloge injectée unique (`isExpired` `>=`),
  reprise de réponse perdue (rappel fournisseur idempotent), toute exception BDD
  inconnue ⇒ `IntegrityFailure` sanitizé, preuves C1–C4 service-level. Suite non
  transactionnelle dédiée. Validation post-merge sur la stable `70379a0` : P3-D3
  **54/246**, P3-D2.1 **20/20**, P3-D2 **71/344**, P3C-A **16/219**, P4-B
  **20/628**, suite complète **478/3894**, Pint **149**, **29 migrations**,
  aucune `000014`, `git diff --check` propre.

- **`P3-D4 + P3-D5 — Confirmation serveur + OrderPaid` ✅ TERMINÉS, MERGÉS ET
  VALIDÉS** via [PR #23](https://github.com/mysterus44/DigiTrove/pull/23), head
  `8aad4fc`, merge `a62563fdb8aad86bef5cf1ac27b4bebcb5259342`
  (parents `0b9e7ac` + `8aad4fc`), **CI #27 success**
  (macro-gate `p3-d4-d5-payment-confirmation`, **D-034**,
  **aucune migration** — 29 inchangées) : webhook CinetPay signé (HMAC `x-token`,
  `hash_equals`) + dédupliqué (`PostgresConstraintViolation`, jamais de
  substring), **contre-appel fournisseur obligatoire hors transaction** (le corps
  du webhook n'est jamais autoritatif), confirmation atomique
  `Payment pending→processing→succeeded` + `Order → paid`, argent comparé en
  entiers, **succès incohérent ⇒ `payment_review`** (jamais faux `paid` ni
  `failed`, référence jamais écrasée), **coupon consommé exactement une fois au
  `paid`** sous `coupons FOR UPDATE`, branche gratuite `total_minor = 0` sans
  Payment (`FreeOrderConfirmationService`), **`OrderPaid` (`order_id` seul) après
  COMMIT** (fenêtre résiduelle assumée, pas d'outbox). **CinetPay désactivé par
  défaut** (`PAYMENT_DRIVER` vide, binding fail-closed, secrets en config
  uniquement) ; **PowerPay** = scaffold `docs/integrations/POWERPAY_SETUP.md`
  sans endpoint inventé. Aucune livraison, aucun listener P4-C. Validation : 59
  tests P3-D4/D5 (adapter 23, binding 5, confirmation 16 dont C3/C4 réels,
  webhook 8 dont C1/C2, événement 7 dont C5), suite complète **537/4083**, Pint
  **175**, **29 migrations**, aucune `000014`.

- **`P4-C0 → P4-C3 — Secure Delivery Pipeline` ✅ TERMINÉS, MERGÉS ET VALIDÉS**
  via [PR #24](https://github.com/mysterus44/DigiTrove/pull/24), head
  `1492cd137a904c6025504fc5fd0cf0d51bd92db9`, merge
  `701cfa4f95700b61d70f242e15feef264adffa8b`, **CI #29 success**
  (macro-gate `p4-c0-c3-secure-delivery-pipeline`, **D-035**,
  **aucune migration** — 29 inchangées) : pipeline **désactivé par défaut**.
  `OrderPaid` → listener `QueueSecureDelivery` (si `DELIVERY_PIPELINE_ENABLED`) →
  job `SecureDeliveryJob` **unique, `order_id` seul** → `GrantIssuanceService`
  (tokens CSPRNG mémoire, SHA-256 en base, snapshot bundle unique autorité, **no
  implicit upgrade**, révoque/réémet au retry) → Mailable **synchrone jamais
  `ShouldQueue`**. `RefundCompletionService` : partial garde les grants, full
  révoque tout dans la même transaction (G4). Adaptateurs e-mail/refund non
  inventés (scaffolds). **Aucun endpoint, aucun `download_logs`, aucun
  `downloads_count`.** P4-C **50 tests / 165 assertions** (dont quatre preuves
  de concurrence PostgreSQL), suite **587/4259**, Pint **191**, 29 migrations.

- **`P4-C4 + P4-C5 + P4-C6 — Authorization, HTTP Delivery & Operations`
  ✅ TERMINÉS, MERGÉS ET VALIDÉS** via PR #25, head `07d566f`, merge
  `109fde4c`, CI #31 success (**D-036**, aucune migration) :
  fragment e-mail retiré par page DB-free → Bearer sur POST seulement → secret
  de tentative distinct en cookie `HttpOnly/SameSite=Strict`; G5 crée une ligne
  `started` et consomme exactement une unité. GET/HEAD/Range réutilisent cette
  ligne ; HEAD reste inerte ; stream privé borné, X-Accel local opt-in
  fail-closed, aucune URL objet. Réconciliation, détection d'abus HMAC,
  révocation support, purge G6, métriques et scheduler n'exposent aucun secret.
  Hardening : préflight stockage hors transaction, transactions DB courtes
  avant/après l'I/O, garde transaction ambiante, locator fail-closed, kill
  switch couvrant les tentatives existantes et secrets bruts
  `SensitiveParameter`. Filtre P4-C4 **18/152** (autorisation + contrat exacts
  **12/109**), P4-C5 **13/202**, P4-C6 **5/41**, P4C456 **10/72**, suite
  complète **623/4542**, Pint **217**, 29 migrations, aucune `000014`.

- **`P5-A0 — Analytics Schema Foundation` ✅ TERMINÉ, MERGÉ ET VALIDÉ** via
  [PR #26](https://github.com/mysterus44/DigiTrove/pull/26), head `8d9d8cc`,
  merge `94a8c08`, CI #32 success (**D-037**) :
  migrations `000014` à `000016`; parent `events` RANGE + `events_default`,
  append-only; `analytics_sessions`; trois rollups journaliers currency-safe;
  cinq modèles/factories structurels. Aucune FK vers le commerce, aucun droit
  `PUBLIC`/`digitrove_runtime`, aucune ingestion, route, API, service, job,
  campagne, segmentation ou P6/P7. Validation : **32 migrations**, P5-A0
  **19/256**, suite complète **642/4779**, Pint **235**, rollbacks isolés verts.

- **`P5-A1 — First-party Event & Session Ingestion` ✅ TERMINÉ, MERGÉ ET
  VALIDÉ** via PR #27, head `955cc340`, merge `c699c5b9`, CI #33 success
  (**D-038**) :
  migration `000017`, executor PostgreSQL NOLOGIN, fonction SECURITY DEFINER,
  runtime EXECUTE-only, consentement versionné, cookies first-party chiffrés,
  `page_view`/`product_view` uniquement, HMAC IP, rate limiting et
  sessionisation atomique isolée par contexte d'authentification dans
  PostgreSQL. Une session anonyme peut être enrichie au login; une session
  identifiée n'est jamais réutilisée après logout ni sous un autre compte.
  Ingestion désactivée par défaut; aucune écriture sans consentement courant;
  aucune donnée financière cliente, queue, rollup ou fournisseur tiers.
  Validation : **33 migrations**, P5-A1 **74/400**, suite complète **716/5183**,
  Pint **254**.

- **`P5-A2 — Authoritative Rollups and Safe Partition Operations` ✅
  TERMINÉ, MERGÉ ET VALIDÉ** via PR #28, head `03063db8`, merge `17aaa4f4`,
  CI #35 success (**D-039**) :
  migration unique `000018`; engagement produit sans devise séparé des
  projections commerciales par devise; snapshot
  `order_items.purchased_product_id` positif, sans FK et immuable; rollups UTC
  autoritatifs sans join catalogue; worker LOGIN EXECUTE-only et executor
  NOLOGIN; trois fonctions SECURITY DEFINER; partitions mensuelles bornées sans
  déplacement de DEFAULT; commandes et scheduler conditionnel. Validation :
  **34 migrations**, P5-A2 **25/198**, suite complète **741/5381**, Pint
  **278**, rollback isolé, ACL et concurrence PostgreSQL verts.

**P5-A3A/B ADMIN ANALYTICS READ BOUNDARY, OVERVIEW AND SALES TERMINÉ, MERGÉ ET
VALIDÉ** via PR #29, head `31f986dc`, merge `2bbf2b52`, CI #36 success
(**D-040**). Seul un admin actif et non
supprimé accède au panel et à l'analytique globale; `staff`, `customer` et les
comptes inactifs sont refusés. Le rôle PostgreSQL
`digitrove_analytics_reader` et la connexion `pgsql_analytics_reader` lisent
uniquement les quatre rollups en transaction read-only. Vue d'ensemble et
Ventes séparent strictement les devises, exposent les trous de calcul et le jour
UTC courant provisoire, sans données brutes, Commerce, worker, opération, API
ou export. Validation historique : **35 migrations**, P5-A3 **32/193**, suite
complète **773/5575**, Pint **302**.

**P5-A3C PRODUCT AND FUNNEL ANALYTICS VIEWS TERMINÉ, MERGÉ ET VALIDÉ** via
[PR #30](https://github.com/mysterus44/DigiTrove/pull/30), head `642f8e35`,
merge `87bf8399`, CI #37 success (**D-041**). Produits : engagement global sans
devise, commerce par devise,
libellé `Produit #<id>`, aucun join catalogue ni faux ratio de cohorte. Tunnel :
volumes calendaires et ratios agrégés non cohortés, trous à `NULL`, jour UTC
courant provisoire. Même reader D-040, transactions read-only et cache scalaire
compatible Redis; aucune migration/ACL supplémentaire. Validation post-merge :
P5-A3C **22/178**, P5-A3 agrégé **54/371**, P5-A2 **25/198**, P5-A1 **74/400**,
P5-A0 **19/256**, suite **795/5753**, Pint **318**, **35 migrations**.

**P5 ANALYTIQUE TERMINÉ, MERGÉ ET VALIDÉ** (**D-042**). P5-A3D est reporté au
durcissement préproduction, non bloquant pour P6; les opérations restent
CLI/scheduler, désactivées par défaut, et aucune opération n'est exposée dans
Filament.

**P6-A0 CRM IDENTITY, CONSENT AND AUTHORIZATION FOUNDATION TERMINÉ, MERGÉ ET
VALIDÉ** via [PR #31](https://github.com/mysterus44/DigiTrove/pull/31), head
`3276fef`, merge `47888d0` (**D-043**). La migration unique `000020` crée
`crm_contacts` et le ledger append-only `crm_marketing_consent_events`.
Déduplication par e-mail exact normalisé (`trim` + CITEXT) seulement; aucun
Visitor, alias folding ou rapprochement approximatif. Les achats invités
utilisent le snapshot e-mail de l'Order; un compte n'est lié que s'il est actif,
non supprimé, vérifié et de même e-mail. Le hardening final impose
`UserStatus::Active` dans `resolve_crm_contact` et dans le trigger de liaison;
`suspended|blocked` sont refusés. Consentement limité à `email/promotional`,
checkout grant seulement, compte vérifié grant/withdraw, version de politique
obligatoire et idempotence SHA-256. `digitrove_crm_executor` est
NOLOGIN/NOINHERIT; trois fonctions SECURITY DEFINER à search_path fixe donnent
au runtime un accès EXECUTE-only. Config désactivée par défaut, services
fail-closed et Gate `manageCustomerRelationships` réservée à l'admin actif non
supprimé. Aucun CI GitHub n'était visible avant merge; validation locale
post-merge : **36 migrations**, P6-A0 **40/235**, suite **835/5988**, Pint
**347**, rollback/concurrence/diff-check verts.

**P6-A1.0 DURABLE ORDER-TO-CRM ATTRIBUTION PIPELINE TERMINÉ ET MERGÉ** (PR #32 sur `77652f2`, **D-045**). La migration unique `000021` crée une outbox transactionnelle sans
PII et une attribution immuable. Les nouveaux checkouts appliquent le contrat
e-mail `3..254`; un replay exact peut encore retourner un Order historique valide
jusqu'à 320 caractères avant le contrôle de nouvelle création. Le schéma Commerce
`VARCHAR(320)` reste intact. Le trigger suit la transition `false → true` du
prédicat `status acquis + paid_at non NULL` dans les deux ordres et capture seulement un contact actif exact déjà existant; il
n'appelle jamais le resolver. Après commit, `OrderPaid` est un signal faible;
un job unique à TTL 3600 secondes et un sweeper borné appellent des autorités PostgreSQL
SECURITY DEFINER EXECUTE-only. Un e-mail historique incompatible ou un conflit
devient terminal `unattributable`, sans rollback financier. Le snapshot de
contact préserve l'historique après anonymisation sans attribuer une ancienne
vente à un nouveau contact de même e-mail. Validation : **37 migrations**,
P6-A1.0 **48/285**, suite **883/6273**, Pint **367**, concurrence/rollback/
diff-check verts.

**P6-A1.1 CURRENCY-SAFE COMMERCE ROLLUP AUTHORITY — TERMINÉ, MERGÉ ET VALIDÉ**
via [PR #33](https://github.com/mysterus44/DigiTrove/pull/33), head `732d491`,
merge `8fe6cfa`, CI #40 success (D-046 / D-046.1). La migration `000022`
(`2026_07_14_000022_create_crm_contact_commerce_rollups.php`) crée la table
`crm_contact_commerce_rollups`, PK
`(contact_id,currency)`, projection mutable par autorité PostgreSQL uniquement
(pas append-only), owner `digitrove_crm_executor`, aucun accès runtime ni PUBLIC,
aucun modèle/service/job/listener/commande/scheduler. Une unique fonction
`SECURITY DEFINER` `refresh_crm_contact_commerce_rollup(BIGINT, VARCHAR)`
(search_path fixe, objets qualifiés `public.`, paramètres préfixés `p_`,
`ON CONFLICT ON CONSTRAINT`, calcul NUMERIC puis contrôle de débordement BIGINT,
advisory lock contact/devise). `acquired_orders_count`
inclut les commandes gratuites ; les refunds `succeeded` sont agrégés par Order.
Le `down()` révoque `SELECT` sur `payments`/`refunds` pour restaurer exactement la
frontière `000021`. Aucun worker rollup, backfill, UI, segment, campagne,
P6-A1.2+, P6-A2+, P7 ou P5-A3D.

**P6-A1.2 DURABLE ROLLUP REFRESH ORCHESTRATION & RECONCILIATION — TERMINÉ, MERGÉ
ET VALIDÉ** via PR #34, head `a75eef6`, merge `7dc78aff`, CI #41 success (D-047).
La migration `000023`
(`2026_07_14_000023_create_durable_crm_rollup_refresh_pipeline.php`, **39
migrations**) orchestre durablement l'appel à l'autorité
`refresh_crm_contact_commerce_rollup` **sans jamais recalculer les montants** :
outbox `crm_commerce_rollup_refresh_outbox` coalescée par `(contact_id, currency)`
avec compteur de génération (`requested_generation >= processed_generation`, aucune
PII), signaux PostgreSQL `AFTER INSERT` sur `crm_order_attributions` et `→ succeeded`
sur `refunds` (contact **uniquement** issu de l'attribution, jamais de l'e-mail ; un
refund `succeeded` avant attribution n'invente aucun contact), autorités
`enqueue`/`list_due`/`process` SECURITY DEFINER (owner `digitrove_crm_executor`),
runtime **EXECUTE-only sur `list_due` et `process`** (jamais l'outbox, enqueue ou
refresh ; PUBLIC sans accès ; aucun nouveau rôle). `process` sérialise via `FOR
UPDATE` (génération concurrente jamais perdue), retry transient borné, terminal
explicite sur overflow/intégrité, jamais de clamp ni de faux succès. Couche Laravel
mince : job ID-only `ProcessCrmCommerceRollupRefresh` (`ShouldBeUnique`,
`contactId`+`currency` seul, aucun calcul monétaire), sweeper
`crm:sweep-commerce-rollup-refresh` (**recovery du durable, aucun backfill**),
scheduler 5 min **désactivé par défaut**. Le `down()` restaure exactement la
frontière `000022`.

**P6-A1.3 EXPLICIT HISTORICAL COMMERCE ROLLUP BACKFILL — TERMINÉ, MERGÉ ET
VALIDÉ** via PR #35, head `ba32582`, merge `106ffb0a`, CI #42 success (D-048).
La migration `000024`
(`2026_07_14_000024_create_crm_commerce_rollup_backfill_runs.php`, **40
migrations**, aucune `000025`) ajoute l'**outil opérateur explicite** qui retrouve
les couples `(contact_id, currency)` historiques et les **injecte dans le pipeline
P6-A1.2** — il ne calcule aucun montant, ne crée ni contact ni attribution, ne mute
ni Commerce ni la table rollup. Source autoritative unique :
`crm_order_attributions ⋈ orders` acquis (**aucun e-mail, resolver, User/Visitor
stitching ni Analytics**). ⚠️ `crm_order_attributions` n'a **pas** de colonne `id`
(PK = `order_id`) et **aucun marqueur d'insertion autoritatif** (`attributed_at` est
`timestamp(0)` ET fourni par l'appelant) : la borne gelée est un **high-water mark**
`attribution_order_id_high_water_mark = MAX(order_id)`, **pas un snapshot MVCC**.
Sémantique exacte : toute attribution présente au démarrage vérifie `order_id <= HWM`
(la réciproque n'est pas revendiquée). Le système est **race-safe** grâce au trigger
P6-A1.2 — une attribution tardive est toujours enqueue par lui, et la double
couverture est inoffensive (coalescing A1.2, recalcul autoritatif A1.1). **Finitude** :
attributions immuables + au plus une par Order ⇒ domaine borné ⇒ le run termine. **Keyset** `(contact_id, currency)` sans `OFFSET`, curseur
durable, couples `DISTINCT`. Table de runs audités (statuts `ready|running|
completed|failed`, `last_error_code` SQLSTATE **seulement**, aucune PII) avec
**index unique partiel garantissant un seul run actif**. Six autorités
`SECURITY DEFINER` (owner `digitrove_crm_executor`) ; runtime **EXECUTE-only** sur
ces autorités, **jamais** `SELECT`/`DML` sur la table de runs, **jamais** `EXECUTE`
sur `enqueue` (P6-A1.2) ni `refresh` (P6-A1.1) ; PUBLIC sans accès ; aucun nouveau
rôle. Commande `crm:backfill-commerce-rollups` **dry-run par défaut** : mutation
seulement avec **`--execute` ET `CRM_COMMERCE_ROLLUP_BACKFILL_ENABLED=true`**
(double barrière), batches transactionnels bornés (≤100×100 par invocation),
run **resumable**, retry d'un `failed` **explicite**. **Aucun job, scheduler ou
listener de backfill** : le seul pipeline asynchrone reste P6-A1.2. Le `down()`
restaure exactement la frontière `000023`.

**P6-A2 TYPED VERSIONED CRM SEGMENTS — TERMINÉ, MERGÉ ET VALIDÉ** via PR #36,
head `ea562c7`, merge `920eb1b9`, CI #43 success (D-049 architecture → D-050
implémentation). La migration
`000025` (`2026_07_14_000025_create_typed_versioned_crm_segments.php`, **41
migrations**, aucune `000026`) crée quatre tables — `crm_segments`,
`crm_segment_versions`, `crm_segment_generations`,
`crm_segment_generation_members` — toutes owner `digitrove_crm_executor`.
**DSL V1 typé et allowlisté** stocké en JSONB mais **jamais interprété comme du
SQL** : enveloppe exacte `{schema_version, match, criteria}`, 1..50 critères,
≤ 32768 octets, clés **exactes** par type de critère (toute clé `sql`/`column`/
`raw`/`path`/… rend la définition invalide), INT64 exact (rejette `1.2`, `"100"`,
`1e100`, overflow), timestamps **RFC3339 UTC absolus** (aucun « 30 days ago »),
enums limités aux valeurs **réelles** du dépôt (`active|anonymized`,
`guest_order|verified_account`). **Tout critère commerce est currency-scoped** et
lit exactement une ligne `(contact_id, currency)` : aucun FX, aucune somme
multi-devises, aucun LTV global ; **un rollup absent vaut FALSE pour TOUS les
opérateurs**, `neq` compris (un Order gratuit acquis a déjà une ligne à 0, donc
« jamais acquis » et « acquis gratuitement » ne se confondent pas). Le **contenu
d'une version est immuable dès l'INSERT** (seule transition `draft → published`) ;
publier une nouvelle version est **refusé** tant qu'une génération est en cours.
Une génération gèle `contact_id_high_water_mark = MAX(crm_contacts.id)` (borne de
**population de contacts**, pas un snapshot MVCC des faits : la fenêtre de build
peut voir les faits changer), parcourt par **keyset borné** en avançant le curseur
sur le **dernier contact SCANNÉ** (jamais le dernier matché), puis est **publiée
atomiquement** via `crm_segments.current_generation_id` — les lecteurs voient
l'ancienne génération **entière** puis la nouvelle **entière**, jamais une
demi-génération. Les pointeurs sont protégés par **FK composites**
`(current_version_id, id) → versions (id, segment_id)` : un segment ne peut
**structurellement** pas pointer vers la version d'un autre. Échec de batch
**atomique** (aucun membership/curseur/compteur partiel, **SQLSTATE seul**), retry
**explicite**. **Le matcher ne lit jamais `crm_marketing_consent_events`** :
appartenance ≠ éligibilité d'envoi. ACL : aucun nouveau rôle, runtime
**EXECUTE-only sur 11 autorités bornées**, jamais sur le validateur ni le matcher
internes, jamais `SELECT`/`DML` sur les quatre tables ; PUBLIC sans accès. Couche
Laravel mince (service, job **ID-only**, dispatcher, sweeper, commande opérateur
**preview par défaut**, scheduler **désactivé par défaut**) ; **aucune UI, route
ni ressource Filament**. Le `down()` restaure exactement la frontière `000024`.

**P6-B0 (CRM Admin Views) : TERMINÉ, MERGÉ ET VALIDÉ** via
[PR #37](https://github.com/mysterus44/DigiTrove/pull/37), head `b05edb2`, merge
`2df7e6f`, **CI SUCCESS** (D-052). Suite complète locale B0 standalone : **1295 tests
/ 8250 assertions, 0 échec**. ⚠️ Le merge a exigé un correctif final `b05edb2` : le CI
**standalone** de B0 a révélé que `P5A3AnalyticsSecurityContractTest` — le jumeau A/B
du fichier P5-A3C déjà corrigé — portait le **même glob trop large** et heurtait les
commentaires de `CrmSegments.php` qui documentent leur propre absence d'export. Leçon :
**une campagne `--filter` ciblée ne voit pas les contrats d'inventaire des autres
phases ; seul le CI standalone de la branche les révèle.**
⚠️ D-051 annonçait « aucune
migration » : **faux**. Le runtime n'a **aucun `SELECT`** sur une table `crm_*` et,
si toutes les autorités **Segments** existaient déjà (P6-A2), il n'existait
**aucune** autorité pour parcourir les contacts, chercher par e-mail exact, lire une
timeline de consentement, lire les faits commerce par devise, lister les
appartenances courantes ou l'historique des versions. **P6-B0.1 ajoute la migration
`000026`** (**42 migrations**, aucune `000027`) avec les **7 autorités de lecture**
manquantes — toutes `STABLE` + `SECURITY DEFINER`, owner `digitrove_crm_executor`,
`search_path` épinglé, **aucun nouveau rôle**, PUBLIC sans accès, runtime
**EXECUTE-only**, `down()` restaurant exactement `000025`.
Panel admin + Gate `manageCustomerRelationships` fail-closed **re-vérifiée dans
chaque action**, aucune donnée CRM publique, aucune reconstruction financière côté
UI, montants en unités mineures exactes avec devise explicite et **aucun total
multi-devises** ni **division par 100** (XOF exposant 0 vs USD exposant 2, aucune
table d'exposants auditée), recherche contact par **e-mail normalisé exact dans
l'autorité** (jamais en PHP, aucun wildcard ; un contact anonymisé a `email IS NULL`
— adresse **physiquement absente**, ni masquée ni retrouvable), constructeur de
critères **structuré** produisant **uniquement le DSL V1** (aucun textarea, éditeur
JSON, éditeur de code ni SQL ; entiers en **nombres JSON**, dates **RFC3339 UTC
absolues**), et séparation stricte consentement / appartenance / éligibilité
d'envoi. **PostgreSQL reste l'autorité finale** : appelé hors builder avec 7
définitions invalides, il les refuse toutes et **aucune version n'est créée**.
Aucun envoi, aucun export (P6-B1).
⚠️ **Aucune suite complète locale B0 n'a été exécutée ni revendiquée** — elle est
différée au stack B1 qui contient B0. P6B0+P6B01 **113 tests / 655 assertions**,
Pint **446 fichiers**.
**Trois contrats historiques corrigés en portée (jamais affaiblis)** : P5-A3C
(inventaire explicite des 11 fichiers Analytics, `toHaveCount(11)` fail-closed,
+2 tests prouvant que le garde garde ses dents et que le CRM est hors périmètre),
P6-A0 (`/(?<!acquired_)orders_count/` — ⚠️ **échec préexistant** introduit par
`0a62eb5`, campagne non rejouée à l'époque), P6-A2 (la page B0 autorisée est
**nommée**, toute autre UI segment échoue toujours). `Tests\Support\SourceScanner`
scanne du **code** (commentaires retirés) : sinon un fichier qui documente ce qu'il
refuse de faire déclenche sa propre alarme.
**P6-B1 (Private Audited CRM Exports) : TERMINÉ, MERGÉ ET VALIDÉ** via
[PR #38](https://github.com/mysterus44/DigiTrove/pull/38), head `bf9ea09`, merge
`474f92c`, **CI SUCCESS** (D-053 architecture → D-054 implémentation). Suite complète
sur la stable finale : **1379 tests / 8711 assertions, 0 échec**. B1 a été réalignée sur
la stable mergée par **merge normal** (`bf9ea09`), jamais par rebase ni force-push ;
**un seul conflit**, le contrat Analytics, résolu en prenant la version **stable en
entier** (la plus forte). **Migration `000027`**,
**43 migrations**, aucune `000028`. Table `crm_exports` + **10 autorités**
`SECURITY DEFINER` (owner `digitrove_crm_executor`, runtime **EXECUTE-only**, ni `SELECT`
ni DML sur la table, aucun nouveau rôle) ; `down()` restaure exactement `000026`.
**Snapshot de génération** : la génération publiée courante est **figée à la création**
de l'export — une G2 publiée pendant l'écriture ne fait apparaître aucune ligne, le
fichier reste 100 % G1. **Plafond strict** : lecture de `row_limit + 1`, dépassement ⇒
`failed`/`row_limit_exceeded` sans aucun fichier publié, **jamais de troncature
silencieuse**. **Sûreté formule CSV** : apostrophe devant `= + - @ TAB CR LF` (le
guillemetage seul ne protège pas — `"=1+1"` redevient une formule à l'import) ; `NULL`
⇒ champ vide, jamais « NULL ». **Aucun I/O sous transaction** (claim court → COMMIT →
écriture hors transaction → finalisation courte, garde `assertOutsideTransaction()`).
**Téléchargement** réservé au demandeur : un **autre admin** reçoit un **404 plat
identique octet pour octet** à celui d'un export inexistant (sinon le statut serait un
oracle d'existence) ; aucune URL publique, signée ni bearer. Job **ID-only**, flags et
schedulers **désactivés par défaut**.
⚠️ **DÉFAUT D'AUTORISATION RÉEL FERMÉ** : `CrmExports::canAccess()` appelait
`parent::canAccess()`. **PHP aplatit une méthode de trait DANS la classe**, donc
`parent::` visait `Filament\Pages\Page::canAccess()` (`true`) et **contournait entièrement
la Gate** — la page était accessible à `staff` et `customer`. Le trait expose désormais
un hook `crmGateExtraCondition()` : la forme qui échoue ainsi n'est plus disponible.
⚠️ **Écart assumé** : l'export « membres courants » part de la page **Exports**
(sélecteur de segment), pas du détail du segment, pour ne pas affaiblir le contrat
`P6B0SecurityContractTest` qui prouve que B0 n'expose aucune affordance d'export.
Validation ciblée : B0+B1 **197 tests / 1112 assertions**, Pint **463 fichiers**.

**P6-C (Paniers / Relances) : TERMINÉ, MERGÉ ET VALIDÉ** via [PR #39](https://github.com/mysterus44/DigiTrove/pull/39), head `b6b63f9`, merge `a5de60a`, **CI SUCCESS**. Suite complète sur la stable finale : **1461 tests / 9290 assertions, 0 échec**. (D-055 architecture → D-056
implémentation, branche `p6-c-cart-reminders`, migration **`000028`**, **44 migrations**,
aucune `000029`). ⚠️ **INFRASTRUCTURE BACKEND DORMANTE** : le dépôt n'a **aucun flux
panier applicatif** (zéro route, zéro contrôleur, `CartItem` sans `$touches`,
`secret_hash` produit uniquement par la factory) — **aucune reprise panier end-to-end
n'est revendiquée**. Flags et schedulers **OFF par défaut** ; **aucune cadence marketing
livrée** : un réglage absent **refuse** au lieu d'inventer. `carts.last_activity_at` +
**trigger sur `cart_items`** (car `updated_at` est un signal faux : `CartItem` ne touche
pas le parent, et la transition d'abandon se compterait elle-même). Ledger
`cart_reminder_attempts` : identité immuable `(cart, step)`, transitions **monotones**,
raisons **allowlistées**, **zéro PII**. **Revalidation à l'envoi** du consentement
`promotional`, de la conversion et de l'achat couvrant (`paid|partially_refunded|
refunded`, couverture **stricte**). **Reprise fragment → POST** : patron P4-C réutilisé,
bootstrap GET **sans base**, CSP à nonce par réponse, `history.replaceState` **avant**
usage, puis continuation en **session serveur opaque** ; capability CSPRNG 256 bits,
SHA-256 seul au repos, **TTL imposé par l'autorité PostgreSQL**, rejouable dans son TTL.
ACL Commerce minimales posées par `000028` et révoquées **exactement** au `down()` ;
**aucune migration historique modifiée**.
⚠️ **`app/Services/Crm/` interdit `DB::table(`** : les services de relance lisent
Commerce, donc ils vivent dans **`app/Services/Cart/`**. Contrat corrigé par
**déplacement**, jamais par affaiblissement.
⚠️ **D-030 : `P6-C LOCAL = CLOSED`, `GLOBAL = OPEN`** — `DeliveryConfig::assertMailerSafe()`
(P4-C) reste plus faible sur cinq points (nom au lieu du transport résolu, `array` toléré,
transport inconnu accepté, préparation non vérifiée, composition parcourue à un seul
niveau par nom). **P4-C n'est pas modifié dans ce gate** ; son durcissement mérite le sien.
Validation : P6-C **82 / 576**, campagne + régressions **1008 / 6485**, rollback
**44→43→44**, Pint **486**.

**P6-D0 (Affiliation — Fondation BDD) : TERMINÉ, MERGÉ ET VALIDÉ** via
[PR #40](https://github.com/mysterus44/DigiTrove/pull/40), head `1e8aa79`, merge
`dcdc966` (parents `7fede04` + `1e8aa79`), **CI SUCCESS**. Suite complète sur la stable
finale : **1524 tests / 11835 assertions, 0 échec** (37,0 min) ; P6-D0 **63 / 2546** ;
Pint **495**. — **D-057**, migration unique
**`000029`**, **45 migrations**, aucune `000030`. **Neuf tables** :
`affiliate_program_policies`, `affiliates`, `affiliate_codes`, `affiliate_touches`,
`affiliate_attributions`, `affiliate_commissions`, `affiliate_commission_entries`,
`affiliate_payouts`, `affiliate_payout_items`.

⚠️ **FONDATION DORMANTE — JAMAIS À PRÉSENTER COMME UN PROGRAMME D'AFFILIATION.** Aucun flux
de candidature, aucune capture de clic, aucun moteur de commission, aucun payout, aucune
route, aucun contrôleur, aucun service, aucun job, aucun scheduler, aucun écran Filament,
aucun cookie, aucun provider. Le dépôt n'ayant **aucun storefront**, il n'y a **aucun clic
réel à attribuer**. Un contrat fail-closed scanne **tout** `app/`, `routes/`, `config/` et
`resources/` : **pas un seul fichier** ne mentionne « affiliate ».

Décisions gravées dans le schéma : **politiques versionnées, jamais rétroactives** (index
unique partiel ⇒ **une seule `active`**) · **1500 bps**, `INTEGER`, borné `0..5000` (100 %
refusé comme absurde) · base = **`line_total_after_discount`** seule valeur autorisée
(`order_items.line_total_minor` est **déjà** net de remise) · commission au **niveau
`order_item`** · snapshot immuable taux/base/devise/délai · **ledger append-only** à
montants **signés**, direction contrainte par type · payout **manuel, mono-affilié,
mono-devise**, **aucun provider**, **aucune donnée bancaire/Mobile Money** ·
`affiliates.user_id` **UNIQUE** (D-014 : jamais un `users.role`) · **une seule attribution
financière par commande**. `visitor_id` est un **ancrage d'identité**, pas une preuve :
`visitors.first_touch_*` et `events` restent **non autoritatifs** (D-037), et les cibles de
FK hors du bloc sont exactement `order_items`, `orders`, `refunds`, `users`, `visitors`.
**ACL fail-closed** : le runtime n'a **ni lecture ni écriture**, PUBLIC sans accès, **aucun
nouveau rôle**, **aucune fonction `SECURITY DEFINER` opérationnelle** (les **trois**
fonctions sont des gardes d'intégrité derrière un trigger). **Aucune politique insérée.**

**Quatre garanties structurelles ajoutées au durcissement pré-merge** : (1) l'ancrage d'une
touche est un **trigger `BEFORE INSERT`**, jamais un CHECK — un CHECK est réévalué par
l'UPDATE d'un `ON DELETE SET NULL` et **vetoerait toute purge de visiteur définitivement** ;
(2) une **politique effective est physiquement immuable** (trigger `BEFORE UPDATE`), donc
changer un réglage exige une nouvelle version et n'altère jamais une commission passée ;
(3) **FK composites** `(order_item_id, order_id)`, `(attribution_id, order_id)`,
`(attribution_id, affiliate_id)` — des FK séparées ne prouvent que l'**existence** de chaque
id, jamais leur appartenance mutuelle ; (4) **payout mono-affilié ET mono-devise
structurellement** (quatre FK composites), plus deux **identités d'idempotence naturelles**
(un seul `accrual` par commission, un seul `refund_reversal` par `(commission, refund)`).
⚠️ Un seul objet posé hors du bloc : l'index `order_items (id, order_id)`, **créé par
`000029` et retiré par son `down()`** — aucune migration historique n'est modifiée.

⚠️ **Deux dettes explicites pour `P6-D1`** : `P4B_ALLOWED_SERVICE_FILES` devra être
**élargie explicitement** au premier fichier sous `app/Services` ; et
`P6D0SecurityContractTest` devra être **rescopé par inventaire exact** des fichiers
autorisés (comme P5-A3C et P6-B0), **jamais** par suppression d'assertion.

**P6-D1 (Autorité d'affiliation + gouvernance des politiques) : TERMINÉ, MERGÉ ET VALIDÉ**
via [PR #41](https://github.com/mysterus44/DigiTrove/pull/41), head `d2ecfb44`, merge
**`aeac8a5d`** (parents `ec0191f` + `d2ecfb44`), **CI SUCCESS**. D-058, migration unique
**`000030`**, **46 migrations**, aucune `000031`. Validation : P6-D1 **42 / 307**,
régressions **848 / 7776**, suite complète **1566 / 12145**, 0 échec (35,87 min),
Pint **510**.

**LA DETTE SUPERUSER EST FERMÉE.** Les neuf tables appartenaient à `digitrove`, le rôle
migrateur **superuser** ; toute autorité `SECURITY DEFINER` s'y serait exécutée en
superuser — la vulnérabilité fermée par **D-029.6 / P4-B0**. `000029` n'a **pas** été
réécrite. Rôle `digitrove_affiliate_executor` **NOLOGIN/NOINHERIT/non-superuser**, créé par
le **script de provisioning** (cluster-global, **jamais supprimé au `down()`**), propriétaire
des **9 tables et 9 séquences** ; **5 autorités `SECURITY DEFINER`** lui appartiennent ;
runtime **EXECUTE-only**, **zéro DML direct** ; `PUBLIC` sans `EXECUTE`.

**Publication immédiate uniquement.** `status='active'` **≡ en vigueur maintenant** ;
intervalle **`[effective_from, effective_until)`** avec **la même valeur `now()`** aux deux
bornes, donc `predecessor.until == successor.from` — **ni trou ni chevauchement**. Aucune
autorité n'accepte d'horodatage : rien ne peut être programmé.

⚠️ **Précision élargie à `timestamptz(6)` par `000030`** : en `(0)`, deux publications
séparées de moins d'une seconde s'écrasent sur la même valeur et violent
`affiliate_program_policies_period_check`.

⚠️ **ROLLBACK LOSSLESS-ONLY.** Le retour `(6) → (0)` n'est autorisé **que si aucune valeur
persistée ne change** ; le contrôle est la **première opération** du `down()`, avant tout
`DROP`/`REVOKE`/`ALTER`. Sinon : **refus avant toute mutation**, base laissée **entièrement
en P6-D1**. **Après une publication réelle, ce refus est le cas NORMALEMENT ATTENDU** —
`now()` garde les microsecondes. Ce n'est **pas** un bug : le système préfère conserver
l'historique exact plutôt que falsifier les horodatages qui expliquent les commissions.
Ne jamais écrire `rollback 46 → 45 → 46 PASS` sans qualifier les données.

**PROCHAIN GATE : P6-D1.1 — cycle de vie affilié + codes**, **NON COMMENCÉ**, aucune
migration `000031`. ⚠️ La **re-candidature après `rejected`** est différée à son préflight :
`affiliates.user_id` est **UNIQUE**, donc transition d'état, jamais une seconde ligne.

Points figés par D-058 : rôle `digitrove_affiliate_executor` NOLOGIN/NOINHERIT créé par le
**script de provisioning** (les rôles sont cluster-globaux — précédent P4-B0) et **jamais
supprimé au `down()`** · propriété transférée pour les 9 tables **et leurs 9 séquences** ·
**cinq autorités bornées**, aucun CRUD générique · **publication atomique** avec **`now()`**
et non `clock_timestamp()` (bornes identiques ⇒ intervalle semi-ouvert **sans trou ni
chevauchement**) · **`status='active'` ≡ « en vigueur maintenant »**, la **publication
différée n'est PAS livrée** (exigerait `btree_gist`, absent) mais reste ajoutable ensuite
**sans rouvrir la frontière** · Filament **gouvernance seule**.

Frontières suivantes : `P6-D1.1` cycle de vie affilié + codes · `P6-D2` touches et
attribution · `P6-D3` moteur de commissions et compensations (⚠️ `refunds` est au **niveau
commande** : la répartition vers les lignes réutilise la convention **Hamilton** déjà
autoritative — `App\Services\Pricing\DiscountAllocator`, D-030 Q3 — sans inventer d'arrondi,
**aucune seconde implémentation**) · `P6-D4` payout administratif. **Aucun `P6-D5` créé
artificiellement** : les surfaces admin sont absorbées par le gate qui les justifie.

**ANCIEN ÉTAT (pour mémoire) : P6-C — Paniers / Relances**, architecture **gelée par D-055**, NON
COMMENCÉ, aucune migration `000028`. ⚠️ Deux contraintes dures issues de l'audit réel :
`carts` ne porte **aucune colonne e-mail** (un panier invité est structurellement
**inadressable** — la V1 ne peut relancer qu'un panier lié à un compte actif et vérifié,
**aucun e-mail deviné, aucun rapprochement flou**) et `carts.abandoned_at` existe mais
**aucun code applicatif ne l'écrit** (il n'y a aujourd'hui **aucune** transition
d'abandon ; P6-C doit la définir comme une écriture durable et idempotente, jamais une
heuristique de lecture). Consentement `promotional` **relu à l'envoi**, arrêt après achat
**vérifié à l'envoi**, reprise de panier via `carts.secret_hash` (secret brut jamais
persisté). Dépendance bloquante : la dette D-030 `MAIL_MAILER=log` ferait fuiter contenu
et lien de reprise dans `storage/logs` — à clore **avant** tout envoi réel.
Invariants hérités :
l'Order et ses `order_items` sont la **source autoritative** ; aucune donnée
tarifaire client n'est acceptée ; **aucun coupon n'est consommé au checkout** —
`coupon_redemptions` et `redemptions_count` n'arrivent qu'à la confirmation
serveur (P3-D4) ; le webhook n'est jamais autoritatif, le contre-appel fournisseur
l'est ; le TTL `pending` est validé côté serveur (D-032) ; la clé d'idempotence
brute n'est jamais persistée ; les collisions d'`order_number` passent par un
**savepoint** PostgreSQL. `P4-C0` bloque tous les gates de livraison ; **`P4-C2`
avant l'activation réelle de `P4-C3`**. Le guide
`.context/skills/SECURITE_TELECHARGEMENT.md` est désormais aligné sur
D-035/D-036. Deux jugements
conservateurs hérités de P3-D1 restent en vigueur : seul
`products.status = 'published'` est vendable ; `min_order_minor` est un plancher
mesuré sur le panier entier. D-037 impose aussi que les événements restent
non autoritatifs, sans FK transactionnelle, sans donnée sensible brute et sans
droit du runtime métier; chaque future partition enfant doit recevoir ses
révocations ACL explicites.

## 🔄 EN FIN DE TÂCHE

Mettre à jour `PROGRESS_TRACKER.md` + `HANDOFF.md` (journal + PROCHAINE TÂCHE), logger
toute décision dans `DECISIONS_LOG.md`, vérifier tests/pint, commit clair
`feat|fix|docs|chore: <résumé> [par Claude Code]`, puis `git push` (jamais `main`).
