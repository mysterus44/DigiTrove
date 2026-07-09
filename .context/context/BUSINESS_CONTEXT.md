# BUSINESS_CONTEXT.md — Vision, modèle et marché

---

## 🎯 PROPOSITION DE VALEUR

DigiTrove vend des **produits digitaux** (logiciels, vastes packs de formation,
e-books) via sa **propre infrastructure**, sans intermédiaire.

**Pourquoi l'indépendance ?**
- Aucune commission de plateforme (Gumroad ~10 %, marketplaces jusqu'à 30 %)
- Contrôle total de la donnée client (impossible sur une marketplace)
- Liberté sur le pricing, les promotions, le marketing
- Aucun risque de fermeture de compte arbitraire

**Le coût de cette indépendance** : il faut construire la confiance et le trafic
soi-même. C'est là que le blog SEO et le CRM deviennent vitaux.

---

## 💰 SOURCES DE REVENUS

1. **Vente à l'unité** de produits digitaux
2. **Bundles / packs** (panier moyen plus élevé, marge identique)
3. **Upsell** post-achat (produit complémentaire recommandé)
4. **Licences** (pour les logiciels : activation limitée, renouvellement possible)

Le produit digital a une caractéristique unique : **le coût marginal est nul**.
Vendre 1 000 exemplaires ne coûte pas plus cher que d'en vendre 1. Donc :
- Le levier n°1 est le **trafic** (SEO, campagnes)
- Le levier n°2 est le **taux de conversion**
- Le levier n°3 est le **panier moyen** (bundles, upsell)
- Le stock n'existe pas. Ne construis jamais de gestion de stock.

---

## 📊 LES MÉTRIQUES QUI COMPTENT

```
Trafic          : visiteurs uniques, sources, coût par visite
Conversion      : product_view → add_to_cart → begin_checkout → purchase
Panier moyen    : avg_order_minor
LTV             : lifetime_value_minor par client
ROAS            : revenu par campagne / budget de la campagne
Rétention       : % de clients avec orders_count >= 2
Abandon panier  : carts abandonnés / carts créés
Taux de fraude  : tentatives de paiement falsifié, partages de liens détectés
```

Le tunnel de conversion est **la** métrique quotidienne. Chaque étape qui perd
plus de 70 % des gens est un chantier prioritaire.

---

## ⚠️ LES RISQUES SPÉCIFIQUES AU DIGITAL

| Risque | Parade |
|--------|--------|
| Piratage / partage des fichiers | Grants expirables, quota, détection multi-IP, révocation |
| Rétrofacturation (chargeback) | Journal d'audit complet, preuve de livraison, CGV claires |
| Fraude au paiement | Confirmation serveur stricte, contre-appel `getStatus` |
| Fichier corrompu livré | `checksum_sha256` vérifié à l'upload |
| Perte du fichier source | Sauvegarde du disque privé, jamais un seul exemplaire |
| Dépendance à un agrégateur | Interface `PaymentGateway`, plusieurs providers possibles |

---

## 🧭 CE QUE JE CHALLENGE DANS LA VISION

**« Big Data » est prématuré.** Avec quelques milliers de visiteurs par mois,
PostgreSQL bien indexé fait tout. La table `events` partitionnée te porte jusqu'à
plusieurs dizaines de millions de lignes sans effort. Ne construis pas un data
warehouse avant d'avoir le trafic qui le justifie — c'est du temps volé au produit.

Le schéma est **conçu pour** cette montée en charge (partitionnement, rollups,
absence de FK sur `events`), mais on ne déploie ClickHouse que le jour où PostgreSQL
souffre réellement. Ce jour peut ne jamais venir.

**Le vrai goulot d'étranglement, ce n'est pas la base : c'est le trafic.**
Un catalogue parfait avec 50 visiteurs par jour ne vend rien. Le blog SEO (P7) et
les campagnes (P6) méritent autant d'attention que le cœur technique.
