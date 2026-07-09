# AUDIT_LEGACY.md — L'ancien DigiTrove : ce qui existe, ce qui est cassé
# Audit réalisé le 2026-07-08 sur l'archive fournie par KingKouda.

---

## 🚨 FAILLES CRITIQUES TROUVÉES (à traiter avant tout)

### 1. Mots de passe en clair, committés dans git
Deux fichiers contenaient un mot de passe **écrit en dur dans le code source** :

| Fichier | Variable |
|---------|----------|
| `data/setup_database.php` | `$admin_password` (mot de passe personnel, masqué ici volontairement) |
| `admin/admin-blog.php` | `$password` (mot de passe admin du blog) |

⚠️ **Supprimer les lignes ne suffit pas** : les valeurs sont dans **l'historique git**.
Toute personne ayant accès au dépôt (même ancien) peut les récupérer.

**Action requise** :
- [ ] Changer ces deux mots de passe **partout où ils sont réutilisés** (le premier
      ressemble à un mot de passe personnel : messagerie, autres comptes, etc.)
- [ ] Ne jamais réintroduire de secret dans le code (`.env` uniquement)
- [ ] Si le dépôt a été public : considérer les identifiants comme compromis

### 2. Base de données utilisateurs versionnée
`data/users.sqlite` était committé. Elle contient les comptes (avec hachages).
- [ ] Ne jamais committer de base de données. Ajouter au `.gitignore` dès le départ.

### 3. Absence de couches
Pas de validation centralisée, pas d'autorisation par policy, pas de tests.
La sécurité reposait sur des `if` dispersés dans les fichiers PHP.

---

## 📦 CE QUI EXISTE (et qu'on récupère)

### Architecture actuelle
PHP procédural. Données en **fichiers JSON** + une base **SQLite** pour les users.

```
index.php
includes/     config.php · db.php · functions.php · header.php · navbar.php · footer.php
              promo-products.php · submit_review.php
page/         index · boutique · blog · article · a-propos · cgv · faqs
              politique-confidentialite · politique-livraison · return-refund
admin/        dashboard · gestion-produits · gestion-commandes · gestion-utilisateurs
              gestion-blog · gestion_avis · login · logout · edit_produit
              delete_produit · delete_avis · increment_sale · fix_urls
data/         produits.json · avis.json · blog-articles.json · article.json
              users.sqlite · setup_database.php
images/ · uploads/ · videos/
```

### Le contenu à migrer
| Source | Contenu | Cible |
|--------|---------|-------|
| `data/produits.json` | 5 produits | `products` (+ `product_files`) |
| `data/avis.json` | avis clients | `reviews` |
| `data/blog-articles.json`, `article.json` | articles de blog | `articles` |
| `data/users.sqlite` (table `users`) | comptes | `users` + `customer_profiles` |

### Structure d'un produit legacy
```
id · nom · prix_original · prix_reduit · ventes · image · disponible · type · usb · url
```

Correspondances vers le nouveau schéma :
- `prix_original` → `compare_at_price_minor` (prix barré)
- `prix_reduit` → `price_minor`
- `ventes` → `sales_count`
- `image` → `cover_image_path`
- `disponible` → `status` (`published` / `archived`)
- `type` → `type` (enum : software / course / ebook / bundle / template)
- `url` → devient un `product_file` (le fichier ne doit plus être une URL publique)
- `usb` → ❓ **question ouverte** (voir ci-dessous)

⚠️ Les prix legacy sont probablement des nombres décimaux ou des chaînes.
La migration doit les convertir en **entiers (unités mineures)**, avec vérification
manuelle : une erreur de facteur 100 sur un catalogue, ça se voit trop tard.

---

## ❓ QUESTIONS OUVERTES POUR KINGKOUDA

1. **Le champ `usb`** — les produits legacy portent un champ `usb`. S'agit-il d'une
   livraison **physique sur clé USB** (pour les gros packs de formation) ?
   Si oui, ce n'est plus un pur e-commerce digital : il faut une adresse de livraison,
   un statut d'expédition, et un modèle de commande **hybride**. C'est une décision
   structurante, à trancher **avant** la migration du catalogue.

2. **Le champ `url`** — pointait-il vers un fichier hébergé publiquement (Google Drive,
   Mega, serveur) ? Si oui, ces liens sont probablement **déjà en circulation**.
   Les fichiers doivent être re-uploadés sur le disque privé, et les anciens liens coupés.

3. **Les commandes** — l'ancien admin a une page `gestion-commandes.php`, mais aucun
   fichier de données de commandes n'a été trouvé. Où sont stockées les ventes
   historiques ? Faut-il les migrer (pour la LTV client) ou repartir de zéro ?

4. **Devise** — XOF uniquement, ou ventes internationales ?

---

## ✅ CE QUE LA RÉÉCRITURE CORRIGE

| Problème legacy | Solution nouvelle |
|-----------------|-------------------|
| Secrets dans le code | `.env` + seeder qui lit l'environnement |
| Données en JSON | PostgreSQL relationnel, contraintes, index |
| Fichiers en URL publique | Disque privé + `download_grants` hachés, expirables |
| Pas d'historique de prix | Snapshot dans `order_items` + `product_price_history` |
| Aucune analytique | `events` partitionnée + rollups + attribution |
| Pas de CRM | `customer_profiles` + segments + LTV |
| Aucun test | Pest, pas de feature sans test |
| Admin bricolé | Filament + Policies + journal d'audit |
