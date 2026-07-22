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
n'existe pas : `app/Services|Actions|Events|Listeners|Jobs|Notifications|Mail|
Policies|Support|Http\Requests|Http\Middleware` n'existent pas, `routes/web.php`
ne déclare que la page d'accueil, `OrderService` / `OrderPaid` /
`IssueDownloadGrants` / `DownloadService` / `DownloadController` sont absents.

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
- 🚨 **`.context/skills/SECURITE_TELECHARGEMENT.md` est PARTIELLEMENT PÉRIMÉ**
  (UPDATE direct de `downloads_count`, colonne `grant_id` inexistante, colonnes
  P4-B obligatoires absentes, ni tentative authentifiée ni G5 ni frontière
  runtime). **Ne plus l'utiliser comme modèle de code ; le réécrire avant le gate
  `P4-C4`.**

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

- 🚧 **`P3-D2.1 — PostgreSQL Constraint Classification Hardening` EN ATTENTE DE
  REVUE/MERGE** (branche `p3-d2-1-postgresql-constraint-hardening`, aucune
  migration). `OrderService` classait des erreurs BDD sur la **présence d'un nom
  de contrainte dans le message** d'un `Throwable`, sans exiger le SQLSTATE
  `23505` : un message usurpé provoquait un **retry `order_number` injustifié**
  et de faux `CartAlreadyCheckedOut` / `IdempotencyConflict`. **Aucune régression
  métier observée** — défaut purement défensif. Correctif : primitive
  `App\Support\PostgresConstraintViolation` (SQLSTATE lu dans `errorInfo[0]`,
  nom de contrainte extrait **après** confirmation du `23505`, **égalité
  exacte**). Unit **20/20**, P3-D2 **71/344**, suite **424/3641**, Pint **140**.
  ⚠️ **Ce pattern est obligatoire en P3-D3 pour les contraintes de `payments`.**

**PROCHAINE TÂCHE : revue et merge de `P3-D2.1`. `P3-D3 — Payment Initiation`
(branche future `p3-d3-payment-initiation`) est BLOQUÉ** jusqu'à ce merge et à
son CI vert — **non commencé**. Invariants hérités : l'Order et
ses `order_items` sont la **source autoritative** (le panier est `converted` et
peut avoir changé) ; aucune donnée tarifaire client n'est acceptée ; une commande
gratuite reste `pending` ; **aucun coupon n'est consommé au checkout** —
`coupon_redemptions` et `redemptions_count` n'arrivent qu'à la confirmation
serveur du paiement (P3-D4) ; le TTL `pending` est validé côté serveur (D-032) et
un rejeu conserve l'expiration d'origine ; la clé d'idempotence brute n'est
jamais persistée ; les collisions d'`order_number` passent par un **savepoint**
PostgreSQL. Aucun Payment, aucun `OrderPaid`, aucun DownloadGrant n'existe encore.
Deux jugements conservateurs hérités
de P3-D1 restent en vigueur : seul `products.status = 'published'` est vendable ;
`min_order_minor` est un plancher mesuré sur le panier entier. P5 non démarré.

## 🔄 EN FIN DE TÂCHE

Mettre à jour `PROGRESS_TRACKER.md` + `HANDOFF.md` (journal + PROCHAINE TÂCHE), logger
toute décision dans `DECISIONS_LOG.md`, vérifier tests/pint, commit clair
`feat|fix|docs|chore: <résumé> [par Claude Code]`, puis `git push` (jamais `main`).
