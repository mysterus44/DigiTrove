# PROJECT_CONTEXT.md — Contexte Projet (le fichier le + lu)
# Volontairement court. Relu à CHAQUE session par Claude Code et Codex.

---

## PROJET

**Nom** : **DigiTrove**

**Description** : écosystème **e-commerce + CRM/ERP 100 % autonome** de vente de
produits digitaux (logiciels, vastes packs de formation, e-books).

**Vision** : **indépendance absolue.** Aucune plateforme tierce (ni Shopify, ni
Gumroad, ni marketplace) pour la gestion ni pour les ventes. Tout est interne.

---

## LES 3 BLOCS DU PRODUIT

### 1. Front-office (boutique + blog)
- Catalogue ultra-rapide, optimisé pour la conversion
- Tunnel de commande (checkout) **interne**, fluide et sécurisé
- Blog intégré nativement pour propulser le SEO

### 2. Back-office (écosystème administratif & marketing)
- Tableau de bord **Filament** : catalogue, commandes
- Module **CRM** : données clients, comportements d'achat, segmentation
- **Analytics** ventes & marketing : graphiques, taux de conversion, campagnes

### 3. Cœur technique (sécurité & automatisation)
- **Livraison automatisée et sécurisée** des accès et gros fichiers digitaux après
  paiement : liens **uniques, expirables, révocables**
- Base **SQL robuste**, prête pour l'analyse poussée et le volume

---

## POINT DE DÉPART RÉEL

L'ancien DigiTrove est du **PHP procédural**, avec les données en **JSON + SQLite**.
Ce n'est **pas un refactor** : c'est une **réécriture complète**. On garde le contenu
(produits, articles, avis), on jette l'architecture.
Détail de l'existant et failles trouvées : `.context/context/AUDIT_LEGACY.md`.

---

## STACK (validée — ne pas changer sans justifier dans DECISIONS_LOG.md)

| Couche | Choix |
|--------|-------|
| Framework | **Laravel 13** (PHP 8.3+), orienté objet — voir D-012 |
| Base de données | **PostgreSQL 16** (partitionnement, JSONB, window functions) |
| Admin / ERP | **Filament 5** (gratuit, moderne — pas Nova) — voir D-012 |
| Front | Blade + Tailwind + Alpine.js (ou Livewire) |
| Mots de passe | **Argon2id** |
| Argent | **BIGINT** en unités mineures — jamais FLOAT |
| Fichiers digitaux | Disque **privé** (local ou S3), jamais `public/` |
| Tests / style | **Pest** / **Pint** |
| IA de dev | Claude Code ⇄ Codex (co-codage, voir CO_CODING_PROTOCOL.md) |

---

## RÈGLES STRUCTURANTES (les 5 non négociables)

1. **La BDD avant la logique.** Aucune feature codée avant sa migration testée.
2. **Snapshot du prix et du nom dans `order_items`.** Un prix qui change demain ne
   doit pas réécrire la comptabilité d'hier.
3. **L'analytique ne partage pas les tables chaudes.** `events` est append-only,
   partitionnée, sans clé étrangère. Le dashboard lit des rollups.
4. **`visitors`** (identité anonyme persistante) : sans elle, aucune attribution
   marketing possible, et le CRM devient décoratif.
5. **Livraison** : on ne stocke que le **hash** du token de téléchargement, jamais
   le token ni l'URL du fichier. Expiration + quota + révocation obligatoires.

---

## ORDRE D'IMPLÉMENTATION

```
P0  Fondations      : Laravel, PostgreSQL, .env, Filament, tests, CI
P1  Identité        : users + customer_profiles + visitors
P2  Catalogue       : products + product_files + categories + bundles
P3  Commerce        : carts → orders → order_items → payments
P4  Livraison       : download_grants + download_logs   (⚠️ cœur sécurité)
P5  Analytique      : events partitionnée + rollups + campaigns
P6  CRM & Marketing : segments, dashboards, campagnes
P7  Blog & SEO      : articles, sitemap, données structurées
```

---

## ATTENTES DE KINGKOUDA (mode de fonctionnement)

- **Code & architecture** : PHP/Laravel moderne, propre, orienté objet.
  Toujours proposer la structure de la base **avant** de coder la logique.
- **Sparring partner** : challenger ses idées. Si une table peut être mieux pensée
  pour l'analyse future, le dire.
- **Interface & UX** : le front (JS/CSS) doit pouvoir accueillir des maquettes
  Figma professionnelles.
- **Sécurité** : tolérance zéro. Protéger les transactions et les téléchargements.
