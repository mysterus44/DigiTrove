# PRD_05 — ANALYTIQUE (events partitionnée + rollups)
# Phase P5. Prérequis : P1→P4. Le transactionnel doit être stable AVANT d'ajouter
# la charge d'écriture analytique.

---

## 🎯 OBJECTIF
Capter le comportement (visiteurs, sessions, événements) sans jamais ralentir le
commerce, et alimenter des tables de rollup que le dashboard lira directement.

---

## 📄 PROMPT PRÊT À COLLER

```
Lis AGENTS.md, DigiTrove_Schema_BDD_v1.md (bloc ANALYTIQUE) et
.context/skills/CRM_ANALYTICS.md.

MISSION P5 — Analytique : capture d'événements + rollups.

BDD D'ABORD :
- events : table APPEND-ONLY, PARTITIONNÉE PAR MOIS (RANGE sur occurred_at),
  SANS clé étrangère vers users/orders (ids en colonnes molles). Index sur
  (event_name, occurred_at), (visitor_id, occurred_at), GIN sur properties.
  Crée la partition du mois courant + une commande/job pour créer les partitions
  à venir automatiquement.
- analytics_sessions (durée, pages vues, entrée/sortie, utm)
- daily_sales_stats, daily_product_stats, daily_funnel_stats (rollups)

Puis :
- EventTracker (service) : enregistre un événement (page_view, product_view,
  add_to_cart, begin_checkout, purchase…) avec visitor_id, session_id, properties
  JSONB, utm, ip HACHÉE (jamais l'IP brute — RGPD).
- Middleware qui assure un visitor_id (cookie 1re partie) et une session analytique.
- Écriture des events en asynchrone (queue/job) pour ne pas ralentir la requête.
- Job planifié (Scheduler) qui agrège events → tables daily_* (horaire).
- Le dashboard et les stats LISENT les tables daily_*, JAMAIS events directement.

CONTRAINTES :
- Zéro FK entre events et les tables chaudes.
- IP toujours hachée. Aucune donnée perso brute dans events.
- Les écritures analytiques ne doivent pas bloquer une page ou un checkout.

LIVRABLES :
- Migrations (table partitionnée + rollups) + EventTracker + middleware + jobs
- Tests Pest : event écrit en JSONB · partition du mois utilisée · rollup calcule
  correctement le CA du jour (en entiers) · ip stockée hachée · funnel cohérent
- Trackers mis à jour (PROCHAINE TÂCHE = PRD_06_CRM_MARKETING)

PROCESSUS : plan d'abord, validation, puis implémentation.
```

---

## ✅ CRITÈRES D'ACCEPTATION
- Les events s'écrivent dans la bonne partition mensuelle.
- Le dashboard ne requête jamais `events` en direct (seulement les rollups).
- Aucune IP en clair, aucune FK vers le transactionnel.
- `php artisan test` + `pint` verts.

## 🚫 HORS PÉRIMÈTRE
Segments CRM et campagnes (PRD_06). Affichage graphique riche (PRD_06).
