# EXIGENCES_FONCTIONNELLES.md — Spécifications DigiTrove
# Priorités MoSCoW : Must = indispensable · Should = important · Could = souhaitable

---

## 👥 ACTEURS

| Acteur | Rôle |
|--------|------|
| Visiteur anonyme | Navigue, consulte le blog, ajoute au panier (pas de compte requis) |
| Client | Achète, télécharge, consulte ses commandes |
| Staff | Gère catalogue, commandes, contenu |
| Admin | Tout + finances, remboursements, révocations, journal d'audit |

---

## 1. FRONT-OFFICE — BOUTIQUE
| ID | Exigence | Priorité |
|----|----------|----------|
| EF-B1 | Catalogue avec filtres (type, catégorie) et recherche | Must |
| EF-B2 | Fiche produit : prix, aperçu, contenu inclus, avis vérifiés | Must |
| EF-B3 | Panier persistant (visiteur anonyme ET connecté) | Must |
| EF-B4 | Checkout **invité** (sans création de compte) | Must |
| EF-B5 | Coupons de réduction | Should |
| EF-B6 | Bundles / packs de produits | Should |
| EF-B7 | Upsell / produits liés après achat | Could |
| EF-B8 | Espace client : historique + re-téléchargement | Should |

## 2. FRONT-OFFICE — BLOG & SEO
| ID | Exigence | Priorité |
|----|----------|----------|
| EF-S1 | Blog natif (articles, tags, catégories) | Must |
| EF-S2 | Slug propre, meta title/description, canonical | Must |
| EF-S3 | sitemap.xml + robots.txt | Must |
| EF-S4 | Données structurées Schema.org (Product, Article) | Should |
| EF-S5 | Lien article ↔ produits (`article_product`) | Should |
| EF-S6 | Mesure de conversion par article | Could |

## 3. PAIEMENT
| ID | Exigence | Priorité |
|----|----------|----------|
| EF-P1 | Paiement via agrégateur (Mobile Money / carte) | Must |
| EF-P2 | **Confirmation serveur stricte** (signature + getStatus + montant) | Must |
| EF-P3 | Webhooks **idempotents** | Must |
| EF-P4 | Aucune livraison depuis le retour navigateur | Must |
| EF-P5 | Multi-agrégateurs (interface `PaymentGateway`) | Should |
| EF-P6 | Remboursements + révocation des accès | Should |
| EF-P7 | Multi-devises | Could |

## 4. LIVRAISON DIGITALE (cœur)
| ID | Exigence | Priorité |
|----|----------|----------|
| EF-L1 | Génération automatique des accès après paiement confirmé | Must |
| EF-L2 | Liens **uniques, expirables, à quota, révocables** | Must |
| EF-L3 | Token stocké **haché** ; fichiers sur disque **privé** | Must |
| EF-L4 | Double canal : affichage écran + e-mail | Must |
| EF-L5 | Gros fichiers (X-Accel-Redirect ou URL S3 pré-signée) | Must |
| EF-L6 | Journal des téléchargements + détection de partage | Should |
| EF-L7 | Licences logicielles (clé hachée, limite d'activation) | Could |
| EF-L8 | Ré-émission de liens par le support | Should |

## 5. BACK-OFFICE (Filament)
| ID | Exigence | Priorité |
|----|----------|----------|
| EF-A1 | CRUD catalogue (produits, fichiers, catégories, bundles) | Must |
| EF-A2 | Gestion des commandes + paiements | Must |
| EF-A3 | Modération des avis (verified_purchase) | Should |
| EF-A4 | Actions métier : rembourser, révoquer, réémettre | Should |
| EF-A5 | 2FA pour les comptes admin | Should |
| EF-A6 | Journal d'audit (qui a fait quoi) | Should |

## 6. CRM
| ID | Exigence | Priorité |
|----|----------|----------|
| EF-C1 | Profil client : LTV, nb commandes, première/dernière commande | Must |
| EF-C2 | Identité anonyme persistante (`visitors`) + stitching au login | Must |
| EF-C3 | Segmentation dynamique (définition JSONB) | Should |
| EF-C4 | Paniers abandonnés + relance | Should |
| EF-C5 | Export CSV des segments | Should |
| EF-C6 | Consentement marketing tracé | Must |

## 7. ANALYTIQUE & MARKETING
| ID | Exigence | Priorité |
|----|----------|----------|
| EF-M1 | Tracking d'événements (`events` partitionnée, écriture async) | Must |
| EF-M2 | Tunnel de conversion (view → cart → checkout → purchase) | Must |
| EF-M3 | Rollups quotidiens (le dashboard ne lit jamais `events`) | Must |
| EF-M4 | Attribution first touch + last touch | Should |
| EF-M5 | Campagnes + ROAS | Should |
| EF-M6 | Graphiques dans Filament | Should |

---

## EXIGENCES NON-FONCTIONNELLES
| ID | Exigence | Priorité |
|----|----------|----------|
| ENF-1 | Sécurité : tolérance zéro (secrets, SQL, XSS, CSRF, autorisation) | Must |
| ENF-2 | Argent en entiers (BIGINT), jamais FLOAT | Must |
| ENF-3 | Performance : LCP < 2,5 s ; dashboard < 1 s (rollups) | Should |
| ENF-4 | Scalabilité : `events` partitionnée, sans FK vers tables chaudes | Must |
| ENF-5 | Maintenabilité : services découplés, tests Pest, CI | Must |
| ENF-6 | UX : mobile-first, WCAG AA, thème sombre | Should |
| ENF-7 | RGPD : IP hachée, consentement, minimisation | Should |
| ENF-8 | Sauvegardes : base + disque privé (jamais un seul exemplaire) | Must |

---

## CRITÈRES D'ACCEPTATION (recette finale)
- Un visiteur anonyme peut acheter **sans créer de compte** et télécharger immédiatement.
- **Aucun accès n'est délivré** sans confirmation serveur du paiement.
- Un lien de téléchargement **expire**, respecte son **quota**, et se **révoque**.
- Aucun fichier digital n'est accessible par une URL directe.
- Le dashboard affiche le tunnel de conversion en moins d'une seconde.
- Un prix modifié aujourd'hui **ne change pas** les factures d'hier.
- Aucun secret n'est présent dans le dépôt git.
