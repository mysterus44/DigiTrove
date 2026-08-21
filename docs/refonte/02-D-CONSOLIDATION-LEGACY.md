# Plan de consolidation `legacy/` ↔ `DigiTrove-Ancien/`

**Phase 0 — Livrable D**

**Statut :** plan uniquement, aucune consolidation exécutée

**Autorité fonctionnelle imposée :** `DigiTrove-Ancien/`

## 1. État prouvé

| Source | État |
|---|---|
| `legacy/` | 91 fichiers suivis par le dépôt principal, plus `data/users.sqlite` local ignoré |
| `DigiTrove-Ancien/` hors `.git` | 90 fichiers fonctionnels |
| `.git` imbriqué | 112 fichiers, 55 299 258 octets |
| HEAD imbriqué | `1e41b923825895660b000411f9e6fbed7326ca54` (`DigiTrove V2`, 26 novembre 2025) |
| Remote imbriqué | même dépôt GitHub historique que DigiTrove |
| État imbriqué | branche `main` sale : modifications, suppressions et fichiers non suivis |
| Santé imbriquée | `git fsck --full` échoue : blob `dfdcb640a2244f239f243888ffdbf85472790750` manquant |

Le `.git` imbriqué ne doit donc être ni ajouté tel quel au dépôt principal, ni considéré comme une sauvegarde saine. Il ferait traiter le répertoire comme un dépôt incorporé et son historique est déjà incomplet.

## 2. Comparaison de contenu

La comparaison SHA-256 normalisée LF, hors SQLite verrouillé par Apache, donne :

- 91 fichiers comparés côté `legacy/` ;
- 89 côté `DigiTrove-Ancien/` ;
- **80 contenus identiques** ;
- 3 fichiers uniquement dans `legacy/` ;
- 1 fichier uniquement dans `DigiTrove-Ancien/` ;
- 8 contenus réellement différents.

### 2.1 Présents seulement dans `legacy/`

| Chemin | Intérêt | Décision proposée |
|---|---|---|
| `README.md` | avertit que le code legacy ne doit pas être exécuté | conserver comme garde-fou, réécrit si nécessaire |
| `images/logos/logo.svg` | cible exacte du favicon legacy manquant dans l'autorité | préserver après validation de provenance |
| `images/offres/tools.png` | cible exacte d'une carte d'univers | préserver après validation de provenance |

Ces deux médias expliquent des références cassées observées dans `DigiTrove-Ancien`. Leur absence de la copie autoritaire ne suffit pas à conclure qu'ils doivent disparaître.

### 2.2 Présent seulement dans `DigiTrove-Ancien/`

| Chemin | Constat | Décision proposée |
|---|---|---|
| `te.txt` | transcription de 5 830 lignes, 230 795 octets, contexte opérationnel et occurrences sensibles | ne jamais versionner ; quarantaine puis suppression approuvée |

### 2.3 Contenus différents

| Chemin | Delta normalisé | Sens du delta | Traitement proposé |
|---|---:|---|---|
| `admin/admin-blog.php` | +169 / -2 | portail admin séparé avec secret historique | ne pas consolider en clair ; conserver seulement une description d'audit |
| `data/setup_database.php` | +66 / -2 | création SQLite et compte admin par défaut | ne pas consolider ; données/mot de passe à exclure |
| `data/produits.json` | +1 / -1 | compteur logiciels 192 chaîne → 193 entier | adopter **193** après validation métier |
| `includes/header.php` | +2 / -2 | CDN Lucide changé vers un artefact `lucide-react` | autorité fonctionnelle à documenter, mais ne pas migrer ce CDN vers Laravel |
| `includes/navbar.php` | +1 / -1 | ordre À propos / Blog inversé | reprendre l'ordre observé dans la référence visuelle |
| `page/blog.php` | +8 / -8 | espaces et fin de fichier | aucune valeur métier |
| `page/boutique.php` | +148 / -589 | copie autoritaire plus simple ; `legacy/` contient une variante plus riche (tri, sélection, aperçu, état vide) | garder la copie autoritaire comme preuve historique ; archiver le diff riche comme recherche UX, jamais comme code à porter |
| `page/index.php` | +2 / -2 | espaces et fin de fichier | aucune valeur métier |

Le SQLite possède la même taille (16 384 octets) dans les deux arborescences, mais le fichier de `legacy/` était ouvert par Apache lors du calcul. La comparaison de données doit être faite sur une copie en lecture seule, hors serveur, sans afficher les lignes ni les hachages.

## 3. Cible recommandée

Une fois validée, la consolidation doit produire **une seule archive legacy suivie**, sous `legacy/`, avec ces règles :

1. `DigiTrove-Ancien/` fournit la référence fonctionnelle et les contenus ;
2. aucune donnée utilisateur, aucun secret et aucun `.git` imbriqué n'entre dans Git ;
3. les médias manquants mais prouvés dans `legacy/` sont conservés si leur provenance est acceptable ;
4. la variante riche de la boutique est conservée seulement comme diff/document de recherche, pas comme runtime ;
5. Laravel ne lit jamais `legacy/` en production ; seuls des importeurs explicites et idempotents peuvent lire des sources auditées ;
6. un manifeste de hachages et une note de provenance remplacent la dépendance à l'historique imbriqué corrompu.

Structure cible proposée :

```text
legacy/
├── README.md                    # archive, interdiction d'exécution
├── manifest.sha256              # fichiers non sensibles uniquement
├── data/
│   ├── produits.json
│   ├── avis.json
│   └── blog-articles.json
├── source/                      # PHP historique nécessaire à l'audit uniquement
├── media/                       # médias autorisés et dédupliqués
└── research/
    └── boutique-rich-diff.patch # variante UX, sans secret ni donnée personnelle
```

Cette structure est une proposition. Le déplacement physique n'est pas autorisé tant que KingKouda/MAESTRO n'a pas validé le plan et l'inventaire de suppression.

## 4. Séquence de consolidation proposée

### Gate D0 — gel de preuve

- arrêter temporairement Apache qui tient le SQLite, ou travailler sur une copie ;
- produire les manifestes chemin/taille/date/SHA-256 des deux arborescences ;
- exporter `git status`, les références et `git fsck` du dépôt imbriqué ;
- chiffrer l'archive contenant SQLite/transcription/secrets et la placer hors worktree ;
- faire approuver l'emplacement et la durée de rétention.

**Critère :** aucune donnée sensible ne figure dans le manifeste public, aucun fichier n'est encore déplacé.

### Gate D1 — récupération de l'historique imbriqué

- cloner le remote historique dans un répertoire temporaire neuf ;
- vérifier que le commit `1e41b92…` existe et que `git fsck` est vert ;
- comparer le worktree sale de `DigiTrove-Ancien` à ce clone sans réparer en place ;
- exporter un patch des seules différences non sensibles ;
- documenter les objets irrécupérables si le remote ne possède pas le blob manquant.

**Critère :** l'historique est préservé hors du dépôt principal ou son impossibilité est prouvée. Aucun `git reset`, `checkout` destructif ou `gc` n'est exécuté dans la copie utilisateur.

### Gate D2 — arbitrage des contenus

- valider les 5 produits, prix et compteurs ;
- valider les 19 articles et leurs slugs ;
- valider les 6 avis et le droit de les republier ;
- valider la provenance/licence de chaque média conservé ;
- décider du sort des pages légales obsolètes et des mentions Chariow/USB ;
- décider si la variante riche de boutique a une valeur de recherche.

**Critère :** matrice `keep / import / archive / delete` signée par le décideur.

### Gate D3 — construction d'une archive assainie

- copier seulement les fichiers validés vers une nouvelle arborescence temporaire ;
- supprimer de cette copie les secrets, SQLite, `te.txt`, `.git`, scripts de création de comptes et login à mot de passe en clair ;
- ajouter le README d'interdiction d'exécution et le manifeste ;
- scanner les secrets et vérifier les références médias ;
- comparer les compteurs et contenus attendus.

**Critère :** scan secret vert, inventaire exact, aucune URL de fichier digital, aucun compte.

### Gate D4 — remplacement contrôlé

- présenter le diff exact avant tout déplacement ;
- effectuer un commit dédié, sans `git add -A` ;
- ne jamais ajouter `DigiTrove-Ancien/.git` ;
- ne retirer `DigiTrove-Ancien/` et les doublons qu'après validation explicite du livrable E ;
- vérifier que Laravel et ses tests ne dépendent pas du PHP archivé.

**Critère :** dépôt principal propre, archive unique, tests verts, historique externe récupérable.

## 5. Tests et contrôles attendus

- manifeste 100 % reproductible ;
- vérification qu'aucun fichier suivi ne contient `.env`, clé, token, mot de passe ou base ;
- `git check-ignore` positif pour SQLite et secrets locaux ;
- import catalogue idempotent, avec 5 produits et prix validés ;
- import blog idempotent, 19 brouillons, zéro publication automatique ;
- tests de slugs et redirections SEO ;
- détection de médias référencés absents ;
- `php -d memory_limit=3G vendor/bin/pest`, Pint et `git diff --check`.

## 6. Rollback

Le rollback doit être un commit inverse de l'archive assainie. Les sources originales ne seront supprimées qu'après :

1. sauvegarde chiffrée vérifiée ;
2. clone historique sain ou déclaration d'irrécupérabilité ;
3. approbation humaine de l'inventaire E ;
4. validation des importeurs et des contenus.

Il est interdit de compter sur le `.git` imbriqué actuel comme rollback : `git fsck` prouve qu'il est incomplet.
