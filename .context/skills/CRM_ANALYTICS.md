# CRM_ANALYTICS.md — Tracking, Attribution, Segmentation
# Principe fondateur : l'analytique ne partage JAMAIS les tables chaudes du commerce.

---

## 🧠 LE MODÈLE MENTAL

```
visitor (anonyme, UUID en cookie)
   │  navigue, voit des produits, ajoute au panier
   │  → chaque action = 1 ligne dans `events`
   │
   └─ s'inscrit / se connecte
        → visitors.user_id = users.id      (stitching : on récupère TOUT son passé)
        → customer_profiles.first_touch_* copié depuis visitors.first_touch_*
```

Sans `visitors`, un client qui visite 5 fois puis achète apparaît comme venu de nulle
part. Tu ne sauras jamais quelle campagne l'a amené. **Le CRM devient décoratif.**

---

## 📥 COLLECTE D'ÉVÉNEMENTS

```php
// app/Services/AnalyticsService.php
public function track(string $event, array $props = [], ?Model $entity = null): void
{
    // Écriture asynchrone : ne JAMAIS ralentir la requête utilisateur
    RecordEvent::dispatch([
        'occurred_at'  => now(),
        'visitor_id'   => $this->visitorId(),        // cookie 1re partie, UUID
        'user_id'      => auth()->id(),              // nullable, SANS clé étrangère
        'session_id'   => $this->sessionId(),
        'event_name'   => $event,
        'entity_type'  => $entity ? class_basename($entity) : null,
        'entity_id'    => $entity?->getKey(),
        'properties'   => $props,                    // JSONB
        'page_url'     => request()->fullUrl(),
        'referrer'     => request()->header('referer'),
        'utm_source'   => request()->query('utm_source'),
        'utm_medium'   => request()->query('utm_medium'),
        'utm_campaign' => request()->query('utm_campaign'),
        'ip_hash'      => hash_hmac('sha256', request()->ip(), config('app.key')),
    ]);
}
```

⚠️ `ip_hash`, jamais l'IP brute (RGPD). `user_id` sans clé étrangère (sinon verrous
sur `users` à chaque event).

---

## 📊 LES ÉVÉNEMENTS À TRACKER (le minimum utile)

| Événement | Quand | Propriétés utiles |
|-----------|-------|-------------------|
| `page_view` | chaque page | `path` |
| `product_view` | fiche produit | `product_id`, `price_minor` |
| `add_to_cart` | ajout panier | `product_id`, `quantity` |
| `begin_checkout` | entrée tunnel | `cart_value_minor` |
| `purchase` | commande payée | `order_id`, `total_minor`, `items_count` |
| `download_started` | téléchargement | `product_file_id` |
| `article_read` | fin d'article blog | `article_id`, `scroll_depth` |

Un tunnel complet = `product_view → add_to_cart → begin_checkout → purchase`.
Sans ces 4, tu ne peux pas calculer un taux de conversion. Avec, tu vois où ça fuit.

---

## ⚡ PARTITIONNEMENT — la table `events` grossit vite

Une partition par mois. Créée à l'avance par un job planifié.

```sql
CREATE TABLE events_2026_08 PARTITION OF events
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');
```

Purger 2 ans de données = `DROP TABLE events_2024_08;` → **instantané**, aucun `DELETE`
massif qui bloque la base pendant 20 minutes.

---

## 🧮 ROLLUPS — le dashboard ne lit JAMAIS `events`

```php
// Job planifié (Laravel Scheduler), toutes les heures
final class RefreshDailyStats
{
    public function handle(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO daily_funnel_stats (day, visitors, product_views, add_to_carts, checkouts, purchases)
            SELECT
                date_trunc('day', occurred_at)::date                                  AS day,
                COUNT(DISTINCT visitor_id)                                            AS visitors,
                COUNT(*) FILTER (WHERE event_name = 'product_view')                   AS product_views,
                COUNT(*) FILTER (WHERE event_name = 'add_to_cart')                    AS add_to_carts,
                COUNT(*) FILTER (WHERE event_name = 'begin_checkout')                 AS checkouts,
                COUNT(*) FILTER (WHERE event_name = 'purchase')                       AS purchases
            FROM events
            WHERE occurred_at >= current_date - interval '2 days'
            GROUP BY 1
            ON CONFLICT (day) DO UPDATE SET
                visitors      = EXCLUDED.visitors,
                product_views = EXCLUDED.product_views,
                add_to_carts  = EXCLUDED.add_to_carts,
                checkouts     = EXCLUDED.checkouts,
                purchases     = EXCLUDED.purchases;
        SQL);
    }
}
```

`COUNT(*) FILTER (WHERE …)` est du PostgreSQL pur, bien plus rapide qu'un
`SUM(CASE WHEN …)`. C'est une des raisons du choix de PostgreSQL.

---

## 🎯 ATTRIBUTION — d'où vient l'argent ?

Deux attributions coexistent, et elles ne servent pas à la même chose :

| Type | Stocké dans | Répond à |
|------|-------------|----------|
| **First touch** | `visitors.first_touch_*`, copié dans `customer_profiles` | « Quelle campagne fait découvrir la marque ? » |
| **Last touch** | `orders.utm_*` (figé à l'achat) | « Quelle campagne déclenche l'achat ? » |

```sql
-- Revenu par campagne (last touch), joint aux budgets → ROAS
SELECT c.name, c.budget_minor,
       SUM(o.total_minor)                          AS revenue_minor,
       ROUND(SUM(o.total_minor)::numeric / NULLIF(c.budget_minor,0), 2) AS roas
FROM orders o
JOIN campaigns c ON c.utm_campaign = o.utm_campaign
WHERE o.status = 'paid'
GROUP BY c.id, c.name, c.budget_minor
ORDER BY revenue_minor DESC;
```

---

## 👥 SEGMENTATION CRM

Segments **dynamiques** : la définition vit en `JSONB`, rejouée par un job.

```php
// Exemple de définition stockée dans customer_segments.definition
{
  "lifetime_value_minor": { ">=": 50000 },
  "last_order_at":        { "<":  "-90 days" }
}
// → "Gros clients qui décrochent" : la cible d'une campagne de réactivation
```

Segments de base à créer d'emblée :
- **Nouveaux** : `orders_count = 1`
- **Fidèles** : `orders_count >= 3`
- **VIP** : `lifetime_value_minor >= X`
- **Dormants** : `last_order_at < now() - 90 jours`
- **Panier abandonné** : `carts.status = 'abandoned'` depuis > 2 h

---

## 🔄 ROLLUPS CRM — maintenus par événement, pas par `COUNT()`

```php
// Listener sur OrderPaid
$profile = $order->user?->customerProfile;
$profile?->update([
    'orders_count'         => DB::raw('orders_count + 1'),
    'lifetime_value_minor' => DB::raw('lifetime_value_minor + ' . (int) $order->total_minor),
    'last_order_at'        => $order->paid_at,
    'first_order_at'       => $profile->first_order_at ?? $order->paid_at,
    'lifecycle_stage'      => $profile->orders_count >= 1 ? 'repeat' : 'customer',
]);
```

Afficher la LTV d'un client doit coûter **une lecture**, pas une agrégation.

---

## 🚫 LES 4 FAUTES À NE JAMAIS COMMETTRE

1. Mettre une clé étrangère de `events` vers `orders`/`users` → verrous, écritures lentes.
2. Faire les graphiques du dashboard directement sur `events` → dashboard à 12 secondes.
3. Stocker l'IP brute → problème RGPD gratuit.
4. Oublier `visitors` → aucune attribution possible, jamais, rétroactivement.
