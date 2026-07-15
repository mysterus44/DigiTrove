# PRD_04 — LIVRAISON SÉCURISÉE (⚠️ CŒUR SÉCURITÉ DU PROJET)
# Phase P4. Prérequis : P3 (commerce) — l'événement OrderPaid doit exister.
# C'est LA feature qui protège ton business. Zéro compromis.

---

## 🎯 OBJECTIF
Après paiement confirmé, livrer automatiquement les fichiers digitaux via des liens
uniques, expirables, à quota, et révocables — sans jamais exposer l'URL réelle du
fichier ni stocker le token en clair.

---

## 📄 PROMPT PRÊT À COLLER

```
Lis AGENTS.md, DigiTrove_Schema_BDD_v1.md (bloc COMMERCE : download_grants,
download_logs) et surtout .context/skills/SECURITE_TELECHARGEMENT.md.

MISSION P4 — Livraison sécurisée des produits digitaux. Tolérance zéro.

BDD D'ABORD (si pas déjà fait en P3) :
- download_grants (token_hash UNIQUE — on stocke le HASH SHA-256, jamais le token ;
  expires_at, max_downloads, downloads_count, revoked_at)
- download_logs (grant_id, ip_hash, user_agent, bytes_sent, status)

Puis :
- Listener sur l'événement OrderPaid : pour chaque order_item payé, crée un
  download_grant par product_file. Génère un token aléatoire (32+ octets), envoie
  le token EN CLAIR au client (email + page de confirmation), stocke UNIQUEMENT
  son hash en base.
- DownloadController (route signée type /download/{token}) qui :
  1. hache le token reçu et cherche le grant correspondant
  2. refuse si : introuvable, expiré, quota atteint, révoqué
  3. sert le fichier depuis le disque PRIVÉ en streaming (jamais de redirect vers
     une URL publique, jamais de fichier dans public/)
  4. incrémente downloads_count et journalise dans download_logs
- Révocation : méthode pour révoquer les grants d'une commande (ex. remboursement).
- DeliveryService encapsule la génération et la révocation.

CONTRAINTES SÉCURITÉ (non négociables) :
- Token : stocké haché, comparé par hash. Jamais loggé en clair.
- Fichier : disque privé (config filesystems 'private'), servi par le contrôleur.
- Rate limiting sur la route de download. Routes signées Laravel.
- Détection d'abus : si un même grant est téléchargé depuis N IP différentes,
  logger une alerte (pas de blocage auto en v1, juste le log).

LIVRABLES :
- Migrations (si besoin) + Listener + DownloadController + DeliveryService
- Tests Pest : lien expiré refusé (403/410) · quota dépassé refusé · grant révoqué
  refusé · token invalide refusé · URL directe du fichier impossible (privé) ·
  téléchargement nominal OK + compteur incrémenté + log écrit
- Trackers mis à jour (PROCHAINE TÂCHE = PRD_05_ANALYTIQUE)

PROCESSUS : plan d'abord, validation, puis implémentation.
```

---

## ✅ CRITÈRES D'ACCEPTATION
- Impossible d'accéder à un fichier sans grant valide.
- Le token n'existe en clair nulle part en base ni dans les logs.
- Lien expiré / quota atteint / révoqué = refusé proprement.
- Chaque téléchargement est journalisé.

## 🚫 HORS PÉRIMÈTRE
Statistiques de téléchargement agrégées (P5/P6). Emails marketing (P6).
