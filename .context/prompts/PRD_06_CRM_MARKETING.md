# PRD_06 — CRM & MARKETING (segments, dashboards, campagnes)
# Phase P6. Prérequis : P5 (les rollups existent et sont alimentés).

---

## 🎯 OBJECTIF
Exploiter les données : segmenter les clients, visualiser les ventes et le tunnel de
conversion dans Filament, gérer les campagnes marketing et mesurer leur ROI via
l'attribution utm figée aux commandes.

---

## 📄 PROMPT PRÊT À COLLER

```
Lis AGENTS.md, DigiTrove_Schema_BDD_v1.md (customer_segments, campaigns, rollups),
.context/skills/CRM_ANALYTICS.md et .context/skills/FILAMENT_ADMIN.md.

MISSION P6 — CRM & Marketing.

BDD D'ABORD (si pas déjà en place) :
- customer_segments (definition JSONB, is_dynamic) + customer_segment_members
- campaigns (channel, utm_campaign UNIQUE, budget_minor)

Puis :
- SegmentService : évalue une définition JSONB (ex. {"lifetime_value_minor":{">=":50000}})
  et matérialise les membres. Segments dynamiques recalculables par job.
- Maintien des rollups CRM sur customer_profiles (orders_count, lifetime_value_minor,
  first/last_order_at, lifecycle_stage) via listeners sur OrderPaid — pas de COUNT()
  à la volée sur le dashboard.
- Dashboard Filament :
  * widgets ventes (CA jour/semaine/mois, panier moyen) lus depuis daily_sales_stats
  * tunnel de conversion depuis daily_funnel_stats
  * top produits depuis daily_product_stats
  * ROI campagne : recettes des orders where utm_campaign = X vs budget
- CampaignResource (Filament) pour créer/suivre les campagnes.
- Vue client 360° (Filament) : profil, commandes, LTV, segment, source première visite.

CONTRAINTES :
- Toutes les stats lisent les rollups (daily_*), jamais events.
- Argent en entiers. Graphiques via Filament widgets.

LIVRABLES :
- Migrations (si besoin) + SegmentService + listeners rollup + widgets + resources Filament
- Tests Pest : segment JSONB correct · LTV mise à jour à chaque OrderPaid ·
  ROI campagne calculé juste · vue client 360 agrège les bonnes données
- Trackers mis à jour (PROCHAINE TÂCHE = PRD_07_BLOG_SEO)

PROCESSUS : plan d'abord, validation, puis implémentation.
```

---

## ✅ CRITÈRES D'ACCEPTATION
- Un segment se recalcule à partir de sa définition JSONB.
- La LTV d'un client bouge à chaque commande payée, sans recalcul lourd.
- Le ROI d'une campagne se lit via l'attribution utm des commandes.

## 🚫 HORS PÉRIMÈTRE
Blog et SEO (PRD_07). Envoi réel d'emails de campagne (à cadrer plus tard).
