# Matrice des écarts — ancien site vs Laravel

**Phase 0 — Livrable C**

**Date :** 21 août 2026

Légende : **Conserver** = équivalent ou supérieur déjà utilisable ; **Partiel** = fondation présente, expérience incomplète ; **Manquant** = absent du Laravel visible ; **Remplacer** = comportement legacy à ne pas reproduire.

## 1. Front-office et contenu

| Fonction legacy | Laravel actuel | Verdict | Décision proposée |
|---|---|---|---|
| Héro vidéo et identité bleu/orange | héro éditorial très sobre vert/crème | Partiel | conserver la clarté Laravel, réintroduire personnalité, mouvement maîtrisé et marque |
| Navigation Accueil/Boutique/À propos/Blog | Catalogue/Blog/Panier/Avis/Accès | Partiel | architecture mobile + liens institutionnels + états actifs |
| Menu mobile et thème clair/sombre | liens masqués sans menu ; pas de thème | Manquant | reconstruire avec Alpine léger, clavier et cible 44 px |
| Six univers de produits | 4 catégories comptées mais non cliquables | Partiel | pages/filtres catégories fondés sur PostgreSQL |
| 5 produits | 5 produits publiés | Conserver | corriger les deux divergences de données |
| Cartes produits visuelles | cartes + prix actifs + prix barré + ventes | Conserver | enrichir hiérarchie, badges prouvés et CTA |
| Recherche/type/prix client-side | simple pagination | Manquant | filtres GET serveur, URLs partageables, état vide |
| CTA vers Chariow | fiche, panier et checkout internes | Supérieur | supprimer toute rupture Chariow après validation paiement |
| Pas de fiche produit | fiche interne sécurisée | Supérieur | enrichir contenu, bénéfices, livrables, FAQ, réassurance |
| Pas de panier | panier invité session/visitor | Supérieur | améliorer UX, quantités seulement si besoin validé |
| Pas de checkout interne | checkout e-mail, ordre, provider, statut | Supérieur fonctionnel / cassé visuellement | réparer le build CSS puis tester bout en bout |
| « ventes » = clics sortants | commandes/paiements autoritatifs existent | Supérieur | ne jamais migrer le faux chiffre comme revenu |
| 6 avis JSON | 3 extraits codés dans une ressource PHP | Partiel | schéma d'avis modérés et import des 6 après décision BDD |
| Formulaire d'avis | absent | Manquant | endpoint CSRF, rate limit, modération et preuve d'achat optionnelle |
| Contact factice | absent | Remplacer | vraie persistance/notification avant message de succès |
| Newsletter factice | simple ancre `Acces`, aucun formulaire | Remplacer | réutiliser le ledger de consentement CRM, retrait append-only |
| À propos | absent | Manquant | réécrire depuis le contenu legacy et la marque actuelle |
| FAQ | absente | Manquant | réécrire sans Chariow, avec paiement/livraison internes |
| CGV / confidentialité / livraison / retours | absentes | Manquant critique | pages versionnées et validées juridiquement avant mise en ligne |
| Réseaux sociaux et WhatsApp | absents | Manquant | footer administrable/configuré, pas d'URL en dur dans les vues |
| 404 Apache | 404 Laravel générique | Partiel | page DigiTrove accessible, sans oracle sur commandes/grants |

## 2. Blog et SEO

| Fonction legacy | Laravel actuel | Verdict | Décision proposée |
|---|---|---|---|
| 19 articles JSON publiés | schéma, vues et importeur existent ; base locale vide | Partiel | import en brouillons, revue humaine, publication progressive |
| 19 catégories distinctes | catégories normalisées | Partiel | consolider la taxonomie avant indexation |
| Recherche client-side | absente | Manquant | recherche serveur seulement si utile ; éviter une page JS lourde |
| Parseur Markdown artisanal | CommonMark durci via `ArticleContent` | Supérieur | conserver |
| Slugs acquis | importeur conserve puis normalise les slugs | Supérieur | tests de redirection si changement |
| Métadonnées minimales | canonical, OG, JSON-LD, sitemap, robots | Supérieur | conserver et compléter images/auteurs |
| Publicités fictives | absentes | Remplacer | ne rien afficher sans réseau/contrat réel |
| Liens externes non durcis | rendu serveur contrôlé | Supérieur | garder `rel` et politiques de contenu |

## 3. Identité et relation client

| Fonction legacy | Laravel actuel | Verdict | Décision proposée |
|---|---|---|---|
| Login/inscription compte public | admin Filament uniquement ; modèles identité présents | Partiel | arbitrer le portail client et la récupération d'achats avant UI |
| SQLite comptes | PostgreSQL `users`, profils, visitors | Supérieur | migration minimale après dédoublonnage, jamais copier le fichier |
| Rôles procéduraux | Policies et Gates | Supérieur | conserver, introduire permissions staff seulement si besoin |
| Limite login par session | protections Laravel à compléter pour public | Partiel | throttle IP+identité, logs sans secret, MFA admin à étudier |
| Contact et newsletter sans preuve | CRM contacts + consentements append-only | Fondation supérieure | connecter le front à ces autorités, sans créer un second modèle |
| Aucun stitching anonyme durable fiable | `visitors`, attribution et contacts CRM | Supérieur | instrumenter le storefront et expliquer le consentement |

## 4. Back-office

| Fonction legacy | Filament actuel | Verdict | Décision proposée |
|---|---|---|---|
| Dashboard clics/revenus estimés | analytics et rollups autoritatifs | Supérieur côté moteur | refondre la page d'accueil admin autour d'indicateurs vrais |
| CRUD produits JSON | ressource produits PostgreSQL | Supérieur | conserver, améliorer UX et validation de publication |
| Uploads web | ressource fichiers privés | Supérieur | distinguer couverture publique et livrable privé |
| CRUD avis | absent | Manquant | ajouter après schéma/revue |
| CRUD blog | articles/catégories/redirections | Supérieur | conserver, améliorer édition et aperçu |
| CRUD utilisateurs/rôles | aucune ressource utilisateur | Manquant | surface sécurisée avec Policy, audit et interdiction auto-désactivation |
| Commandes placeholder | domaine complet, aucune ressource | Partiel critique | vues read-first puis actions explicites et auditées |
| Paiements absents | domaine/adaptateurs complets, aucune ressource | Partiel critique | lecture, réconciliation et revue manuelle ; aucune mutation libre |
| Remboursements absents | schéma/services complets, aucune ressource | Partiel critique | workflows autorisés, idempotents, avec compensation livraison/affiliation |
| Livraison externe | grants/logs/jobs privés, aucune vue opérateur dédiée | Partiel | diagnostic et révocation contrôlés |
| CRM absent | contacts, segments, exports Filament | Supérieur | conserver et intégrer au dashboard |
| Affiliation absente | cycle, programme, commissions et payout | Supérieur | conserver ; gate bancaire séparé avant tout versement réel |
| Deux logins admin | un login Filament | Supérieur | supprimer les alternatives legacy après consolidation |

## 5. Architecture, sécurité et exploitation

| Ancien | Laravel | Verdict |
|---|---|---|
| PHP procédural | Laravel OOP, contrôleurs/services/events/jobs | Supérieur |
| JSON + SQLite | PostgreSQL 16 avec contraintes et transactions | Supérieur |
| secrets committés | environnement + gardes de configuration | Supérieur, à protéger du dossier `_to_delete` |
| suppressions GET sans CSRF | verbes HTTP + CSRF + Policies | Supérieur |
| uploads publics | disques privés + contrôleurs | Supérieur |
| paiement présumé au clic | webhook signé + contre-appel + idempotence | Supérieur |
| dépendances CDN runtime | Vite local | Supérieur en principe ; artefact construit actuellement périmé |
| aucun test | 208 fichiers Pest et contrats PostgreSQL | Supérieur |
| aucun CI fiable | pipeline et conventions documentés | Supérieur |

## 6. Synthèse décisionnelle

Le Laravel actuel doit rester la seule application. L'ancien site n'apporte aucune architecture à réutiliser, mais il apporte ce qui manque le plus au produit visible :

- la voix et la personnalité de marque ;
- l'étendue du contenu public ;
- les parcours de découverte ;
- la preuve sociale ;
- les pages institutionnelles ;
- un benchmark concret du niveau visuel attendu.

Les trois écarts bloquants avant le premier lot visuel sont :

1. reconstruire et fiabiliser l'artefact Vite afin que panier et checkout rendent le CSS source ;
2. arbitrer/réaligner les données importées avec `DigiTrove-Ancien` ;
3. établir un design system public responsive, avant de refaire page par page.
