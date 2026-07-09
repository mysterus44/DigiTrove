# SYSTEM_ARCHITECTURE.md — Architecture globale DigiTrove
# Laravel 13 monolithique · PostgreSQL 16 · Redis · Filament 5

---

## 🗺️ VUE D'ENSEMBLE

```
┌──────────────────────────────────────────────────────────────┐
│                        DIGITROVE                              │
├──────────────────────────────────────────────────────────────┤
│                                                               │
│   FRONT-OFFICE                    BACK-OFFICE (Filament)      │
│   ┌────────────┐  ┌──────────┐   ┌──────────┐ ┌───────────┐  │
│   │ Boutique   │  │  Blog    │   │ Catalogue│ │ CRM       │  │
│   │ Checkout   │  │  SEO     │   │ Commandes│ │ Marketing │  │
│   └─────┬──────┘  └────┬─────┘   └────┬─────┘ └─────┬─────┘  │
│         │              │              │             │         │
│  ═══════════════════════════════════════════════════════════  │
│                    LARAVEL — Routes & Controllers (fins)      │
│  ═══════════════════════════════════════════════════════════  │
│         │              │              │             │         │
│   ┌─────▼──────────────▼──────────────▼─────────────▼──────┐  │
│   │                     SERVICES                            │  │
│   │  OrderService · PaymentGateway · DownloadService        │  │
│   │  AnalyticsService · CrmService · CatalogService         │  │
│   └─────┬───────────────────────────────────────┬──────────┘  │
│         │                                       │             │
│   ┌─────▼──────┐   ┌──────────────┐   ┌─────────▼──────────┐  │
│   │ PostgreSQL │   │    Redis     │   │  Disque PRIVÉ      │  │
│   │  (OLTP +   │   │ cache/queue  │   │  (fichiers, S3)    │  │
│   │  analytics)│   │  sessions    │   │  jamais public/    │  │
│   └────────────┘   └──────────────┘   └────────────────────┘  │
│                                                               │
└──────────────────────────────────────────────────────────────┘
```

---

## 🔄 LE FLUX D'ACHAT (le chemin critique)

```
1.  Visiteur arrive → middleware pose un cookie visitor_id (UUID)
2.  Chaque action → AnalyticsService::track() → queue → table `events`
3.  Ajout au panier → carts / cart_items
4.  Checkout (invité possible) → OrderService::createFromCart()
    └─ crée Order (pending) + order_items AVEC SNAPSHOT prix/nom
5.  PaymentGateway::initiate() → URL de l'agrégateur
6.  Client paie chez l'agrégateur
7.  Webhook reçu :
    ├─ signature vérifiée (hash_equals)
    ├─ idempotence (déjà traité ? on sort en 200)
    ├─ contre-appel getStatus()          ← on ne croit pas le payload
    ├─ montant comparé en entiers        ← anti-fraude
    └─ Order → paid ; Event OrderPaid
8.  Listeners (en queue, idempotents) :
    ├─ IssueDownloadGrants   → tokens hachés, expirables, à quota
    ├─ SendPurchaseEmail     → liens de téléchargement
    └─ UpdateCustomerRollups → orders_count, LTV, lifecycle_stage
9.  Client télécharge → DownloadController vérifie le grant à chaque requête
    └─ incrément atomique du compteur + download_logs
10. Jobs planifiés : rollups horaires · expiration des commandes pending ·
    création des partitions `events` · détection de partage de lien
```

⚠️ **Le retour navigateur (étape 6→7) n'affiche qu'une page.** Il ne livre rien.
Seule l'étape 7, côté serveur, déclenche la livraison.

---

## 📁 ARBORESCENCE

```
app/
├── Enums/          OrderStatus · PaymentStatus · ProductType · LifecycleStage
├── Models/         fins : relations + casts uniquement
├── Services/
│   ├── OrderService.php · CatalogService.php · CrmService.php
│   ├── AnalyticsService.php
│   ├── Download/    DownloadService.php
│   └── Payment/     PaymentGateway.php (interface) · CinetPayGateway.php
│                    WebhookVerifier.php
├── Http/
│   ├── Controllers/ fins
│   ├── Requests/    validation
│   └── Middleware/  TrackVisitor.php
├── Policies/
├── Events/          OrderPaid · GrantRevoked
├── Listeners/       IssueDownloadGrants · SendPurchaseEmail · UpdateCustomerRollups
├── Jobs/            RecordEvent · RefreshDailyStats · CreateEventPartition
├── Support/         Money.php · helpers
└── Filament/        Resources/ · Widgets/
database/
├── migrations/  factories/  seeders/
resources/views/
├── components/  boutique/  blog/  emails/
tests/
├── Unit/  Feature/
```

---

## ⚙️ JOBS PLANIFIÉS (Scheduler)

| Job | Fréquence | Rôle |
|-----|-----------|------|
| `RefreshDailyStats` | horaire | rollups sales / product / funnel |
| `ExpirePendingOrders` | 10 min | annule les commandes pending > 30 min |
| `CreateEventPartition` | mensuel | crée la partition `events` du mois suivant |
| `DetectLinkSharing` | horaire | grants téléchargés depuis > 3 IP en 24 h |
| `MarkAbandonedCarts` | horaire | paniers inactifs > 2 h → `abandoned` |

---

## 🔐 LES 3 FRONTIÈRES DE SÉCURITÉ

```
1. ENTRÉE      Form Request (validation) + Policy (autorisation) + rate limiting
2. PAIEMENT    signature + getStatus + montant entier + idempotence
3. LIVRAISON   disque privé + token haché + expiration + quota + révocation
```

Chacune est indépendante. Une faille dans l'une ne doit pas ouvrir les autres.

---

## 📈 CHEMIN DE MONTÉE EN CHARGE

| Étape | Déclencheur | Action |
|-------|-------------|--------|
| Aujourd'hui | — | PostgreSQL unique, `events` partitionnée, rollups |
| Trafic ×10 | requêtes lentes | index affinés, cache de vue, réplique de lecture |
| Trafic ×100 | `events` > 100M lignes | export vers ClickHouse/DuckDB pour l'analytique |
| Fichiers volumineux | > 2 Go | S3 + URL pré-signées courtes |

Ne déploie **aucune** de ces étapes avant que la précédente ne souffre réellement.
