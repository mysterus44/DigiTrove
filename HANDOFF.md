# HANDOFF.md — Carnet de Passation (Claude Code ⇄ Codex)
# 🔴 LE FICHIER LE PLUS IMPORTANT DU CO-CODAGE.
# Quel que soit l'agent, il LIT ceci en premier et le MET À JOUR en dernier.

---

## 📍 ÉTAT ACTUEL

- **Dernier agent** : Claude Code
- **Date** : 2026-07-16
- **Branche git active** : `p0-foundations-laravel13` à `93d1f17` (synchronisée avec
  `origin/p0-foundations-laravel13` ; P4-A1 mergé et clôturé via PR #12)
- **Commit fondations local** : `4f48fc8 feat: bootstrap Laravel foundations [par Codex]`
- **Merge SITE-00** : `83b6b0c Merge pull request #1 from mysterus44/site-00-static-preview`
- **Merge P1 Identité** : `3f9d132 Merge pull request #2 from mysterus44/p1-identity`
- **Merge P2 Catalogue** : `aff4d05 Merge pull request #3 from mysterus44/p2-catalog`
  (SHA complet `aff4d05d552a78754fc90bcb145fb75aba66dc93`, 2 parents `9a11791` + `fbaa33a`)
- **Merge P3A Coupons et Paniers** :
  `234e303 Merge pull request #4 from mysterus44/p3a-coupons-carts`
  (SHA complet `234e3034f0e1ea5e20af9ca359d19d799c140072`, parents `2288a63` + `1c0d5a2`)
- **Merge P3B Commandes** : [PR #5](https://github.com/mysterus44/DigiTrove/pull/5)
  `f07d225 Merge pull request #5 from mysterus44/p3b-orders`
  (SHA complet `f07d2258c18e196af608f7df97a5816a7cf578f6`, parents `6f7578e` + `499e2bd`)
- **Merge P3C-A Payments** : [PR #6](https://github.com/mysterus44/DigiTrove/pull/6)
  `4a077db Merge pull request #6 from mysterus44/p3c-a-payments`
  (SHA complet `4a077db6720ee07b304a7746bf7545f6dcf743ec`, parents `be1af7f` + `1a792a3` ;
  commits intégrés `c45e44a` + `0d04f77` + `1a792a3`)
- **Merge P3C-B Webhooks** : [PR #8](https://github.com/mysterus44/DigiTrove/pull/8)
  `51c4847 Merge pull request #8 from mysterus44/p3c-b-webhooks`
  (parents `963eef0` + `49ad374` ; commit P3C-B `49ad374`)
- **Merge P3C-B.1 hardening** : [PR #9](https://github.com/mysterus44/DigiTrove/pull/9)
  `13932ac Merge pull request #9 from mysterus44/p3c-b1-webhook-replay-hardening`
  (SHA complet `13932ac11c59be366b859916bd5f15948d7d2cdf`, parents `51c4847` + `c772ac1`)
- **Merge P3C-C Refunds** : [PR #10](https://github.com/mysterus44/DigiTrove/pull/10)
  `122332a Merge pull request #10 from mysterus44/p3c-c-refunds`
  (SHA complet `122332aa5cc9fc25e9bf1898224f5a1da30f6446`, parents `be74854` +
  `1270c53` ; commit final P3C-C intégré `1270c530124fc605ade299f441277edbbf1c5534`)
- **Merge P4-A0 ProductFile Immutability** : [PR #11](https://github.com/mysterus44/DigiTrove/pull/11)
  `a047571 Merge pull request #11 from mysterus44/p4-a0-product-file-immutability`
  (SHA complet `a047571fe4e3453fec39336f297f4241cbb95898`, parents `abaea6e` +
  `8b822c1` ; commit P4-A0 intégré `8b822c1a49710be48371ce1b489a9a213f518d1b`)
- **Merge P4-A1 Bundle Purchase Snapshot** : [PR #12](https://github.com/mysterus44/DigiTrove/pull/12)
  `93d1f17 Merge pull request #12 from mysterus44/p4-a1-bundle-purchase-snapshots`
  (SHA complet `93d1f173b6021fccb7d4df70e24938e02d16f3e3`, parents `a1e2e7f` +
  `94b018c` ; commit P4-A1 intégré `94b018c303d1f91469f6364c664dff4196da97f4`)
- **`origin/main`** : `11130f4` (intact après P4-A1 ; aucun push direct)
- **Build/tests** :
  - `docker compose up -d` OK : PostgreSQL 16 + Redis 7 healthy
  - `php artisan --version` OK via `digitrove-php:dev` → Laravel Framework 13.19.0
  - `php artisan test` OK → 2 tests, 2 assertions
  - `./vendor/bin/pint --test` OK → 25 fichiers Laravel
  - `git fsck --full` OK après récupération de l'objet legacy manquant
  - SITE-00 : `php artisan test` OK via `digitrove-php:dev` → 5 tests, 20 assertions
  - SITE-00 : `./vendor/bin/pint --test` OK via `digitrove-php:dev` → 26 fichiers
  - SITE-00 : `npm run build` OK
  - Post-merge SITE-00 : `npm run build` OK, `php artisan test` OK via
    `digitrove-php:dev` → 5 tests, 20 assertions, `./vendor/bin/pint --test` OK
    via `digitrove-php:dev` → 26 fichiers
  - P1 Identité : `php artisan migrate:fresh --env=testing` OK via PostgreSQL réel
    (`digitrove_testing`) → 4 migrations P1
  - P1 Identité : `php artisan test` OK via PostgreSQL réel → 17 tests,
    57 assertions
  - P1 Identité : `./vendor/bin/pint --test` OK → 38 fichiers
  - Post-merge P1 : `php artisan migrate:fresh --env=testing` OK via PostgreSQL
    réel → tables applicatives créées uniquement `users`, `customer_profiles`,
    `visitors`
  - Post-merge P1 : `php artisan test` OK via PostgreSQL réel → 17 tests,
    57 assertions
  - Post-merge P1 : `./vendor/bin/pint --test` OK → 38 fichiers
  - P2 Catalogue : `php artisan migrate:fresh --env=testing` OK via PostgreSQL
    réel → P1 + 6 migrations P2
  - P2 Catalogue : `php artisan test` OK via PostgreSQL réel → 29 tests,
    173 assertions
  - P2 Catalogue : `./vendor/bin/pint --test` OK → 55 fichiers
  - P2 Catalogue : `git diff --check` OK
  - Post-merge P2 (sur `p0-foundations-laravel13` à `aff4d05`, via `digitrove-php:dev`
    + PostgreSQL réel `digitrove_testing`) :
    - `php artisan migrate:fresh --env=testing` OK → 10 migrations (4 P1 + 6 P2)
    - inventaire tables applicatives = `users`, `customer_profiles`, `visitors`,
      `categories`, `products`, `product_prices`, `product_files`,
      `product_category`, `product_bundles` (+ `migrations`) ; extension `citext`
      présente ; **aucune** table commerce/paiement/téléchargement/affiliation/analytics
    - `php artisan test` OK → 29 tests, 173 assertions
    - `./vendor/bin/pint --test` OK → 55 fichiers
    - `git diff --check` OK
  - Post-merge P3A Coupons et Paniers (PostgreSQL réel `digitrove_testing`) :
    - `php artisan migrate:fresh --env=testing` OK → 16 migrations (P1 + P2 + 6 P3A)
    - tables applicatives = `users`, `customer_profiles`, `visitors`, `categories`,
      `products`, `product_prices`, `product_files`, `product_category`,
      `product_bundles`, `coupons`, `coupon_currency_rules`, `coupon_products`,
      `coupon_categories`, `carts`, `cart_items`
    - aucune table commande, paiement, remboursement, livraison ou analytics
    - `php artisan test` OK → 44 tests, 322 assertions
    - tests P3A ciblés OK → 15 tests, 147 assertions
    - `./vendor/bin/pint --test` et `git diff --check` OK
  - Plan final P3B documentaire (D-027, aucun code P3B/P3C) :
    - `php artisan test` OK sur PostgreSQL réel → 44 tests, 322 assertions
    - `./vendor/bin/pint --test` OK → 72 fichiers
    - `git diff --check` OK
  - Post-merge P3B Commandes sur `p0-foundations-laravel13` (PostgreSQL réel) :
    - `php artisan migrate:fresh --env=testing` OK → 19 migrations
    - rollback automatisé des trois migrations P3B OK → tables, fonctions et
      triggers P3B supprimés dans une base PostgreSQL isolée
    - tests P3B ciblés OK → 18 tests, 337 assertions
    - suite complète OK → 62 tests, 650 assertions
    - `./vendor/bin/pint --test` OK → 83 fichiers ; `git diff --check` OK

---

## ✅ CE QUI EST FAIT

- Schéma relationnel v1 conçu → `.context/architecture/SCHEMA_BDD.md`
- Décisions de stack figées → `.context/memory/DECISIONS_LOG.md`
- Audit du legacy réalisé (failles trouvées) → `.context/context/AUDIT_LEGACY.md`
- **P0 Fondations terminé techniquement** :
  - Laravel 13.19 installé (D-012 : Laravel 11 abandonné car bloqué par advisories Composer/Packagist)
  - Filament 5 installé, panneau admin vide créé
  - PostgreSQL 16 + Redis 7 via `docker-compose.yml`
  - Image PHP dev `digitrove-php:dev` avec `intl`, `pdo_pgsql`, `redis`, `zip`, Composer
  - `.env.example` complet ; `.env` ignoré
  - `config/hashing.php` ajouté, Argon2id par défaut
  - disque `private` déclaré, non servi publiquement
  - Pest + Pint configurés
  - CI GitHub Actions ajoutée
  - aucune migration P0 : `database/migrations` est vide
  - `data/users.sqlite` retiré de l'index Git et ignoré
  - scripts legacy avec mots de passe (`data/setup_database.php`, `admin/admin-blog.php`) neutralisés
- **P0.5 Assainissement pré-P1 terminé techniquement** :
  - objet Git manquant `images/offres/tools.png` récupéré via `git fetch --refetch origin`
  - legacy isolé sous `legacy/` sans suppression volontaire
  - `legacy/README.md` ajouté : archive non exécutable, source de migration uniquement
  - `.codex/` ignoré comme outillage local non destiné au commit
  - `DigiTrove_Schema_BDD_v1.md` corrigé : Laravel 13.19, extension `citext`,
    `users.deleted_at`, `status` business sans `deleted`, ordre futur des migrations
  - décisions finales pré-P1 loggées : multi-devises, checkout invité, compte client
    suggéré mais non obligatoire, affiliation future avec compte obligatoire et
    tables dédiées hors P1
  - aucune migration P1, aucune table métier, aucune logique métier ajoutée
- **SITE-00 Vitrine statique de prévisualisation terminé techniquement et mergé** :
  - PR #1 mergée correctement dans `p0-foundations-laravel13`
  - commit de merge : `83b6b0c Merge pull request #1 from mysterus44/site-00-static-preview`
  - branche locale `site-00-static-preview` supprimée après vérification qu'elle
    était mergée
  - branche distante `origin/site-00-static-preview` conservée
  - `origin/main` ne contient pas SITE-00
  - page d'accueil Laravel remplacée par une vitrine/boutique statique premium
  - produits, prix XOF, catégories, avis et aperçus blog issus du contenu legacy
    figés dans la vue
  - images marketing sûres copiées vers `public/images/digitrove/`
  - CTA limités à des ancres ou boutons désactivés, sans route transactionnelle
  - tests HTTP ajoutés : homepage 200, produits/prix visibles, absence de `/checkout`,
    `legacy/`, uploads, liens de livraison, `.zip`, `.pdf`, `download_grants`,
    `product_files`
  - aucune migration, aucune table, aucun modèle métier, aucun contrôleur métier,
    aucun panier, aucun paiement, aucun téléchargement public
- **P1 Identité implémenté et mergé dans `p0-foundations-laravel13`** :
  - PR #2 mergée correctement via
    `3f9d132 Merge pull request #2 from mysterus44/p1-identity`
  - branche locale `p1-identity` supprimée après vérification qu'elle était mergée
  - branche distante `origin/p1-identity` conservée
  - `origin/main` reste intact à `1e41b92 DigiTrove V2`
  - schéma BDD v1 officiellement validé par KingKouda
  - extension PostgreSQL `citext`
  - tables strictement P1 : `users`, `customer_profiles`, `visitors`
  - modèles : `User`, `CustomerProfile`, `Visitor`
  - enums : `UserRole`, `UserStatus`, `LifecycleStage`
  - factories : `UserFactory`, `CustomerProfileFactory`, `VisitorFactory`
  - tests PostgreSQL : extension, tables, colonnes, email CITEXT unique,
    `password_hash` Argon2id, contraintes SQL, relations, SoftDeletes,
    visiteurs anonymes et garde-fou anti tables hors périmètre
  - aucun catalogue, panier, commande, paiement, téléchargement, affiliation,
    analytics, checkout invité ou front métier ajouté
- **P2 Catalogue implémenté et mergé dans `p0-foundations-laravel13`** :
  - PR #3 mergée correctement via
    `aff4d05 Merge pull request #3 from mysterus44/p2-catalog`
    (commits intégrés `d43751d` + `fbaa33a`, base `9a11791`)
  - `origin/main` reste intact à `1e41b92 DigiTrove V2` (P2 absent de `main`)
  - branche locale `p2-catalog` supprimée après vérification `git branch -d` (merge confirmé)
  - branche distante `origin/p2-catalog` conservée
  - `main` local réaligné (pointeur only, `git branch -f main origin/main`) sur `1e41b92`
  - branche créée depuis `origin/p0-foundations-laravel13` à `9a11791`
  - migrations strictement P2 : `categories`, `products`, `product_prices`,
    `product_files`, `product_category`, `product_bundles`
  - modèles : `Category`, `Product`, `ProductPrice`, `ProductFile`
  - enums : `ProductType`, `ProductStatus`
  - factories P2 sans création de vrai fichier digital
  - tests PostgreSQL : tables P2, contraintes, prix multi-devises en `BIGINT`,
    fichiers privés, relations, bundles, SoftDeletes, garde-fous hors périmètre
  - corrections d'audit P2 : index FK inverses explicites, timestamps catégories
    officialisés, `storage_path` durci pour chemins privés relatifs uniquement
  - aucune donnée legacy importée, aucun fichier digital copié, aucun Filament/admin,
    aucune route/API, aucun checkout, paiement, panier, commande, livraison,
    analytics ou affiliation ajouté
  - cycles indirects de bundles toujours non exposés et à traiter avant toute
    écriture métier/admin/API
- **P3A Coupons et Paniers implémenté et mergé** :
  - PR #4 mergée dans `p0-foundations-laravel13` via `234e303`
  - commits intégrés : `81f32fc` (implémentation) + `1c0d5a2` (tests renforcés)
  - branche locale `p3a-coupons-carts` supprimée après preuve du merge
  - branche distante `origin/p3a-coupons-carts` conservée à `1c0d5a2`
  - `origin/main` reste intact à `1e41b92`, sans P3A
  - six migrations strictement P3A : `coupons`, `coupon_currency_rules`,
    `coupon_products`, `coupon_categories`, `carts`, `cart_items`
  - modèles : `Coupon`, `CouponCurrencyRule`, `Cart`, `CartItem`
  - enums : `CouponDiscountType`, `CartStatus`
  - factories sans secret brut, prix panier ou fichier digital réel
  - tests PostgreSQL des contraintes CITEXT, montants `BIGINT`, pivots, UUID/hash,
    FK prudentes, index et absence de tables hors périmètre
  - couverture de régression renforcée : cascades réelles panier → articles et
    coupon → règles/pivots, avec préservation des produits et catégories
  - introspection PostgreSQL verrouillant `coupons.code` en `citext`,
    `carts.public_id` en `uuid` et les devises panier/coupon en `varchar(3)`
  - cohérences coupon inter-tables reportées à la future logique transactionnelle
  - aucun contrôleur, route, API, service, Filament, checkout, commande, paiement,
    webhook, remboursement, téléchargement, import legacy ou déploiement Azure
  - P3B/P3C absents du périmètre et de la PR P3A
- **P3B Commandes implémenté, corrigé et mergé** :
  - [PR #5](https://github.com/mysterus44/DigiTrove/pull/5) mergée dans
    `p0-foundations-laravel13` via `f07d225`
  - commits intégrés : `b42371b` (implémentation) + `499e2bd` (durcissement sécurité/tests)
  - branche locale `p3b-orders` supprimée après preuve du merge
  - branche distante `origin/p3b-orders` conservée à `499e2bd`
  - `origin/main` reste intact à `1e41b92`, sans P3B
  - décision D-027 validée humainement puis appliquée sur `p3b-orders`
  - ordre : `orders` → `order_items` → `coupon_redemptions`
  - suppression et mutations commerciales des commandes/lignes bloquées par triggers
    PostgreSQL ; seules transitions de cycle de vie et nullifications FK contrôlées
  - une ligne maximum par produit non NULL via index unique partiel
  - cohérence lignes/commande et commande/redemption validée au commit par constraint
    triggers différés ; `SET CONSTRAINTS ALL IMMEDIATE` obligatoire dans les tests
  - consommation coupon seulement après paiement serveur confirmé en P3C, sous verrou,
    avec identité `HMAC-SHA-256` versionnée ; aucune réservation pendant `pending`
  - remises P3 limitées aux coupons ; `discount_minor = 0` sans snapshots coupon
  - trois migrations, trois modèles, un enum, trois factories et tests PostgreSQL créés
  - correction post-review : `orders_coupon_snapshot_consistency_check` ferme le cas
    PostgreSQL `CHECK = UNKNOWN` ; tests P3B vérifient SQLSTATE + nom de contrainte
    ou message trigger, scénarios coupon NULL couverts, rollback P3B automatisé
  - audit post-merge : trois tables P3B, six fonctions, huit triggers, dont quatre
    constraint triggers `DEFERRABLE INITIALLY DEFERRED`, confirmés dans PostgreSQL
  - aucun code P3C, checkout, paiement, webhook, remboursement ou livraison

---

## ⏭️ PROCHAINE TÂCHE

**P4-A0 est mergé et clôturé** dans `p0-foundations-laravel13` via
[PR #11](https://github.com/mysterus44/DigiTrove/pull/11), merge `a047571` (parents
`abaea6e` + `8b822c1`). La migration `000008`, la fonction G0
`enforce_product_file_content_immutability` et le trigger BEFORE UPDATE
`product_files_enforce_content_immutability_trigger` sont confirmés dans PostgreSQL
(1 fonction, 1 trigger non interne actif, aucune table/objet P4 supplémentaire).

Validation post-merge réelle : 24 migrations ; suite complète 116 tests /
1666 assertions ; Pint 102 fichiers ; `git diff --check` propre ; aucune base
temporaire résiduelle. Colonnes figées confirmées par `pg_get_functiondef`
(`IS DISTINCT FROM`) : `id`, `product_id`, `storage_disk`, `storage_path`,
`checksum_sha256`, `size_bytes`, `mime_type`, `version`, `created_at` ; mutables :
`original_name` (seul usage code = `$fillable`, libellé d'affichage), `position`,
`is_active`. Rollback isolé frontière `000008` vert. Branche locale supprimée,
distante conservée à `8b822c1`. `origin/main` intact à `11130f4`.

**P4-A1 est mergé et clôturé** dans `p0-foundations-laravel13` via
[PR #12](https://github.com/mysterus44/DigiTrove/pull/12), merge `93d1f17` (parents
`a1e2e7f` + `94b018c`). La migration `000009`, la table
`order_item_bundle_components`, le modèle/factory/relations et les trois fonctions /
trois triggers S1/S2/S3 sont confirmés dans PostgreSQL (triggers non internes,
actifs, **non deferrable** ; aucun S4 ; aucune contrainte de cardinalité ; aucune
fonction de copie).

Validation post-merge réelle : 25 migrations ; suite complète **133 tests /
1882 assertions** ; Pint **106 fichiers** ; `git diff --check` propre ; aucune base
temporaire résiduelle. Schéma confirmé par introspection : `order_item_id` bigint
NOT NULL **RESTRICT**, `child_product_id` bigint NULL **SET NULL**, snapshots
name/slug text NOT NULL, `created_at` timestamptz NOT NULL ; aucune colonne
quantity/position/updated_at/jsonb/metadata ; CHECK not-blank en
`btrim(col, E' \t\n\r\f\v')` ; unique partiel `oibc_order_item_child_unique ...
WHERE (child_product_id IS NOT NULL)` ; index `oibc_order_item_id_index` et
`oibc_child_product_id_index`. S2 utilise `IS DISTINCT FROM` + `pg_trigger_depth()
> 1` ; **S3 ne mute rien** (vérifié sur `pg_get_functiondef`). Bundles imbriqués
refusés ; **bundle vide techniquement autorisé en BDD** (refus = future garantie
applicative de l'OrderService, P4-A2 fail-closed) ; exhaustivité applicative
uniquement ; risque d'insertion tardive par rôle SQL privilégié documenté et non
éliminé. Rollback isolé `000009` vert (P4-A0/G0 et P0–P3C préservés). Branche
locale supprimée, distante conservée à `94b018c`. `origin/main` intact `11130f4`.
Action suivante : **plan technique P4-A2 — Download Grants**, dans une exécution
séparée, avant toute migration.
- prochaine branche réservée : `p4-a2-download-grants` (depuis la stable `93d1f17`) ;
- prochaine migration réservée : `2026_07_14_000010_create_download_grants_table.php`
  (frontière rollback `000010`) : table `download_grants` + G1–G4 (prevent-delete,
  immutabilité + consommation +1 bornée, préconditions d'émission sous verrou
  `orders FOR UPDATE`, cohérence différée bidirectionnelle grant↔commande).
Rappels de contrat (D-029/D-029.1/D-029.2/D-029.3) : unité `order_item ×
product_file` ; `token_hash VARCHAR(64)` SHA-256 unique, token brut jamais stocké ;
`public_id UUID` ; FK RESTRICT + `user_id SET NULL` (audit) ; un seul grant ACTIF
par couple (index partiel `WHERE revoked_at IS NULL`) ; **`max_downloads` et
`expires_at` EXPLICITES à l'insertion, aucun DEFAULT commercial** ; TTL 72 h /
quota 5 / rétention 365 j = simples recommandations de config applicative ;
révocation set-once appariée au motif ; rotation = verrouiller `orders` AVANT de
révoquer puis insérer (anti-deadlock) ; lignée bundle prouvée **uniquement** contre
`order_item_bundle_components` — **aucun repli sur `product_bundles`**, fail-closed
si le snapshot est absent ou incomplet.
Ensuite, P4-B `p4-b-download-logs` (`000011`) uniquement APRÈS merge de P4-A2.

Gate : aucun contrôleur/route, checkout, webhook HTTP, fournisseur de paiement concret,
SDK, Filament, job de purge, téléchargement réel, token réel ou déploiement Azure.
Aucune consommation applicative réelle avant P4-B. P5 reste non démarré.
Ne jamais pousser sur `main`. Plan avant code, une feature à la fois, BDD avant logique.

Note régression P3C-A (transparence) : ajouter `payments` a rendu obsolètes des
assertions « table interdite » dans `IdentitySchemaTest`, `CatalogSchemaTest`,
`P3ACouponsCartsSchemaTest` et `P3BOrdersSchemaTest` — `payments` retiré de ces listes
(webhooks/refunds restent interdits). La cohérence bidirectionnelle paiement↔commande
est **imposée par D-028.2** (pas un nouveau choix) ; l'utilisateur a seulement retenu,
via question interactive, l'**option d'adaptation des fixtures** (plutôt qu'affaiblir la
règle) : les fixtures P3B `paid`/`payment_review` reçoivent désormais un paiement cohérent.
Aucun trigger/fonction P3B modifié. Isolation des tests de rollback : le correctif
`0d04f77` (step dynamique) corrigeait le symptôme mais **pas l'isolation** (base
temporaire migrée entièrement puis rollback « gate → fin », rollbackant les gates
ultérieurs). Corrigé via `tests/Support/PhaseMigrationHarness` : chaque test de rollback
applique **uniquement** les migrations jusqu'à la frontière du gate (`migrate --path=…`,
aucune migration postérieure exécutée) puis rollbacke **uniquement** les migrations du
gate (`migrate:rollback --path=…`), en vérifiant les objets antérieurs préservés.
Plus aucun `--step`, `migrate:fresh` ni `count - gateIndex` dans ces tests.

Points reportés sans bloquer la structure : durée métier `pending` (30 min recommandé),
anonymisation invité, rotation des secrets HMAC, valeur de rétention webhook
(90 j recommandé), gestion du paiement tardif `requires_review`.

Toujours respecter : BDD avant logique, plan avant code, une seule feature à la fois.

---

## ⚠️ POINTS D'ATTENTION

- 🚨 **Legacy** : deux mots de passe en clair étaient dans l'historique git de l'ancien
  dépôt. Les scripts concernés sont neutralisés, mais les valeurs historiques doivent
  rester considérées compromises.
- Le legacy est archivé sous `legacy/` pour migration de contenu uniquement. Ne pas
  exécuter ce code PHP et ne pas servir ce dossier publiquement.
- `legacy/data/users.sqlite` peut exister localement pour audit/migration, mais reste
  ignoré par Git (`*.sqlite`).
- PHP/Composer ne sont pas installés sur le host Windows. Utiliser l'image :
  `docker build -f docker/php/Dockerfile -t digitrove-php:dev .`
- Redis DigiTrove est exposé sur le port hôte `6380` pour éviter le conflit avec un
  conteneur existant `8fi-redis` sur `6379`.
- Le schéma BDD v1 attend la validation finale de KingKouda avant P1.
- Multi-devises validé : `currency` obligatoire sur les montants, montants en
  `BIGINT` unités mineures. Pour P2, prix fixes par devise via `product_prices`
  (D-018) avec `currency VARCHAR(3)` contraint longueur 3 + majuscules (D-019) ;
  conversion automatique et taux de change reportés.
- Checkout invité validé : un visiteur peut acheter via `visitors` + e-mail sans
  créer de compte. Le compte reste fortement suggéré pour historique d'achat,
  promotions, annonces, avantages CRM et future affiliation.
- Affiliation future validée : compte obligatoire, rattachement à `users`, tables
  dédiées à prévoir plus tard (`affiliate_profiles`, `affiliate_links`,
  `referrals`, `affiliate_commissions`, `affiliate_payouts`). Ne pas migrer en P1.
- P1 reste strictement limité à `users`, `customer_profiles`, `visitors` + extension
  PostgreSQL `citext`.
- `git fsck --full` ne signale plus de `missing blob`; les `dangling tree` restants
  sont des objets non référencés et ne bloquent pas P1.
- La question ouverte du champ `usb` (produits legacy) n'est pas tranchée — voir
  `AUDIT_LEGACY.md`.
- Blocage précédent résolu : pas de push direct sur `origin/main`; la suite passe par
  la branche dédiée `p0-foundations-laravel13`.

---

## 📝 JOURNAL DES PASSATIONS (le plus récent en haut)

### 2026-07-16 — Claude Code (clôture post-merge P4-A1)
- Fait : **P4-A1 mergé** via [PR #12](https://github.com/mysterus44/DigiTrove/pull/12),
  merge `93d1f173b6021fccb7d4df70e24938e02d16f3e3` (exactement deux parents
  `a1e2e7f` + `94b018c`, sujet « Merge pull request #12 from
  mysterus44/p4-a1-bundle-purchase-snapshots »). Commit P4-A1 `94b018c` intégré et
  ancêtre de la stable. `p0-foundations-laravel13` synchronisé fast-forward
  (`a1e2e7f..93d1f17`, 10 fichiers). `origin/main` intact `11130f4`.
- Introspection PostgreSQL post-merge : table `order_item_bundle_components` avec
  les six colonnes exactes (aucune quantity/position/updated_at/jsonb/metadata) ;
  FK `order_item_id` **RESTRICT** + `child_product_id` **SET NULL** ; CHECK
  not-blank `btrim(col, E' \t\n\r\f\v')` ; unique partiel
  `oibc_order_item_child_unique ... WHERE (child_product_id IS NOT NULL)` ; index
  `oibc_order_item_id_index` / `oibc_child_product_id_index` (aucun index
  inattendu) ; **3 fonctions / 3 triggers** S1 (BEFORE DELETE), S2 (BEFORE UPDATE,
  `IS DISTINCT FROM` + `pg_trigger_depth() > 1`), S3 (BEFORE INSERT), tous non
  internes (`tgisinternal=f`), actifs (`O`), **non deferrable** ; **S3 ne mute
  rien** (aucun INSERT/UPDATE/DELETE ni affectation à `NEW` dans
  `pg_get_functiondef`) ; **0 constraint trigger de cardinalité, 0 fonction de
  copie, 0 S4** — bundle vide toujours accepté par la BDD (garde-fou applicatif).
- Périmètre mergé (`a1e2e7f..93d1f17`) audité : migration `000009`, modèle
  `OrderItemBundleComponent`, factory, relation `OrderItem::bundleComponents()`,
  test P4-A1, adaptation historique P4-A0, et 4 documents (le schéma v1 était déjà
  finalisé par D-029.3). Aucun `000010`/`000011`, DownloadGrant, DownloadLog,
  OrderService, CheckoutService, route, contrôleur, service, job, listener, token,
  endpoint, P5. Migrations `000001`–`000008` inchangées.
- Non-régression : G0/P4-A0 présent (1 fonction + 1 trigger) ; `products`,
  `product_files`, `product_bundles`, `orders`, `order_items`, `payments`,
  `refunds`, `payment_webhook_events` intactes ; 5 fonctions refunds présentes ;
  `download_grants`/`download_logs` absents.
- Validation : `migrate:fresh` 25 migrations ; suite complète **133 / 1882** ; Pint
  **106** ; `git diff --check` propre ; rollback isolé `000009` vert (P4-A0/G0 et
  P0–P3C préservés) ; aucune base temporaire résiduelle.
- Nettoyage : branche locale `p4-a1-bundle-purchase-snapshots` supprimée
  (`git branch -d`, merge confirmé) ; distante conservée à `94b018c`.
- Décisions : aucune nouvelle (D-029.3 inchangée ; merge consigné).
- Laisse à : **plan technique P4-A2 — Download Grants** (branche
  `p4-a2-download-grants`, migration `000010`), exécution séparée. P4-A2/P4-B/P5
  non démarrés.

### 2026-07-16 — Claude Code (P4-A1 Bundle Purchase Snapshot implémenté)
- Fait : gate **P4-A1** sur branche `p4-a1-bundle-purchase-snapshots` (depuis
  `a1e2e7f`, état Git prouvé). Migration unique
  `2026_07_14_000009_create_order_item_bundle_components_table.php` : table
  `order_item_bundle_components` (FK `order_item_id` RESTRICT + `child_product_id`
  SET NULL, snapshots textuels name/slug, `created_at`, CHECK not-blank nommés,
  unique partiel `oibc_order_item_child_unique WHERE child_product_id IS NOT NULL`,
  index `oibc_order_item_id_index`/`oibc_child_product_id_index`) et **3 fonctions /
  3 triggers** (S1 prevent-delete, S2 immutabilité ROW + exception FK
  `pg_trigger_depth() > 1`, S3 validation BEFORE INSERT). Modèle
  `OrderItemBundleComponent` (`$timestamps = false`, `created_at` immutable_datetime),
  `OrderItemBundleComponentFactory` (part toujours d'un achat cohérent ; ne crée
  jamais de lien pivot absent, ne modifie jamais un pivot existant ni l'order_item),
  relation `OrderItem::bundleComponents()`.
- Deux findings corrigés en cours de gate : (1) **`btrim/1` ne retire que les
  espaces** → CHECK durci en `btrim(col, E' \t\n\r\f\v')` (un snapshot de
  tabulations passait) — renforcement, jamais un affaiblissement ; (2) `order_number`
  du test de concurrence hors alphabet Crockford (`O` interdit). Les fixtures PDO
  brutes groupent order+order_item dans une transaction explicite (le trigger
  différé P3B valide au COMMIT).
- Tests : `tests/Feature/P4A1BundlePurchaseSnapshotTest.php` — **17 tests /
  217 assertions** : schéma physique complet (types, nullabilité, FK `r`/`n`, CHECK,
  index partiel + prédicat, 3 fonctions, 3 triggers, timings, **0 trigger différé,
  0 contrainte de cardinalité**), snapshot valide 1..N, factory par défaut + ordre
  des states, même composant dans deux order_items/commandes, order_item direct
  refusé, chaque violation S3 (child NULL, autre bundle, non attaché, **bundle
  imbriqué refusé**, bundle purgé, FK autorité pour l'inexistence), doublon 23505,
  CHECK blank/whitespace + NOT NULL, S1 (DELETE simple/multiple/relation), S2
  (colonne par colonne, valeur identique acceptée, multi-colonnes, SQL brut +
  Eloquent), **nullification FK** (UPDATE manuel et swap refusés, DELETE product réel
  autorisé → NULL + textes préservés), **historique** (C ajouté après achat absent du
  snapshot, B retiré toujours reconnu, snapshot tardif de B refusé), **bundle vide
  accepté par la BDD** (règle applicative documentée dans le test), **concurrence
  2 connexions** (verrou `products FOR UPDATE` → 55P03, INSERT...SELECT unique,
  double copie → 23505), zéro effet collatéral, rollback isolé `000009`.
- **Risque résiduel documenté dans le test lui-même** : un rôle SQL privilégié peut
  insérer tardivement un composant ajouté après l'achat (S3 lit légitimement le pivot
  à la copie) — PostgreSQL ne l'empêche pas ; permissions, absence d'API de mutation
  et tests le couvrent. Jamais présenté comme une garantie.
- Validation : 25 migrations ; suite complète **133 / 1882** (baseline 116/1666 +
  17/217, zéro régression) ; Pint **106** ; `git diff --check` propre ; aucune base
  temporaire résiduelle ; migrations `000001`–`000008` intactes.
- Décisions : aucune nouvelle (note d'exécution sous D-029.3, dont le durcissement
  `btrim` signalé).
- Laisse à : review humaine + merge de la PR `p4-a1-bundle-purchase-snapshots` →
  `p0-foundations-laravel13`, puis clôture documentaire post-merge, puis P4-A2
  (`000010`) en exécution séparée. P4-A2/B et P5 non démarrés.

### 2026-07-16 — Claude Code (plan final P4-A1 + décisions D-029.3)
- Fait : **finalisation documentaire du plan P4-A1** (exécution en lecture/analyse,
  décision **D-029.3**). Introspection PostgreSQL prouvant le problème :
  `product_bundles` n'a **ni timestamps, ni trigger, ni historique** (PK composite
  `(bundle_id, child_product_id)`, FK CASCADE, colonne `position` seule) — sa
  composition est librement mutable et ne conserve aucune trace du vendu. Un
  order_item bundle se reconnaît par `product_type_snapshot = 'bundle'` (figé P3B).
  `products.name/slug` sont mutables (aucun trigger) → snapshots textuels justifiés.
- **KingKouda a tranché : Q1=A, Q2=A** → (1) **bundles imbriqués EXCLUS** : S3
  refuse tout composant `type='bundle'` (les cycles indirects restent non protégés ;
  l'aplatissement serait exposé aux cycles, le conserver livrerait un achat
  incomplet en silence) ; (2) **S3 validation immédiate BEFORE INSERT** (order_item
  bundle, `product_id` non NULL, composant existant/non-bundle/∈ pivot à la copie ;
  vérifie et refuse, ne mute jamais) + **exhaustivité APPLICATIVE** via un unique
  `INSERT ... SELECT` de l'OrderService dans la même transaction, après `products
  FOR UPDATE`. Options écartées documentées : constraint trigger différé (faux
  refus sous concurrence + dépendance permanente au pivot) et fonction de copie
  atomique (muterait, contre le principe « triggers vérifient, service mute »).
  Risque résiduel assumé (insertion tardive par rôle SQL privilégié, couvert par
  permissions/absence d'API/tests) — **jamais présenté comme éliminé par PostgreSQL**.
- Objets P4-A1 arrêtés : table + **3 fonctions / 3 triggers** (S1/S2/S3) ; aucune
  quantité (le pivot n'en a pas) ; aucune `position` ; aucun fallback pivot en
  P4-A2 ; fail-closed si snapshot absent ou incomplet.
- **Garde-fou bundle vide** (précision validée) : la BDD autorise techniquement un
  snapshot vide (aucune cardinalité minimale — l'imposer exigerait le constraint
  trigger différé écarté) ; le futur OrderService refuse la commande d'un bundle
  vide avant la création de l'order_item ; une copie échouée/oubliée laisse P4-A2
  fail-closed. Garantie APPLICATIVE, jamais un invariant PostgreSQL.
- État build/tests : `git diff --check` propre ; **aucun fichier PHP touché** (docs
  seuls). Baseline inchangée : 24 migrations, 116 tests / 1666 assertions, Pint 102.
- Décisions prises (→ DECISIONS_LOG.md) : **D-029.3**.
- Laisse à : **implémentation P4-A1** (`p4-a1-bundle-purchase-snapshots`, migration
  `000009`), exécution séparée. Aucune migration, branche, classe ou test créés ici.

### 2026-07-16 — Claude Code (clôture post-merge P4-A0)
- Fait : **P4-A0 mergé** via [PR #11](https://github.com/mysterus44/DigiTrove/pull/11),
  merge `a047571fe4e3453fec39336f297f4241cbb95898` (exactement deux parents
  `abaea6e` + `8b822c1`, sujet « Merge pull request #11 from
  mysterus44/p4-a0-product-file-immutability »). Commit P4-A0 `8b822c1` intégré et
  ancêtre de la stable. `p0-foundations-laravel13` synchronisé fast-forward
  (`abaea6e..a047571`, 6 fichiers). `origin/main` intact `11130f4`.
- Introspection PostgreSQL post-merge : 1 fonction
  `enforce_product_file_content_immutability` (corps `IS DISTINCT FROM` sur id +
  product_id + storage_disk + storage_path + checksum_sha256 + size_bytes +
  mime_type + version + created_at, RAISE 23514 + DETAIL colonne, retourne NEW sans
  réécriture), 1 trigger `product_files_enforce_content_immutability_trigger`
  BEFORE UPDATE FOR EACH ROW sur `product_files`, non interne (`tgisinternal=f`),
  actif (`tgenabled=O`). Aucune autre fonction/trigger/table P4. Migration `000008`
  ne crée que la fonction + le trigger (aucune colonne modifiée, aucune donnée
  réécrite, aucune table, aucun index).
- Périmètre mergé (`abaea6e..a047571`) audité : uniquement migration `000008`,
  test `P4A0ProductFileImmutabilityTest`, et 4 documents de suivi
  (DECISIONS_LOG/PROGRESS_TRACKER/CLAUDE.md/HANDOFF ; le schéma v1 était déjà
  finalisé par le commit D-029.2). Aucun modèle/enum/factory/table snapshot/grant/
  log/route/contrôleur/service/job/listener/token/endpoint/P5. Migrations
  `000001`–`000007` inchangées. `original_name` : seul usage code = `$fillable`
  (libellé d'affichage confirmé).
- Validation : `migrate:fresh` 24 migrations ; suite complète **116 / 1666** ;
  Pint **102** ; `git diff --check` propre ; rollback isolé frontière `000008` vert
  (données `product_files` préservées, P0–P3C intacts, mutabilité retrouvée après
  down() — documenté) ; aucune base temporaire résiduelle.
- Nettoyage : branche locale `p4-a0-product-file-immutability` supprimée
  (`git branch -d`, merge confirmé) ; distante conservée à `8b822c1`.
- Décisions : aucune nouvelle (D-029/D-029.1/D-029.2 inchangées ; merge consigné).
- Laisse à : **plan P4-A1 — Bundle Purchase Snapshot** (branche
  `p4-a1-bundle-purchase-snapshots`, migration `000009`), exécution séparée. Aucun
  code P4-A1/A2/B/P5 démarré.

### 2026-07-16 — Claude Code (P4-A0 ProductFile Content Immutability implémenté)
- Fait : gate **P4-A0** sur branche `p4-a0-product-file-immutability` (depuis
  `abaea6e`, état Git prouvé conforme). Migration ADDITIVE unique
  `2026_07_14_000008_harden_product_files_content_immutability.php` : fonction
  `enforce_product_file_content_immutability` (comparaisons `IS DISTINCT FROM`
  résistantes à NULL, RAISE 23514 avec message stable + DETAIL nommant la colonne)
  + trigger `product_files_enforce_content_immutability_trigger` BEFORE UPDATE.
  Figés : `id` (précédent P3B/P3C, signalé dans D-029.2) + les huit colonnes
  D-029.2. Mutables : `original_name`/`position`/`is_active`. La migration P2
  mergée n'est pas touchée ; aucune table/modèle/enum/factory créé.
- Tests : `tests/Feature/P4A0ProductFileImmutabilityTest.php` — 9 tests /
  132 assertions : introspection physique (fonction, trigger BEFORE UPDATE,
  colonnes surveillées exactes), refus colonne par colonne (valeur d'origine
  préservée), UPDATE même-valeur accepté, mutables seuls acceptés, refus atomique
  des mélanges (`original_name`+`storage_path`, `position`+`version`), SQL brut et
  Eloquent refusés pareil, « nouvelle version = nouvelle ligne » (A intacte puis
  désactivée, B active), absence d'effets collatéraux, rollback isolé frontière
  `000008` avec données `product_files` préservées et mutabilité retrouvée après
  down() (comportement documenté honnêtement).
- Validation : suite complète **116 tests / 1666 assertions** (baseline 107/1534
  + 9/132, zéro régression, aucune adaptation historique nécessaire), Pint
  **102 fichiers**, `git diff --check` propre, aucune base temporaire résiduelle.
- Décisions : aucune nouvelle (note factuelle dans D-029.2 : `id` figé par
  alignement sur le précédent projet).
- Laisse à : review humaine + merge de la PR `p4-a0-product-file-immutability` →
  `p0-foundations-laravel13`, puis clôture documentaire post-merge, puis P4-A1
  (`000009`) dans une exécution séparée. P4-A1/A2/B et P5 non démarrés.

### 2026-07-15 — Claude Code (D-029.2 : version figée + gates P4 isolés)
- Fait : correction documentaire pré-implémentation (**D-029.2**), sur état Git
  prouvé (`6ba74e4` = distant, push manuel D-029.1 confirmé, aucun artefact P4).
  (1) **`product_files.version` devient IMMUABLE** avec le contenu (une étiquette
  de version renommable après achat rendait l'historique improuvable) ; G0 fige
  désormais `product_id`, `storage_disk`, `storage_path`, `checksum_sha256`,
  `size_bytes`, `mime_type`, `version`, `created_at` ; `original_name` audité dans
  le code (aucun usage de résolution/clé/intégrité/preuve — libellé d'affichage au
  téléchargement) → mutable, distinction écrite. (2) **Gate composite P4-A abandonné**
  (sa frontière `000010` n'aurait pas retiré `000008`/`000009`) → QUATRE gates
  isolés : P4-A0 `p4-a0-product-file-immutability` (`000008`) → P4-A1
  `p4-a1-bundle-purchase-snapshots` (`000009`) → P4-A2 `p4-a2-download-grants`
  (`000010`) → P4-B `p4-b-download-logs` (`000011`), chacun avec sa frontière de
  rollback, merge obligatoire avant le gate suivant, migration N+1 jamais créée
  avant merge du gate N.
- État build/tests : aucun fichier PHP touché (docs seuls) ; baseline inchangée
  (23 migrations, 107 tests / 1534 assertions).
- Décisions prises (→ DECISIONS_LOG.md) : **D-029.2**.
- Laisse à : **P4-A0 — ProductFile Content Immutability** (exécution séparée,
  périmètre strict dans PROCHAINE TÂCHE). Aucune branche/migration/classe P4 créée ici.

### 2026-07-15 — Claude Code (audit contradictoire P4 + décisions D-029.1)
- Fait : **audit final en lecture seule du plan P4** au commit `202e4b8` (preuves
  Git : push confirmé, `origin/main` intact, commit strictement documentaire — la
  « contradiction » du rapport précédent était l'état pré-commit `94d0dec`, parent
  de `202e4b8`). Verdict initial : `DÉCISIONS P4 REQUISES` — trois failles réelles :
  (1) `product_files` mutable in-place ⇒ « version achetée » non garantie ;
  (2) `product_bundles` sans historique ⇒ lignée bundle prouvée sur la composition
  courante, pas celle de l'achat ; (3) `max_downloads DEFAULT 5` figeait une
  politique commerciale dans le schéma.
- **KingKouda a tranché : B–A–B** → amendement **D-029.1** consigné, documents du
  plan mis à jour : P4-A devient TROIS migrations (`000008` durcissement G0
  `product_files`, `000009` snapshot `order_item_bundle_components` S1/S2,
  `000010` `download_grants` sans DEFAULT commercial, frontière rollback `000010`) ;
  P4-B `download_logs` passe à `000011`. Précisions d'audit intégrées : règle
  anti-deadlock de rotation (verrou `orders` d'abord), sémantique stricte
  `download_logs.status` (jamais de token inconnu en table), autorisation =
  conjonction pure sur colonnes vérifiables.
- État build/tests : aucun fichier PHP touché (docs seuls) ; baseline inchangée
  (23 migrations, 107 tests / 1534 assertions).
- Décisions prises (→ DECISIONS_LOG.md) : **D-029.1** (B–A–B).
- Laisse à : **implémentation P4-A** sur `p4-a-download-grants` (exécution séparée,
  voir PROCHAINE TÂCHE). Aucune branche/migration/classe P4 créée ici.

### 2026-07-15 — Claude Code (plan final P4 Delivery & Download Integrity)
- Fait : **finalisation documentaire du plan P4** (exécution strictement documentaire,
  décision **D-029**). Garde-fous Git confirmés : `p0-foundations-laravel13` à
  `94d0dec` = distant, worktree propre, `122332a`/`1270c53` ancêtres, `origin/main`
  intact à `11130f4`, aucune branche/migration/classe P4 existante. Bloc P4 de
  `DigiTrove_Schema_BDD_v1.md` réécrit : schéma cible `download_grants` (P4-A,
  `000008`) et `download_logs` (P4-B, `000009`), CHECK nommés anti-`CHECK = UNKNOWN`,
  index partiels (un grant actif par couple), catalogue G1–G6 (prevent-delete,
  immutabilité + consommation +1 bornée, préconditions d'émission sous verrou
  `orders FOR UPDATE` avec lignée produit/bundle, cohérence différée bidirectionnelle
  grant↔commande, journal append-only, purge contrôlée par rétention), threat model
  et plan de tests (dont concurrence à 2 connexions sur la dernière utilisation).
- Contrat prouvé sans nouvelle décision humaine : D-009/D-010/D-014/D-028 + schéma
  v1 + `SECURITE_TELECHARGEMENT.md`. `licenses` exclu de P4 (option produit non
  décidée). Limite P3C-C documentée : remboursement partiel non ciblable par ligne
  ⇒ aucune révocation automatique en partiel ; révocation totale exigée au commit
  quand la commande passe à `refunded`.
- État build/tests : `git diff --check` OK ; **aucun fichier PHP touché** (docs
  seuls : DECISIONS_LOG, schéma v1, PROGRESS_TRACKER, HANDOFF, CLAUDE.md). Baseline
  inchangée : 23 migrations, 107 tests / 1534 assertions, Pint 100 fichiers.
- Décisions prises (→ DECISIONS_LOG.md) : **D-029** plan P4.
- Laisse à : **validation humaine du plan P4**, puis implémentation P4-A
  (`p4-a-download-grants`, migration `000008`) dans une exécution séparée. Aucune
  migration, modèle, enum, factory, route, service ou logique P4/P5 créés ici.

### 2026-07-15 — Codex (clôture post-merge P3C-C Refunds)
- Fait : **P3C-C mergé** via [PR #10](https://github.com/mysterus44/DigiTrove/pull/10),
  merge `122332aa5cc9fc25e9bf1898224f5a1da30f6446` (parents `be74854` + `1270c53`).
  Le commit final audité `1270c530124fc605ade299f441277edbbf1c5534` est intégré.
- PostgreSQL réel : 23 migrations ; table `refunds`, cinq fonctions, six triggers dont
  deux constraint triggers `DEFERRABLE INITIALLY DEFERRED`; plafond cumulatif sous
  verrou Payment, concurrence réelle, mapping Refund↔Order et nullification contrôlée
  de l'initiateur confirmés. Rollback isolé `000007` sans objet/base résiduel.
- Validation : P3C-C **16/461**, P3C-B **13/189**, P3C-A **16/221**, P3B **18/359**,
  suite complète **107/1534**, Pint **100**, `git diff --check` propre.
- Nettoyage : branche locale `p3c-c-refunds` supprimée ; branche distante conservée à
  `1270c53`. `origin/main` intact à `11130f4`. Aucune nouvelle décision D-028.
- Laisse à : **plan P4 — intégrité delivery/download**, exécution séparée. Aucun code P4/P5.

### 2026-07-15 — Claude Code (clôture post-merge P3C-B.1)
- Fait : **durcissement P3C-B.1 mergé** via [PR #9](https://github.com/mysterus44/DigiTrove/pull/9)
  → `13932ac` (2 parents `51c4847` + `c772ac1`). `p0-foundations-laravel13` synchronisé
  fast-forward (`963eef0..13932ac`, inclut aussi le merge P3C-B PR #8 `51c4847`).
- Validation post-merge (PostgreSQL réel) : 22 migrations ; **index de rejeu durci confirmé**
  par introspection (`WHERE (external_event_id IS NOT NULL) AND (signature_verified = true)`) ;
  P3C-B **13/191**, suite complète **91/1081**, Pint **95**, `git diff --check` propre, aucune
  base temporaire résiduelle. Migration mergée `000005` inchangée.
- Nettoyage : branches locales `p3c-b-webhooks` (49ad374) et `p3c-b1-webhook-replay-hardening`
  (c772ac1) supprimées (mergées) ; distantes conservées. `origin/main` intact `11130f4`.
- Décisions : aucune nouvelle (D-028.4 déjà consignée : rejeu restreint aux signés).
- Laisse à : **P3C-C `refunds`** (branche dédiée, migration `000007`, exécution séparée).

### 2026-07-15 — Claude Code (durcissement P3C-B.1 — unicité de rejeu)
- Contexte : P3C-B mergé via **PR #8** (`51c4847`, parents `963eef0` + `49ad374`). Review
  adversariale post-merge : l'index `payment_webhook_events_provider_external_event_unique`
  avait le prédicat `WHERE external_event_id IS NOT NULL` (non restreint aux signés). Un
  webhook **non signé** peut porter un `external_event_id` → il réserve `(provider,
  external_event_id)` et **bloque l'événement signé légitime** (poisoning/DoS). KingKouda
  a validé le durcissement.
- Fait : branche `p3c-b1-webhook-replay-hardening` (depuis `51c4847`). Migration **additive**
  `2026_07_14_000006_harden_webhook_external_event_unique.php` (jamais d'édition de `000005`
  mergée) : DROP + recrée l'index en `WHERE external_event_id IS NOT NULL AND
  signature_verified = true` ; `down()` restaure l'ancien. `external_event_id` reste conservé
  sur les invalides pour l'audit ; invalides dédupliqués par `(provider, payload_hash)
  WHERE signature_verified=false`. Test adversarial ajouté (`P3CBWebhooksSchemaTest`) : un
  non signé ne bloque plus un signé ; deux signés restent en conflit. D-028.4 : note factuelle.
- Validation (PostgreSQL réel) : 22 migrations ; index durci confirmé par introspection ;
  P3C-B **13/191**, P3C-A **16/223**, P3B **18/360**, suite complète **91/1081**, Pint **95**,
  `git diff --check` propre, aucune base temporaire résiduelle.
- Laisse à : review + merge de `p3c-b1-webhook-replay-hardening` ; puis P3C-C `refunds`.

### 2026-07-15 — Claude Code (P3C-B payment_webhook_events implémenté)
- Fait : gate **P3C-B** sur branche `p3c-b-webhooks` (depuis la clôture P3C-A `963eef0`).
  Migration `2026_07_14_000005_create_payment_webhook_events_table.php` : table
  `payment_webhook_events` (provider `VARCHAR(32)` canonique, `external_event_id`/`payment_id`
  nullables, `payload_hash VARCHAR(64)`, `filtered_payload JSONB`, `signature_verified`,
  `processing_status` 4 valeurs `received/processed/ignored/failed` — **pas de `duplicate`**,
  dates de cycle + rétention). CHECK stricts (provider/hash regex, payload objet, cohérence
  statut/dates en `CASE…ELSE FALSE END IS TRUE`, signé⇒external_id, forme minimale de
  l'invalide, anti-`CHECK = UNKNOWN`). FK `payment_id` **RESTRICT**. 2 index uniques partiels
  de rejeu : `(provider, external_event_id) WHERE NOT NULL` et `(provider, payload_hash)
  WHERE signature_verified=false`. **3 fonctions / 3 triggers immédiats** :
  `enforce_webhook_event_immutability` (ROW figée + `payment_id`/`event_type`/dates set-once
  + `retention_until` extensible seulement + machine à états received→terminal),
  `validate_webhook_payment_consistency` (BEFORE INSERT/UPDATE OF payment_id : lien ⇒ signé
  + provider = paiement ; inexistence laissée à la FK), `enforce_webhook_event_retention_delete`
  (BEFORE DELETE : autorisé seulement si statut terminal + `retention_until ≤ now`).
  Enum `WebhookProcessingStatus`, modèle `PaymentWebhookEvent` (payload_hash masqué),
  relation `Payment::webhookEvents()`, `PaymentWebhookEventFactory` (états processed/ignored/
  failed/invalidSignatureMinimal/forPayment ; aucun secret, aucun appel réseau).
- Tests : `tests/Feature/P3CBWebhooksSchemaTest.php` (12 tests / 187 assertions), incluant
  rollback isolé par frontière (`PhaseMigrationHarness`, frontière `…000005`, down() du seul
  gate, P3C-A + P3B préservés, aucune migration Refund/P4/P5 appliquée).
- Régression : suite complète **90 tests / 1077 assertions** verte, Pint **94 fichiers**,
  `git diff --check` propre, aucune base temporaire résiduelle. Adaptation : `payment_webhook_events`
  retiré des listes « table interdite » de P1/P2/P3A/P3B/P3C-A (refunds/download_grants/P4/P5
  restent interdits). Le rollback isolé P3C-A reste correct (sa frontière `…000004` exclut `…000005`).
- Décisions : aucune nouvelle (implémentation fidèle à D-028.4/D-028.5 ; note factuelle dans D-028).
- Laisse à : review + merge de `p3c-b-webhooks` ; puis P3C-C `refunds`. Aucun webhook HTTP,
  fournisseur concret, SDK, contrôleur, route, service, job de purge créés.

### 2026-07-15 — Claude Code (clôture post-merge P3C-A)
- Fait : **P3C-A Payments mergé** via [PR #6](https://github.com/mysterus44/DigiTrove/pull/6)
  → merge commit `4a077db6720ee07b304a7746bf7545f6dcf743ec` (2 parents `be1af7f` + `1a792a3`,
  commits intégrés `c45e44a` + `0d04f77` + `1a792a3`). `p0-foundations-laravel13` synchronisé
  fast-forward (`be1af7f..4a077db`).
- Validation post-merge (PostgreSQL réel) : 20 migrations ; rollbacks isolés **2/54** ;
  P3B **18/361** ; P3C-A **16/225** ; suite complète **78/896** ; Pint **89** ; `git diff --check`
  propre. Audit BDD : table `payments` (types uuid/bigint/varchar(32|64|3)/jsonb/timestamptz,
  FK `order_id` RESTRICT), 4 fonctions + 5 triggers P3C-A dont 2 constraint triggers
  `DEFERRABLE INITIALLY DEFERRED`, 3 index uniques partiels. Non-régression P3B : 6 fonctions,
  8 triggers, 4 différés, `orders_coupon_snapshot_consistency_check` intacts. Aucune table
  `payment_webhook_events`/`refunds`/`download_grants`/`licenses`/`events`. `PhaseMigrationHarness`
  opérationnel (frontières exactes, aucun `--step`, nettoyage `finally`, aucune base temporaire
  résiduelle, `digitrove_testing` accessible).
- Nettoyage : branche locale `p3c-a-payments` supprimée (mergée), `origin/p3c-a-payments`
  conservée à `1a792a3` ; `origin/main` intact `1e41b92`. Aucune correction post-merge nécessaire.
- Décisions : aucune nouvelle (D-028 inchangée ; merge consigné). P3C-B/P3C-C non démarrés.
- Laisse à : **plan BDD P3C-B `payment_webhook_events`** (exécution séparée).

### 2026-07-14 — Claude Code (isolation des tests de rollback P3B/P3C-A)
- Contexte : le correctif précédent `0d04f77` (calcul dynamique du `--step`) corrigeait
  le **symptôme** (faux échec dû à un `--step` figé obsolète) mais **pas la cause** :
  la base temporaire exécutait toutes les migrations (`migrate:fresh`) puis rollbackait
  « du gate jusqu'à la fin » — donc le test P3B rollbackait déjà `payments`, et les deux
  tests rollbackeraient les gates P3C-B/C futurs. Isolation de phase **non prouvée**.
- Fait : nouveau helper `tests/Support/PhaseMigrationHarness.php`. Chaque test de rollback
  applique **uniquement** les migrations jusqu'à la frontière du gate via
  `migrate --path=<fichiers>` (aucune migration postérieure exécutée ; refus explicite si
  une migration au-delà de la frontière apparaît) puis rollbacke **uniquement** les
  migrations du gate via `migrate:rollback --path=<fichiers du gate>`. Preuves réelles
  depuis la table `migrations` : liste appliquée (se termine à la frontière), liste des
  `down()` exécutés (exactement le gate), `current_database()` = base temporaire, objets
  antérieurs préservés, nettoyage garanti dans `finally`. **Plus aucun `--step`,
  `migrate:fresh` ni `count - gateIndex`** dans les deux tests.
  - P3B : frontière `…000003_coupon_redemptions` ; down() = orders, order_items,
    coupon_redemptions ; `payments` jamais créée ; P1/P2/P3A préservés.
  - P3C-A : frontière `…000004_payments` ; down() = payments seul ; P3B préservé
    (6 fonctions, 8 triggers, `orders_coupon_snapshot_consistency_check`) ; aucune table
    webhook/refund appliquée.
- Validations : rollbacks isolés 2/54 · suite complète **78 tests / 896 assertions** ·
  Pint **89 fichiers** · `git diff --check` propre · aucune base temporaire résiduelle.
- Périmètre : uniquement 2 tests + 1 helper + docs. **Aucune migration, fonction, trigger,
  modèle, enum, factory ou relation modifié. D-028.2 inchangée.**
- Laisse à : review finale de l'isolation, puis merge de `p3c-a-payments` ; ensuite P3C-B.

### 2026-07-14 — Claude Code (P3C-A Payments implémenté)
- Fait : gate technique **P3C-A** sur branche `p3c-a-payments` (depuis `be1af7f`).
  Migration `2026_07_14_000004_create_payments_table.php` : table `payments` (identité
  `public_id`/`order_id RESTRICT`/`provider VARCHAR(32)` lowercase/`idempotency_key_hash
  VARCHAR(64)`/`attempt_number`, montants `BIGINT` > 0, devise `VARCHAR(3)`, statut
  contraint 7 valeurs, métadonnées fournisseur FILTRÉES, dates de cycle). Contraintes
  nommées, index partiels (`one_succeeded`/`one_requires_review` par commande, réf
  fournisseur), FK RESTRICT. **4 fonctions / 5 triggers** : prevent-delete, immutabilité
  + machine à états, cohérence immédiate montant/devise (free/amount/currency), cohérence
  différée paiement↔commande (constraint triggers DEFERRABLE INITIALLY DEFERRED sur
  `payments` et `orders`). Enum `PaymentStatus`, modèle `Payment` (hash masqué), relation
  `Order::payments()`, `PaymentFactory` (états, hash factices valides, aucun secret).
- Tests : `tests/Feature/P3CAPaymentsSchemaTest.php` (16 tests / 209 assertions),
  incluant rollback isolé de phase (harness de frontière — voir entrée du 2026-07-14
  ci-dessus), transitions, cohérence différée, commande gratuite, unicité,
  immutabilité, concurrence non traitée ici.
- Régression : suite complète verte, `git diff --check` propre. La cohérence
  bidirectionnelle est **imposée par D-028.2** ; seule l'**option d'adaptation des
  fixtures** a été retenue par l'utilisateur (question interactive) : fixtures P3B
  `paid`/`payment_review` reçoivent un paiement cohérent ; `payments` retiré des listes
  « table interdite » de P1/P2/P3A/P3B (webhooks/refunds restent interdits).
  Isolation des rollbacks : voir l'entrée dédiée. Aucun trigger/fonction P3B modifié.
- Décisions : aucune nouvelle (implémentation fidèle à D-028 ; strictness = D-028.2).
- Laisse à : review humaine + merge de `p3c-a-payments` ; puis P3C-B webhooks (branche
  dédiée). Aucun webhook HTTP, fournisseur concret, SDK, contrôleur, route créés.

### 2026-07-14 — Claude Code (plan final P3C Paiements & Remboursements)
- Fait : **finalisation documentaire du plan BDD P3C** après validation humaine des
  choix `1A`–`5A`. Décision **D-028** enregistrée. Réécriture de la section P3C de
  `DigiTrove_Schema_BDD_v1.md` : tables `payments`, `payment_webhook_events`, `refunds`
  (provider canonique `VARCHAR(32)` lowercase, `public_id UUID`, `idempotency_key_hash
  VARCHAR(64)`, `attempt_number`, montants `BIGINT` > 0, devise `VARCHAR(3)`), index
  partiels (`one_succeeded`/`one_requires_review` par commande, réf fournisseur, dédup
  webhook signé et invalide-par-hash), catalogue de triggers **T1–T11** (prevent-delete,
  immutabilité + transitions, cohérence immédiate montant/devise, constraint triggers
  différés paiement↔commande et remboursement↔commande, plafond remboursement IMMÉDIAT
  avec `FOR UPDATE`, garde de rétention webhook), orchestrations serveur et plan de tests.
- Divergences corrigées vs brouillon D-024 : `idempotency_key`→`idempotency_key_hash`,
  ajout `public_id`/`attempt_number`, `ON DELETE CASCADE`→`RESTRICT`, suppression du
  `raw_payload` (métadonnées filtrées), provider `TEXT`→`VARCHAR(32)` lowercase, ajout
  cohérence différée + machine à états, webhook invalide minimal + dédup par hash,
  cumul remboursements par trigger immédiat verrouillant (≠ différé P3B).
- État build/tests : `git diff --check` OK ; **aucun fichier PHP touché** (exécution
  documentaire). Tests non relancés (aucun code modifié ; baseline P3B inchangée :
  19 migrations, 62 tests / 650 assertions).
- Décisions prises (→ DECISIONS_LOG.md) : **D-028** intégrité P3C.
- Laisse à : **implémentation P3C sur une branche dédiée** (nouvelle exécution), après
  ce plan validé. Ne rien implémenter ici.

### 2026-07-14 — Codex (clôture post-merge P3B)
- Fait : [PR #5](https://github.com/mysterus44/DigiTrove/pull/5) confirmée et mergée
  dans `p0-foundations-laravel13` via
  `f07d2258c18e196af608f7df97a5816a7cf578f6` ; commits `b42371b` et `499e2bd`
  intégrés, absents de `origin/main` resté à `1e41b92`.
- Fait : audit PostgreSQL post-merge confirmé : tables `orders`, `order_items`,
  `coupon_redemptions`, six fonctions, huit triggers, quatre constraint triggers
  différés et contrainte coupon durcie contre `CHECK = UNKNOWN`.
- État build/tests : `migrate:fresh` OK (19 migrations), tests P3B OK (18 tests,
  337 assertions), suite complète OK (62 tests, 650 assertions), Pint OK (83 fichiers),
  `git diff --check` OK. Rollback P3B isolé automatisé toujours vert.
- Nettoyage : branche locale `p3b-orders` supprimée ; branche distante conservée.
- Laisse à : plan P3C Paiements/Remboursements uniquement, dans une exécution séparée.
  Aucun code P3C n'est démarré.

### 2026-07-14 — Codex (correctif post-review P3B)
- Fait : correction de la contrainte coupon `orders_coupon_snapshot_consistency_check`
  avec branches strictes et `CASE ... ELSE FALSE END IS TRUE`, afin de refuser les
  expressions PostgreSQL `CHECK = UNKNOWN`.
- Fait : durcissement des tests P3B : helpers SQLSTATE/contrainte/message, scénarios
  coupon NULL et branches percent/fixed, reproductions critiques verrouillées, rollback
  automatisé des trois migrations P3B en base PostgreSQL isolée.
- État build/tests : `migrate:fresh` OK (19 migrations), tests P3B OK (18 tests,
  337 assertions), suite complète OK (62 tests, 650 assertions), Pint OK (83 fichiers),
  `git diff --check` OK.
- Laisse à : review finale de `p3b-orders`, puis PR vers `p0-foundations-laravel13`
  si l'audit est propre. P3C reste strictement interdit.

### 2026-07-14 — Codex (implémentation P3B Commandes)
- Fait : branche `p3b-orders` créée depuis `6f7578e`; trois migrations réversibles
  ajoutées pour `orders`, `order_items` et `coupon_redemptions`, sans artefact P3C.
- Fait : immutabilité et suppression physique bloquées par triggers PostgreSQL,
  agrégats/devises et redemption coupon contrôlés au commit par constraint triggers
  différés, snapshots historiques et nullifications FK testés.
- Fait : modèles, `OrderStatus`, factories et relations P1/P2/P3A minimales ajoutés ;
  aucun service, route, contrôleur, paiement, webhook, checkout ou téléchargement.
- État build/tests : 19 migrations et rollback P3B OK ; tests P3B 17/200 ; suite
  complète 61/513 ; Pint 83 fichiers ; diff-check OK.
- Laisse à : review technique et PR de `p3b-orders` vers
  `p0-foundations-laravel13`. P3C reste interdit avant merge et nouveau plan validé.

### 2026-07-14 — Codex (plan final P3B Commandes)
- Fait : garde-fous Git confirmés sur `p0-foundations-laravel13` à `ba48b5d`, local
  synchronisé `0/0` avec origin, `main` intact et aucun artefact P3B/P3C existant.
- Fait : D-027 et le schéma P3B final consignés : immutabilité PostgreSQL, une ligne
  par produit, HMAC client versionné, consommation après paiement, trois migrations et
  constraint triggers différés. `CLAUDE.md` a été réaligné sur l'état P3A réel.
- État build/tests : `git diff --check` OK ; suite PostgreSQL complète OK (44 tests,
  322 assertions) ; Pint OK (72 fichiers). Aucune migration ni logique P3B/P3C créée.
- Décisions prises (→ DECISIONS_LOG.md) : D-027 ; remises P3 limitées aux coupons,
  `expires_at` immuable et compromis `SET NULL` protégé par triggers + permissions BDD.
- Laisse à : validation humaine du plan P3B final avant toute branche ou migration.

### 2026-07-13 — Codex (clôture post-merge P3A)
- Fait : PR #4 confirmée et mergée dans `p0-foundations-laravel13` via
  `234e3034f0e1ea5e20af9ca359d19d799c140072` ; commits `81f32fc` et `1c0d5a2`
  intégrés, absents de `origin/main` resté à `1e41b92`.
- Fait : base locale synchronisée par fast-forward ; branche locale
  `p3a-coupons-carts` supprimée et branche distante conservée.
- État build/tests : `migrate:fresh` OK (16 migrations et 15 tables applicatives
  P1/P2/P3A), tests P3A OK (15 tests, 147 assertions), suite complète OK
  (44 tests, 322 assertions), Pint OK (72 fichiers), diff-check OK.
- Décisions prises (→ DECISIONS_LOG.md) : D-026, P3A clos ; P3B reste derrière
  un plan d'implémentation validé et P3C demeure non démarré.
- Laisse à : plan P3B Commandes uniquement, sans code avant validation humaine.

### 2026-07-13 — Codex (durcissement tests P3A)
- Fait : ajout de deux tests comportementaux PostgreSQL prouvant les cascades
  `carts` → `cart_items` et `coupons` → règles devise/pivots, sans supprimer les
  produits ni catégories référencés.
- Fait : introspection `information_schema.columns` ajoutée pour verrouiller les
  types physiques `citext`, `uuid` et `varchar(3)` ; aucune migration, modèle,
  factory ou logique métier modifié.
- État build/tests : `migrate:fresh` OK (16 migrations), tests P3A OK (15 tests,
  147 assertions), suite complète OK (44 tests, 322 assertions), Pint et diff-check OK.
- Laisse à : review finale puis PR de P3A vers `p0-foundations-laravel13`.
  P3B/P3C restent non démarrés.

### 2026-07-13 — Codex (P3A Coupons et Paniers)
- Fait : audit de reprise classé `P3A NON DÉMARRÉ`, base propre/synchronisée à
  `2288a63`, puis création de `p3a-coupons-carts`. Implémentation stricte des six
  tables P3A, modèles, enums, factories et tests PostgreSQL ; aucun artefact partiel
  de Claude n'était présent à récupérer.
- État build/tests initial : `migrate:fresh`, tests P3A, suite complète, Pint et
  diff-check étaient verts. Les compteurs actuels sont consignés dans l'entrée
  de durcissement de couverture ci-dessus.
- Décisions prises (→ DECISIONS_LOG.md) : D-025, durcissements P3A (`secret_hash`,
  expiration obligatoire, limites positives, FK produit restrictive, index explicites).
- Laisse à : review/PR de P3A vers `p0-foundations-laravel13`. P3B/P3C interdits tant
  que P3A n'est pas revu et mergé.

### 2026-07-13 — Claude Code (plan P3)
- Fait : décisions humaines P3 consignées (D-024) et **plan BDD P3 Commerce finalisé**.
  Réécriture du BLOC COMMERCE de `DigiTrove_Schema_BDD_v1.md` : argent `BIGINT`, devise
  `VARCHAR(3)` uppercase (fin du `CHAR(3)`), prix panier dynamique (aucun prix dans
  `cart_items`), snapshot étendu dans `order_items`, `coupon_currency_rules` (règle
  par devise), un seul coupon par panier/commande (suppression `is_cumulative`), panier
  invité `public_id` + `SHA-256(secret)`, `payments` avec idempotence + un seul
  `succeeded` par commande, `payment_webhook_events` (anti double-webhook, payload
  allowlisté), `refunds` partiels avec garde cumul, `coupon_redemptions`. Aucune
  migration/modèle/logique P3 créé.
- État build/tests : `git diff --check` OK ; `php artisan test` OK (29 tests,
  173 assertions) ; `./vendor/bin/pint --test` OK (55 fichiers) — inchangés (docs seuls).
- Décisions prises (→ DECISIONS_LOG.md) : D-024, décisions de schéma P3 validées.
- Ouvertes : #5 coupon multi-devises tranché (règle par devise) ; non bloquantes à
  confirmer à l'implémentation (expiration panier 7 j, commande pending 30 min,
  anonymisation invité, paiement tardif `requires_review`).
- Laisse à : **validation humaine du plan BDD P3 complet** avant d'écrire la moindre
  migration. Ne pas démarrer P3 (ni Filament, ni paiement, ni webhook, ni download).

### 2026-07-13 — Claude Code (suite)
- Fait : création du point d'entrée `CLAUDE.md` (miroir court d'`AGENTS.md`, sans
  duplication) après confirmation du push manuel de `bc13f13` sur
  `origin/p0-foundations-laravel13`. Documenté via D-023.
- État build/tests : `git diff --check` OK ; `php artisan test` OK (29 tests,
  173 assertions) ; `./vendor/bin/pint --test` OK (55 fichiers) — inchangés,
  `CLAUDE.md` est purement documentaire.
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-023, `CLAUDE.md` point
  d'entrée Claude Code miroir d'`AGENTS.md`.
- Laisse à : plan BDD P3 Commerce (aucun code), à faire valider par KingKouda.

### 2026-07-13 — Claude Code
- Fait : audit post-merge P2 et clôture. Merge PR #3 confirmé localement
  (`aff4d05 Merge pull request #3 from mysterus44/p2-catalog`, 2 parents
  `9a11791` + `fbaa33a`, commits `d43751d` + `fbaa33a`) ; `origin/main` intact à
  `1e41b92` et P2 absent de `main`. `p0-foundations-laravel13` synchronisé sur
  `origin/p0-foundations-laravel13` (fast-forward, worktree propre, aucun reset/rebase).
  Branche locale `p2-catalog` supprimée (`git branch -d`, merge confirmé) ;
  `origin/p2-catalog` conservée. `main` local réaligné par pointeur seul
  (`git branch -f main origin/main`) sur `1e41b92` — 3 conditions prouvées
  (main non active, `4f48fc8` ancêtre de `origin/p0-foundations-laravel13`,
  `origin/main` toujours `1e41b92`) ; aucun `4f48fc8`/P1/P2 perdu (tous atteignables
  depuis `p0-foundations-laravel13`).
- État build/tests : post-merge P2 via `digitrove-php:dev` + PostgreSQL réel
  `digitrove_testing` (conteneur `digitrove-postgres-1`) —
  `php artisan migrate:fresh --env=testing` OK (10 migrations : 4 P1 + 6 P2),
  inventaire = `users`, `customer_profiles`, `visitors`, `categories`, `products`,
  `product_prices`, `product_files`, `product_category`, `product_bundles`
  (+ `migrations`) ; extension `citext` présente ; aucune table hors périmètre ;
  `php artisan test` OK (29 tests, 173 assertions) ; `./vendor/bin/pint --test` OK
  (55 fichiers) ; `git diff --check` OK.
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-022, P2 clos et mergé ; P3
  Commerce reste derrière un plan BDD validé avant toute migration.
- Note : le SHA `afff4d05` de la consigne humaine est une coquille pour `aff4d05`
  (SHA réel du merge). `CLAUDE.md` est absent de la racine (miroir d'`AGENTS.md` à recréer).
- Laisse à : production du plan BDD P3 Commerce UNIQUEMENT (aucun code), à faire
  valider par KingKouda. Push effectué sur `origin/p0-foundations-laravel13` seul.

### 2026-07-12 — Codex
- Fait : corrections d'audit P2 appliquées sur `p2-catalog` sans élargir le
  périmètre. Ajout des index FK inverses `categories_parent_id_index`,
  `product_category_category_id_index`, `product_bundles_child_product_id_index`,
  officialisation des timestamps catégories et durcissement BDD de
  `product_files.storage_path`.
- État build/tests : `php artisan migrate:fresh --env=testing` OK via PostgreSQL
  réel, `php artisan test` OK (29 tests, 173 assertions),
  `./vendor/bin/pint --test` OK (55 fichiers), `git diff --check` OK.
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-021, index relationnels,
  timestamps catégories et chemins privés durcis.
- Laisse à : nouvel audit P2 après validations finales et push de la correction.

### 2026-07-12 — Codex
- Fait : P2 Catalogue implémenté sur `p2-catalog` conformément à la baseline
  publiée. Ajout strict des migrations, modèles, enums, factories et tests pour
  `categories`, `products`, `product_prices`, `product_files`, `product_category`
  et `product_bundles`.
- État build/tests : `php artisan migrate:fresh --env=testing` OK via PostgreSQL
  réel, `php artisan test` OK (29 tests, 173 assertions),
  `./vendor/bin/pint --test` OK (55 fichiers), `git diff --check` OK.
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-020, P2 reste un socle
  schéma sans exposition métier ; cycles indirects de bundles non exposés et à
  traiter avant toute écriture métier.
- Laisse à : review humaine de `p2-catalog`, puis PR vers
  `p0-foundations-laravel13` si validations finales vertes. Ne pas démarrer P3.

### 2026-07-11 — Codex
- Fait : décisions humaines P2 enregistrées. Le schéma catalogue est révisé :
  prix sortis de `products`, ajout de `product_prices`, prix fixes par devise,
  bundles tarifés comme produits autonomes via `product_prices`, et
  `product_price_history` reportée. Baseline durcie ensuite : la devise catalogue
  P2 est documentée en `VARCHAR(3)` avec contraintes longueur 3 + majuscules.
- État build/tests : `php artisan test` OK via PostgreSQL réel (17 tests,
  57 assertions), `./vendor/bin/pint --test` OK (38 fichiers).
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-018, prix catalogue
  séparés par devise et bundles autonomes ; D-019, devise catalogue en
  `VARCHAR(3)` contraint.
- Laisse à : validation humaine du plan BDD `P2 — Catalogue` révisé, puis aucune
  migration P2 tant que ce plan n'est pas validé.

### 2026-07-11 — Codex
- Fait : audit post-merge P1 confirmé. La PR #2 a été mergée dans
  `p0-foundations-laravel13` via `3f9d132`, `origin/main` reste intact à
  `1e41b92`, la branche locale `p1-identity` a été supprimée et la branche distante
  `origin/p1-identity` est conservée.
- État build/tests : post-merge P1 sur `p0-foundations-laravel13`,
  `php artisan migrate:fresh --env=testing` OK via PostgreSQL réel avec uniquement
  `users`, `customer_profiles`, `visitors`, `php artisan test` OK (17 tests,
  57 assertions), `./vendor/bin/pint --test` OK (38 fichiers).
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-017, P1 est clos après
  merge ; P2 Catalogue nécessite validation humaine du plan BDD avant tout code.
- Laisse à : validation humaine du plan `P2 — Catalogue`, puis implémentation P2
  uniquement si le plan est validé.

### 2026-07-10 — Codex
- Fait : P1 Identité implémenté sur `p1-identity` après validation officielle du
  schéma BDD v1. Ajout strict de l'extension `citext`, des tables `users`,
  `customer_profiles`, `visitors`, des modèles, enums, factories et tests P1.
- État build/tests : `php artisan migrate:fresh --env=testing` OK via PostgreSQL
  réel (`digitrove_testing`), `php artisan test` OK via PostgreSQL réel (17 tests,
  57 assertions), `./vendor/bin/pint --test` OK (38 fichiers).
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-016, P1 se limite au socle
  identité et au futur rattachement optionnel `visitor -> user`; aucun middleware
  avancé ni checkout invité n'est livré en P1.
- Laisse à : review humaine de `p1-identity`, puis PR vers `p0-foundations-laravel13`.

### 2026-07-10 — Codex
- Fait : audit post-merge SITE-00 confirmé. La PR #1 a été mergée dans
  `p0-foundations-laravel13` via `83b6b0c`, `origin/main` ne contient pas SITE-00,
  la branche locale `site-00-static-preview` a été supprimée et la branche distante
  `origin/site-00-static-preview` est conservée.
- État build/tests : post-merge sur `p0-foundations-laravel13`, `npm run build` OK,
  `php artisan test` OK via `digitrove-php:dev` (5 tests, 20 assertions),
  `./vendor/bin/pint --test` OK via `digitrove-php:dev` (26 fichiers).
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : aucune nouvelle décision ;
  confirmation que SITE-00 reste statique et que P1 reste bloqué.
- Laisse à : validation humaine finale de `DigiTrove_Schema_BDD_v1.md`, puis P1
  Identité seulement si KingKouda valide explicitement le schéma.

### 2026-07-09 — Codex
- Fait : SITE-00 vitrine statique de prévisualisation, avec landing/boutique
  mobile-first, catalogue legacy figé, catégories, avis, FAQ, aperçu blog SEO,
  CTA non transactionnels et images marketing copiées dans `public/images/digitrove/`.
- État build/tests : `php artisan test` OK via `digitrove-php:dev` (5 tests,
  20 assertions), `./vendor/bin/pint --test` OK via `digitrove-php:dev`
  (26 fichiers), `npm run build` OK.
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-015, SITE-00 reste une
  prévisualisation statique sans backend métier, sans checkout, sans paiement,
  sans panier et sans téléchargement.
- Laisse à : revue de `site-00-static-preview`, puis validation humaine finale du
  schéma BDD v1. P1 reste bloqué.

### 2026-07-09 — Codex
- Fait : décisions humaines finales pré-P1 documentées : multi-devises, checkout
  invité, compte client suggéré mais non obligatoire, affiliation future avec compte
  obligatoire et tables dédiées hors P1.
- État build/tests : `php artisan test` OK via `digitrove-php:dev` (2 tests,
  2 assertions), `./vendor/bin/pint --test` OK (25 fichiers).
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-014, `currency` obligatoire,
  montants en `BIGINT`, prix fixes par devise recommandés avant P2/P3, achat invité
  via `visitors` + e-mail, affiliation rattachée à `users` sans rôle simple.
- Laisse à : validation humaine finale du schéma corrigé `DigiTrove_Schema_BDD_v1.md`,
  puis P1 Identité strictement limité à `users`, `customer_profiles`, `visitors`
  + extension PostgreSQL `citext`.

### 2026-07-09 — Codex
- Fait : P0.5 assainissement pré-P1, récupération de l'objet Git manquant, isolation
  du legacy sous `legacy/`, correction documentaire du schéma BDD v1.
- État build/tests : `php artisan test` OK via `digitrove-php:dev` (2 tests),
  `./vendor/bin/pint --test` OK (25 fichiers), `php artisan --version` OK
  (Laravel Framework 13.19.0), `git fsck --full` OK sans `missing blob`.
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-013, P0.5 avant P1,
  `citext` avant tables, `users.deleted_at` pour SoftDeletes, `status` sans
  `deleted`, analytics/rollups sans FK intentionnels, ordre futur des migrations.
- Laisse à : validation humaine du schéma corrigé `DigiTrove_Schema_BDD_v1.md`,
  puis P1 Identité strictement limité à `users`, `customer_profiles`, `visitors`.

### 2026-07-09 — Codex
- Fait : finalisation documentaire P0, alignement Laravel 13.19 + Filament 5, branche dédiée.
- État build/tests : `docker compose up -d` OK, `php artisan --version` OK,
  `php artisan test` OK, `./vendor/bin/pint --test` OK.
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-012, abandon de Laravel 11
  bloqué par advisories Composer/Packagist ; adoption Laravel 13.19 + Filament 5 ;
  aucun contournement Composer.
- Laisse à : revue humaine de `p0-foundations-laravel13`, puis P1 après validation
  humaine du schéma BDD.

### 2026-07-09 — Codex
- Fait : P0 fondations Laravel/Filament/PostgreSQL/Redis/Pest/Pint/CI terminé.
- État build/tests : `docker compose up -d` OK, `php artisan --version` OK,
  `php artisan test` OK, `./vendor/bin/pint --test` OK.
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-012, passage à Laravel 13.19
  + Filament 5 pour éviter d'installer Laravel 11 bloqué par advisories Composer.
- Sécurité : `.gitignore` ajouté, `.env` ignoré, `data/users.sqlite` sorti de
  l'index Git, scripts legacy à mots de passe neutralisés.
- Laisse à : P1 — Identité & CRM (`.context/prompts/PRD_01_IDENTITE.md`).

<!-- TEMPLATE à copier en haut à chaque passation :
### AAAA-MM-JJ — [Claude Code | Codex]
- Fait : …
- État build/tests : …
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : …
- Laisse à : … (= réécris le bloc PROCHAINE TÂCHE ci-dessus)
-->
