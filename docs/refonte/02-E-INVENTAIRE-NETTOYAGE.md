# Inventaire des candidats au nettoyage

**Phase 0 — Livrable E**

**Date :** 21 août 2026

**Statut :** inventaire uniquement — **rien n'a été supprimé, déplacé ou désindexé**

## 1. Règles de décision

| Confiance | Signification |
|---|---|
| Certaine | résidu non référencé ou secret explicite ; suppression reste soumise à approbation |
| Probable | doublon/expérimentation prouvé, mais une valeur d'archive est possible |
| Incertaine | aucune référence littérale trouvée, mais intention future ou référence dynamique possible |

Le « risque » mesure le dommage si la suppression était erronée, pas la probabilité qu'elle soit utile.

## 2. Candidats prioritaires

| Chemin | Taille | Modifié | Preuve de non-référence / nature | Confiance | Risque et prérequis |
|---|---:|---|---|---|---|
| `_to_delete/_diag_redis.php` | 741 o | 2026-08-20 21:55 | non suivi, aucun historique Git, aucun appel applicatif | Certaine | faible ; valider que le diagnostic n'est plus requis |
| `_to_delete/_genius_webhook_create.php_CONTAINS_REAL_SANDBOX_KEYS` | 2 319 o | 2026-08-21 01:05 | nom explicite, non suivi, aucun historique Git | Certaine | **critique sécurité** : révoquer/faire tourner les clés avant purge sécurisée |
| `_to_delete/_genius_webhook_setup.php` | 3 931 o | 2026-08-21 01:07 | non suivi, aucun appel applicatif | Certaine | élevé si contient encore un secret ; même gate de rotation |
| `_to_delete/hot` | 21 o | 2026-08-14 01:53 | ancien marqueur Vite déplacé hors `public/`, non suivi | Certaine | faible |
| `DigiTrove-Ancien/te.txt` | 230 795 o | 2026-08-21 00:50 | 5 830 lignes de transcription opérationnelle, aucun appel source, occurrences de secret/token | Certaine | élevé : archiver chiffré seulement si valeur légale/opérationnelle, puis purge |
| `DigiTrove-Ancien/.git/` | 55 299 258 o | 2026-08-21 02:30 | métadonnées imbriquées, jamais runtime, `git fsck` signale un blob manquant | Probable | **très élevé** : récupérer l'historique par clone sain et patch avant retrait |
| `DigiTrove-Ancien/data/users.sqlite` | 16 384 o | 2026-08-15 20:30 | référencé seulement par le runtime legacy et son setup ; interdit dans le dépôt | Probable après migration | **critique données personnelles** : copie chiffrée, dédoublonnage/migration validés, rétention décidée |
| `DigiTrove-Ancien/data/article.json` | 562 o | 2026-08-15 20:30 | aucune référence ; structure anglaise incompatible avec les 19 articles | Probable | moyen : vérifier qu'il n'est pas un brouillon éditorial attendu |
| `DigiTrove-Ancien/page/test.php` | 11 169 o | 2026-08-15 20:30 | aucune navigation/référence ; variante du blog | Probable | faible : conserver une capture/diff si utile |
| `PROMPT_CODEX_REFONTE.md` | 29 505 o | 2026-08-21 02:02 | mission locale non suivie, référencée uniquement comme instruction de travail | Incertaine pendant la mission | élevé tant que la Phase 0 n'est pas acceptée ; archiver ensuite si l'attachement fait foi |

**Total `_to_delete/` :** 4 fichiers, 7 012 octets. `git ls-files` et `git log --all` ne retournent aucune entrée pour ce dossier : les clés réelles n'ont pas été trouvées dans l'historique du dépôt principal. Cela ne dispense pas de les révoquer.

## 3. Doublons binaires exacts

Les groupes suivants ont le même SHA-256. Le gain maximal théorique en gardant une copie par groupe est **15 060 363 octets**.

| Copie à garder à arbitrer | Doublons exacts | Gain théorique | Risque |
|---|---|---:|---|
| `images/products/pack_de_formation_dev_fullstack.png` | `uploads/6921f01235942-pack_de_formation_dev_fullstack.png` | 1 384 770 o | moyen, l'URL JSON utilise actuellement le chemin `uploads` |
| une copie `687ab1…` ou `687b7…` | deux uploads logiciels de 2 228 073 o | 2 228 073 o | faible après preuve de non-référence |
| `images/products/pack-livres.png` | `uploads/pack-livres.png` | 1 199 945 o | moyen, le JSON utilise l'upload |
| `images/products/pack_Logiciels_Premium.png` | trois uploads identiques | 3 351 045 o | moyen, un des uploads est référencé |
| `images/products/pack_e-com_et_marketing.png` | `uploads/pack_e-com_et_marketing.png` | 1 100 307 o | faible si l'offre reste hors catalogue |
| `images/products/pack_ms_office.png` | trois uploads identiques | 4 435 458 o | moyen, un upload est référencé |
| une copie `Office Intégral_simple_compose.png` | `uploads/687c411…` + nom simple | 1 360 765 o | faible après arbitrage |

Il ne faut pas supprimer le chemin référencé puis compter sur le hash identique : l'ancien PHP stocke les chemins, pas les identités de contenu. La consolidation doit d'abord réécrire les références vers le chemin canonique, vérifier l'affichage, puis seulement proposer la suppression.

## 4. Médias sans référence littérale

La recherche des noms de fichiers dans tous les PHP/JSON/Markdown/`.htaccess` hors `.git` trouve **23 médias sans référence littérale**, soit **31 967 484 octets**. Ce contrôle peut manquer une référence générée dynamiquement ; ils sont donc candidats, pas déchets certains.

| Chemin | Taille | Modifié | Preuve | Confiance / risque |
|---|---:|---|---|---|
| `images/equipe/ceo2.png` | 5 575 027 o | 2026-08-15 | le À propos utilise `ceo3.png` | Probable / moyen |
| `images/equipe/client2.png` | 1 398 235 o | 2026-08-15 | le À propos utilise `client.png` | Probable / moyen |
| `images/logos/Digitrove-2.0-.png` | 43 800 o | 2026-08-15 | nom absent des sources | Incertaine / élevé (marque) |
| `images/logos/digitrove-2.0-bgR.png` | 148 471 o | 2026-08-15 | login demande à tort la version `.svg` | Incertaine / élevé ; peut être l'asset à réparer |
| `images/logos/digitrove-2.0.png` | 150 324 o | 2026-08-15 | nom absent des sources legacy | Incertaine / élevé (marque) |
| `images/logos/digitrove-2.0.svg` | 1 011 973 o | 2026-08-15 | nom absent | Incertaine / élevé (source vectorielle) |
| `images/logos/icon.svg` | 88 754 o | 2026-08-15 | non suivi dans le dépôt imbriqué, nom absent | Incertaine / élevé |
| `images/products/pack_ Formation_Marketing_Digital.png` | 1 113 934 o | 2026-08-15 | aucune offre actuelle ne le nomme | Incertaine / moyen |
| `images/products/Pack_Design_Montage.png` | 1 222 195 o | 2026-08-15 | aucune offre actuelle ne le nomme | Incertaine / moyen |
| `images/products/pack_e-com_et_marketing.png` | 1 100 307 o | 2026-08-15 | aucune offre actuelle ne le nomme | Incertaine / moyen |
| `images/products/pack_hacking.png` | 973 671 o | 2026-08-15 | aucune offre actuelle ne le nomme | Incertaine / moyen |
| `uploads/687a6352881c2-Office Intégral_simple_compose.png` | 2 388 802 o | 2026-08-15 | nom absent des données/sources | Probable / faible |
| `uploads/687a6c69887da-pack_hacking.png` | 2 083 061 o | 2026-08-15 | nom absent des données/sources | Probable / faible |
| `uploads/687ab1fe50eb9-pack_Logiciels_Premium.png` | 2 228 073 o | 2026-08-15 | nom absent, doublon exact d'un autre upload | Probable / faible |
| `uploads/687b7c28d4402-pack_Logiciels_Premium.png` | 2 228 073 o | 2026-08-15 | nom absent, doublon exact | Probable / faible |
| `uploads/687c364b56b39-pack_ms_office.png` | 1 478 486 o | 2026-08-15 | nom absent, doublon exact | Probable / faible |
| `uploads/687c36e36b67e-pack_ms_office.png` | 1 478 486 o | 2026-08-15 | nom absent, doublon exact | Probable / faible |
| `uploads/687c411d3811e-Office Intégral_simple_compose.png` | 1 360 765 o | 2026-08-15 | nom absent, doublon exact | Probable / faible |
| `uploads/6883f94b0d571-pack_Logiciels_Premium.png` | 1 117 015 o | 2026-08-15 | nom absent, doublon exact | Probable / faible |
| `uploads/688405809229d-pack_Logiciels_Premium.png` | 1 117 015 o | 2026-08-15 | nom absent, doublon exact | Probable / faible |
| `uploads/Office Intégral_simple_compose.png` | 1 360 765 o | 2026-08-15 | nom absent, doublon exact | Probable / faible |
| `uploads/pack_e-com_et_marketing.png` | 1 100 307 o | 2026-08-15 | nom absent, doublon exact | Probable / faible |
| `uploads/packlivres.png` | 1 199 945 o | 2026-08-15 | JSON utilise `pack-livres.png` avec tiret | Probable / faible |

## 5. Références cassées à corriger, pas à supprimer

| Référence | État | Décision proposée |
|---|---|---|
| `images/logos/logo.svg` | absent de l'autorité, présent dans `legacy/` | restaurer ou remplacer après provenance |
| `images/offres/tools.png` | absent de l'autorité, présent dans `legacy/` | restaurer ou retirer l'univers après décision contenu |
| `images/offres/abonnement.jpg` | seul `abonnement.png` existe | corriger l'extension ou choisir un fond dédié |
| `images/logos/digitrove-2.0-bgR.svg` | seul le PNG existe | corriger le login ou produire un SVG validé |
| `page/retours.php` depuis CGV | route/fichier absent | pointer vers `return-refund.php` dans l'archive uniquement ; Laravel aura sa propre route |

## 6. Ordre de nettoyage proposé

1. révoquer les clés sandbox exposées et confirmer leur statut fournisseur ;
2. sauvegarder chiffré SQLite et `te.txt`, avec durée de rétention ;
3. récupérer l'historique du dépôt imbriqué depuis un clone sain ;
4. valider la matrice médias/contenus ;
5. consolider les chemins canoniques et vérifier toutes les références ;
6. présenter un diff de suppression exact ;
7. supprimer seulement après approbation explicite ;
8. scanner les secrets, lancer Pest/Pint et vérifier Git.

## 7. Ce qui ne doit pas être supprimé implicitement

- `legacy/` ou `DigiTrove-Ancien/` dans leur ensemble ;
- les 19 articles, 6 avis ou 5 produits ;
- un média de marque ou une image non référencée sans arbitrage éditorial ;
- `users.sqlite` avant preuve de migration/rétention ;
- le `.git` imbriqué avant récupération de l'historique ;
- le prompt de mission avant acceptation formelle de la Phase 0.
