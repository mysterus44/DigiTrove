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

**Date** : 2026-08-03. **Statut** : **P5-A3A/B IMPLÉMENTÉ — EN ATTENTE DE
REVUE/MERGE** sur `p5-a3ab-admin-analytics-dashboard`.

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
read-only, identités, cache, UI et rollback isolé sont couverts. P5-A3C,
P5-A3D, P6 et P7 ne sont pas commencés. Prochaine tâche après merge :
**P5-A3C — Product and Funnel Analytics Views**.

## À AJOUTER AU FIL DU PROJET
[Chaque nouvelle décision importante vient ici, datée.]
