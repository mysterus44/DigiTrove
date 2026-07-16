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
   ligne grant elle-même (UPDATE conditionnel atomique côté service, pattern
   SECURITE_TELECHARGEMENT) ; deux téléchargements simultanés sur la dernière
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
    minimisation RGPD ; rétention recommandée 365 j (valeur à confirmer — non
    bloquant). FK `download_logs.download_grant_id ON DELETE RESTRICT` (aucune
    cascade détruisant l'audit).
CATALOGUE D'OBJETS : G1–G4 (P4-A) et G5–G6 (P4-B), schéma exact, CHECK nommés,
index partiels, matrice de tests et threat model consignés dans le bloc P4 de
`DigiTrove_Schema_BDD_v1.md`. Les triggers REFUSENT et ne mutent jamais.
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
cosmétique). Nouvelle numérotation : P4-A = `000008` + `000009` + `000010`
(frontière harness `000010`, un seul gate/branche `p4-a-download-grants`) ;
P4-B = `000011` (frontière `000011`). **[Séquencement remplacé par D-029.2
ci-dessous : le gate composite P4-A est ABANDONNÉ.]**

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
   - **P4-B — Download Logs** : branche `p4-b-download-logs`, migration
     `2026_07_14_000011_create_download_logs_table.php`, frontière `000011`.
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
**Note d'implémentation P4-A0** : la fonction G0 fige aussi `id` (identité de
ligne), en plus des huit colonnes D-029.2 — alignement sur le précédent projet
(les triggers d'immutabilité P3B/P3C figent toujours la clé primaire dans leur
comparaison). Signalé à l'implémentation, aucune décision modifiée.
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
  isolés et `version` figée par D-029.2). Prêt pour implémentation **P4-A0**
  (`p4-a0-product-file-immutability`, migration `000008` uniquement), puis
  P4-A1 → P4-A2 → P4-B, chaque gate mergé avant le suivant. TTL (72 h), quota (5)
  et rétention logs (365 j) restent des recommandations de CONFIG APPLICATIVE
  (aucun default BDD, D-029.1-B) à fixer à la phase service. La phase licences
  reste une décision produit ouverte (liée à la question `usb` du legacy).

---

## À AJOUTER AU FIL DU PROJET
[Chaque nouvelle décision importante vient ici, datée.]
