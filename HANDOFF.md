# HANDOFF.md — Carnet de Passation (Claude Code ⇄ Codex)
# 🔴 LE FICHIER LE PLUS IMPORTANT DU CO-CODAGE.
# Quel que soit l'agent, il LIT ceci en premier et le MET À JOUR en dernier.

---

## 📍 ÉTAT ACTUEL

- **Dernier agent** : Codex
- **Date** : 2026-07-10
- **Branche git** : `p1-identity`
- **Commit fondations local** : `4f48fc8 feat: bootstrap Laravel foundations [par Codex]`
- **Merge SITE-00** : `83b6b0c Merge pull request #1 from mysterus44/site-00-static-preview`
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
- **P1 Identité implémenté sur branche de review `p1-identity`** :
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

---

## ⏭️ PROCHAINE TÂCHE

**Action recommandée immédiate : review humaine de la branche `p1-identity`, puis
Pull Request vers `p0-foundations-laravel13` si les garde-fous P1 sont acceptés.**

Après merge de P1 seulement, préparer P2 Catalogue. Ne pas démarrer P2 tant que
P1 n'est pas reviewé et mergé.

Résumé P1 : créer `users`, `customer_profiles`, `visitors`, les modèles/casts/enums,
le middleware `visitor_id`, le stitching visitor → user, et le seeder admin qui lit
`ADMIN_EMAIL` / `ADMIN_PASSWORD` depuis `.env`.

Critère de fin P1 : migrations PostgreSQL vertes, extension `citext`, tests Argon2id,
relations, cookie visiteur, stitching, et aucun mot de passe en dur.

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
  `BIGINT` unités mineures. Avant P2/P3, trancher prix fixes par devise
  (recommandé) ou conversion automatique.
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
