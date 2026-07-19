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
  `updated_at` G2 lié au cycle de vie) MERGÉ (PR #14 → `2c25e2a`)** → P4-B
  `download_logs` (`000012`) ; `licenses` exclu de P4. Une migration, une branche,
  une frontière de rollback par gate ; migration N+1 jamais créée avant merge du
  gate N. Détail dans le bloc P4 de `DigiTrove_Schema_BDD_v1.md`.

P4-A2.1 est **terminé et mergé** via
[PR #14](https://github.com/mysterus44/DigiTrove/pull/14), merge `2c25e2a` : le
hotfix remplace uniquement G2/G3, garde 4 fonctions / 5 triggers et préserve
`000010` immuable. Validation post-merge : 27 migrations ; P4-A2.1 7/113 ; suite
158/2301 ; Pint 112 ; rollback isolé `000011` restaurant exactement G2/G3 d'origine.
Le **plan P4-B — Download Logs** (branche `p4-b-download-logs`,
migration `000012`, frontière `000012`) est désormais **FINALISÉ — NON IMPLÉMENTÉ**
par D-029.5. Décisions : consommation à `started` atomique log+compteur, rétention
NOT NULL explicite, HMAC IP versionné, secret de tentative dédié (digest seulement,
expiration courte), une unité pour Range/retries de la même tentative, HEAD sans
log/quota, et `completed` = remise au mécanisme, jamais réception client. G5 ajoute
2 fonctions/2 triggers P4-B avec G6 et remplace G2 en place ; rollback `000012`
fail-closed et restauration exacte de G2 `000011`. Prochaine étape : **implémenter
P4-B sur la branche réservée dans une nouvelle exécution**. Branche, migration et
code P4-B sont encore absents ; P5 non démarré.

## 🔄 EN FIN DE TÂCHE

Mettre à jour `PROGRESS_TRACKER.md` + `HANDOFF.md` (journal + PROCHAINE TÂCHE), logger
toute décision dans `DECISIONS_LOG.md`, vérifier tests/pint, commit clair
`feat|fix|docs|chore: <résumé> [par Claude Code]`, puis `git push` (jamais `main`).
