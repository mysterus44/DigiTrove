# PRD_03 — COMMERCE (panier → commande → paiement)
# Phase P3. Prérequis : P1 (identité) + P2 (catalogue) migrés et testés.
# ⚠️ Aucune livraison de fichier ici — c'est P4. Ici on encaisse, pas on livre.

---

## 🎯 OBJECTIF
Permettre à un visiteur (connecté ou invité) d'acheter des produits digitaux via un
tunnel de commande interne, fluide et sécurisé, avec confirmation de paiement
strictement côté serveur.

---

## 📄 PROMPT PRÊT À COLLER

```
Lis AGENTS.md, DigiTrove_Schema_BDD_v1.md (bloc COMMERCE) et
.context/skills/SECURITE_PAIEMENT.md.

MISSION P3 — Commerce : panier, commande, paiement (PAS de livraison, c'est P4).

BDD D'ABORD. Crée les migrations exactement comme spécifié :
- carts + cart_items (panier visiteur ET connecté, rattachable au login)
- orders (order_number type DGT-2026-000123, statuts, totaux en BIGINT, snapshot
  attribution utm_*, email même en invité)
- order_items (⚠️ SNAPSHOT du nom + prix unitaire — jamais de JOIN sur products
  pour un prix historique)
- payments (1 commande = N tentatives, idempotency_key UNIQUE, raw_payload JSONB)
- refunds, coupons

Puis :
- Modèles Eloquent + relations + enums (OrderStatus, PaymentStatus).
- CartService : ajouter/retirer, fusion panier invité→user au login.
- CheckoutService : crée la commande depuis le panier, fige les snapshots et les
  totaux (subtotal, discount, tax, total) en entiers.
- PaymentService + interface PaymentProvider (abstraction : un provider CinetPay
  d'abord, mais l'interface ne dépend d'aucun provider).
- Webhook de paiement : route dédiée, qui (1) vérifie la signature, (2) REVÉRIFIE
  le montant côté serveur contre la commande, (3) applique l'idempotency_key,
  (4) ne passe la commande à 'paid' qu'après ces contrôles. Jamais de passage à
  'paid' sur simple retour navigateur.
- Événement OrderPaid émis (P4 s'y branchera pour la livraison — ne PAS livrer ici).

CONTRAINTES :
- Argent : BIGINT partout, jamais FLOAT.
- Sécurité : rate limiting sur checkout, CSRF, Form Requests pour la validation.
- Une commande invité doit rester rattachable plus tard via email.

LIVRABLES :
- Migrations + modèles + services + interface provider + contrôleur webhook
- Tests Pest : snapshot de prix immuable même si le prix produit change ·
  webhook idempotent (double appel = un seul paiement) · montant falsifié rejeté ·
  fusion panier invité→user · totaux calculés en entiers
- Trackers mis à jour (PROCHAINE TÂCHE = PRD_04_LIVRAISON)

PROCESSUS : plan d'abord, validation, puis implémentation. Une étape à la fois.
```

---

## ✅ CRITÈRES D'ACCEPTATION
- Un prix produit modifié après commande ne change PAS la commande passée.
- Double réception du webhook = un seul paiement enregistré.
- Webhook avec montant ≠ commande = rejeté, commande non payée.
- `php artisan test` + `pint` verts.

## 🚫 HORS PÉRIMÈTRE
Livraison de fichiers, génération de liens (PRD_04). Analytics (PRD_05).
