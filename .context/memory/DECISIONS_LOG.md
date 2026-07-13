# DECISIONS_LOG.md — Log des Décisions Architecturales
# NE JAMAIS RE-DÉCIDER ce qui est ici sans raison valable et sans l'écrire.

---

## FORMAT
```
D-XXX : [Décision]
CONTEXTE : pourquoi il fallait trancher
CHOIX : ce qui a été décidé
ALTERNATIVES REJETÉES : ce qui a été écarté, et pourquoi
IMPACT : ce que ça touche
```

---

## 2026-07 — Décisions fondatrices

### D-001 : Réécriture complète, pas refactor ✅
CONTEXTE : l'ancien DigiTrove est du PHP procédural, données en JSON + SQLite.
CHOIX : réécriture complète sur Laravel. On migre le **contenu** (produits,
articles, avis), on jette l'architecture. La version active est Laravel 13.19
suite à D-012.
ALTERNATIVES REJETÉES : refactor progressif → le code legacy n'a ni couches, ni tests,
ni modèle de données exploitable. Le refactor coûterait plus cher que la réécriture.
IMPACT : tout.

### D-002 : Laravel, pas « architecture type Laravel » ✅
CONTEXTE : la demande initiale disait « PHP, architecture type Laravel ».
CHOIX : du vrai Laravel. La version active est Laravel 13.19 suite à D-012.
RAISON : l'indépendance de DigiTrove vient de l'absence de **plateforme commerciale
tierce** (Shopify, Gumroad), pas de l'absence de framework. « Type Laravel » revient à
réécrire mal ce que Laravel fait bien (routing, ORM, queues, validation, auth).
IMPACT : structure entière.

### D-003 : PostgreSQL 16, pas MySQL ✅
CONTEXTE : besoin annoncé d'analyse poussée et de volume.
CHOIX : PostgreSQL 16.
RAISON : partitionnement natif (table `events`), JSONB indexable en GIN, window
functions, `COUNT(*) FILTER (WHERE …)`, et un chemin de sortie propre vers
ClickHouse/DuckDB à grande échelle. MySQL bloquerait sur chacun de ces points.
IMPACT : toutes les migrations.

### D-004 : Filament, pas Nova ✅
CONTEXTE : besoin d'un back-office CRM/ERP.
CHOIX : Filament. La version active est Filament 5 suite à D-012.
RAISON : gratuit, plus moderne, couvre le besoin. Nova est payant et n'apporte rien ici.
IMPACT : tout le back-office.

### D-005 : Argent en BIGINT (unités mineures), jamais FLOAT ✅
CONTEXTE : XOF (FCFA) n'a pas de centimes.
CHOIX : `BIGINT` en base, `int` ou value object `Money` en PHP.
RAISON : un `float` dérive. Sur une facture, c'est un litige.
IMPACT : products, orders, order_items, payments, wallet, rollups.

### D-006 : Snapshot du prix ET du nom dans `order_items` ✅
CONTEXTE : les prix changent (promos, revalorisations).
CHOIX : `unit_price_minor`, `product_name_snapshot`, `product_type_snapshot` figés à
la commande. Jamais de JOIN sur `products` pour un prix historique.
RAISON : sans ça, une promo appliquée demain réécrit la comptabilité d'hier.
IMPACT : OrderService, factures, rapports.

### D-007 : `events` append-only, partitionnée, SANS clé étrangère ✅
CONTEXTE : besoin d'analytique à fort volume d'écriture.
CHOIX : table `events` partitionnée par mois, `user_id`/`entity_id` en colonnes
« molles » (pas de FK). Le dashboard lit des tables de rollup.
RAISON : une FK vers `orders`/`users` créerait des verrous sur les tables chaudes à
chaque événement. Et purger 2 ans = `DROP PARTITION` (instantané) au lieu d'un
`DELETE` massif qui bloque la base.
IMPACT : AnalyticsService, widgets Filament, jobs de rollup.

### D-008 : Table `visitors` (identité anonyme persistante) ✅
CONTEXTE : besoin d'attribution marketing.
CHOIX : UUID en cookie 1re partie, rattaché à `users.id` au login (stitching).
RAISON : sans elle, un client qui visite 5 fois puis achète semble venu de nulle part.
Aucune attribution rétroactive n'est possible. Le CRM devient décoratif.
IMPACT : middleware de tracking, CRM, attribution, campagnes.

### D-009 : `download_grants` — token haché, jamais le token ✅
CONTEXTE : livraison sécurisée de produits digitaux (cœur du business).
CHOIX : on stocke `token_hash` (SHA-256). Le token clair n'existe que dans l'e-mail
du client. Expiration + quota + révocation vérifiés à chaque requête. Fichiers sur
disque **privé**, jamais dans `public/`.
RAISON : un token en clair en base = un mot de passe en clair. Une fuite SQL exposerait
tout le catalogue.
IMPACT : DownloadService, DownloadController, e-mails, remboursements.

### D-010 : Paiement — confirmation serveur stricte ✅
CONTEXTE : on livre des biens instantanés et irrécupérables.
CHOIX : signature webhook vérifiée (`hash_equals`) + **contre-appel `getStatus`** à
l'agrégateur + montant comparé en entiers + clé d'idempotence unique. Aucune livraison
depuis un retour navigateur.
RAISON : le retour navigateur est falsifiable ; un webhook seul est forgeable ou rejouable.
IMPACT : PaymentGateway, webhooks, Event OrderPaid.

### D-011 : Tests Pest + style Pint, pas de feature sans test ✅
IMPACT : CI, définition de « terminé ».

### D-012 : Laravel 13.19 + Filament 5, aucun contournement Composer ✅
CONTEXTE : P0 prévoyait Laravel 11 + Filament v3, mais Composer/Packagist bloque
`laravel/framework` 11.x à cause d'advisories de sécurité. Le contournement via un
ancien Composer a été refusé par l'auto-review car il installerait volontairement
des dépendances signalées vulnérables.
CHOIX : abandonner Laravel 11 pour P0, adopter Laravel 13.19 comme version sécurisée
et supportée au moment de l'installation, et adopter Filament 5 pour rester cohérent
avec l'écosystème Laravel actuel.
ALTERNATIVES REJETÉES : forcer Composer à ignorer les advisories, utiliser un
Composer plus ancien, ou désactiver la politique Packagist → incompatible avec la
règle sécurité tolérance zéro.
IMPACT : P0, composer.json, CI, documentation projet. Les décisions métier
PostgreSQL, Filament, Argon2id, BIGINT, disque privé, Pest/Pint restent inchangées.

### D-013 : P0.5 — assainissement pré-P1 et schéma corrigé ✅
CONTEXTE : P0 est terminé, mais P1 ne peut pas commencer avec un worktree legacy
polluant l'application Laravel, un schéma racine encore marqué Laravel 11, et un
objet Git manquant sur un asset legacy.
CHOIX : lancer une phase P0.5 dédiée avant P1. Le schéma racine est aligné sur
Laravel 13.19, `citext` est déclaré explicitement avant les colonnes `CITEXT`,
`users.deleted_at TIMESTAMPTZ NULL` devient la stratégie Laravel SoftDeletes, et
`users.status` reste limité aux états business `active`, `suspended`, `blocked`.
Les rollups et tables analytics sans FK sont confirmés comme intentionnels pour
découpler l'analytique des tables transactionnelles chaudes.
ALTERNATIVES REJETÉES : commencer P1 malgré le worktree sale, garder
`status = deleted`, laisser l'ordre futur des migrations implicite, ou supprimer
des fichiers legacy sans validation humaine.
IMPACT : `DigiTrove_Schema_BDD_v1.md`, futur ordre de migrations
(extensions PostgreSQL avant tables, types/enums avant usage, `coupons` avant
`orders`, `orders` avant `order_items`, `licenses` après `order_items`), et
phase P1 strictement limitée à `users`, `customer_profiles`, `visitors` après
validation humaine du schéma corrigé.

### D-014 : Multi-devises, checkout invité et affiliation future ✅
CONTEXTE : avant P1, KingKouda a validé les règles produit structurantes pour
ne pas forcer la création de compte et pour préparer la vente internationale et
l'affiliation sans polluer le modèle identité.
CHOIX : DigiTrove supporte le multi-devises. `currency` reste obligatoire sur les
montants, et les montants restent en `BIGINT` unités mineures. Le choix catalogue
P2 est tranché par D-018 : prix fixes par devise, conversion automatique reportée.
Le compte client n'est pas obligatoire pour acheter :
un visiteur peut acheter en checkout invité via `visitors` + e-mail. Le compte
est fortement suggéré pour l'historique d'achat, les promotions, les annonces,
les avantages CRM et l'accès futur à l'affiliation. L'affiliation exige un compte,
doit être rattachée à un `user`, et ne doit pas être modélisée comme un simple
rôle utilisateur. Elle utilisera plus tard des tables dédiées, par exemple
`affiliate_profiles`, `affiliate_links`, `referrals`, `affiliate_commissions`,
`affiliate_payouts`.
ALTERNATIVES REJETÉES : XOF seul comme contrainte produit définitive, compte
obligatoire pour tout achat, affiliation portée uniquement par `users.role`, ou
implémentation de l'affiliation en P1.
IMPACT : `DigiTrove_Schema_BDD_v1.md`, futures phases P2/P3 pour la stratégie de
prix multi-devises, futures phases CRM/marketing pour l'affiliation. P1 reste
strictement limité à `users`, `customer_profiles`, `visitors` + extension `citext`.

### D-015 : SITE-00 reste une vitrine statique non transactionnelle ✅
CONTEXTE : KingKouda a validé une version limitée `SITE-00 — Vitrine statique de
prévisualisation`, avant validation finale du schéma BDD v1 et avant P1.
CHOIX : livrer uniquement une expérience front-office statique dans Laravel, nourrie
par le contenu marketing legacy figé dans la vue et par des images publiques sûres
copiées sous `public/images/digitrove/`. Tous les CTA restent non transactionnels.
ALTERNATIVES REJETÉES : créer un checkout, un panier, des routes commerce, des
modèles métier, des migrations, lire `legacy/` au runtime, ou exposer un fichier
digital public.
IMPACT : `resources/views/welcome.blade.php`, `resources/css/app.css`, tests HTTP
de garde-fou, assets marketing publics. P1 reste bloqué jusqu'à validation humaine
du schéma BDD v1.

### D-016 : P1 Identité démarre après validation officielle BDD v1 ✅
CONTEXTE : KingKouda a validé officiellement `DigiTrove_Schema_BDD_v1.md` avant
implémentation de P1.
CHOIX : P1 implémente uniquement le socle identité : extension PostgreSQL `citext`,
`users`, `customer_profiles`, `visitors`, enums, modèles, factories et tests
PostgreSQL. `users.email` est en `CITEXT`, `users.password_hash` est compatible
Laravel Auth et Argon2id, `users.deleted_at` porte SoftDeletes, `visitors.user_id`
reste nullable pour préparer un rattachement futur sans forcer le compte.
ALTERNATIVES REJETÉES : ajouter le middleware visitor, le stitching login, un
checkout invité, un seeder admin, des segments CRM avancés, des tables analytics,
ou toute table catalogue/commerce/livraison en P1.
IMPACT : migrations P1, modèles identité, tests PostgreSQL. P2 ne doit pas démarrer
tant que P1 n'est pas reviewé et mergé.

### D-017 : P1 mergé, P2 Catalogue reste derrière un plan BDD validé ✅
CONTEXTE : la PR #2 P1 Identité a été mergée dans `p0-foundations-laravel13` via
`3f9d132`, avec tests PostgreSQL et Pint verts. `main` reste intact à `1e41b92`.
CHOIX : clôturer P1 comme terminé et mergé, puis préparer uniquement le plan BDD
P2 Catalogue avant toute migration ou logique. P2 devra rester limité au catalogue :
`categories`, `products`, `product_files`, pivots/liaisons nécessaires et bundles.
Les fichiers digitaux restent sur disque privé, les montants en `BIGINT` avec
devise explicite, et aucun checkout/paiement/download grant ne doit apparaître.
ALTERNATIVES REJETÉES : démarrer directement les migrations P2, ajouter du checkout,
ajouter des paiements, exposer des fichiers digitaux, créer des grants de
téléchargement, ou viser `main`.
IMPACT : mémoire projet, plan P2 à valider, future implémentation catalogue.

### D-018 : P2 — prix catalogue séparés par devise et bundles autonomes ✅
CONTEXTE : avant P2, KingKouda a validé que DigiTrove doit gérer des prix fixes
par devise et que les bundles ont leur propre prix commercial.
CHOIX : retirer `price_minor`, `compare_at_price_minor` et `currency` de
`products`. Ajouter `product_prices` avec `product_id`, `currency VARCHAR(3)`,
`price_minor BIGINT`, `compare_at_price_minor`, `is_active` et timestamps.
Contrainte unique `(product_id, currency)`, prix non négatifs, prix barré nul ou
supérieur/égal au prix, devise ISO 4217 de longueur 3 en majuscules, index
`(currency, is_active)`.
Un bundle reste un `products.type = 'bundle'` et son prix vit aussi dans
`product_prices`, indépendamment de la somme des enfants.
ALTERNATIVES REJETÉES : prix directement sur `products`, conversion automatique,
taux de change en P2, prix de bundle calculé automatiquement, historique des prix
en P2.
IMPACT : `DigiTrove_Schema_BDD_v1.md`, futur plan P2 Catalogue, futures migrations
`products`, `product_prices`, `product_bundles`. `product_price_history`,
conversion automatique, promotions avancées, checkout, commandes, paiements et
download grants sont reportés.

### D-019 : P2 — devise catalogue en `VARCHAR(3)` contraint ✅
CONTEXTE : la baseline P2 documentait initialement `product_prices.currency` en
`CHAR(3)`, ce qui peut introduire des ambiguïtés de padding en PostgreSQL.
CHOIX : utiliser `VARCHAR(3)` pour `product_prices.currency`, avec contrainte
stricte `char_length(currency) = 3` et `currency = upper(currency)`. Le code
attendu reste ISO 4217, et les montants restent en `BIGINT`.
ALTERNATIVES REJETÉES : conserver `CHAR(3)`, ou utiliser `TEXT` sans contrainte
de longueur.
IMPACT : documentation BDD P2 et futures migrations catalogue. Aucun `FLOAT`,
`REAL`, `DOUBLE PRECISION` ou `DECIMAL` ne doit être utilisé pour l'argent.

### D-020 : P2 Catalogue reste un socle schéma sans exposition métier ✅
CONTEXTE : P2 implémente le catalogue après validation de la baseline, mais ne doit
pas ouvrir d'écriture métier tant que les cycles indirects de bundles ne sont pas
traités côté service/tests.
CHOIX : P2 ajoute uniquement les migrations, modèles, enums, factories et tests
pour `categories`, `products`, `product_prices`, `product_files`,
`product_category` et `product_bundles`. Aucun import legacy, aucun Filament/admin,
aucune route/API, aucun upload réel, aucun checkout/paiement/livraison.
ALTERNATIVES REJETÉES : exposer une création de bundle avant la détection des
cycles indirects, importer le catalogue legacy dans la même phase, ou copier des
fichiers digitaux dans `public/`.
IMPACT : branche `p2-catalog`, tests PostgreSQL catalogue, garde-fous sécurité.

### D-021 : P2 — index relationnels, timestamps catégories et chemins privés durcis ✅
CONTEXTE : l'audit P2 a demandé de supprimer les ambiguïtés restantes avant PR :
PostgreSQL n'indexe pas automatiquement les FK, les timestamps des catégories
doivent être officiels, et les chemins de fichiers doivent refuser les variantes
Windows/Unix dangereuses au niveau BDD.
CHOIX : conserver officiellement `categories.created_at` et `categories.updated_at`.
Ajouter les index `categories_parent_id_index`,
`product_category_category_id_index` et `product_bundles_child_product_id_index`.
Durcir `product_files.storage_path` pour accepter uniquement un chemin relatif
privé non vide, sans URL, chemin absolu Unix/Windows, segment `public` ni
traversée `..`.
ALTERNATIVES REJETÉES : s'appuyer seulement sur les clés primaires composites,
laisser les timestamps catégories implicites, ou repousser la sécurité
`storage_path` à une validation Laravel.
IMPACT : migrations P2 existantes, tests PostgreSQL catalogue, documentation BDD.

### D-022 : P2 Catalogue mergé, P3 Commerce reste derrière un plan BDD validé ✅
CONTEXTE : la PR #3 P2 Catalogue a été mergée dans `p0-foundations-laravel13` via
`aff4d05 Merge pull request #3 from mysterus44/p2-catalog` (commits `d43751d` +
`fbaa33a`, base `9a11791`), avec CI verte côté GitHub et audit post-merge local
vert (migrate:fresh 10 migrations, 29 tests / 173 assertions, Pint 55 fichiers,
`git diff --check` propre, `citext` présent, inventaire strictement P1 + P2).
`origin/main` reste intact à `1e41b92` et ne contient pas P2.
CHOIX : clôturer P2 comme terminé et mergé. Nettoyage contrôlé : branche locale
`p2-catalog` supprimée après confirmation du merge, branche distante
`origin/p2-catalog` conservée, `main` local réaligné sur `origin/main` par pointeur
seul (`git branch -f main origin/main`) sans reset/rebase/force-push et sans jamais
pousser `main`. Étape suivante : produire UNIQUEMENT le plan BDD P3 Commerce
(`carts`, `cart_items`, `coupons`, `orders`, `order_items`, `payments`, `refunds`
+ pivots strictement nécessaires) à faire valider avant toute migration.
ALTERNATIVES REJETÉES : démarrer les migrations/modèles P3, ajouter checkout,
paiements, webhooks, un fournisseur de paiement, des téléchargements ou des
`download_grants`, supprimer la branche distante `p2-catalog`, ou pousser sur `main`.
IMPACT : mémoire projet, `HANDOFF.md`, futur plan P3 à valider. Invariants imposés
au plan P3 : argent en `BIGINT`, devise `VARCHAR(3)` majuscule (jamais FLOAT / REAL /
DOUBLE / DECIMAL / NUMERIC), snapshot obligatoire dans `order_items` (nom, type,
prix unitaire, devise, quantité, total ligne), commande indépendante du prix
catalogue courant, checkout invité (`user_id` nullable + `visitor` + e-mail),
statuts contraints sur commandes/paiements/remboursements, idempotence
paiements/webhooks, montant et devise revérifiés serveur, remboursement partiel
supportable, jamais de livraison sur retour navigateur, aucun `download_grant` en P3.

### D-023 : `CLAUDE.md` — point d'entrée Claude Code miroir d'`AGENTS.md` ✅
CONTEXTE : `AGENTS.md` désigne `CLAUDE.md` comme le miroir/point d'entrée de Claude
Code, mais le fichier était absent de la racine. Sans lui, la session Claude Code
n'a pas de point d'ancrage explicite équivalent à celui de Codex.
CHOIX : créer un `CLAUDE.md` court et économe en tokens, qui (1) identifie DigiTrove
et le rôle ARIA-DEV, (2) impose l'ordre de lecture prioritaire (`HANDOFF.md`,
`PROJECT_CONTEXT.md`, `DigiTrove_Schema_BDD_v1.md`, `.context/CO_CODING_PROTOCOL.md`,
`PROGRESS_TRACKER.md`, `DECISIONS_LOG.md`), (3) rappelle les garde-fous
non négociables (plan avant code, une feature à la fois, BDD avant logique, aucun
secret, aucun push sur `main`, tests PostgreSQL réels, arrêt sur validation humaine),
(4) renvoie à `AGENTS.md` pour les règles détaillées sans les dupliquer, (5) résume
l'état (P0/SITE-00/P1/P2 faits, P3 non démarré).
ALTERNATIVES REJETÉES : dupliquer tout `AGENTS.md` dans `CLAUDE.md` (risque de
divergence entre les deux points d'entrée du même développeur), ou laisser
`CLAUDE.md` absent.
IMPACT : continuité du co-codage Codex ⇄ Claude Code. Aucun impact code/BDD :
`CLAUDE.md` est de la documentation, `AGENTS.md` reste la source unique des règles.

---

## 🔶 EN ATTENTE DE VALIDATION PAR KINGKOUDA

- **Le schéma `DigiTrove_Schema_BDD_v1.md` dans son ensemble** (v1) : validé
  officiellement par KingKouda avant P1.
- **Le champ `usb` du legacy** : les produits avaient un champ `usb`. Livraison
  physique sur clé USB ? Si oui, il faut un modèle de commande hybride
  (digital + physique) avec adresse de livraison. À trancher. Voir AUDIT_LEGACY.md.
- **Prix multi-devises avant P2/P3** : prix fixes par devise validés pour P2.
  Conversion automatique et taux de change reportés.
- **P2 Catalogue** : ✅ mergé dans `p0-foundations-laravel13` via PR #3 (`aff4d05`).
  Clos (voir D-022).
- **P3 Commerce** : en attente du plan BDD P3 (aucun code tant que le plan n'est pas
  validé par KingKouda).

---

## À AJOUTER AU FIL DU PROJET
[Chaque nouvelle décision importante vient ici, datée.]
