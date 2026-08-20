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

### D-024 : P3 Commerce — décisions de schéma validées (plan, avant migration) ✅
CONTEXTE : avant d'écrire la moindre migration P3, KingKouda a tranché les points
structurants du schéma Commerce. Le plan BDD P3 est finalisé sur ces bases ; aucune
migration/logique n'est écrite tant que le plan complet n'est pas validé.
CHOIX :
1. **Prix du panier** : recalcul dynamique jusqu'au passage en commande. AUCUN prix,
   remise ou devise stocké dans `cart_items`. Snapshot définitif UNIQUEMENT dans
   `order_items` (prix unitaire, sous-total, remise, total, devise, nom, slug, type).
2. **Coupon fixe multi-devises** : aucune conversion automatique (cohérent D-018).
   Une règle indépendante par devise via `coupon_currency_rules` (remplace le nom
   `coupon_amounts`) : `fixed_amount_minor`, `min_order_minor`, `max_discount_minor`,
   `UNIQUE (coupon_id, currency)`, devise `VARCHAR(3)` uppercase, montants `>= 0`.
3. **Suppression produit** : `order_items.product_id` nullable, `ON DELETE SET NULL`,
   snapshots conservés définitivement → la commande reste lisible même produit
   renommé/archivé/supprimé. La suppression physique reste évitée (SoftDeletes P2).
4. **Quantité digitale** : entier `>= 1` autorisé (prépare ventes multi-licences/unités).
5. **Panier invité** : `carts.public_id UUID` opaque + secret aléatoire cryptographique
   transmis UNIQUEMENT dans un cookie sécurisé (HttpOnly, SameSite, signé/chiffré) ;
   en base seul `SHA-256(secret)` (`carts.secret_hash`), jamais le secret brut, jamais
   un token complet dans les logs.
6. **Coupons** : un seul coupon MAX par panier (`carts.coupon_id`) et par commande
   (`orders.coupon_id` + snapshot code/type/montant), aucun cumul ; la notion
   `is_cumulative` est supprimée. Consommation tracée dans `coupon_redemptions`
   (`UNIQUE (order_id)`).
HARDENING BDD associé : argent en `BIGINT`, devise `VARCHAR(3)` uppercase partout
(correction de `CHAR(3)` du schéma v1) ; `payments` avec `idempotency_key UNIQUE`,
`UNIQUE (provider, provider_ref) WHERE provider_ref IS NOT NULL`, et
`UNIQUE (order_id) WHERE status='succeeded'` (un seul encaissement final) ;
`payment_webhook_events UNIQUE (provider, external_event_id)` (anti double-webhook,
payload allowlisté, aucun secret/PAN/token) ; `orders.status` sépare l'échec de
tentative (dans `payments.status`) de l'état commande (`pending`/`payment_review`/...).
NON exprimable par CHECK mono-ligne (→ service + trigger transactionnel + tests de
concurrence) : existence d'une règle devise pour un coupon `fixed`, cumul
`SUM(refunds succeeded) <= payment.amount_minor`, plafonds coupon global/par client.
ALTERNATIVES REJETÉES : prix réservé au panier, conversion automatique de devises en
P3, blocage dur de suppression produit, quantité forcée à 1, token invité en clair en
base, cumul de coupons / `is_cumulative`, argent en `CHAR(3)`/FLOAT/DECIMAL.
IMPACT : `DigiTrove_Schema_BDD_v1.md` (BLOC COMMERCE réécrit), futures migrations P3.
Décisions non bloquantes (recommandations, non figées) : expiration panier invité 7 j,
commande `pending` 30 min, anonymisation partielle des données invité après durée
légale à définir, paiement tardif => `requires_review` (traitement manuel), suppression
physique commandes/paiements interdite hors politique légale dédiée.

### D-025 : P3A Coupons et Paniers — durcissements d'implémentation ✅
CONTEXTE : KingKouda a validé l'implémentation de la première tranche P3, strictement
limitée à `coupons`, `coupon_currency_rules`, `coupon_products`,
`coupon_categories`, `carts` et `cart_items`. La consigne P3A précise plusieurs
invariants plus stricts que le pseudo-SQL initial de D-024.
CHOIX : les limites coupon globales/par client sont `NULL` ou strictement positives ;
`carts.secret_hash` est obligatoire, unique et contraint à 64 caractères hexadécimaux
minuscules ; `carts.expires_at` est obligatoire sans figer en BDD une durée métier ;
les FK identité/coupon du panier utilisent `ON DELETE SET NULL` ; la suppression
physique d'un produit présent dans `cart_items` est refusée (`ON DELETE RESTRICT`) ;
les index inverses des pivots et les index de cycle de vie panier sont explicites.
Les montants coupon sont uniquement des `BIGINT` dans `coupon_currency_rules`, avec
une devise `VARCHAR(3)` uppercase. `cart_items` ne contient aucun prix, devise,
remise, sous-total, total ou snapshot.
LIMITES ASSUMÉES : l'existence d'une règle devise pour un coupon fixe, l'éligibilité
produit/catégorie et les plafonds de consommation restent des cohérences
transactionnelles de la future logique métier. P3A ne fournit ni moteur coupon, ni
cookie panier, ni checkout, ni commande, ni paiement, ni webhook, ni livraison.
IMPACT : six migrations P3A, modèles/enums/factories associés, tests PostgreSQL et
mémoire projet. P3B/P3C restent bloqués jusqu'à review et merge de P3A.

### D-026 : P3A mergé, P3B reste derrière un plan validé ✅
CONTEXTE : la PR #4 `p3a-coupons-carts` a été mergée dans
`p0-foundations-laravel13` via `234e3034f0e1ea5e20af9ca359d19d799c140072`
(commits `81f32fc` + `1c0d5a2`, base `2288a63`). L'audit post-merge confirme
16 migrations, uniquement les tables P1/P2/P3A, 44 tests / 322 assertions et
Pint vert sur 72 fichiers. `origin/main` reste intact à `1e41b92`.
CHOIX : clôturer P3A comme mergé et validé. Supprimer uniquement la branche locale
`p3a-coupons-carts`, conserver `origin/p3a-coupons-carts`, puis préparer comme
prochaine étape le plan d'implémentation de P3B Commandes uniquement. Aucune
migration, modèle ou logique P3B ne démarre avant validation humaine de ce plan ;
P3C Paiements reste non démarré.
ALTERNATIVES REJETÉES : démarrer immédiatement `orders`/`order_items`, préparer les
paiements ou webhooks, supprimer la branche distante P3A, ou toucher à `main`.
IMPACT : mémoire projet et gate P3B. P3A fournit uniquement le socle coupons/paniers ;
aucun checkout, commande, paiement, webhook ou téléchargement n'est encore livré.

### D-027 : Immutabilité et consommation des commandes ✅
CONTEXTE : KingKouda a validé les choix finaux `1A`, `2A` et `3A` avant toute
migration P3B. Le plan Commandes doit préserver l'historique commercial même face à
une suppression ou une écriture SQL accidentelle, sans anticiper P3C Paiements.
CHOIX :
1. **Commandes immuables en PostgreSQL** : tout `DELETE` de `orders` est refusé par
   trigger. Après insertion, seules les colonnes de cycle de vie `status`, `paid_at`,
   `cancelled_at` et `updated_at` peuvent évoluer ; `expires_at`, identités publiques,
   idempotence, snapshots client/coupon, montants, devise, attribution, `placed_at` et
   `created_at` restent figés. Les transitions de statut restent à valider par le futur
   service, sans machine à états SQL excessivement rigide.
2. **Nullification référentielle contrôlée** : les FK `cart_id`, `user_id`,
   `visitor_id` et `coupon_id` restent `ON DELETE SET NULL`. Le trigger autorise
   uniquement leur transition non-NULL → NULL sans altérer un snapshot ou montant.
   Cette exception permet aussi techniquement une nullification SQL directe ; les
   permissions BDD minimales, l'absence d'API de mutation et les SoftDeletes ou la
   désactivation normale complètent la protection.
3. **Lignes immuables** : tout `DELETE` de `order_items` est refusé et toute mise à
   jour commerciale est interdite. Seule l'exception `product_id` non-NULL → NULL,
   sans aucun autre changement, `updated_at` inclus, rend `ON DELETE SET NULL` compatible avec
   les suppressions physiques exceptionnelles ; les produits sont normalement
   SoftDeleted. `order_id` utilise `ON DELETE RESTRICT`.
4. **Une ligne par produit et commande** : `quantity` porte plusieurs unités/licences.
   Un index unique partiel `(order_id, product_id) WHERE product_id IS NOT NULL` évite
   les doublons sans empêcher plusieurs lignes historiques NULL issues de produits
   distincts supprimés.
5. **Coupon consommé après paiement uniquement** : aucune réservation de quota pour
   une commande `pending` et aucune insertion P3B dans `coupon_redemptions`. P3C
   insérera la consommation uniquement après confirmation serveur du paiement, jamais
   depuis un retour navigateur, sous verrou transactionnel du coupon. `UNIQUE(order_id)`
   empêchera la double consommation d'une commande.
6. **Identité client versionnée** : `customer_key_hash` est un HMAC-SHA-256 de
   `email:v1:<email normalisé>`, avec secret dédié hors BDD et logs.
   `customer_key_version SMALLINT NOT NULL DEFAULT 1 CHECK (> 0)` accompagne le hash ;
   l'index de plafond inclut `(coupon_id, customer_key_version, customer_key_hash)`.
   Une rotation devra conserver les anciennes clés ou recalculer toutes les versions
   actives avant P3C afin de ne pas réinitialiser implicitement les plafonds client.
7. **Remises P3 limitées aux coupons** : `discount_minor` reste la seule remise
   appliquée et vaut zéro en l'absence de snapshots coupon. Promotions automatiques et
   remises manuelles sont reportées ; leur arrivée exigera une décision et une migration
   explicites plutôt qu'un `discount_source` prématuré.
8. **Cohérence comptable différée** : des constraint triggers PostgreSQL différés
   valident au commit la présence des lignes, leur devise et leurs sommes, puis la
   cohérence commande/consommation. Les tests forcent `SET CONSTRAINTS ALL IMMEDIATE`.
ALTERNATIVES REJETÉES : protections Laravel seules, `ON DELETE CASCADE` sur l'historique,
unicité simple des lignes, réservation de coupon pendant `pending`, email ou secret HMAC
stocké en clair, hash non versionné, remises génériques non modélisées, suppression des
FK historiques ou triggers comptables immédiats empêchant la création transactionnelle.
IMPACT : migrations P3B `orders`, `order_items`, `coupon_redemptions`, triggers et
tests PostgreSQL associés. P3B a été mergé via la
[PR #5](https://github.com/mysterus44/DigiTrove/pull/5) dans
`p0-foundations-laravel13` par `f07d2258c18e196af608f7df97a5816a7cf578f6`, puis
validé post-merge : 19 migrations, 62 tests / 650 assertions, six fonctions, huit
triggers dont quatre différés, rollback isolé automatisé et contrainte coupon durcie
contre `CHECK = UNKNOWN`. P3C reste non démarré et soumis à un plan séparé validé.

### D-028 : Intégrité P3C Paiements, Webhooks et Remboursements ✅
CONTEXTE : KingKouda a validé les choix `1A`–`5A` du plan P3C avant toute migration.
P3C ne crée que trois tables (`payments`, `payment_webhook_events`, `refunds`) ;
`coupon_redemptions` (P3B) est seulement alimentée. Argent `BIGINT`, devise `VARCHAR(3)`
uppercase, provider canonique, hash SHA-256, aucune ligne de paiement pour une commande
gratuite, aucune validation sur retour navigateur. Plan documenté dans
`DigiTrove_Schema_BDD_v1.md` (bloc P3C : tables + catalogue de triggers T1–T11).
CHOIX :
1. **Fournisseur canonique (`1A`)** : `provider VARCHAR(32)` identique dans les trois
   tables, minuscules, sans espace, indépendant du nom commercial, `CHECK ~
   '^[a-z0-9][a-z0-9_-]{0,31}$'`. La normalisation empêche le contournement des
   contraintes uniques par variation de casse. `refunds.provider` = `payments.provider`
   et `payment_webhook_events.provider` = `payments.provider` (si `payment_id`) garantis
   par trigger.
2. **Cohérence paiement ↔ commande (`2A`)** : garantie au commit par une fonction
   `validate_payment_order_consistency()` sur constraint triggers `DEFERRABLE INITIALLY
   DEFERRED` (montés sur `payments` et `orders`). Règles : un `succeeded` ⇒ commande
   `paid|partially_refunded|refunded` ; une commande non gratuite dans ces états ⇒
   exactement un `succeeded` ; commande `total_minor = 0` ⇒ aucune ligne `payments`,
   jamais de `succeeded`, `paid` atteint par un flux gratuit distinct ; `requires_review`
   ⇔ `payment_review` (exactement un). Index partiels : `UNIQUE(order_id) WHERE
   status='succeeded'` ET `UNIQUE(order_id) WHERE status='requires_review'`. Revalidé par
   le service (montant/devise) et par un trigger immédiat à l'INSERT (`= orders.total_minor`,
   `= orders.currency`, `orders.total_minor > 0`).
3. **Cohérence remboursements ↔ commande (`3A`)** : au commit, pour l'unique paiement
   capturé : somme des refunds `succeeded` `= 0` ⇒ `paid` ; `0 < somme < capture` ⇒
   `partially_refunded` ; `= capture` ⇒ `refunded`. Constraint triggers différés distincts
   du trigger immédiat de plafond. Le `RefundService` met à jour `orders.status` ; le
   trigger vérifie et refuse, sans jamais muter.
4. **Webhook à signature invalide (`4A`)** : conservé sous forme minimale — provider,
   `external_event_id` si disponible, `payload_hash` SHA-256, `signature_verified=false`,
   `processing_status='failed'`, `received_at`, `failed_at`, erreur générique sanitizée.
   Interdits : `filtered_payload`, signature brute, secret, token, données bancaires,
   `payment_id` déduit d'un contenu non fiable. `external_event_id` nullable ; signé valide
   ⇒ non NULL. Index partiels : `UNIQUE(provider, external_event_id)` pour les événements
   signés avec identifiant non NULL ;
   `UNIQUE(provider, payload_hash) WHERE signature_verified=false`. **Pas de statut
   `duplicate`** : un rejeu retrouve la ligne existante et répond de façon idempotente.
5. **Transitions minimales (`5A`)** : protégées par triggers. Paiement :
   `pending→processing|requires_review|failed|cancelled|expired` ;
   `processing→succeeded|requires_review|failed|cancelled|expired` ;
   `failed|cancelled|expired→requires_review` (confirmation fournisseur tardive) ;
   `requires_review→succeeded|failed|cancelled|expired` ; `succeeded` terminal ; interdits
   `failed|cancelled|expired→succeeded` et `succeeded→autre`. Remboursement :
   `pending→processing|failed|cancelled` ; `processing→succeeded|failed|cancelled` ;
   terminaux `succeeded|failed|cancelled`. Webhook : `received→processed|ignored|failed`,
   terminaux non réactivables.
RECOMMANDATIONS RETENUES : aucun statut `refunded` dans `payments.status` (état dérivé
des lignes `refunds` réussies) ; un seul `succeeded` par commande ; immutabilité hybride
BDD + service ; montant/devise paiement↔commande garantis en BDD et revalidés service ;
cumul remboursements protégé par trigger IMMÉDIAT `enforce_refund_cumulative_cap()` avec
`SELECT … payments … FOR UPDATE` (excluant la ligne courante via `id <> NEW.id`), ≠ des
triggers différés P3B ; webhook valide = `payload_hash` + `filtered_payload` allowlisté ;
rétention configurable (recommandée 90 j) via `retention_until` + fonction de suppression
contrôlée (uniquement après rétention et statut terminal, job non implémenté en P3C) ;
paiement tardif → revue manuelle. Ordre de verrouillage global :
`orders → payments → coupons → refunds/agrégats` (anti-deadlock).
ALTERNATIVES REJETÉES : `payments.status='refunded'` (double source de vérité) ; cumul
remboursements par trigger différé (course inter-transactions) ou par service seul ;
statut webhook `duplicate` ; `external_event_id` obligatoire pour les invalides ;
`prevent-delete` total sur `payment_webhook_events` (empêcherait la purge légale) ;
provider en casse libre ; triggers qui mutent au lieu de refuser.
IMPACT : futures migrations P3C `create_payments_table`,
`create_payment_webhook_events_table`, `create_refunds_table`, fonctions/triggers T1–T11
et tests PostgreSQL. **P3C-A `create_payments_table` implémenté et mergé** via PR #6
(`4a077db`, commit final audité `1a792a3`) conformément à cette décision : 4 fonctions,
5 triggers (dont 2 constraint triggers différés), 3 index uniques partiels, FK RESTRICT.
**P3C-B `create_payment_webhook_events_table` implémenté** (branche `p3c-b-webhooks`)
conformément à D-028.4/D-028.5 : 3 fonctions / 3 triggers immédiats (immutabilité +
transitions received→terminal, cohérence webhook↔paiement signé/même provider, suppression
contrôlée par rétention), 2 index uniques partiels de rejeu, pas de statut `duplicate`,
webhook invalide en forme minimale. P3C-B mergé via PR #8 (`51c4847`).
**Durcissement D-028.4 (validé KingKouda, review post-merge)** : l'unicité de rejeu
`(provider, external_event_id)` est désormais **restreinte aux événements signés**
(`WHERE external_event_id IS NOT NULL AND signature_verified = true`). Motif : un
`external_event_id` provenant d'un webhook **non signé** est contrôlé par l'attaquant et
ne doit pas réserver ce créneau ni bloquer un événement signé légitime (poisoning/DoS).
`external_event_id` reste conservé sur les invalides pour l'audit ; les invalides
restent dédupliqués par `(provider, payload_hash) WHERE signature_verified = false`.
Correctif = migration additive `2026_07_14_000006_harden_webhook_external_event_unique`
(jamais d'édition de la migration mergée), **mergé via PR #9 (`13932ac`)**. P3C-B mergé
via PR #8 (`51c4847`). **P3C-C `refunds` est implémenté et mergé** via
[PR #10](https://github.com/mysterus44/DigiTrove/pull/10), merge
`122332aa5cc9fc25e9bf1898224f5a1da30f6446` (parents `be74854` + `1270c53`), dans
l'unique migration `000007` : enum `pending|processing|succeeded|failed|cancelled`, cinq
fonctions, six triggers dont deux constraint triggers différés, plafond immédiat sous
verrou Payment `FOR UPDATE`, rollback isolé et concurrence réelle testée. L'audit
post-merge confirme la nullification de `initiated_by_user_id` réservée à l'action FK
imbriquée `ON DELETE SET NULL`, le refus de la mutation par UPDATE direct et les courses
par INSERT ou transition simultanée vers `succeeded`. Aucun changement de décision D-028,
aucun remboursement HTTP ni fournisseur réel. P4/P5 restent non démarrés.

### D-029 : P4 — Delivery & Download Integrity (plan finalisé, avant migration) ✅
CONTEXTE : P3 Commerce est intégralement mergé (dernier merge P3C-C `122332a`).
Le cœur sécurité du produit — livraison automatisée des fichiers digitaux — doit
être planifié en BDD avant toute migration. Le contrat est intégralement dérivable
des décisions existantes : D-009 (token haché, expiration/quota/révocation),
D-010 (confirmation serveur stricte, jamais de livraison sur retour navigateur),
D-014 (checkout invité), D-028.2/3 (statuts commande livrables, commande gratuite
`paid` sans ligne payments, matrice refunds↔order), bloc P4 de référence du schéma
v1 et `SECURITE_TELECHARGEMENT.md`. Aucune décision humaine nouvelle n'est requise.
CHOIX :
1. **Découpage minimal** : P4-A `download_grants` (migration `2026_07_14_000008`)
   puis P4-B `download_logs` (migration `2026_07_14_000009`), chacun sur branche
   dédiée avec rollback isolé par frontière (`PhaseMigrationHarness`). **`licenses`
   est EXCLU de P4** : la table est marquée « optionnel selon catalogue » depuis v1 ;
   aucune preuve d'un besoin MVP — décision produit à trancher séparément avant
   toute phase licences. Aucune logique applicative (listener, service, contrôleur,
   e-mail, purge) dans ces gates : BDD avant logique.
2. **Unité du grant** : `order_item × product_file` (D-009). Un index unique
   PARTIEL `(order_item_id, product_file_id) WHERE revoked_at IS NULL` garantit un
   seul grant ACTIF par couple tout en permettant la réémission après révocation.
   `quantity > 1` ne multiplie pas les grants (consommation via `max_downloads` ;
   les licences par unité relèveraient de la phase licences exclue).
3. **Acteurs et preuve d'accès** : la possession du token (envoyé UNE fois à
   `orders.customer_email`) est la preuve d'accès — checkout invité couvert sans
   compte (D-014). `user_id` est un rattachement d'audit nullable `ON DELETE SET
   NULL` (nullification manuelle refusée, pattern refunds), JAMAIS une preuve
   d'autorisation. La suppression de l'acheteur ne détruit ni ne réactive rien :
   le grant reste ancré sur `order_item` (NOT NULL, RESTRICT).
4. **Token** : brut = `random_bytes(32)`, jamais stocké/logué ; en BDD uniquement
   `token_hash VARCHAR(64) UNIQUE CHECK '^[0-9a-f]{64}$'` (SHA-256, comparaison par
   hash à longueur fixe) + `public_id UUID` (identifiant public séparé du secret).
   Rotation = révocation + réémission d'un grant frais (pas de compteur de version).
   `expires_at TIMESTAMPTZ NOT NULL` obligatoire (TTL recommandé 72 h, config env,
   valeur à confirmer — non bloquant) ; `max_downloads` défaut 5 (idem).
5. **Préconditions de création (trigger immédiat G3)** : commande en statut
   livrable `paid|partially_refunded` (confirmation serveur D-010), verrou de la
   ligne `orders` (`FOR UPDATE`, ordre global étendu : orders → payments → coupons
   → refunds → download_grants) sérialisant l'émission contre un remboursement
   concurrent ; `order_item.product_id NOT NULL` ; `product_file.is_active = true`
   à l'émission ; lignée fichier prouvée (fichier du produit acheté, ou d'un enfant
   `product_bundles` si la ligne est un bundle). `payment_review` ne livre JAMAIS
   (paiement tardif D-028) ; commande gratuite `paid` livrable sans ligne payments.
6. **Consommation et concurrence** : compteur `downloads_count BIGINT` protégé par
   `CHECK (0 <= downloads_count <= max_downloads)` + trigger G2 (incrément +1
   EXACT, refusé si révoqué, expiré ou quota atteint). Point de sérialisation = la
   ligne grant elle-même ; **D-029.5 remplace l'ancien UPDATE conditionnel du
   service par l'appariement PostgreSQL G5 log+compteur**. Deux tentatives
   simultanées sur la dernière
   utilisation → un seul réussit, jamais de compteur négatif ni de dépassement ;
   grants distincts sans blocage mutuel. Modèle HYBRIDE retenu : compteur protégé
   sur le grant + journal `download_logs` (audit/détection de partage).
7. **Remboursements** : total (`orders.status = refunded`) ⇒ AUCUN grant actif au
   COMMIT — invariant différé bidirectionnel G4 (constraint triggers `DEFERRABLE
   INITIALLY DEFERRED` sur `download_grants` et `orders`) ; le RefundService révoque
   et change le statut dans la même transaction (ordre réparable). Partiel
   (`partially_refunded`) ⇒ grants conservés, AUCUNE révocation automatique :
   `refunds` ne porte qu'un `payment_id`, aucune allocation par ligne — limite
   P3C-C assumée et documentée ; révocation manuelle/support possible. Un ciblage
   par ligne exigerait une table d'allocation refund→order_item et une décision
   dédiée. L'invariant couvre aussi cancelled/expired/pending/payment_review :
   aucun grant actif hors statut livrable.
8. **Versionnement fichier** : le grant pointe la ligne `product_files` achetée
   (version achetée) ; jamais d'upgrade implicite vers un fichier non acheté ;
   remplacement critique ⇒ révocation explicite (`revoked_reason_code =
   'file_replaced'`) + réémission. FK `product_file_id ON DELETE RESTRICT` : la
   suppression physique d'un produit vendu est refusée (la cascade
   products→product_files se heurte au RESTRICT) — l'historique de livraison
   survit ; les produits restent SoftDeleted en fonctionnement normal.
9. **Statut dérivé, pas stocké** : aucun enum de grant (`active/exhausted/expired/
   revoked` se dérivent de `revoked_at`/`expires_at`/`downloads_count`) —
   `expired` dépend de l'horloge et un statut matérialisé serait invérifiable par
   PostgreSQL. Seul `download_logs.status` (`started/completed/denied`) est stocké
   (enum PHP `DownloadLogStatus` en P4-B). Révocation set-once APPARIÉE à un motif
   (`revoked_at` + `revoked_reason_code` ensemble, `CASE … IS TRUE` anti-UNKNOWN).
10. **Suppression et rétention** : `download_grants` = prevent-delete absolu (G1,
    un grant se révoque et ne se supprime jamais) ; `download_logs` = SEULE table
    P4 purgeable, `retention_until` + garde BEFORE DELETE (statut terminal ET
    rétention échue, pattern T7 webhooks ; job de purge hors P4). `ip_hash` =
    HMAC-SHA-256 (clé hors BDD), jamais d'IP brute ; `user_agent` tronqué (500) ;
    minimisation RGPD ; **D-029.5/2A fixe la rétention NOT NULL sans DEFAULT**,
    365 j restant une recommandation de configuration. FK
    `download_logs.download_grant_id ON DELETE RESTRICT` (aucune
    cascade détruisant l'audit).
CATALOGUE D'OBJETS : G1–G4 (P4-A) et G5–G6 (P4-B), schéma exact, CHECK nommés,
index partiels, matrice de tests et threat model consignés dans le bloc P4 de
`DigiTrove_Schema_BDD_v1.md`. G0/S1-S3/G1-G4 vérifient et refusent sans mutation ;
**D-029.5 autorise uniquement G5 à muter `downloads_count` pour rendre l'insertion
`started` atomique**.
**[Note D-029.2 : le séquencement et la branche cités dans l'IMPACT ci-dessous
sont remplacés — première implémentation = P4-A0 sur
`p4-a0-product-file-immutability`, voir l'amendement D-029.2.]**
ALTERNATIVES REJETÉES : FK `ON DELETE CASCADE` du schéma brouillon v1 (cascade
supprimant l'historique de livraison) ; `token_hash TEXT` libre (remplacé par le
format strict 64 hex) ; unité `order × product_file` ou `customer × product_file`
(perte du lien au snapshot commercial, partage accidentel entre achats) ; statut
de grant stocké (redondance invérifiable) ; compteur de version de token (rotation
par réémission suffit) ; révocation automatique sur remboursement partiel
(impossible sans allocation par ligne) ; création de `licenses` « parce que listée » ;
journal IP en clair ; grant dépendant exclusivement d'un `user` supprimable.
IMPACT : bloc P4 de `DigiTrove_Schema_BDD_v1.md` réécrit (schéma cible + catalogue
G1–G6 + plan de tests), PROGRESS_TRACKER, HANDOFF, CLAUDE.md. Prochaine étape :
implémentation P4-A sur branche `p4-a-download-grants` (migration `000008`) après
validation humaine de ce plan. À l'implémentation P4-A : retirer `download_grants`
des seules assertions globales « table interdite » (Identity/Catalog/P3A/P3B/
P3C-A/P3C-B/P3C-C) en CONSERVANT les assertions des rollbacks isolés dont la
frontière précède `000008`, et en conservant l'interdiction de `download_logs`
(jusqu'à P4-B), `licenses`, `events` et toute table P5+. Aucune migration, modèle,
enum, factory, route, service ou logique P4/P5 créés par ce plan.

**AMENDEMENT D-029.1 — Audit contradictoire du plan (validé KingKouda : B–A–B).**
L'audit final en lecture seule du commit `202e4b8` a identifié trois décisions non
prouvées ; KingKouda a tranché. Ces choix REMPLACENT les points correspondants du
texte D-029 ci-dessus :
1. **Q1 = B — Contenu `product_files` immuable** (remplace le point 8) : la table
   P2 est mutable in-place (aucun trigger) ; « le grant fige la version achetée »
   n'était qu'une convention. Correctif : migration ADDITIVE
   `2026_07_14_000008_harden_product_files_content_immutability` (la migration P2
   mergée n'est jamais éditée — pattern P3C-B.1) + trigger **G0** figeant
   `storage_disk`, `storage_path`, `checksum_sha256`, `size_bytes`, `mime_type` ;
   `original_name`/`version`/`position`/`is_active` restent mutables. Toute
   nouvelle version de contenu = nouvelle ligne ; remplacement critique =
   désactivation + révocation + réémission.
2. **Q2 = A — Snapshot des composants de bundle à la commande** (précise le point
   5) : `product_bundles` est un pivot mutable sans historique — la lignée G3
   contre la composition COURANTE aurait livré aux anciens acheteurs des produits
   ajoutés après l'achat (fenêtre réelle : paiement tardif, réémissions).
   Correctif : table `order_item_bundle_components` (migration
   `2026_07_14_000009`), figée à la commande par le futur OrderService (pattern
   coupon_redemptions : créée en P4-A, alimentée par le checkout), triggers
   **S1/S2** (prevent-delete + immutabilité, nullification FK contrôlée). La
   lignée bundle de G3 n'interroge QUE ce snapshot ; fail-closed sans lignes.
3. **Q3 = B — Aucun DEFAULT commercial en BDD** (remplace le point 4 sur ce
   volet) : le `DEFAULT 5` de `max_downloads` figeait une politique commerciale
   dans le schéma. Correctif : `max_downloads` et `expires_at` EXPLICITES à
   chaque insertion ; la BDD n'impose que les bornes (`>= 1`, `> created_at`) ;
   TTL (72 h), quota (5) et rétention logs (365 j) deviennent des recommandations
   de configuration applicative — modifiables sans migration, à fixer à la phase
   service.
Précisions d'audit intégrées au plan (sans nouvelle décision) : règle
d'orchestration rotation anti-deadlock (verrouiller `orders` AVANT de révoquer
puis insérer) ; sémantique stricte de `download_logs.status`
(started/completed/denied sur grant existant UNIQUEMENT — un token inconnu
n'entre jamais dans la table, anti-empoisonnement P3C-B.1) ; autorisation =
conjonction pure `revoked_at IS NULL AND expires_at > now() AND downloads_count
< max_downloads` (priorité d'affichage revoked > expired > exhausted purement
cosmétique). Cette ancienne numérotation et le gate composite P4-A sont
**remplacés par D-029.2 ci-dessous**, puis par le hotfix P4-A2.1 : la séquence
active réserve désormais `000011` au hardening et `000012` à P4-B.

**AMENDEMENT D-029.2 — ProductFile version immutability and isolated P4 gate
sequencing (correction pré-implémentation).** Deux incohérences architecturales
de D-029.1 sont corrigées avant toute migration :
1. **`product_files.version` IMMUABLE.** Laisser `version` mutable alors que le
   contenu est figé aurait permis de renommer l'étiquette de version après
   l'achat : l'historique ne pourrait plus prouver de manière stable quelle
   version était associée à la ligne au moment de l'émission d'un grant.
   Colonnes FIGÉES par G0 (identité du contenu) : `product_id`, `storage_disk`,
   `storage_path`, `checksum_sha256`, `size_bytes`, `mime_type`, `version`,
   `created_at`. Colonnes mutables : `is_active`, `position`, et
   `original_name` — **audité** : libellé d'AFFICHAGE uniquement (nom de fichier
   présenté au client au téléchargement, skill SECURITE_TELECHARGEMENT) ; aucun
   usage dans le code pour résoudre le fichier (`storage_path`), produire une
   clé de stockage, vérifier l'intégrité (`checksum_sha256`) ou prouver la
   version livrée (`version` + checksum) → il reste mutable, distinction écrite.
   Confirmé : durcissement ADDITIF (`000008`), la migration P2 mergée n'est
   jamais éditée ; nouvelle version de contenu = nouvelle ligne ; une ancienne
   ligne se désactive, ne se réécrit jamais ; un grant historique continue de
   pointer vers l'ancienne ligne ; aucune réactivation ni réécriture silencieuse.
2. **Gate composite P4-A ABANDONNÉ** (violait la discipline de rollback : la
   frontière `000010` ne retirait que `000010`, laissant `000008`/`000009`
   installées ; trois invariants distincts ne partagent pas un gate ; une
   anomalie dans `download_grants` ne doit pas rollbacker le durcissement
   ProductFile). Nouveau séquencement — QUATRE gates isolés, une migration par
   gate, une frontière de rollback par gate, merge du gate N obligatoire AVANT
   la création de la migration du gate N+1 :
   - **P4-A0 — ProductFile Content Immutability** : branche
     `p4-a0-product-file-immutability`, migration
     `2026_07_14_000008_harden_product_files_content_immutability.php`,
     frontière `000008`. Rollback : retire uniquement G0 ; P0–P3C préservés.
     Garantit une référence ProductFile pointant vers un contenu historique stable.
   - **P4-A1 — Bundle Purchase Snapshot** : branche
     `p4-a1-bundle-purchase-snapshots`, migration
     `2026_07_14_000009_create_order_item_bundle_components_table.php`,
     frontière `000009`. Rollback : retire uniquement la table + S1/S2 ;
     P0–P3C + P4-A0 préservés. Garantit une composition de bundle achetée
     indépendante du pivot mutable courant.
   - **P4-A2 — Download Grants** : branche `p4-a2-download-grants`, migration
     `2026_07_14_000010_create_download_grants_table.php`, frontière `000010`.
     Rollback : retire uniquement les objets grants (G1–G4) ; P0–P3C + P4-A0 +
     P4-A1 préservés. Garantit autorisation, quota, expiration, révocation et
     consommation atomique.
   - **P4-A2.1 — Download Grant Integrity Hardening** : branche
     `p4-a2-1-grant-integrity-hardening`, migration additive
     `2026_07_14_000011_harden_download_grants_integrity.php`, frontière `000011`.
   - **P4-B — Download Logs** : branche `p4-b-download-logs`, migration
     `2026_07_14_000012_create_download_logs_table.php`, frontière `000012`.
     Rollback : retire uniquement les logs (G5–G6) ; tout le reste préservé.
     Journal métier append-only des consommations et refus sur grant existant.
   Règles de rollback par gate : appliquer uniquement jusqu'à la frontière,
   down() de la seule migration du gate, objets propres disparus, migrations
   antérieures préservées, migrations futures absentes, nettoyage dans
   `finally` ; jamais `migrate:fresh` comme preuve, jamais de rollback global,
   jamais de dépendance à un gate futur.
   Workflow : chaque gate = branche depuis la stable → PR vers
   `p0-foundations-laravel13` → merge → clôture documentaire → gate suivant.
   Interdits : développer plusieurs gates sur une branche, empiler les
   migrations avant merge du gate précédent, pousser vers `main`.
RECONFIRMÉ (inchangé) : aucune fonctionnalité HTTP/endpoint/service avant la fin
du schéma P4 ; `max_downloads` et `expires_at` toujours EXPLICITES à l'insertion ;
aucune valeur commerciale en DEFAULT (D-029.1-B) ; TTL 72 h / quota 5 / rétention
365 j = recommandations de config applicative non validées comme valeurs.
**AMENDEMENT D-029.3 — Bundle purchase snapshot integrity (validé KingKouda :
Q1=A, Q2=A).** L'audit du plan P4-A1 a établi par introspection que
`product_bundles` n'a **ni timestamps, ni trigger, ni historique** (PK composite
`(bundle_id, child_product_id)`, FK CASCADE des deux côtés, colonne `position`
seule) : sa composition est librement mutable et ne conserve aucune trace de ce
qui a été vendu. Un `order_item` est reconnu comme bundle par
`product_type_snapshot = 'bundle'` (figé par le trigger P3B). Le schéma de la
table (colonnes, FK, unique partiel, index) reste celui fixé par D-029.2 et
n'est pas re-décidé ; cet amendement fige les points laissés ouverts :
1. **Bundles imbriqués EXCLUS (Q1=A)** : S3 refuse tout composant dont
   `products.type = 'bundle'`. Motif : le CHECK P2 n'interdit que l'auto-inclusion
   directe et la prévention des cycles indirects est explicitement reportée —
   un aplatissement récursif serait exposé aux cycles, et conserver le bundle
   imbriqué tel quel livrerait un achat incomplet en silence (les fichiers des
   petits-enfants ne seraient jamais livrables). L'exclusion est fail-closed
   explicite jusqu'à une décision produit ET une protection anti-cycle dédiées.
   Aucun bundle imbriqué n'existe aujourd'hui, aucun test n'en crée.
2. **S3 — validation immédiate (Q2=A)** : fonction
   `validate_order_item_bundle_component()` / trigger
   `order_item_bundle_components_validate_trigger`, **BEFORE INSERT**. Elle
   VÉRIFIE et REFUSE uniquement — ne crée ni ne modifie aucune ligne. Contrôles :
   order_item existant (inexistence laissée au message FK, pattern P3C) ;
   `product_type_snapshot = 'bundle'` (un order_item DIRECT ne reçoit jamais de
   composant) ; `product_id IS NOT NULL` (sans le produit bundle la lignée n'est
   pas prouvable) ; composant existant ; composant **non-bundle** ; composant
   présent dans `product_bundles` pour le bundle de l'order_item **au moment de
   la copie**. C'est la seule lecture légitime du pivot ; aucune comparaison au
   pivot courant n'existe après le COMMIT.
3. **Exhaustivité = garantie APPLICATIVE, jamais BDD** : un trigger ligne-par-ligne
   prouve que chaque ligne est valide, jamais que toutes ont été copiées. Le futur
   OrderService réalise un **unique `INSERT ... SELECT`** depuis `product_bundles`
   (exhaustif et cohérent par construction : une seule requête = un seul
   instantané, aucun mélange avant/après), dans la **même transaction** que la
   création de l'order_item, après `SELECT ... FROM products WHERE id =
   <bundle_id> FOR UPDATE`. Ordre de verrous : le catalogue se verrouille avant
   `orders` (ordre global `orders → payments → coupons → refunds →
   download_grants` inchangé pour les phases existantes).
   **Options écartées** : constraint trigger différé d'exhaustivité — il relit le
   pivot au COMMIT, provoquant un faux refus d'un checkout légitime sous
   concurrence et créant une dépendance permanente au pivot mutable (anti-pattern
   interdit) ; fonction de copie atomique — elle muterait, contre le principe
   « les triggers vérifient et refusent, le service exécute les mutations ».
4. **Risque résiduel ASSUMÉ, jamais présenté comme éliminé par PostgreSQL** : un
   rôle SQL privilégié peut insérer tardivement une ligne pour un composant ajouté
   au bundle après l'achat (S3 la validerait, le pivot courant la contenant).
   Couverture : permissions BDD minimales, absence d'API de mutation directe,
   tests — cohérent avec le compromis assumé de D-027.
5. **Aucun fallback en P4-A2** : la lignée bundle se prouve UNIQUEMENT contre le
   snapshot, aucun repli sur `product_bundles`, aucune supposition, message stable.
   ⚠️ **Formulation corrigée par D-029.4 (finding 1)** — le texte original disait
   « snapshot absent **ou incomplet** ⇒ émission refusée », ce qui prêtait à
   P4-A2 une capacité qu'il n'a pas. Contrat cible exact : un snapshot
   **totalement absent** est détectable et refusé ; un composant absent du
   snapshot n'est simplement jamais livrable ; un snapshot **partiellement copié
   est indétectable** (P4-A1 ne stocke ni en-tête, ni compteur attendu, ni preuve
   de complétude, et comparer au pivot courant est interdit). Risque résiduel :
   sous-livraison possible, jamais de sur-livraison.
6. **Objets P4-A1** : table `order_item_bundle_components` + **trois fonctions /
   trois triggers** (S1 prevent-delete, S2 immutabilité, S3 validation immédiate).
   Aucune quantité (le pivot n'en a pas) ; aucune `position` (ordre d'affichage
   mutable, inutile à P4-A2). Snapshots textuels `child_product_name/slug`
   justifiés : `products.name/slug` sont mutables et `child_product_id` est
   SET NULL — la FK seule ne suffit pas à l'audit (pattern D-027 : la preuve
   d'achat survit à une purge légale).
7. **Bundle vide — garde-fou explicite (validé KingKouda)** : la BDD **autorise
   techniquement** un snapshot vide ; aucune contrainte n'impose « au moins une
   ligne » pour un order_item bundle (un tel invariant exigerait un constraint
   trigger différé relisant le pivot mutable — écarté au point 3). Le futur
   **OrderService REFUSE la commande d'un bundle vide AVANT la création de
   l'order_item**. Si une copie échoue ou est oubliée, **P4-A2 reste fail-closed :
   aucun grant n'est émis**. Cette règle est une **garantie APPLICATIVE**, jamais
   un invariant PostgreSQL — elle ne doit jamais être présentée autrement. Test
   P4-A1 attendu : prouver que la BDD accepte l'absence de snapshot (pas de
   cardinalité minimale) et consigner que le refus incombe à l'OrderService.
8. **Migration** : `2026_07_14_000009_create_order_item_bundle_components_table.php`.
   **Branche** : `p4-a1-bundle-purchase-snapshots`, créée depuis la stable après
   merge de `000008`. **Rollback isolé** (frontière `000009`) : le down() retire
   S1/S2/S3 et la table uniquement ; P4-A0 (fonction + trigger G0), P0–P3C,
   `products`, `product_files`, `product_bundles`, `orders`, `order_items` sont
   préservés ; `000010`, `000011` et `000012` restent absentes ; nettoyage dans
   `finally`.
EXCLUSIONS P4-A1 : aucun grant, token, quota, expiration, téléchargement, log ;
aucun OrderService, checkout, service, endpoint, route, job ou listener ; aucun
code P4-A2/P4-B/P5.
CRITÈRES DE SORTIE : migration `000009` verte ; 3 fonctions / 3 triggers confirmés
par introspection ; matrice de tests complète (schéma, snapshot valide, produit
direct refusé, intégrité S3 dont imbrication refusée, immutabilité, suppression,
historique ajout/retrait, concurrence à 2 connexions, rollback isolé) ; suite
complète et Pint verts ; PR vers `p0-foundations-laravel13` (jamais mergée par
l'agent). Adaptation historique prévue : retirer `order_item_bundle_components`
de l'unique assertion globale de `P4A0ProductFileImmutabilityTest`, en CONSERVANT
son absence dans le rollback isolé P4-A0 (frontière `000008` < `000009`) et les
interdictions `download_grants`/`download_logs`/`licenses`/P5.

**AMENDEMENT D-029.4 — Download grant security and lifecycle (validé KingKouda :
option A).** Finalise le contrat P4-A2 (`download_grants`, migration `000010`,
branche `p4-a2-download-grants`). Le schéma, l'unité (`order_item × product_file`),
le token SHA-256, les FK, l'unique partiel, les index, `max_downloads`/`expires_at`
sans DEFAULT et G1–G4 restent ceux de D-029/D-029.1/D-029.2 et ne sont pas
re-décidés. Cet amendement fige les points laissés ouverts et corrige trois
formulations.
1. **ProductFiles postérieurs à l'achat — option A : émission immédiate comme
   snapshot APPLICATIF.** Aucun snapshot BDD des fichiers achetés n'existe (P4-A1
   ne snapshotte que les composants de bundle) : la lignée G3 accepterait donc
   techniquement un fichier ajouté après l'achat. Choix retenu : à l'événement
   futur `OrderPaid`, le service d'émission crée les grants pour les ProductFiles
   **actifs à cet instant**, financièrement éligibles et de lignée valide ; les
   lignes `download_grants` constituent ensuite le **snapshot applicatif des
   fichiers livrés**. **PostgreSQL ne garantit pas qu'un ProductFile existait au
   moment de l'achat** : G3 garantit seulement la lignée (fichier d'un produit
   réellement acheté), l'absence de fichier d'un produit non acheté et l'absence
   de fallback vers le pivot bundle courant. → **« absence d'upgrade implicite =
   garantie APPLICATIVE, pas invariant PostgreSQL »**, à écrire ainsi partout.
   Alternatives écartées : snapshot BDD des ProductFiles (quatrième gate, garantie
   forte mais coût structurel) ; accès aux fichiers actifs courants (contredirait
   D-029.1-B « version achetée / jamais d'upgrade implicite »).
2. **ProductFile ajouté après l'émission initiale** : ne reçoit aucun grant
   automatiquement ; n'est jamais sélectionné par une rotation ni par une
   réémission support ; aucun listener rétroactif ; reste inaccessible à cette
   commande. Toute création ultérieure d'un grant vers ce fichier relève d'une
   opération métier distincte — **`upgrade entitlement`** — exclue de P4-A2, de
   P4-B et du MVP, soumise à une décision produit explicite. Ne jamais la
   qualifier de rotation ni de réémission.
3. **Rotation / réémission / upgrade** : la **rotation de token** révoque l'ancien
   grant et crée une nouvelle ligne conservant EXACTEMENT le même
   `order_item_id + product_file_id`, avec un nouveau digest ; aucun changement de
   fichier ; historique conservé. La **réémission support** obéit à la même règle.
   **Tout changement de `product_file_id` est une nouvelle attribution
   commerciale** (upgrade), jamais une rotation.
FINDINGS CORRIGÉS PAR CET AMENDEMENT :
- **Finding 1 — snapshot bundle partiel** : voir la correction de formulation
  insérée dans D-029.3 point 5. Absent = détectable et refusé ; composant manquant
  = jamais livrable ; **partiellement copié = indétectable** ; sous-livraison
  possible, jamais de sur-livraison ; exhaustivité = futur OrderService + tests ;
  aucune comparaison ultérieure au pivot courant.
- **Finding 2 — grant expiré non révoqué** : l'unique partiel
  `UNIQUE(order_item_id, product_file_id) WHERE revoked_at IS NULL` retient un
  grant **expiré mais non révoqué** dans son prédicat : il bloque toute nouvelle
  émission pour le même couple et **doit être révoqué avant réémission** (motif
  recommandé `expired_reissue`). **Aucun index partiel n'utilisera `now()`**
  (prédicat non immutable). Expiration et révocation restent distinctes :
  l'expiration n'écrit rien, aucun job ne révoque implicitement, la réémission
  déclenche explicitement la révocation préalable.
- **Finding 3 — `user_id` redondant** : conservé conformément à D-029.2, mais
  qualifié pour ce qu'il est — une **dénormalisation de support et d'audit**, non
  la source d'autorité du bénéficiaire. Source d'autorité : `grant → order_item →
  order` ; `orders.customer_email` (CITEXT NOT NULL) reste le snapshot d'identité
  obligatoire ; `user_id` nullable, `ON DELETE SET NULL`. G3 vérifie la cohérence
  initiale avec `orders.user_id` lorsqu'il est renseigné.
ÉMISSION FINANCIÈRE : la source d'autorité est **`orders.status`**, déjà garantie
au COMMIT par les constraint triggers différés P3C (`orders_validate_payment_
consistency`, `orders_validate_refund_consistency`, `refunds_validate_order_
consistency` — vérifiés `deferrable=true`). États **livrables** : `paid`,
`partially_refunded`. Non livrables : `pending`, `payment_review`, `cancelled`,
`expired`, `refunded`. **G3/G4 ne relisent pas `payments`** (aucune nécessité
démontrée ; la commande gratuite `total_minor = 0` suit les invariants Order
existants sans sémantique Payment supplémentaire).
SCHÉMA FINAL CONFIRMÉ (aucune colonne ajoutée) : `id`, `public_id` UUID,
`order_item_id` (RESTRICT), `product_file_id` (RESTRICT), `user_id` (SET NULL,
audit), `token_hash` VARCHAR(64) UNIQUE, `max_downloads` NOT NULL, `downloads_count`
NOT NULL (0 technique), `expires_at` NOT NULL, `revoked_at`, `revoked_reason_code`,
`created_at`, `updated_at` (`created_at` fait foi comme date d'émission — aucune
colonne `issued_at` distincte n'est ajoutée). Token brut jamais persisté ; SHA-256
64 hex ; aucun DEFAULT commercial ; **aucun quota illimité, aucune absence
d'expiration** ; `downloads_count` créé mais **non consommé avant P4-B** ; aucune
IP, user-agent, metadata, JSONB, `last_downloaded_at`, `token_prefix`, nom/chemin/
checksum de fichier (déjà immuables via G0), statut texte ni soft delete.
FRONTIÈRE P4-B : P4-A2 livre table, modèle, factory, relations, contraintes, G1–G4,
structure du compteur et tests (émission, lignée, révocation, rotation). P4-A2 ne
crée **aucune** route, contrôleur, streaming, consommation réelle, incrément depuis
une requête, DownloadLog, IP, user-agent ni analyse. P4-B réalisera atomiquement :
validation du token, contrôle expiration/révocation/quota, création du log,
incrément du compteur, succès ou refus cohérent.
CONCURRENCE : émission sous `orders FOR UPDATE` ; deux émissions simultanées →
unique partiel du couple actif ; collision de token → unique `token_hash`
(régénération avant commit) ; rotation → verrou du grant existant, révocation, puis
création ; remboursement total → verrou Order avant grants ; suppression
ProductFile → FK RESTRICT ; désactivation ProductFile → nouvelle émission refusée ;
consommation future → verrou du grant en P4-B. Ordre global inchangé : `orders →
payments → coupons → refunds → download_grants`. Aucun verrou global.
RISQUES RÉSIDUELS ASSUMÉS (jamais présentés comme éliminés par PostgreSQL) :
émission SQL privilégiée d'un grant vers un fichier ajouté après l'achat (G3 valide
la lignée, pas la temporalité) ; snapshot bundle partiel indétectable ; mutation
administrative privilégiée ne prenant pas le verrou applicatif ; fuite du token
dans une couche applicative future. Couverture : permissions BDD minimales, absence
d'API de mutation directe, service transactionnel unique, tests, audit.
CRITÈRES DE SORTIE : migration `000010` verte ; G1–G4 confirmés par introspection ;
matrice de tests complète (schéma, token, éligibilité financière, lignée
directe/bundle, snapshot absent refusé + partiel documenté comme indétectable,
fichier inactif refusé, quota/expiration, immutabilité, révocation irréversible,
rotation même ProductFile, grant expiré bloquant la réémission puis
`expired_reissue`, remboursement total + G4, concurrence, rollback isolé `000010`
préservant P4-A0/G0 et P4-A1/S1-S3) ; suite complète et Pint verts ; PR jamais
mergée par l'agent. P4-A2.1 (`000011`), P4-B (`000012`) et P5 non démarrés à la
date de cette décision.

**Note d'exécution P4-A2** (aucune décision nouvelle) : implémenté sur
`p4-a2-download-grants` (migration `000010`) conformément à D-029.4, puis **mergé
via [PR #13](https://github.com/mysterus44/DigiTrove/pull/13) → `77f3766`** (parents
`1b6e401` + `cea5f2d`). Audit post-merge : 13 colonnes exactes, FK RESTRICT/RESTRICT/
SET NULL, 5 CHECK nommés, uniques `public_id`/`token_hash`, index partiel actif sans
`now()`, 4 fonctions / 5 triggers (G4 différé sur les deux domaines), G3/G4 sans
mutation, G3 sans lecture de `payments`, aucun trigger `download%` sur `refunds`,
G0 et S1/S2/S3 préservés, rollback isolé `000010` vert ; suite 151/2188, Pint 110.
**Delta d'assertions** : 1882 − 9 (9 itérations `hasTable('download_grants')`
devenues fausses : 5 entrées de listes + 4 éléments de `foreach`) + 315 = 2188 ;
les 3 assertions remplacées par `download_logs` et `toBe(25)`→`toBe(26)` sont
neutres ; les 4 assertions des rollbacks isolés antérieurs conservées. Confirmé par
introspection : **4 fonctions / 5 triggers** — G1 `prevent_download_grants_delete`
(BEFORE DELETE), G2 `enforce_download_grants_immutability` (BEFORE UPDATE : ROW
figée + `user_id` nullable via `pg_trigger_depth() > 1` + compteur +1 borné +
révocation set-once irréversible), G3 `validate_download_grant_delivery` (BEFORE
INSERT, `orders FOR UPDATE`), **G4 `validate_download_grant_order_consistency`
monté sur les DEUX domaines** (`download_grants_validate_order_consistency_trigger`
+ `orders_validate_download_consistency_trigger`, tous deux `DEFERRABLE INITIALLY
DEFERRED` — vérifié `tgdeferrable=t, tginitdeferred=t`). Vérifié : G3/G4 ne mutent
rien (0 écriture dans `pg_get_functiondef`) ; **G3 ne lit jamais `payments`**
(0 occurrence) ; **aucun trigger `download%` sur `refunds`** ; `max_downloads` et
`expires_at` sans DEFAULT (seul `downloads_count` garde `'0'::bigint` technique) ;
aucun index n'utilise `now()`. **Findings d'implémentation signalés** : (1) les
CHECK `count_within_quota_check` sont SHADOWÉS par G2/G3 qui s'exécutent avant —
ils restent une défense en profondeur, asserts structurellement ; (2) le state
`revoked()` initialement écrit dans la factory produisait une ligne non-insérable
(G3 exige un grant né actif) — supprimé, la révocation ne s'obtient que par UPDATE.
Adaptations historiques : `download_grants` retiré de 12 assertions globales, les
4 assertions des rollbacks isolés (frontières `000005`/`000007`/`000008`/`000009`)
et le test HTML `StorefrontPreviewTest` conservés ; compteur de migrations P4-A1
porté de 25 à 26. Validation : 26 migrations ; P4-A2 **18 tests / 315 assertions** ;
suite complète **151 / 2188** ; Pint **110** ; rollback isolé `000010` vert (P4-A1
et P4-A0/G0 préservés) ; aucune base temporaire résiduelle. PR en attente de
review ; P4-B (`000012`) et P5 non démarrés.

**Correctif d'exécution P4-A2.1 — Grant beneficiary and timestamp integrity
hardening** (amendement factuel à D-029.4, aucune nouvelle décision) : deux
transactions PostgreSQL post-merge ont démontré que G3 acceptait un `user_id`
arbitraire lorsque `orders.user_id IS NULL`, et que G2 acceptait une modification
isolée de `updated_at`. Ces reproductions ont été rollbackées sans donnée persistée.
Le gate correctif utilise exclusivement la migration
additive `2026_07_14_000011_harden_download_grants_integrity.php` ; la migration
mergée `000010` reste byte-for-byte inchangée. G3 exige désormais
`NEW.user_id IS NOT DISTINCT FROM orders.user_id`, soit la matrice stricte invité
`NULL`/compte même identifiant. G2 n'accepte un changement d'`updated_at` que s'il
avance strictement et accompagne une consommation `downloads_count + 1` ou une
révocation valide ; la nullification FK légitime de `user_id` reste autorisée sans
imposer un changement de timestamp. Le remplacement conserve les signatures, les
4 fonctions et les 5 triggers existants, sans mutation de données, table, index,
CHECK ni trigger supplémentaire. Le `down()` restaure exactement les définitions
G2/G3 de `000010`, prouvé avec `pg_get_functiondef` dans une base PostgreSQL isolée.
Validation post-merge : 27 migrations ; P4-A2.1 7 tests / 113 assertions ; P4-A2
18/315 ; P4-A1 17/216 ; P4-A0 9/130 ; suite complète 158/2301 ; Pint 112 ;
rollback isolé `000011` vert ; aucune base temporaire résiduelle. Statut : **mergé
via [PR #14](https://github.com/mysterus44/DigiTrove/pull/14), merge
`2c25e2a412a24ac6ae2e5d51ed6929f3f0a397f7` (parents `0633eb0` + `ba834be`)**.
La branche locale du hotfix est supprimée et sa distante conservée. P4-B est
renuméroté `2026_07_14_000012_create_download_logs_table.php` et reste non démarré.

### D-029.5 — Download consumption and audit logging ✅
**Décision** : le gate P4-B est planifié sur la branche future
`p4-b-download-logs`, dans l'unique migration future
`2026_07_14_000012_create_download_logs_table.php`. Il consomme des grants déjà
émis ; il ne crée ni grant, ni route, ni contrôleur, ni streaming. Décisions
humaines figées : **1A** consommation à `started`, **2A** rétention obligatoire
sans DEFAULT, **3A** HMAC IP versionné, **R1A** une tentative authentifiée regroupe
Range/retries, **R2A** HEAD n'a aucun effet commercial, **R3A** `completed` prouve
la remise au mécanisme de livraison et jamais la réception intégrale par le client.

**Unité consommante et atomicité** : une tentative de téléchargement consomme une
unité. La première autorisation GET réussie calcule les secrets en mémoire, retrouve
le grant, verrouille **Order puis DownloadGrant**, revalide statut financier,
révocation, expiration, quota et ProductFile, insère un log `started` avec
`quota_consumed=true`, incrémente `downloads_count` exactement de `+1`, puis commit.
La livraison commence seulement après le commit ; aucune transaction ne reste
ouverte pendant le streaming ou la remise à X-Accel/X-Sendfile/stockage. Un refus
préalable sur grant connu crée directement `denied + quota_consumed=false`, sans
incrément. Un échec après `started` produit `started → denied`, conserve
`quota_consumed=true` et ne restitue jamais le quota. Un token de grant inconnu ne
crée aucun DownloadLog.

**Schéma exact planifié** : `download_logs` comporte uniquement `id BIGSERIAL`,
`public_id UUID`, `download_grant_id BIGINT`, `status VARCHAR(20)`,
`quota_consumed BOOLEAN`, `attempt_token_hash VARCHAR(64)`,
`attempt_expires_at TIMESTAMPTZ`, `denial_reason_code VARCHAR(64)`,
`ip_hash VARCHAR(64)`, `ip_hash_key_version SMALLINT`,
`user_agent VARCHAR(500)`, `bytes_sent BIGINT`, `terminal_at TIMESTAMPTZ`,
`retention_until TIMESTAMPTZ` et `created_at TIMESTAMPTZ`. `id` est la PK ;
`public_id`, `download_grant_id`, `status`, `quota_consumed`, `retention_until` et
`created_at` sont NOT NULL ; `download_grant_id` référence `download_grants(id)`
en `ON DELETE RESTRICT`. Aucun DEFAULT métier : ni statut, ni quota consommé, ni
expiration de tentative, ni rétention. Seul `created_at DEFAULT now()` est
technique. Aucun `updated_at` générique, soft delete, JSONB, token brut, préfixe,
IP brute, email, chemin, checksum ou copie du token/digest du grant.

| Colonne | Type / NULL / DEFAULT | Contraintes et index | Mutabilité / rôle |
|---|---|---|---|
| `id` | `BIGSERIAL NOT NULL` (séquence technique) | PK | immuable, identité interne |
| `public_id` | `UUID NOT NULL`, sans DEFAULT | UNIQUE | immuable, identifiant public non secret |
| `download_grant_id` | `BIGINT NOT NULL`, sans DEFAULT | FK `download_grants.id RESTRICT`; index grant/date | immuable, fixe grant et ProductFile |
| `status` | `VARCHAR(20) NOT NULL`, sans DEFAULT | CHECK fermé `started/completed/denied`; G5 | seule transition `started → completed/denied` |
| `quota_consumed` | `BOOLEAN NOT NULL`, sans DEFAULT | CHECK d'état + G5 | immuable, preuve historique de l'incrément |
| `attempt_token_hash` | `VARCHAR(64) NULL`, sans DEFAULT | regex SHA-256; unique partiel non NULL | immuable, digest du secret dédié |
| `attempt_expires_at` | `TIMESTAMPTZ NULL`, sans DEFAULT | pairé au hash; `created_at < valeur <= retention_until`; index started | immuable, fenêtre courte de reprise |
| `denial_reason_code` | `VARCHAR(64) NULL`, sans DEFAULT | CHECK ensemble fermé | NULL→code seulement lors de `started→denied`; sinon figé |
| `ip_hash` | `VARCHAR(64) NULL`, sans DEFAULT | HMAC lowercase, pairé à la version | immuable, pseudonyme réseau |
| `ip_hash_key_version` | `SMALLINT NULL`, sans DEFAULT | pairé au hash et `> 0` | immuable, version non secrète |
| `user_agent` | `VARCHAR(500) NULL`, sans DEFAULT | non blanc si présent | immuable, audit minimisé |
| `bytes_sent` | `BIGINT NULL`, sans DEFAULT | `>= 0` si présent | monotone pendant `started`, figé au terminal |
| `terminal_at` | `TIMESTAMPTZ NULL`, sans DEFAULT | pairé au statut, `>= created_at` | set-once au terminal ; timestamp partagé minimal, sans colonnes redondantes |
| `retention_until` | `TIMESTAMPTZ NOT NULL`, sans DEFAULT | `> created_at`; index partiel terminal | réduction interdite, extension seule |
| `created_at` | `TIMESTAMPTZ NOT NULL DEFAULT now()` | horloge technique | immuable, naissance du log |

**Machine d'état stricte** : INSERT autorise `started` ou `denied`, jamais
`completed`. `started` exige quota consommé, secret/expiration présents, motif et
`terminal_at` NULL, et `bytes_sent` NULL à l'insertion. Le seul `denied` insérable
directement exige quota non consommé, aucun secret/expiration, motif fermé et
`terminal_at` renseigné. Les seules transitions sont `started → completed` et
`started → denied`; elles conservent `quota_consumed=true` et le secret. Le motif
reste NULL pour `completed` et devient obligatoire pour `denied`. `completed` et
`denied` sont terminaux, non réactivables ; seule une extension de rétention reste
possible. `terminal_at` est set-once et au moins égal à `created_at`.

Codes fermés de `denial_reason_code` : `authorization_denied`, `quota_exhausted`,
`grant_expired`, `grant_revoked`, `order_not_deliverable`,
`product_file_unavailable`, `attempt_expired`, `delivery_interrupted`,
`storage_failure`, `internal_error`. Aucun texte libre, exception, token, digest,
email, chemin ou message fournisseur.

**Secret de tentative (R1A)** : CSPRNG distinct du token/public_id du grant et du
public_id du log ; communiqué une fois par la future couche HTTP, gardé en mémoire,
persisté seulement comme SHA-256 lowercase `[0-9a-f]{64}`. G5 refuse notamment un
digest identique à `download_grants.token_hash`. Le digest est unique lorsqu'il
est non NULL et immuable. `attempt_token_hash IS NULL ⇔ attempt_expires_at IS NULL`;
l'expiration est explicite, courte, strictement postérieure à `created_at`, au plus
égale à `retention_until`, immuable, sans prolongation ni rotation en place. Elle
est obligatoire pour `started`, `completed` et `denied` issu de `started`, absente
du refus direct non consommant. Aucune durée n'est un DEFAULT BDD. Une reprise
présente `public_id + secret`; `public_id` seul n'autorise rien. Le secret futur
voyage dans un header dédié ou un cookie sécurisé à décider au gate HTTP, jamais
en query string, logs, exceptions, analytics ou documentation.

**Range, retries et HEAD** : Range/reprise/retry avec tentative valide réutilisent
la même ligne et le même grant donc le même ProductFile immuable, sans log ni
incrément supplémentaire. Ils revalident secret, expiration, révocation, état
livrable de l'Order et absence de substitution. Des retries concurrents se
résolvent par le lookup unique du digest et des transitions terminales atomiques ;
aucun segment Range ne crée de ligne. Après expiration, une nouvelle autorisation
et une nouvelle unité sont nécessaires si du quota reste. Révocation ou
remboursement total annulent l'autorité de reprise, même avec un secret valide.
Un log déjà `completed` peut authentifier les retries jusqu'à l'expiration sans
être rouvert ni muté. `HEAD` ne crée ni log, ni tentative, ni secret, ni incrément ;
il répond de façon non énumérable. Rate limit, journal sécurité et détection d'abus
HEAD restent hors de `download_logs`. Le futur UI exige une action GET explicite et
n'expose aucune URL consommante à un mécanisme de préchargement.

**Sémantique de `completed` (R3A)** : remise réussie au composant de livraison
(réponse Laravel initialisée, X-Accel, X-Sendfile, URL temporaire ou mécanisme
futur explicitement supporté). Elle ne prouve ni réception de tous les octets, ni
écriture disque client, ni absence de coupure, ni ouverture humaine. `bytes_sent`
reste nullable ; lorsqu'il est mesurable, il est BIGINT non négatif et progresse
de façon monotone pendant `started`, puis devient immuable. Il peut rester NULL ou
être inférieur à `product_files.size_bytes` lors de `completed`; il ne restitue
jamais un quota. Une panne après commit avant remise laisse `started` consommé
jusqu'à une réconciliation explicite ; aucune transition BDD par timeout. Une
panne après `completed` ne change pas cette sémantique et relève de la télémétrie
du mécanisme, pas d'une preuve de réception client.

**Rétention et identité IP** : `retention_until TIMESTAMPTZ NOT NULL`, fournie
explicitement, strictement postérieure à `created_at`, réduction interdite,
extension autorisée ; 365 jours est seulement une recommandation de configuration.
`ip_hash` est un HMAC-SHA-256 lowercase en `VARCHAR(64)`, jamais un SHA simple ;
`ip_hash_key_version SMALLINT > 0`. Hash/version sont tous deux NULL ou tous deux
présents et sont immuables. Le secret HMAC reste hors BDD ; une rotation ne modifie
pas les anciennes lignes. `user_agent` nullable et borné à 500 caractères suit la
même rétention.

**Contraintes/index planifiés** : CHECK nommés et stricts (`CASE ... ELSE FALSE END
IS TRUE`) pour statut/quota/motif/secret/timestamp terminal, format du digest,
couple hash-expiration et fenêtre temporelle, rétention, paire HMAC/version,
user-agent non blanc et `bytes_sent >= 0`. Uniques : `public_id`, puis index unique
partiel `attempt_token_hash WHERE attempt_token_hash IS NOT NULL`. Index :
`(download_grant_id, created_at DESC)`, `(download_grant_id, attempt_expires_at)
WHERE status='started'`, et `(retention_until) WHERE status IN
('completed','denied')`. Aucun prédicat ne dépend de `now()`.

**G2/G5/G6 et catalogue exact** : `000012` ajoute exactement **deux fonctions et
deux triggers** P4-B : `enforce_download_logs_integrity()` reliée par
`download_logs_enforce_integrity_trigger` (`BEFORE INSERT OR UPDATE`) et
`enforce_download_logs_retention_delete()` reliée par
`download_logs_retention_delete_trigger` (`BEFORE DELETE`). Elle remplace aussi
en place la fonction G2 existante `enforce_download_grants_immutability()` sans
ajouter de trigger grant. G5 est l'unique exception P4 explicitement mutante : sur
INSERT `started`, elle verrouille Order puis Grant, revalide, et effectue l'UPDATE
`downloads_count + 1`; l'INSERT et l'UPDATE rollbackent ensemble. G2 conserve
toutes les protections P4-A2.1 (`user_id` FK, révocation, bornes, `updated_at`) mais
refuse tout incrément direct à profondeur 1 et n'accepte l'exact `+1` que depuis
le trigger G5 imbriqué (`pg_trigger_depth() > 1`). Le rôle applicatif ne possède
aucun privilège DDL permettant de fabriquer un trigger concurrent. G5 contrôle
insertions, transitions, immutabilité, progression des octets et extension de
rétention, sans réécrire silencieusement NEW. G6 autorise une suppression seulement
pour une ligne terminale arrivée à rétention ; un DELETE multi-lignes contenant une
ligne inéligible échoue atomiquement. La purge ne touche jamais compteur, grant,
OrderItem ou ProductFile et ne rend aucun quota.

**Concurrence** : ordre global `orders → payments → coupons → refunds →
download_grants`. P4-B effectue une lecture minimale, verrouille l'Order, puis le
Grant, revalide, insère et incrémente. Avec une unité restante, une seule création
`started` réussit ; l'autre attend puis produit un refus direct non consommant ou
une réponse uniforme selon l'orchestration, sans dépassement. Les retries d'une
même tentative n'acquièrent aucune nouvelle unité ; les transitions terminales
sérialisées ne peuvent ni rouvrir la ligne ni la terminaliser deux fois. Grants
distincts ne subissent aucun verrou global.

**Artefacts Laravel futurs** : enum `DownloadLogStatus` strictement
`started|completed|denied` et enum fermé `DownloadDenialReasonCode` aligné sur le
CHECK ; modèle `DownloadLog` avec `grant(): BelongsTo`, casts bool/int/datetime et
hashes de tentative/IP masqués ; relation minimale
`DownloadGrant::downloadLogs(): HasMany`. La factory produit par défaut un
`started` cohérent sur grant valide avec digests factices uniques et aucune valeur
brute. Les scénarios `completed` et `denied` après consommation doivent créer
d'abord `started` puis effectuer la transition (jamais contourner G5 par un state
d'insertion invalide) ; le refus direct est un state distinct non consommant.
Aucun state ne modifie silencieusement Order/Grant hors l'effet G5 attendu, ne
désactive un trigger ou n'effectue d'appel réseau.

**Rollback fail-closed `000012`** : `down()` refuse en SQLSTATE `23514` si la
table contient une ligne. Aucun audit n'est détruit, aucun compteur n'est remis à
zéro ou décrémenté. Table vide seulement : supprimer triggers puis fonctions P4-B,
restaurer textuellement G2 P4-A2.1 issu de `000011`, puis supprimer la table ;
préserver P4-A0, P4-A1, P4-A2, P4-A2.1 et leurs données/objets. Le harness isolé
teste séparément le refus avec ligne, puis le rollback vide à frontière exacte
`000012`, sans migration future ni base résiduelle.

**Matrice de tests obligatoire** : PostgreSQL réel, introspection types/FK/CHECK/
index/fonctions/triggers, anti-`CHECK = UNKNOWN`, insertion `started` et incrément
atomiques, rollback commun, refus direct non consommant, transitions et champs
immuables, rétention et purge multi-lignes, HMAC versionné, absence de secrets et
colonnes interdites. Deux connexions réelles couvrent dernière unité, retries et
transitions concurrents, grants distincts et absence de deadlock. Range/retries
vérifient un seul log, secret/public_id appariés, même grant/fichier, expiration,
révocation/remboursement et substitution refusée. HEAD reste sans effet. Les tests
de mécanisme documentent Laravel/X-Accel/X-Sendfile/URL temporaire, `bytes_sent`
NULL ou inférieur à la taille, sans prétendre à la réception client. Rollback
isolé prouve restauration exacte de G2 `000011` et préservation de P4-A. La cible
après l'unique migration est **28 migrations** ; les adaptations historiques
retirent `download_logs` uniquement des assertions globales devenues obsolètes,
conservent son absence dans toutes les frontières antérieures à `000012`, et
laissent P2/P3/P4-A intégralement verts.

**Threat model final** :

| Menace | Prévention | Détection | Risque résiduel | Gate responsable |
|---|---|---|---|---|
| vol du secret de tentative | CSPRNG, digest seul, transport header/cookie, TTL court | IP HMAC/version + logs sécurité | usage possible pendant la fenêtre | HTTP + P4-B |
| rejeu pendant validité | revalidation secret, Grant et Order à chaque requête | retries/IP/rate limit | rejeu légitime et malveillant difficiles à distinguer | HTTP/Redis |
| collision de digest | 256 bits + unique partiel | violation `23505`/télémétrie | risque cryptographique négligeable, non nul | P4-B |
| tentative sur autre fichier | lignée immuable log→grant→ProductFile | refus uniforme + audit du log | rôle SQL privilégié | P4-B + permissions |
| usage après révocation | Grant revalidé à chaque Range/retry | code `grant_revoked` si tentative connue | course sérialisée par verrou | HTTP + G5/G4 |
| usage après remboursement total | Order revalidée livrable | code `order_not_deliverable` | course avant commit financier | HTTP + G4 |
| secret en query string | query interdite par contrat | tests route/proxy et revue de logs | mauvaise configuration HTTP | gate HTTP |
| secret journalisé | masquage, aucune exception/analytics avec secret | tests de confidentialité + scan logs | infrastructure externe mal configurée | HTTP/Ops |
| retries concurrents | unique digest/log, transitions atomiques | tests deux connexions | charge sans dépassement commercial | P4-B + Redis |
| Range abusive | même tentative, quota non multiplié ; rate limit futur | métriques/rate limit | bande passante consommée | HTTP/Redis |
| tentative expirée rejouée | expiration explicite immuable, nouvelle autorisation | refus uniforme + audit sécurité | horloge/configuration incorrecte | HTTP + P4-B |
| préchargement automatique | action GET explicite, aucune balise préchargeable | métriques front/sécurité | préfetch externe non maîtrisé | UI/HTTP |
| HEAD d'énumération | réponse uniforme, aucun log/quota/secret | rate limit et logs sécurité séparés | trafic d'énumération | HTTP/Redis |
| `completed` interprété comme réception | définition R3A et aucune condition `bytes=size` | revue tests/docs | aucune preuve de réception client | P4-B/Ops |
| `bytes_sent` non fiable | nullable, non utilisé pour quota/terminal | télémétrie du mécanisme | mesure absente avec délégation | HTTP/Ops |
| panne après commit avant remise | transaction courte, log `started` consommé | détection des `started` anciens | quota consommé sans livraison ; pas de compensation MVP | Ops futur |
| panne après `completed` | sémantique limitée à la remise | télémétrie X-Accel/stockage | livraison client finale inconnaissable | Ops futur |

Détection d'abus et rate limiting relèvent de Redis/logs sécurité/gate HTTP,
jamais de lignes `download_logs` pour un token inconnu.

**Exclusions/statut** : aucune émission de grant, compensation quota, listener
OrderPaid, e-mail, route, contrôleur, service, streaming, endpoint public, P5 ou
code fournisseur. Le choix header/cookie, la durée exacte de tentative/rétention,
le rate limiting et la réconciliation sont des paramètres/gates applicatifs
futurs, sans DEFAULT commercial BDD. **P4-B implémenté sur `p4-b-download-logs`
(`8cf24a8`) mais BLOQUÉ AU MERGE** : l'audit offensif a prouvé que l'autorité
`pg_trigger_depth() > 1` de G2 est contournable — voir **D-029.6** (gate préalable
P4-B0, séparation de rôles + G5 `SECURITY DEFINER`).

**Note d'exécution P4-A1** (aucune décision nouvelle) : implémenté sur
`p4-a1-bundle-purchase-snapshots` (migration `000009`) conformément à D-029.3, puis
**mergé via [PR #12](https://github.com/mysterus44/DigiTrove/pull/12) → `93d1f17`**
(parents `a1e2e7f` + `94b018c`). Audit post-merge : schéma, FK RESTRICT/SET NULL,
CHECK, unique partiel, index, 3 fonctions / 3 triggers (non internes, actifs, non
deferrable), S3 sans mutation, 0 S4 / 0 cardinalité / 0 fonction de copie, G0 et
P0–P3C préservés, rollback isolé `000009` vert ; suite 133/1882, Pint 106.
Trois fonctions / trois triggers confirmés par introspection : S1
`prevent_order_item_bundle_components_delete`, S2
`enforce_order_item_bundle_component_immutability` (ROW `IS DISTINCT FROM` +
exception FK par `pg_trigger_depth() > 1`, pattern P3C-C), S3
`validate_order_item_bundle_component` (BEFORE INSERT ; vérifié : la définition
ne contient aucune écriture ni affectation à `NEW`). Aucun trigger différé, aucun
S4, aucune contrainte de cardinalité minimale — le bundle vide reste accepté par
la BDD (garde-fou applicatif, point 7). **Durcissement d'implémentation signalé** :
les CHECK `oibc_child_name/slug_not_blank_check` utilisent `btrim(col, E' \t\n\r\f\v')`
et non `btrim/1` (qui ne retire que les espaces) — un snapshot composé uniquement
de tabulations/retours ligne aurait autrement passé le contrôle. Renforcement
strict, plus sévère que le pattern P3B, aucun affaiblissement. Validation :
25 migrations ; P4-A1 **17 tests / 217 assertions** ; suite complète **133 / 1882** ;
Pint **106** ; rollback isolé `000009` vert (P4-A0/G0 et P0–P3C préservés, données
`product_bundles`/`order_items` intactes) ; aucune base temporaire résiduelle.
PR en attente de review ; P4-A2/P4-B/P5 non démarrés.

**Note d'implémentation P4-A0** : la fonction G0 fige aussi `id` (identité de
ligne), en plus des huit colonnes D-029.2 — alignement sur le précédent projet
(les triggers d'immutabilité P3B/P3C figent toujours la clé primaire dans leur
comparaison). Signalé à l'implémentation, aucune décision modifiée. **P4-A0 mergé
via PR #11 → `a047571` (parents `abaea6e` + `8b822c1`)** : fonction G0
`enforce_product_file_content_immutability` + trigger
`product_files_enforce_content_immutability_trigger` BEFORE UPDATE confirmés ;
migration `000008` additive (ni colonne, ni donnée, ni table, ni index modifiés) ;
`original_name`/`position`/`is_active` mutables ; rollback isolé `000008` vert.
IMPACT : bloc P4 du schéma v1 réécrit (gates, G0 durci, table de préservation des
rollbacks), PROGRESS_TRACKER, HANDOFF, CLAUDE.md. Prochaine implémentation :
**P4-A0 uniquement** (aucun snapshot bundle, aucun grant, aucun log dans ce gate).

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
- **P3 Commerce** : P3A Coupons et Paniers mergé via PR #4 (`234e303`, D-024 à
  D-026). P3B Commandes mergé via PR #5 (`f07d225`, D-027). **P3C Paiements,
  Webhooks et Remboursements mergé** via PR #6/#8/#9/#10 conformément à D-028 ; le
  dernier merge P3C-C est `122332a`.
  Les durées d'expiration métier, l'anonymisation invité et la valeur exacte de
  rétention webhook (90 j recommandé) restent à confirmer avant les tranches concernées.
- **Plan P4 (D-029 + D-029.1 + D-029.2)** : ✅ validé (audit B–A–B, puis gates
  isolés et `version` figée par D-029.2). **P4-A0 `000008` mergé via
  [PR #11](https://github.com/mysterus44/DigiTrove/pull/11) → `a047571`** (parents
  `abaea6e` + `8b822c1`) : fonction G0 `enforce_product_file_content_immutability`
  + trigger `product_files_enforce_content_immutability_trigger` confirmés en
  PostgreSQL, suite 116/1666, Pint 102, rollback isolé `000008`. Prochaine étape :
  **P4-A1 `000009` mergé via [PR #12](https://github.com/mysterus44/DigiTrove/pull/12)
  → `93d1f17`** (plan **D-029.3** : Q1=A bundles imbriqués exclus, Q2=A S3 +
  exhaustivité applicative ; 3 fonctions / 3 triggers S1/S2/S3 confirmés, suite
  133/1882, Pint 106, rollback isolé `000009` vert). **P4-A2 `000010` mergé via
  [PR #13](https://github.com/mysterus44/DigiTrove/pull/13) → `77f3766`** (plan
  **D-029.4** option A ; 4 fonctions / 5 triggers G1–G4, G4 différé sur
  `download_grants` ET `orders` ; suite 151/2188, Pint 110, rollback isolé `000010`
   vert). **P4-A2.1 `000011` mergé via
   [PR #14](https://github.com/mysterus44/DigiTrove/pull/14) → `2c25e2a`** :
   correction additive G2/G3 sans modifier `000010`, bénéficiaire null-safe,
   timestamp lié au cycle de vie, rollback exact ; 7/113, suite 158/2301, Pint 112.
   **D-029.5 finalise le plan P4-B** (`p4-b-download-logs`, migration `000012`,
   `download_logs` + G5/G6) ; P4-B a été implémenté (`8cf24a8`) mais **BLOQUÉ au
   merge** par la vulnérabilité d'autorité G2/G5 — **D-029.6** impose le gate
   préalable **P4-B0** (séparation de rôles PostgreSQL + G5 `SECURITY DEFINER`)
   avant tout merge P4-B. TTL grant (72 h),
  quota (5) et rétention logs (365 j) restent des recommandations de CONFIG
  APPLICATIVE (aucun default BDD, D-029.1-B) à fixer à la phase service. La phase
  licences reste une décision produit ouverte (liée à la question `usb` du legacy).

---

### D-029.6 — Frontière de privilèges PostgreSQL runtime (gate P4-B0) ✅
**Date** : 2026-07-19. **Statut** : PLAN FINALISÉ — NON IMPLÉMENTÉ. **P4-B BLOQUÉ**
jusqu'au merge et à la validation de P4-B0.

**Contexte — vulnérabilité prouvée.** L'implémentation P4-B (branche
`p4-b-download-logs`, commit `8cf24a8`) faisait reposer l'autorité de consommation
sur `pg_trigger_depth() > 1` dans G2. Un audit offensif (transactions réelles
terminées par ROLLBACK, rôle `digitrove`) a prouvé que cette condition démontre
seulement l'imbrication, jamais l'origine :

| Vecteur | Profondeur du UPDATE | `downloads_count` | `download_logs` | Résultat |
|---|---:|---:|---:|---|
| UPDATE direct | 1 | 0 → 0 | 0 | refusé 23514 |
| trigger TEMP BEFORE INSERT | 2 | 0 → 1 | 0 | **contournement** |
| trigger TEMP AFTER INSERT | 2 | 0 → 1 | 0 | **contournement** |
| trigger PERMANENT sur `products` | 2 | 0 → 1 | 0 | **contournement** |
| G5 légitime (INSERT started) | 2 | 0 → 1 | 1 | conforme |

L'invariant « aucun incrément sans DownloadLog consommant atomique » est donc
contournable par tout contexte de trigger imbriqué. Le setup actuel aggrave le
risque : le rôle **unique** `digitrove` est **superuser** (rolsuper=t, confirmé),
propriétaire de toutes les tables/fonctions, et sert simultanément de migrateur,
de runtime et d'identité de test (`config/database.php` connexion `pgsql`,
`phpunit.xml`, CI `ci.yml`, `PhaseMigrationHarness`).

**ACL par défaut PostgreSQL 16.14 (mesurées, rôle non privilégié frais)** :
`TEMP = accordé via PUBLIC` (datacl NULL) — c'est le vecteur du trigger temporaire ;
`CREATE ON SCHEMA public = refusé` (défaut durci PG15+, `public` appartient à
`pg_database_owner`) ; `USAGE ON public = accordé` ; `EXECUTE` sur toute fonction
`= accordé à PUBLIC` par défaut ; `UPDATE` de table `= refusé` sans grant explicite.
Point durci confirmé : `REVOKE TEMPORARY ON DATABASE … FROM PUBLIC` ramène TEMP à
refusé. Un privilège `UPDATE` accordé au niveau **table** annulerait toute
révocation par colonne — donc le runtime ne doit jamais recevoir d'UPDATE table.

**Décision humaine (KingKouda)** : **Option A renforcée** — séparation de rôles
PostgreSQL + G5 `SECURITY DEFINER`, dans un gate BDD **préalable P4-B0** mergé
AVANT P4-B. Options B (invariant compteur ≡ nombre de logs) et C (fonction de
consommation unique API) écartées : B est incompatible avec la purge G6 sans
nouveau ledger durable, C impose une refonte plus large du plan D-029.5.

**Trois rôles PostgreSQL cibles.**
1. `digitrove` — **migrateur/propriétaire** : exécute les migrations, possède les
   objets, applique GRANT/REVOKE, attribue la propriété de G5 à l'exécuteur. **Ne
   sert plus d'identité runtime.** En production, PAS superuser ; en local, droits
   administratifs tolérés uniquement pour les migrations/harness, jamais comme
   runtime.
2. `digitrove_runtime` — **runtime** : `LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
   NOREPLICATION NOBYPASSRLS`, aucun membership vers `digitrove` ni l'exécuteur,
   aucune propriété d'objet, aucun DDL, aucun TRIGGER, **aucun TEMP**, **aucun
   UPDATE table-level sur `download_grants`**, **aucun privilège sur
   `download_grants.downloads_count`**. Identité de l'app web, des workers, des
   commandes métier et des sondes d'autorisation.
3. `digitrove_download_executor` — **exécuteur G5** : `NOLOGIN NOSUPERUSER
   NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS`, non accordé au runtime,
   propriétaire de G5 uniquement, privilèges minimaux (SELECT orders/download_grants/
   product_files + UPDATE des seules colonnes `download_grants.downloads_count` et
   `updated_at`). Ne possède aucune table.

**Propriété non forgeable de G5.** G5 devient `SECURITY DEFINER`, **propriétaire =
`digitrove_download_executor`**, avec `search_path` épinglé
`pg_catalog, public, pg_temp` (pg_temp en dernier ; `public` non inscriptible par
le runtime, confirmé) et **tous les objets qualifiés par schéma** (`public.orders`,
`public.download_grants`, `public.download_logs`, `public.product_files`, plus audit
opérateurs/casts/types/séquences). La preuve d'origine principale n'est plus la
profondeur mais **l'identité effective** : sous G5 `SECURITY DEFINER`,
`current_user = digitrove_download_executor`; G2 exige désormais
`current_user = 'digitrove_download_executor'` pour autoriser l'incrément, condition
qu'un trigger forgé (exécuté sous `digitrove_runtime`, SECURITY INVOKER) ne peut
satisfaire. `pg_trigger_depth() > 1` **reste une défense secondaire**, jamais la
preuve principale. Le runtime ne peut ni forger de fonction (ni TEMP ni CREATE
public), ni exécuter/rattacher G5 (EXECUTE révoqué), ni devenir l'exécuteur
(pas de membership), ni obtenir l'UPDATE du compteur.

**Fermeture des ACL implicites** (traitées explicitement, dans la même transaction
que les créations/remplacements) : `REVOKE TEMPORARY ON DATABASE <db> FROM PUBLIC` ;
`REVOKE CREATE ON SCHEMA public FROM PUBLIC` (défense en profondeur — déjà refusé
en PG16, mais versionné) ; `REVOKE ALL ON FUNCTION … FROM PUBLIC` et `FROM
digitrove_runtime` pour G5 (audit de G0/S1–S3/G1–G6) ; `ALTER DEFAULT PRIVILEGES …
REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC` pour les fonctions futures ; aucun
privilège `TRIGGER` au runtime ; runtime non propriétaire de la base et non membre
de `pg_database_owner` (sinon CREATE sur public réapparaît).

**Matrice de privilèges runtime (colonnes).** `download_grants` : SELECT ; UPDATE
**uniquement** sur les colonnes réellement mutées par le runtime (à confirmer sur
le code réel : `revoked_at`, `revoked_reason_code`, `updated_at` pour la révocation
support/refund) ; **JAMAIS** `downloads_count`, `max_downloads`, `token_hash`,
`order_item_id`, `product_file_id`, `user_id`, `expires_at`, `created_at`,
`public_id` ; aucun UPDATE table-level. `download_logs` : SELECT + INSERT + UPDATE
(transitions autorisées par G5) + DELETE (purge contrôlée par G6) — G5/G6 restent
les autorités ; aucun de ces droits n'atteint le compteur du grant. Séquences
associées accordées au strict nécessaire. Jamais de `ALL` au runtime.

**Double connexion Laravel.** Connexion par défaut `pgsql` = identité `runtime`
(app, workers, commandes, sondes) ; connexion `pgsql_migration` = identité
`migrateur` (migrations, provisioning contrôlé, `PhaseMigrationHarness`, tâches
admin). Variables d'environnement : `DB_USERNAME`/`DB_PASSWORD` (runtime) +
`DB_MIGRATION_USERNAME`/`DB_MIGRATION_PASSWORD` (migrateur) — **aucun secret dans
Git**, valeurs en `.env`/secrets CI seulement ; l'exécuteur est NOLOGIN (aucun mot
de passe). `php artisan migrate` cible explicitement `pgsql_migration`. Asymétrie
assumée et documentée pour les tests : la connexion de migration/schéma des tests
reste le migrateur (pour que `RefreshDatabase` puisse migrer et préserver les
suites vertes existantes), tandis que le nouveau **suite P4-B0 et les tests de
frontière de consommation P4-B ouvrent explicitement une connexion `pgsql_runtime`**
pour prouver le refus ; un test d'autorisation ne doit jamais passer sous le
propriétaire (garde-fou d'audit + assertion CI que le runtime est non-superuser).

**Provisioning — deux vecteurs (recommandation argumentée).** (1) **Rôles
cluster-level** (`CREATE ROLE … LOGIN PASSWORD` / `NOLOGIN`) → **script SQL
versionné idempotent** (candidat `docker/postgres/provision-runtime-roles.sql`),
exécuté par un administrateur : les rôles sont cluster-globaux, ne peuvent pas
vivre dans une migration Laravel par-base sans embarquer de mot de passe dans Git,
et `/docker-entrypoint-initdb.d` ne s'exécute qu'à l'initialisation initiale du
volume — le plan prévoit donc aussi une commande de re-provisioning explicite et
idempotente (`CREATE ROLE … IF NOT EXISTS` via bloc `DO`) pour les volumes
existants, sans supprimer de données. (2) **ACL d'objets** (REVOKE TEMP/CREATE/
EXECUTE, GRANT colonnes, ALTER DEFAULT PRIVILEGES, propriété/SECURITY DEFINER de
G5) → **migration Laravel dédiée**, afin que `migrate:fresh` les reproduise dans
toutes les bases (tests, CI, bases temporaires du harness, prod) et qu'elles soient
versionnées avec le schéma. `REVOKE TEMPORARY … FROM PUBLIC` s'écrit sur
`current_database()` via `EXECUTE format('… %I …', current_database())`.

**Impact numérotation.** P4-B0 nécessite une migration ACL réservée
`2026_07_14_000012_harden_database_runtime_privileges.php` ; l'implémentation P4-B
non mergée (`download_logs`, actuellement `000012`) sera **renumérotée en
`000013_create_download_logs_table.php`** au moment de son rebasage sur la stable
durcie, et son G5 passera en `SECURITY DEFINER` (propriétaire exécuteur, search_path
épinglé) avec l'ajout de la vérification d'identité dans G2. **Aucune renumérotation
n'est effectuée dans cette décision** (documentaire). La branche `8cf24a8` reste
inchangée.

**Docker, CI, harness, production.** Docker : provisionner les deux rôles (script +
re-provisioning idempotent), aucun mot de passe en clair dans Git, fonctionner sur
base fraîche ET existante, ne pas dépendre du seul entrypoint init. CI : démarrer
Postgres → provisionner les 3 rôles → migrer avec le migrateur → exécuter app et
sondes de sécurité **sous le runtime** → asserter runtime non-superuser + ACL +
**rejouer la sonde du trigger temporaire et obtenir un refus** → tests de rollback
sous les bons rôles ; les tests P4-B ne valent plus s'ils tournent seulement en
superuser. Harness : provisionner/hériter les rôles cluster, migrer sous migrateur,
exposer un `runtimePdo()` pour les sondes, nettoyer intégralement, ne jamais masquer
un défaut d'ACL en superuser. Production : déploiement sans interruption (backup +
audit ACL → création runtime → création exécuteur NOLOGIN → REVOKE/GRANT → double
connexion → migration migrateur → bascule app vers runtime → sondes santé →
vérifier que l'app n'utilise plus `digitrove` → puis P4-B) ; rollback opérationnel
qui ne redonne jamais SUPERUSER/TEMP/CREATE, ne restaure la connexion migrateur que
sous action explicite d'un opérateur, et conserve les données.

**Threat model (synthèse)** : runtime compromis (ne détient ni UPDATE compteur ni
identité exécuteur) ; credentials migrateur compromis (surface réduite, hors
runtime) ; membership accidentel vers migrateur/exécuteur (interdit, testé) ; TEMP/
CREATE/EXECUTE réaccordés à PUBLIC (revoke versionné + ALTER DEFAULT PRIVILEGES +
assertion CI) ; SECURITY DEFINER à search_path vulnérable (épinglé + objets
qualifiés + public non inscriptible) ; rattachement/forge de G5 (EXECUTE révoqué,
pas de CREATE/TEMP) ; UPDATE table-level réintroduit (interdit, testé) ; CI/harness
en superuser masquant le défaut (sondes obligatoires sous runtime) ; restauration
perdant les ACL (ACL en migration reproduite par migrate:fresh). Chaque menace a
prévention + détection + test + risque résiduel + gate responsable dans le bloc P4
du schéma v1.

**Statut** : `P4-B0 PLANIFIÉ — PRÉREQUIS AU MERGE DE P4-B` ;
`P4-B BLOQUÉ — AUTORITÉ G2/G5 NON ENCORE CORRIGÉE`. Aucun code, rôle, privilège,
migration, script, branche ou test P4-B0 créé dans cette décision.

**Note d'exécution P4-B0** (aucune décision nouvelle) : implémenté sur
`p4-b0-postgresql-runtime-privileges` conformément à D-029.6.
**P4-B0 IMPLÉMENTÉ — EN ATTENTE DE MERGE.**

*Gate de faisabilité (prouvé avant tout code, base temporaire nettoyée, migrateur
non-superuser `rolsuper=f` propriétaire de la base)* : la danse de propriété de G5
fonctionne — `GRANT CREATE ON SCHEMA public TO <executor>` temporaire →
`SET LOCAL ROLE <executor>` → `CREATE FUNCTION … SECURITY DEFINER` → `RESET ROLE`
→ `REVOKE CREATE`, en une transaction, l'exécuteur ne conservant **aucun** CREATE
permanent. Et un trigger `SECURITY DEFINER` **se déclenche même quand le rôle
déclencheur n'a pas EXECUTE** sur la fonction : à l'intérieur,
`session_user = digitrove_runtime` et `current_user = digitrove_download_executor`
— c'est la preuve d'origine non forgeable sur laquelle G2 s'appuiera en P4-B.

*Trois findings d'implémentation qui changent le contrat* :
1. **Le `REVOKE EXECUTE … FROM PUBLIC` doit être exécuté par le PROPRIÉTAIRE de la
   fonction** ; fait par le migrateur non-propriétaire, c'est un no-op silencieux
   (`WARNING: no privileges could be revoked`). Le lockdown de G5 se fera donc
   dans la danse, sous l'identité exécuteur.
2. **L'exécuteur a besoin de `SELECT` en plus de `UPDATE`** : `SET downloads_count
   = downloads_count + 1` *lit* la colonne. Matrice retenue : SELECT sur `orders`,
   `order_items`, `download_grants`, `product_files` + UPDATE des seules colonnes
   `downloads_count` et `updated_at`.
3. **`ALTER DEFAULT PRIVILEGES … REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC` — forme
   globale vs `IN SCHEMA`** (mesuré sur PostgreSQL 16.14, migrateur non-superuser) :
   - la **forme GLOBALE** (sans `IN SCHEMA`) FONCTIONNE : elle enregistre une
     entrée `pg_default_acl` de type `f` à `defaclnamespace = 0` et une nouvelle
     fonction naît avec `proacl = {owner=X/owner}` — **PUBLIC n'a pas EXECUTE, sans
     aucun REVOKE explicite** ;
   - la forme **`IN SCHEMA public`** ne peut PAS retirer le privilège intégré
     global : elle n'enregistre AUCUNE entrée `pg_default_acl` (elle ne sait
     qu'annuler un GRANT par défaut ajouté au niveau schéma), et la fonction
     conserve `=X` (EXECUTE PUBLIC). C'était la cause de l'observation initiale
     erronée (la migration utilisait par erreur `IN SCHEMA public`) ;
   - les défauts s'appliquent au **rôle qui crée réellement** l'objet, sans
     héritage depuis ses memberships → l'exécuteur (créateur de G5) doit avoir SON
     PROPRE défaut global. Un migrateur non-superuser ne peut pas fixer le défaut
     d'un autre rôle directement (`permission denied`) : il passe par
     `SET ROLE digitrove_download_executor` (le chemin SET provisionné) ;
   - les **fonctions existantes** (créées avant `000012`) ne sont pas couvertes par
     un défaut : elles restent protégées par la **boucle de REVOKE explicite** sur
     chaque fonction trigger, exécutée par leur propriétaire.
   La migration `000012` applique donc les DEUX défauts globaux (migrateur +
   exécuteur via SET ROLE) ET la boucle de REVOKE sur l'existant. **Conséquence
   pour P4-B** : une fois PUBLIC privé d'EXECUTE à la naissance, un migrateur
   non-superuser qui attache un trigger à G5 doit recevoir `GRANT EXECUTE` explicite
   sur G5 (le trigger sinon échoue `permission denied`) — pattern prouvé par le
   test P4-B0. Des tests épinglent la forme globale (migrateur ET exécuteur) et la
   présence des deux entrées `pg_default_acl` à `namespace = 0`.

*Livré* : script cluster idempotent `docker/postgres/provision-runtime-roles.sql`
(mot de passe injecté par GUC de session paramétré, jamais en clair ni en
argument), commande `php artisan db:provision-runtime-roles`, migration ACL
`2026_07_14_000012_harden_database_runtime_privileges.php` fail-closed (refuse si
les rôles manquent, ont des attributs dangereux, si le runtime est membre du
migrateur/exécuteur ou si le migrateur n'a pas le chemin SET). ACL appliquées :
`REVOKE TEMPORARY … FROM PUBLIC` + runtime + exécuteur, `REVOKE CREATE ON SCHEMA
public` idem, USAGE/CONNECT explicites, DML runtime en verbes explicites (jamais
`ALL`), `REVOKE UPDATE`/`DELETE` table-level sur `download_grants` puis
`GRANT UPDATE (revoked_at, revoked_reason_code, updated_at)` seulement, grants
minimaux de l'exécuteur, REVOKE EXECUTE sur **toutes les fonctions trigger** de
`public` (extensions comme citext épargnées), et default privileges TABLES/
SEQUENCES pour couvrir `download_logs` en P4-B. **`down()` fail-closed** : retire
les grants runtime/exécuteur mais **ne réouvre jamais** TEMP/CREATE/EXECUTE à
PUBLIC, ne supprime aucun rôle ni donnée.

*Double connexion* : `pgsql` = `digitrove_runtime` (app, workers, suite métier),
`pgsql_migration` = `digitrove` (migrations, provisioning, harness) ;
`DB_MIGRATION_USERNAME`/`DB_MIGRATION_PASSWORD` ; aucun secret dans Git.

*Identité d'exécution des tests* : la suite métier tourne réellement sous
`digitrove_runtime` (`session_user`/`current_user` asserté), les migrations sous
le propriétaire via le trait `RefreshesDatabaseAsMigrator`. Exception assumée et
documentée : **P4-A2 et P4-A2.1 tournent sous le propriétaire**
(`RefreshesDatabaseAsOwner`), car leurs sondes mutent des colonnes que le runtime
ne peut pas atteindre par conception (`downloads_count`, `user_id`, suppression
physique) — sous runtime l'ACL répondrait 42501 *avant* que les triggers G1–G4
puissent répondre 23514, faisant perdre la couverture de la logique trigger. La
preuve complémentaire que le runtime est bien refusé sur ces mêmes opérations vit
dans la suite P4-B0, sous le vrai rôle restreint : aucune des deux couches n'est
validée par le seul superuser. Les fixtures de bases temporaires du harness
utilisent l'identité propriétaire, et `PhaseMigrationHarness` expose désormais
`runtimePdo()` pour les sondes de frontière.

*Validation réelle* : 28 migrations ; suite P4-B0 **13 tests / 84 assertions**
(rôles et memberships, identité runtime effective, TEMP/CREATE/SCHEMA/FUNCTION
refusés, `SET ROLE` refusé, EXECUTE retiré mais triggers existants qui se
déclenchent quand même, matrice de colonnes `download_grants`, **tous les vecteurs
de contournement historiques rejoués sous runtime et refusés en 42501 sans le
moindre incrément**, Query Builder/Eloquent refusés, chemin `SECURITY DEFINER`
prouvé en base isolée, **défauts globaux prouvés : toute fonction future (créée
par le migrateur OU l'exécuteur) naît sans EXECUTE PUBLIC**, propagation des grants
aux tables futures, rollback ACL sans réouverture — y compris fail-closed sur les
défauts de fonctions) ; suite complète **171 tests / 2385 assertions** ; Pint **117
fichiers** ; aucune base temporaire résiduelle. CI durcie : création de la base de
test, provisioning des rôles, puis une étape d'assertion de frontière qui échoue
si le runtime redevient superuser, récupère TEMP/CREATE, un UPDATE table-level,
l'écriture de `downloads_count`, l'EXECUTE des fonctions protégées ou un
membership.

*Limites* : aucune table `download_logs`, aucune fonction G5/G6, aucune migration
`000013`, aucun endpoint/route/contrôleur/service/streaming/P5. La branche P4-B
reste **inchangée à `8cf24a8`** et sa PR ne s'ouvre pas avant le merge de P4-B0 ;
elle devra ensuite être renumérotée `000013` et passer G5 en `SECURITY DEFINER`.

**Clôture P4-B0 (2026-07-20) — `P4-B0 TERMINÉ ET MERGÉ`.** Mergé via
[PR #15](https://github.com/mysterus44/DigiTrove/pull/15), merge
`6d23e5462f9ee786b7e229f6962740be3e7da741` (deux parents : stable `a3eac5e` +
P4-B0 `9b69f192`, sujet « Merge pull request #15 from
mysterus44/p4-b0-postgresql-runtime-privileges »). Périmètre mergé audité
(`a3eac5e..6d23e546`) : seule la migration `000012` ajoutée (`000001`–`000011`
intactes), aucune table `download_logs`, aucun `000013`, aucun G5/G6 réel
(`SECURITY DEFINER` en commentaires seulement, 0 `CREATE FUNCTION`/`CREATE
TRIGGER`), aucun endpoint/service/streaming/P5, aucun secret. Validation
post-merge sur PostgreSQL réel : provisioning `db:provision-runtime-roles`
idempotent (rejoué 2×, aucun secret affiché) ; identités prouvées (migrations
sous `digitrove`, requêtes métier sous `digitrove_runtime`) ; frontière runtime
confirmée par introspection (TEMP/CREATE/CREATE FUNCTION/TRIGGER refusés, pas
d'UPDATE table-level ni `downloads_count`, EXECUTE refusé sur les 26 fonctions
trigger, `SET ROLE` refusé, membership unique `digitrove → executor` SET-only,
défauts fonctions **globaux ns=0** pour migrateur ET exécuteur) ; 28 migrations ;
suite P4-B0 **13/84** ; suite complète **171/2385** ; Pint **117** ; zéro base/rôle
de sonde résiduel. Branche locale P4-B0 supprimée, distante conservée à `9b69f192`,
`origin/main` toujours `11130f4`, P4-B toujours `8cf24a8`. **Prochaine étape :
reprendre P4-B** (rebase sur la stable durcie, renumérotation `000013`, G5
`SECURITY DEFINER` possédée par l'exécuteur avec `GRANT EXECUTE … TO digitrove`
pour l'attachement du trigger, autorité `current_user = digitrove_download_executor`
dans G2, `pg_trigger_depth()` en défense secondaire, tests de contournement sous
runtime). Aucune décision nouvelle.

**Note d'exécution P4-B après P4-B0 (2026-07-20)** — aucune décision nouvelle,
application conjointe de D-029.5 (contrat Download Logs) et D-029.6 (frontière de
privilèges). **`P4-B ADAPTÉ APRÈS P4-B0 — EN ATTENTE DE MERGE`**, `P4-B0 TERMINÉ
ET MERGÉ` conservé.

*Intégration* : la stable `d7c53cf` a été intégrée dans `p4-b-download-logs` par
**merge** `fca10d9` (parents `8cf24a8` + `d7c53cf`) — jamais de rebase, aucun
force-push, aucun commit publié amendé. Conflits limités aux 5 documents (résolus
en conservant la version stable post-P4-B0) ; les 11 suites historiques ont
fusionné automatiquement ; migrations `000001`–`000011` identiques à la stable.

*Renumérotation* : `git mv` de `000012_create_download_logs_table.php` vers
**`000013_create_download_logs_table.php`**. Ordre final `000011` P4-A2.1 →
`000012` P4-B0 (ACL) → `000013` P4-B ; **29 migrations** ; aucune seconde
`000012`.

*Préconditions fail-closed de `000013`* : rôles `digitrove_runtime` et
`digitrove_download_executor` présents, exécuteur NOLOGIN non-superuser, runtime
non-superuser sans membership vers migrateur/exécuteur, migrateur détenant le
chemin `SET ROLE` vers l'exécuteur, runtime sans TEMP, sans CREATE sur `public` et
sans UPDATE sur `downloads_count`, défauts globaux de fonctions présents pour les
deux rôles. Aucune table, fonction, trigger ni index n'est créé si la frontière
P4-B0 n'est pas en force ; `000013` ne corrige jamais P4-B0.

*G5 sécurisée* : `enforce_download_logs_integrity` devient `SECURITY DEFINER`
**possédée par `digitrove_download_executor`**, créée par la danse prouvée (GRANT
CREATE temporaire → `SET ROLE` exécuteur → `CREATE FUNCTION` → `GRANT EXECUTE` au
migrateur juste le temps d'attacher le trigger → `RESET ROLE` → `REVOKE CREATE` →
`CREATE TRIGGER` → retour sous l'exécuteur pour révoquer EXECUTE au migrateur,
à PUBLIC et au runtime). `search_path` épinglé `pg_catalog, public, pg_temp`,
objets qualifiés `public.orders`/`public.order_items`/`public.download_grants`/
`public.product_files`. ACL finale mesurée :
`{digitrove_download_executor=X/digitrove_download_executor}` — ni PUBLIC ni
runtime ; l'exécuteur ne conserve aucun CREATE. G6 reste possédée par le
migrateur, SECURITY INVOKER, sans EXECUTE PUBLIC/runtime (défauts globaux), et son
trigger se déclenche quand même.

*Autorité G2 non forgeable* : l'exact `downloads_count + 1` n'est accepté que si
**`current_user = 'digitrove_download_executor'` ET `pg_trigger_depth() > 1`** ;
l'identité effective est l'autorité principale, la profondeur une simple défense
secondaire (l'ancienne condition `pg_trigger_depth() <= 1` a disparu). Toutes les
protections P4-A2/P4-A2.1 sont conservées verbatim. **Preuve décisive** : un
trigger forgé par le **propriétaire superuser** à profondeur 2 est refusé en
`23514` — l'ancienne G2 l'aurait accepté ; le runtime est arrêté encore plus tôt,
en `42501`, sans jamais atteindre G2. Aucun GUC secret, marqueur de session ni
quatrième rôle n'a été introduit.

*Finding d'implémentation (mesuré, PG 16.14)* : `SELECT … FOR UPDATE OF orders`
exige un **privilège de verrou** — un GRANT `SELECT` seul est refusé, et même
`FOR KEY SHARE` l'est ; un **UPDATE de colonne** suffit. La migration accorde donc
`GRANT UPDATE (updated_at) ON orders TO digitrove_download_executor`, le
privilège minimal permettant à G5 de tenir l'ordre de verrouillage Order → Grant.
G5 n'écrit jamais `orders`, et le trigger d'immutabilité des commandes reste en
défense.

*ACL `download_logs`* : PUBLIC révoqué ; runtime `SELECT, INSERT, UPDATE, DELETE`
+ `USAGE, SELECT` sur la séquence, **sans** TRIGGER, TRUNCATE, REFERENCES ni
ownership ; l'exécuteur ne reçoit **rien** sur la table (G5 ne lit/écrit que
grants, orders, order_items et product_files).

*Identités de test* : la suite P4-B tourne sous `digitrove_runtime` pour les
chemins métier et offensifs. Les sondes internes qui doivent atteindre G2/G1
seedent leur propre grant dans une **transaction propriétaire** annulée — une
connexion séparée ne voit pas la transaction de test du runtime — ce qui préserve
la double couverture : ACL sous runtime (42501) et triggers sous propriétaire
(23514). Le test de rollback vide s'arrête à `000012` (la frontière que `down()`
doit restaurer).

*Rollbacks* : table non vide → refus fail-closed avant toute destruction (table,
lignes, G5/G6, G2 version P4-B, ACL et compteur conservés). Table vide → triggers
puis G6 supprimés par le migrateur, **G5 supprimée sous l'identité exécuteur**
(seul son propriétaire le peut), grant de verrou révoqué, **G2 restaurée OCTET
POUR OCTET à son état post-`000012`**, table et séquence supprimées ; `000012`
reste appliquée et la frontière P4-B0 intacte (TEMP/CREATE/EXECUTE toujours
fermés).

*Validation réelle* : 29 migrations ; `download_logs` à 15 colonnes ; 6 fonctions
/ 7 triggers du domaine P4 ; G4 toujours différé ; G2 unique et non dupliquée ;
suite **P4-B 19 tests / 603 assertions** ; **suite complète 190 / 2975** ;
**Pint 121** ; `git diff --check` propre ; zéro base temporaire résiduelle ; aucun
objet P5. Aucun endpoint, route, contrôleur, service, streaming, listener, job ni
e-mail créé.

**Clôture P4-B (2026-07-21) — `P4-B TERMINÉ ET MERGÉ`.** Mergé via
[PR #16](https://github.com/mysterus44/DigiTrove/pull/16), merge
`98441014b60e26dc81de5abaf4c207fefc0e52c5` (deux parents : stable
`d7c53cf798e75c17ea705e821dee4c18310cdff0` + P4-B
`49692e25e5b27e851f2d3b565c68731d079b7a77`, sujet « Merge pull request #16 from
mysterus44/p4-b-download-logs »). **Le schéma P4 Livraison est complet** :
P4-A0 → P4-A1 → P4-A2 → P4-A2.1 → P4-B0 → P4-B, 29 migrations.

Périmètre mergé audité (`d7c53cf..98441014`) : **seule la migration `000013`**
côté migrations (`000001`–`000012` inchangées, P4-B0 intégralement préservé),
plus le modèle `DownloadLog`, sa factory, la relation `DownloadGrant`, les suites
de tests adaptées et les 5 documents. Aucun endpoint, contrôleur, service,
streaming, secret ni objet P5.

Validation post-merge sur PostgreSQL réel : provisioning
`db:provision-runtime-roles` idempotent (rejoué 2×, aucun secret affiché) ;
identités prouvées (migrations sous `digitrove`, métier sous
`digitrove_runtime`) ; `download_logs` à **15 colonnes** sans `updated_at`, 1 FK
RESTRICT, 10 CHECK, 1 unique, 6 index ; **G5 possédée par
`digitrove_download_executor`, `SECURITY DEFINER`, `search_path=pg_catalog,
public, pg_temp`**, ACL `{executor=X/executor}`, objets tous qualifiés et
exécuteur sans CREATE permanent ; **G6** possédée par le migrateur, SECURITY
INVOKER, sans EXECUTE PUBLIC/runtime, trigger actif ; **G2 unique** avec autorité
principale `current_user = 'digitrove_download_executor'` et profondeur en défense
secondaire, protections P4-A2/P4-A2.1 conservées ; **6 fonctions / 7 triggers P4**
et G4 toujours `DEFERRABLE INITIALLY DEFERRED`. ACL runtime : DML complet sur
`download_logs` mais **sans** TRIGGER/TRUNCATE/ownership, **sans**
`downloads_count`, **sans** TEMP ni CREATE, **sans** EXECUTE G5/G6 ni `SET ROLE`
sensible. Compteurs : **P4-B 19 tests / 603 assertions**, **suite complète
190 / 2975**, **Pint 121**, `git diff --check` propre, zéro base ou rôle
temporaire résiduel.

**La vulnérabilité historique est fermée et prouvée fermée** : sous le vrai rôle
runtime tous les vecteurs (UPDATE direct, Query Builder, Eloquent, CREATE
TEMP/TABLE/FUNCTION/TRIGGER, rattachement de G5, `SET ROLE`) échouent en `42501` ;
et un trigger arbitraire forgé par le **propriétaire superuser** à profondeur > 1
est refusé par G2 en `23514` faute d'être l'exécuteur — la sécurité ne repose donc
plus sur la profondeur de trigger ni sur les seules ACL runtime.

Clôture : branche locale `p4-b-download-logs` supprimée (était `49692e2`),
distante conservée à `49692e25`, `origin/main` toujours `11130f4`. Aucune décision
nouvelle. **Prochaine étape à lire dans le roadmap** (couche applicative P4 —
listener, service, contrôleur, streaming, rate limiting, purge, e-mails — ou P5
analytique) ; elle n'est pas commencée.

---

### D-030 — Roadmap applicative Commerce → Livraison sécurisée ✅
**Date** : 2026-07-21. **Statut** : **FINALISÉE ET VALIDÉE** (KingKouda : Q1=A
renforcée, Q2=B renforcée, Q3=A). **Aucun code applicatif écrit par cette
décision.**

**CONTEXTE.** Le schéma relationnel P1→P4 est complet et mergé (29 migrations,
dernière `000013`, merge P4-B `98441014`). L'audit applicatif P4-C0 mené sur la
stable `50d043ec` a établi par lecture du code réel que **la couche applicative
est intégralement absente** : les répertoires `app/Services/`, `app/Actions/`,
`app/Events/`, `app/Listeners/`, `app/Jobs/`, `app/Notifications/`, `app/Mail/`,
`app/Policies/`, `app/Support/`, `app/Http/Requests/` et `app/Http/Middleware/`
n'existent pas ; `app/Http/Controllers/` ne contient que la classe abstraite
`Controller.php` ; `routes/web.php` ne déclare que la page d'accueil ;
`AppServiceProvider` et `withMiddleware()` sont vides ; la seule commande Artisan
est `ProvisionRuntimeRoles` (infrastructure P4-B0). Confirmé nominativement :
`OrderService`, `OrderPaid`, `IssueDownloadGrants`, `DownloadService` et
`DownloadController` sont **ABSENTS, sans alias ni équivalent sous un autre nom**.

**1. CORRECTION DE NOMMAGE (contraignante).** Le rapport d'audit nommait le
premier gate `P4-C1 — Pricing & Money kernel`. **Ce nom est incorrect** :
tarification, checkout et paiement relèvent de **P3 Commerce** ; P4 Livraison ne
commence qu'à l'émission des grants. Nommage officiel figé :

| Gate | Nom | Branche future |
|---|---|---|
| **P3-D1** | Pricing & Quote Kernel | `p3-d1-pricing-kernel` |
| **P3-D2** | Checkout Order Transaction | `p3-d2-checkout-order-transaction` |
| **P3-D3** | Payment Initiation | `p3-d3-payment-initiation` |
| **P3-D4** | Server-side Payment Confirmation | `p3-d4-payment-confirmation` |
| **P3-D5** | OrderPaid Domain Event | `p3-d5-order-paid-event` |
| **P4-C0** | Queue & Mail Secret Safety | `p4-c0-queue-mail-secret-safety` |
| **P4-C1** | Download Grant Issuance | `p4-c1-grant-issuance` |
| **P4-C2** | Refund Grant Revocation | `p4-c2-refund-grant-revocation` |
| **P4-C3** | Secure Secret Delivery Job | `p4-c3-secret-delivery-job` |
| **P4-C4** | Download Authorization | `p4-c4-download-authorization` |
| **P4-C5** | HTTP File Delivery | `p4-c5-http-file-delivery` |
| **P4-C6** | Delivery Operations | `p4-c6-delivery-operations` |

Discipline D-029.2 reconduite : **une branche par gate, un objectif par gate,
merge du gate N avant l'ouverture du gate N+1**, jamais de push sur `main`. Aucun
de ces gates ne crée de migration ; `licenses` reste exclu ; **P5 reste
entièrement non démarré**.

**2. Q1 = A RENFORCÉE — Token perdu après le COMMIT du grant.** Le token de
`download_grants` est généré par CSPRNG, existe **uniquement en mémoire vive**,
est haché en SHA-256 avant persistance, et n'est **jamais** reconstructible.
Rejetées définitivement : secret déterministe dérivé d'une clé serveur ; outbox
contenant le secret chiffré ; stockage temporaire du token brut ; toute
possibilité de retrouver le secret depuis la BDD. Motif : DigiTrove vend le
catalogue lui-même ; une clé maître compromise régénérerait tous les tokens
actifs, exactement le scénario que D-009 interdit (« exactement comme un mot de
passe » — un mot de passe n'est pas dérivable).
*Sémantique de panne acceptée* : si une panne survient après le COMMIT du grant
mais avant confirmation fiable de la remise, le token peut être perdu et le grant
actif devient inutilisable par le client. Il ne doit **jamais** être « retrouvé ».
Toute reprise suit la séquence stricte : (1) verrouiller l'Order ; (2) révoquer
les grants actifs concernés avec un motif fermé (candidat `delivery_retry`, code
exact à confirmer au gate P4-C1/P4-C3) ; (3) émettre de nouveaux grants ; (4)
générer de nouveaux tokens ; (5) remettre uniquement les nouveaux tokens. La
règle est **« révoquer puis réémettre »**, jamais « réessayer avec l'ancien
token ». Cette contrainte n'est pas seulement doctrinale : l'unique partiel
`download_grants_active_pair_unique (order_item_id, product_file_id) WHERE
revoked_at IS NULL` **empêche physiquement** une nouvelle émission tant que le
grant fantôme n'est pas révoqué (finding 2 de D-029.4).
*Risque résiduel assumé et documenté honnêtement* : si l'e-mail a été envoyé mais
que le worker meurt avant l'ACK, le retry peut révoquer le premier lien ; le
client peut recevoir un premier e-mail devenu invalide, suivi d'un second
contenant le lien actif. Cette sémantique est **at-least-once**. Aucun
exactly-once externe n'est promis.
*Récupération client* : hors du premier gate et hors P4-C1. Lorsqu'elle arrivera,
elle devra : ne jamais afficher un secret après simple saisie d'un numéro de
commande ; envoyer la nouvelle livraison à l'e-mail de la commande ; être
rate-limitée ; être protégée contre l'énumération ; révoquer puis réémettre ;
utiliser l'authentification du compte lorsqu'elle existe.

**3. Q2 = B RENFORCÉE — Queue et e-mail.** La livraison passe par **un job queued
unique par Order, dont le payload ne transporte que `order_id`**. Le payload ne
doit contenir aucun token de grant, secret de tentative, hash de token, lien de
téléchargement complet, `storage_path`, IP, clé HMAC, ni contenu d'e-mail porteur
du lien.
*Chaîne retenue* : (1) `OrderPaid` dispatché **après COMMIT** de la transition
effective vers `paid` ; (2) le listener programme un job unique par Order ; (3) le
job transporte `order_id` seul ; (4) le worker verrouille l'Order ; (5) le worker
génère les tokens en mémoire ; (6) le worker crée les grants — ou révoque puis
réémet lors d'un retry ; (7) le worker compose et envoie l'e-mail **de manière
synchrone dans le même processus** ; (8) le token disparaît de la mémoire.
*Interdits* : notification elle-même mise en queue avec le token ; Mailable queued
portant le token ; job enfant portant le token ; événement portant le token ;
token dans Redis, dans `jobs`, dans `failed_jobs`, dans une exception ou un log.
*Idempotence et concurrence* : unicité par `order_id`, prévention de chevauchement
par `order_id`, verrou PostgreSQL sur l'Order, invariants uniques de
`download_grants`, réémission contrôlée lors d'un retry réel. Un **nouveau
dispatch après succès** ne doit pas envoyer silencieusement un nouveau jeu de
liens : il détecte les grants actifs et se termine sans effet, sauf procédure de
réémission explicitement demandée. Un **retry après échec de remise** peut
révoquer les grants actifs de la tentative précédente, émettre de nouveaux
secrets et envoyer un nouveau message.
*Le transport Laravel n'est pas exactly-once* et ne doit jamais être présenté
comme tel : seule l'idempotence garantie par les invariants BDD fait foi.

**4. CORRECTION FACTUELLE — configuration de la queue.** Le rapport d'audit P4-C0
affirmait que le driver d'échec par défaut était `file`. **C'est faux** : `file`
n'est qu'un override de `.env.example` (`QUEUE_FAILED_DRIVER=file`). État réel
mesuré dans `config/queue.php` :

| Point | Valeur réelle | Ligne |
|---|---|---|
| connexion par défaut | `env('QUEUE_CONNECTION', 'database')` | L16 |
| `database.after_commit` | `false` | L44 |
| `redis.after_commit` | `false` | L73 |
| `beanstalkd` / `sqs` `after_commit` | `false` | L53 / L64 |
| driver d'échec par défaut | **`env('QUEUE_FAILED_DRIVER', 'database-uuids')`** | L124 |
| table d'échec attendue | `failed_jobs` | L126 |
| batching | table `job_batches`, base `env('DB_CONNECTION', 'sqlite')` | L105–107 |

Il n'existe **aucune migration `jobs`, `job_batches` ni `failed_jobs`** dans les
29 migrations. Conséquences : le défaut `database` n'est **pas opérationnel** ; le
défaut `database-uuids` n'est **pas opérationnel** ; `.env.example` masque
partiellement le problème en choisissant Redis ; `after_commit = false` est
dangereux pour la livraison (un job programmé dans une transaction peut être
consommé avant son COMMIT, et lire un Order qui n'est pas encore `paid`) ; et
`phpunit.xml` fixant `QUEUE_CONNECTION=sync` ne prouve **jamais** la sérialisation
réelle du payload.

**5. P4-C0 — Queue & Mail Secret Safety (gate préalable obligatoire).** Aucun job
de livraison ne peut être mergé avant lui. Objectif unique : **rendre
l'infrastructure asynchrone sûre avant qu'un secret de téléchargement existe**.
Périmètre futur : configuration de queue explicite ; Redis comme backend prévu du
projet si confirmé par l'environnement existant (`docker-compose` expose Redis 7
sur l'hôte `6380`) ; **`after_commit = true` sur la connexion de livraison** ;
stratégie de failed jobs sans token ; worker et politique de retry ; **tests de
sérialisation réelle sur une connexion non-`sync`** ; garde empêchant un mailer de
journaliser un token ; configuration mail sûre ; vérification que
`MAIL_MAILER=log` ne peut pas être utilisé dans un environnement émettant de vrais
liens.
**Sous-point explicitement LAISSÉ OUVERT, à trancher au gate P4-C0 sur le code
réel** : le stockage des failed jobs — (a) `failed_jobs` en base via la migration
Laravel standard, (b) failed jobs désactivés (`QUEUE_FAILED_DRIVER=null`) avec
alerte externe, ou (c) autre stockage sécurisé. Ce point n'est **pas** tranché
ici : il dépend d'une évaluation de ce que le job de livraison expose réellement
en cas d'échec. Aucun fichier de configuration n'est créé par la présente
décision documentaire.

**6. Q3 = A — Coupon scopé et allocation de la remise.**
*Coupon sans ligne éligible* : si un coupon scopé par `coupon_products` ou
`coupon_categories` ne couvre aucune ligne du panier, **le checkout est refusé par
une erreur de validation explicite**. Le coupon n'est jamais retiré
silencieusement ; la commande n'est jamais créée au plein tarif sans consentement
explicite du client. Motif : le retrait silencieux produit la classe de litige la
plus coûteuse en e-commerce (« mon code n'a pas été appliqué et vous m'avez quand
même débité »).
*Allocation* : méthode du **plus grand reste (Hamilton)**, contractuellement —
(1) déterminer uniquement les lignes éligibles ; (2) calculer le montant global de
remise sur leur sous-total ; (3) appliquer le pourcentage en basis points ou la
remise fixe de la devise, puis le plafond `coupon_currency_rules.max_discount_minor`
et le plafond naturel du sous-total éligible ; (4) calculer la part rationnelle de
chaque ligne ; (5) affecter la partie entière à chaque ligne ; (6) distribuer le
reste **unité par unité** aux plus grands résidus ; (7) départager de façon stable.
*Ordre de départage contractuel* : résidu décroissant, puis `product_id`
croissant, puis identifiant stable de ligne croissant si nécessaire.
*Garanties* : aucun `float`, aucune division flottante, aucun `round()` sur les
montants ; `line_discount_minor >= 0` ; `line_discount_minor <=
line_subtotal_minor` ; **somme des remises de lignes exactement égale à
`orders.discount_minor`** ; algorithme déterministe au rejeu ; résultat
indépendant de l'ordre de chargement de la collection.
*Quantité multiple* : l'allocation se fait au niveau de la ligne ;
`line_subtotal_minor = unit_price_minor * quantity` ; aucune distribution par
unité physique n'est nécessaire dans ce gate.
*Fondement mesuré* : le constraint trigger différé `validate_order_items_consistency`
(migration `000002`) exige au COMMIT `SUM(line_subtotal_minor) =
orders.subtotal_minor`, **`SUM(line_discount_minor) = orders.discount_minor`** et
`SUM(line_total_minor) + orders.tax_minor = orders.total_minor` ; et
`orders_coupon_snapshot_consistency_check` exige `discount_minor > 0` dès qu'un
snapshot coupon existe. Une remise non allouée fait donc échouer le COMMIT en
`23514`. `cart_items` ne portant **aucun prix** (`id, cart_id, product_id,
quantity, timestamps`), la tarification est un prérequis arithmétique dur du
checkout, et non un détail interne.

**7. CONSOMMATION DU COUPON — correction figée.** La création d'une Order
`pending` stocke les snapshots du coupon dans `orders`, **n'insère pas**
`coupon_redemptions` et **n'incrémente pas** `coupons.redemptions_count`
(D-027/5). La consommation effective du quota intervient lors de la confirmation
serveur du paiement, ou du passage légitime d'une commande gratuite
(`total_minor = 0`) à `paid` : elle verrouille le coupon, revalide les limites,
crée `coupon_redemptions`, incrémente `redemptions_count`, et **participe à la
même transaction que la transition finale vers `paid`**. Un panier abandonné ou
une Order `pending` ne consomme jamais le quota. Vérifié : aucun trigger ne
maintient `coupons.redemptions_count` (aucune occurrence dans `000003`) — le
plafond global et le plafond par client (`(coupon_id, customer_key_version,
customer_key_hash)`) sont **100 % applicatifs**, sous `FOR UPDATE`.

**8. ROADMAP FINALE.**

```text
P3-D1 Pricing & Quote Kernel
    ↓
P3-D2 Checkout Order Transaction
    ↓
P3-D3 Payment Initiation
    ↓
P3-D4 Server-side Payment Confirmation
    ↓
P3-D5 OrderPaid Domain Event
    ↓
P4-C0 Queue & Mail Secret Safety
    ↓
P4-C1 Download Grant Issuance
    ├──────────────┐
    ↓              ↓
P4-C2 Refund       P4-C3 Secure Secret Delivery Job
Grant Revocation        ↓
                   P4-C4 Download Authorization
                        ↓
                   P4-C5 HTTP File Delivery
                        ↓
                   P4-C6 Delivery Operations
```

Règles d'ordonnancement : **P4-C2 doit être mergé avant l'activation réelle de
P4-C3** ; **P4-C1 peut être implémenté comme service non câblé** tant que P4-C2 et
P4-C3 ne sont pas prêts (aucun listener branché, aucune émission déclenchée par un
événement réel) ; **aucun téléchargement public n'existe avant P4-C5** ; P5 ne
commence pas pendant cette roadmap.
*Justification de la contrainte P4-C2 avant P4-C3* : G4
(`validate_download_grant_order_consistency`) est monté **DEFERRABLE INITIALLY
DEFERRED sur `download_grants` ET sur `orders`**. Dès qu'un grant actif existe,
toute transition d'`orders` vers `refunded` échoue au COMMIT si les grants ne sont
pas révoqués dans la même transaction. Livrer l'émission en production sans la
révocation rendrait donc **les remboursements totaux impossibles**. Ce n'est pas
une amélioration optionnelle : c'est un couplage dur imposé par le schéma.

**9. PREMIER GATE OFFICIEL — `P3-D1 — Pricing & Quote Kernel`**, branche future
`p3-d1-pricing-kernel`. Il **remplace** le nom incorrect « P4-C1 — Pricing & Money
kernel » du rapport d'audit.
*Périmètre* : Money value object ; quote immuable ; résolution de prix fixe par
devise depuis `product_prices` ; snapshots produit nécessaires au futur OrderItem ;
validation du coupon ; calcul de la remise globale ; allocation Hamilton.
*Exclusions strictes* : zéro écriture BDD, zéro Order créée, zéro route, zéro
paiement, zéro grant, zéro migration.
*Sortie attendue* : `PricedQuote { currency, subtotalMinor, discountMinor,
taxMinor, totalMinor, lines[], couponSnapshot|null }`, chaque ligne portant
`product_id`, `product_name_snapshot`, `product_slug_snapshot`,
`product_type_snapshot`, `unit_price_minor`, `quantity`, `line_subtotal_minor`,
`line_discount_minor`, `line_total_minor`.
*Invariants préparés pour P3-D2* : `line_subtotal_minor = unit_price_minor *
quantity` ; `line_total_minor = line_subtotal_minor - line_discount_minor` ;
`Σ line_subtotal_minor = subtotalMinor` ; `Σ line_discount_minor = discountMinor` ;
`Σ line_total_minor + taxMinor = totalMinor` ; `discountMinor <= subtotalMinor` ;
devise unique en majuscules ; `discountMinor > 0 ⟺ couponSnapshot != null`.
*Sécurité* : aucun secret manipulé ; interdiction absolue de `float`/`round()` sur
des montants ; le prix ne provient **jamais** d'une entrée client, uniquement de
`product_prices` ; un produit sans prix dans la devise demandée est un **refus**,
jamais un repli sur une autre devise (D-018 : conversion automatique reportée).
*Matrice de tests* : arithmétique entière pure ; produit direct ; bundle ; devise
absente ⇒ refus ; coupon `percent` avec `max_discount_minor` ; coupon `fixed` par
devise ; `min_order_minor` non atteint ; coupon scopé sans ligne éligible ⇒ refus
explicite ; **Σ remises de lignes == remise Order sur restes non divisibles** ;
départage stable prouvé sur résidus égaux ; coupon expiré / inactif ; absence de
`float` dans le code.
*Nom exact des classes* : à confirmer par lecture des conventions au moment du
gate. **Aucune décision métier ne reste ouverte pour ce gate.**

**10. DETTE — `SECURITE_TELECHARGEMENT.md` PARTIELLEMENT PÉRIMÉ.** Le skill n'est
**pas modifié** par la présente décision (seuls cinq documents sont autorisés),
mais il ne doit **plus servir de modèle de code**. Points périmés mesurés :
UPDATE direct de `downloads_count` depuis PHP (refusé `42501` pour le runtime,
`23514` pour le propriétaire depuis D-029.6) ; création simplifiée de DownloadLog ;
colonne `grant_id` inexistante (la colonne réelle est `download_grant_id`) ;
colonnes P4-B obligatoires absentes (`public_id`, `quota_consumed`,
`retention_until`, tous NOT NULL sans DEFAULT) ; absence de la notion de tentative
authentifiée ; absence de G5 ; absence de la frontière PostgreSQL runtime.
**Action obligatoire consignée à l'époque : réécrire
`SECURITE_TELECHARGEMENT.md` avant le gate P4-C4**, réaligné sur D-029.5 et
D-029.6. **Action fermée par D-036 le 2026-07-24.**

**11. DETTES RECONNUES ET LEUR GATE.** Consignées sans correction dans cette
mission :

| # | Dette mesurée | Gate responsable |
|---|---|---|
| 1 | `DOWNLOAD_LINK_TTL_HOURS` / `DOWNLOAD_MAX_PER_GRANT` présents dans `.env.example` mais **lus par aucun fichier `config/`** | ✅ fermée par D-036 : retirés ; valeurs `DELIVERY_*` bornées |
| 2 | queue par défaut `database` **sans table `jobs`/`job_batches`** | **P4-C0** |
| 3 | failed jobs par défaut `database-uuids` **sans table `failed_jobs`** | **P4-C0** (sous-point ouvert) |
| 4 | `after_commit = false` sur toutes les connexions de queue | **P4-C0** |
| 5 | `MAIL_MAILER` par défaut `log` ⇒ une URL avec token brut serait écrite dans `storage/logs` | **P4-C0** |
| 6 | `phpunit.xml` force `QUEUE_CONNECTION=sync` ⇒ la sérialisation réelle n'est jamais prouvée | **P4-C0** |
| 7 | enums `DownloadLogStatus` et `DownloadDenialReasonCode` prévus par D-029.5 mais **absents** de `app/Enums/` | **P4-C4** |
| 8 | `coupons.redemptions_count` et les plafonds coupon sont **entièrement applicatifs** (aucun trigger) | **P3-D4** |
| 9 | aucun `Money` value object alors que `LARAVEL_PATTERNS.md` le prescrit | **P3-D1** |
| 10 | G4 rend la révocation **obligatoire** au remboursement total, sans quoi les refunds deviennent impossibles | **P4-C2** |
| 11 | `SECURITE_TELECHARGEMENT.md` périmé et dangereux à copier | **avant P4-C4** |

**ALTERNATIVES REJETÉES** : nommer le gate de tarification « P4-* » (la
tarification, le checkout et le paiement relèvent de P3 Commerce) ; démarrer la
couche applicative par `IssueDownloadGrants` ou `DownloadController` (leurs
prérequis n'existent pas) ; construire `OrderService` avant le noyau de
tarification (l'allocation entière de la remise serait enfouie dans une
transaction, bien plus coûteuse à tester) ; consommer le coupon à la création de
la commande (D-027/5) ; secret dérivé ou outbox chiffrée (Q1) ; token brut
traversant la queue (Q2) ; retrait silencieux d'un coupon inapplicable (Q3) ;
fusionner résolution, tentative, streaming, rate limiting et purge dans un
`DownloadService` unique ; créer la table `events` ou toute logique P5.

**IMPACT** : `DECISIONS_LOG.md` (cette décision), `DigiTrove_Schema_BDD_v1.md`
(bloc roadmap applicative), `PROGRESS_TRACKER.md` (sections P3-D et P4-C),
`HANDOFF.md` (état + prochaine tâche + journal), `CLAUDE.md` (état résumé).
**Aucun fichier PHP, migration, test, route, service, job, event, listener,
notification, config, script SQL, rôle PostgreSQL ni branche n'est créé par cette
décision.** Migrations `000001`–`000013` inchangées ; aucune `000014` ;
`origin/main` intact. **Prochaine tâche : implémenter `P3-D1 — Pricing & Quote
Kernel` sur la branche `p3-d1-pricing-kernel`.**

**Note d'exécution P3-D1** (aucune décision nouvelle) : gate **`P3-D1 — Pricing &
Quote Kernel`** implémenté sur la branche `p3-d1-pricing-kernel`, créée depuis la
stable exacte `ba48cce1`. **AUCUNE migration** (29 migrations inchangées, aucune
`000014`), aucune route, aucun contrôleur, aucun paiement, aucun grant, aucune
écriture BDD.

*Classes livrées (9)* : `App\Support\IntegerMath` (multiply/add/subtract avec
refus `OverflowException` — PHP promeut silencieusement un dépassement d'entier
en float, ce qui détruirait un montant exact) ; `App\Support\Money`
(`final readonly`, montant `int` seul, devise canonique `^[A-Z]{3}$`, addition/
soustraction/comparaison **uniquement à devise identique**, aucune conversion) ;
`App\Services\Pricing\{PricingService, DiscountAllocator, PricedQuote, PricedLine,
CouponSnapshot, PricingException, PricingRefusalReason}`. Aucun repository
abstrait, aucune interface sans seconde implémentation, aucun bus, aucun event,
aucun listener, aucun DTO générique.

*`declare(strict_types=1)` introduit sur les fichiers du gate* — nouveauté dans le
dépôt, justifiée et bornée : sans lui, `Money::of(1.5, 'XOF')` serait coercé en
`1` (dépréciation silencieuse PHP 8.3), exactement la corruption monétaire que
D-005 interdit. Pint (preset `laravel`) reste vert.

*Résolution des prix* : lecture exclusive de `product_prices` sur
`(product_id, currency)` avec `is_active = true`. **Aucun repli de devise**
(D-018), aucun prix issu du panier (`cart_items` n'en porte aucun, D-024/1) ni de
l'appelant. Produit **fail-closed** : `status` doit valoir `published` et un
produit soft-deleted n'est pas chargé du tout ; `draft` et `archived` sont
refusés. Cette lecture stricte de l'enum `ProductStatus` est conservatrice
(refuse plus, ne sur-autorise jamais) et devra être reconfirmée au gate P3-D2.

*Coupons* : fenêtre `starts_at`/`ends_at` **inclusive aux deux bornes** ;
`min_order_minor` mesuré sur le **sous-total du panier entier** (la colonne est un
plancher de commande) tandis que la base de remise reste le **sous-total
éligible** ; portée produit et portée catégorie combinées en **UNION** (jamais
une intersection) ; un coupon `fixed` sans règle utilisable dans la devise
demandée est refusé ; plafonds `max_discount_minor` puis sous-total éligible ;
remise nulle refusée (`orders_coupon_snapshot_consistency_check` interdit un
snapshot coupon avec `discount_minor = 0`). **Q3 = A appliqué** : un coupon scopé
sans ligne éligible produit un refus explicite `CouponNotApplicable`, jamais un
retrait silencieux.

*Hamilton* : `numeratorᵢ = D × sᵢ`, `baseᵢ = intdiv(numeratorᵢ, S)`,
`remainderᵢ = numeratorᵢ % S`, puis distribution unité par unité du reste selon
**résidu décroissant → `product_id` croissant → id de ligne croissant**. Garde
défensive supplémentaire : une ligne n'absorbe jamais plus que son propre
sous-total (protège une ligne gratuite). Aucun `float`, aucune division
flottante, aucun `round()` — audit statique confirmé : les seules occurrences de
`float`/`round(` dans les fichiers du gate sont des **commentaires**.

*`taxMinor` explicitement `0`* : P3-D1 ne définit **aucune** politique fiscale,
ne crée aucun `TaxService` ni configuration fiscale, et ne revendique aucune
conformité. Toute fiscalité future exigera une décision dédiée.

*Aucune consommation de coupon* : ni `coupon_redemptions`, ni incrément de
`coupons.redemptions_count`, ni réservation de quota — cela reste P3-D4 (D-027,
point 5). Prouvé par test : compteurs et lignes inchangés après tarification.

*Tests réels* : **Unit 36 tests / 55 assertions** (aucun accès PostgreSQL —
`IntegerMath`, `Money`, `DiscountAllocator`) et **Feature 48 tests /
167 assertions** sur PostgreSQL réel, migrations sous le migrateur et requêtes
sous **`digitrove_runtime`** (identité prouvée dans le test). Absence d'écriture
prouvée deux fois : capture du journal SQL (aucun `insert|update|delete|truncate|
merge`, aucun `FOR UPDATE`) **et** comparaison octet à octet des tables
`carts`/`cart_items`/`coupons`/`products`/`product_prices` avant/après. Absence
de N+1 prouvée par `Model::preventLazyLoading()` **et** par une comparaison de
volumétrie (panier de 2 vs 8 lignes → nombre de requêtes identique), sans figer
un nombre fragile.

*Adaptation historique nécessaire* : le garde-fou de périmètre de
`P4BDownloadLogsTest` assertait `app/Services` inexistant. P3-D1 crée
légitimement `app/Services/Pricing`. L'assertion est **restreinte, pas
supprimée** : `app/Listeners` et `app/Jobs` restent prouvés absents, et aucun
espace de noms de service ne peut correspondre à `download|delivery|grant`.
Suite P4-B : 19 tests / **612** assertions (était 603).

*Validation* : suite complète **274 tests / 3206 assertions** (base 190/2975) ;
Pint **132 fichiers** ; `git diff --check` propre ; 29 migrations inchangées ;
P3A 15/139, P3B 18/357, Catalogue 12/111 verts. **Exclusions confirmées** :
aucune Order, aucun `CouponRedemption`, aucun Payment, aucun DownloadGrant,
aucun DownloadLog, aucune config queue/mail, aucun P4-C, aucun P5.

*Statut* : **mergé** via [PR #17](https://github.com/mysterus44/DigiTrove/pull/17),
merge `78f475e750b0e060fd38c44d6733844807683ff9` (parents
`ba48cce18833535f5cc226ecdd822ece3bf2e409` + `95ab5627337ceaa18bc4eb47c19b359e2542e843`),
CI run #18 `success`. **Le merge a précédé la revue contradictoire prévue** ; un
audit post-merge a donc été conduit et a démontré quatre défauts de contrat
défensif — voir la note P3-D1.1 ci-dessous. Périmètre mergé audité : exactement
**17 fichiers**, aucune migration, route, contrôleur, Request, config, modèle,
factory, secret, P4-C ni P5.

**Note d'exécution P3-D1.1 — Hardening post-merge** (aucune décision nouvelle,
périmètre A1–A4 validé par KingKouda) : branche `p3-d1-post-merge-hardening`
créée depuis `78f475e7`. **Aucune migration, aucune politique métier modifiée.**

*Audit post-merge contradictoire* — quatre anomalies démontrées par sondes
exécutées (jamais par raisonnement seul), toutes de **défense en profondeur** :
le calcul de prix lui-même était correct et aucune corruption monétaire n'était
possible via `PricingService::quote()`.

* **A1 — devise acceptant un saut de ligne final.** `Money::of(100, "XOF\n")`
  était **accepté** : en PCRE, `$` matche aussi juste avant un saut de ligne
  final, donc `/^[A-Z]{3}$/` ne tenait pas son contrat annoncé. Impact réel nul
  (fail-closed en aval : aucune ligne `product_prices` trouvée, et
  `orders.currency VARCHAR(3)` aurait rejeté), mais erreur BDD obscure au lieu
  d'un refus propre en P3-D2. **Correctif** : ancres absolues `/\A[A-Z]{3}\z/`
  + `Money::assertValidCurrency()` devenue **source unique** du contrat, réutilisée
  par `PricedQuote`.
* **A2 — garde-fou P4-B affaibli.** Le rétrécissement effectué pendant P3-D1
  (pour laisser passer `app/Services/Pricing`) laissait passer un service de
  livraison sous un namespace neutre : sonde avec fichiers réels
  `app/Services/Fulfilment/{GrantIssuer,DeliveryManager}.php` → **test PASSANT**.
  La regex de mots-clés ne les couvrait pas et la garde de namespace ne testait
  que le basename du sous-dossier. **Correctif** : garde **fail-closed** par
  **allowlist exacte** des 7 fichiers P3-D1 sous `app/Services`, chemins relatifs
  normalisés (`\` → `/`), récursive, indépendante de l'OS, nommant les intrus ;
  plus un test synthétique (sans créer de fichier) couvrant `GrantIssuer`,
  `DeliveryManager`, `StreamManager`, `RateLimiter`, `DownloadService` et un
  `Pricing/UnexpectedService.php` inattendu. Chaque gate futur devra **élargir
  explicitement** cette allowlist — c'est l'intérêt d'une frontière historique.
* **A3 — DTO de pricing sans aucun invariant.** `PricedLine`, `PricedQuote` et
  `CouponSnapshot` acceptaient des états incohérents (remise > sous-total,
  quantité négative, snapshots vides, total incohérent, `lines` vide, snapshot
  coupon avec remise nulle, devise `'zzz'`). Impact nul aujourd'hui — le seul
  producteur, `PricingService::assemble()`, validait déjà — mais **P3-D2 est le
  consommateur** et pouvait les construire. **Correctif** : invariants dans les
  constructeurs, miroir exact des CHECK `order_items`/`orders`
  (`line_subtotal = unit × qty`, `line_total = subtotal − discount`,
  `Σ lignes = commande`, `total = subtotal − discount + tax`, `taxMinor === 0`,
  snapshot coupon ⟺ remise > 0, types via `ProductType`/`CouponDiscountType`),
  toute l'arithmétique passant par `IntegerMath`.
* **A4 — identifiants de ligne dupliqués écrasés en silence.** Deux parts
  partageant un `line_id` s'écrasaient sur la même clé : `allocate(10, …)`
  retournait `{"7":5}`, **somme 5 ≠ 10**, sans exception — une mauvaise réponse
  silencieuse sur le chemin monétaire. Inatteignable via `PricingService`
  (`cart_items.id` est une PK), mais la classe est publique et son contrat
  promettait une somme exacte. **Correctif** : refus explicite des `line_id`
  dupliqués, `line_id < 1` et `product_id < 1`.

*Vérifié inchangé* : `IntegerMath` (17 cas limites, dont `PHP_INT_MIN × -1`,
`-1 × PHP_INT_MIN`, `MAX+1`, `MIN-1` — tous refusés, aucun montant valide refusé
à tort) ; l'immutabilité profonde de `PricedQuote` (les quatre tentatives de
mutation, y compris imbriquée, échouent ; copie de tableau sans aliasing) ;
Hamilton (999 lignes / D=998 → `sum=998, max=1, min=0`, prouvant ≤ +1 par ligne
et une boucle en O(n)) ; ligne gratuite jamais remisée ; toutes les politiques
métier validées (union produit ∪ catégorie, `min_order_minor` sur panier total,
remise sur sous-total éligible, `published` seul, aucun repli de devise, aucune
consommation de coupon).

*Auto-audit post-correctif* : A1 → `"XOF\n"`, `"XOF\r\n"`, `"XOF "`, `" XOF"`,
`"XOF\t"`, `"XOF\0"`, `"xof"` tous refusés ; A2 → sonde avec fichiers réels
`Fulfilment/{GrantIssuer,DeliveryManager}.php` désormais **refusée**, l'allowlist
nommant précisément les deux intrus ; A3 → aucune incohérence constructible ;
A4 → duplication refusée avant toute allocation. Aucun faux positif sur les
7 classes autorisées, `PricingService` produit toujours une quote valide, aucun
scénario légitime ne lève d'exception supplémentaire. Sondes supprimées, worktree
propre, aucun résidu.

*Validation* : Unit **94 tests / 117 assertions** (était 36/55), Feature P3-D1
**48/167** inchangée, P4-B **20/616** (était 19/612), P3A 15/139, P3B 18/357,
Catalogue 12/111 ; suite complète **333 tests / 3272 assertions** (était
274/3206) ; Pint **132** ; `git diff --check` propre ; **29 migrations
inchangées**, aucune `000014` ; PostgreSQL et Redis healthy ; aucune base
temporaire résiduelle.

*Statut* : **P3-D1 ET P3-D1.1 TERMINÉS ET MERGÉS.** P3-D1.1 mergé via
[PR #18](https://github.com/mysterus44/DigiTrove/pull/18), merge
`0e18d69d7216b87118bc024e697cc5629c561846` (parents
`78f475e750b0e060fd38c44d6733844807683ff9` +
`6349fc19b70858ef4bc0186e4adf2687742fd8c4`), CI run #19 `success`.

*Clôture post-merge P3-D1.1* : périmètre mergé audité = **exactement 12 fichiers
modifiés** (5 classes, 2 suites de tests, 5 documents), **aucun ajout ni
suppression** ; aucune migration, route, contrôleur, Request, modèle, factory,
config, `OrderService`, checkout, paiement, event, listener, job, P4-C ni P5.
Relecture du code sur la stable : `Money::CURRENTY_PATTERN` vaut bien
`'/\A[A-Z]{3}\z/'` et `Money::assertValidCurrency()` est réutilisée par
`PricedQuote` (aucune normalisation silencieuse) ; l'allowlist
`P4B_ALLOWED_SERVICE_FILES` liste les 7 fichiers `Pricing/*` et la comparaison
est récursive sur chemins normalisés via `array_diff`, `app/Jobs` et
`app/Listeners` restant prouvés absents ; les trois DTO lèvent sur chaque
invariant, toute l'arithmétique passant par `IntegerMath`, `taxMinor !== 0`
refusé, snapshot coupon ⟺ remise positive, `lines` immuable ; `DiscountAllocator`
refuse `line_id` dupliqué, `line_id < 1` et `product_id < 1` avant toute
allocation. Auto-audit rejoué sur la stable : `"XOF\n"`/`"XOF\r\n"`/`"\nXOF"`/
`"xof"`/`"XOFF"` refusés, `'XOF'` accepté ; les 7 constructions incohérentes
refusées ; duplication refusée ; Hamilton inchangé (999 lignes / D=998 →
`sum=998, max=1, min=0`) ; ligne gratuite jamais remisée ; immutabilité profonde
intacte. Validation post-merge : Unit **94/117**, Feature P3-D1 **48/167**, P4-B
**20/616**, P3A **15/139**, P3B **18/357**, Catalogue **12/111**, suite complète
**333/3272**, Pint **132**, `git diff --check` propre, **29 migrations
inchangées**, aucune `000014`, PostgreSQL 16 et Redis 7 `healthy`, aucune base
temporaire résiduelle, **aucune politique métier modifiée**. Branches locales
`p3-d1-pricing-kernel` et `p3-d1-post-merge-hardening` supprimées, distantes
conservées à `95ab5627` et `6349fc19`, `origin/main` intact `11130f4d`. Aucun
code P3-D2, P4-C ou P5 créé.

---

### D-031 — Contrat transactionnel P3-D2 (Checkout Order Transaction) ✅
**Date** : 2026-07-21. **Statut** : **P3-D2 IMPLÉMENTÉ — EN ATTENTE DE MERGE**
(branche `p3-d2-checkout-order-transaction`, depuis `5d07abad`). **Aucune
migration** (29 inchangées). Décisions humaines : **Q1 = C**, **Q2 = B**.

1. **Q1 = C — composant de bundle soft-deleted ⇒ checkout REFUSÉ.** Aucun
   filtrage silencieux, aucun snapshot partiel. Motif : S3 ne lit pas
   `products.deleted_at` (vérifié par `pg_get_functiondef`) et P4-A1 ne stocke ni
   en-tête ni compteur — un snapshot partiel est **structurellement
   indétectable** (D-029.3/5). Un incident catalogue doit échouer bruyamment au
   checkout plutôt que produire une sous-livraison définitive et invisible.
   Refus : `BundleComponentUnavailable`. Bundle sans composant :
   `BundleEmpty`, refusé **avant** toute écriture.
2. **Q2 = B — le Cart passe à `converted` dans la MÊME transaction** que
   l'Order, après que toutes les écritures de commande sont valides. Aucun
   vidage, aucune suppression, aucun nouveau panier. Sur rollback le Cart reste
   `active` ; sur rejeu idempotent il n'est **pas reconverti**.
3. **Idempotence.** Clé brute opaque, `/\A[A-Za-z0-9._-]{32,255}\z/`, **jamais
   persistée ni loguée** ; seul `hash('sha256', clé)` entre dans
   `checkout_idempotency_hash`. Le rejeu est résolu **après** le verrou du Cart
   mais **avant** toute règle d'état (un panier converti est l'état normal après
   un premier appel réussi). Rejeu identique ⇒ Order existante retournée, sans
   rien réécrire — l'**Order**, pas le panier éventuellement muté, est la vérité
   autoritative. Le schéma ne stockant aucun fingerprint, l'égalité est prouvée
   par **comparaison des colonnes** `cart_id`, acteur, `currency`, `coupon_id`,
   `customer_email` : divergence ⇒ `IdempotencyConflict`. Même Cart + autre clé
   ⇒ `CartAlreadyCheckedOut`, jamais un faux rejeu. Backstop PostgreSQL :
   `orders_cart_id_unique` (23505), distinct de
   `orders_checkout_idempotency_hash_unique` et de
   `orders_order_number_unique` — **aucun 23505 n'est classé « idempotence » par
   défaut**, chaque contrainte est traduite séparément.
4. **Order gratuite (`total_minor = 0`) reste `pending`.** Prouvé accepté par le
   schéma (sonde : `SET CONSTRAINTS ALL IMMEDIATE` vert). Le passage à `paid`
   appartient à P3-D4. **Aucune ligne `payments` dans ce gate.**
5. **Aucune consommation de coupon.** P3-D2 valide via `PricingService` et copie
   le `CouponSnapshot` dans `orders` ; ni `coupon_redemptions`, ni incrément de
   `redemptions_count`, ni réservation de quota (D-027 point 5). Une Order
   `pending` **ne réserve rien** : P3-D4 revalide sous verrou et peut refuser.
6. **Aucun Payment, event, listener, job, route, contrôleur, Request, grant ni
   log.** Le coupon est résolu depuis `carts.coupon_id` (D-024/6), jamais fourni
   par l'appelant.

**Ordre de verrouillage** (déterministe, anti-deadlock) : `carts` par
`public_id` `FOR UPDATE` → `products` du panier par `id` croissant
(`withTrashed`) → par bundle croissant : `product_bundles` par
`child_product_id` puis **produits enfants par `id` croissant**. Le verrou des
**enfants** est ce qui rend Q1=C réellement applicable : un soft-delete
concurrent doit attendre la fin de la transaction (**prouvé : `55P03`**).
`product_prices` n'est pas verrouillé — la quote et les `order_items`
proviennent d'une **lecture unique** de `PricingService` dans la transaction.

**Propriété du panier** : compte ⇒ `cart.user_id === acteur->id` ; invité ⇒
`cart.visitor_id === acteur->id` **et** `cart.user_id IS NULL`. Panier
inexistant et panier d'autrui produisent le **même** refus `CartUnavailable`
(anti-énumération de `carts.public_id`). E-mail : autoritatif depuis le compte,
exigé et validé pour un invité.

**Snapshot bundle** : après création de l'`order_item` parent, **un seul
`INSERT … SELECT`** par bundle depuis `product_bundles` (un statement = un
instantané, exhaustif par construction, D-029.3/3), **sans filtre** — un
composant soft-deleted a déjà provoqué le refus. Le nombre de lignes inséré est
comparé au compte mesuré sous verrou ; toute divergence lève
`BundleSnapshotMismatch` et rollback total.

**Fichiers livrés (3)** : `App\Services\Checkout\{OrderService,
CheckoutException, CheckoutRefusalReason}`. Aucun repository, interface, DTO
générique, bus, event, listener, job, contrôleur ni route.
`P4B_ALLOWED_SERVICE_FILES` élargie de **exactement** ces trois chemins, sans
wildcard ; un test prouve que `Checkout/UnexpectedService.php` reste refusé.

**Validation** : P3-D2 **47 tests / 171 assertions** ; P4-B **20/619** ; P3-D1
Unit 94/117, Feature 48/167 ; P3B 18/357 ; P3A 15/139 ; Catalogue 12/111 ;
suite complète **380 / 3446** (base 333/3272) ; Pint **136** ;
`git diff --check` propre ; 29 migrations inchangées. Concurrence prouvée sur
**bases jetables + deux connexions PDO réelles** (pattern P4-A1) : soft-delete
concurrent d'un composant bloqué (`55P03`), deux checkouts du même panier
sérialisés (`55P03`), seconde commande pour le même panier refusée par
`orders_cart_id_unique` (`23505`).

**ALTERNATIVES REJETÉES** : filtrer silencieusement un composant soft-deleted
(Q1=A) ; l'inclure au snapshot (Q1=B) ; laisser le panier `active` (Q2=A) ou
déléguer la conversion à P3-D4 (Q2=C) ; accepter un `PricedQuote` ou un montant
du client ; recopier la tarification dans `OrderService` ; verrouiller
`product_prices` ou le catalogue entier ; traiter tout `23505` comme un rejeu ;
un seam de test dans le service pour les tests de concurrence.

---

### D-032 — Expiration des commandes `pending` ✅
**Date** : 2026-07-21. **Statut** : appliquée sur `p3-d2-checkout-order-transaction`
(P3-D2 toujours **EN ATTENTE DE MERGE**). **Aucune migration.**

**CONTEXTE** : la revue pré-publication de P3-D2 a relevé que
`orders.expires_at` était calculé avec une constante `CART_TTL_MINUTES = 30`
codée en dur dans `OrderService`. `orders.expires_at` est `NOT NULL` **sans
DEFAULT** (aucune politique commerciale en base, D-029.1-B) et D-024 ne
mentionne les 30 minutes que comme **recommandation non figée** : la constante
était donc une décision commerciale implicite. Toute mention antérieure
laissant croire que ces 30 minutes étaient déjà arbitrées est **corrigée par la
présente décision**.

**CHOIX** : `expires_at = placed_at + durée configurée`. Défaut **30 minutes**,
**configurable sans modification du code** via `config/checkout.php`
(`pending_ttl_minutes`, alimenté par `CHECKOUT_PENDING_TTL_MINUTES`). Unité :
**minutes entières**, minimum `1`, plafond `525 600` (une année — borne de
sûreté Carbon/PHP). Une valeur absente retombe sur `30`. Une valeur configurée
**invalide provoque un échec explicite AVANT toute écriture métier**, classé
`IntegrityFailure` — c'est un incident serveur, **jamais une faute du client**.
`OrderService` ne lit jamais `env()` directement et n'accepte aucune durée
depuis la requête de checkout. **Le rejeu idempotent conserve l'`expires_at`
d'origine** et ne recalcule rien. P3-D3 pourra exploiter cette échéance mais ne
devra pas la redéfinir silencieusement.
*Validation stricte* : `is_int` (booléen exclu) ou chaîne `/\A[1-9][0-9]*\z/`.
Sont refusés `0`, négatif, décimal, `'1.5'`, `'abc'`, `''`, `'0'`, `' 30'`,
`true`, `false`, `null`, tableau, `30.5` et toute valeur au-delà du plafond
(13 cas couverts par test).

**CORRECTIF ASSOCIÉ — retry `order_number` en transaction PostgreSQL avortée.**
La même revue a demandé de vérifier empiriquement le retry de collision. **Le
défaut était réel** : le retry se faisait par `try/catch` **sans savepoint**
dans la transaction de checkout. Reproduction sous `digitrove_runtime` :
`INSERT` → `23505 orders_order_number_unique` → toute commande suivante de la
même transaction reçoit *« current transaction is aborted, commands ignored »*
(**25P02**) — le retry était donc **non fonctionnel** et dégradait en
`IntegrityFailure` avec un message trompeur. La même sonde avec
`SAVEPOINT` / `ROLLBACK TO SAVEPOINT` réussit la seconde tentative et laisse la
transaction utilisable (2 lignes visibles). **Correctif** : l'INSERT susceptible
de collision est enveloppé dans une **transaction Laravel imbriquée**, qui émet
un véritable `SAVEPOINT` PostgreSQL et y revient sur exception — le verrou du
Cart et tout le travail antérieur sont préservés. Maximum **3 essais**, puis
`IntegrityFailure`. Seule la contrainte `orders_order_number_unique` est
retentée ; `orders_cart_id_unique` et
`orders_checkout_idempotency_hash_unique` restent traduites distinctement.

**Primitive extraite** : `App\Support\OrderNumberGenerator` (alphabet Crockford
sans I/L/O/U, suffixe CSPRNG, format `DGT-YYYY-XXXXXXXXXX`). Résolue par le
conteneur et **non `final`** : le numérotage est une vraie primitive métier
qu'un gate ultérieur peut faire varier, et c'est ce qui permet d'exercer le
chemin de collision **de bout en bout sans seam de test dans la signature
publique de `checkout()`**. Elle vit sous `app/Support`, donc l'allowlist P4-B
(`app/Services` uniquement) est inchangée.

**Hachage d'idempotence — audité, inchangé** : clé brute jamais stockée, jamais
loguée, jamais placée dans une exception (test dédié) ; digest exactement
64 caractères hexadécimaux. SHA-256 est documenté comme **identifiant
d'idempotence**, pas comme mécanisme d'authentification ; toute évolution de
cette stratégie exigerait une décision séparée.

**Validation** : P3-D2 **66 tests / 308 assertions** (était 47/171) ; suite
complète **399 / 3584** (était 380/3446) ; Pint **138** ; `git diff --check`
propre ; **29 migrations inchangées**, aucune `000014`. Non-régressions P3-D2
confirmées : propriété User/Visitor, refus anti-énumération, idempotence et ses
quatre variantes de conflit, bundle vide, composant soft-deleted, snapshot
exhaustif, Cart converti, rollback vers Cart `active`, Order gratuite `pending`,
aucune consommation de coupon, aucun Payment, aucun P4-C.

**ALTERNATIVES REJETÉES** : laisser la constante en dur ; lire `env()` dans le
service ; accepter la durée depuis la requête ; tolérer une valeur invalide en
retombant silencieusement sur 30 ; recalculer `expires_at` au rejeu ; rollbacker
toute la transaction pour retenter (perte du verrou du Cart) ; traiter tout
`23505` comme une collision de numéro ; ajouter un callback de test au service.

---

### D-033 — Initiation de paiement en deux phases (P3-D3) ✅
**Date** : 2026-07-22 (implémentation) · **clôturée le 2026-07-23**. **Statut** :
**P3-D3 TERMINÉ, MERGÉ ET VALIDÉ** —
[PR #22](https://github.com/mysterus44/DigiTrove/pull/22), head
`5188e6cc5c82358cdc1e72efe3e8e3345babf930`, merge
`70379a02e1f220e6dac4f53552b8a5712c6e4815` (parents `6e701a1e` + `5188e6cc`),
**CI #26 success**, périmètre exact **14 fichiers (+2005/-7)**. **Aucune
migration** (29 inchangées). **Aucun adaptateur fournisseur réel, aucun secret,
aucun appel HTTP.** Les décisions de durcissement ci-dessous sont **figées**.

**CONTEXTE** : le schéma P3C-A `payments` (`000004`) est mergé — uniques
`payments_idempotency_key_hash_unique`, `payments_order_id_attempt_number_unique`,
`payments_provider_reference_unique` (partiel), `one_succeeded_per_order`,
`one_requires_review_per_order` ; trigger BEFORE INSERT
`validate_payment_order_amount` (montant = `orders.total_minor`, devise =
`orders.currency`, refus d'une commande gratuite en `23514`) ; trigger
d'immutabilité (identité commerciale figée, `provider_payment_reference`
NULL→valeur une seule fois puis figée, dates de cycle figées, machine à états
T5A) ; constraint trigger différé bidirectionnel de cohérence. P3-D2.1 impose
`App\Support\PostgresConstraintViolation` pour toute classification de
contrainte. Ces uniques protègent le digest et le numéro de tentative ; la
règle « une seule tentative vivante » est **applicative**, sérialisée par le
verrou Order — jamais présentée comme un invariant PostgreSQL.

**CHOIX** :
1. **Deux phases.** Le Payment `pending` est créé et **committé avant** l'appel
   externe ; le fournisseur est appelé **hors de toute transaction** ; la
   référence est finalisée dans une **seconde transaction**. Aucun verrou Order
   n'est conservé pendant la latence réseau.
2. **Aucun appel réseau sous transaction** — invariant dur du gate.
3. **`payments.public_id` = clé d'idempotence fournisseur** : publique, non
   secrète, **stable pour tous les rejeux** de la même tentative. Le contrat du
   port exige qu'une réinvocation avec le même `public_id` soit idempotente
   côté adaptateur.
4. **Clé brute appelant hachée uniquement** (`hash('sha256', clé)` →
   `idempotency_key_hash`), `#[SensitiveParameter]`, **jamais** persistée,
   loguée, mise dans une exception ni **envoyée au fournisseur** (ni la clé, ni
   le digest).
5. **Reprise ambiguë sur la même ligne et la même clé** : après un timeout, le
   Payment reste `pending`, `provider_payment_reference` NULL, aucune tentative
   compensatoire ; le rejeu de la même clé reprend la même ligne et renvoie le
   même `public_id` au fournisseur.
6. **Une seule tentative `pending|processing` vivante par Order** : une nouvelle
   clé est refusée (`PaymentAlreadyInProgress`) tant qu'une tentative est vivante ;
   le client doit rejouer la clé d'origine.
7. **Nouvelles tentatives uniquement après `failed|cancelled|expired`** ;
   `requires_review` et `succeeded` sortent l'Order de `pending` et bloquent
   toute nouvelle initiation (`OrderNotPayable`). `attempt_number` = MAX + 1 sous
   verrou Order.
8. **Timeout ambigu ⇒ tentative laissée `pending`** : P3-D3 ne marque jamais
   `failed` automatiquement (le fournisseur a pu accepter la requête) — la
   qualification est P3-D4.
9. **Référence fournisseur finalisée en seconde transaction** (Order FOR UPDATE
   puis Payment FOR UPDATE) : NULL→valeur enregistrée ; valeur identique ⇒ rejeu
   idempotent ; valeur différente ⇒ `ProviderReferenceConflict`, l'existant
   **jamais écrasé** ; collision inter-Payments `23505 /
   payments_provider_reference_unique` ⇒ `IntegrityFailure` générique via
   `PostgresConstraintViolation`.
10. **Aucune confirmation** : aucun `succeeded`, aucun `orders.status = paid`,
    aucun `coupon_redemptions`, aucun incrément de `redemptions_count`, aucun
    `payment_webhook_events`, aucun refund, aucun `OrderPaid`, aucun
    DownloadGrant. L'Order reste `pending`.
11. **Aucun adaptateur réel dans ce gate** : port `PaymentProvider` abstrait
    (`app/Contracts/Payments`), fournisseur factice déterministe dans les tests.

**AUTORITÉ ET SÉCURITÉ** : source unique `orders`, jamais `carts`/`cart_items`/
`product_prices`. Montant, devise et e-mail viennent de l'Order sous verrou ; la
signature `initiate(User|Visitor, orderPublicId, idempotencyKey, ?at)`
**n'expose** aucun montant/devise/e-mail client. Refus **uniforme**
`OrderUnavailable` pour une Order inexistante comme pour celle d'autrui
(anti-énumération de `orders.public_id`). Création encapsulée dans une
transaction Laravel imbriquée (vrai `SAVEPOINT`) : un `23505 /
payments_idempotency_key_hash_unique` concurrent est rattrapé et rejoué,
`payments_order_id_attempt_number_unique` et tout le reste → `IntegrityFailure`.
`provider_metadata` **n'est jamais persisté** ; les instructions client
éphémères ne vivent qu'en mémoire dans le DTO de retour.

**FICHIERS LIVRÉS (7)** : `App\Contracts\Payments\{PaymentProvider,
ProviderInitiationRequest, ProviderInitiationResult}` et
`App\Services\Payments\{PaymentInitiationService, PaymentInitiationException,
PaymentInitiationRefusalReason, InitiatedPayment}`. `app/Contracts` n'est pas
scanné par le garde-fou P4-B ; l'allowlist est élargie de **exactement** les
4 fichiers `Services/Payments/*`.

**VALIDATION (après durcissement)** : P3-D3 **54 tests / 246 assertions** (suite
non transactionnelle, requêtes sous `digitrove_runtime`, connexions
indépendantes réelles) ; audit statique propre — plus aucun `isFuture` ni
classification par substring sous `Services/Payments`, les deux seules
occurrences résiduelles (`$now = $at ?? now()` unique, `throw $exception`
re-lançant une `PaymentInitiationException`) sont légitimes ; Pint **149** ;
29 migrations inchangées. Concurrence service-level prouvée : `55P03`
sérialisation + `PaymentAlreadyInProgress`, `IdempotencyConflict` + backstop
`23505 / payments_idempotency_key_hash_unique`, collision de référence via
finalisation ⇒ `IntegrityFailure` (backstop index `23505 /
payments_provider_reference_unique`).

**VALIDATION POST-MERGE (clôture 2026-07-23, stable `70379a0`)** : synchronisation
fast-forward de la stable sur le merge `70379a02`, deux parents prouvés
(`6e701a1e` + `5188e6cc`), quatre commits de la PR présents (`cb2313d`, `59313c2`,
`6554af1`, `5188e6c`), périmètre exact **14 fichiers (+2005/-7)** sans migration,
route, contrôleur, webhook, adaptateur réel ni secret. Suites rejouées
séquentiellement sous les vraies identités : P3-D3 **54/246**, P3-D2.1 **20/20**,
P3-D2 **71/344**, P3C-A **16/219**, P4-B **20/628**, **suite complète 478/3894**,
Pint **149**, `git diff --check` propre, **29 migrations** (aucune `000014`).

**DURCISSEMENT PRÉ-MERGE (revue contradictoire, 5 findings fermés)** :
1. **`initiate()` refuse tout contexte transactionnel ambiant** — la toute
   première vérification exige `DB::transactionLevel() === 0` (sinon
   `IntegrityFailure`, aucune lecture/écriture, aucun appel fournisseur). La
   phase fournisseur ne peut donc s'exécuter que hors de toute transaction. La
   suite P3-D3 tourne désormais **sans transaction enveloppante** (concern dédié
   `InteractsWithPaymentsDatabase` : migration sous le migrateur, TRUNCATE par le
   migrateur seul, requêtes métier sous `digitrove_runtime`, déconnexion en
   teardown pour ne pas épuiser le pool) — aucune garde contournée en test,
   `RefreshesDatabaseAsMigrator` et `PhaseMigrationHarness` inchangés.
2. **Éligibilité sur horloge injectée unique** : `$now` est résolu une seule fois
   (`$at ?? now()`) et passé explicitement à `resolveReplay()` ; l'ancien
   `expires_at->isFuture()` (qui relisait l'horloge réelle) est supprimé. Prédicat
   unique partagé `isExpired()` : **expirée ssi `$now >= expires_at`** — même
   frontière pour l'appel initial, le rejeu et la décision de rappel fournisseur.
3. **Reprise d'une réponse perdue** : un rejeu payable (même clé, même Order,
   même fournisseur, Payment `pending|processing`, Order `pending` non expirée)
   **rappelle le fournisseur avec le même `payment.public_id` MÊME si une
   référence est déjà enregistrée**, afin de récupérer les instructions client
   éphémères perdues après un crash post-finalisation. La phase 3 reste
   idempotente : référence identique ⇒ succès sans écrasement, différente ⇒
   `ProviderReferenceConflict`.
4. **Aucune exception BDD/Eloquent brute** : `initiate()` enveloppe tout le
   flux ; une `PaymentInitiationException` est relancée telle quelle, **toute
   autre `Throwable` (QueryException, PDOException, ModelNotFound, message de
   trigger, SQLSTATE `23514`/`40001`/`55P03`…) devient `IntegrityFailure`
   sanitizé**. `name()` du port qui lève ⇒ `ProviderUnavailable`. `finalise()`
   ne relance plus d'exception brute. `PostgresConstraintViolation` reste
   cantonné à `23505` + nom exact (recouvrement du digest).
5. **Preuves C1–C4 service-level** : E0 prouve `transactionLevel = 0` au moment
   de l'appel fournisseur + visibilité du Payment `pending` depuis une
   **connexion `digitrove_runtime` indépendante** ; C1 verrou `55P03` puis
   `PaymentAlreadyInProgress` du service ; C3 `IdempotencyConflict` du service +
   backstop d'index `23505` ; C4 collision de référence via le **vrai chemin de
   finalisation** ⇒ `IntegrityFailure` sans fuite. Tests de classification
   défensive ajoutés (message usurpant un nom de contrainte ⇒ jamais classé sans
   `23505`). Helper `$orderRef` mort supprimé.

**ALTERNATIVES REJETÉES** : appel fournisseur sous transaction (verrou tenu
pendant la latence) ; laisser l'appelant enrober `initiate()` dans une
transaction ; horloge réelle au rejeu ; ne pas rappeler le fournisseur au rejeu
(instructions perdues irrécupérables) ; laisser sortir une exception BDD brute ;
envoyer la clé brute ou le digest au fournisseur ; plusieurs tentatives vivantes
simultanées ; marquer `failed` sur timeout ambigu ; écraser une référence
existante ; persister `provider_metadata` ou les instructions client ; classer
une exception fournisseur par texte ; adaptateur PowerPay réel ou secret dans ce
gate ; preuves de concurrence purement SQL sans le chemin réel du service.

### D-034 — Confirmation serveur atomique et événement OrderPaid (P3-D4 + P3-D5) ✅
**Date** : 2026-07-23. **Statut** : **P3-D4 + P3-D5 TERMINÉS, MERGÉS ET
VALIDÉS** — [PR #23](https://github.com/mysterus44/DigiTrove/pull/23), head
`8aad4fc`, merge `a62563fdb8aad86bef5cf1ac27b4bebcb5259342` (parents `0b9e7ac` +
`8aad4fc`), **CI #27 success** (branche `p3-d4-d5-payment-confirmation`,
**macro-gate unique**, **aucune migration** — 29 inchangées). Validation
post-merge sur la stable `a62563f` : P3D4 **52**, P3D5 **7**, P4-B **20/649**,
Pint **175**, 29 migrations, aucune `000014`. CinetPay est l'unique adaptateur
réel, **désactivé par défaut** ; PowerPay reste un scaffold documentaire sans
endpoint inventé. Décisions ci-dessous **figées**.

**DÉCISIONS FIGÉES**
1. **Le corps du webhook n'est jamais autoritatif** — même signé, il ne sert
   qu'à déclencher une contre-vérification serveur.
2. **Signature HMAC obligatoire avant tout lien avec un Payment** : `x-token`
   reconstruit dans l'ordre officiel des 16 champs, `hash_hmac('sha256', …)`,
   comparaison `hash_equals()` uniquement. La connaissance CinetPay (endpoints,
   libellés de statut, ordre HMAC) est **isolée dans l'adaptateur**.
3. **Contre-appel fournisseur obligatoire** (`/v2/payment/check`), même quand le
   webhook annonce `ACCEPTED`/`REFUSED`/`CANCELLED`. Le résultat normalisé est la
   **seule autorité externe**.
4. **Aucun appel réseau sous transaction** : `DB::transactionLevel() === 0` exigé
   avant le contre-appel ; réservation/enregistrement committé, appel hors
   transaction, confirmation en transaction séparée.
5. **Argent comparé en entiers** : `provider.amount_minor === payment.amount_minor
   === order.total_minor` et devises strictes. Le montant fournisseur est parsé
   en **digit-string** (`^[0-9]{1,18}$`) — aucun float, arrondi ou notation
   scientifique.
6. **Ordre de verrouillage global** : `Order → Payment → Coupon → CouponRedemption
   → WebhookEvent`. Le Payment est localisé sans verrou pour trouver `order_id`,
   puis Order est verrouillé avant Payment.
7. **Ladder Payment respectée** : le trigger interdit `pending → succeeded`
   direct ; la confirmation monte `pending → processing → succeeded` dans la même
   transaction, `succeeded_at`/`processing_at`/`last_verified_at` renseignés.
8. **Payment et Order changent dans une seule transaction** ; le trigger différé
   `validate_payment_order_consistency` impose leur cohérence au COMMIT.
9. **Succès externe incohérent ⇒ revue manuelle** (montant/devise/référence
   divergents, coupon inconsommable, état local contradictoire) : `Payment →
   requires_review`, `Order → payment_review`, **aucun coupon consommé, aucun
   OrderPaid**. Un succès incohérent **ne devient jamais `failed`** ; une
   référence stockée **n'est jamais écrasée**.
10. **Coupon consommé exclusivement à la transition `paid`**, sous verrou
    `coupons FOR UPDATE` : `max_redemptions` (et par client) vérifié, une seule
    `CouponRedemption` (backstop `coupon_redemptions_order_id_unique`), un seul
    incrément de `redemptions_count` (compteur **100 % applicatif**). La remise
    n'est **jamais recalculée** — seul le snapshot figé de l'Order est copié.
    `customer_key_hash` = `sha256(user:… | visitor:… | email:…)`, version 1
    (primitive `App\Support\CustomerRedemptionKey`).
11. **Rejeu entièrement idempotent** : dédup webhook par `23505 + nom de
    contrainte exact` (`PostgresConstraintViolation`, jamais de substring) — signé
    par `provider_external_event_unique`, invalide par `provider_payload_hash_
    unique` ; un rejeu terminal ne retraite jamais l'argent ni ne redispatche.
12. **Aucune livraison** dans ce gate : ni DownloadGrant, ni job, ni mail, ni
    endpoint de téléchargement, ni listener P4-C.
13. **Commande gratuite** (`FreeOrderConfirmationService`) : `total_minor = 0`,
    **aucune ligne Payment**, verrou Order puis Coupon, coupon consommé dans la
    même transaction si applicable, `Order → paid`, OrderPaid après COMMIT ;
    ownership anti-énumération uniforme ; coupon indisponible ⇒ rollback complet
    (pas de chemin de revue sans Payment).
14. **`OrderPaid` (P3-D5) ne porte que `order_id`** (entier), dispatché via
    `DB::afterCommit` **uniquement sur une transition réelle `pending → paid`**,
    jamais avant COMMIT, jamais en rollback/rejeu, jamais pour `payment_review`
    ni pour processing/failed/cancelled/unknown. **Aucun listener** n'existe dans
    ce gate.
15. **Fenêtre résiduelle assumée** : le dispatch est *au-moins-une-fois* par
    transition locale ; une panne du processus entre COMMIT et dispatch reste une
    fenêtre résiduelle — la réconciliation durable appartient au futur P4-C/Ops.
    **Aucune table outbox** n'est créée ici.
16. **CinetPay désactivé par défaut** (`PAYMENT_DRIVER` vide) ; résolu uniquement
    avec `PAYMENT_DRIVER=cinetpay` et configuration complète, sinon
    `ProviderConfigurationFailure` **avant tout HTTP** (binding lazy dans
    `AppServiceProvider`). HTTPS obligatoire hors `local`/`testing`, TLS jamais
    désactivé, secrets lus **uniquement depuis `config/payments.php`** (jamais
    `env()` dans l'adaptateur), jamais logués ni exposés.

**IDENTIFIANT D'ÉVÉNEMENT CINETPAY** : CinetPay ne fournit pas d'ID d'événement
autonome, donc dérivation bornée `derived:` + `sha256("cinetpay\n" +
transaction_id + "\n" + payload_hash)` (72 caractères). Même notification exacte
⇒ même ID ; toute variation ⇒ événement distinct. Le `payload_hash` canonique
(clés triées, JSON stable) couvre une **allowlist non-PII** — téléphone
(`cel_phone_num`, `cpm_phone_prefixe`) et `cpm_custom` exclus du hash **et** du
`filtered_payload` ; `x-token`/secret jamais persistés.

**RÉPONSES HTTP** (jamais révélatrices de l'existence d'un Order/Payment) :
health `200` · traité/rejoué `200` · signature invalide `401` · payload invalide
`422` · fournisseur indisponible/protocole/config `503` · erreur interne `500`.
Route API stateless dédiée (`routes/api.php`), sans session ni CSRF web, pas de
route PowerPay.

**PÉRIMÈTRE** : `app/Contracts/Payments/*` (NormalizedPaymentStatus,
ProviderWebhookEnvelope, ProviderPaymentVerificationRequest/Result,
PaymentConfirmationProvider), `app/Payments/*` (CinetPayProvider, CinetPayWebhook,
PaymentProviderFactory), `app/Services/Payments/*` (WebhookRecordingService,
PaymentConfirmationService, FreeOrderConfirmationService, WebhookOutcome,
RecordedWebhook, PaymentConfirmationException/RefusalReason),
`app/Events/OrderPaid.php`, `app/Support/CustomerRedemptionKey.php`,
`app/Http/{Controllers/Api,Requests}/CinetPay*`, `config/payments.php`,
`routes/api.php`, `docs/integrations/POWERPAY_SETUP.md`, `.env.example`,
`bootstrap/app.php` (routing API), `AppServiceProvider` (binding).

**VALIDATION** : nouvelles suites P3-D4/D5 **59 tests** (adapter 23, binding 5,
confirmation 16 dont C3/C4 sur connexions runtime réelles, webhook HTTP 8 dont
C1/C2, événement/free-order 7 dont C5). Suite complète **537/4083** (était
478/3894), Pint **175 fichiers**, **29 migrations**, aucune `000014`,
`git diff --check` propre. Concurrence prouvée : verrous `coupons`/`orders`
sérialisent (`55P03`), dernière place coupon ⇒ perdante en `payment_review` sans
dépasser le compteur, aucun `25P02`/`42501`.

**ALTERNATIVES REJETÉES** : faire confiance au statut du webhook ; contre-appel
sous transaction ; comparaison monétaire flottante ; classement de contrainte par
substring ; dispatch d'OrderPaid avant COMMIT ou au rejeu ; consommation de
coupon au checkout ; recalcul de la remise ; endpoint/statut/secret PowerPay
inventé ; adaptateur CinetPay actif par défaut ; table outbox dans ce gate ;
livraison ou listener P4-C.

### D-035 — Pipeline de livraison sécurisé (P4-C0 → P4-C3) ✅
**Date** : 2026-07-24. **Statut** : **P4-C0 à P4-C3 TERMINÉS, MERGÉS ET
VALIDÉS** via [PR #24](https://github.com/mysterus44/DigiTrove/pull/24), head
`1492cd137a904c6025504fc5fd0cf0d51bd92db9`, merge
`701cfa4f95700b61d70f242e15feef264adffa8b`, CI #29 success. Macro-gate
`p4-c0-c3-secure-delivery-pipeline`, **aucune migration** — 29 inchangées.
Pipeline **désactivé par défaut**.

**DÉCISIONS FIGÉES**
1. `OrderPaid` ne transporte que `order_id` (P3-D5, hérité).
2. Un listener `QueueSecureDelivery` dispatch un job unique portant **`order_id`
   seul**, et **uniquement si `DELIVERY_PIPELINE_ENABLED=true`**.
3. Aucun token brut dans l'événement, le listener, le job, le payload de queue,
   `failed_jobs`, le cache, la base, les logs ou une exception.
4. Les tokens bruts sont générés **uniquement dans la mémoire du worker**
   (`random_bytes(32)`, base64url sans padding).
5. Seul le **SHA-256** du token est persisté (`download_grants.token_hash`).
6. Le mail est envoyé **synchroniquement dans le worker** (`Mail::to()->send()`).
7. La Mailable `OrderDownloadsReady` **n'implémente jamais `ShouldQueue`** et
   refuse explicitement toute mise en queue ou sérialisation générique.
8. Les grants sont créés et **committés avant** l'envoi du mail.
9. Échec du mail ⇒ **révocation** des grants émis (`delivery_failed`) puis
   exception sanitizée pour retry (at-least-once).
10. Une reprise réutilise les **couples historiques** `(order_item_id,
    product_file_id)` déjà émis — jamais un upgrade implicite ; les grants actifs
    stales sont révoqués `delivery_uncertain_reissue` avant réémission.
11. Le **live bundle pivot** n'est jamais lu pour déterminer les droits.
12. La **snapshot d'achat** (`order_item_bundle_components`) est l'unique autorité
    bundle ; produit simple ⇒ `product_files` actifs du produit acheté.
13. Un **remboursement partiel** conserve les grants (`Order → partially_refunded`).
14. Un **remboursement total** révoque **tous les grants actifs** dans la **même
    transaction** que `Order → refunded` (`full_refund`), satisfaisant le trigger
    différé **G4**. `RefundCompletionService` monte la ladder
    `pending→processing→succeeded` et ne dépasse jamais le montant capturé (le
    trigger de cap BDD reste la dernière défense).
15. **Aucun endpoint de téléchargement** dans ce macro-gate (P4-C4/C5).
16. Livraison réelle **désactivée par défaut** tant que P4-C4/C5 ne sont pas
    mergés ; `DeliveryConfig` **fail-closed** (TTL/quota bornés, URL requise +
    HTTPS hors local/testing quand activé).
17. Fournisseurs e-mail **configurables par environnement**, jamais codés
    (`docs/integrations/MAIL_PROVIDER_SETUP.md`, `.env.example` sans secret ;
    `MAIL_MAILER=log` refusé avant dispatch/émission). Adaptateur refund fournisseur
    **non implémenté** (`REFUND_PROVIDER_SETUP.md`, scaffold placeholders,
    `RefundCompletionService` = primitive locale sans appel réseau).

**TRANSPORT PROVISOIRE** : jusqu'à P4-C4/C5, les liens utilisent
`{base}/{public_id}#token={raw}`. Le fragment n'est pas envoyé au serveur/proxy ;
le futur gate devra l'échanger côté client contre le credential de tentative
prévu, sans query string et sans ajouter de route dans ce macro-gate.

**CRASH APRÈS MAIL (honnête)** : une panne après un envoi réussi mais avant l'ACK
du job peut provoquer, au retry, une révocation puis un nouvel e-mail ; l'ancien
lien devient invalide. Ce comportement **at-least-once** est volontaire et plus
sûr que de conserver un grant dont le token brut n'est plus disponible.

**PÉRIMÈTRE** : `config/delivery.php`, `app/Support/DeliveryConfig.php`,
`app/Enums/GrantRevocationReason.php`, `app/Jobs/SecureDeliveryJob.php`,
`app/Listeners/QueueSecureDelivery.php`, `app/Mail/OrderDownloadsReady.php`,
`app/Services/Delivery/{GrantIssuanceService,RefundCompletionService}.php`,
`app/Support/{IssuedGrant,IssuedGrantBatch,RefundCompletionResult}.php`,
`AppServiceProvider` (listener), `.env.example`,
`docs/integrations/{MAIL_PROVIDER_SETUP,REFUND_PROVIDER_SETUP}.md`.

**VALIDATION** : **50 tests / 165 assertions P4-C** (C0 **16/42**, C1 **14/36**
dont bundle/no-upgrade/token et Order refunded, C2 **7/29**, C3 **9/30**,
concurrence **4/28**). C1–C3 utilisent des processus/connexions PostgreSQL
indépendants et prouvent l'attente réelle sur les verrous ; C4 classe exactement
`23505 + download_grants_token_hash_unique`, C5 exactement
`23505 + download_grants_active_pair_unique`. Suite complète **587/4259**, Pint
**191**, **29 migrations**, aucune `000014`. Garde-fou P4-B élargi (fichiers
`Services/Delivery`, `app/Jobs`, `app/Listeners`, `app/Mail` en allowlist
fail-closed).

**ALTERNATIVES REJETÉES** : token brut en queue/log/exception ; reconstruire un
token depuis son hash ; Mailable `ShouldQueue` ; mail sous transaction ; live
bundle pivot comme autorité ; upgrade implicite au retry ; suppression/réactivation
de grant ; endpoint de téléchargement ; API refund/e-mail inventée ; migration
outbox.

**CLÔTURE POST-MERGE (2026-07-24)** : D-035 est intégralement conservée et son
macro-gate est désormais **terminé, mergé et validé** via
[PR #24](https://github.com/mysterus44/DigiTrove/pull/24), head
`1492cd137a904c6025504fc5fd0cf0d51bd92db9`, merge
`701cfa4f95700b61d70f242e15feef264adffa8b`, **CI #29 success**. Prochaine
tâche : macro-gate P4-C4/P4-C5/P4-C6.

### D-036 — Download Authorization, File Delivery and Operations ✅
**Date** : 2026-07-24. **Statut** : **P4-C4 + P4-C5 + P4-C6 TERMINÉS, MERGÉS ET
VALIDÉS** via [PR #25](https://github.com/mysterus44/DigiTrove/pull/25), head
`07d566fb4016a805fc007f4210bf122ac2fd9bed`, merge
`109fde4c6c0b401f4a8252fad780d1368711b161`, **CI #31 success** (commits
`de0fbe4` + `fefb28e` + `671669b` + hardening `5bd86d3` + `07d566f`,
**aucune migration** — 29 inchangées). Le pipeline reste **désactivé par
défaut** tant que la configuration opérationnelle de production n'est pas
renseignée.

**DÉCISIONS FIGÉES**
1. Le secret brut du grant ne passe jamais en query string. Le lien e-mail le
   place uniquement dans le fragment URI ; la page d'échange retire
   immédiatement ce fragment via `history.replaceState()`.
2. La page d'échange est uniforme et sans accès BDD, ressource tierce, analytics
   ou stockage navigateur. Elle transmet le secret du grant uniquement dans
   `Authorization: Bearer …` sur le POST d'autorisation.
3. L'autorisation génère un secret de tentative distinct avec
   `random_bytes(32)` encodé base64url sans padding. Seul son SHA-256 est
   persisté ; le brut reste en mémoire puis dans le cookie `dl_attempt`
   `HttpOnly`, `SameSite=Strict`, `Secure` hors local/testing, chemin borné au
   fichier et durée courte.
4. Le POST effectue un préflight du fichier privé **hors transaction**, puis
   verrouille **Order → DownloadGrant** dans une transaction courte sans I/O
   filesystem. Il revalide grant, Order et métadonnées ProductFile, puis laisse
   **G5** créer exactement un `download_logs.started` et incrémenter
   `downloads_count` de `+1` atomiquement. Une tentative encore réutilisable
   bloque le rejeu ; le dernier quota produit une seule consommation et un refus
   uniforme non consommant.
5. Grant inconnu, identifiant malformé, token faux, grant expiré/révoqué/épuisé,
   Order non livrable et fichier indisponible exposent la même réponse publique.
   Aucun token, digest, disque, `storage_path` ou détail SQL/stack ne sort.
6. GET, HEAD et Range utilisent exclusivement le cookie de tentative et la même
   ligne `download_logs`. Aucun secret de grant n'est accepté sur la route
   fichier ; `?token`, `?attempt` et `?grant` sont refusés.
7. HEAD valide les mêmes invariants et renvoie les métadonnées sans corps,
   nouvelle ligne, incrément ou transition de statut.
8. Un seul Range `bytes` strict est accepté (`start-end`, `start-`, `-suffix`).
   Multi-range, unité différente, syntaxe ambiguë, overflow et hors-limite
   retournent `416`. Les retries réutilisent le même attempt sans quota.
9. Le mode par défaut est un `readStream()` privé, ouvert **hors transaction
   PostgreSQL**, lu par chunks bornés et fermé en `finally`.
   `X-Accel-Redirect` est préparé lui aussi hors transaction, opt-in, réservé au
   disque local privé et fail-closed si préfixe, chemin ou taille minimale sont
   invalides. Aucune URL objet publique, permanente ou pré-signée n'est générée.
10. `completed` signifie remise réussie au mécanisme de livraison, jamais preuve
    de réception intégrale par le navigateur (R3A). Une erreur avant ouverture
    du flux devient `denied/storage_failure` ; une interruption ultérieure relève
    de la sémantique at-least-once et de la réconciliation.
11. Les logs `started` anciens passent une seule fois à
    `denied/delivery_interrupted`. Le quota consommé n'est jamais rendu et
    `downloads_count` n'est jamais décrémenté.
12. L'abus se mesure par nombre distinct de `ip_hash` HMAC versionnés sur une
    fenêtre bornée. Aucune IP brute, adresse e-mail ou token n'est produit par les
    commandes ou métriques ; aucune révocation automatique n'est déclenchée.
13. La révocation support accepte uniquement
    `manual_security_reissue`, verrouille Order puis Grant, est set-once et
    idempotente pour la même raison. Une raison différente ne réécrit jamais
    l'historique.
14. La purge supprime par batch uniquement les logs `completed|denied` après
    `retention_until`, sous l'autorité de **G6**. Aucun grant, Order, OrderItem ou
    ProductFile n'est supprimé ; un log `started` n'est jamais purgé.
15. Les commandes `downloads:reconcile`, `downloads:detect-abuse`,
    `downloads:purge`, `downloads:metrics` et `downloads:revoke` sont
    non-interactives et sans secret. Réconciliation toutes les 10 minutes,
    détection/métriques horaires et purge quotidienne utilisent
    `withoutOverlapping`; `onOneServer` n'est activé qu'avec un cache distribué
    à verrou atomique.
16. Les limiters `download-authorize` et `download-file` utilisent un HMAC de
    l'IP et un hash du public ID du grant, jamais un token. Limites, TTL, chunks,
    rétention, seuils et batches sont configurables mais bornés fail-closed.
17. Les preuves PostgreSQL C1–C6 utilisent des processus runtime indépendants :
    double autorisation et dernière unité ne consomment qu'une fois ;
    autorisation/révocation restent sérialisées ; deux Range partagent une ligne ;
    la purge ne retire pas un log actif ; la double réconciliation ne réalise
    qu'une transition. Aucun `25P02`, `42501` ou deadlock.
18. Le schéma P4-B existant suffit. **Aucune migration `000014`** ni nouvelle
    table n'est créée.
19. Aucun I/O filesystem ou objet n'est autorisé sous transaction PostgreSQL.
    `PrivateFileLocator` refuse en production `diskFor`, `assertResolvable`,
    `size`, `readStream` et `xAccelPath` lorsque
    `DB::transactionLevel() !== 0`. Les services d'autorisation et de livraison
    refusent également toute transaction ambiante avant lecture BDD ou stockage.
20. La livraison suit trois phases explicites : transaction DB courte
    **Order → Grant → Log** et snapshot scalaire immuable ; I/O stockage,
    Range, stream ou X-Accel hors transaction ; puis transaction DB courte de
    revalidation/finalisation. Le callback HTTP s'exécute au niveau de
    transaction zéro.
21. Une erreur stockage ferme tout stream ouvert puis finalise séparément le log
    encore `started` en `denied/storage_failure`, sans restitution de quota.
    Une revalidation finale refusée ferme immédiatement le stream et ne retourne
    aucun fichier.
22. `DELIVERY_PIPELINE_ENABLED=false` coupe au niveau service les nouvelles
    autorisations **et les tentatives déjà émises** (GET, HEAD, Range et appel
    direct), sans accès stockage ni mutation de log/compteur.
23. Les paramètres transportant les secrets bruts grant/attempt sont marqués
    `#[SensitiveParameter]`. La persistance reste exclusivement SHA-256.

**SURFACE HTTP** :
`GET /downloads/{grantPublicId}` ·
`POST /api/downloads/{grantPublicId}/authorize` ·
`GET|HEAD /downloads/{grantPublicId}/file`.
Headers fichier : `Accept-Ranges`, `Content-Length`, `Content-Disposition`
nettoyé, `ETag`, `Content-Range` pour 206/416, `Cache-Control: private, no-store`,
`X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`.

**VALIDATION APRÈS HARDENING** : filtre P4-C4 **18/152** (inclut P4C456 ;
autorisation + contrat exacts **12/109**), P4-C5 **13/202**, P4-C6 **5/41**,
contrat et concurrence P4-C456 **10/72**. Les niveaux maximum observés sont
`exists=0`, `size=0`, `readStream=0`, `xAccelPath=0` et callback stream `=0`.
Suite complète **623 tests / 4542 assertions**, Pint **217 fichiers**,
`git diff --check` propre, **29 migrations** jusqu'à `000013`, aucune `000014`.

**ALTERNATIVES REJETÉES** : token en query/cookie de grant/localStorage/log ;
nouvelle ligne par Range ; quota rendu après interruption ; fichier public ;
`Storage::url()`/`temporaryUrl()` permanent ; lecture entière en mémoire ;
multi-range ; X-Accel implicite ; provider objet activé sans audit ; révocation
automatique sur métrique ; purge de grant ou log actif ; migration inutile.

**CLÔTURE POST-MERGE** : les cinq commits de la branche sont intégrés par le
merge `109fde4c`. Les validations post-merge ciblées confirment P4-C4 **18/152**,
P4-C5 **13/202**, P4-C6 **5/41**, P4C456 **10/72**, P4-C3 **9/29**, P4-B
**20/561** et P3-B **18/357** ; Pint **217**, `git diff --check` propre, 29
migrations jusqu'à `000013`, aucune `000014`. **P4-C0 → P4-C6 sont terminés** :
la couche applicative Commerce → Paiement → Livraison est complète. Prochaine
tâche : **P5-A0 — Analytics Schema Foundation**.

### D-037 — Partitioned Analytics Foundation ✅
**Date** : 2026-07-24. **Statut** : P5-A0 terminé, mergé et validé via
[PR #26](https://github.com/mysterus44/DigiTrove/pull/26), head
`8d9d8cc798e6a35ae74a36d1d9ae6a9d22bf171a`, merge
`94a8c08c5a9d8448dd161665f69602d84715432b`, CI #32 success.

**CONTEXTE** : P4 est complet. La fondation analytique doit accepter un volume
élevé sans ajouter de FK, de verrou ni de dépendance à la disponibilité des
tables transactionnelles. Les données commerciales historiques restent
autoritatives dans `orders`, `order_items`, `payments` et `refunds`; un événement
analytique est une observation non autoritative et potentiellement livrée au
moins une fois.

**CHOIX** :

1. Trois migrations indépendantes constituent P5-A0 :
   `000014_create_partitioned_events_table`, `000015_create_analytics_sessions_table`
   et `000016_create_analytics_rollups_tables`. Leurs rollbacks isolés sont
   testés dans une base PostgreSQL temporaire.
2. `events` est un parent PostgreSQL réellement partitionné par
   `RANGE (occurred_at)`, avec clé primaire `(id, occurred_at)`, unicité
   `(public_id, occurred_at)` et partition `events_default`. Aucun mois calendaire
   n'est créé automatiquement en P5-A0. La création, l'attachement, la migration
   et la purge des futures partitions seront des opérations contrôlées.
3. `events` est append-only : `UPDATE` et `DELETE` sont refusés en SQLSTATE
   `23514` par `prevent_analytics_events_mutation`. Il n'existe aucun mécanisme
   d'ingestion, route, service, job, listener ou API dans ce gate.
4. `events`, `analytics_sessions` et les trois rollups n'ont **aucune FK** vers
   le commerce. Les identifiants `visitor_id`, `user_id`, `entity_id` et
   `product_id` sont des références molles; leur éventuelle obsolescence ne doit
   jamais bloquer une vente, un paiement, un remboursement ou une livraison.
5. Le rôle général `digitrove_runtime` et `PUBLIC` n'ont aucun droit sur les
   tables analytiques, `events_default`, la séquence d'identité ni la fonction
   append-only. P4-B0 accordant par défaut des droits aux futurs objets, **chaque
   future partition enfant devra aussi être explicitement révoquée**. Une
   autorité d'ingestion dédiée sera conçue séparément en P5-A1.
6. Confidentialité : aucune IP brute, adresse e-mail, cookie, token, secret,
   payload webhook, URL complète ni chemin privé. `page_path`/`entry_path`/
   `exit_path` sont des chemins relatifs sans query ni fragment; `referrer_host`
   est un hostname canonique; `ip_hash` est un HMAC SHA-256 versionné. Les
   propriétés sont un objet JSONB limité à 16 KiB.
7. Les rollups sont recalculables, sans FK et sans monnaie flottante. Tous les
   montants et compteurs sont des `BIGINT`. Les ventes et produits sont séparés
   par `currency VARCHAR(3)`; les clés sont `(day, currency)` et
   `(day, product_id, currency)`. `net_revenue_minor` suit exactement
   `gross - discount + tax - refunds`; la moyenne est une division entière
   déterministe. `daily_funnel_stats` inclut `new_customers` et n'impose aucune
   monotonie artificielle entre étapes.
8. Aucun index GIN sur `properties` n'est créé sans contrat de requête mesuré.
   Les index B-tree couvrent événement/date, visiteur/date, session/date,
   entité/date et campagne UTM/date.
9. `campaigns`, segmentation client et affiliation relèvent de P6. P5-A0 ne les
   crée pas et P5-A1 n'est pas commencé.

**ALTERNATIVES REJETÉES** : FK vers les tables chaudes; droits analytiques au
runtime métier; ingestion synchrone cachée dans ce gate; partition mensuelle
codée en dur; DDL automatique; index GIN spéculatif; rollup de chiffre d'affaires
sans devise; argent en `FLOAT`/`DECIMAL`; événements considérés comme source
financière; campagnes ou segmentation avancées en P5-A0.

**IMPACT ET VALIDATION** : cinq tables analytiques plus `events_default`, cinq
modèles et cinq factories structurelles. PostgreSQL confirme le parent
`relkind = 'p'`, la partition DEFAULT, zéro FK et zéro privilège runtime. P5-A0 :
**19 tests / 256 assertions**; suite complète : **642 / 4779**; Pint :
**235 fichiers**; **32 migrations**; `git diff --check` propre; aucune base
temporaire résiduelle. Prochaine étape après revue/merge :
**P5-A1 — First-party Event & Session Ingestion**.

**CLÔTURE POST-MERGE** : parents du merge `9a2a8f104d2f81719988c51091f2a29a9ec6cb0f`
et `8d9d8cc798e6a35ae74a36d1d9ae6a9d22bf171a`. Les validations post-merge
confirment P5-A0 **19/256**, P4-C **86/559**, P4-B **20/560**, P3-B
**18/354**, Pint **235**, `git diff --check` propre et **32 migrations**.
PostgreSQL confirme le parent RANGE, la partition DEFAULT, zéro FK, append-only
et aucun DML analytique pour `digitrove_runtime`. D-037 reste inchangée.

### D-038 — Privacy-gated First-party Analytics Ingestion ✅
**Date** : 2026-07-24. **Statut** : P5-A1 terminé, mergé et validé via
[PR #27](https://github.com/mysterus44/DigiTrove/pull/27), head
`955cc34050daa4b8706e752fd9a82f579bebb02b`, merge
`c699c5b97d987ed7d5e23c99edebf66ba2053f99`, CI #33 success.

**CONTEXTE** : D-037 interdit tout DML analytique direct au runtime métier.
P5-A1 doit collecter deux observations comportementales first-party sans faire
de l'analytique une dépendance de Commerce, sans créer d'identité avant
consentement et sans accepter une donnée financière venant du navigateur.

**CHOIX** :

1. L'ingestion est désactivée par défaut. Le consentement est first-party,
   explicite et versionné. Consentement absent, refusé, obsolète ou révoqué :
   aucune écriture. La révocation arrête immédiatement les écritures futures et
   expire les cookies d'identité analytique, sans suppression historique
   automatique ni modification de l'identité Commerce.
2. Aucun fournisseur ou script tiers, `localStorage`, fingerprint, e-mail,
   téléphone, nom, token ou secret. L'IP brute et le user-agent brut ne sont
   jamais persistés : l'IP devient un HMAC-SHA-256 versionné et le user-agent
   une classe grossière `device_type`.
3. Les seuls événements publics sont `page_view` et `product_view`. `purchase`,
   `payment`, `refund`, `download`, revenus et toute donnée financière sont
   refusés côté client; les futurs événements financiers proviendront
   exclusivement des tables transactionnelles autoritatives.
4. Une requête HTTP same-origin sous middleware web/CSRF transporte exactement
   un événement JSON borné. Le serveur impose l'horodatage, dérive `user_id` de
   l'authentification et `visitor_id` du contexte first-party, normalise chemin,
   referrer, UTM, appareil et HMAC IP. Le client ne choisit jamais identité,
   session effective, timestamp, IP, appareil, montant, devise, commande ou
   paiement.
5. Les événements comportementaux sont at-least-once; aucune garantie
   exactly-once n'est annoncée. Une panne analytique interne produit un `204`
   sanitizé et ne fait jamais échouer Commerce. Le service refuse toute
   transaction ambiante (`DB::transactionLevel() !== 0`) et ne mute aucune table
   Commerce.
6. La migration additive `000017` ne crée aucune table. Elle installe
   `ingest_first_party_analytics_event`, fonction SECURITY DEFINER possédée par
   `digitrove_analytics_executor`, rôle NOLOGIN sans CREATE, TEMP, CREATEDB,
   CREATEROLE ni droits Commerce. Le `search_path` est épinglé, les objets sont
   qualifiés, il n'existe ni SQL dynamique ni DDL.
7. `digitrove_runtime` conserve zéro SELECT/INSERT/UPDATE/DELETE analytique et
   reçoit uniquement EXECUTE sur la fonction; `PUBLIC` ne reçoit aucun EXECUTE.
   L'executor possède seulement INSERT sur `events`, les droits nécessaires sur
   sa séquence et SELECT/INSERT/UPDATE sur `analytics_sessions`.
8. La sessionisation est atomique dans PostgreSQL. Un advisory lock par visiteur
   sérialise la première session; la session réutilisée est verrouillée
   `FOR UPDATE`, vérifiée par visiteur, inactivité, âge maximal et compatibilité
   du contexte d'authentification. Une session anonyme peut être réutilisée puis
   enrichie lors du login. Une session déjà identifiée n'est jamais réutilisée
   après logout ni sous un autre compte : le changement de contexte crée ou
   sélectionne une session compatible, tandis que l'identité de l'ancienne
   session reste immuable. Cette règle est imposée par la fonction PostgreSQL,
   pas seulement par Laravel. `last_seen_at` ne recule jamais et `page_views`
   n'augmente que pour `page_view`.
9. Les cookies `dt_analytics_consent`, `dt_analytics_visitor` et
   `dt_analytics_session` sont chiffrés/signés par Laravel, HttpOnly,
   SameSite Strict et Secure hors local/testing. L'identité visiteur analytique
   est un UUID dédié créé uniquement après consentement; elle ne modifie aucun
   invariant checkout Visitor.
10. Toute configuration est bornée par `AnalyticsConfig` et fail-closed :
    activation, version de consentement, TTL/inactivité, âge maximal, limite,
    taille des propriétés, clé/version HMAC et HTTPS hors local/testing.
11. Le rate limiter `analytics-ingestion` emploie uniquement un HMAC de l'IP et
    un SHA-256 de l'UUID visiteur; aucune IP, cookie ou session brute dans sa clé.
12. Une connexion `pgsql_analytics` séparée est rejetée pour ce gate : avec les
    mêmes identifiants runtime, elle n'ajoutait aucune isolation d'identité
    mesurable. L'invocation préparée unique utilise la connexion runtime et la
    garde stricte contre les transactions Commerce ambiantes.

**ALTERNATIVES REJETÉES** : DML direct au runtime; ingestion générique ou batch;
événements financiers navigateur; timestamp client; cookie publicitaire;
identité Visitor Commerce réutilisée sans preuve; IP/user-agent bruts; script
tiers; queue; listener `OrderPaid`/refund; rollup ou DDL de partition pendant
l'ingestion; connexion dédiée cosmétique.

**IMPACT ET VALIDATION** : six suites P5-A1 couvrent autorité, consentement,
ingestion, sessions, concurrence et contrat statique : **74 tests / 400
assertions**. Les scénarios HTTP et les appels directs sous
`digitrove_runtime` prouvent logout A → session anonyme distincte, A → B →
session B distincte, anonyme → A → session enrichie et A → A → session
réutilisée. Deux processus PostgreSQL indépendants prouvent aussi la
sérialisation d'une requête A puis anonyme sans mélange d'identité, perte de
page view, deadlock, `25P02` ou `42501`. Suite complète : **716 / 5183**; Pint :
**254 fichiers**; **33 migrations**; rollback isolé `000017`, P5-A0 **19/256**,
P4-C **86/559**, P4-B **20/560**, P3-B **18/354**, `git diff --check` propre.
P5-A2, P6 et P7 ne sont pas commencés.

**CLÔTURE POST-MERGE** : les parents du merge sont
`5bff49bf30dd76c193ce3a55207e007c52fd84b4` et
`955cc34050daa4b8706e752fd9a82f579bebb02b`. Les cinq commits P5-A1 sont
intégrés à la stable. Les validations post-merge confirment P5-A1 **74/400**,
P5-A0 **19/256**, P4-C **86/559**, P4-B **20/560**, P3-B **18/354**, Pint
**254**, **33 migrations** et `git diff --check` propre. D-037 et D-038 restent
intégralement applicables. Prochaine tâche : **P5-A2 — Authoritative Rollups and
Safe Partition Operations**.

### D-039 — Dimensionally Correct Product Analytics and Authoritative Operations ✅

**Date** : 2026-07-25. **Statut** : P5-A2 terminé, mergé et validé via PR #28,
head `03063db8acf0b974ab9369f72d188f8cb52df71b`, merge
`17aaa4f43fcac0d3ef5e039897f0d30666b9d29d`, CI #35 success.

**PROBLÈMES CORRIGÉS** :

1. `daily_product_stats` associait `views` et `add_to_carts`, observations sans
   devise, à une clé `(day, product_id, currency)` conçue pour les achats et le
   revenu. Dupliquer une vue dans chaque devise ou utiliser une devise sentinelle
   (`XXX`, `N/A`, devise catalogue/pays/client) aurait créé une mesure fausse.
2. `order_items.product_id` est une FK catalogue nullable `ON DELETE SET NULL`.
   Elle ne peut donc pas identifier durablement le produit acheté après une
   suppression catalogue; ni titre, ni slug, ni zéro ne sont des substituts
   historiques fiables.

**DÉCISION STRUCTURELLE** :

- `daily_product_engagement_stats`, sans devise ni FK, porte `(day,
  product_id)`, `views`, `add_to_carts` et `updated_at`. `views` compte les
  événements `product_view`; `add_to_carts` reste zéro tant que cet événement
  n'est pas autorisé.
- `daily_product_stats` devient strictement commercial : `(day, product_id,
  currency)`, `purchases`, `revenue_minor`, `updated_at`.
- `order_items.purchased_product_id BIGINT NOT NULL` est un snapshot positif,
  sans FK et immuable. Le checkout serveur le copie depuis le `PricedLine`; le
  client ne le fournit jamais. Il survit à la nullification de `product_id`. Une
  ligne bundle attribue l'achat au bundle vendu, jamais à ses composants.
- L'audit pré-migration a trouvé zéro `order_items`, zéro `product_id NULL` et
  zéro métrique historique `views/add_to_carts` non nulle : aucun identifiant ni
  engagement n'a été inventé. La migration reste fail-closed si un futur
  environnement contient une ligne impossible à backfiller ou une métrique
  ambiguë non nulle.

**ROLLUPS AUTORITATIFS** :

- `daily_sales_stats` utilise les commandes payées par `paid_at` et les
  remboursements réussis par `succeeded_at`, groupés en journée UTC et devise.
- `daily_product_stats` utilise `purchased_product_id`, la quantité et le total
  snapshot de ligne, avec la devise de la commande; aucun join catalogue.
- `daily_product_engagement_stats` compte chaque `product_view` une fois, sans
  devise ni source financière.
- `daily_funnel_stats` dérive visiteurs, sessions, vues, checkouts, achats et
  nouveaux clients selon leurs sources autoritatives. Le recalcul journalier
  est atomique, idempotent et sérialisé par advisory lock de date.

**AUTORITÉ ET OPÉRATIONS** :

- `digitrove_analytics_worker` est un rôle LOGIN dédié, sans droit direct sur
  tables/séquences; il reçoit seulement EXECUTE sur trois fonctions.
- `digitrove_analytics_rollup_executor` est NOLOGIN et possède la fonction
  `refresh_authoritative_daily_analytics(date)` SECURITY DEFINER. Ses lectures
  Commerce et écritures de projections sont strictement bornées.
- `ensure_analytics_events_month_partition(date)` crée uniquement une partition
  mensuelle déterministe dans une fenêtre de ±60 mois, sous advisory lock. Elle
  refuse toute plage déjà occupée dans `events_default`, vérifie parent/bornes et
  applique explicitement les ACL. Elle ne déplace, détache ni supprime rien.
- `audit_analytics_event_partitions()` expose seulement noms, bornes et nombre
  de lignes DEFAULT. Les services refusent une transaction ambiante et utilisent
  la connexion dédiée `pgsql_analytics_worker`; commandes :
  `analytics:rollup`, `analytics:partitions:ensure` et
  `analytics:partitions:audit`. Le scheduler est conditionnel, distribué et sans
  chevauchement.

**MIGRATION ET ROLLBACK** : toute la correction et les autorités résident dans
`2026_07_14_000018_create_analytics_operations_authority.php`; aucune `000019`.
Le rollback isolé retire les autorités et l'engagement, restaure exactement les
colonnes P5-A0, enlève le snapshot créé par ce gate et préserve P5-A0/P5-A1,
événements, partitions déjà créées, Commerce et rôles globaux.

**VALIDATION** : PostgreSQL 16 réel, **34 migrations**, P5-A2 **25 tests / 198
assertions**, suite complète **741 / 5381**, Pint **278 fichiers**,
`git diff --check` propre. Concurrence par connexions indépendantes, ACL,
fonctions, partition DEFAULT, rollback et backfill fail-closed sont couverts.
P5-A3, P6 et P7 ne sont pas commencés.

**CLÔTURE POST-MERGE** : le merge a pour parents
`d7c4d62ac21c66ad86d14d065957a44030bcb500` et
`03063db8acf0b974ab9369f72d188f8cb52df71b`. Les commits
`2016e025977073d8e09bd4ec489f398b892a4bd8`,
`b36171d0a9553b6bfc639fc0701a49d78e0fa6cd`,
`6b0191958419f199b51cd784fb34c9aa2fa0bba5` et
`03063db8acf0b974ab9369f72d188f8cb52df71b` sont intégrés. Les validations
post-merge confirment aussi P5-A1 **74/400**, P5-A0 **19/256**, P4-C **86/559**,
P4-B **20/560**, P3-D2 **91/364** et P3-B **18/354**.

**AUDIT P5-A3 — CONSTATS, PAS NOUVELLE DÉCISION** :

1. Le dépôt enregistre un seul panel Filament, `admin`. `User` n'implémente pas
   `FilamentUser` et aucun `canAccessPanel()` n'existe : Filament refuse donc
   tout utilisateur en environnement non local. Les rôles formels sont
   `customer`, `admin`, `staff`, mais aucune policy/gate analytique ne tranche
   l'accès aux finances.
2. Aucun vendeur, marchand, créateur, tenant ou owner n'existe dans les modèles
   `Product`/`Order` ou les rollups. Les quatre rollups sont globaux. Un
   dashboard vendeur serait faux sans ownership Commerce et dimensions
   analytiques additives; le seul périmètre compatible aujourd'hui est global,
   sous réserve d'une validation produit.
3. `digitrove_runtime` et `digitrove_analytics_worker` reçoivent tous deux
   `permission denied` sur `daily_sales_stats`; le worker demeure EXECUTE-only
   et ne doit jamais servir les requêtes web. P5-A3 nécessite donc une frontière
   additive dédiée, recommandée sous forme d'un rôle LOGIN read-only et d'une
   connexion Laravel limités aux quatre rollups, sans `events`,
   `analytics_sessions`, Commerce ni fonctions d'opération.
4. Filament fournit déjà `ChartWidget`, `StatsOverviewWidget` et Chart.js, mais
   aucun widget/resource/page applicatif, filtre, empty state ou skeleton
   analytique n'existe. La locale applicative par défaut est `en`, tandis que
   les guides métier emploient surtout le français.
5. Le tracker place encore les widgets CA/tunnel/top produits en P6, alors que
   le gate demandé les nomme P5-A3. L'audience (`admin` seul ou staff actif),
   la portée globale/tenant et l'ownership P5-A3/P6 sont des décisions humaines
   bloquantes. Aucun D-040 n'est créé artificiellement.

### D-040 — Admin-only Global Analytics Read Model ✅

**Date** : 2026-08-03. **Statut** : **P5-A3A/B TERMINÉ, MERGÉ ET VALIDÉ** via
PR #29, head `31f986dc84c05f15fb5f2e2f1f3db8ea496d01be`, merge
`2bbf2b5260bb97c7981cf84a13c84910062a21cc`, CI #36 success.

**ACCÈS ET PORTÉE** : seul un utilisateur authentifié, non supprimé, de rôle
`admin` et statut `active` accède au panel Filament `admin` et à la Gate
`viewGlobalAnalytics`. `staff`, `customer`, `suspended` et `blocked` sont
refusés. Le dashboard est global uniquement : aucune dimension vendeur, tenant
ou owner n'est prétendue. Les widgets analytiques appartiennent à P5-A3; P6
reste réservé au CRM, aux campagnes, segments et au marketing.

**FRONTIÈRE POSTGRESQL** : la migration additive
`2026_07_14_000019_grant_analytics_dashboard_read_privileges.php` ne crée ni
table ni index. Le rôle LOGIN `digitrove_analytics_reader`, sans privilège
élevé ni membership, reçoit seulement `USAGE` sur `public` et `SELECT` sur
`daily_sales_stats`, `daily_product_stats`,
`daily_product_engagement_stats` et `daily_funnel_stats`. `PUBLIC`, le runtime
web et le worker P5-A2 restent sans lecture directe. Le rollback retire
seulement ces ACL et conserve rôle, tables, données et autorités P5-A0/A1/A2.

La connexion Laravel `pgsql_analytics_reader` n'a aucun fallback, vérifie
`session_user` et `current_user`, refuse une transaction ambiante puis exécute
une transaction PostgreSQL read-only avec statement/lock timeouts bornés.
Credentials incomplets, identité inattendue ou dashboard désactivé entraînent
un refus fail-closed. Aucun worker P5-A2 ne sert les requêtes HTTP.

**READ MODELS ET CACHE** : `AnalyticsOverviewQuery` et
`AnalyticsSalesQuery` lisent seulement les rollups autorisés et retournent des
DTO immuables. Les plages sont UTC, 30 jours par défaut, 366 maximum; devise
strictement uppercase; pagination 100 maximum et ordre stable. Le cache Laravel
est borné à 0–300 secondes et sa clé `analytics:v1` inclut rôle admin, portée
globale, UTC, query, plage, devise, page et taille, sans identité utilisateur,
session, cookie, IP ou token.

**SÉMANTIQUE ET UI** : aucune somme monétaire ne mélange les devises; chaque
carte financière et la vue Ventes restent currency-safe. Un jour absent est
« non calculé », le jour UTC courant présent est « provisoire », un net négatif
reste signé et `add_to_carts` est « Non suivi ». L'interface Filament française
affiche Vue d'ensemble et Ventes avec couverture, états vide/indisponible et
erreurs sanitizées. Elle ne lit ni `events`, `analytics_sessions`, données
Commerce ou personnelles brutes; elle n'expose aucune API, export CSV, commande
de rollup/partition, tenant ou dashboard staff.

**VALIDATION** : PostgreSQL 16 réel, **35 migrations**, P5-A3 **32 tests / 193
assertions**, suite complète **773 / 5575**, Pint **302 fichiers**,
`git diff --check` propre. ACL reader/runtime/worker/PUBLIC, transaction
read-only, identités, cache, UI et rollback isolé sont couverts. À la clôture
de D-040, P5-A3C, P5-A3D, P6 et P7 n'étaient pas commencés. La tâche suivante
était :
**P5-A3C — Product and Funnel Analytics Views**.

### D-041 — Global Product and Funnel Analytics Views ✅

**Date** : 2026-08-03. **Statut** : **P5-A3C TERMINÉ, MERGÉ ET VALIDÉ** via
[PR #30](https://github.com/mysterus44/DigiTrove/pull/30), head
`642f8e359348ca6d65c0dad1e14418d1400a8ff2`, merge
`87bf83999712360fdacab4537ebc96d81506a543`, CI #37 success. Base exacte avant
merge : `c6790e6ec4171034050d91202e758971881f5aa0`, après le merge P5-A3A/B
`2bbf2b5260bb97c7981cf84a13c84910062a21cc`.

**PRODUITS** : `AnalyticsProductQuery` agrège séparément
`daily_product_stats` (achats et revenu dans la devise sélectionnée) et
`daily_product_engagement_stats` (vues globales sans devise), puis réunit les
produits vus, achetés ou les deux. Aucun catalogue n'est lu : le libellé honnête
est `Produit #<id>`. Le classement est stable par revenu, achats, vues ou ID;
pagination 30 par défaut, 100 maximum. Le prix moyen est calculé en unités
mineures entières et reste indisponible sans achat. `add_to_carts` demeure
« Non suivi »; aucun ratio achats/vues n'est présenté comme une conversion.

**TUNNEL** : `AnalyticsFunnelQuery` lit uniquement `daily_funnel_stats`. La
série calendaire conserve un jour absent à `NULL`, distingue un zéro réellement
calculé et marque le jour UTC courant présent comme provisoire. Les ratios
agrégés sont explicitement **non cohortés**, calculés par PostgreSQL avec six
décimales, non plafonnés et `NULL` lorsque le dénominateur vaut zéro. Ils ne
prétendent donc pas suivre une même cohorte entre les étapes.

**FRONTIÈRE ET CACHE** : les deux queries réutilisent exclusivement le reader
D-040, ses transactions read-only, sa fenêtre UTC bornée et ses quatre rollups
autorisés. Aucune migration ni ACL supplémentaire n'est requise : le total
reste **35 migrations**, sans `000020`. Les caches utilisent les clés bornées
`analytics:v1` et stockent des tableaux scalaires réhydratés en DTO immuables,
compatibles avec Redis lorsque la désérialisation d'objets est désactivée.

**UI ET SÉCURITÉ** : les widgets Filament Produits et Tunnel restent réservés à
l'admin actif global de D-040. Ils n'exposent aucune donnée brute, personnelle
ou Commerce, aucune API, export, opération analytique, vendeur, tenant ou
ownership. P5-A3D reste optionnel et non commencé; l'implémentation P6 et P7
reste non commencée.

**VALIDATION** : PostgreSQL 16 et Redis réels, P5-A3C **22 tests / 178
assertions**; P5-A3 agrégé **54 / 371**; suite complète **795 / 5753**; Pint
**318 fichiers**; **35 migrations** appliquées; `git diff --check` propre.
P5-A2/P5-A1/P5-A0, P4-C/P4-B et P3-D2/P3-B restent verts. Les compteurs
précédemment inscrits (18/123, 50/316 et 791/5698) précédaient le durcissement
final des tests intégré au head de la PR.

### D-042 — P5 Completion and Operational Status Deferral ✅

**Date** : 2026-08-03. **Statut** : **P5 ANALYTIQUE TERMINÉ, MERGÉ ET VALIDÉ**.

**CHOIX** : P5-A0 (fondations), P5-A1 (ingestion first-party), P5-A2 (rollups et
partitions), P5-A3A/B (frontière de lecture, vue d'ensemble et ventes) et P5-A3C
(produits et tunnel) sont terminés. P5-A3D, interface read-only de statut
opérationnel analytique, est explicitement reporté au durcissement
préproduction et ne bloque pas P6.

Les opérations de rollup et de partitions restent accessibles uniquement par
CLI/scheduler, désactivées par défaut et protégées par leur identité PostgreSQL
dédiée. Aucune commande opérationnelle n'est exposée dans Filament. Cette
clôture ne crée ni code P6/P7, ni campagne, ni segmentation, ni export.

**RAISON** : P5-A2 fournit déjà les commandes opérationnelles auditées et
l'interface métier P5-A3 couvre les besoins analytiques courants. Ajouter P5-A3D
maintenant retarderait le CRM sans fermer un risque bloquant.

**IMPACT** : P5 est clos. L'audit d'architecture P6 CRM & Marketing est documenté;
son implémentation reste non commencée et toute nouvelle décision d'identité, de
consentement ou de rétention doit encore être validée humainement.

### D-043 — CRM Identity and Consent Foundation ✅

**Date** : 2026-08-03. **Statut** : **P6-A0 TERMINÉ, MERGÉ ET VALIDÉ** via
[PR #31](https://github.com/mysterus44/DigiTrove/pull/31), head
`3276fef12d94f25e91fe6386e153ae3130424eb1`, merge
`47888d0992aa5664e82341e52f6c3a68c4b0b15a` (parents `a11de061` et
`3276fef`). Aucun CI GitHub n'était visible avant le merge; la validation locale
post-merge complète sur la stable est verte.

**IDENTITÉ** : la seule clé de déduplication CRM est l'e-mail exact après `trim`,
comparé selon le contrat CITEXT. Aucun retrait de `+alias`, point, ni fusion par
nom, téléphone, IP, appareil, cookie, Visitor ou similarité. `user_id` n'est pas
une clé CRM et plusieurs contacts historiques peuvent référencer un même compte.
Un compte n'est lié que s'il est actif, non supprimé, vérifié et porte exactement
le même e-mail. Un achat invité est résolu uniquement depuis
`orders.customer_email`; aucun stitching Visitor ni backfill n'est autorisé.
Le hardening final exige explicitement `UserStatus::Active` dans les deux
frontières de preuve `verified_account` : l'autorité `resolve_crm_contact` et le
trigger `enforce_crm_contacts_integrity` lors d'une liaison `user_id NULL -> id`.
Les comptes `suspended` et `blocked` sont refusés avant toute création ou liaison.

**CONSENTEMENT** : l'achat ne vaut jamais consentement. Le ledger append-only ne
couvre que `channel=email` et `purpose=promotional`, avec actions
`granted|withdrawn`. La source `checkout` exige un Order exact et autorise
seulement `granted`; `account_settings` exige un User actif, vérifié et exact et
autorise grant/withdraw. Version de politique non vide, ordre serveur et digest
SHA-256 d'idempotence sont obligatoires; aucune clé brute ou date navigateur
n'est persistée. Le consentement analytique P5 et les colonnes legacy
`customer_profiles.marketing_consent|consent_updated_at` restent distincts et
non autoritatifs.

**ANONYMISATION ET PÉRIMÈTRE** : le schéma supporte un état irréversible
`anonymized` avec e-mail/user effacés et retourne alors toujours faux. Aucune
durée légale, purge, rétention automatique ou workflow public n'est inventé.
Une nouvelle relation au même e-mail crée un contact neuf sans hériter du ledger.
Aucune route, UI Filament, listener, observer, job, mail, campagne, segment,
rollup, export, affiliation ou intégration automatique n'appartient à P6-A0.

**BDD ET AUTORISATION** : la migration unique
`2026_07_14_000020_create_crm_identity_and_consent_foundation.php` crée seulement
`crm_contacts` et `crm_marketing_consent_events`. Le rôle cluster-global
`digitrove_crm_executor` est NOLOGIN, NOINHERIT, sans privilège élevé ni mot de
passe. Il possède `resolve_crm_contact`, `record_crm_marketing_consent` et
`has_current_marketing_consent`, trois SECURITY DEFINER à `search_path` fixe.
`digitrove_runtime` n'a aucun droit direct sur tables/séquences et seulement
EXECUTE sur ces autorités; PUBLIC n'a aucun accès. La Gate distincte
`manageCustomerRelationships` autorise uniquement un admin actif et non supprimé.

**VALIDATION** : PostgreSQL 16 et Redis réels, 36 migrations, P6-A0 **40 tests /
235 assertions**, deux scénarios de concurrence et rollback isolé verts; suite
complète **835 / 5988**; Pint **347 fichiers**; `git diff --check` propre. P5,
P4 et P3 restent verts. P6-A1 est audité mais non implémenté; P6-A2+, P7 et
P5-A3D ne sont pas commencés.

### D-044 — Currency-safe CRM Commerce Rollup Architecture ⚠️

**Date** : 2026-08-03. **Statut** : **AUDIT FINALISÉ; ARCHITECTURE ROLLUP
CONSERVÉE, VOLET ATTRIBUTION SUPERSEDÉ PAR D-045**. P6-A1.1+ ne sont pas
commencés.

**SOURCES FINANCIÈRES** : Commerce est l'unique autorité. Une acquisition est un
`orders.status` parmi `paid|partially_refunded|refunded` avec `paid_at` présent;
`payment_review`, `pending`, `cancelled` et `expired` sont exclus. La date
commerciale uniforme est `orders.paid_at`. Pour une commande non gratuite,
PostgreSQL garantit exactement un `payments.status = succeeded`, avec montant et
devise identiques à l'Order; une commande gratuite `total_minor = 0` devient
`paid` sans Payment. La valeur payée vient de l'immuable `orders.total_minor` et
la devise de `orders.currency`; Payment sert de preuve croisée, pas de seconde
source de montant. Seuls les `refunds.status = succeeded`, datés par
`refunds.succeeded_at`, réduisent la valeur. Plusieurs remboursements réussis
sont permis, mais leur somme ne peut dépasser l'unique Payment réussi. Aucun
Payment `requires_review` ne contribue. Aucun FLOAT, FX, total multi-devise,
événement Analytics ni prix catalogue courant n'est autorisé.
Un Order portant l'unique Payment `succeeded` ne peut pas finir `cancelled` : la
cohérence différée paiement/commande refuserait cet état et le Payment réussi est
terminal.

**ATTRIBUTION ORDER -> CONTACT** : l'option A, jointure dynamique
`orders.customer_email = crm_contacts.email`, est rejetée : l'anonymisation
efface l'e-mail de l'ancien contact et autorise un nouveau contact au même e-mail,
ce qui réattribuerait l'histoire. L'option C, `contact_id` ajouté directement à
`orders`, est rejetée car elle couple et réouvre le contrat Commerce immuable.
L'option retenue est une table séparée immuable `crm_order_attributions` avec
`order_id` unique/FK RESTRICT, `contact_id` FK RESTRICT, source fermée et
`attributed_at TIMESTAMPTZ`. Elle ne contient aucune PII et n'implique aucun
consentement. Les faits restent liés à l'ancien `contact_id` après anonymisation;
l'UI future affiche seulement « Contact anonymisé ». Un nouveau contact au même
e-mail repart à zéro et ne reçoit ni attribution, rollup ni consentement ancien.

**POINT TRANSACTIONNEL HISTORIQUE, SUPERSEDÉ PAR D-045** : l'audit recommandait
que l'attribution future soit créée dans
la même transaction que la première transition vers `OrderStatus::Paid`, sous
verrou Order et verrou/advisory lock CRM, avec résolution exacte de
`orders.customer_email`. Un User n'est lié que s'il est actif, non supprimé,
vérifié et exact; sinon l'achat reste une preuve `guest_order`, jamais Visitor.
`OrderPaid` est seulement un signal de recalcul : sa fenêtre COMMIT -> dispatch
est explicitement non durable et ne peut pas porter l'identité historique.
L'unicité `order_id` rend le replay idempotent; tout conflit de contact doit être
refusé, jamais remplacé.

**BLOCAGES D'ATTRIBUTION LEVÉS PAR D-045** : l'audit identifiait deux choix.
Premièrement, Commerce conserve sa colonne historique jusqu'à 320 caractères,
mais toute nouvelle création par checkout applique désormais le contrat CRM
3..254; un ancien snapshot incompatible est classé terminalement sans réécriture.
Deuxièmement, la décision humaine retient l'outbox durable plutôt que le rollback
de la finalisation financière. Le simple événement `OrderPaid` reste insuffisant;
outbox et sweeper apportent la reprise durable. Ces choix sont détaillés dans
D-045 et ne bloquent plus P6-A1.0.

**ROLLUP RETENU** : `crm_contact_commerce_rollups`, clé primaire
`(contact_id, currency)`, FK contact RESTRICT, devise `VARCHAR(3)` uppercase.
Métriques : `paid_orders_count`, `paid_total_minor`,
`refunded_amount_minor`, `net_revenue_minor`, `first_paid_at`, `last_paid_at`,
`last_refunded_at`, `calculation_version` et `reconciled_at`. Le nom
`paid_total_minor` est préféré à `gross_revenue_minor`, ambigu avec le sous-total
avant remise/taxe. Tous les montants sont BIGINT, non négatifs, zéro permis;
`refunded_amount_minor <= paid_total_minor` et
`net_revenue_minor = paid_total_minor - refunded_amount_minor`. Les agrégats
PostgreSQL sont calculés en NUMERIC puis bornés avant cast BIGINT afin de refuser
un overflow. Aucun index de tri métier n'est créé avant un lecteur prouvé.

**CALCUL, REPLAY ET CONCURRENCE** : stratégie hybride. Les événements ne font que
signaler; une autorité SECURITY DEFINER reconstruit intégralement la projection
depuis attribution + Orders + unique Payment réussi + Refunds réussis, sous
transaction `REPEATABLE READ` et verrou ciblé `(contact_id, currency)`. Aucun
compteur n'est incrémenté aveuglément. Deux OrderPaid, deux refunds, leurs replays
ou un ordre inverse convergent vers le même état. Des clés distinctes restent
parallèles. L'absence actuelle de `RefundSucceeded` n'est pas un blocage : une
réconciliation périodique est obligatoire; un futur événement peut seulement
réduire la latence.

**RÔLES ET EXPOSITION** : l'autorité d'attribution peut rester possédée par
`digitrove_crm_executor` NOLOGIN. Le rollup financier doit utiliser un nouvel
executor `digitrove_crm_rollup_executor` NOLOGIN et, dans un gate ultérieur, un
LOGIN `digitrove_crm_rollup_worker` EXECUTE-only. Le runtime web n'a aucun accès
direct aux attributions/rollups. Le futur lecteur CRM reste derrière
`manageCustomerRelationships`, admin actif uniquement; il ne réutilise pas la
Gate Analytics. UI, export, segment et campagne sont hors P6-A1.
Le worker reçoit ses credentials uniquement par l'environnement, avec connexion
dédiée fail-closed; les tests CI doivent créer temporairement les rôles requis et
le rollback doit retirer les objets du gate sans laisser de LOGIN résiduel.

**BACKFILL** : aucune migration ni résolution automatique historique. Un gate
séparé devra fournir une commande désactivée par défaut, dry-run, lots bornés,
curseur/reprise, rapport d'Orders non attribuables et idempotence. Par défaut il
ne crée aucun contact : après anonymisation, le schéma ne permet plus de prouver
quel ancien contact portait l'e-mail. Autoriser la création depuis un snapshot
historique est une décision humaine distincte et n'équivaut jamais à un
consentement marketing.
Le dépôt ne contient pas de jeu de production permettant de chiffrer les Orders
honnêtement attribuables; aucun volume de backfill ne peut donc être promis.

**DÉCOUPAGE** : P6-A1.0 attribution immuable; P6-A1.1 table et autorité de
reconstruction currency-safe; P6-A1.2 worker LOGIN, signaux et réconciliation;
P6-A1.3 backfill explicite. A1.0 et A1.1 restent séparés pour isoler le fait
historique de la projection recalculable; worker et backfill restent séparés.

**PREMIER GATE PROVISOIRE** : `P6-A1.0 - Immutable Order-to-CRM Attribution`,
branche future `p6-a1-0-order-crm-attribution`, migration envisagée
`2026_07_14_000021_create_crm_order_attributions_table.php`. Une table, FKs
RESTRICT, PK/unique Order, index `(contact_id, order_id)`, immutabilité, autorité
PostgreSQL possédée par l'executor CRM et trigger immédiat sur la transition
Order vers `paid`; aucun service, job, commande, UI ou rollup. Tests futurs :
compte/guest exacts, inactive fallback sans liaison, absence Visitor, replay et
concurrence, anonymisation, nouveau contact, refus de conflit, ACL, erreurs
sanitizées et rollback isolé. Le gate ne commence qu'après résolution des deux
blocages d'attribution ci-dessus.
Le rollback devra retirer table, fonction et trigger P6-A1.0 tout en préservant
P6-A0. Les gates rollup suivants devront aussi couvrir Order payant/gratuit,
pending/review ignorés, refund partiel/complet/multiple, devises séparées,
absence de total global, replay, ordre inverse, concurrence, reconstruction,
worker EXECUTE-only et réconciliation.

### D-045 — Durable CRM Attribution without Financial Rollback ✅

**Date** : 2026-08-03, durcie le 2026-08-04. **Statut** : **P6-A1.0 DURABLE ORDER-TO-CRM ATTRIBUTION
PIPELINE IMPLÉMENTÉ — EN ATTENTE DE REVUE/MERGE**.

**CONTRAT E-MAIL** : toute nouvelle création d'Order par checkout valide une
adresse de 3 à 254 caractères; 254 est accepté sans troncature et 255 est refusé
avant toute création. L'enveloppe initiale trimée et syntaxiquement validée reste
bornée à 320 caractères afin qu'un replay idempotent exact retrouve d'abord son
Order historique. Un Order existant jusqu'à 320 caractères est retourné sans
nouvelle ligne ni mutation du Cart; un e-mail différent avec le même digest reste
un conflit. La colonne historique `orders.customer_email VARCHAR(320)` et ses
données existantes ne sont pas réécrites. Une Order historique que le processeur
CRM ne peut attribuer devient terminale `unattributable` avec la raison fermée
`invalid_email_contract`; elle ne crée ni contact ni consentement et ne rollbacke
jamais le paiement.

**OUTBOX TRANSACTIONNELLE** : la migration unique
`2026_07_14_000021_create_durable_crm_order_attribution_pipeline.php` crée
`crm_order_attribution_outbox` et `crm_order_attributions`. Le trigger suit la
transition `false → true` du prédicat complet `status IN
('paid','partially_refunded','refunded') AND paid_at IS NOT NULL`, indépendamment
de l'ordre des deux mises à jour, puis insère l'outbox dans la même transaction
financière. Il peut capturer uniquement le `contact_id` d'un contact
actif à e-mail exact déjà présent; il n'appelle jamais `resolve_crm_contact`, ne
crée aucun contact et ne contient ni e-mail, Visitor, cookie, session, IP, nom,
téléphone, JSON ni autre PII. `available_at` est `TIMESTAMPTZ(6)` afin qu'une
ligne immédiatement due ne soit jamais arrondie dans le futur.

**SNAPSHOT ET ANONYMISATION** : `contact_id_snapshot` est une preuve sans PII.
S'il existe, le traitement attribue à ce contact exact même après anonymisation;
un nouveau contact recréé plus tard avec le même e-mail ne peut pas hériter de
l'ancienne vente. Sans snapshot, la résolution post-commit applique D-043 : User
seulement actif, non supprimé, vérifié et e-mail exact; sinon résolution
`guest_order`. Aucun stitching Visitor. Toute tentative de remplacer un fait
`order_id → contact_id` existant devient terminale
`unattributable/attribution_conflict`, jamais un écrasement.

**RÉSOLUTION POST-COMMIT DURABLE** : `OrderPaid` est seulement un signal faible
après commit. `ProcessCrmOrderAttribution` est un job `ShouldBeUnique`, queue
`crm`, payload `orderId` uniquement, retries/backoff bornés et verrou unique à
TTL fini de 3600 secondes, supérieur à l'horizon déclaré des cinq tentatives. Un sweeper
`crm:dispatch-order-attributions`, désactivé par défaut, récupère par lots bornés
les lignes dues et le scheduler le lance toutes les cinq minutes avec verrou
d'exécution. La durabilité vient de l'outbox et du sweeper, pas de la fenêtre de
dispatch événementielle; après expiration du verrou, l'idempotence PostgreSQL
rend un éventuel redispatch sans danger. Le processeur ne s'exécute jamais dans une transaction
ambiante et retourne uniquement un DTO minimal sans PII.

**AUTORITÉ POSTGRESQL** : cinq fonctions et trois triggers sont possédés par le
rôle existant `digitrove_crm_executor` NOLOGIN/NOINHERIT, avec `search_path`
fixe. `digitrove_runtime` n'a aucun droit direct sur les deux tables ou leurs
séquences et reçoit seulement EXECUTE sur
`list_due_crm_order_attributions(integer)` et
`process_crm_order_attribution(bigint)`. PUBLIC reste sans accès. Aucune nouvelle
identité PostgreSQL n'est créée. L'executor ne reçoit sur Orders que SELECT et
`UPDATE(id)`, privilège minimal nécessaire au verrou `SELECT ... FOR UPDATE`;
les colonnes financières restent non modifiables.

**IMMUTABILITÉ ET ÉTATS** : l'outbox est append-preserving, sans DELETE; sa preuve
(`order_id`, snapshot, disponibilité, création) est immuable et seule la
transition `pending → attributed|unattributable` avec incrément exact de tentative
est permise. L'attribution finale est totalement immuable et FK RESTRICT, source
fermée `existing_contact_snapshot|verified_account_resolution|guest_order_resolution`.
Le processus est idempotent sous verrous Order/outbox et ne modifie jamais
Commerce, les consentements ou les montants.

**VALIDATION ET PORTÉE** : PostgreSQL 16/Redis réels, **37 migrations**, P6-A1.0
**48 tests / 285 assertions**, deux scénarios de concurrence et rollback isolé
préservant P6-A0; suite complète **883 / 6273**; Pint **367 fichiers**;
`git diff --check` propre. P6-A0 reste **40/235**, P4-B **20/560**, P3-D2
**91/364** et P3-B **18/354**. Aucune migration `000022`, table rollup, worker
LOGIN, backfill, UI, segment, campagne, export, relance, affiliation, P6-A2+, P7 ou P5-A3D n'est créé.

### D-046 — Currency-safe Commerce Rollup Authority : Contrat Complet P6-A1.1 ✅

**Date** : 2026-08-04 (complétée). **Statut** : **D-046 COMPLÈTE — P6-A1.1 AUDITÉ
ET PRÊT À IMPLÉMENTER — AUCUN CODE P6-A1.1 CRÉÉ**.

**NOTE DE PROTOCOLE** : le commit précédent `7d53dc5` a utilisé `git add .` au lieu
du staging explicite fichier par fichier — déviation de protocole sans impact sur le
contenu distant (seuls quatre documents modifiés), corrigée par le présent commit qui
utilise le staging explicite obligatoire.

---

#### 4.1 — Population éligible

Le rollup futur utilise exclusivement `crm_order_attributions INNER JOIN orders`.
Conditions Order : `status IN ('paid', 'partially_refunded', 'refunded')` ET
`paid_at IS NOT NULL`.

Exclusions : Order non attribué ; outbox encore `pending` ; Order `unattributable` ;
Order `pending`, `payment_review`, `cancelled` ou `expired` ; résolution dynamique
par e-mail ; User ; Visitor ; Analytics ; prix catalogue courant.

Un contact anonymisé conserve ses faits sur son ancien `contact_id`. Un nouveau
contact portant ultérieurement le même e-mail repart sans historique.

#### 4.2 — Source financière

Source du brut : `orders.total_minor`. Source de la devise : `orders.currency`.
Date d'acquisition : `orders.paid_at`. Les Payments servent uniquement à vérifier
le contrat Commerce existant. Ne jamais additionner `orders.total_minor` et
`payments.amount_minor`.

Remboursements : `SUM(refunds.amount_minor) WHERE refunds.status = 'succeeded'
AND refunds.payment_id` appartient au Payment `succeeded` de l'Order. Agrégat des
Refunds par Order avant agrégation par contact/devise afin d'éviter toute
multiplication de lignes.

#### 4.3 — Commandes gratuites

Décision : les commandes gratuites acquises sont incluses. Elles incrémentent le
compteur, participent aux dates `first/last_acquired_at`, et ajoutent zéro au brut,
au remboursement et au net. Nom obligatoire : `acquired_orders_count`. Ne pas
utiliser `paid_orders_count`.

#### 4.4 — Schéma futur exact

Table future : `crm_contact_commerce_rollups`.
Migration future : `2026_07_14_000022_create_crm_contact_commerce_rollups.php`.
Clé primaire : `(contact_id, currency)`.

Colonnes retenues :

| Colonne | Type |
|---------|------|
| `contact_id` | `BIGINT NOT NULL` |
| `currency` | `VARCHAR(3) NOT NULL` |
| `acquired_orders_count` | `BIGINT NOT NULL` |
| `gross_revenue_minor` | `BIGINT NOT NULL` |
| `refunded_amount_minor` | `BIGINT NOT NULL` |
| `net_revenue_minor` | `BIGINT NOT NULL GENERATED ALWAYS AS (gross_revenue_minor - refunded_amount_minor) STORED` |
| `first_acquired_at` | `TIMESTAMPTZ NOT NULL` |
| `last_acquired_at` | `TIMESTAMPTZ NOT NULL` |
| `last_refunded_at` | `TIMESTAMPTZ NULL` |
| `calculation_version` | `SMALLINT NOT NULL` |
| `refreshed_at` | `TIMESTAMPTZ NOT NULL` |

Ne pas créer : `created_at`, `updated_at`, `reconciled_at`, `checksum`, `email`,
`user_id`, `visitor_id`, JSON/JSONB, `global_lifetime_value_minor`.

Contraintes : `currency ~ '^[A-Z]{3}$'` ; `acquired_orders_count > 0` ;
`gross_revenue_minor >= 0` ; `refunded_amount_minor >= 0` ;
`refunded_amount_minor <= gross_revenue_minor` ; `calculation_version > 0` ;
`first_acquired_at <= last_acquired_at`.

FK : `contact_id → crm_contacts.id ON DELETE RESTRICT`. Index supplémentaire :
aucun — la PK commence déjà par `contact_id` ; tout index futur exige une preuve
de requête.

#### 4.5 — Types monétaires

Stockage : `BIGINT`. Calcul intermédiaire : `NUMERIC` (PostgreSQL `SUM(BIGINT)`
retourne `NUMERIC`). L'autorité future doit : (1) calculer en NUMERIC ;
(2) vérifier chaque total contre `0 <= valeur <= 9223372036854775807` ;
(3) refuser explicitement tout débordement ; (4) caster en BIGINT uniquement après
vérification. Aucun cast silencieux, aucun FLOAT, aucun DECIMAL fractionnaire.
Le futur DTO PHP utilisera des `int`.

#### 4.6 — Cycle de vie de la ligne

Une ligne existe uniquement lorsqu'au moins un Order acquis et attribué existe pour
le couple `(contact_id, currency)`. Si le recalcul retourne
`acquired_orders_count = 0`, l'autorité supprime la ligne existante et retourne un
résultat vide explicite. Une commande gratuite produit néanmoins une ligne car le
compteur est positif.

**La table n'est pas append-only. C'est une projection mutable uniquement par
autorité PostgreSQL.**

#### 4.7 — Autorité PostgreSQL

Fonction future : `refresh_crm_contact_commerce_rollup(p_contact_id BIGINT,
p_currency VARCHAR)`.

Elle doit : valider le contact ; valider la devise ; prendre un advisory
transaction lock déterministe sur contact/devise ; calculer les métriques dans
une seule instruction SQL/CTE ; utiliser un snapshot cohérent de cette instruction ;
faire un UPSERT atomique ; supprimer la ligne si aucun Order éligible ; être
idempotente ; retourner un DTO minimal sans PII ; qualifier tous les objets ;
utiliser un `search_path` fixe ; ne lire aucune table Analytics ; ne créer ni
contact ni attribution.

Isolation retenue : `READ COMMITTED` + une instruction d'agrégation cohérente +
advisory transaction lock par contact/devise. Ne pas imposer `SERIALIZABLE`.

#### 4.8 — Propriétaire et ACL

Décision unique : `owner = digitrove_crm_executor`.

P6-A1.1 ne crée aucun rôle ; conserve executor `NOLOGIN/NOINHERIT` ; transfère la
propriété de la table et de la fonction à l'executor ; révoque tout accès `PUBLIC` ;
n'accorde aucun `SELECT/DML/EXECUTE` à `digitrove_runtime` ; n'accorde aucun accès
aux séquences ; ne crée aucun reader ; ne crée aucun worker LOGIN.

La protection repose sur : ownership, `REVOKE`, absence de membership vers
l'executor, fonction `SECURITY DEFINER` comme seule frontière de mutation. Le futur
worker recevra `EXECUTE` uniquement dans P6-A1.2.

#### 4.9 — Modèle applicatif (P6-A1.1)

Aucun modèle Eloquent, aucun service Laravel, aucun DTO PHP, aucun job, aucun
listener, aucune commande, aucun scheduler. P6-A1.1 est une autorité PostgreSQL
structurelle et testée.

#### 4.10 — Signaux et durabilité future (P6-A1.2, P6-A1.3)

P6-A1.2 devra créer une outbox durable distincte de refresh, car `OrderPaid` peut
être perdu, un Refund `succeeded` doit provoquer un nouveau calcul, une attribution
créée après le paiement doit provoquer un calcul, et le replay doit rester sans
double comptage. P6-A1.2 contiendra : outbox de refresh, worker
`order/contact/currency` ID-only, signaux post-commit faibles, sweeper, scheduler,
réconciliation.

P6-A1.3 reste le backfill explicite : désactivé par défaut, dry-run, borné,
reprenable, audité, sans création de contact, sans attribution par e-mail.

P6-A1.1 ne crée aucun de ces éléments.

#### 4.11 — Matrice de tests futurs (à documenter, non créés)

**Schéma** : migration `000022` uniquement ; une table ; aucune `000023` ;
PK `(contact_id,currency)` ; FK RESTRICT ; devise uppercase ; BIGINT monétaires ;
net généré ; version positive ; aucun e-mail, User, Visitor, JSON ou float.

**Calcul** : un Order payant ; un Order gratuit ; plusieurs Orders ;
plusieurs devises ; pending/review ignorés ; Order unattributable ignoré ;
Refund partiel ; Refund complet ; Refunds multiples ; Refund non `succeeded`
ignoré ; dates exactes ; net exact ; anonymisation conservée ; nouveau contact
séparé ; débordement refusé.

**Idempotence et concurrence** : replay ; deux refresh du même couple ; deux devises
simultanées ; nouvelle attribution concurrente ; nouveau Refund concurrent ;
aucun double comptage ; aucun état partiel ; rollback atomique.

**ACL** : PUBLIC sans accès ; runtime sans SELECT ; runtime sans DML ;
runtime sans EXECUTE ; executor NOLOGIN/NOINHERIT ; SECURITY DEFINER ;
`search_path` fixe ; objets qualifiés ; aucun nouveau rôle.

**Rollback** : le rollback de `000022` doit supprimer uniquement la fonction de
refresh, la table `crm_contact_commerce_rollups` et les privilèges P6-A1.1. Il doit
conserver : `crm_contacts`, `crm_marketing_consent_events`,
`crm_order_attribution_outbox`, `crm_order_attributions`, `orders`, `payments`,
`refunds`, `digitrove_crm_executor`.

### D-046.1 : Implémentation P6-A1.1 — Currency-safe Commerce Rollup Authority ✅ (MERGÉ)
CONTEXTE : Le plan P6-A1.1 (D-046) est implémenté, validé et **mergé sur la stable** via PR #33 (head `732d491`, merge `8fe6cfa`, CI #40 success : Syntax / Pint / Tests / runtime privilege boundary). P6-A1.2 (orchestration durable du refresh) devient le prochain gate actif ; P6-A1.3 (backfill) reste non commencé.
CHOIX :
1. **Migration 000022** (38 migrations, aucune `000023`) : crée la table `crm_contact_commerce_rollups` (PK `(contact_id, currency)`, `net_revenue_minor` GENERATED STORED) et l'unique fonction PostgreSQL `refresh_crm_contact_commerce_rollup(BIGINT, VARCHAR)`. Table et fonction possédées par `digitrove_crm_executor` ; fonction `SECURITY DEFINER`, `search_path` fixe, objets qualifiés `public.`.
2. **Gestion de l'ambiguïté des colonnes** : résolue par des paramètres préfixés `p_` et des références de tables qualifiées/aliasées — sans directive de résolution de conflit de variables. L'UPSERT utilise `ON CONFLICT ON CONSTRAINT crm_contact_commerce_rollups_pkey`.
3. **Types & calcul** : calcul intermédiaire NUMERIC puis contrôle de débordement explicite avant cast BIGINT. `acquired_orders_count` inclut les commandes gratuites ; refunds `succeeded` agrégés par Order.
4. **Verrous consultatifs** : `pg_advisory_xact_lock` par contact/devise pour sérialiser le recalcul concurrent.
5. **Tests** : Authority, Concurrency, Privileges, Rollback ACL, Schema — **38 tests / 169 assertions** (`--filter=P6A11`). La suppression d'un rollup obsolète (`acquired_orders_count = 0`) est éprouvée en insérant directement une projection périmée via la connexion propriétaire puis en rappelant l'autorité — sans contournement d'immuabilité de commande ni désactivation de trigger. Runtime et PUBLIC sans accès ; executor propriétaire ; aucun nouveau rôle ; aucune couche applicative.
6. **Rollback (D-046.1)** : le `down()` de `000022` révoque `SELECT` sur `payments` et `refunds` pour `digitrove_crm_executor` AVANT de supprimer la fonction et la table, restaurant exactement la frontière `000021`. Prouvé par un test ACL avant/up/down via `has_table_privilege`/`has_function_privilege`.
IMPACT : P6-A1.1 clôturé et mergé (autorité financière du rollup en place). P6-A1.2 (orchestration durable du refresh + réconciliation) est désormais le gate actif ; P6-A1.3 (backfill historique explicite) reste non commencé.

## À AJOUTER AU FIL DU PROJET
[Chaque nouvelle décision importante vient ici, datée.]

### D-050 : P6-A2 — Typed Versioned CRM Segments — IMPLEMENTATION ✅ (MERGÉ)
CONTEXTE : D-049 avait gelé l'architecture. D-050 fige le résultat **réel**, **mergé sur la stable** via PR #36 (head `ea562c7`, merge `920eb1b9`, CI #43 success). **P6-B0 (CRM Admin Views) devient le gate actif ; P6-B1 (exports) non commencé.**

**Divergences constatées entre D-049 et le schéma réel** (auditées avant tout code) :
- `crm_contacts.status` ∈ {`active`, `anonymized`} — il n'existe **pas** de valeur `archived` ; `crm_contacts.origin` ∈ {`guest_order`, `verified_account`}. L'allowlist d'enum reprend **exactement** ces valeurs.
- `crm_contacts.created_at` existe mais est **NULLABLE** et de type `timestamp(0)` (précision 1 s). Un `created_at` NULL rend le critère **explicitement FALSE** (jamais un NULL SQL qui se propagerait dans l'algèbre booléenne).
- Les six primitives commerce de D-049 existent bien dans `crm_contact_commerce_rollups` ; `last_refunded_at` existe aussi mais est **volontairement exclu** de l'allowlist V1 (aucune décision architecturale ne l'a retenu).

CHOIX :
1. **Migration unique 000025** (`2026_07_14_000025_create_typed_versioned_crm_segments.php`, **41 migrations**, aucune `000026`) : quatre tables `crm_segments`, `crm_segment_versions`, `crm_segment_generations`, `crm_segment_generation_members`, toutes owner `digitrove_crm_executor`.
2. **Intégrité des pointeurs au niveau PostgreSQL** : `crm_segment_versions` et `crm_segment_generations` portent un `UNIQUE (id, segment_id)`, ce qui permet des **FK composites** `crm_segments (current_version_id, id) → versions (id, segment_id)` et `(current_generation_id, id) → generations (id, segment_id)`, plus `generations (segment_version_id, segment_id) → versions (id, segment_id)`. Un segment ne peut **structurellement pas** pointer vers la version ou la génération d'un autre segment — ce n'est pas une vérification PHP.
3. **DSL V1 typé et allowlisté** stocké en JSONB **uniquement** parce qu'il est validé clé par clé et **jamais interprété comme du SQL**. Enveloppe exacte `{schema_version, match, criteria}` (aucune autre clé) ; `schema_version = 1` ; `match ∈ {all, any}` ; `criteria` 1..50 ; taille ≤ 32768 octets (CHECK). Aucune récursion, aucun groupe imbriqué, aucun AST.
4. **Champs allowlistés** : `commerce.{net_revenue_minor, gross_revenue_minor, refunded_amount_minor, acquired_orders_count, first_acquired_at, last_acquired_at}` et `contact.{created_at, status, origin}`. **Opérateurs** : numériques `eq|neq|gt|gte|lt|lte|between`, dates `before|after|between`, enums `in|not_in`. **Clés exactes par type de critère** : toute clé supplémentaire (`sql`, `column`, `table`, `path`, `expression`, `callback`, `raw`, `where`, `having`, `join`, `order`, `select`, …) rend la définition **invalide** — jamais ignorée.
5. **Currency scoping obligatoire** : tout critère `commerce.*` porte `currency` (`^[A-Z]{3}$`) et lit **exactement une ligne** `(contact_id, currency)`. Un critère `contact.*` **refuse** une devise. **Aucun FX, aucune somme multi-devises, aucun LTV global.** Une exigence multi-devises s'exprime par plusieurs critères explicites.
6. **Types stricts** : valeurs numériques = **JSON number entier exact** dans la plage BIGINT signée (rejette `1.2`, `"1000"`, `true`, `null`, `1e100`, `9223372036854775808`) ; `between` exige `lower <= upper`. Timestamps = **RFC3339 UTC absolu** `^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?Z$` (rejette « 30 days ago », naïf, offset `+01:00`). Enums : 1..32 valeurs, uniques, toutes allowlistées.
7. **Sémantique d'absence de rollup** : une ligne `(contact_id, currency)` absente vaut **FALSE pour TOUS les opérateurs**, y compris `neq`. Un Order gratuit acquis possède déjà une ligne (`acquired_orders_count > 0`, montants 0), donc `net_revenue_minor = 0` cible correctement les acquéreurs gratuits **sans** les confondre avec « jamais acquis ».
8. **Immuabilité** : le **contenu** d'une version est immuable dès l'INSERT (même en `draft`) ; la seule transition permise est `draft → published`. Une génération `published` est figée ; le membership est **append-only** pendant le build et refusé après publication. Triggers PostgreSQL, pas de convention applicative.
9. **Générations** : `contact_id_high_water_mark = MAX(crm_contacts.id)` gelé au démarrage — il borne la population de **contacts** parcourue. Ce **n'est pas** un snapshot MVCC des faits CRM/commerce : ceux-ci peuvent changer pendant la **fenêtre de build**. Ce qui est **atomique**, c'est la **visibilité** du membership publié. Keyset `id > cursor AND id <= HWM`, batch 1..100, et le curseur avance sur le **dernier contact SCANNÉ** (jamais le dernier *matché*, sinon un batch sans match bouclerait indéfiniment). Un index unique partiel garantit **au plus une génération active par segment** ; des segments différents avancent indépendamment.
10. **Publication atomique** : dans la transaction finale, la génération passe `published` et `crm_segments.current_generation_id` bascule. Les lecteurs passent **exclusivement** par `list_crm_segment_current_members`, qui exige `status = 'published'` : ils voient l'ancienne génération **en entier** jusqu'à la bascule, puis la nouvelle **en entier**. Jamais de demi-génération.
11. **Version vs génération** (D-049 K5) : publier une nouvelle version est **refusé** tant qu'une génération `ready|running` existe pour le segment ; la génération est donc épinglée à sa version pour tout le build. Une version publiée ne peut jamais reculer (numéros strictement croissants).
12. **Échec de batch atomique** : la sous-transaction annule **tout** le batch (membership, curseur, compteurs) puis marque `failed` avec **le seul SQLSTATE**. Aucun message brut, aucune trace, aucune PII. Reprise **explicite** via `retry_crm_segment_generation` : le curseur reste au dernier batch committé et les membres déjà écrits sont conservés.
13. **Consentement séparé** : le matcher lit **uniquement** `crm_contacts` et `crm_contact_commerce_rollups`. `crm_marketing_consent_events` n'est **jamais** lu — deux contacts identiques ne différant que par leur consentement ont le **même** membership. L'éligibilité d'envoi reste une politique distincte, appliquée au moment marketing.
14. **Contacts anonymisés** : un contact `anonymized` **peut** appartenir à un segment si ses faits non-PII satisfont les critères (membership ≠ éligibilité d'envoi). `contact.status` est allowlisté pour permettre de les exclure **explicitement** quand c'est voulu.
15. **ACL** : aucun nouveau rôle. Runtime `EXECUTE` sur les **11 autorités bornées** (`create_crm_segment`, `create_crm_segment_version`, `publish_crm_segment_version`, `get_crm_segment`, `list_crm_segments`, `start_crm_segment_generation`, `process_crm_segment_generation_batch`, `retry_crm_segment_generation`, `get_crm_segment_generation`, `list_due_crm_segment_generations`, `list_crm_segment_current_members`) ; **jamais** sur le **validateur** ni le **matcher** internes, et **aucun** `SELECT`/`DML` direct sur les quatre tables. PUBLIC sans accès.
16. **Couche Laravel mince** : `CrmSegmentService` (client des autorités, aucune évaluation PHP), job `ProcessCrmSegmentGeneration` (**payload `generationId` seul**, `ShouldBeUnique`, `uniqueFor = 3600` > horizon de retry `5 × (120 + 300) = 2100 s`, **≤ 10 batches par exécution**), dispatcher borné (lecture DB terminée **avant** tout dispatch queue), commande de recovery `crm:sweep-segment-generations` et commande opérateur `crm:rebuild-segment` (**preview par défaut**, mutation seulement avec `--execute` **et** `CRM_SEGMENT_REBUILD_ENABLED=true`). Scheduler 5 min **désactivé par défaut**. **Aucune UI, aucune route, aucune ressource Filament** dans ce gate.
17. **Rollback 000025** : supprime les triggers, révoque les `EXECUTE` runtime, drop les **18** fonctions (11 autorités runtime + 4 internes — validateur, ses **deux helpers de typage** et matcher — + 3 fonctions trigger) puis les FK composites circulaires (**explicitement, jamais `CASCADE`**) et enfin les quatre tables — restaurant exactement la frontière `000024`.

TESTS : Schema, DefinitionValidation, Matching, Versioning, Generation, Concurrency, Privileges, Rollback, Job, Sweeper, SecurityContract. Compteurs de migration relevés 40→41 **uniquement** sur les assertions d'état courant ; les frontières historiques (**37** pour `000021`, **39** pour `000023`, **40** pour `000024`) restent inchangées.

IMPACT : P6-A2 prêt pour revue. Prochain gate : **P6-B0 — CRM Admin Views**, architecture gelée par D-051 (non commencé).

### D-051 : P6-B0 — CRM Admin Views — ARCHITECTURE GELÉE (PLAN SEULEMENT) 📐
CONTEXTE : Cette décision **fige l'architecture** du prochain gate (`B0 vues` dans le découpage P6) après audit du dépôt réel, afin que le prochain agent implémente sans refaire l'audit. **P6-B0 EST NON COMMENCÉ : aucun code, aucune migration `000026`, aucune ressource Filament, aucune page, route, contrôleur ni vue.**

CHOIX (à implémenter en P6-B0, pas avant) :
1. **Autorisation** : panel `admin` uniquement, Gate **`manageCustomerRelationships`** (existante, P6-A0) — admin **actif et non supprimé**. Fail-closed : `staff`, `customer` et comptes inactifs refusés, exactement comme la frontière P5-A3 (D-040). **Aucune donnée CRM sur une route publique/storefront.**
2. **Pages attendues** : liste/détail Contacts CRM (identité, origine, statut), timeline de consentement, faits commerce **par devise**, liste des Segments, versions, état des générations, membership. Lecture seule pour la V1 sauf le lifecycle segment explicitement prévu.
3. **Sources de données** : exclusivement les autorités existantes (`get_crm_segment`, `list_crm_segments`, `list_crm_segment_current_members`, `get_crm_segment_generation`, rollups P6-A1.1). **Aucune reconstruction financière côté UI**, aucun recalcul, aucun accès direct aux tables CRM depuis le runtime.
4. **Devises** : les montants sont affichés en unités mineures formatées **avec la devise toujours explicite**. **Aucun total global multi-devises**, aucun FX, aucun float. Une page « valeur client » présente une ligne par devise.
5. **Recherche contact** : **e-mail normalisé exact** uniquement (contrat P6-A0 : `trim` + CITEXT). **Pas** de recherche floue, pas d'alias folding, pas de rapprochement approximatif. Pour un contact **anonymisé**, l'ancien e-mail n'est **jamais** reconstitué ni affiché — la recherche par e-mail ne peut pas le retrouver.
6. **Consentement** : la timeline peut être affichée (ledger append-only P6-A0), mais l'UI doit rendre explicite que **timeline de consentement ≠ appartenance à un segment** et que **appartenance ≠ autorisation d'envoi**. Aucune action d'envoi dans ce gate.
7. **UI Segments** : créer un segment, créer une version, publier une version, lancer un rebuild, consulter l'état d'une génération et lire le membership — **exclusivement** via les autorités P6-A2. Le futur constructeur de critères doit produire **uniquement le DSL V1** (champs/opérateurs/valeurs allowlistés, devise obligatoire sur les critères commerce). **Interdit** : éditeur SQL, JSON libre non validé, constructeur d'expressions arbitraires.
8. **Pagination** : keyset borné (≤ 100) sur les autorités existantes ; aucune pagination `OFFSET` sur de grandes tables, aucun export dans ce gate (les exports sont P6-B1).
9. **Migration** : P6-B0 ne devrait avoir besoin d'**aucune** migration ; si un besoin apparaît (ex. index d'affichage), il devra être justifié par un `EXPLAIN` réel et isolé dans son propre gate.
10. **Tests futurs** : autorisation fail-closed par rôle/statut, absence de PII pour un contact anonymisé, séparation consentement/membership/éligibilité, currency-safety de l'affichage, absence de SQL libre dans le constructeur de critères, pagination bornée, aucune route publique exposant du CRM.

IMPACT : **P6-B0 NON COMMENCÉ, AUCUN CODE, AUCUNE MIGRATION `000026`.** Contrat d'architecture à appliquer après le merge de P6-A2.

### D-052 : P6-B0 — CRM Admin Views — IMPLEMENTATION ✅ (MERGÉ)
CONTEXTE : Implémentation du gate gelé par D-051. **Une hypothèse de D-051 s'est révélée fausse à l'audit** : son point 9 annonçait « aucune migration ». Le runtime `digitrove_runtime` ne détient **aucun `SELECT`** sur une table `crm_*`, et si toutes les autorités **Segments** existaient déjà (P6-A2), il n'existait **aucune** autorité pour parcourir les contacts, chercher par e-mail exact, lire une timeline de consentement, lire les faits commerce par devise, lister les appartenances courantes d'un contact ou l'historique des versions d'un segment. L'UI ne pouvait donc être construite qu'en (a) cassant la frontière de lecture par des `SELECT` runtime directs — interdit — ou (b) ajoutant les autorités manquantes. **P6-B0.1 fait (b)** : migration **`000026`**, **42 migrations**, aucune `000027`.

CHOIX :
1. **Migration `000026` — 7 autorités de lecture** (`2026_07_14_000026_create_crm_admin_read_authorities.php`) : `list_crm_contacts`, `get_crm_contact`, `find_crm_contact_by_exact_email`, `list_crm_contact_consent_events`, `list_crm_contact_commerce_rollups`, `list_crm_contact_segment_memberships`, `list_crm_segment_versions`. Toutes **`STABLE`** (PostgreSQL interdit structurellement toute mutation), **`SECURITY DEFINER`**, owner `digitrove_crm_executor`, `search_path` épinglé, objets qualifiés `public.`, **aucun nouveau rôle**, PUBLIC sans accès, runtime **EXECUTE-only**. Inventaire canonique unique : une fonction ne peut pas être créée sans être aussi possédée, verrouillée et droppée (leçon P6-A2). `down()` restaure exactement la frontière `000025`.
2. **Recherche par e-mail : exact et normalisé, jamais en PHP.** La normalisation `lower(btrim(...))` sur CITEXT est faite **dans l'autorité**, donc l'UI ne peut pas dériver du contrat P6-A0. Aucun `LIKE`, `ILIKE`, wildcard, folding d'alias ni rapprochement approximatif. Un besoin hors bornes (3..254) **retourne vide au lieu de lever** : un refus distinguable transformerait l'écran en oracle d'énumération. Un contact **anonymisé** a `email IS NULL` par le CHECK d'état P6-A0 — l'ancienne adresse est **physiquement absente**, ce n'est pas un masquage.
3. **Aucun total multi-devises.** Les faits commerce arrivent **déjà calculés**, une ligne par `(contact_id, currency)`, et ne sont **jamais** sommés. `CrmMoneyPresenter` **formate** sans jamais calculer : pas d'addition, pas de FX, pas de `round()`, pas de float, **pas de division par 100**. Le dépôt n'a **aucune table d'exposants de devise** : XOF a l'exposant 0 et USD l'exposant 2, donc diviser par 100 sous-estimerait tout montant XOF de deux ordres de grandeur. Tant qu'une table auditée n'existe pas, l'affichage honnête est **l'entier exact plus sa devise**. Aucun helper `total()` n'est offert.
4. **Constructeur de critères structuré — `App\Support\CrmSegmentDefinitionBuilder`.** Aucun `<textarea>`, aucun éditeur JSON, aucun éditeur de code, aucune saisie SQL : chaque champ, opérateur et valeur d'énumération vient d'un `<select>` fermé. Les 9 champs, les jeux d'opérateurs par type et les valeurs d'enum sont des **allowlists fermées** (`match` exhaustif ⇒ un jeton inconnu **lève**, il ne « passe » jamais). Les entiers sont émis comme **nombres JSON** (jamais `"100"`, que l'autorité refuse) et l'absence de débordement est prouvée par **aller-retour** (`canonicalInteger($raw) !== (string)(int) $raw`) — un `(int)` PHP **sature** au lieu d'échouer, donc comparer à `PHP_INT_MAX` ne suffirait pas. Les dates sont **RFC3339 UTC absolues avec `Z`** ; aucune expression relative n'est acceptée (une définition est immuable, une borne relative changerait silencieusement de sens). Une clé injectée (`sql`, `column`, `raw`, `path`) n'est jamais propagée : les critères sont construits **clé par clé**, jamais fusionnés depuis l'entrée.
5. **PHP n'est PAS l'autorité finale.** Le builder est une couche de **mise en forme**, pas une frontière de sécurité : sa raison d'être est de rendre l'UI structurellement incapable de composer une définition que l'autorité refuserait, pour offrir un message par champ au lieu d'un refus opaque. `create_crm_segment_version` → `validate_crm_segment_definition_v1` reste seul juge — prouvé en appelant le service **directement**, hors builder, avec sept définitions invalides : PostgreSQL refuse les sept et **aucune ligne de version n'est créée**.
6. **Cycle de vie versions/générations** : créer une version (brouillon), publier (confirmation explicite, irréversible). Une version existante n'est **jamais** éditée ni supprimée — changer la définition crée toujours une nouvelle version ; le trigger d'immuabilité P6-A2 refuse en `23514` même sur la connexion **propriétaire**. Le rebuild réutilise le **vrai flag P6-A2** `crm.segment_rebuild.enabled` (celui qu'obéit `crm:rebuild-segment`) : désactivé, le bouton est inerte **et** le serveur refuse indépendamment — **zéro génération créée**, y compris sur appel Livewire forgé ; un flag malformé est traité comme désactivé. Le retry est réservé à une génération `failed`, sur confirmation, **jamais automatique**.
7. **Aucune fuite de message base.** L'écran n'affiche jamais de SQLSTATE, de message driver ni de trace : les refus deviennent `CrmOperationException` (sanitisée) ou un **code de raison stable** traduit en phrase opérateur. `CrmSegmentDefinitionException::reason()` expose ce code sous son propre nom précisément pour qu'un contrat de sécurité puisse bannir `getMessage()` sur toute la surface CRM. Le seul code d'erreur affiché est le `last_error_code` borné à 5 caractères que l'autorité stocke.
8. **Aucune surface publique, aucun envoi, aucun export.** Les deux pages sont des pages de panel `admin` derrière `manageCustomerRelationships` (admin actif non supprimé), re-vérifiée **dans chaque action** et pas seulement à l'accès. Aucune route publique, aucune API, aucun webhook. Aucun `Mail::`, `Notification::`, `Http::`, `ShouldQueue`, ni CSV/téléchargement : les exports sont **P6-B1**, avec leur propre autorité, frontière de stockage et piste d'audit.

**Trois contrats historiques corrigés (portée, jamais affaiblissement)** :
- **P5-A3C** globait `app/Filament/Pages/*.php` et bannissait `csv`/`export` **globalement**. Une page d'export CRM (P6-B1) aurait fait échouer un contrat **Analytics** pour une raison étrangère à Analytics. Correctif : **inventaire explicite des 11 fichiers Analytics réels** + `toHaveCount(11)` fail-closed, **pas** d'exclusion générique « ignorer CRM » (une exclusion affaiblit la couverture définitivement). Deux tests ajoutés prouvent que le garde **garde ses dents** (une page Analytics gagnant `export`/`csv`/`Artisan::call` échouerait) et que les pages CRM sont **hors périmètre Analytics**.
- **P6-A0** bannissait la sous-chaîne `orders_count`, ce qui heurtait `acquired_orders_count` — la **vraie** colonne de rollup P6-A1.1 lue par la couche B0. ⚠️ **Cet échec préexistait à cette passe** : il a été introduit par le commit `0a62eb5` et la campagne P6-A0 n'avait pas été rejouée ensuite. Correctif : le ban vise désormais la colonne **legacy dénormalisée** via `/(?<!acquired_)orders_count/`, jamais la colonne honnête.
- **P6-A2** affirmait « aucune UI segment » de façon absolue. P6-B0 ajoute **exactement une** page. Correctif : la page autorisée est **nommée explicitement**, donc toute **autre** UI segment (ressource, widget, seconde page) échoue toujours.

**Les contrats de sécurité scannent du CODE, pas de la prose.** `Tests\Support\SourceScanner` retire les commentaires (tokenizer PHP, `{{-- --}}`/`<!-- -->` pour Blade) avant de chercher un jeton interdit. Sans cela, un fichier qui **documente** ce qu'il refuse de faire (« no `DB::table`, no raw SELECT ») déclencherait sa propre alarme — et la réponse habituelle, supprimer l'explication, dégrade le code. Quatre faux positifs de ce type ont été rencontrés et corrigés ainsi (`#[Url]`, `OFFSET`, `DB::table`, `getMessage()`).

ALTERNATIVES REJETÉES :
- **`SELECT` runtime direct sur les tables `crm_*`** pour éviter une migration → casse la frontière de privilèges de P6-A0/A1/A2 ; la migration `000026` est le prix honnête d'une UI de lecture.
- **Diviser les montants par 100 pour « faire joli »** → fausserait tout montant XOF de deux ordres de grandeur. Un nombre faux sur un écran CRM est pire qu'un nombre verbeux.
- **Exclure les fichiers CRM du contrat Analytics** → affaiblit le garde de façon permanente ; l'inventaire explicite le renforce.
- **Renommer/masquer `acquired_orders_count`** pour satisfaire un test à sous-chaîne → adapter le code honnête à un test imprécis ; c'est le test qui a été rendu précis.
- **Valider la définition uniquement en PHP** → PHP est contournable ; l'autorité PostgreSQL reste juge et le prouve par test.

TESTS (**campagnes ciblées**, la suite complète locale est **volontairement différée** au stack B1 qui contient B0) : P6B0 + P6B01 **113 tests / 655 assertions** — ContactList, ContactDetail, ExactEmailSearch, ConsentView, CommerceView, CurrentMembershipView, PublicExposure, SegmentList, SegmentDetail, SegmentLifecycle, SegmentBuilder, GenerationView, SecurityContract, plus les 3 fichiers B0.1 préexistants. Pint **446 fichiers**. **42 migrations**, `000026` présente, `000027` absente.

IMPACT : **P6-B0 TERMINÉ, MERGÉ ET VALIDÉ** via [PR #37](https://github.com/mysterus44/DigiTrove/pull/37), head `b05edb2`, merge `2df7e6f`, **CI SUCCESS**. Le merge a exigé un correctif final `b05edb2` : le CI standalone B0 a révélé que `P5A3AnalyticsSecurityContractTest` (jumeau A/B du fichier P5-A3C déjà corrigé) portait le **même glob trop large** et heurtait les commentaires de `CrmSegments.php` qui documentent leur propre absence d.export. Corrigé par inventaire explicite + compteur fail-closed + preuve de dents + preuve d.exclusion CRM. Suite complète locale B0 standalone : **1295 tests / 8250 assertions, 0 échec**. **P6-B1 est mergé à son tour (D-054).**

### D-053 : P6-B1 — Private Audited CRM Exports — ARCHITECTURE GELÉE (PLAN SEULEMENT) 📐
CONTEXTE : Cette décision **fige l'architecture** du gate `B1 exports` après audit du dépôt réel, afin que l'implémentation n'ait pas à refaire l'audit. **P6-B1 EST NON COMMENCÉ : aucun code, aucune migration `000027`, aucune table, fonction, job, commande, route, contrôleur ni UI d'export.** Le dépôt est à **42 migrations** (`000026` = P6-B0.1) et P6-B0 interdit explicitement toute surface d'export (contrat `P6B0SecurityContractTest`).

**AUDIT (faits vérifiés)** :
1. Le disque `private` existe (`config/filesystems.php`) : driver `local`, racine `storage_path('app/private')`, `visibility: private`, `serve: false`, `throw: true`. Le disque `public` est distinct et sert par URL.
2. `App\Services\Delivery\PrivateFileLocator` (P4-C5) fournit le modèle éprouvé : `assertOutsideTransaction()`, allowlist de disques privés, `validPath()` refusant `\0`, `\`, chemin absolu et segments `.`/`..`.
3. `DownloadFileController` (P4-C5) fixe les en-têtes : `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`, `X-Content-Type-Options: nosniff`, et refuse par un **404 plat** (jamais 403 : un statut distinguable est un oracle d'existence).
4. `P4B_ALLOWED_SERVICE_FILES` est une **allowlist fail-closed à égalité exacte** : tout nouveau fichier sous `app/Services` doit l'élargir explicitement.
5. Sept fichiers de tests portent des compteurs de migration ou une glob `000027` à relever (P6A10/P6A11/P6A12/P6A13/P6A2 Schema, P5A3C et P6A11 SecurityContract).

CHOIX (à implémenter en P6-B1, pas avant) :
1. **Deux `kind` exactement** : `crm_contacts` et `segment_current_members`. **CSV uniquement** — aucun XLSX, aucun JSON, aucun PDF. Privé, admin-only, audité, borné, expirant, sûr vis-à-vis des formules.
2. **Migration unique `000027`** (**43 migrations**, aucune `000028`) : table `crm_exports`, owner `digitrove_crm_executor`, **aucun accès runtime direct** (ni `SELECT` ni DML), PUBLIC sans accès, **aucun nouveau rôle**. Autorités `SECURITY DEFINER` bornées à `search_path` épinglé, runtime **EXECUTE-only**. `down()` restaure exactement la frontière `000026`.
3. **Snapshot de génération — invariant central.** Un export `segment_current_members` **gèle `crm_segments.current_generation_id` à la création** et le stocke. Chaque page lit la génération **gelée**, jamais `current_generation_id` à nouveau. Si un rebuild publie G2 pendant l'écriture de G1, le fichier reste **100 % G1** : c'est la seule corruption qu'un export paginé d'une cible mouvante peut produire, et elle est fermée structurellement (CHECK : `kind='segment_current_members'` ⇒ `segment_id` ET `generation_id` NOT NULL ; `kind='crm_contacts'` ⇒ les deux NULL).
4. **États** : `queued|running|completed|failed|expired`, avec CHECK de cohérence — `completed` exige `storage_disk`, `storage_path`, `size_bytes`, `checksum_sha256`, `row_count` et `completed_at` ; `failed` exige `failed_at` ; `expired` exige `expired_at`. Revendication `queued → running` **atomique** (`FOR UPDATE`), pour qu'un double worker ne produise jamais deux fichiers.
5. **Plafond de lignes strict** : lire `row_limit + 1`. En dépassement ⇒ `failed` + `terminal_reason='row_limit_exceeded'`, **aucun CSV final**, **aucune troncature silencieuse**. Un export partiel présenté comme complet est pire qu'un échec.
6. **Sûreté formule CSV** : préfixer d'une apostrophe toute cellule TEXTE commençant par `=`, `+`, `-`, `@`, TAB, CR ou LF. Vecteurs de test obligatoires : `=1+1`, `+SUM(...)`, `-2+3`, `@cmd`, TAB, CR, LF, `+email@example.com`, plus virgule, guillemet, CRLF, Unicode et multiligne. UTF-8.
7. **Aucun I/O sous transaction** (invariant D-036) : revendication (transaction courte) → COMMIT → lectures paginées + écriture fichier + checksum/taille → finalisation (transaction courte). Garde `assertOutsideTransaction()` sur le chemin stockage, comme `PrivateFileLocator`.
8. **Stockage privé** : disque privé réel, chemin **relatif généré par le serveur**, **aucune PII dans le chemin**, aucun disque public, aucune URL publique, aucun chemin absolu, aucune traversée (`validPath()`).
9. **Téléchargement admin strict** : authentifié, admin actif non supprimé, Gate `manageCustomerRelationships`, feature flag actif, export `completed`, non expiré, **et `requested_by_user_id === auth()->id()`**. Un **autre** admin est refusé. Aucune URL publique, aucun bearer, aucune URL signée anonyme. En-têtes de P4-C5 ; refus = **404 plat**.
10. **Job ID-only** `GenerateCrmExport` : payload `exportId` seul, `ShouldQueue` + `ShouldBeUnique`, **aucune PII** (ni e-mail, ni chemin, ni contenu) dans Redis, `jobs`, `failed_jobs`, une exception ou un log.
11. **Anonymisé ⇒ `email` NULL** dans le CSV (CHECK d'état P6-A0 : l'adresse est physiquement absente).
12. **Purge et recovery** : `crm:purge-expired-exports` et `crm:sweep-exports`, **bornées et idempotentes**, schedulers **désactivés par défaut**. La lecture DB se termine **avant** toute mise en file.
13. **Flags à `false` par défaut** : `CRM_EXPORTS_ENABLED`, `CRM_EXPORT_PROCESSING_ENABLED`, `CRM_EXPORT_PURGE_ENABLED`. Bornes : `CRM_EXPORT_MAX_ROWS=10000` (1..50000), `CRM_EXPORT_TTL_HOURS=24` (1..168), validées **avant toute écriture**.
14. **UI** : CRM → Exports (historique), action « Exporter les contacts », action « Exporter les membres courants » sur le détail d'un segment, avec confirmation explicite. Affiche `kind`, statut, `row_count`, création, expiration, disponibilité. **N'affiche jamais** le chemin de stockage, une exception brute, ni une quelconque éligibilité d'envoi.
15. **Dettes à relever** : `P4B_ALLOWED_SERVICE_FILES` doit être élargi explicitement pour tout nouveau service ; les sept fichiers portant un compteur de migration passent de 42 à 43 et la glob d'absence de `000027` devient `000028`.

ALTERNATIVES REJETÉES :
- **Relire `current_generation_id` à chaque page** → produirait un fichier mélangeant deux générations sans aucun signal.
- **Tronquer silencieusement au plafond** → un export partiel présenté comme complet fausse toute décision qui s'appuie dessus.
- **URL signée anonyme pour le téléchargement** → transforme un artefact CRM en ressource porteuse ; l'accès doit rester une décision serveur par requête.
- **Autoriser tout admin à télécharger l'export d'un autre** → l'export est un artefact nominatif et audité ; la propriété est une donnée d'audit, pas une commodité.
- **Écrire le fichier dans la transaction de revendication** → viole D-036 et tient une transaction PostgreSQL ouverte pendant un I/O disque.
- **XLSX/JSON en V1** → surface de parsing supplémentaire sans besoin démontré ; CSV avec neutralisation de formules suffit.

IMPACT : **P6-B1 NON COMMENCÉ, AUCUN CODE, AUCUNE MIGRATION `000027`.** Contrat d'architecture à appliquer après le merge de P6-B0.

### D-054 : P6-B1 — Private Audited CRM Exports — IMPLEMENTATION ✅ (MERGÉ)
CONTEXTE : Implémentation du gate gelé par D-053, empilée sur P6-B0 (`p6-b1-crm-private-exports`, base `7cecc93`). **Migration unique `000027`** (`2026_07_14_000027_create_crm_exports_table.php`) — **43 migrations**, aucune `000028`.

CHOIX :
1. **Table `crm_exports` + 10 autorités**, toutes `SECURITY DEFINER`, owner `digitrove_crm_executor`, `search_path` épinglé, objets qualifiés `public.`, **aucun nouveau rôle**, PUBLIC sans accès, runtime **EXECUTE-only**. Le runtime ne détient **ni `SELECT` ni DML** sur la table (prouvé par `has_table_privilege`). Les cinq autorités de lecture sont `STABLE`. `down()` restaure exactement la frontière `000026` (prouvé : 43 → 42, 0 objet B1 résiduel, les 7 autorités B0.1 intactes).
2. **Snapshot de génération — invariant central.** `create_crm_export` **gèle `crm_segments.current_generation_id` à la création** ; `list_crm_export_member_rows` lit cette génération **figée**, jamais `current_generation_id` à nouveau. Preuve : une G2 publiée **entre** la création et l'écriture ne fait apparaître **aucune** de ses lignes — le fichier reste 100 % G1. C'est la seule corruption qu'un export paginé d'une cible mouvante peut produire, et elle est fermée structurellement.
3. **Deux `kind` exactement**, séparés par CHECK : `segment_current_members` ⇒ `segment_id` ET `generation_id` NOT NULL ; `crm_contacts` ⇒ les deux NULL. Un export de segment **sans génération publiée est refusé** : un fichier vide affirmerait « ce segment n'a aucun membre », ce qui est une phrase différente et fausse.
4. **`completed` est une affirmation vérifiable** : un CHECK exige `storage_disk`, `storage_path`, `size_bytes`, `checksum_sha256`, `row_count` et `completed_at` ensemble. Sans checksum ni taille, « terminé » serait infalsifiable.
5. **Plafond strict, jamais de troncature.** Le générateur lit une ligne de plus que `row_limit` ; au dépassement l'export devient `failed` / `row_limit_exceeded`, **aucun fichier n'est publié** et `row_count` reste NULL. Un export partiel présenté comme complet fausse toute décision qui s'en sert.
6. **Sûreté formule CSV** (`App\Support\CrmExportCsvWriter`) : apostrophe de neutralisation devant toute cellule TEXTE commençant par `=`, `+`, `-`, `@`, TAB, CR ou LF. Le guillemetage CSV **ne suffit pas** — `"=1+1"` redevient une formule dès que l'importeur retire les guillemets. Seul le **premier** caractère décide, donc `a=b` reste intact. Vecteurs prouvés : `=1+1`, `+SUM()`, `-2+3`, `@cmd`, DDE `=cmd|' /C calc'!A0`, `=HYPERLINK(...)`, TAB/CR/LF, `+email@example.com`, plus virgule/guillemet/CRLF/Unicode/multiligne avec aller-retour par un vrai parseur. `NULL` ⇒ champ vide, **jamais** « NULL » ni un masque : un contact anonymisé n'a pas d'adresse.
7. **Aucun I/O sous transaction** (D-036) : revendication atomique `queued → running` (`FOR UPDATE`) → COMMIT → lectures paginées + écriture + checksum **hors transaction** → finalisation courte. Le garde `assertOutsideTransaction()` **refuse** plutôt que de faire confiance à l'appelant (prouvé : appel sous `beginTransaction()` ⇒ `RuntimeException`).
8. **Stockage privé** : `CrmConfig::exportDisk()` exige un disque **local, sans clé `url`, jamais `public`** — la propriété qui compte est l'**inatteignabilité HTTP**, pas la clé `visibility`. Chemin généré par le serveur (`crm-exports/<id>-<32 aléatoires>.csv`), **aucune PII**, aucune traversée, aucun chemin absolu.
9. **Téléchargement admin strict** : authentifié, admin actif, Gate `manageCustomerRelationships`, flag actif, `completed`, non expiré, **et `requested_by_user_id === auth()->id()`**. **Deux couches, deux réponses délibérément différentes** : au niveau *panel*, staff/customer reçoivent le 403 uniforme de Filament (identique pour toute URL du panel, donc sans information) ; au niveau *export*, un admin non propriétaire reçoit le **404 plat**, **octet pour octet identique** à celui d'un export inexistant — sinon le statut deviendrait un oracle d'existence sur les exports d'autrui. Aucune URL publique, signée ou bearer.
10. **Job ID-only** `GenerateCrmExport` (`ShouldQueue`, `ShouldBeUnique`, `uniqueFor=3600`, horizon de retry `3 × (300 + 300) = 1800 < 3600`) : payload = **un entier**. Aucun e-mail, chemin, checksum ou contenu dans Redis, `jobs`, `failed_jobs` ou un log. `failed()` ne persiste **jamais** le message du throwable.
11. **Purge et sweeper bornés et idempotents**, flags **indépendants** : `crm:purge-expired-exports` (transition DB d'abord, suppression fichier ensuite et hors transaction ; un second passage n'expire rien de neuf) et `crm:sweep-exports` (**recovery**, jamais de backfill ; la lecture se termine avant tout dispatch). Schedulers **désactivés par défaut** et gatés séparément, pour qu'activer le traitement n'active jamais implicitement la suppression d'artefacts.
12. **UI** : page `CrmExports` (historique, statut, `row_count`, création, expiration, disponibilité). Elle **n'affiche jamais** de chemin de stockage, d'exception brute, de SQLSTATE ni d'éligibilité d'envoi. ⚠️ **Écart assumé par rapport au plan** : l'export « membres courants » est déclenché **depuis la page Exports** (avec sélecteur de segment) et **non depuis le détail du segment**, parce que le contrat `P6B0SecurityContractTest` prouve que P6-B0 n'expose **aucune** affordance d'export ; cette preuve vaut plus que la commodité d'un second bouton, et tout export audité a désormais **un seul point d'entrée**.
13. **Flags à `false` par défaut** : `CRM_EXPORTS_ENABLED`, `CRM_EXPORT_PROCESSING_ENABLED`, `CRM_EXPORT_PURGE_ENABLED` ; bornes `CRM_EXPORT_MAX_ROWS` (1..50000) et `CRM_EXPORT_TTL_HOURS` (1..168) validées **avant toute écriture**.

**FRONTIÈRES HISTORIQUES DÉPLACÉES (portée, jamais affaiblissement)** — toutes révélées par la **suite complète**, aucune par les campagnes ciblées, ce qui est précisément leur utilité :
- **`P5A3AnalyticsSecurityContractTest`** portait le **même glob trop large** que son jumeau P5-A3C (corrigé en D-052) : `app/Filament/Pages/*.php` + interdiction de `export`/`csv`. La page `CrmExports` le faisait échouer pour une raison étrangère à Analytics. Correctif identique : **inventaire explicite des 11 fichiers Analytics**, `toHaveCount(11)` fail-closed, preuve que les pages CRM sont hors périmètre — **pas** d'exclusion générique.
- **Trois inventaires exacts de routes `download`** (`CatalogSchemaTest`, `P4BDownloadLogsTest`, `P4C3SecureDeliveryJobTest`) : la route d'export est **nommée explicitement** plutôt qu'exclue par motif, pour qu'une **quatrième** surface de téléchargement reste impossible à introduire sans décision. Elle est documentée comme n'étant **pas** une route de livraison : panel authentifié, propriétaire seul, 404 plat sinon.
- **Deux inventaires de fichiers fail-closed** dans `P4BDownloadLogsTest` : `CrmExportDownloadController.php` (dont le NOM matche `Download.*Controller`) et `GenerateCrmExport.php` sous `app/Jobs`, plus l'allowlist `P4B_ALLOWED_SERVICE_FILES` élargie de `Crm/CrmExportGenerator.php` et `Crm/CrmExportService.php` (ordre de tri vérifié contre le système de fichiers réel).
- **Cinq compteurs `DB::table('migrations')->count()`** relevés 42 → 43, en plus des sept compteurs de glob déjà relevés. Les bornes de rollback (`41` pour `000025`, `42` pour `000026`) restent **inchangées** : ce sont des frontières historiques, pas des mesures de l'état courant.

**DÉFAUT D'AUTORISATION RÉEL TROUVÉ ET FERMÉ** : `CrmExports::canAccess()` avait d'abord été écrit comme `CrmConfig::exportsEnabled() && parent::canAccess()`. **PHP aplatit une méthode de trait DANS la classe utilisatrice**, donc `parent::` ne désignait pas `AuthorizesCrmAdmin` mais `Filament\Pages\Page::canAccess()`, qui renvoie `true` : la Gate était **entièrement contournée** et la page d'export était accessible à `staff` et `customer`. Le code *paraissait* correct. Correctif : le trait expose désormais un hook `crmGateExtraCondition()` et `canAccess()` reste **sa** propriété — **la forme qui échoue de cette manière n'est plus disponible** pour une sous-classe.

ALTERNATIVES REJETÉES :
- **Relire `current_generation_id` à chaque page** → fichier mélangeant deux générations, sans aucun signal.
- **Tronquer silencieusement au plafond** → un export partiel présenté comme complet.
- **URL signée anonyme** → transforme un artefact CRM en ressource porteuse ; l'accès doit rester une décision serveur par requête.
- **403 pour « existe mais pas à vous »** → oracle d'existence sur les exports des autres admins.
- **Assouplir le contrat P6-B0 pour poser un bouton d'export sur la page Segments** → affaiblit une preuve déjà livrée ; l'entrée unique est préférable.
- **Exiger `visibility === 'private'` sur le disque** → mesure une clé de configuration, pas la propriété réelle (inatteignabilité HTTP), et casse tout test utilisant `Storage::fake()`.

TESTS (**suite complète locale du stack B0+B1 : 1377 tests / 8712 assertions, 0 échec**, contre une baseline avant B0 de 1178/7562) : P6B1 **84 tests** — Schema (11), Generation (8), CsvSafety (24), Download (8), Authorization (13), Expiration (8), Rollback (1), SecurityContract (11) — soit **B0+B1 agrégé 197 tests / 1112 assertions** (113 pour B0). Régression P4/P5 après déplacement des frontières : **152 tests / 1823 assertions**. Pint **463 fichiers**. **43 migrations**, `000027` présente, `000028` absente.

**RÉALIGNEMENT SUR LA STABLE APRÈS LE MERGE DE B0** : B1 avait pour base l'ancien HEAD B0 `7cecc93`, antérieur au correctif `b05edb2`. Réalignée par **merge normal** de la stable dans B1 (`bf9ea09`) — **jamais de rebase, jamais de force-push**, historique publié préservé. **Un seul conflit**, `tests/Unit/P5A3AnalyticsSecurityContractTest.php` : les deux branches avaient corrigé le même contrat Analytics. Résolu en prenant la version **stable en entier**, la plus forte (inventaire fail-closed + preuve de dents + preuve d'exclusion CRM), la version B1 antérieure étant plus faible. Delta de suite après réalignement : **+2 tests, −1 assertion** — exactement explicable, la version stable ajoute deux tests et consolide huit `not->toContain` en un `violations()->toBe([])`.

IMPACT : **P6-B1 TERMINÉ, MERGÉ ET VALIDÉ** via [PR #38](https://github.com/mysterus44/DigiTrove/pull/38), head `bf9ea09`, merge `474f92c`, mergé le 2026-08-09T23:04:27Z, **CI SUCCESS** (`Pint and tests`). Suite complète locale sur B1 réaligné : **1379 tests / 8711 assertions, 0 échec** (2393 s). **43 migrations**, `000027` présente, `000028` absente. Le prochain gate est **P6-C — Paniers / Relances**, dont l'architecture est gelée par **D-055** (non commencé, aucun code, aucune `000028`). Dépendance dure héritée : la dette D-030 `MAIL_MAILER=log` doit être close avant tout envoi réel en P6-C.

### D-055 : P6-C — Paniers / Relances — ARCHITECTURE GELÉE (PLAN SEULEMENT) 📐
CONTEXTE : Cette décision **fige l'architecture** du gate `C paniers/relances` du découpage P6 (`… → B0 vues → B1 exports → **C paniers/relances** → D affiliation`) après audit du dépôt réel. Le titre est repris **littéralement** de la roadmap : aucun intitulé plus précis n'est inventé. **P6-C EST NON COMMENCÉ : aucun code, aucune migration `000028`, aucune table, fonction, job, commande, route, UI ni intégration fournisseur.**

**AUDIT DU DÉPÔT RÉEL (faits vérifiés, pas des suppositions)** :
1. `carts` (migration `000005`) porte déjà : `public_id` UUID unique, **`secret_hash`** (unique, `CHECK ~ '^[0-9a-f]{64}$'`), `visitor_id` nullable, `user_id` nullable, `coupon_id` nullable, `currency` nullable, `status` (`CHECK IN ('active','converted','abandoned','expired')`), `expires_at`, **`abandoned_at`**.
2. **`carts` ne contient AUCUNE colonne e-mail.** Un panier invité n'a donc **aucune identité adressable**.
3. **`abandoned_at` et le statut `'abandoned'` existent mais AUCUN code applicatif ne les écrit** — seuls le cast/`fillable` du modèle et la factory les mentionnent. Il n'existe **aucune transition d'abandon** en production.
4. `cart_items` porte `cart_id`, `product_id`, `quantity` — **aucun snapshot de prix** (le prix vient de `PricingService` au checkout, D-030).
5. `crm_marketing_consent_events` (P6-A0) est limité à `channel='email'` / `purpose='promotional'`, avec `source` ∈ {checkout, account_settings} et version de politique obligatoire.
6. `MAIL_MAILER` vaut **`log` par défaut** (dette reconnue D-030) : tout envoi écrirait son contenu dans `storage/logs`.
7. Les `events` Analytics sont **non autoritatifs, sans FK transactionnelle** (D-037).

CHOIX (à implémenter en P6-C, pas avant) :
1. **Source autoritative = `carts` + `cart_items` + `orders` UNIQUEMENT.** Analytics n'est **jamais** autoritatif pour décider qu'un panier est abandonné ni pour déclencher une relance (D-037). Aucun `events`, aucune session, aucun rollup.
2. **Définition de l'abandon, explicite et durable.** L'abandon n'est **pas** une heuristique évaluée à la lecture : c'est une **transition écrite** (`active` → `abandoned`, `abandoned_at` posé) par une autorité bornée et idempotente, sur un critère d'inactivité explicite et configurable. Un panier `converted` ou `expired` n'est jamais abandonné rétroactivement.
3. **Identité — LA CONTRAINTE CENTRALE.** Un panier invité n'a **aucune adresse**. La V1 ne peut donc relancer qu'un panier dont le `user_id` pointe vers un compte **actif, non supprimé et vérifié**, dont l'e-mail est celui du compte. **Aucun e-mail deviné, aucun rapprochement approximatif CRM, aucune inférence `visitor_id` → contact, aucun folding d'alias.** Un panier invité sans compte est **hors périmètre V1** — et ce n'est pas une lacune à contourner : inventer une adresse serait une fuite.
4. **Jamais de relance vers un contact anonymisé.** `crm_contacts.status='anonymized'` ⇒ `email IS NULL` (CHECK d'état P6-A0) : l'adresse est **physiquement absente**, donc structurellement inadressable.
5. **Consentement vérifié À L'ENVOI, pas à la mise en file.** Une relance panier est une sollicitation marketing : elle exige un **grant courant** `email`/`promotional` (P6-A0), relu **au moment de l'envoi**. Un retrait entre la mise en file et l'envoi **annule** l'envoi. Appartenance à un segment ≠ éligibilité d'envoi (invariant D-052).
6. **Arrêt après achat.** Si le panier est passé `converted`, ou si une commande acquise couvre le même contenu, la relance est **annulée à l'envoi**, pas seulement au moment de la planification — la fenêtre entre les deux est réelle.
7. **Opt-out, cooldown, plafond de tentatives, déduplication, idempotence.** Un **ledger append-only** de relances (une ligne par tentative, sans PII : jamais l'adresse, jamais le contenu) porte une **clé d'idempotence** et fait respecter : un plafond de tentatives par panier, un délai minimal entre deux relances, et l'unicité d'une tentative par `(cart, étape)`. Sémantique **at-least-once** assumée pour l'e-mail — aucun exactly-once promis (précédent D-030 Q1).
8. **Reprise de panier sécurisée.** `carts.secret_hash` existe déjà (SHA-256, unique, contraint). Le lien de reprise porte `public_id` + un **secret brut jamais persisté, jamais logué, jamais reconstructible** — exactement la discipline P4-C (Q1 = A renforcée) : en cas de panne après COMMIT, on **révoque et réémet**, on ne « réessaie pas avec l'ancien secret ». Aucun secret dans une URL journalisée côté serveur sans nécessité, aucun identifiant de contact dans le lien.
9. **Frontière fournisseur.** **Aucun endpoint inventé.** Le port d'envoi est une abstraction ; l'adaptateur réel reste un scaffold documenté tant qu'il n'est pas fourni, comme PowerPay en P3-D4. ⚠️ **Bloquant** : `MAIL_MAILER=log` ferait fuiter le contenu et le lien de reprise dans `storage/logs`. P6-C ne doit **rien envoyer** tant que cette dette D-030 n'est pas close.
10. **Job ID-only, hors transaction.** Un job de relance ne transporte que des identifiants (`cart_id`, étape) — **jamais** d'e-mail, de secret, de lien ni de contenu dans Redis, `jobs`, `failed_jobs`, une exception ou un log (invariant D-030 Q2). Aucun I/O fournisseur sous transaction PostgreSQL (invariant D-036).
11. **ACL** : réutiliser `digitrove_crm_executor` (owner) et `digitrove_runtime` (EXECUTE-only) — **aucun nouveau rôle** sauf preuve contraire ; PUBLIC sans accès ; le runtime ne lit jamais directement les tables de relance.
12. **Rétention et audit** : le ledger est purgé par une commande bornée et idempotente ; il ne conserve **aucune PII** ni message fournisseur brut — au plus un code d'erreur stable et une raison terminale allowlistée.
13. **Feature flags à `false` par défaut**, sur le modèle P6-A1.2/A1.3/A2 : détection d'abandon, mise en file et envoi sont **trois** interrupteurs distincts, et le scheduler est désactivé par défaut.
14. **Rollback** : la migration P6-C devra restaurer exactement la frontière laissée par P6-B1.

ALTERNATIVES REJETÉES :
- **Déduire l'e-mail d'un panier invité** (via `visitor_id`, Analytics, un Order antérieur de même IP/session, ou un rapprochement flou CRM) → fabrique une identité non consentie et peut envoyer à la mauvaise personne. Un panier invité sans compte est simplement hors périmètre V1.
- **Traiter l'abandon comme une requête à la volée** (`WHERE updated_at < now() - interval`) → non idempotent, non auditable, et rejoue les relances à chaque passage du scheduler.
- **Considérer une relance panier comme transactionnelle** pour contourner le consentement → c'est une sollicitation commerciale ; le grant `promotional` est requis.
- **Vérifier consentement et conversion seulement à la mise en file** → la fenêtre file→envoi est réelle ; les deux contrôles doivent être refaits à l'envoi.
- **Inventer un endpoint fournisseur** → précédent explicitement refusé en P3-D4 (PowerPay).

IMPACT : **P6-C NON COMMENCÉ, AUCUN CODE, AUCUNE MIGRATION `000028`.** Contrat d'architecture à appliquer après le merge de P6-B1. Dépendance dure : la dette D-030 `MAIL_MAILER=log` doit être close **avant** tout envoi réel.


### D-056 : P6-C — Cart Abandonment & Reminder Implementation Contract ✅ (MERGÉ)
CONTEXTE : Contrat d'implémentation figé APRÈS audit réel du dépôt, en application de D-055. Il précise ce que D-055 laissait ouvert et **résout explicitement la contradiction sémantique** que D-055 portait (« ledger append-only » ET « tentative avec raison terminale »).

**AUDIT RÉEL — TROIS FAITS DÉCISIFS (vérifiés, pas supposés)** :
1. **Il n'existe AUCUN flux panier dans l'application.** `grep` sur `routes/*.php` : zéro occurrence de `cart`. Aucun contrôleur panier. Le **seul** code applicatif qui touche `carts` est `App\Services\Checkout\OrderService`, qui verrouille un panier existant et le passe `active → converted`. Aucun code ne crée de panier, n'ajoute d'article, ni ne génère de `secret_hash` — seule `CartFactory` le fait.
2. **`carts.updated_at` n'est PAS un signal d'activité valide.** `CartItem` ne déclare **aucun** `$touches`, donc même si un storefront existait, ajouter ou retirer un article ne toucherait pas le parent. Bâtir l'abandon sur `updated_at` serait une heuristique fausse — exactement ce que D-055 interdit.
3. **Le consentement est atteignable sans nouvelle autorité** : `find_crm_contact_by_exact_email` (P6-B0.1) → `public_id` → `has_current_marketing_consent` (P6-A0). Les deux sont déjà `SECURITY DEFINER` et EXECUTE-only pour le runtime.

⚠️ **PRÉCONDITION EXPLICITEMENT ASSUMÉE** : P6-C livre l'**infrastructure** d'abandon et de relance, pas un flux marchand. Tant qu'aucun storefront ne crée de panier, ce gate ne peut pas être exercé de bout en bout en production. C'est le même choix assumé qu'en P4-C (pipeline de livraison livré avant tout fournisseur de paiement réel) et qu'en P6-B1 (exports livrés flags à `false`). **Ce n'est pas un défaut caché : c'est une dépendance nommée.**

CHOIX :
1. **Signal d'activité — la plus petite addition possible.** `carts.last_activity_at TIMESTAMPTZ NOT NULL` est ajoutée par `000028`, adossée à un **trigger PostgreSQL sur `cart_items`** (INSERT/UPDATE/DELETE) et maintenue par l'autorité d'abandon. Le signal vit donc **dans la base**, à la couche qu'aucune future implémentation applicative ne peut contourner — et non dans un `$touches` Eloquent qu'un worker, une commande ou une requête brute ignorerait. `updated_at` n'est **jamais** surchargé : la transition d'abandon ne doit pas se compter elle-même comme activité.
2. **Résolution de la contradiction D-055 — option B.** Le ledger `cart_reminder_attempts` porte une **identité de tentative immuable** (`(cart_id, step)` unique) et des **transitions d'état monotones et bornées** : `pending → claimed → sent | suppressed | failed`. Une transition ne réécrit **jamais** un fait métier antérieur, ne revient **jamais** en arrière, et il n'y a **aucun DELETE hors purge de rétention**. Ce n'est donc pas un append-only strict de lignes, mais un **append-only sémantique** : chaque champ terminal est écrit une seule fois et n'est jamais réécrit. Option A (ligne + table d'événements) a été écartée : elle double le stockage et la surface ACL sans rien prouver de plus, l'auditabilité étant déjà assurée par l'immuabilité des champs terminaux.
3. **Cadence NON figée.** Aucune valeur marketing n'est décidée ici. Le délai d'inactivité, le cooldown, le plafond de tentatives et le nombre d'étapes sont **configurables et bornés**, validés **avant toute écriture**, et **fail-closed** si absents ou invalides lorsque le flag correspondant est actif. Le produit n'affirme pas qu'une cadence donnée est la bonne cadence.
4. **Identité V1 — comptes vérifiés uniquement.** Un panier n'est relançable que si `user_id` pointe vers un compte **actif, non supprimé, e-mail vérifié**. Un panier invité est **structurellement inadressable** (`carts` ne porte aucune colonne e-mail) et est **hors périmètre V1** : inventer une adresse serait une fuite. Aucun rapprochement `visitor_id` → e-mail, aucun matching flou CRM, aucune lecture Analytics (non autoritatif, D-037).
5. **Contact anonymisé ⇒ jamais adressable** (`crm_contacts.status='anonymized'` ⇒ `email IS NULL` par le CHECK P6-A0).
6. **Revalidation AU MOMENT EXACT DE L'ENVOI**, jamais seulement à la mise en file : tentative encore envoyable, panier toujours `abandoned`, utilisateur actif/non supprimé/vérifié, contact non anonymisé, **grant `email`/`promotional` courant**, panier non `converted`, aucune commande acquise couvrant le contenu, cooldown et plafond encore valides, flag d'envoi actif, **transport mail sûr**. Toute condition devenue fausse ⇒ **aucun envoi**, et une raison terminale allowlistée sans PII.
7. **Transport mail FAIL-CLOSED — dette D-030 traitée structurellement.** `.env.example` dit `smtp`, mais `config/mail.php` retombe sur `'default' => env('MAIL_MAILER', 'log')` **et** `MAIL_HOST` est vide dans l'exemple. Un garde dédié refuse donc, avant toute génération de lien exploitable : `log`, `array`, un mailer absent ou inconnu, une configuration de transport incomplète, et **récursivement** toute composition `failover`/`roundrobin` dont une branche retomberait sur un transport qui journalise ou absorbe. Un mailer nommé `smtp` n'est **pas** présumé prêt : ses valeurs réellement requises sont vérifiées. Le message de refus ne contient **aucun secret**.
8. **Aucun fournisseur inventé.** Ni endpoint, ni signature, ni webhook propriétaire. P6-C utilise l'abstraction Mail de Laravel avec un SMTP réel configuré par l'opérateur, documenté par `docs/integrations/MAIL_PROVIDER_SETUP.md`. Les tests utilisent exclusivement `Mail::fake()` ; **aucun appel réseau en CI**.
9. **Ledger sans PII.** Aucune adresse, aucun prénom, aucun contenu, aucun secret brut, aucun token, aucun lien, aucun message fournisseur, aucune stack trace, aucun payload mail. Autorisés : identifiants internes, étape, statut, horodatages, code d'erreur **SQLSTATE** stable, raison terminale **allowlistée**.
10. **Job ID-only** : payload = l'identifiant interne de la tentative, rien d'autre. Sérialisation vérifiée par test.
11. **Secret de reprise — discipline P4-C (Q1 = A renforcée).** Le secret brut est CSPRNG, vit **en mémoire seulement**, n'est **jamais** persisté, mis en file, logué, inclus dans une exception ni reconstructible ; seul son SHA-256 est stocké. ⚠️ **`carts.secret_hash` est unique et sert déjà d'identifiant de session panier** : une rotation invaliderait une session ouverte. P6-C **ne réutilise donc pas** cette colonne et n'ouvre pas silencieusement une seconde capacité d'accès ; le lien de reprise porte un secret **propre à la tentative**, dont la rotation n'affecte aucune session existante. Panne après COMMIT ⇒ **révoquer puis réémettre**, jamais « réessayer avec l'ancien secret ».
12. **Aucun I/O fournisseur sous transaction** (D-036) : claim court → COMMIT → revalidation autoritative → préparation du secret en mémoire → écriture courte du hash → COMMIT → I/O fournisseur → finalisation courte. Garde `assertOutsideTransaction()` avant tout I/O.
13. **Trois flags indépendants à `false` par défaut**, aucun n'en activant implicitement un autre : détection d'abandon, mise en file, envoi. Scheduler **désactivé par défaut**.
14. **ACL** : owner `digitrove_crm_executor`, runtime **EXECUTE-only**, PUBLIC sans accès, **aucun nouveau rôle**. Inventaire canonique unique ; rollback fail-closed lu dans `pg_catalog`.
15. **Sémantique at-least-once assumée** ; aucun exactly-once promis. Un crash après l'envoi fournisseur mais avant finalisation peut dupliquer côté fournisseur — mais **jamais** créer une seconde tentative métier, **jamais** contourner le plafond, **jamais** laisser un secret persistant.
16. **Migration unique `000028`** ⇒ **44 migrations**, aucune `000029`. `down()` restaure exactement la frontière `000027`.

ALTERNATIVES REJETÉES :
- **Bâtir l'abandon sur `carts.updated_at`** → `CartItem` n'a aucun `$touches` ; le signal serait faux dès qu'un storefront ajouterait un article.
- **Un `$touches` Eloquent au lieu d'un trigger** → contournable par toute écriture SQL, worker ou commande ; le signal doit vivre dans la base.
- **Réutiliser `carts.secret_hash` pour le lien de relance** → colonne unique servant déjà de session panier ; une rotation casserait une session ouverte et créerait une seconde capacité d'accès non auditée.
- **Deviner l'e-mail d'un panier invité** (visitor, Analytics, IP, commande antérieure) → fabrique une identité non consentie et peut écrire à la mauvaise personne.
- **Vérifier consentement et conversion seulement à la mise en file** → la fenêtre file→envoi est réelle et se mesure en heures.
- **Considérer une relance comme transactionnelle pour contourner le consentement** → c'est une sollicitation commerciale ; le grant `promotional` est requis.
- **Figer 24 h / 48 h / 3 relances** → aucune décision marketing n'a été prise ; ces valeurs seraient inventées.
- **Une table d'événements séparée (option A)** → double le stockage et la surface ACL sans renforcer l'auditabilité déjà garantie par l'immuabilité des champs terminaux.

**AUDIT D-030 REPOSITORY-WIDE — VERDICT : LOCAL FERMÉ, GLOBAL ENCORE OUVERT.**
Le dépôt ne contient que **deux** chemins d'envoi réels : `SecureDeliveryJob` →
`OrderDownloadsReady` (P4-C, porteur de tokens de téléchargement bruts dans le fragment
d'URL) et le futur envoi P6-C. P4-C **possède déjà** un garde,
`DeliveryConfig::assertMailerSafe()`, mais il est **strictement plus faible** que
`MailTransportGuard` sur cinq points vérifiés ligne à ligne :
1. il teste le **NOM** du mailer (`$mailer === 'log'`), pas le `transport` réellement
   résolu — un mailer nommé `smtp` dont `transport` vaut `log` passe ;
2. il **autorise `array`** en `local`/`testing`, alors que ce transport absorbe le message ;
3. il **n'refuse pas un transport inconnu** — un driver non revu passe ;
4. il ne vérifie **aucune préparation réelle** : `smtp` avec `MAIL_HOST` vide passe, et
   l'adresse `From` n'est jamais contrôlée ;
5. sa marche dans `failover`/`roundrobin` est **à un seul niveau et par nom**
   (`array_intersect($members, ['log','array'])`) : une branche nommée `backup` dont le
   transport est `log`, ou un `failover` imbriqué, passe.

**Conséquence** : `D-030 GLOBAL = OPEN`. Un déploiement peut encore faire retomber le
mail de livraison P4-C sur un transport qui journalise, et ce mail porte une capability
brute. **`D-030 LOCAL (P6-C) = CLOSED`** par `MailTransportGuard`, qui lit le transport
résolu, refuse `log`/`array`/`null`/inconnu/incomplet, contrôle l'expéditeur et **récurse**
dans les compositions avec garde de cycle. Aligner P4-C sur ce garde modifierait une
frontière de sécurité **déjà mergée** et sort du périmètre de P6-C : cela doit faire
l'objet de son propre gate revu. **Aucune documentation de ce dépôt ne doit déclarer
D-030 clos tant que ce gate n'a pas eu lieu.**

**PRÉCISIONS ARRÊTÉES PENDANT L'IMPLÉMENTATION** :
- **ACL Commerce de l'exécuteur.** Les autorités sont `SECURITY DEFINER` et s'exécutent donc comme `digitrove_crm_executor`, rôle provisionné pour le CRM et qui **ne possède rien dans Commerce** : sans grant, toute autorité échoue en `42501`. `000028` accorde le **minimum** — `SELECT, UPDATE` sur `carts` (transition d'abandon + trigger d'activité), `SELECT` sur `users`, `cart_items`, `orders`, `order_items` — et son `down()` les révoque **exactement**. ⚠️ **Aucune migration historique n'est modifiée** : `git diff` sur `database/migrations/` ne retourne que `000028`, en ajout.
- **Achat couvrant.** Prédicat « acquis » repris **tel quel** du dépôt (P6-A1.1) : `paid`, `partially_refunded`, `refunded`. Aucun statut `fulfilled` n'est inventé. Couverture **stricte** : un panier A+B dont seul A est acquis n'est **pas** couvert et reste relançable ; `pending`, `payment_review`, `cancelled` et `expired` ne suppriment jamais. La comparaison porte sur `order_items.purchased_product_id`, le snapshot immuable — **jamais sur l'e-mail**.
- **Reprise par fragment — réutilisation du patron P4-C mergé.** Le lien porte `#c=<capability>`. Un fragment n'entre **ni dans la ligne de requête HTTP ni dans `Referer`** : ni ce serveur ni un reverse-proxy ne le voient. Le bootstrap GET est **sans base de données** (donc identique pour un panier inexistant), sert une CSP à **nonce par réponse** sans `unsafe-inline`, et son script efface le fragment par `history.replaceState` **avant** de s'en servir, puis le transmet **une seule fois** dans un corps POST protégé par CSRF. Aucun `localStorage`, `sessionStorage`, cookie, `console` ni query string.
- **Continuation après rédemption.** Le résultat n'est pas un simple « succès » : la rédemption établit une **référence de session serveur, opaque et courte**, vers le `public_id` du panier, puis redirige vers une URL propre. **Aucun nouveau modèle persistant**, aucune frontière hors `000028`. La capability brute ne retourne **jamais** dans une URL.
- **Cycle de vie de la capability.** CSPRNG 256 bits, en mémoire uniquement, **SHA-256 seul au repos**, portée à **une tentative / un panier**, **TTL imposé par l'autorité PostgreSQL** (pas en PHP, donc le runtime ne peut pas l'élargir), révocable en effaçant le digest (ce que font `suppress` et `fail`). Elle est **rejouable dans son TTL et non one-time** : un client qui reclique le même e-mail ne doit pas être bloqué ; rejouer produit la **même** continuation et **aucune** seconde tentative. Un échec après COMMIT et avant livraison détruit définitivement le secret — le retry en frappe un nouveau et écrase le digest.
- **Garantie du code vs limite d'infrastructure.** Le code garantit : aucun `Log::`, aucune exception, aucune télémétrie, aucune queue, aucun stockage en clair. Le fragment ne quittant pas le navigateur, l'access log ne peut **pas** contenir le secret ; ce qui peut y figurer, ce sont le **chemin et le `public_id`**. La limite réelle restante est la journalisation éventuelle des **corps POST** par l'infrastructure, qui doit être proscrite en préproduction.
- **`unsafe-inline` n'est jamais introduit globalement** : la CSP est posée **par réponse** sur la seule surface de reprise.

IMPACT : **P6-C TERMINÉ, MERGÉ ET VALIDÉ** via [PR #39](https://github.com/mysterus44/DigiTrove/pull/39), head `b6b63f9`, merge `a5de60a`, **CI SUCCESS**. Suite complète sur la stable finale : **1461 tests / 9290 assertions, 0 échec** (baseline avant P6-C : 1379 / 8711). **44 migrations**, `000028` présente, `000029` absente. Le prochain gate est **P6-D — Affiliation**, **NON COMMENCÉ** et dont l.architecture n.est pas gelée : elle ne doit pas être inventée avant audit. Précondition nommée : **aucun flux panier n'existe encore** — ni route, ni contrôleur, ni création, ni mutation applicative. P6-C livre donc une **infrastructure backend dormante**, flags **OFF par défaut**, vérifiable par fixtures mais **jamais présentable comme une fonctionnalité de relance panier utilisable de bout en bout** tant qu'un storefront ne crée pas réellement de paniers. Tout futur flux panier devra respecter le contrat `last_activity_at` (maintenu par la base), ne jamais écrire `abandoned_at` lui-même, et ne jamais créer de tentative directement.
### D-057 : P6-D — Affiliation — ARCHITECTURE FIGÉE ET FONDATION BDD (P6-D0) 📐→✅
CONTEXTE : décisions produit arbitrées par KingKouda après l'audit pré-P6-D. Ce gate livre **uniquement** l'architecture figée et la fondation PostgreSQL (`000029`). **Aucune logique d'attribution, de calcul de commission, de candidature, de payout, aucune route, aucun contrôleur, aucun service, aucun job, aucun scheduler, aucun écran Filament, aucun cookie, aucun provider de paiement, aucune notification.**

**AUDIT MONÉTAIRE — TROIS FAITS QUI FIXENT LA CONCEPTION** :
1. **La remise est DÉJÀ par ligne.** `order_items` porte `line_subtotal_minor`, `line_discount_minor` et `line_total_minor`, avec les CHECK `line_subtotal_minor = unit_price_minor * quantity` et **`line_total_minor = line_subtotal_minor - line_discount_minor`**. La base de commission « montant après remise » **est donc exactement `line_total_minor`** : aucune allocation de remise n'est à inventer, et la répartition Hamilton du coupon a déjà été faite au checkout (D-030 Q3).
2. **Les remboursements ne descendent PAS à la ligne.** `refunds` s'attache à `payment_id` (et `payments.order_id` donne la commande), avec un `amount_minor` **au niveau commande**. Un remboursement partiel ne dit donc pas *quelle ligne* il rembourse. C'est le seul point qui exigeait une règle.
3. **Aucune politique fiscale n'existe** — `taxMinor` vaut explicitement `0` et P3-D1 « claims no fiscal policy ». « Hors taxes » est donc un non-sujet : le choisir reviendrait à inventer une fiscalité.

CHOIX :
1. **Un affilié est un `user`, jamais un rôle.** D-014 (validée avant P1) l'imposait déjà et rejetait explicitement « affiliation portée uniquement par `users.role` ». Un rôle ne peut porter ni statut de candidature, ni historique, ni solde, ni audit ; `affiliates.user_id` est **UNIQUE**.
2. **Admission `pending → active`**, avec `suspended`, `rejected`, `closed`. La transition est administrative ; P6-D0 ne livre que les états et leurs contraintes.
3. **Attribution : code saisi prioritaire, sinon dernier clic valide, fenêtre 30 jours.** L'attribution est **interdite après la création de la commande** — sinon un affilié pourrait revendiquer une vente déjà faite.
4. **Les UTM de `visitors` et la table `events` ne sont JAMAIS une autorité financière.** `visitors.first_touch_source/medium/campaign` sont des colonnes marketing, et D-037 a figé que les `events` sont non autoritatifs, sans FK transactionnelle. P6-D introduit donc `affiliate_touches`, une table **dédiée et financièrement autoritative**, plutôt que de détourner un signal marketing en preuve de rémunération.
5. **`affiliate_codes` et `affiliate_touches` restent SÉPARÉS.** Cardinalités et rétentions différentes : un code est durable et peu nombreux, une touche est volumétrique et périssable. Désactiver ou faire tourner un code ne doit jamais effacer l'historique des touches qui l'ont utilisé.
6. **Politiques versionnées, jamais rétroactives.** `affiliate_program_policies` porte `effective_from`, `effective_until` nullable et un statut. Toute modification crée une **nouvelle version** ; une politique déjà effective n'est jamais réécrite. Conséquences figées : un changement de taux ne vaut que pour les **commissions futures**, un changement de fenêtre que pour les **attributions nouvelles**, le délai de 14 jours est **snapshoté à la création de la commission**, et le seuil de retrait est évalué **à la demande** puis snapshoté dans le payout.
7. **Commission au niveau `order_item`.** Une commission référence `affiliate`, `order`, `order_item`, `attribution` et la **politique appliquée**. Sans cette granularité, un remboursement partiel ou une future règle par produit rendrait le calcul ambigu. `order_items` porte déjà un trigger anti-suppression, donc la cible est immuable.
8. **Snapshot immuable sur chaque commission** : taux en **basis points** (`INTEGER`), base retenue, devise et délai `payable`. Même patron que `orders.coupon_*_snapshot`, qui est le précédent établi du dépôt.
9. **Ledger append-only `affiliate_commission_entries`.** L'identité de commission est distincte de ses écritures : une correction **crée une écriture**, elle n'en réécrit jamais une. Allowlist réduite au nécessaire : `accrual`, `release`, `refund_reversal`, `admin_adjustment`, `payout_allocation`, `payout_reversal`. Les montants y sont **signés** — c'est le seul endroit du schéma où un montant peut être négatif.
10. **Remboursements par compensation, jamais par effacement.** Total ⇒ contre-écriture intégrale ; partiel ⇒ contre-écriture **quantifiée** ; commission déjà payée ⇒ **solde négatif reporté et auditable**. ⚠️ Comme `refunds` est au niveau commande (fait n°2), la répartition entre lignes réutilise **la convention Hamilton déjà autoritative** du dépôt (`App\Services\Pricing\DiscountAllocator`, D-030 Q3) : plus grand reste, tie-break **résidu ↓ → `product_id` ↑ → `line_id` ↑**, entiers seuls, somme des allocations **exactement** égale au montant remboursé. **Aucune règle d'arrondi nouvelle n'est inventée**, et aucune unité XOF n'est créée ni perdue.
11. **Payout manuel, XOF, seuil 10 000, validation administrative, aucun provider externe.** Un payout est **mono-affilié et mono-devise** : une commission dans une autre devise ne peut pas y entrer, et **aucune conversion n'existe** — cohérent avec P6-A1.1, qui interdit tout total multi-devises.
12. **Aucune donnée bancaire ou Mobile Money dans cette fondation.** Le payout ne porte qu'une **référence administrative non sensible**. Un numéro Wave/Mobile Money en clair est hors périmètre et devra faire l'objet d'un gate dédié.
13. **Le code d'affiliation est un identifiant PUBLIC, pas un secret d'authentification.** Il est normalisé et unique, désactivable **sans suppression**, et ne contient aucune PII.
14. **ACL fail-closed** : tables possédées selon le modèle existant, runtime **sans INSERT/UPDATE/DELETE direct**, aucun nouveau rôle, grants minimaux, revokes symétriques. **P6-D0 ne crée AUCUNE fonction `SECURITY DEFINER` opérationnelle** : leur contrat appartient à P6-D1/D2/D3 et les figer ici serait décider trop tôt.
15. **Aucune politique n'est insérée par la migration.** Amorcer une politique « active » en `000029` ferait croire à la plateforme qu'un programme d'affiliation tourne déjà, alors qu'aucun code applicatif ne l'utilise. Le bootstrap est **explicitement différé à P6-D1**.

⚠️ **PRÉCONDITION ASSUMÉE, IDENTIQUE À P6-C** : il n'existe **aucun storefront** ni flux panier applicatif, donc aucun clic réel à attribuer. P6-D0 livre une **fondation BDD dormante** ; elle ne doit jamais être présentée comme un programme d'affiliation opérationnel.

FRONTIÈRES DES GATES SUIVANTS : `P6-D1` politique active + identité affilié + codes · `P6-D2` touches et attribution autoritative · `P6-D3` moteur de commissions et compensations de remboursement · `P6-D4` payout administratif · `P6-D5` surfaces admin et reporting.

ALTERNATIVES REJETÉES :
- **`users.role = 'affiliate'`** → rejeté par D-014 ; incapable de porter candidature, statut, solde et audit.
- **Commission au niveau commande** → rend un remboursement partiel ou une règle par produit non calculables sans ambiguïté.
- **Taux mutable stocké sur `affiliates`** → détruit la temporalité : on ne saurait plus quel taux s'appliquait à une commission passée.
- **Réécrire une commission lors d'un remboursement** → détruit la piste d'audit ; seules des écritures compensatoires sont acceptables.
- **Fusionner `affiliate_codes` et `affiliate_touches`** → couple un objet durable et peu nombreux à un flux volumétrique et périssable.
- **Utiliser `visitors.first_touch_*` ou `events` comme preuve d'attribution** → un signal marketing non autoritatif ne peut pas justifier un versement d'argent.
- **Insérer la politique initiale dans `000029`** → ferait croire qu'un programme est actif avant tout code.
- **Convertir les devises pour atteindre le seuil de retrait** → invente un taux de change et viole l'interdiction de total multi-devises.

IMPACT : **P6-D0 = fondation BDD uniquement**, migration `000029`, **45 migrations**, aucune `000030`. Aucun flux de candidature, d'attribution, de commission, de payout ni de storefront n'est livré.

IMPLÉMENTATION (P6-D0, 2026-08-10) — **MERGÉE** via [PR #40](https://github.com/mysterus44/DigiTrove/pull/40), head `1e8aa79`, merge `dcdc966` (parents `7fede04` + `1e8aa79`), **CI SUCCESS**. Validation finale : P6-D0 **63 tests / 2546 assertions** (134 s), suite complète **1524 / 11835**, 0 échec (37,0 min), Pint **495**, rollback **45 → 44 → 45**, `git diff --check` propre, **45 migrations**, aucune `000030`. Preuve migrations : `git diff 7fede04...1e8aa79 --name-status -- database/migrations/` ⇒ **une seule ligne, `A .../000029`** ; **aucune migration historique modifiée**.

**Livré** : migration unique `2026_07_14_000029_create_affiliate_schema_foundation.php` (9 tables, **3 fonctions et 3 triggers — tous des gardes d'INTÉGRITÉ, aucune autorité opérationnelle**, index unique partiel « une seule politique active », `REVOKE ALL` sur les 9 tables **et** leurs séquences pour `PUBLIC` **et** `digitrove_runtime`), 7 fichiers de tests (`P6D0AffiliateSchemaTest`, `P6D0AffiliateInvariantsTest`, `P6D0AffiliateLifecycleTest`, `P6D0AffiliatePolicyVersioningTest`, `P6D0AffiliateCoherenceTest`, `P6D0AffiliateRollbackTest`, `P6D0SecurityContractTest`) et `tests/Support/AffiliateFixtures.php`. **Aucun fichier sous `app/`, `routes/`, `config/` ou `resources/`** — un contrat fail-closed scanne l'arbre entier et exige zéro mention d'« affiliate ».

DURCISSEMENT PRÉ-MERGE (audit KingKouda §3 → §7) — **quatre renforcements structurels** :

**(a) `visitor_id` — le CHECK de sujet était un VETO PERMANENT sur toute purge.** `CHECK (visitor_id IS NOT NULL OR user_id IS NOT NULL)` est **réévalué par l'UPDATE que produit `ON DELETE SET NULL`** : supprimer le visiteur ancre d'une touche anonyme échouait donc en `23514`, **pour toujours**. C'est exactement le blocage injustifié d'une politique de rétention que l'audit interdit. Remplacé par un **trigger `BEFORE INSERT`** : l'ancrage est exigé à la **création** (sans quoi la touche ne pourrait jamais être appariée), et une touche qui perd son ancre plus tard n'est **pas** ambiguë — elle n'apparie plus **rien**, ce qui est l'issue fail-closed, et l'attribution qui en découle conserve ses **propres** snapshots. Le choix `SET NULL` suit le **précédent du dépôt lui-même** : `orders.visitor_id` et `carts.visitor_id` sont tous deux `nullOnDelete`, donc une ligne financière ne dépend jamais de la survie d'un visiteur. **Aucun `CASCADE` n'existe dans le bloc** : les seules FK `SET NULL` sont six identités **non financières** (`reviewed_by`, `created_by`, `requested_by`, `approved_by`, et les deux sujets de touche).

**(b) Une politique effective est désormais PHYSIQUEMENT immuable.** Trigger `BEFORE UPDATE` : dès que `status <> 'draft'`, toute modification de `version`, `attribution_model`, `attribution_window_days`, `default_commission_bps`, `payable_delay_days`, `payout_threshold_minor`, `payout_currency`, `effective_from` ou `public_id` lève `23514`. Les transitions de statut et la fermeture d'`effective_until` restent permises : ce sont des mouvements de cycle de vie, pas des réécritures. **C'est ce qui rend vraie la promesse « modifiable plus tard depuis l'administration, sans altérer rétroactivement les commissions existantes »** — sans ce trigger, une politique réécrite rendrait le snapshot d'une commission passée inexplicable. **Aucune extension PostgreSQL n'est installée** : `btree_gist` n'est pas requis, aucune contrainte d'exclusion n'est créée, donc le rollback ne peut pas endommager une infrastructure partagée qu'il ne possède pas ; le chevauchement historique entre versions `superseded` est explicitement **différé à l'autorité P6-D1**.

**(c) Cohérences croisées rendues STRUCTURELLES (§7).** Des FK séparées ne prouvent que l'**existence** de chaque id, jamais leur **appartenance mutuelle** : une commission pouvait viser la commande 7 et une ligne de la commande 9 en satisfaisant chaque clé mono-colonne. Trois FK **composites** ferment cela — `(order_item_id, order_id) → order_items (id, order_id)`, `(attribution_id, order_id)` et `(attribution_id, affiliate_id) → affiliate_attributions`. ⚠️ La cible `order_items (id, order_id)` est un **index unique redondant ajouté par `000029` et retiré par son `down()`** — **aucun fichier de migration historique n'est modifié**, exactement le patron utilisé par P6-C pour ses ACL Commerce.

**(d) Ledger et payout : mono-affilié et mono-devise deviennent structurels (§6).** FK composites `(commission_id, affiliate_id)` et `(commission_id, currency)` sur le ledger ; quatre FK composites sur `affiliate_payout_items` (via une colonne `affiliate_id` **dénormalisée à cette seule fin**, jamais source de vérité puisque les clés la forcent à égaler les deux côtés). Payer la commission de l'affilié B dans le payout de A, ou mélanger XOF et USD dans un payout, est **refusé par PostgreSQL** — aucun taux de change ne peut être glissé pour atteindre un seuil de retrait. **Identités d'idempotence naturelles** plutôt qu'une clé opaque : index unique partiel « **un seul `accrual` par commission** » (le double paiement classique) et « **un seul `refund_reversal` par (commission, refund)** ». Un worker P6-D3 rejoué est arrêté par le stockage, pas par l'espoir que l'appelant s'en souvienne.

**TROIS DÉFAUTS RÉELS FERMÉS PENDANT L'IMPLÉMENTATION** (aucun n'a jamais tourné en dehors de la branche) :
1. **CHECK structurellement insatisfiable** — `base_kind_snapshot` était en `VARCHAR(24)` alors que `'line_total_after_discount'`, **la seule valeur que son propre CHECK autorise**, fait 25 caractères. La colonne est passée en `VARCHAR(32)`. Leçon : **aucun test de « valeur refusée » ne révèle ce défaut** ; seul un test de **valeur acceptée** le pouvait. Un CHECK n'est pas prouvé par ce qu'il rejette.
2. **Deux résidus de rollback** — `down()` faisait un drop en ordre inverse simple, ce qui heurte la **FK retour** `affiliate_commission_entries.payout_id → affiliate_payouts` (ajoutée après coup, une fois les deux tables créées) ; et il laissait survivre la fonction `enforce_affiliate_ledger_append_only`, car dropper la table emporte le **trigger** mais pas la **fonction**. Corrigé : `DROP CONSTRAINT IF EXISTS` du lien retour d'abord, puis drop inverse, puis `DROP FUNCTION`. Le test de rollback lit l'inventaire dans `pg_catalog` comme **liste exacte**, jamais par sondage ponctuel.
3. **Contrat de sécurité trop grossier** — la liste de tokens interdits confondait **la table `visitors` comme identité** et **ses colonnes `first_touch_*` comme signal marketing**, et criait donc sur une FK légitime. Corrigé **par précision, jamais par affaiblissement** : les cibles de FK hors du bloc affiliation sont désormais asserties comme **liste exacte et exhaustive** (`order_items`, `orders`, `refunds`, `users`, `visitors`), donc une future FK vers `events` ou `analytics_sessions` casse le test. Une assertion supprimée aurait aveuglé le contrat sur le risque réel.

**Sémantique retenue pour `visitor_id`** : c'est un **ancrage d'identité**, pas une preuve. `visitors` (migration `000004`, ère P1) est l'identité d'un visiteur pré-login, et un clic doit bien être rattaché à quelqu'un. Ce que D-057 interdit reste inchangé : lire `first_touch_source/medium/campaign` ou `events` comme **preuve** de qui a gagné une commission. La ligne `affiliate_touches` **est** le fait autoritatif. `ON DELETE SET NULL` laisse l'effacement possible, mais le `CHECK (visitor_id IS NOT NULL OR user_id IS NOT NULL)` est **réévalué par cette mise à jour** : effacer l'unique ancrage d'une touche anonyme **échoue bruyamment** au lieu d'orpheliner une ligne financière (prouvé par test).

**Pièges de fixtures rencontrés (à retenir pour P6-D2/D3)** : `orders` porte deux CHECK **DEFERRED** — « au moins un `order_item` » et « une commande `paid` exige exactement un `payment` `succeeded` ». L'Order, sa ligne et son paiement doivent donc committer **dans la même transaction, sur la même connexion**. Par ailleurs, un remboursement **total** exige `orders.status = 'refunded'` : une fixture de remboursement doit être **partielle** et passer l'Order en `partially_refunded`, sinon Commerce refuse — correctement.

⚠️ **Compteurs de frontière déplacés 44 → 45 dans 15 fichiers de test**, sous **les deux formes** (`glob(...)->toHaveCount(N)` **et** `DB::table('migrations')->count())->toBe(N)`), plus l'assertion « dernière migration » de `P6A11CommerceRollupSchemaTest`. C'est la confirmation du piège déjà documenté : **une campagne `--filter` ciblée ne voit pas les contrats d'inventaire des autres phases.**

⚠️ **CONTRAT HISTORIQUE RESCOPÉ (jamais affaibli)** : `CatalogSchemaTest` interdisait explicitement `affiliate_commissions` et `affiliate_payouts`, que P6-D0 crée désormais légitimement. Aucune campagne `--filter` ciblée ne l'exécute — **seule la suite complète l'a révélé**, exactement comme lors de P6-B0. Les deux entrées devenues légitimes sont remplacées par une **assertion d'inventaire exact des neuf tables**, donc une dixième table d'affiliation apparaissant sous le nez du Catalogue **casse toujours** le test. Les jumeaux `P3ACouponsCartsSchemaTest` et `P3BOrdersSchemaTest` ne listaient pas ces deux tables et restent verts.

⚠️ **PIÈGE POSTGRESQL DÉCOUVERT — `information_schema` EST FILTRÉ PAR PRIVILÈGES.** La première version du garde rescopé interrogeait `information_schema.tables` ; sous `digitrove_runtime` — le rôle par défaut de la suite, à qui P6-D0 n'accorde **rien** — la requête retourne une liste **vide**, donc le contrat **passait à vide et ne prouvait rien**. Corrigé en interrogeant `pg_class`/`pg_namespace`, qui ne filtrent pas. `Schema::hasTable()` reste sûr (la grammaire PostgreSQL de Laravel s'appuie sur `pg_class`). **Tout inventaire censé voir des tables verrouillées doit utiliser `pg_catalog` ou la connexion propriétaire.**

⚠️ **DETTE OUVERTE POUR P6-D1** : `P6D0SecurityContractTest` exige aujourd'hui **zéro** fichier applicatif mentionnant « affiliate ». P6-D1 devra le **rescoper par inventaire exact** des fichiers autorisés — comme l'ont fait P5-A3C et P6-B0 — **jamais** en supprimant l'assertion. Et `P4B_ALLOWED_SERVICE_FILES` devra être **élargie explicitement** dès le premier fichier sous `app/Services`.

### D-059 : P6-D1.1 — Cycle de vie affilié + codes — ARCHITECTURE GELÉE (PLAN SEULEMENT) 📐

CONTEXTE : P6-D1 est clos et mergé (D-058, PR #41, merge `aeac8a5d`, closure `19b128b6`, **46 migrations**). La frontière d'autorité PostgreSQL de l'affiliation existe : `digitrove_affiliate_executor` possède les 9 tables et 9 séquences, 5 autorités bornées, runtime **EXECUTE-only**, `PUBLIC` sans accès. P6-D1.1 réutilise cette frontière — il ne la rouvre pas et ne la contourne pas. **Aucun code n'est écrit dans ce gel.**

**TROIS FAITS MESURÉS DANS LE SCHÉMA RÉEL, QUI FIXENT LA CONCEPTION** :

1. **`affiliates.user_id` est UNIQUE.** Une seconde ligne de candidature pour le même compte est **structurellement impossible**. Toute re-candidature est donc une **transition d'état sur la ligne existante**, jamais une création.
2. **Les CHECK d'horodatage sont UNIDIRECTIONNELS.** `affiliates_status_timestamps_check` exige l'horodatage quand le statut correspond, mais **n'exige jamais le statut quand l'horodatage est posé**. Un `rejected_at` peut donc survivre sur une ligne redevenue `pending` — la base l'accepte.
3. **Aucun horodatage n'est jamais effacé, et aucun n'est répété.** `applied_at`, `approved_at`, `rejected_at`, `suspended_at`, `closed_at` sont des **marqueurs cumulatifs à une seule case**. Après `active → suspended → active → suspended`, il ne reste **qu'une** date de suspension. **Le snapshot ne peut pas porter l'historique** : il n'en a physiquement pas la place. Il n'existe par ailleurs **ni `reviewed_at` ni `reactivated_at`**.

CHOIX :

1. **SNAPSHOT + LEDGER — la décision structurante.** `affiliates` reste le **snapshot de l'état courant**, une ligne par `user_id`. Un **ledger append-only `affiliate_lifecycle_events`** conserve **toutes** les transitions. Le snapshot répond « quel est son état **maintenant** ? » ; le ledger répond « **comment** y est-il arrivé ? ». ⚠️ **L'historique complet ne doit JAMAIS être dérivé des colonnes `*_at` de `affiliates`** — le fait n°3 le rend impossible. C'est aussi pourquoi une table `affiliate_applications` a été **écartée** : elle n'aurait historisé que les candidatures, laissant les cycles suspension/réactivation répétés sans trace.

2. **Le ledger est append-only**, au même titre que `affiliate_commission_entries` : inséré, **jamais** modifié, **jamais** supprimé par le runtime, **jamais** réécrit pour « nettoyer » l'histoire, **conservé après fermeture**. Owner `digitrove_affiliate_executor` ; runtime **sans DML direct** ; écriture **uniquement** par les autorités métier, jamais isolément. Un événement doit permettre de déterminer : l'affilié · `from_status` · `to_status` · le type de transition · l'acteur (nullable) · l'instant · un `reason_code` structuré nullable. **Aucune taxonomie exhaustive n'est figée ici**, et **aucun texte libre n'est requis** — un motif obligatoire devra être une **liste bornée**, jamais un payload arbitraire. **Aucune PII n'est copiée dans le ledger** : il référence l'identité, il ne la snapshote pas.

3. **SÉMANTIQUE DES HORODATAGES DU SNAPSHOT — clarifiée, pas durcie.** `applied_at` = candidature **la plus récente** (une re-candidature la remplace). `approved_at` = **dernière approbation issue de `pending`** ; une simple réactivation `suspended → active` **ne la réécrit pas**. `rejected_at` = **dernier refus**, et il **peut légitimement rester non nul** après une re-candidature approuvée. `suspended_at` = dernière suspension. `closed_at` = fermeture terminale. ⚠️ **Ne PAS durcir le CHECK en `status = X ⟺ X_at IS NOT NULL`** : cela détruirait exactement la sémantique cumulative retenue et rendrait la re-candidature impossible.

4. **Ni `reviewed_at` ni `reactivated_at` ne sont ajoutés au snapshot.** Le dernier événement du ledger fournit déjà quand, qui et quelle transition. Les ajouter fabriquerait un **second historique incomplet** dans `affiliates`, avec un risque de divergence entre deux sources.

5. **MACHINE À ÉTATS FIGÉE.** Autorisées : `NONE → pending` · `pending → active` · `pending → rejected` · **`rejected → pending`** · `active → suspended` · `suspended → active` · `active → closed` · `suspended → closed`. Interdites : `pending → suspended|closed` · `rejected → active|suspended|closed` · `closed → *` · `active|suspended → pending`. **`closed` est TERMINAL** : aucune réactivation, aucune re-candidature. **`rejected` n'est PAS terminal.**

6. **Première candidature — atomique** : vérifier l'éligibilité du compte · créer la ligne `pending` · écrire l'événement. Deux candidatures concurrentes : `affiliates_user_id_unique` est le backstop structurel, un seul gagnant en `23505`.

7. **Re-candidature — atomique** : verrouiller la ligne · exiger `status = 'rejected'` · passer à `pending` · **remplacer `applied_at`** par le nouvel instant · **préserver `rejected_at`** · écrire l'événement. **Jamais** de `DELETE` de l'identité, **jamais** de seconde ligne, **jamais** d'effacement du refus antérieur.

8. **Éligibilité — dérivée du modèle réel, aucune notion inventée.** `UserStatus` vaut exactement `active|suspended|blocked` et `User` porte `SoftDeletes`. Sont donc refusés : compte **supprimé** (soft-deleted), `suspended`, `blocked`. Seuls les comptes **ordinaires** deviennent affiliés : `admin` et `staff` **ne deviennent pas affiliés en D1.1**. ⚠️ **`users.role` n'est jamais modifié** (D-014) et aucun rôle nouveau n'est créé. L'éligibilité est **relue DANS la transaction** avant approbation ou réactivation : un compte devenu inéligible entre-temps fait échouer la transition.

9. **CODES — un seul actif au maximum par affilié**, garanti par un **index unique partiel** `(affiliate_id) WHERE is_active`. ⚠️ Le schéma actuel **ne l'impose pas** : plusieurs codes actifs sont aujourd'hui possibles. C'est la faille structurelle que `000031` doit fermer.

10. **`active` ⇒ exactement un code actif** en fin de transaction. `pending`, `rejected`, `suspended`, `closed` ⇒ **zéro code actif**. L'index garantit « au plus un » ; **les autorités transactionnelles garantissent la cohérence statut ↔ code**. Un trigger de contrainte n'est pas exigé si autorités + index rendent tous les chemins runtime sûrs.

11. **Génération serveur uniquement.** Code produit par un **CSPRNG**, conforme au format existant `^[A-Z0-9]{4,32}$`, longueur raisonnable dans cette plage, **retry de collision borné**. **Aucun vanity code** en D1.1 — ni par l'affilié, ni par l'admin, ni par le client : un code choisi ouvre l'usurpation (`SUPPORT`, `ADMIN`), les mots offensants et le squattage de marque, que le format n'arrête pas. **Aucune PII encodée** dans un code.

12. **Non-réutilisation — déjà garantie.** `affiliate_codes_code_unique` est **GLOBAL** : un code désactivé reste **définitivement réservé**, pour tout affilié. ⚠️ **Ne jamais remplacer cette unicité globale par une unicité partielle** — ce serait ouvrir la réutilisation.

13. **Désactivation** : `is_active = false` **et** `deactivated_at` posé. Durcissement **recommandé** vers la cohérence bidirectionnelle (`active ⇒ deactivated_at IS NULL`), **uniquement si le préflight de migration prouve qu'aucune donnée existante ne serait réécrite**. **Aucun nettoyage silencieux.**

14. **Approbation `pending → active` — une seule transaction** : verrouiller · **reconfirmer l'éligibilité** · changer le statut · poser `approved_at` · **créer le code actif** · écrire l'événement. Si la création du code échoue, **l'approbation entière est annulée**. ⚠️ **Jamais d'affilié `active` sans code par demi-transaction.**

15. **Refus `pending → rejected`** : poser `rejected_at` · conserver l'identité · **zéro code actif** · écrire l'événement avec l'acteur admin. Re-candidature future possible.

16. **Suspension `active → suspended`** : verrouiller · statut · `suspended_at` · **désactiver le code actif** · écrire l'événement. Fin de transaction : **zéro code actif**, codes historiques conservés.

17. **Réactivation `suspended → active` — un NOUVEAU code, jamais l'ancien.** Réactiver un code désactivé rendrait son `deactivated_at` **faux** et son historique mensonger. L'ancien reste inactif, un nouveau est généré. **`approved_at` n'est pas réécrit** (choix 3).

18. **Rotation — réservée à `status = 'active'`**, transaction atomique : verrouiller l'affilié et son code actif · désactiver l'ancien · générer et insérer le nouveau · écrire l'événement. **Jamais deux codes actifs visibles**, même transitoirement ; l'index partiel est le backstop.

19. **Fermeture `active|suspended → closed` — terminale** : poser `closed_at` · **désactiver tout code encore actif** · écrire l'événement · **conserver toutes les lignes et tous les codes historiques**. ⚠️ **Jamais de `DELETE`** d'affilié ni de code — les neuf FK entrantes sont **toutes `RESTRICT`**, ce qui l'interdit déjà structurellement dès qu'une touche, attribution, commission, écriture ou payout existe.

20. **AUCUNE AUTORITÉ `issue_code` PUBLIQUE.** La création d'un code est un **effet interne** de `approve`, `reactivate` et `rotate`. Une autorité autonome permettrait de créer un code actif sur un affilié `pending`, `rejected`, `suspended` ou `closed` — exactement l'état incohérent que le choix 10 interdit.

21. **`approve` et `reject` vivent dans UNE SEULE autorité de revue.** C'est **une décision unique** sur un dossier `pending`, bornée à une action structurée `approve|reject`. Deux autorités concurrentes dupliqueraient la transition et créeraient une vraie course approve/reject. Ici, les deux verrouillent la **même ligne** : un gagne, l'autre voit un dossier qui n'est plus `pending` et reçoit un refus métier propre. **Jamais de double revue.**

22. **Autorités minimales** (noms SQL choisis à l'implémentation, **aucun CRUD générique**) : soumettre/re-soumettre une candidature · **réviser** (`approve|reject`) · suspendre · réactiver · fermer · faire tourner le code · lister candidatures et affiliés pour l'administration · lire le détail d'un affilié avec son historique et son code. Owner **`digitrove_affiliate_executor`**, `search_path` épinglé, objets qualifiés. **Aucun rôle nouveau.**

23. **IDEMPOTENCE — refus explicite, jamais succès silencieux.** `approve` sur `active`, `reject` sur `rejected`, `suspend` sur `suspended`, `close` sur `closed`, `reactivate` sur `active` ⇒ **refus**. Un « succès » masquerait qu'un autre administrateur a déjà traité le dossier entre-temps. La machine à états et les identités naturelles suffisent : **aucune clé d'idempotence artificielle**.

24. **CONCURRENCE** : deux candidatures initiales ⇒ `user_id UNIQUE` · `approve` vs `reject`, `suspend` vs `close`, `reactivate` vs `close`, re-candidature vs action admin ⇒ **`FOR UPDATE` sur la ligne affilié**, un gagnant, `closed` terminal · deux rotations ⇒ verrou affilié + code actif + **index unique partiel** · compte devenu inéligible pendant la revue ⇒ **relecture de l'éligibilité dans la transaction**.

25. **Ordre historique du ledger** : identité monotone et **déterministe** (horodatage + identifiant, ou équivalent stable selon les conventions du dépôt). **Aucune dépendance à un texte libre** pour ordonner l'histoire.

26. **SURFACE — backend complet + administration seule.** Fait mesuré : **le dépôt n'a AUCUNE zone client authentifiée** — les dix routes web sont l'accueil, le consentement analytics ×3, l'ingestion, la reprise de panier ×3 et le téléchargement, **sans un seul middleware `auth`**. Livrer login + espace client + formulaire ferait exploser le périmètre. D1.1 rend donc la candidature **capable côté domaine** ; l'admin peut l'enregistrer et la traiter. Le jour où l'espace client existera, il **appellera exactement les mêmes autorités** — ⚠️ **ne jamais construire deux workflows concurrents**.

27. **Filament D1.1 — admin uniquement** : liste des candidatures `pending` · recherche d'affiliés · détail · **historique lifecycle** · approuver/refuser · suspendre · réactiver · fermer · code courant · rotation. **Interdits** : tableau de bord financier, commissions, payouts, attributions, touches, analytics de campagne. Seules les informations **nécessaires à la revue** sont exposées.

28. **`000031` est NÉCESSAIRE.** Périmètre prévu : `affiliate_lifecycle_events` + ownership/ACL + invariants append-only · **index unique partiel « un seul code actif par affilié »** · durcissement bidirectionnel `is_active`/`deactivated_at` **si data-safe** · autorités D1.1 · `EXECUTE` runtime exact · `PUBLIC` révoqué · garde `up()` data-safe · garde `down()` **LOSSLESS-ONLY**. **Aucune table financière.**

29. **GARDE `up()` — refuser avant mutation.** Avant de créer l'index « un seul actif », `000031` doit **vérifier les données réelles**. Si un affilié possède déjà plusieurs codes actifs : **REFUSER AVANT TOUTE MUTATION**. Interdits : désactiver arbitrairement, garder « le plus récent », supprimer les autres, corriger en silence. D0/D1 sont largement dormants, mais **le garde doit exister quand même**.

30. **GARDE `down()` — LOSSLESS-ONLY, la leçon de P6-D1 est désormais une règle.** Le ledger contient une histoire qui n'existe **nulle part ailleurs** : le supprimer serait un rollback **destructif**. Base sans événement ⇒ downgrade exact possible. **Ledger peuplé ⇒ `down()` REFUSE AVANT TOUTE MUTATION.** Le préflight est la **première opération** du `down()`. ⚠️ Ne pas déclarer une migration réversible au motif qu'un catalogue vide se rétracte.

31. **PRÉCISION TEMPORELLE — ne pas généraliser le correctif de P6-D1.** L'élargissement à `timestamptz(6)` en `000030` répondait à une **contrainte mathématique de continuité instantanée** entre deux bornes de politique. Les transitions de cycle de vie sont **humaines** : la seconde suffit. **Ne pas modifier la précision** des horodatages de `affiliates` / `affiliate_codes` sans preuve fonctionnelle — et si cela devenait nécessaire, **reproduire le garde lossless**, sans quoi le défaut de P6-D1 se rejouerait à l'identique.

32. **CONTRATS HISTORIQUES — élargir nommément, jamais affaiblir.** `P6D0SecurityContractTest` (inventaire exact de 11 chemins ; couches `Console/Commands`, `Jobs`, `Listeners`, `Mail`, `Models` **restant vides**) · `P6D0AffiliateSchemaTest` (inventaire exact de **8 fonctions** `%affiliate%` et du **rôle unique**) · `P6D1*` (frontières préservées) · `P4B_ALLOWED_SERVICE_FILES` (**chemin par chemin, aucun joker**) · inventaires Filament · compteurs **46 → 47** **uniquement sur les contrats d'état courant**, les **frontières historiques ne bougent jamais**. ⚠️ **`pg_catalog`, jamais `information_schema`** pour tout audit d'ACL, de propriété ou d'inventaire.

HORS PÉRIMÈTRE — NON NÉGOCIABLE : aucune route publique, aucun `/affiliate/apply`, aucun formulaire public, aucun login, aucune zone client, aucun cookie, aucun paramètre de parrainage · **aucune anticipation de D2** (consommation du code dans une URL, capture de clic, touche, `code_then_last_click`, attribution de commande, storefront) — **les codes D1.1 existent sans être consommés publiquement, et c'est volontaire** · **aucun objet financier** : ni commission, ni accrual, ni reversal, ni payout, ni Hamilton.

D3 et D4 restent inchangés : **D3** commission au niveau `order_item`, répartition des remboursements via **`App\Services\Pricing\DiscountAllocator`** (Hamilton existant, **aucune seconde implémentation**) ; **D4** payout manuel.

ALTERNATIVES REJETÉES :
- **Table `affiliate_applications`** → n'historise que les candidatures ; les cycles suspension/réactivation répétés resteraient sans trace, alors que le snapshot ne peut pas les porter (fait n°3).
- **Dériver l'historique des colonnes `*_at`** → physiquement impossible : une seule case par type d'événement.
- **Durcir le CHECK en `status = X ⟺ X_at IS NOT NULL`** → détruit la sémantique cumulative et rend la re-candidature impossible.
- **Ajouter `reviewed_at` / `reactivated_at` au snapshot** → fabrique un second historique incomplet et divergent.
- **`rejected` terminal** → bannissement à vie pour un motif corrigeable, et incitation directe à créer un second compte — précisément ce que `user_id UNIQUE` cherche à empêcher.
- **Plusieurs codes actifs par affilié** → D2 devrait arbitrer « quel code a gagné » ; la question doit être fermée par le schéma.
- **Vanity code choisi** → usurpation, mots offensants, squattage de marque ; le format `^[A-Z0-9]{4,32}$` n'en protège pas.
- **Réactiver l'ancien code à la réactivation** → rend `deactivated_at` faux.
- **Autorité `issue_code` autonome** → permettrait un code actif sur un affilié non actif.
- **Deux autorités séparées `approve` et `reject`** → duplique la transition et crée une vraie course.
- **Candidature client dans D1.1** → aucune zone client authentifiée n'existe ; le gate déborderait sur login et espace client.

IMPACT : **P6-D1.1 = plan seulement, aucun code écrit dans ce gel.** L'implémentation utilisera **une** migration `000031` (**47 migrations**), créera le ledger `affiliate_lifecycle_events` et ses autorités sous `digitrove_affiliate_executor`, fermera la faille « plusieurs codes actifs », livrera une couche Laravel mince et une administration Filament, et rescopera **quatre** familles de contrats par inventaire exact. **Aucune surface publique, aucune capture de clic, aucun objet financier.**

### D-058 : P6-D1 — Frontière d'autorité affiliation + gouvernance des politiques — ARCHITECTURE GELÉE (PLAN SEULEMENT) 📐

CONTEXTE : P6-D0 est clos et mergé (D-057, PR #40, merge `dcdc966`, **45 migrations**). Le schéma d'affiliation existe et est **dormant**. Un préflight d'architecture a audité le dépôt réel — `pg_catalog`, migrations, tests, patterns P4-B0 / P6-A2 / P6-B0-B1 — et a découvert **une dette critique que la documentation D-057 ne nommait pas**. KingKouda a arbitré : **Q1 = A** (gouvernance seule en D1, cycle de vie affilié en D1.1) et **Q2 = A** (rôle exécuteur dédié). Ce gate **ne livre aucun code** ; il fige la frontière.

**LA DETTE CRITIQUE DÉCOUVERTE — MESURÉE, PAS SUPPOSÉE** : les neuf tables `affiliate_*` appartiennent à **`digitrove`**, le rôle migrateur **superuser**, alors que `crm_segments`, `crm_exports` et les autres surfaces d'autorité appartiennent à leur exécuteur dédié. Distribution réelle des fonctions `SECURITY DEFINER` du dépôt : `digitrove_crm_executor` **59**, `digitrove_analytics_executor` 1, `digitrove_analytics_rollup_executor` 1, `digitrove_download_executor` 1, `digitrove` **2** (partitions analytics, héritage antérieur à P4-B0). Créer une autorité `SECURITY DEFINER` sur l'affiliation en l'état la ferait **s'exécuter en superuser** — précisément la vulnérabilité fermée par **D-029.6 / P4-B0**. **`000029` n'est pas réécrite** : la correction appartient à `000030`.

CHOIX :

1. **P6-D1 = frontière d'autorité + gouvernance des politiques UNIQUEMENT.** Le cycle de vie affilié et les codes partent en **P6-D1.1**. Ce n'est pas un raccourci : c'est la même logique que **P4-B0 avant P4-B** et que **P6-B0.1 avant les vues** — la frontière de privilèges se pose **avant** que quoi que ce soit ne l'utilise. Aucune fonctionnalité n'est supprimée, elle est ordonnancée.

2. **Rôle `digitrove_affiliate_executor`**, domaine affiliation **uniquement**, `NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS **NOINHERIT**` — attributs alignés sur les 7 rôles existants. **Jamais réutiliser `digitrove_crm_executor`** : cela donnerait au CRM pouvoir sur les tables financières d'affiliation et réciproquement, une confusion de domaines contraire au moindre privilège.

3. **Le rôle est créé par le script de provisioning, PAS par la migration.** Précédent P4-B0 vérifié : `docker/postgres/provision-runtime-roles.sql` est idempotent, s'exécute **une fois par cluster** (les rôles sont **cluster-globaux**), et la commande `db:provision-runtime-roles` l'invoque. Les **ACL d'objets** vivent dans la migration pour que `migrate:fresh` les reproduise dans chaque base. `digitrove_affiliate_executor` est **NOLOGIN**, donc **aucun mot de passe** n'est manipulé — contrairement à `digitrove_runtime`.

4. **Le `down()` de `000030` ne supprime PAS le rôle.** Un rôle est cluster-global et potentiellement référencé par d'autres bases du même cluster ; le supprimer au rollback détruirait une infrastructure que le gate ne possède pas. Le `down()` restitue **exactement** la frontière post-`000029` : propriété rendue à `digitrove` (tables **et séquences**), privilèges révoqués, fonctions de gouvernance supprimées. C'est la stratégie du dépôt, examinée et non devinée.

5. **Transfert de propriété : les neuf tables ET leurs neuf séquences.** Oublier les séquences laisserait une dérive d'ACL invisible. ⚠️ Les trois fonctions trigger d'intégrité de `000029` (`enforce_affiliate_ledger_append_only`, `enforce_affiliate_policy_immutability`, `enforce_affiliate_touch_subject`) **restent possédées par `digitrove`** : elles ne sont **pas** `SECURITY DEFINER`, elles n'accordent rien, elles refusent. Mais l'implémentation **devra prouver par test** qu'elles se déclenchent toujours après le changement de propriétaire — P4-B0 a établi qu'un trigger se déclenche même sans `EXECUTE` pour le rôle déclencheur, et ce fait doit être **remesuré**, pas supposé.

6. **Autorités bornées, jamais de CRUD générique.** Cinq responsabilités exactement : créer un brouillon · modifier un brouillon · **publier** · lire la politique effectivement applicable · lister l'historique pour l'administration. **Aucune fonction `update_policy` générique.** Chaque autorité : `SECURITY DEFINER`, owner `digitrove_affiliate_executor`, `search_path` épinglé, objets qualifiés `public.`, paramètres préfixés, aucun SQL dynamique inutile. Runtime **EXECUTE-only** ; `PUBLIC` sans `EXECUTE` ; `digitrove_runtime` **sans DML direct** sur `affiliate_*`.

7. **SÉMANTIQUE TEMPORELLE — `status = 'active'` SIGNIFIE « EN VIGUEUR MAINTENANT », POINT.** C'est la décision la plus importante de D-058, et elle est prise **contre** le modèle qui aurait paru plus riche. Deux modèles étaient possibles :

   - **Modèle 1 (RETENU)** — `active` ≡ en vigueur. `effective_from` est **posé par la publication** (`now()`), jamais planifié. « Politique applicable à l'instant T » pour T = maintenant **est exactement** l'unique ligne `active`. Les deux notions **ne divergent jamais**.
   - **Modèle 2 (REJETÉ)** — `active` ≡ « tête publiée », éventuellement datée dans le futur. Il ferait **diverger** `status='active'` et « effectivement applicable », créerait une fenêtre où **aucune** politique n'est en vigueur alors qu'une est `active`, et exigerait une contrainte d'exclusion temporelle donc l'extension **`btree_gist`** — qu'aucune des 45 migrations n'installe.

   **Conséquence assumée et explicitement nommée : P6-D1 ne supporte QUE la publication immédiate.** La publication différée (« au 1ᵉʳ septembre, le taux passe à 10 % ») **n'est pas livrée**. Elle reste possible plus tard **sans rouvrir cette frontière** : un gate ultérieur ajouterait un statut `scheduled` et un ordonnanceur qui appelle **la même autorité de publication** à l'instant voulu. Le choix du Modèle 1 n'interdit rien, il ne le livre pas.

   ⚠️ **Verrue connue et acceptée** : `effective_from` est `NOT NULL`, donc un brouillon doit porter une valeur. Tant que la politique est `draft`, cette valeur **n'est pas autoritative** — la publication l'écrase. L'écran d'administration **ne doit pas** la présenter comme un contrôle de planification, sous peine de promettre une fonctionnalité inexistante.

8. **PUBLICATION ATOMIQUE — L'INVARIANT CENTRAL.** Publier un successeur **et** fermer son prédécesseur est **une seule transition PostgreSQL atomique**, dans **une seule autorité**. Six états sont rendus inatteignables par la publication normale : deux politiques actives · **zéro** politique active à cause d'un échec en cours de route · chevauchement historique · trou temporel · prédécesseur fermé sans successeur publié · successeur publié sans fermeture du prédécesseur.

   **Point de conception précis, à ne PAS recopier aveuglément de `publish_crm_segment_version`** : ce précédent utilise `clock_timestamp()`. L'autorité d'affiliation doit utiliser **`now()`** (horodatage de transaction), parce que `predecessor.effective_until` et `successor.effective_from` doivent recevoir **la valeur identique**. Avec un intervalle **semi-ouvert `[from, until)`**, cela produit **ni trou ni chevauchement**, par construction. `clock_timestamp()` avance entre deux instructions et fabriquerait un trou de quelques microsecondes — invisible en test naïf, réel en audit.

   Ce que le précédent apporte en revanche et qu'il faut reprendre : `FOR UPDATE` sur les lignes concernées, refus explicite par SQLSTATE de toute transition invalide, et **refus de publier une version dont le numéro n'est pas supérieur** à celui de la politique active.

9. **CE QUE `000029` GARANTIT DÉJÀ, ET QUI NE DOIT PAS ÊTRE REFAIT** : index unique partiel `((status)) WHERE status='active'` (au plus une active) · trigger d'immuabilité dès `status <> 'draft'` · `version` UNIQUE · bornes `0..5000` bps, 1..365 jours, seuil ≥ 0, devise 3 majuscules · `effective_until > effective_from`. ⚠️ Le trigger d'immuabilité **autorise** la publication : sa garde est `IF OLD.status = 'draft' THEN RETURN NEW`, donc un `UPDATE` posant `effective_from` **et** `status='active'` sur un brouillon passe. Vérifié contre le code réel.

10. **IDEMPOTENCE PAR IDENTITÉS NATURELLES — aucune clé opaque n'est nécessaire.** Créer un brouillon : `version` **UNIQUE** ⇒ double clic = `23505` sur le second, à traduire en **refus explicite**, jamais en retry aveugle. Modifier un brouillon : rejouable, résultat déterministe (un brouillon est mutable par conception). Publier : `FOR UPDATE` sérialise ; **un seul admin gagne**, le perdant reçoit une **erreur métier sanitisée**, l'état final reste cohérent ; un replay HTTP ne crée **ni second successeur, ni période cassée, ni fermeture répétée destructive**. Modifier une politique effective : refusé `23514` par le trigger, y compris au replay. **Timeout** : tout se joue dans **une** transaction, donc l'appelant n'a jamais à deviner si la moitié a réussi. Toute classification de contrainte passe obligatoirement par **`App\Support\PostgresConstraintViolation`** (D-030.2.1) — SQLSTATE **et** nom exact, jamais une sous-chaîne de message.

11. **Couche Laravel — mince, sans DML arbitraire.** Un concern miroir de `UsesCrmAuthority` : refus de toute **transaction ambiante** (`DB::transactionLevel() !== 0`), vérification que `session_user` **et** `current_user` valent `digitrove_runtime`, exceptions sanitisées en erreur opaque. Un service dédié aux politiques. Une configuration **fail-closed** si une configuration est nécessaire. Une Gate/policy réservant la gouvernance aux administrateurs actifs non supprimés. **Aucune connexion ne doit permettre du DML arbitraire sur les neuf tables** — l'ACL le rend structurellement impossible, et le service ne doit pas chercher à le contourner.

12. **Surface Filament — gouvernance seule.** Autorisé : voir la politique en vigueur · consulter l'historique des versions · créer un brouillon · le modifier · publier. **Interdit** : candidature · liste opérationnelle d'affiliés · création/rotation de codes · touches/clics · attribution · commissions · ledger · payout. Montants en unités mineures avec devise explicite, **aucune division par 100**, **aucun total multi-devises** — invariants hérités de P6-B0.

13. **`000030` : UNE SEULE MIGRATION ADDITIVE**, sauf impossibilité technique démontrée. Elle porte : propriété (tables **et** séquences) · ACL `EXECUTE`-only · les cinq autorités de gouvernance · les invariants de publication qui doivent réellement vivre dans PostgreSQL. **Aucune migration historique modifiée. Aucune extension partagée** sans justification extraordinaire. **Aucune modification de `000029`.**

14. **FRONTIÈRES HISTORIQUES — ÉLARGIR EXACTEMENT, JAMAIS AFFAIBLIR.** `P4B_ALLOWED_SERVICE_FILES` : ajouter les chemins D1 **un par un**, **aucun joker** ; le garde est une liste littérale comparée par `array_diff`, donc un service inattendu continue de casser le test **nommément**. `P6D0SecurityContractTest` : remplacer « zéro surface affiliation » par un **inventaire exact** des surfaces D1 autorisées ; tout fichier affiliation supplémentaire doit casser le contrat. Les tests « aucun rôle `%affiliate%` » (`P6D0AffiliateSchemaTest`, `P6D0AffiliateRollbackTest`) deviennent un **inventaire exact autorisant uniquement `digitrove_affiliate_executor`** — aucun second rôle ne doit pouvoir apparaître silencieusement. Compteurs de frontière **45 → 46** sous **les deux formes**, plus l'assertion « dernière migration ».

15. **`pg_catalog`, JAMAIS `information_schema`, POUR TOUT AUDIT D'ACL, DE PROPRIÉTÉ OU D'INVENTAIRE.** Leçon payée en D-057 : `information_schema` est **filtré par privilèges**, et sous `digitrove_runtime` — à qui l'affiliation n'accorde rien — il retourne une liste **vide**, donc un contrat écrit ainsi **passe à vide et ne prouve rien**. Cette leçon est conservée explicitement et s'applique d'autant plus à D1, qui audite précisément de la propriété et des privilèges.

HORS PÉRIMÈTRE P6-D1 — NON NÉGOCIABLE : candidature affilié · approbation/rejet · suspension/fermeture · émission, rotation ou désactivation métier de codes · route publique · cookie · tracking · touche · capture de clic · attribution de commande · commission · passage `pending`/`payable` · `accrual` · `refund_reversal` · **Hamilton** · payout · provider · job · scheduler · campagnes · reporting financier d'affiliation.

⚠️ **RAPPEL HAMILTON** : P6-D3 devra réutiliser l'autorité existante **`App\Services\Pricing\DiscountAllocator`** (plus grand reste, tie-break `résidu ↓ → product_id ↑ → line_id ↑`, D-030 Q3). **Aucune seconde implémentation ne sera acceptée.**

FEUILLE DE ROUTE AFFILIATION ENREGISTRÉE :
- **P6-D1** — autorité PostgreSQL + gouvernance des politiques versionnées *(ce gel)*
- **P6-D1.1** — cycle de vie affilié (candidature, validation/refus, activation, suspension, fermeture) + codes. ⚠️ La **re-candidature après `rejected`** est **volontairement différée à son propre préflight** : `affiliates.user_id` est **UNIQUE**, donc le schéma impose une transition d'état sur la ligne existante plutôt qu'une seconde ligne — c'est une décision produit, pas une découverte d'implémentation. **Ne pas modifier `affiliates` ni créer de table de candidature avant ce gate.**
- **P6-D2** — touches + résolution `code_then_last_click` + attribution de commande. ⚠️ Le dépôt n'a **toujours aucun storefront** : **ne jamais annoncer D2 end-to-end** tant qu'aucun flux public réel n'existe.
- **P6-D3** — commissions par `order_item` + `accrual` + `pending`/`payable` + remboursements/reversals **via `DiscountAllocator`**
- **P6-D4** — payout manuel, mono-affilié, mono-devise

Aucun **D5** n'est créé artificiellement : les surfaces d'administration et le reporting sont absorbés par le gate qui les justifie. Le découpage après D4 sera réévalué **à partir du dépôt réel**, pas anticipé ici.

ALTERNATIVES REJETÉES :
- **D1 = gouvernance + cycle de vie affilié (option B du préflight)** → mélange dans une même revue une décision d'infrastructure de sécurité et un flux d'identité utilisateur ; livrerait des codes publics **sans usage** puisque D2 n'existe pas. Le dépôt a déjà tranché deux fois dans le sens inverse (P4-B0, P6-B0.1).
- **Réutiliser `digitrove_crm_executor`** → aucun rôle nouveau, mais confusion de domaines : le CRM obtiendrait pouvoir sur les tables financières d'affiliation.
- **Laisser la propriété à `digitrove`** → toute `SECURITY DEFINER` s'exécuterait en **superuser**. C'est la vulnérabilité fermée par D-029.6.
- **Contrainte d'exclusion `tstzrange` + `btree_gist`** → exige une **extension partagée** qu'aucun gate n'installe et qu'un `down()` ne peut pas retirer sans risquer d'autres phases ; et surtout elle exprime « les intervalles ne se chevauchent pas », alors que l'invariant réel est **une transition atomique**, pas un prédicat statique.
- **DML direct du runtime, ou service Laravel avec accès table** → exigerait de rendre au runtime le pouvoir de réécrire un taux, défaisant l'ACL de `000029`.
- **`clock_timestamp()` dans l'autorité de publication** → fabriquerait un trou temporel de quelques microsecondes entre la fermeture et l'ouverture.
- **Supprimer le rôle au `down()`** → détruirait une identité cluster-globale possiblement partagée par d'autres bases.

IMPACT : **P6-D1 = plan seulement, aucun code écrit dans ce gel.** L'implémentation utilisera **une** migration `000030` (**46 migrations**), créera le rôle via le script de provisioning, transférera la propriété des neuf tables et de leurs séquences, posera cinq autorités bornées et l'ACL `EXECUTE`-only, livrera une couche Laravel mince et un écran Filament de gouvernance, et rescopera **quatre** familles de contrats historiques par inventaire exact. **Aucune candidature, aucun code affilié, aucune touche, aucune attribution, aucune commission, aucun payout.**

### D-060 : Re-séquencement Storefront MVP avant P6-D1.1 ✅

CONTEXTE : P6-D1.1 dispose d'une architecture validée dans D-059, mais DigiTrove ne
possède encore aucun storefront transactionnel. Sans catalogue dynamique, panier public
et checkout, aucune vente réelle ni touche affiliée ne peut alimenter les gates D2/D3.
KingKouda priorise donc le chemin de revenu minimal avant la poursuite de l'affiliation.

CHOIX :
1. **P6-D1.1 est mis en pause, pas annulé.** D-059, sa machine à états, son ledger et ses
   invariants restent intégralement autoritatifs pour la reprise future.
2. Le WIP est préservé sur `p6-d1-1-affiliate-lifecycle-codes` au checkpoint
   `f15d192566c4c968fd00a9eb03bd5159e6cc52ba`. Cette branche contient un brouillon
   `000031` non livré. La stable `4407fca` reste à 46 migrations, sans `000031`.
3. Le sprint actif devient **Storefront MVP invité** : XOF sans sélecteur, quantité 1,
   coupons non exposés, aucun login/register/espace client. Le schéma multi-devises et le
   moteur coupons restent intacts pour les évolutions futures.
4. Avant le catalogue, deux prérequis sont séquentiels : fermeture **D-030 GLOBAL** pour
   qu'aucun lien de livraison ne tombe dans un transport mail de journalisation, puis
   correction de la récupération concurrente de `PaymentInitiationService`.
5. Aucun travail P6-D1.1 ne doit être mélangé à la branche Storefront. Sa reprise exigera
   une décision explicite après livraison et validation du parcours de vente.

ALTERNATIVES REJETÉES : continuer D1.1 avant toute vente réelle ; supprimer ou réinitialiser
le WIP ; mélanger son brouillon `000031` aux prérequis Storefront ; lancer plusieurs agents
en parallèle sur le même worktree.

IMPACT : branche active `codex/storefront-mvp-prerequisites` depuis `4407fca` ; aucune
migration Storefront créée par cette décision. Prochaine séquence : D-030 GLOBAL, bug
concurrent paiement, puis catalogue dynamique avec provisionnement produit à valider.

### D-061 : Autorité mail partagée — D-030 GLOBAL fermé ✅

CONTEXTE : D-056 avait fermé localement le risque mail de P6-C avec
`MailTransportGuard`, mais démontré que P4-C conservait cinq contournements dans
`DeliveryConfig::assertMailerSafe()` : contrôle du nom au lieu du transport résolu,
`array` toléré en test/local, transport inconnu accepté, SMTP incomplet accepté, et
composition `failover`/`roundrobin` inspectée sur un seul niveau. P4-C peut envoyer des
capabilities de téléchargement brutes ; un fallback `log` les exposerait dans les logs.

CHOIX :
1. **Une autorité unique.** `DeliveryConfig::assertPipelineReady()` délègue désormais à
   `MailTransportGuard::assertSafe()`. L'ancien garde privé plus faible est supprimé ;
   aucune seconde implémentation de la politique mail ne subsiste.
2. **Fail-closed dans tous les environnements.** Le transport réellement résolu est
   contrôlé. `log`, `array`, `null`, un transport absent/inconnu ou incomplet sont refusés,
   y compris en `local`/`testing`. Toute branche d'une composition imbriquée est auditée,
   avec profondeur bornée contre les cycles. Un expéditeur configuré est obligatoire.
3. **SMTP réel, secrets hors dépôt.** `.env.example` sélectionne `smtp` mais laisse
   volontairement `MAIL_HOST`, username et password vides : ce template ne peut donc pas
   activer un envoi. L'opérateur configure hôte, identité, secret et expéditeur uniquement
   dans le `.env` non suivi, puis valide le domaine et un envoi sandbox. Aucun credential,
   endpoint propriétaire ni appel fournisseur n'est ajouté au dépôt.
4. **Tests sans réseau.** Le chemin P4-C prouve lui-même les cinq classes de refus et un
   SMTP complet accepté ; les contrats P6-C restent l'autorité exhaustive. Les tests
   utilisent uniquement la configuration Laravel et `Mail::fake()` : aucun réseau CI.

VALIDATION CIBLÉE : `P4C0QueueMailSecretSafetyTest` + `P6CMailTransportSafetyTest` =
**33 tests / 94 assertions**, 0 échec ; `git diff --check` propre.

VALIDATION GLOBALE DU GATE : P3-D3 **55 tests / 257 assertions** ; suite complète
**1571 tests / 12167 assertions** ; Pint **510 fichiers** ; `git diff --check` propre.
La course d'idempotence conserve un budget de verrou explicitement inférieur au timeout du
processus et prouve que l'appel perdant est encore bloqué au commit du gagnant ; une lenteur
CI ne peut donc plus être confondue avec la collision `23505` visée.

IMPACT : **D-030 GLOBAL = CLOSED** sur la branche de prérequis Storefront. Les deux seuls
chemins d'envoi réels connus, livraison P4-C et relance P6-C, partagent la même frontière.
Le pipeline de livraison reste désactivé tant que sa configuration opérationnelle et un
vrai SMTP ne sont pas fournis hors dépôt. Aucune migration et aucun secret ajoutés.

### D-072 : H2.1 / H2.2 / H2.5 — en-têtes, cookie de session, throttle webhook ✅

CONTEXTE : premier lot du durcissement pré-production. **Aucune fonctionnalité nouvelle,
aucune migration** — une revue de l'existant, corrigée fail-closed là où un défaut réel a
été mesuré. Trois des huit points de l'audit H2, ceux qui bénéficient immédiatement aux
endpoints Genius Pay tout juste mergés.

DÉCISIONS :

1. **`SecurityHeaders` est un PLANCHER, jamais un plafond.** Middleware global, il
   n'écrase **aucun** en-tête déjà posé. Cinq contrôleurs — landing de téléchargement,
   fichier, autorisation, reprise de panier, export CRM — servent déjà une politique
   `default-src 'none'` à nonce ; un middleware qui les écraserait rabaisserait
   **silencieusement les pages les plus sensibles de l'application à la politique la plus
   permissive qu'elle contienne**.
2. ⚠️ **DÉFAUT RÉEL TROUVÉ AU NAVIGATEUR, INVISIBLE AUX TESTS.** La première CSP **tuait
   entièrement le panel Filament**. Alpine compile chaque expression `x-data`/`x-bind` avec
   `new Function` : sans `'unsafe-eval'`, 20 `EvalError`, champ mot de passe non
   initialisé, bouton de connexion non lié, modales inertes — **pendant que le HTML était
   servi parfaitement et que toutes les assertions passaient**. Aucun test de la suite ne
   peut voir ça : la panne est dans le moteur JavaScript du navigateur.
   Correctif : `'unsafe-eval'` **scopé au seul chemin du panel**, lu depuis
   `Filament::getPanel('admin')->getPath()` et jamais codé en dur — déplacer le panel
   déplace la frontière avec lui. Le storefront ne l'hérite pas. Preuve après correctif :
   `new Function` opérationnel, **11/11 composants Alpine initialisés**, bascule
   révéler-mot-de-passe faisant réellement passer l'input de `password` à `text`.
   Le contrat asserte désormais que `/admin` contient `'unsafe-eval'` et que `/` ne le
   contient pas : élargir la relaxation au public fait tomber le test.
3. **La CSP n'est PAS une défense XSS et le commentaire le dit.** `'unsafe-inline'` sur
   `script-src` la rend inopérante contre l'injection ; c'est un contrôle de chaîne
   d'approvisionnement et de clickjacking. La défense XSS reste l'échappement Blade et
   `ArticleContent::toHtml()`. **Ne jamais lire cet en-tête comme une permission de
   relâcher l'un ou l'autre.**
4. **Origines Vite autorisées en `local` UNIQUEMENT**, mesuré de la même façon : une
   politique stricte bloque le client HMR sur `:5173` et le développeur perd le rechargement
   à chaud sans comprendre pourquoi. En production `@vite` sert des fichiers buildés depuis
   la même origine, donc `'self'` suffit et l'exception ne s'applique jamais.
5. **HSTS seulement sur HTTPS réel et hors local/testing.** Annoncé depuis un poste de
   développement, il rendrait `http://localhost` injoignable pour un an. `preload` n'est
   **pas** revendiqué : c'est irréversible.
6. **`SessionCookiePolicy` — le défaut fermé.** `config/session.php` lisait
   `env('SESSION_SECURE_COOKIE')` **sans défaut** : une ligne absente de `.env` produisait
   `null`, que Laravel lit comme « non sécurisé ». Le cookie de session voyageait en clair
   en production **parce qu'une variable manquait**, sans que rien ne le signale. Hors
   local/testing, `secure` et `http_only` sont désormais **forcés** et `same_site` borné à
   `lax|strict` — `none` est une valeur supportée qui désactive la protection.
   ⚠️ La règle vit dans une **classe nommée**, pas en ternaires dans le fichier de config :
   `config/*.php` est chargé via un dépôt d'environnement **IMMUABLE**, donc une logique
   enfouie là n'est **pas atteignable par un test**. Mesuré — un premier test manipulant
   `APP_ENV` était un no-op silencieux qui aurait affirmé le comportement `testing` en
   prétendant prouver celui de production. Patron déjà présent : `App\Support\DeliveryConfig`.
7. **Throttle `payment-webhook` à 120/min par IP.** C'était le **seul** chemin d'écriture
   publique du dépôt sans aucune limite, et il n'est pas gratuit : chaque requête coûte une
   vérification HMAC, chaque requête signée une écriture plus un appel HTTP sortant. Le
   plafond est délibérément haut : **perdre une notification de paiement réelle coûte bien
   plus qu'absorber du bruit**, donc la limite borne l'abus sans façonner le trafic normal.
8. **`.env.example` : défaut SÛR, pas défaut pratique.** `APP_DEBUG` passe à `false` —
   `true` en production expose traces, variables d'environnement et SQL sur chaque page
   d'erreur. `.claude/` est ignoré : configuration locale à la machine, jamais portable.

IMPACT : **aucune migration**. Non-régression CinetPay refaite une **quatrième** fois
(liste nommée + condensé identiques) parce que le lot touche les routes webhook.

### D-071 : Genius Pay — adaptateur, ingress webhook et intake de remboursement ✅

**MERGÉ ET VALIDÉ** — [PR #52](https://github.com/mysterus44/DigiTrove/pull/52), head
`3decc69`, merge `5f4c9394` (parents `9fd63a1` + `3decc69`), CI SUCCESS en 13 min.
18 fichiers (+2717 / −35), **aucune migration** (51 inchangées, aucune `000036`).
Suite complète **1880 / 13790, 0 échec** ; Pint **623** ; post-merge **236 / 1364**.

CONTEXTE : premier gate money-adjacent avec de vrais secrets depuis P3-D4. GeniusPay
remplace CinetPay comme `PAYMENT_DRIVER` actif — **additif, jamais une réécriture** :
CinetPay n'est pas supprimé, seulement désactivé. Le patron port + factory absorbait déjà
l'ajout ; **aucune architecture nouvelle, aucune migration**. Faits tirés de la
documentation publique (`pay.genius.ci/docs/api`), rien d'inventé.

DÉCISIONS :

1. **`ProviderWebhookEnvelope` gagne `?string $rawBody = null`** (+8 lignes, −0). GeniusPay
   signe `timestamp . "." . corps JSON brut` : un corps redécodé puis réencodé change
   l'ordre des clés et les espaces, donc le condensé. CinetPay signe des champs de
   formulaire et laisse le champ `null` — comportement inchangé. Option (b), un second DTO,
   écartée : deux enveloppes presque identiques finissent toujours par diverger.
2. **`PaymentConfirmationService` généralisé par EXTRACTION PRIVÉE, pas par alias public.**
   La forme littérale proposée — `handleProviderWebhook(string $provider, …)` avec
   `handleCinetPayWebhook()` en alias mince — aurait forcé l'extraction AVANT la résolution
   du fournisseur, donc inversé le type d'exception levée sur un payload malformé en driver
   mal configuré (`WebhookInvalid` au lieu de `ProviderConfigurationFailure`). **Changement
   de comportement observable, donc refusé.** Écart signalé avant écriture et validé.
3. **`confirm()` localise par un couple `(colonne, valeur)`, borné par allowlist fermée.**
   GeniusPay n'accepte aucun identifiant marchand : sa `reference` `MTX-…` est le seul
   handle qui revienne, et `payments_provider_reference_unique` la rend non ambiguë.
   `LOCATOR_COLUMNS = ['public_id', 'provider_payment_reference']` est validée **par
   identité, avant toute construction de requête** — un nom de colonne variable dans un
   `where()` n'est sûr que borné ainsi. CinetPay passe textuellement
   `('public_id', $transactionId)` ; aucun paramètre n'a de valeur par défaut, pour qu'un
   argument oublié soit une erreur et non un comportement silencieux.
4. **Un webhook signé mais non résolu reste `received`, jamais `ignored`.** P3-D3 initie en
   deux phases : la référence n'existe localement qu'après la seconde transaction. Un
   webhook arrivé dans cette fenêtre ne trouve rien, et `ignored` est TERMINAL — une
   commande réellement payée resterait `pending` pour toujours, non livrée et non signalée.
   Réponse 503 pour inviter à la redélivrance. **Scopé à GeniusPay** : CinetPay, qui connaît
   `public_id` dès la réservation, garde `ignored` à l'identique.
   ⚠️ **HYPOTHÈSE NOMMÉE** : que GeniusPay retente après un non-2xx. Leur documentation ne
   le dit pas. Dette #6 (`HANDOFF.md`) porte le job d'expiration, l'état terminal distinct
   `unresolved_expired` et l'alerte `critical` au-delà de ~15 min.
5. **`refunded` → `Unknown`, délibérément.** Ni `Succeeded` (ce serait confirmer de l'argent
   rendu) ni `Failed` (l'argent est bien passé). `expired` → `Cancelled`, car terminal et
   non payé : `Unknown` laisserait le paiement en attente indéfiniment. Vérification faite :
   aucun texte visible du client ne confond « annulé » et « expiré » —
   `CheckoutController::statusLabel()` regroupe déjà les deux sous « Cette commande n'est
   plus valide. » **Aucune correction de copie nécessaire.**
6. **`RefundCompletionService` ne crée jamais, il finalise.** `GeniusPayRefundIntakeService`
   est une classe NOUVELLE et DISTINCTE : elle crée la ligne `refunds` en `pending` avec une
   clé d'idempotence **déterministe** dérivée de l'`id` du webhook, puis appelle la chaîne
   existante **inchangée**. Écrire `status = 'succeeded'` directement serait deux lignes de
   moins et **n'émettrait jamais `RefundSucceeded`** : le moteur P6-D3/D4 resterait dormant
   **sans aucune erreur** — la forme de panne de la table de redirections morte de P7,
   appliquée à de l'argent. Les deux contrats ne sont jamais fusionnés.
7. **Tout `payment.refunded` est un remboursement TOTAL.** GeniusPay ne documente aucun
   champ de montant remboursé, nulle part. Vérifié indépendamment par KingKouda : lacune
   réelle de leur documentation, pas de la lecture. Un remboursement partiel non exposé est
   indétectable quel que soit l'effort — limite fournisseur, pas défaut d'adaptateur.
   Dette #5, plus une demande de confirmation écrite au support.
8. **Seul `XOF` est envoyé, et il est envoyé explicitement.** GeniusPay convertit
   automatiquement les devises non-XOF : le montant capturé différerait de
   `orders.total_minor` et chaque confirmation partirait en revue manuelle. Refus **avant
   l'appel réseau**. Ambiguïté évitée plutôt que gérée.
9. **`GENIUSPAY_ENVIRONMENT` est un contrôle croisé, pas un sélecteur.** Déclarer `sandbox`
   avec une clé `_live_` — ou l'inverse — refuse avant toute requête. Sans lui, « je teste
   en sandbox » est une croyance, et sa façon d'être fausse est un vrai débit sur une vraie
   carte. Une valeur non reconnue refuse aussi : un typo ne doit pas désactiver en silence
   la garde qui existe pour rattraper une erreur. Seule une CONTRADICTION refuse, jamais
   l'absence de marqueur — c'est une garde anti-catastrophe, pas un validateur de format.

IMPACT : **aucune migration** — la déduplication réutilise
`payment_webhook_events (provider, external_event_id)`. `P4B_ALLOWED_SERVICE_FILES` élargie
**explicitement** d'une entrée (`Payments/GeniusPayRefundIntakeService.php`), tri vérifié
programmatiquement. `PAYMENT_DRIVER=geniuspay` reste fail-closed sans credentials
complètes ; aucune bascule `live` sans validation explicite de KingKouda après un run
sandbox prouvé de bout en bout. Aucun secret dans le dépôt : `.env.example` ne porte que
des emplacements vides. Non-régression CinetPay **prouvée par liste nommée de tests et
condensé**, refaite après le changement de `confirm()` et pas seulement avant.

⚠️ **DÉFAUT DE TEST INSTRUCTIF, retenu** : `Http::fake()` **fusionne** les stubs au lieu de
les remplacer, et le premier motif qui matche gagne. Re-faker la même URL en cours de
scénario est donc silencieusement sans effet — le contre-appel continuait de répondre
`completed` et quatre tests de remboursement échouaient. **Le code était correct** : il a
refusé de créer un remboursement que le fournisseur ne confirmait pas. Corrigé par un stub
unique à état mutable, jamais en affaiblissant l'assertion.

### D-070 : P7 — Blog natif & SEO ✅

CONTEXTE : dernier gate de la feuille de route P0→P7. Le blog est un canal d'acquisition, pas
une décoration : il doit amener du trafic qualifié et le convertir. Le PRD et
`.context/skills/SEO_BLOG.md` décrivaient le modèle ; neuf points ont été arbitrés en amont
par KingKouda, plus deux tranchés au préflight.

CHOIX :

1. **Un seul macro-gate, migration `000035`.** P7 ne touche **aucune** frontière de privilège
   PostgreSQL : rien n'est money-adjacent, donc aucun rôle, aucune autorité `SECURITY
   DEFINER`, aucun ACL. Importer la machinerie P4/P6 ici n'aurait rien acheté. Traité comme
   P2 Catalogue.

2. **`body` en Markdown, rendu serveur par CommonMark avec `html_input: strip` et
   `allow_unsafe_links: false`.** Plus strict qu'une allowlist : il n'y a **aucune liste de
   balises à maintenir**, et rien à resynchroniser quand le sanitizer évolue. Prouvé sur six
   formes d'attaque — `<script>` en bloc, HTML inline, handler `onclick`, `onerror`,
   `javascript:` et `data:` — plus la preuve inverse que le Markdown légitime survit intact.
   Le rendu se fait **à la lecture** depuis le Markdown stocké : stocker du HTML figerait le
   sanitizer d'aujourd'hui en base, et corriger une évasion future exigerait de réécrire
   chaque ligne au lieu de déployer une fois.

3. **AUCUN `views_count`.** C'est la classe de défaut que P4-B a coûté cher à fermer : un
   compteur incrémenté depuis une requête publique est **forgeable**, et il transforme chaque
   lecture de page en écriture sur une table que tout le site lit. Les métriques de lecture
   appartiennent au pipeline `events`/rollups P5. `reading_minutes` reste, parce qu'il est
   **dérivé du corps à l'écriture** — aucune entrée utilisateur, aucune requête, aucune
   course. Un test assère l'absence de la colonne sur `pg_attribute`.

4. **JSON-LD sans jamais d'`aggregateRating`.** Aucune table `reviews` n'existe — P2 l'a
   explicitement mise en pause — donc une note structurée serait adossée à rien : une
   **pénalité Google**, pas une fonctionnalité. Prix et devise du bloc `Product` viennent du
   **prix réel affiché** (`activeXofPrice`), jamais d'un `XOF` codé en dur.
   ⚠️ **`@json(...)` TRONQUE UN TABLEAU MULTI-LIGNES** : le parseur d'arguments de directive
   Blade n'équilibre pas les crochets sur plusieurs lignes et la vue compilée cesse de
   parser. Mesuré, pas supposé. Les deux blocs sont construits dans un `@php` puis encodés
   sur une ligne, avec `JSON_HEX_TAG|HEX_AMP|HEX_APOS|HEX_QUOT` : un titre contenant une
   balise fermante ne peut pas refermer le `<script>` qui le porte.

5. **Sitemap fail-closed**, même discipline que D-062 : articles `published` non
   soft-deleted, produits publiés avec prix actif, `/`, `/products`, `/blog`.
   ⚠️ **Pages de catégorie EXCLUES** : mesuré sur les données réelles, **19 articles portent
   19 catégories distinctes**, donc chaque page listerait un seul article. Dix-neuf pages
   quasi vides soumises au crawl, c'est du thin content qui dilue les articles eux-mêmes. Les
   pages restent navigables ; elles ne sont pas déclarées.

6. **`ArticleResource` = Resource Filament standard adossée à Eloquent**, pas une Page custom
   façon `AffiliateLifecycle`/`AffiliatePayouts`. Celles-là existent parce que les tables
   affiliées sont derrière une frontière de privilège où un modèle serait un trou. Aucune
   frontière ici : un Resource normal est le bon niveau.
   ⚠️ **`BlogPolicy` créée et enregistrée** : les policies se lient **par modèle**, donc
   `Article`, `ArticleCategory` et `Redirect` n'héritaient **rien** de `CatalogPolicy`. Sans
   elle, Filament serait retombé sur son défaut et **tout utilisateur authentifié — `staff` et
   `customer` compris — aurait pu écrire des articles et créer des 301 pointant n'importe
   où**. Classe distincte plutôt que réutilisation : une policy nommée « catalogue »
   gouvernant en silence l'éditorial rendrait une divergence future dangereuse à découvrir.
   `forceDelete` refusé pour tous — détruire un article libère son slug, et un article
   ultérieur héritant de cette URL hériterait de son SEO et de ses backlinks.

7. **`redirects` : `from_path` unique, ni boucle ni chaîne.** Le CHECK ne voit pas les autres
   lignes, donc la chaîne est interdite par **trigger** dans les deux sens (A→B quand B→C
   existe, et A→B quand C→A existe). Chemins internes absolus seulement : `//host` et
   `/\host` sont lus comme protocol-relative — l'open redirect fermé en P6-D2, sur une table
   bien plus facile à cibler.
   ⚠️ **Le middleware est GLOBAL, pas dans le groupe `web`** — mesuré : une URI non matchée
   lève `NotFoundHttpException` **pendant le routage** et n'atteint jamais un middleware de
   groupe. Enregistré sur `web`, la table de redirections **n'aurait jamais fonctionné** et
   le SEO legacy aurait été perdu au premier déploiement. Il n'agit que sur un 404, donc une
   redirection ne peut pas masquer une route réelle.

8. **Import legacy idempotent par slug**, patron `catalog:import-legacy`. Importe en
   **brouillons** : dix-neuf articles apparaissant en ligne au lancement d'une commande
   serait une décision de publication prise par un script. `withTrashed()` dans le contrôle
   d'existence — un article soft-deleted possède encore son slug.

9. **Core Web Vitals hors gate**, comme le PRD le note — reporté au durcissement
   préproduction, même traitement que P5-A3D.

10. **FK simple `articles.article_category_id`, pas de pivot** : le legacy porte exactement
    une catégorie par article et le PRD rend le pivot optionnel « selon le legacy ». Une FK
    est un sous-ensemble strict d'un futur pivot — l'extension serait un backfill, pas une
    réécriture.

ALTERNATIVES REJETÉES :

- **`views_count`** (§3) · **`aggregateRating`** (§4) · **pages de catégorie au sitemap** (§5).
- **Réutiliser `CatalogPolicy`** pour le blog : couplage sémantique dangereux (§6).
- **Créer une table `tags`** : le legacy porte une chaîne `tags` par article, mais ni le PRD
  ni les arbitrages ne la demandent. **DETTE NOMMÉE** : les tags ne sont pas importés,
  `legacy/data/blog-articles.json` les préserve, reprise possible en gate ultérieur.
- **Un pivot catégories** (§10) · **Stocker du HTML rendu** (§2) · **`@json()` multi-lignes**
  (§4) · **Le middleware sur le groupe `web`** (§7).

IMPACT : migration `000035`, **51 migrations**, aucune `000036`. Quatre tables, un trigger
d'intégrité, **aucune fonction `SECURITY DEFINER`**. La feuille de route **P0→P7 est CLOSE**.
Restent au durcissement préproduction : P5-A3D, Core Web Vitals, et le **déclencheur réel de
remboursement** sans lequel le moteur P6-D3/D4 ne peut pas se déclencher.

### D-069 : P6-D4 — payout administratif et fermeture du trou D-057 §10 ✅

CONTEXTE : le schéma des payouts existait depuis `000029` avec ses quatre FK composites, mais
aucune autorité ne pouvait l'écrire. Le préflight a en outre déterré un **défaut réel de
P6-D3** : D-057 §10 exigeait depuis le début « commission déjà payée ⇒ solde négatif reporté
et auditable », et l'implémentation plafonnait tout renversement au solde du ledger — ce qui
était juste tant que rien ne pouvait le tirer à zéro, et **P6-D4 est précisément ce qui le
peut**. Le trou n'était pas visible avant parce que `paid` était inatteignable.

CHOIX :

1. **`allocated` à la DEMANDE, pas à l'approbation.** Une commission passe
   `payable → allocated` et reçoit son `payout_allocation` (solde tiré à zéro) au moment où
   elle entre dans un payout `requested`. Sinon rien n'empêche deux administrateurs de
   sélectionner la même commission dans deux brouillons concurrents : la protection doit
   exister dès la réservation. `approved` est une **porte d'autorisation humaine**, pas un
   mouvement d'argent supplémentaire.

2. **Deux administrateurs DISTINCTS pour `requested → approved`**, imposé par l'autorité.
   Le compare-and-swap protège d'une course entre deux administrateurs sur le même écran ;
   il ne protège en rien d'un administrateur unique qui demande un virement et se
   l'auto-approuve. Sur un flux qui mobilise de l'argent réel, la ségrégation des rôles est
   le contrôle minimal. Refus par **statut de domaine**
   (`same_administrator_forbidden`), jamais par exception. Aucune colonne ajoutée :
   `requested_by_user_id` et `approved_by_user_id` existaient déjà dans `000029`.

3. **Le montant d'une ligne est le SOLDE du ledger**, jamais `commission.amount_minor`. Un
   remboursement partiel a pu le réduire avant qu'un payout ne soit demandé ; figer le
   montant nominal ferait payer plus que ce qui est réellement dû.

4. **Le statut de l'affilié ne bloque PAS le versement.** Une commission gagnée reste due
   même si l'affilié a depuis été suspendu ou fermé. Refuser inventerait une politique de
   confiscation qui n'a jamais été décidée.

5. **`administrative_reference` obligatoire au passage `paid`**, imposé par l'autorité et non
   par un nouveau CHECK (aucune modification de schéma non sollicitée). Refus par statut de
   domaine `missing_administrative_reference` — même distinction qu'en D-068 : un contrat
   d'appel violé lève, une règle métier retourne un statut.

6. **Machine à états exhaustive**, dans l'autorité et non par convention applicative :
   `requested → approved|rejected|cancelled` · `approved → paid|cancelled`. **`paid`,
   `rejected` et `cancelled` sont TERMINAUX**, sans transition sortante.

7. **`payout_reversal` : lecture SCOPÉE.** Il reste dormant pour le clawback d'un versement
   déjà effectué — un gate séparé si jamais requis — mais devient **l'unique mécanisme
   légitime de libération d'une réservation jamais payée**. Déclenché **uniquement à
   l'intérieur** de l'autorité de transition vers `cancelled`/`rejected`, dans la même
   transaction : jamais une étape manuelle qu'un administrateur pourrait oublier, sinon
   l'argent serait irrécupérablement bloqué sur des commissions `allocated` à solde nul.
   Montant **repris de l'entrée d'allocation elle-même**, jamais recalculé. Effet :
   `allocated → payable`. Que `paid` soit terminal (§6) est ce qui empêche cette lecture
   scopée de s'effondrer silencieusement en lecture large.

8. **Deux index uniques partiels** `(commission_id, payout_id)` — un pour
   `payout_allocation`, un pour `payout_reversal` — aux côtés des deux existants. Le garde
   est sur la table qui porte le mouvement d'argent : s'appuyer sur l'UNIQUE
   d'`affiliate_payout_items` laisserait passer une écriture de ledger rejouée.

9. **Correctif D-057 §10 dans `apply_affiliate_refund_reversal`** (`CREATE OR REPLACE`, pas
   une exception greffée). Le plafond portait sur le **solde du ledger** ; il porte désormais
   sur **ce qui reste commissionnable** — l'accrual moins ce que les remboursements
   précédents ont déjà repris — quantité indépendante de tout payout. Un renversement ne peut
   toujours pas dépasser ce qui a été gagné, et une commission déjà versée porte un **solde
   négatif**. Une commission `allocated`/`paid` intégralement renversée **ne passe pas
   `cancelled`** : elle A ÉTÉ payée, et le prétendre autrement effacerait le fait même que le
   ledger existe pour enregistrer.

10. **Une troisième `Page` Filament custom**, aucun modèle Eloquent (un Resource exigerait un
    modèle, et ce modèle serait un trou dans la frontière de privilèges). Même groupe de
    navigation, même trait `AuthorizesAffiliateAdmin`, même patron snapshot + CAS. Montants
    en unités mineures avec devise explicite, **aucune division par 100**, aucun total
    multi-devises. Affiliés identifiés par `public_id` — **jamais `users.email`**.

ALTERNATIVES REJETÉES :

- **`payout_reversal` strictement dormant** : rendrait `cancelled` inapplicable et
  **détruirait l'argent** — commission bloquée en `allocated` à solde nul, sans transition
  possible sur un ledger append-only. Signalé avant écriture, arbitré en lecture scopée.
- **`admin_adjustment` pour libérer une réservation** : exige un `reason_code` et signifie
  « correction manuelle », pas « libération systématique ». L'audit deviendrait illisible.
- **S'appuyer sur l'UNIQUE d'`affiliate_payout_items` seul** pour l'idempotence (§8).
- **Une exception pour `allocated`/`paid`** dans le correctif du plafond : marche, mais
  greffe un cas particulier là où la quantité était simplement fausse (§9).
- **`CREATE TEMPORARY TABLE`** dans une autorité `SECURITY DEFINER` : D-029.6 a fermé `TEMP`
  et rien ne garantit que l'exécuteur le détienne. Remplacé par des CTE.
- **Comparer un seuil entre devises** : aucune conversion n'existe dans ce dépôt, et en
  inventer une serait le total multi-devises que P6-A1.1 interdit. Une paire
  `(affilié, devise)` dont la devise n'est pas celle de la politique est simplement non
  éligible (`unsupported_currency`).

IMPACT : migration `000034`, **50 migrations**, aucune `000035`, **aucune table, aucune
colonne**. Aucun fournisseur, aucun virement, aucune donnée bancaire ou Mobile Money.
Vingt-et-un contrats d'inventaire avancés ; trois laissés à leur frontière historique.
**Aucune colonne Commerce supplémentaire** : le bilan cumulé reste à cinq tables et dix-neuf
colonnes, asserté par un test qui liste l'inventaire exact.

### D-068 : P6-D3 — moteur de commissions et compensations de remboursement ✅

CONTEXTE : le schéma des commissions existait depuis `000029`, mais **aucune autorité ne
pouvait y écrire**. Mesuré avant tout code : `digitrove_runtime` ne détient RIEN sur
`affiliate_commissions` ni `affiliate_commission_entries` — pas même `SELECT` — et aucune des
20 autorités existantes ne les touche. La frontière D-058 ne se lève pas en assouplissant un
GRANT sur les tables ; elle se respecte en passant par des fonctions étroites.

CHOIX :

1. **Migration `000033`, AUCUNE table.** Même catégorie que `000032` : trois autorités
   `SECURITY DEFINER` bornées, owner `digitrove_affiliate_executor`, `search_path` épinglé,
   `REVOKE ALL FROM PUBLIC`, `GRANT EXECUTE TO digitrove_runtime`. Aucun nouveau rôle, aucun
   trigger sur `orders`/`payments`/`refunds` — la règle posée par D-067 tient.

2. **Le taux vient de la politique de L'ATTRIBUTION**, jamais de la politique active au
   moment du calcul. C'est le sens même des politiques versionnées : publier une nouvelle
   version demain ne réécrit pas une vente d'hier. Un test publie une politique à 500 bps
   avant que l'accrual tourne et prouve que la commission reste à 1500.

3. **`payable_at = orders.paid_at + payable_delay_days_snapshot`** (arbitrage KingKouda).
   Ancré sur l'entrée d'argent, jamais sur l'heure du worker : un retard de queue ne doit pas
   déplacer une échéance financière. Transition `pending → payable` par **balayage planifié**
   sur `(status, payable_at)`, jamais dérivée à la lecture — la transition porte alors un
   horodatage réel et auditable.

4. ⚠️ **La promotion n'écrit AUCUNE entrée de ledger.** `release` est un type **positif** et
   le solde d'un affilié est `SUM(amount_minor)` sur le ledger : émettre un `release`
   par-dessus l'`accrual` créditerait deux fois la même somme. Devenir payable est un
   changement d'ÉTAT, pas un mouvement d'argent. `release` reste inutilisé jusqu'à ce qu'un
   gate lui donne une sémantique qui ne double pas le solde.

5. **Aucune ligne de commission pour un montant nul** (arbitrage KingKouda). Le ledger refuse
   `amount_minor = 0`, donc une telle ligne ne pourrait jamais recevoir son `accrual` et
   resterait éternellement dans un état sans transition légale. L'attribution peut exister
   sans commission ; l'inverse ne doit jamais se produire.

6. **Remboursement : deux régimes.** TOTAL (`orders.status = 'refunded'`) ⇒ chaque commission
   est renversée de son **solde restant entier** — sinon deux remboursements partiels
   totalisant la commande laisseraient l'affilié avec quelques unités mineures d'une vente
   intégralement rendue. PARTIEL ⇒ `(alloué × rate_bps) / 10000`, la même troncature que
   l'accrual, **plafonnée au solde restant**. Une commission dont le solde atteint zéro passe
   `cancelled` : elle ne doit jamais être ramassée par un futur payout.

7. **L'adaptateur Hamilton, jamais une seconde implémentation.**
   `App\Services\Pricing\DiscountAllocator` n'est pas modifié (arbitrage KingKouda : une
   autorité validée ne se plie pas pour un usage aval). Deux adaptations dans l'appelant :
   (a) `order_items.product_id` étant NULLABLE (`ON DELETE SET NULL`, D-006) et l'allocateur
   refusant `product_id < 1`, on passe l'`order_item.id` — **substitut de tri**, jamais une
   identité produit, jamais persisté, l'allocateur ne s'en servant que pour départager les
   résidus ; (b) base = `SUM(order_items.line_total_minor)`, **jamais `orders.total_minor`**,
   et un remboursement excédant cette somme est **plafonné** à elle.
   ⚠️ Ce plafond n'est pas décoratif : `validate_order_items_consistency` impose
   `SUM(line_total_minor) + tax_minor = total_minor`, donc il est atteignable **dès que
   `tax_minor > 0`**. Un test le construit ainsi et échouerait si le plafond disparaissait.

8. **Chaînage, pas deux écouteurs.** `ProcessAffiliateAttribution` dispatche
   `ProcessAffiliateCommissionAccrual` **uniquement** sur `attributed` / `already_attributed`.
   Deux écouteurs indépendants sur `OrderPaid` se courraient après : un accrual exige que la
   ligne d'attribution existe, et rien n'ordonne deux écouteurs du même événement. Dispatch
   direct et non `Bus::chain` — ce dépôt n'utilise `Bus::chain` NULLE PART.

9. **Compensation déclenchée par `RefundCompletionService` seul.** Il émet `RefundSucceeded`
   **après** le `DB::transaction()`, y compris sur rejeu — l'autorité étant idempotente par
   construction, re-tirer l'événement est la seule chance de rattrapage d'un reversal perdu
   par un worker mort. Aucun trigger, aucun nouveau déclencheur métier.

10. **`GRANT SELECT` par COLONNE sur le Commerce** pour l'exécuteur affilié :
    `order_items(id, order_id, line_total_minor, currency)`, `orders(id, paid_at, status)`,
    `refunds(id, payment_id, status)`, `payments(id, order_id)`. Jamais `customer_email`,
    jamais un montant qu'il ne calcule pas. `REVOKE` symétrique au `down()`.

ALTERNATIVES REJETÉES :

- **Assouplir un GRANT sur `affiliate_commissions`** pour que le runtime écrive directement :
  détruirait la frontière D-058 que P6-D1 a payée cher.
- **Émettre un `release` à la promotion** : double le solde (point 4).
- **Renverser au prorata du solde plutôt qu'au taux snapshoté** : ajouterait une seconde règle
  d'arrondi là où celle de l'accrual suffit.
- **Construire le déclencheur réel de remboursement** (port fournisseur, webhook, action
  admin) : plus que doublerait le gate et sort du périmètre D-057. ⚠️ **Conséquence assumée :
  le moteur est testable mais DORMANT** — aucun code n'appelle `RefundCompletionService`.
- **`Bus::chain`** : mécanisme absent du dépôt (point 8).

IMPACT : migration `000033`, **49 migrations**, aucune `000034`. Aucun payout, aucun
`payout_allocation`, aucun `payout_reversal` — c'est P6-D4. Vingt contrats d'inventaire
avancés de leurs trois dents ; **trois laissés à leur frontière historique** ; `app/Console/
Commands` et `routes/console.php` sortent des listes « doit rester vide » **en étant nommés**,
`console.php` par un inventaire de JETONS et non par une exemption de fichier.

### D-067 : P6-D2 — l'attribution sort de `orders` et passe par la queue ✅

CONTEXTE : la première écriture de P6-D2 attachait `resolve_affiliate_attribution()` à
`orders` par un trigger `AFTER INSERT`. La forme paraissait naturelle — PostgreSQL est déjà
l'autorité partout dans ce dépôt — mais elle décidait **deux choses à la fois** sans que
personne ne les ait arbitrées : *quand* attribuer, et *où* le code s'exécute.

CHOIX :

1. **Aucun trigger affilié sur `orders`.** `orders` est partagée par tout P1/P3/P4. Un
   trigger y accroche une fonction métier spécifique à l'affiliation sur **chaque** insertion,
   y compris celles de code qui n'a rien à voir avec le programme. Le coût est nul tant que
   la fonction est correcte ; le risque ne l'est pas : une exception imprévue, une table
   absente en cours de migration, et c'est la **création de commande** qui casse pour tout le
   monde. Sur une table dont le comportement transactionnel venait de coûter trois échanges
   de diagnostic, ajouter une dépendance synchrone de plus était le mauvais sens.

2. **`resolve_affiliate_attribution(p_order_id BIGINT) RETURNS TEXT`** — une fonction
   ordinaire, plus une fonction trigger. Elle lit elle-même `visitor_id`, `user_id` et
   `placed_at` depuis `orders`. **`placed_at` reste l'ancre de la fenêtre d'éligibilité**,
   jamais l'heure d'exécution : un retard de queue ne doit pas changer qui est crédité.

3. **Attribution au `paid`, pas au `pending`.** Conséquence directe et voulue du point 1 :
   l'accroche est `OrderPaid`. Cela aligne P6-D2 sur l'attribution CRM P6-A1.0 et évite
   qu'une commande jamais payée consomme définitivement l'unique attribution autorisée par
   `affiliate_attributions_order_id_unique`.

4. **Listener → job ID-only, jamais de logique dans le listener.** `ResolveAffiliateAttribution`
   vérifie le drapeau et dispatche `ProcessAffiliateAttribution($orderId)` ; le travail se fait
   dans le worker. `OrderPaid` partant après COMMIT, aucun rollback financier n'était possible
   — mais un listener **synchrone** aurait laissé une exception de l'autorité faire échouer la
   réponse HTTP au webhook de confirmation, **après** que le paiement soit correctement
   enregistré. C'est exactement le couplage que `QueueSecureDelivery` existe pour éviter.
   `resolve_affiliate_attribution` étant idempotente (`already_attributed`), la sémantique
   at-least-once de la queue ne crédite personne deux fois.

5. **Un `GRANT SELECT` par colonne, mesuré et non supposé.** Le trigger recevait `NEW`
   gratuitement ; une fonction qui lit `orders` elle-même exige un privilège que
   `digitrove_affiliate_executor` n'a pas — il ne possède que le bloc affiliation. Sans lui :
   `42501 permission denied for table orders`. Accordé sur **exactement** `id`, `visitor_id`,
   `user_id`, `placed_at` — jamais l'e-mail client, jamais un montant — et révoqué à
   l'identique dans le `down()`. Précédent : `000022` fait de même pour l'exécuteur CRM.

6. **Un seul drapeau pour toute la surface affiliée.** Capture publique **et** attribution
   sont gouvernées par `AffiliateConfig::governanceEnabled()`. La classe documentait déjà la
   raison : un second toggle permettrait un état où une moitié du programme tourne pendant
   que l'autre est fermée, ce qu'aucune configuration ne devrait pouvoir produire. Le drapeau
   est **relu à l'exécution du job**, pas seulement au dispatch, pour que l'extinction coupe
   aussi les jobs déjà en file — même règle de kill switch qu'en P4-C.

7. **`POST /affiliate/code`, pas `/cart/affiliate-code`.** La route enregistre une touche
   contre le visiteur de session et ne lit ni n'écrit aucun panier. La placer sous `/cart`
   obligeait un contrat P6-C à porter une exception documentée pour une route qu'il n'était
   pas censé surveiller — un garde qui porte une exception dit moins clairement ce qu'il
   garantit. `POST /affiliate/code` est le pair sémantique de `GET /r/{code}`.

ALTERNATIVES REJETÉES :

- **Trigger sur `orders` filtré par `status`** : réduit la fenêtre, ne change rien au couplage
  — le code affilié tourne toujours dans la transaction de checkout d'autrui.
- **Listener synchrone appelant l'autorité** : écarté au point 4.
- **Second drapeau `AFFILIATE_ATTRIBUTION_ENABLED`** : écarté au point 6.
- **Contrainte unique sur `affiliate_touches` pour l'idempotence** : écartée au profit d'un
  `WHERE NOT EXISTS` dans l'autorité, pour ne pas ajouter de contrainte au schéma `000029`.
  Résidu assumé : sous READ COMMITTED deux requêtes simultanées peuvent encore insérer deux
  touches. Inoffensif — le resolver prend `LIMIT 1` et les deux lignes nomment le même
  affilié et le même code.
- **Exempter `routes/web.php` du contrat P6-D0** : écartée. L'exemption saute le fichier
  entier, donc n'importe quelle route affiliée y passerait ensuite inaperçue. Remplacée par
  un inventaire **des jetons** autorisés dans ce fichier.

IMPACT : migration `000032`, **48 migrations**, aucune `000033`. `orders` ne porte **aucun**
trigger affilié — asserté par un test qui suit la **fonction** et non le nom, et qui vérifie
aussi que `resolve_affiliate_attribution` retourne `text` et non `trigger`. Neuf contrats
d'inventaire avancés de leurs trois dents ; trois laissés à leur frontière historique
(`applyExactMigrations`) ; deux résidus du WIP à trigger convertis en garanties positives.

### D-066 : P6-D1.1 réactivé et livré — cycle de vie affilié + codes ✅

CONTEXTE : D-060 avait mis P6-D1.1 en pause au profit du Storefront MVP, pour une raison
business explicite — l'attribution affiliée valait-elle quelque chose sans commandes
réelles ? Quatre gates plus tard (D-062 à D-065), le Storefront vend de bout en bout. Un
préflight de réactivation a mesuré l'état avant toute reprise, plutôt que de foncer.

CONSTAT 1 — **LE WIP ÉTAIT UNE IMPLÉMENTATION FINIE, PAS UNE ÉBAUCHE.** `f15d192`
(« checkpoint paused P6-D1.1 work ») portait 21 fichiers et +3827 lignes : migration
`000031` (917 l., ledger append-only + 10 autorités + ACL), page Filament (432 l.) et sa
vue, service et 4 DTO (333 l.), **cinq suites de tests D1.1** (1874 l. — lifecycle,
concurrence, rollback, service, page) et quatre contrats rescopés. Tout ce que D-059
décrivait était à la fois codé ET testé ; il ne restait rien d'écrit-mais-non-prouvé.

CONSTAT 2 — **LA DÉRIVE ÉTAIT FAIBLE, PAS DE PLUSIEURS CENTAINES DE COMMITS.**
`git rev-list --left-right --count` : **1 / 16**. Le gel datait du 11 août, juste avant
les gates Storefront. ⚠️ La fusion a été vérifiée par `git merge-tree --write-tree`
**AVANT toute mutation** — arbre propre, aucun conflit — puis réalisée par **merge**,
jamais rebase, conformément à la convention du dépôt. Le seul risque nommé,
`P4B_ALLOWED_SERVICE_FILES` modifiée des deux côtés, ne s'est pas matérialisé : les entrées
affiliation et Storefront tombent à des positions alphabétiques distinctes.

CONSTAT 3 — **LE STOREFRONT NE DÉPLACE PAS LE PÉRIMÈTRE DE D1.1.** Mesuré, pas supposé :
**aucune classe `app/` du WIP** ne référence `OrderPaid`, `OrderService`,
`PaymentInitiation`, `affiliate_touches`, `affiliate_attributions` ni
`affiliate_commissions`. L'unique occurrence est un COMMENTAIRE citant `OrderService` pour
la leçon D-030.2.1. C'est structurel : D-059 avait délibérément séparé **cycle de vie +
codes** (D1.1, administration seule) de **l'attribution** (P6-D2).

⚠️ **LA QUESTION BUSINESS DE D-060 SE POSE EN P6-D2, PAS ICI.** D1.1 gouverne qui est
affilié et quel code il détient ; il ne consomme aucune commande. Le Storefront ne change
donc rien à ce gate — il rend le SUIVANT enfin réaliste, puisqu'il existe désormais de
vraies commandes à attribuer. **La pause D-060 est levée.**

⚠️ **UN PREMIER RUN A RENDU 32 ÉCHECS QUI N'EN ÉTAIENT PAS.** `digitrove_testing` était à
moitié migrée — `relation "migrations" does not exist` ET `relation "carts" already
exists` simultanément — laissée ainsi par un run tué à 10 minutes et par les conteneurs de
prévisualisation de D-065 qui pointaient dessus. **ENVIRONMENT FAILURE**, jamais un défaut
du WIP. Base reconstruite, campagne verte. Même discipline que le flake de fixture de
D-064 : un chiffre faux dans un rapport oriente tout le reste dans la mauvaise direction.

⚠️ **LA BRANCHE GARDE SON NOM ET SON HISTORIQUE.** `p6-d1-1-affiliate-lifecycle-codes`
contient toujours le commit « checkpoint paused ». Le renommer masquerait une information
vraie : ce gate a réellement été gelé puis repris, et l'historique doit le dire.

HORS PÉRIMÈTRE, INCHANGÉ DEPUIS D-059 : aucune surface publique, aucune capture de clic,
aucun objet financier, aucune attribution. `P6-D2` reste le gate des touches et du
rattachement de commande ; `P6-D3` celui des commissions ; `P6-D4` celui des payouts.

### D-065 : Polish Storefront — contrôle visuel réel, états d'erreur, accessibilité ✅

CONTEXTE : les gates D-062 à D-064 ont produit un parcours d'achat invité complet et
testé, mais **jamais regardé**. Le seul contrôle responsive du sprint était une inspection
manuelle non tracée. Aucune migration, aucune autorité P3-D/P4-C touchée.

⚠️ **LA LEÇON DE CE GATE : LES TESTS FONCTIONNELS NE PROUVENT RIEN SUR LE RENDU.**
Neuf classes CSS — `cart-lines`, `cart-line`, `cart-total`, `cart-empty`,
`checkout-summary`, `checkout-form`, `checkout-status`, `form-error`, `detail-add` — ont
été posées dans les vues sur **trois gates successifs** sans qu'aucune ne soit définie dans
`app.css`. Panier et checkout n'étaient donc **pas stylés du tout** : liste à puces brute,
bouton par défaut du navigateur, liens nus. **Aucun test ne l'a vu**, parce qu'ils
vérifient du contenu (`assertSee`), jamais du rendu. Ce n'est pas une négligence isolée :
c'est un angle mort structurel de toute la suite jusqu'ici, et il faut le savoir avant de
conclure qu'une page « marche » parce qu'elle est verte.

⚠️ **SECOND DÉFAUT, INVISIBLE À LA LECTURE.** Après avoir écrit le CSS, la capture a
montré des blocs **débordant sur toute la largeur**, bouton coupé au bord. La cause est
dans la feuille existante : `main > section { width: min(1180px, calc(100% - 32px)) }` ne
protège que les **enfants directs**. Les `<ul>`/`<p>`/`<div>` non enveloppés dans une
`<section>` échappent à la largeur de page. Aucune lecture de classes ne pouvait le
révéler — seule la capture.

CORRECTIONS :
1. **CSS panier/checkout** écrit sur les jetons EXISTANTS (`--panel`, `--line`,
   `--panel-alt`, `--green-dark`) et réutilisant `.button`/`.button-primary`/`.button-muted`
   plutôt que de redéfinir une échelle à côté.
2. **`<section>` enveloppante** sur le corps du panier — la largeur de page en dépend.
3. **Texte d'aide sorti du `<label>`** vers `aria-describedby` (+ `aria-invalid` en erreur).
   Mesuré sur l'arbre d'accessibilité réel : le champ s'annonçait
   « Adresse e-mail Vos liens de téléchargement y seront envoyés. Aucun compte n'est créé. »
   Le nom accessible doit rester court ; l'aide est une description, pas un nom.
4. **Prix barré en `<s>` + libellé masqué** « Ancien prix : », sur la fiche et la carte.
   Il se lisait « 3 500 XOF 7 700 XOF » — le second pouvant passer pour le prix réel.
5. **`.sr-only`** ajoutée (support du point 4).
6. **`focus-visible` explicite** sur les éléments interactifs du parcours.
7. **404 aux couleurs du storefront**. L'ancienne était la page blanche de Laravel : sûre
   (aucune trace, aucune donnée de commande) mais sans marque ni sortie. Son contenu est
   **identique** pour une commande inexistante et celle d'un autre acheteur — la page ne
   doit jamais devenir un oracle d'existence (D-064).

⚠️ **ASSERTION RECENTRÉE SUR LA GARANTIE.** Séparer le libellé « Total » du montant
cassait `assertSee('Total : 15 000 XOF')`. L'assertion porte désormais sur le **montant**,
plus un `assertDontSee` prouvant que la ligne indisponible reste exclue du total. Même
principe que le rescope du contrat de routes P6-C : le test vise la garantie, pas la forme
exacte du HTML.

⚠️ **PIÈGE D'INFRASTRUCTURE CONSIGNÉ DANS `HANDOFF.md`** : `php artisan serve` se bloque
silencieusement avec une session Redis en conteneur ; `php -S … server.php` fonctionne.
Quatre tentatives et deux fausses hypothèses (port Redis, cache de config) avant de trouver.
Ce diagnostic a aussi produit une conclusion **erronée puis rétractée** — « `SessionStoreGuard`
a un coût opérationnel » — qui ne tenait pas : les sessions Redis fonctionnent, le garde
n'a jamais été en cause, et il reste **intact**.

MÉTHODE : écrire, capturer, corriger, recapturer — trois passages sur le panier avant
qu'il soit présentable. Plus lent qu'une feuille écrite d'un coup, et c'est précisément ce
qui évite de reproduire l'erreur trouvée.

HORS PÉRIMÈTRE : aucune migration, aucune autorité, aucun coupon, compte client,
affiliation ou P7.

### D-064 : Checkout invité Storefront ✅

CONTEXTE : le panier invité (D-063) s'arrêtait avant toute commande. Le préflight a établi
que les autorités P3-D acceptaient DÉJÀ un achat invité — `OrderService::checkout()` et
`PaymentInitiationService::initiate()` prennent `User|Visitor` et `?string $guestEmail`, et
`orders.customer_email` est `CITEXT NOT NULL`. Rien n'a dû être élargi : ce gate ORCHESTRE.

CHOIX (arbitrages MAESTRO) :
1. **Aucune migration.** 46 inchangées. Aucune autorité P3-D/P4-C modifiée.
2. **`public_id` dans les URLs, `order_number` jamais.** Le numéro est lisible et dictable
   par téléphone (Crockford base32) — exactement ce qu'il ne faut pas mettre dans un
   chemin. Il est affiché, jamais routé.
3. **Le `public_id` n'est PAS une autorisation.** Il est comparé à celui que la session a
   posé au checkout ; toute non-correspondance rend un **404 identique octet pour octet**
   à celui d'une commande inexistante, sinon la route deviendrait un oracle d'existence.
4. **Le retour navigateur ne confirme RIEN.** Seuls le webhook signé et le contre-appel
   fournisseur peuvent faire passer une commande à `paid` (D-034). La page de statut lit
   l'état déjà en base et n'appelle aucune logique de confirmation.
5. **`clientInstructions['payment_url']` seule clé lue**, validée comme URL. Absente ou
   invalide ⇒ échec fermé, aucune redirection improvisée.
6. **Refus `PricingService` ⇒ message générique + retour panier.** Il refuse le devis
   ENTIER quand une ligne devient invendable — c'est correct ici, contrairement à
   l'affichage panier. Nommer la ligne rapporterait l'état catalogue d'un produit qu'un
   administrateur vient de retirer.
7. **`Cache-Control: no-store`** sur le statut : un cache partagé pourrait servir l'état
   d'un acheteur à un autre.

⚠️ **`CINETPAY_RETURN_URL` est une valeur de config STATIQUE** — elle ne peut pas porter un
`public_id` variable. D'où deux routes : `/checkout/return` **sans paramètre**, qui lit la
session et redirige, et `/checkout/{order}/status`, canonique. Cette séparation rend la
preuve de session PLUS forte que la vérification demandée : la route de retour ne peut
littéralement pas fonctionner sans cookie valide.

⚠️ **DÉFAUT DE CONCEPTION CORRIGÉ.** Injecter `PaymentInitiationService` au constructeur
faisait échouer toute la surface checkout avec « No payment provider is configured »,
y compris le simple AFFICHAGE du formulaire — le binding est fail-closed quand
`PAYMENT_DRIVER` est vide. Un résumé de panier ne doit pas exiger une passerelle de
paiement : la dépendance est résolue paresseusement dans `startPayment()`.

⚠️ **CAUSE RACINE DU « FLAKE » DES GATES PRÉCÉDENTS — RÉSOLUE.** Ce n'était pas une
dépendance d'ordre. `ProductPriceFactory` tire un `compare_at_price_minor` ALÉATOIRE, et le
CHECK exige qu'il soit SUPÉRIEUR au prix ; les fixtures storefront fixaient
`price_minor = 15 000` en laissant le prix barré au hasard, donc un tirage sous 15 000
violait la contrainte de façon intermittente. Le prix barré est désormais épinglé dans les
quatre fixtures. Trois exécutions consécutives identiques (48/190) confirment le
déterminisme. ⚠️ **Une fixture partiellement aléatoire est un piège** : elle ne casse que
parfois, et ressemble alors à un problème d'ordre.

⚠️ **`.env.example` NETTOYÉ** : `PAYMENT_PROVIDER=cinetpay` et un bloc `CINETPAY_*`
incomplet coexistaient avec le bloc `PAYMENT_DRIVER` réellement lu par `config/payments.php`.
Deux clés pour la même intention, dont une pré-remplie. Le doublon est supprimé.

⚠️ **LIVRAISON INVITÉE PROUVÉE, PAS DÉDUITE.** Une commande invitée réelle (aucune ligne
`users`, `user_id` NULL) traverse `GrantIssuanceService` : les grants naissent à
`user_id` NULL, et un grant revendiquant un compte sur cette commande est REFUSÉ en
`23514`. La garantie est bidirectionnelle grâce au `IS NOT DISTINCT FROM` du trigger G3.
L'e-mail part de `orders.customer_email` via un `Mailable` adressé à une chaîne — aucun
`Notifiable`, donc aucun compte nécessaire.

⚠️ **DEUX LIMITES DE HARNAIS ASSUMÉES.** `SecureDeliveryJob` refuse de tourner à un niveau
de transaction autre que 0 et `RefreshesDatabaseAsMigrator` en impose un : le job n'est
donc pas exercé de bout en bout, seule l'adresse de l'enveloppe est prouvée. Même obstacle
que la course du panier en D-063. ⚠️ Et faire passer une commande à `paid` exige d'insérer
le paiement `succeeded` dans la MÊME transaction avec `SET CONSTRAINTS ALL DEFERRED` : les
CHECK jouent dans les deux sens, ce qui rend une commande à demi payée **irreprésentable**
— et c'est précisément pourquoi un retour navigateur forgé est structurellement inoffensif.

⚠️ **`main` A DIVERGÉ MAIS NE PORTE AUCUN CODE.** `git diff p0...main` est VIDE. Son unique
commit propre (`11130f4`, 2026-07-15) est un merge dont le second parent EST la merge-base.
`main` est 213 commits en retard et n'a rien à récupérer. **`p0-foundations-laravel13` est
la branche canonique** — c'est désormais écrit dans `HANDOFF.md`.

HORS PÉRIMÈTRE : aucun compte client, aucun coupon, aucune affiliation, aucun Schema.org,
aucun nouveau fournisseur. ⚠️ **Pas de suivi de commande hors session** : aucun lien e-mail
ne rouvre une commande plus tard. Choix de scope MVP assumé — une capacité durable exigerait
son propre secret haché, comme la reprise de panier P6-C, et donc son propre gate.

TESTS : `StorefrontGuestCheckoutTest` (formulaire, rien accepté du client hors e-mail,
refus pricing générique, **retour navigateur forgé ne mutant rien**, `no-store`,
persistance de session inter-requêtes, 404 identiques, `order_number` hors des routes) et
`StorefrontGuestDeliveryTest` (commande invitée sans compte, grants à `user_id` NULL,
grant usurpant un compte refusé, adressage e-mail).

IMPACT : DigiTrove peut vendre un produit digital de bout en bout à un acheteur invité.

### D-063 : Panier invité Storefront ✅

CONTEXTE : `carts` et `cart_items` existent depuis P3, et P6-C leur a ajouté
`last_activity_at` avec son trigger d'abandon, mais AUCUN flux applicatif ne les
utilisait. Le préflight a établi trois faits qui contraignent l'architecture bien plus
que les intentions initiales : `carts.secret_hash` est `NOT NULL`, `UNIQUE` et contraint
à `^[0-9a-f]{64}$`, donc un panier sans secret SHA-256 ne peut PHYSIQUEMENT pas exister ;
aucun TTL panier autoritatif n'existait, la seule valeur du dépôt étant les sept jours de
`CartFactory`, une fixture et non une décision ; et `config/session.php` a pour défaut
`database` alors qu'aucune table `sessions` n'existe ni n'est créée par une migration.

CHOIX (arbitrages MAESTRO) :
1. **Aucune migration.** Le schéma P3 et les ajouts P6-C suffisent. 46 migrations
   inchangées.
2. **TTL de 14 jours, configurable.** `config/cart.php` + `CART_TTL_DAYS`, même patron
   que `CHECKOUT_PENDING_TTL_MINUTES`. `expires_at` est posé UNE FOIS à la création et
   jamais recalculé : le prolonger à chaque visite rendrait l'expiration inatteignable
   pour exactement les paniers qui en ont besoin. Valeur invalide ⇒ refus AVANT écriture.
3. **Rien ne ressuscite.** Un panier `converted`, `abandoned`, `expired` ou dépassé
   retrouvé en session est REMPLACÉ, jamais réactivé ni cloné. Réactiver contredirait
   littéralement le commentaire du trigger P6-C, qui refuse de bouger `last_activity_at`
   sur ces états précisément pour qu'un churn d'items ne rappelle pas un panier mort ;
   cloner produirait un panier sans trace d'abandon exploitable par le ledger de relances.
4. **Garde de session fail-closed**, même patron que `MailTransportGuard` (D-061). Le NOM
   ne prouve rien : `SessionStoreGuard` résout le HANDLER et exige un
   `CacheBasedSessionHandler` adossé à un `RedisStore`. Le driver `array` n'est admis que
   si `app()->runningUnitTests()` est vrai — condition qu'aucune requête ne peut
   influencer. AUCUNE table `sessions` n'est créée : le déploiement voulu est Redis.
5. **Secret imposé par le schéma.** CSPRNG 256 bits en mémoire, SHA-256 seul persisté, la
   valeur brute ne quitte jamais le service. Ce gate n'en fait PAS une capacité de reprise
   par lien : `public_id` n'apparaît dans aucune route. L'appartenance est prouvée par le
   `visitor_id` de session, jamais par une valeur venue du client.
6. **Produit devenu indisponible.** Ligne générique « Article indisponible » : ni nom, ni
   prix, ni MOTIF — dépublication, archivage, suppression et retrait de prix sont
   indiscernables de l'extérieur. Exclue du total, et JAMAIS supprimée sur un `GET`.
7. **Quantité fixée à 1**, aucun sélecteur, aucune route `PATCH`. Ajout idempotent.

⚠️ DÉFAUT RÉEL TROUVÉ EN TENTANT DE PROUVER LA CONCURRENCE. `firstOrCreate` vérifie PUIS
insère : le perdant d'une course arrive sur un INSERT dont la ligne existe déjà et
recevait un 500. Le service tolère désormais un `23505` **confirmé sur la seule contrainte
`cart_items_cart_product_unique`**, via `PostgresConstraintViolation` (SQLSTATE + nom
exact, jamais par sous-chaîne) ; toute autre erreur BDD remonte. C'est la tentative
honnête de preuve qui a révélé le bug, pas la relecture.

⚠️ LIMITE DE PREUVE ASSUMÉE — À CONNAÎTRE AVANT LE CHECKOUT. La course RÉELLE à deux
connexions n'est PAS prouvée. `RefreshesDatabaseAsMigrator` enveloppe chaque test dans une
transaction : un `commit()` interne ne libère qu'un savepoint, la transaction externe garde
le verrou, et la seconde connexion expire en **`57014`** au lieu de voir **`23505`**. La
prouver exigerait le harnais non transactionnel utilisé en P6-D1.1. Ce qui EST prouvé :
PostgreSQL rejette le doublon en `23505` sur la contrainte nommée, et la classification
refuse un autre nom. ⚠️ Le verrou `Cache::lock` ne prouve rien non plus en test
(`CACHE_STORE=array` ⇒ verrou par processus) et sa clé est le visiteur : il sérialise deux
requêtes qui PARTAGENT DÉJÀ une session, pas deux qui ont couru avant qu'un visiteur
existe. **L'index unique est la garantie structurelle, pas le verrou applicatif.**

⚠️ `PricingService` N'EST PAS APPELÉ À L'AFFICHAGE. Son `quote()` est fail-closed sur le
produit (P3-D1) et refuse le devis ENTIER si une ligne devient invendable — `/cart`
deviendrait un 500 dès qu'un admin dépublie un produit. Le total d'affichage somme les
`price_minor` XOF actifs, en entiers, quantité 1, sans coupon. `PricingService` reste
l'autorité du checkout, là où refuser EST le bon comportement.

⚠️ AUCUN SWEEP D'EXPIRATION. Le contrôle `now() > expires_at` à la lecture suffit pour ce
gate ; c'est un choix assumé, pas un oubli.

PREUVE MESURÉE (trigger P6-C sous privilèges runtime) : `session_user` = `current_user` =
`digitrove_runtime`, `has_function_privilege(…, 'touch_cart_last_activity()', 'EXECUTE')`
= **false**, et l'INSERT comme le DELETE déplacent bien `last_activity_at` ; sur un panier
non actif il ne bouge pas. ⚠️ Le test a d'abord échoué pour une raison qui n'était PAS le
trigger : `last_activity_at` est `timestamptz(0)`, donc un INSERT et un DELETE de la même
seconde produisent la MÊME valeur stockée.

HORS PÉRIMÈTRE : aucun coupon (`coupon_id` reste NULL), aucun checkout, aucun paiement,
aucun compte client (`user_id` reste NULL), aucune reprise par lien, aucun Schema.org,
aucune affiliation. Un panier invité reste structurellement inadressable par e-mail et
n'est donc pas candidat aux relances P6-C.

CONTRAT RESCOPÉ : `P6CCartResumeTest` exigeait EXACTEMENT les trois URI de reprise.
Rescopé par ÉNUMÉRATION EXACTE — `cart`, `cart/items/{slug}` ×2 (POST et DELETE partagent
l'URI), plus les trois URI P6-C. Aucun wildcard : toute autre URI panier échoue toujours.
`P4B_ALLOWED_SERVICE_FILES` élargie de deux chemins nommés.

TESTS : `StorefrontGuestCartTest` (lecture sans écriture, création, TTL configuré, refus
d'un TTL invalide, éligibilité produit fail-closed, idempotence, retrait, isolation entre
sessions, non-résurrection, produit retiré, garde de session, confidentialité) et
`StorefrontGuestCartConcurrencyTest` (trigger sous privilèges runtime, horloge figée sur
panier non actif, classification `23505`). Suite complète : **1616 tests / 12388
assertions**, 0 échec.

IMPACT : le panier invité est fonctionnel de bout en bout. Le checkout invité est le gate
suivant et devra lire cette entrée avant de s'appuyer sur la concurrence du panier.

### D-062 : Catalogue Storefront dynamique et provisionnement produit ✅

CONTEXTE : le schéma P2 complet existait, mais aucune ressource Filament produit,
catégorie ou fichier, aucun import de contenu réel et aucune lecture publique dynamique
n'existaient. SITE-00 conservait cinq offres, quatre catégories et trois avis dans une vue
statique. Le Storefront MVP doit rendre ce catalogue consultable avant d'ouvrir le panier,
sans inventer de nouvelle structure de données ni publier automatiquement un contenu.

CHOIX :
1. **Réutiliser strictement P2, sans migration.** `products`, `product_prices`,
   `product_files`, `categories` et leurs relations restent l'unique schéma. Aucun champ
   d'avis n'est inventé : les trois avis legacy sont signalés comme ignorés par l'import et
   restent du contenu historique non persistant.
2. **Provisionnement administrateur uniquement.** Trois ressources Filament couvrent
   Product, Category et ProductFile. Leur policy commune exige un admin actif et non
   supprimé ; staff, customer, suspended et blocked sont refusés. Les prix sont saisis en
   XOF entier. Les livrables sont écrits sur le disque privé et leur SHA-256 est calculé
   côté serveur ; chemin et digest ne sont jamais rendus publiquement.
3. **Import audité et idempotent.** Une source PHP structurée remplace la duplication de
   tableaux dans `welcome.blade.php`. `catalog:import-legacy` crée 5 produits, 4 catégories,
   5 prix XOF et 5 liaisons au premier passage, tous en `draft` avec `published_at = NULL`.
   Un rejeu ignore les lignes existantes et ne publie rien. Les factories restent réservées
   aux tests.
4. **Lecture publique fail-closed.** Le scope `Product::published()` exige simultanément
   `status = published`, une date `published_at` non future, l'absence de soft-delete et un
   prix XOF actif. `/`, `/products` et `/products/{product:slug}` utilisent ce scope ; la
   fiche d'un brouillon, d'une archive, d'un produit futur, supprimé ou sans prix XOF actif
   répond 404.
5. **Frontière MVP.** Prix XOF seulement, descriptions et métadonnées échappées, aucune
   donnée structurée Schema.org, aucun panier, checkout, coupon, paiement ou téléchargement
   public. La couverture marketing peut être publique ; le livrable reste privé.

VALIDATION : import réel = **5 produits / 4 catégories / 5 prix, tous draft**, puis rejeu
idempotent = **0 création** ; tests catalogue **18 / 120** ; `npm run build` et contrôle
responsive desktop/mobile sans débordement ; Pint **536 fichiers** ; `git diff --check`
propre ; suite exhaustive **1586 / 12281** ; **46 migrations inchangées**.

IMPACT : le catalogue public dynamique et son administration sont prêts pour revue sur
`codex/storefront-catalogue`. P6-D1.1 reste en pause à `f15d192`. Le prochain gate produit
est le panier invité, dans un prompt séparé ; aucune logique panier n'est anticipée ici.

### D-048 : P6-A1.3 — Explicit Historical Commerce Rollup Backfill ✅ (MERGÉ)
CONTEXTE : P6-A1.2 rafraîchit un rollup dès qu'une **nouvelle** attribution ou un **nouveau** refund `succeeded` survient, mais ne reconstruit pas l'historique antérieur. P6-A1.3 est l'**outil opérateur explicite** qui retrouve les couples historiques et les injecte dans le pipeline P6-A1.2. **Mergé sur la stable** via PR #35 (head `ba32582`, merge `106ffb0a`, CI #42 success). **P6-A2 (Typed Versioned CRM Segments) devient le gate actif ; P6-B0 non commencé.**

**Frontière figée** : P6-A1.1 = **autorité financière** · P6-A1.2 = **orchestration durable / recovery** · P6-A1.3 = **backfill historique explicite**.

CHOIX :
1. **Migration unique 000024** (`2026_07_14_000024_create_crm_commerce_rollup_backfill_runs.php`, **40 migrations**, aucune `000025`). Elle crée UNE table d'audit durable `crm_commerce_rollup_backfill_runs` (owner `digitrove_crm_executor`) et six autorités `SECURITY DEFINER`. **Aucun backfill n'est exécuté par la migration elle-même.**
2. **Source autoritative unique** : `crm_order_attributions INNER JOIN orders` avec `status IN ('paid','partially_refunded','refunded') AND paid_at IS NOT NULL`. Contact = `crm_order_attributions.contact_id` ; devise = `orders.currency`. **Aucun e-mail, aucun resolver, aucun User/Visitor stitching, aucun Analytics, aucun catalogue.** P6-A1.3 ne crée ni contact ni attribution, ne modifie ni Order, ni Refund, ni la table rollup, et ne calcule aucun montant.
3. **HIGH-WATER MARK, PAS UN SNAPSHOT MVCC — ADAPTATION AU SCHÉMA RÉEL** : `crm_order_attributions` n'a **pas** de colonne `id` (sa PK **est** `order_id`, cf. 000021) et **aucun marqueur d'insertion autoritatif n'existe** — `attributed_at` est `timestamp(0)` (précision 1 seconde) **et fourni par l'appelant** (l'autorité P6-A1.0 passe `clock_timestamp()`, les fixtures passent `NOW()`), donc il ne peut pas clôturer les insertions ; aucun trigger ne l'impose. Une architecture `xmin`/snapshot exporté serait disproportionnée pour un outil opérateur. Le run gèle donc `attribution_order_id_high_water_mark = COALESCE(MAX(order_id), 0)`, dont la sémantique **exacte** est :

   > toute attribution présente au démarrage du run vérifie `order_id <= HWM`.

   La réciproque n'est **pas** revendiquée. Une attribution **tardive sur un ancien Order** vérifie aussi `order_id <= HWM` : le run peut ou non la voir selon la position du curseur keyset. **Ce n'est pas une anomalie** — le contrat de course est :
   - tardive sur un **nouvel** Order (`order_id > HWM`) : ignorée par le backfill, **enqueue par le trigger P6-A1.2** ;
   - tardive sur un **ancien** Order (`order_id <= HWM`) : **enqueue par le même trigger**, que le backfill la revoie ou non ;
   - **double couverture** (backfill + trigger) : inoffensive — P6-A1.2 coalesce par génération et P6-A1.1 recalcule autoritativement depuis Commerce, donc le **résultat financier final est identique** (aucun double comptage).

   Le système est **race-safe**, il n'est **pas** snapshot-isolé sur la population d'attributions. **Preuve de finitude** : les attributions sont immuables (trigger `BEFORE UPDATE OR DELETE`) et il existe **au plus une attribution par Order** (la PK **est** `order_id`) ; le domaine candidat `{attributions WHERE order_id <= HWM}` est donc borné par les Orders existant au démarrage — fini, donc le parcours keyset **termine toujours**.
4. **Pagination keyset**, jamais d'`OFFSET` : clé `(contact_id, currency)`, ordre déterministe `contact_id ASC, currency ASC`, curseur durable `(cursor_contact_id, cursor_currency)` (tous deux NULL ou tous deux non NULL). Les candidats sont `DISTINCT` : 100 Orders XOF d'un même contact = **un** couple ; XOF + USD = **deux** couples.
5. **Run durable audité** : `attribution_order_id_high_water_mark`, `batch_size` (1..100), curseur, `batches_processed_count`, `enqueued_pairs_count`, `status` (`ready|running|completed|failed`), `last_error_code` (SQLSTATE 5 caractères **uniquement**), horodatages cohérents avec le statut. **Aucune PII, aucun payload, aucun message d'erreur brut, aucune stack trace.** Un **index unique partiel** garantit au plus **un run actif** (`ready|running`) au niveau du stockage.
6. **Autorités** (owner executor, `search_path` fixe, objets qualifiés) : `current_..._snapshot`, `list_..._candidates` (READ-ONLY, alimente le dry-run), `start_...`, `get_..._run`, `process_..._batch`, `retry_..._run`. **`process_batch` sélectionne lui-même les couples** depuis la source autoritative : le runtime ne peut donc jamais injecter une identité arbitraire dans `enqueue`. Chaque batch est transactionnel ; en cas d'échec la sous-transaction annule **tout** (aucun curseur, compteur ni enqueue partiel) et seul un SQLSTATE est conservé. Le retry d'un run `failed` est **explicite**.
7. **Commande opérateur** `crm:backfill-commerce-rollups`, **dry-run par défaut**. Mutation seulement avec **`--execute` ET `CRM_COMMERCE_ROLLUP_BACKFILL_ENABLED=true`** (double barrière). Options `--run`, `--batch-size` (1..100), `--max-batches` (1..100), `--retry-failed` : une invocation traite au plus 100 × 100 couples, jamais de boucle infinie ; un run interrompu reste **resumable** depuis son curseur durable. **Aucun job, scheduler, listener ou cron de backfill** : le seul pipeline asynchrone reste P6-A1.2. Le backfill peut légitimement remplir l'outbox même si le drain P6-A1.2 est désactivé — il ne contourne jamais cette protection, il l'affiche (`rollup_refresh_processing_enabled=0|1`).
8. **Idempotence** : ce qui est idempotent est le **résultat financier**, pas le numéro de génération. Un nouveau backfill explicite peut ré-enqueue un couple (`requested_generation++`) : P6-A1.2 coalesce et P6-A1.1 reconstruit autoritativement, donc la projection est identique. Dans un même run, un couple n'est parcouru **qu'une fois**.
9. **ACL** : aucun nouveau rôle. Runtime `EXECUTE` sur les six autorités operator-safe **seulement** ; jamais `SELECT`/`DML` sur `crm_commerce_rollup_backfill_runs`, jamais `EXECUTE` sur `enqueue_crm_commerce_rollup_refresh` (P6-A1.2) ni `refresh_crm_contact_commerce_rollup` (P6-A1.1) ; PUBLIC sans accès ; executor NOLOGIN/NOINHERIT propriétaire.
10. **Rollback 000024** : révoque les `EXECUTE` runtime, supprime les six fonctions et la table — restaure exactement la frontière `000023` (P6-A1.2, P6-A1.1, P6-A1.0, P6-A0, Commerce et les rôles intacts).
11. **Index** : aucun index nouveau sur une table existante. L'index `crm_order_attributions_contact_order_index (contact_id, order_id)` préexistant sert déjà le parcours ordonné par contact.

TESTS : Schema, Candidates, RunAuthority, Command, Concurrency, Privileges, Rollback, SecurityContract. Aucun `session_replication_role`, aucune désactivation de trigger, aucun affaiblissement d'ACL : la preuve d'échec atomique utilise une contrainte `NOT VALID` temporaire posée et retirée par le **propriétaire** de la table. Compteurs de migration relevés 39→40 **uniquement** là où ils mesurent l'état courant ; les frontières historiques (37 pour `000021`, 39 pour `000023`) restent inchangées.

IMPACT : P6-A1.3 prêt pour revue. Après merge, le prochain gate est **P6-A2 — Typed Versioned CRM Segments**, dont l'architecture est gelée par D-049 (non commencé).

### D-049 : P6-A2 — Typed Versioned CRM Segments — ARCHITECTURE GELÉE (PLAN SEULEMENT) 📐
CONTEXTE : Cette décision **fige l'architecture** de P6-A2 après audit du dépôt réel, afin que le prochain agent implémente sans refaire l'audit. **P6-A2 EST NON COMMENCÉ : aucun code, aucune migration `000025`, aucune table, fonction, rôle, job, UI ou route n'existe.**

**Principe fondamental** : un segment est une **définition typée, allowlistée et versionnée**, matérialisée par **génération immuable**. Le moteur n'évalue jamais d'expression fournie par un utilisateur.

CHOIX (à implémenter en P6-A2, pas avant) :
1. **Interdits absolus** : SQL libre, nom de colonne/table libre, opérateur libre, JSONPath libre, callable PHP, `raw WHERE`/`HAVING`, `eval`, expression arbitraire. Toute définition est une structure **typée** dont chaque champ est validé contre une **allowlist fermée** (fail-closed), à l'image des enums et contrats déjà utilisés en P3-D/P4-C/P6-A1.
2. **Primitives de critères allowlistées** (première version recommandée) : `commerce.net_revenue_minor`, `commerce.gross_revenue_minor`, `commerce.refunded_amount_minor`, `commerce.acquired_orders_count`, `commerce.first_acquired_at`, `commerce.last_acquired_at` (source : `crm_contact_commerce_rollups`) ; `contact.created_at`, `contact.status`, `contact.origin` (source : `crm_contacts`). Aucune lecture d'Analytics, de Commerce brut ou du catalogue.
3. **Types et opérateurs** : entiers `BIGINT` (jamais de float, jamais de division flottante) avec `eq|neq|gt|gte|lt|lte|between` ; dates `TIMESTAMPTZ` avec `before|after|between` et **bornes absolues UTC** (aucune expression relative interprétée) ; énumérations avec `in|not_in` sur des valeurs allowlistées.
4. **Devise obligatoire sur tout critère monétaire — AUCUN LTV global.** Un critère monétaire porte toujours `currency` (`^[A-Z]{3}$`) et s'évalue sur la ligne `(contact_id, currency)` correspondante. **Jamais** d'addition XOF + USD, jamais de FX implicite, jamais de conversion. Un segment multi-devises se compose de critères par devise, explicitement.
5. **Versioning et générations** — modèle recommandé : `crm_segments` (identité stable) → `crm_segment_versions` (définition typée **immuable** une fois publiée) → `crm_segment_generations` (exécution datée d'une version) → `crm_segment_generation_members` (`contact_id` matérialisé). Un refresh **crée une nouvelle génération** et la publie **atomiquement** ; une génération publiée est immuable. Les lecteurs ne voient **jamais** une demi-génération ni un membership partiel (publication par bascule d'un pointeur `current_generation_id` dans la même transaction, ou équivalent prouvé).
6. **Contacts** : le calcul d'appartenance considère l'état du contact (`active`, anonymisé, supprimé) selon des règles explicites et testées. **Le consentement marketing reste une politique SÉPARÉE** : « appartenir à un segment » n'est jamais « être autorisé à recevoir une campagne ». Le filtre de consentement s'applique au moment de l'envoi (P6-B/campagnes), pas au calcul du segment.
7. **ACL** : réutiliser `digitrove_crm_executor` (owner) et `digitrove_runtime` (EXECUTE-only) — **aucun nouveau rôle** sauf preuve contraire. PUBLIC sans accès. Le runtime ne lit jamais directement les tables de segments ; il passe par des autorités `SECURITY DEFINER` bornées (définition validée, rebuild, lecture de membership paginée).
8. **Rebuild** : commande/queue bornée et **désactivée par défaut**, sur le modèle P6-A1.2/A1.3 (feature flag + option explicite). Aucun rebuild automatique au déploiement.
9. **Rollback** : la migration P6-A2 devra restaurer exactement la frontière `000024` (tables, fonctions et grants P6-A2 supprimés ; P6-A1.x intact).
10. **BDD/TDD** : tests d'abord — schéma, allowlist fail-closed (chaque champ/opérateur non listé refusé), currency-safety (aucune agrégation multi-devises), immuabilité d'une version publiée, atomicité de la génération, absence de demi-génération sous concurrence, ACL, rollback, séparation consentement/appartenance.

IMPACT : **P6-A2 NON COMMENCÉ, AUCUN CODE, AUCUNE MIGRATION `000025`.** Cette décision est un contrat d'architecture à appliquer après le merge de P6-A1.3.

### D-047 : P6-A1.2 — Durable Rollup Refresh Orchestration & Reconciliation ✅ (MERGÉ)
CONTEXTE : P6-A1.1 a livré l'**autorité financière** `refresh_crm_contact_commerce_rollup(BIGINT, VARCHAR)`. P6-A1.2 orchestre **durablement** l'appel à cette autorité sans jamais recalculer les montants. **TERMINÉ, MERGÉ ET VALIDÉ** via PR #34, head `a75eef68b0621a438395a152bb5e481d0d256a0e`, merge `7dc78aff8a89aaff513efbb1239d5f943d7d21df`, **CI #41 SUCCESS** (Syntax / Pint / Tests / runtime privilege boundary). **P6-A1.3 (backfill historique explicite) devient le gate actif.**

**Frontière fondamentale** : P6-A1.1 = autorité financière ; **P6-A1.2 = orchestration durable / recovery** ; P6-A1.3 = backfill historique explicite. La vérité financière reste Orders + refunds `succeeded` + attribution immuable — jamais Redis, jamais Analytics, jamais PHP.

CHOIX :
1. **Migration unique 000023** (`2026_07_14_000023_create_durable_crm_rollup_refresh_pipeline.php`, **39 migrations**, aucune `000024`, aucun backfill). Crée la table outbox `crm_commerce_rollup_refresh_outbox` (PK `(contact_id, currency)`, owner `digitrove_crm_executor`) et cinq fonctions PostgreSQL.
2. **Outbox coalescée** : clé logique `(contact_id, currency)` ; compteur de génération durable `requested_generation >= processed_generation` (CHECK). Chaque événement source **incrémente** `requested_generation` ; le traitement d'une génération N ne perd jamais une génération N+1 concurrente. Aucune PII (ni email, user_id, visitor_id, order/refund payload, exception brute) ; au plus `last_error_code` (SQLSTATE) et `terminal_reason` allowlisté. FK contact `ON DELETE RESTRICT`.
3. **Signaux PostgreSQL** : trigger `AFTER INSERT` sur `crm_order_attributions` (contact = `NEW.contact_id`, devise = `orders.currency`) ; trigger `AFTER INSERT OR UPDATE OF status` sur `refunds` pour la **première entrée** en `succeeded` (terminal/immuable ⇒ `false → true` suffit), résolvant refund→payment→order→attribution. **Le contact vient uniquement de l'attribution**, jamais de l'e-mail ; un refund `succeeded` avant attribution n'invente aucun contact — la future attribution déclenchera le refresh qui inclura ce refund.
4. **Autorités `SECURITY DEFINER`** (owner executor, `search_path` fixe, objets qualifiés) : `enqueue_crm_commerce_rollup_refresh` (UPSERT `ON CONFLICT ON CONSTRAINT`, réactive un terminal sur nouvel événement), `list_due_crm_commerce_rollup_refreshes(limit 1..100)`, `process_crm_commerce_rollup_refresh` (verrou `FOR UPDATE` de la ligne = point de sérialisation, capture la génération observée, appelle l'autorité de refresh, avance `processed_generation` sans écraser une génération plus récente, idempotent/replay-safe). Retry transient borné (backoff, `attempt_count`) ; terminal explicite sur overflow/intégrité ; jamais de clamp ni de faux succès.
5. **ACL** : aucun nouveau rôle. Runtime `EXECUTE` sur **`list_due` et `process` uniquement** ; jamais `SELECT`/`DML` sur l'outbox, jamais `EXECUTE` sur `enqueue`, les triggers, ou l'autorité de refresh P6-A1.1 ; PUBLIC sans accès. Les triggers écrivent via ownership/SECURITY DEFINER, jamais par élargissement runtime.
6. **Couche Laravel mince** : job `ProcessCrmCommerceRollupRefresh` (`ShouldBeUnique`, clé d'unicité `contactId:currency`, payload **`contactId`+`currency` seul**, `afterCommit`, queue `crm`, aucun calcul monétaire). **Horizon de retry** = `tries × (timeout + max(backoff))` = `5 × (60 + 300)` = **1800 s < `uniqueFor` = 3600 s** (invariant asserté dans le Job test). Dispatcher/sweeper `crm:sweep-commerce-rollup-refresh` (recovery borné du durable, **aucun backfill**) ; scheduler 5 min **désactivé par défaut** (`CRM_COMMERCE_ROLLUP_REFRESH_PROCESSING_ENABLED=false`).
7. **Rollback 000023** restaure exactement la frontière 000022 : drop des deux triggers, révocation `EXECUTE` runtime, drop des cinq fonctions et de l'outbox ; conserve `refresh_crm_contact_commerce_rollup`, `crm_contact_commerce_rollups`, `crm_order_attributions(_outbox)`, `orders`/`payments`/`refunds`, `digitrove_crm_executor`.

TESTS : Schema, Signals, Privileges, Rollback, Concurrency (dont la preuve « génération 4 en cours de traitement, génération 5 concurrente non perdue »), Job, Sweeper, SecurityContract. La preuve de concurrence utilise un verrou `FOR UPDATE` réel entre deux connexions — aucun `session_replication_role`, aucune désactivation de trigger. Compteurs de migration des phases antérieures relevés de 38 à 39.

IMPACT : P6-A1.2 prêt pour revue. Le pipeline de refresh durable est en place, désactivé par défaut. P6-A1.3 (backfill historique explicite) reste le prochain gate non commencé.
