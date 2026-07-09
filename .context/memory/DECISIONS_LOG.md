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

---

## 🔶 EN ATTENTE DE VALIDATION PAR KINGKOUDA

- **Le schéma `SCHEMA_BDD.md` dans son ensemble** (v1). Tant qu'il n'est pas validé,
  ne pas générer de logique métier au-delà des migrations P1.
- **Le champ `usb` du legacy** : les produits avaient un champ `usb`. Livraison
  physique sur clé USB ? Si oui, il faut un modèle de commande hybride
  (digital + physique) avec adresse de livraison. À trancher. Voir AUDIT_LEGACY.md.
- **Devise(s)** : XOF seul, ou multi-devises (ventes internationales) ? Le schéma
  porte déjà une colonne `currency`, mais la conversion et l'affichage restent à décider.

---

## À AJOUTER AU FIL DU PROJET
[Chaque nouvelle décision importante vient ici, datée.]
