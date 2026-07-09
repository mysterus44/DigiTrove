# PRD_02_CATALOGUE.md — P2 : Catalogue produits

```
Lis d'abord :
- .context/architecture/SCHEMA_BDD.md  (bloc « CATALOGUE »)
- .context/skills/FILAMENT_ADMIN.md
- .context/skills/SECURITE_TELECHARGEMENT.md  (section « fichiers sur disque privé »)
- .context/checklists/PRE_CODE_CHECKLIST.md

═══════════════════════════════════════════════════════

MISSION : Catalogue — products, product_files, categories, bundles, reviews

CONTEXTE : distinction cruciale : le **produit** est une fiche marketing ; le
**fichier** est le livrable. Un vaste pack de formation = 1 produit, 40 fichiers.
Les fichiers ne doivent JAMAIS être accessibles par une URL publique.

OBJECTIF :
- Tables : products, product_files, product_price_history, categories,
  product_category, product_bundles, reviews
- Upload des fichiers sur le disque **private** (jamais public/)
- checksum_sha256 calculé et stocké à l'upload (intégrité)
- Historisation des changements de prix
- Bundles (un produit peut contenir d'autres produits)
- Avis avec modération + `verified_purchase`
- Filament : ProductResource, CategoryResource, ReviewResource

CRITÈRES D'ACCEPTATION :
- [ ] price_minor et compare_at_price_minor sont des BIGINT (jamais FLOAT)
- [ ] Un fichier uploadé n'est PAS accessible par URL directe (test de sécurité)
- [ ] storage_path n'apparaît jamais dans une réponse HTTP (HTML, JSON, erreur)
- [ ] Un changement de prix crée une ligne dans product_price_history
- [ ] Un bundle ne peut pas se contenir lui-même (contrainte CHECK)
- [ ] rating_avg / rating_count / sales_count sont des rollups, pas des COUNT() live
- [ ] Filament : FileUpload forcé sur ->disk('private')
- [ ] Policies sur ProductResource (viewAny/create/update/delete)

EDGE CASES :
- [ ] Upload interrompu → pas de product_file orphelin
- [ ] Fichier > 2 Go → géré ou rejeté proprement
- [ ] Produit archivé mais déjà acheté → les grants existants restent valides
- [ ] Avis sans achat → verified_purchase = false, affiché différemment

CONTRAINTES :
- Sécurité : disque privé, checksum vérifié, policies, nom de fichier randomisé sur disque
- Performance : pas de N+1 sur les listes (with()), pagination 25
- Argent : entiers uniquement

LIVRABLES :
- Migrations + modèles + enums (ProductType, ProductStatus, ReviewStatus)
- CatalogService (création produit + fichiers + checksum)
- Filament Resources + Policies
- Tests Pest : URL directe refusée (404) · checksum calculé · historique de prix ·
  bundle auto-inclusion refusée · argent en entiers
- Trackers mis à jour (PROCHAINE TÂCHE = PRD_03_COMMERCE)

PROCESSUS : plan d'abord, validation, puis implémentation.
```
