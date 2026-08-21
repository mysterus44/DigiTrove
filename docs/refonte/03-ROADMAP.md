# Feuille de route de la refonte DigiTrove

**Phase 0 — Livrable F**

**Date :** 21 août 2026

**Règle :** un lot = une PR vers `p0-foundations-laravel13`

**Statut :** proposition à valider ; aucun lot de Phase 1 ou 2 n'est commencé

## 1. Portes de validation

```text
Phase 0 : audits A–F
        ↓ accord explicite KingKouda / MAESTRO
Phase 1 : front visiteur fidèle au legacy, mieux exécuté
        ↓ validation visuelle explicite, preuves aux 6 largeurs
Phase 2 : audit Chariow puis refonte Filament/admin
```

- Aucun lot de Phase 1 avant acceptation des six livrables.
- Aucun audit ni développement Chariow avant validation visuelle de la Phase 1.
- Toute migration, nouvelle dépendance, suppression, manipulation de secret ou divergence visuelle volontaire repasse par une décision humaine.
- Le code Laravel métier existant est conservé ; la refonte porte d'abord sur les surfaces et les contenus.

## 2. Contrats transversaux

Chaque PR doit fournir :

1. analyse du besoin et des risques ;
2. proposition BDD, même si la conclusion est « aucune migration » ;
3. architecture de fichiers/services ;
4. tests Pest avant livraison ;
5. revue sécurité, UX et accessibilité ;
6. captures avant/après à 320, 375, 414, 768, 1024 et 1440 px pour tout écran modifié ;
7. `php -d memory_limit=3G vendor/bin/pest`, Pint, build Vite et `git diff --check` verts ;
8. mise à jour de `PROGRESS_TRACKER.md`, `HANDOFF.md` et décisions si nécessaire.

Seuils de sortie Phase 1 : Lighthouse mobile Performance ≥ 85, Accessibilité ≥ 95, SEO ≥ 95 ; LCP < 2,5 s ; CLS < 0,1 ; contraste AA ; zéro débordement horizontal ; cibles tactiles ≥ 44 × 44 px ; texte mobile ≥ 14 px.

## 3. Phase 0 — documentation

### Lot P0-R — Audit et roadmap

**Périmètre :** les six documents `docs/refonte/00…03`, captures legacy/Laravel, aucune logique applicative.

**Critères d'acceptation :**

- les 202 entrées sont expliquées et les 90 fichiers fonctionnels couverts ;
- les 19 articles ont été rendus ;
- les écrans significatifs existent en desktop/mobile ;
- routes, migrations, modèles, Filament, tests et intégrations Laravel sont cartographiés ;
- consolidation et nettoyage restent des plans sans exécution ;
- la baseline Pest finale est inscrite dans le livrable B.

**Dépendance :** aucune. **Sortie :** gate humaine Phase 0.

## 4. Phase 1 — front visiteur/client

L'ancien site reste la référence d'information, de vocabulaire et de marque. Tout écart visible doit être présenté avant/après et approuvé.

### Lot V0 — Runtime front fiable

**Périmètre :** reconstruire l'artefact Vite, supprimer la divergence CSS source/build, reproduire puis fermer la régression d'origine du formulaire checkout, vérifier les assets publics.

**BDD :** aucune migration.

**Architecture :** build Vite reproductible ; action checkout relative ou génération d'URL prouvée sous port/proxy ; test de garde du manifest ; documentation locale.

**Critères vérifiables :**

- les sélecteurs panier/checkout existent dans le CSS servi ;
- le checkout est mis en page à 6 largeurs ;
- page et action de formulaire ont la même origine sous `localhost:8000` et proxy simulé ;
- CSP reste `form-action 'self'` et n'est pas affaiblie ;
- aucun secret ni appel fournisseur.

**Dépendance :** gate Phase 0. **PR :** correctif technique seulement.

### Lot V1 — Design system et coque publique fidèle

**Périmètre :** tokens bleu/orange/nuit, typographie, espacements, boutons, champs, cartes, header, menu mobile, thème, footer, focus, états communs et 404.

**BDD :** aucune migration. Liens sociaux/support via configuration non secrète.

**Critères vérifiables :**

- identité immédiatement reconnaissable par rapport au legacy ;
- menu complet clavier/tactile, focus piégé nulle part, fermeture Escape ;
- footer légal/social cohérent ;
- un seul H1, landmarks et skip link ;
- AA mesuré, cibles 44 px, zéro débordement aux 6 largeurs ;
- aucun CDN de rendu runtime.

**Dépendances :** V0. **Écart soumis avant code :** toute modification significative du logo, de la palette ou de la navigation legacy.

### Lot V2 — Accueil fidèle et orienté conversion

**Périmètre :** héro reconnaissable, univers, meilleures ventes, preuve sociale, réassurance, contact d'orientation et CTA ; conserver le ton « réussite numérique » et la proximité africaine.

**BDD :** aucune migration dans ce lot. Les avis restent en lecture depuis une source auditée jusqu'au lot V6.

**Critères vérifiables :**

- même structure d'information que l'ancien, sans formulaire fictif ;
- LCP optimisé, dimensions images/vidéo fixées, aucune superposition ;
- CTA catalogue visible sans scroll à 375 et 1440 px ;
- métriques affichées nommées honnêtement (« historique legacy » si non financières) ;
- captures avant/après aux 6 largeurs.

**Dépendances :** V1.

### Lot V3 — Catalogue consultable

**Périmètre :** recherche, catégories, prix maximum, tri utile, pagination, états vide/erreur, URLs partageables. Les filtres utilisent des paramètres GET et des requêtes Eloquent allowlistées.

**BDD :** aucune migration ; index actuels à mesurer. Toute demande d'index additionnel est un gate BDD séparé.

**Architecture :** Form Request ou objet de filtre typé ; query service ; vues Blade/Alpine uniquement pour amélioration progressive ; eager loading et pagination.

**Critères vérifiables :**

- parité recherche/type/prix avec le legacy ;
- filtrage utilisable sans JavaScript ;
- aucun SQL libre ni colonne transmise par le navigateur ;
- état vide explicite avec remise à zéro ;
- temps et nombre de requêtes mesurés ;
- produits brouillons/non tarifés toujours 404/invisibles.

**Dépendances :** V1, contenu catalogue arbitré.

### Lot V4 — Schéma des pages de vente et avis

Lot BDD préalable au template best-seller. Il doit être présenté et approuvé avant migration.

**Proposition de données :**

| Table | Rôle | Contraintes principales |
|---|---|---|
| `product_sales_sections` | blocs problème, bénéfices, contenu, audience, réassurance | FK produit, `kind` allowlisté, position unique par produit/type, statut actif |
| `product_faqs` | objections/réponses par produit | FK produit, question/réponse non vides, position, actif |
| `reviews` | avis site ou produit | UUID public, `product_id` nullable, auteur snapshot, note SMALLINT 1–5, corps, statut de modération, source, dates |
| éventuel `review_proofs` | preuve d'achat sans exposer la commande | FK avis/commande ou digest, accès admin uniquement |

Les 6 avis legacy n'ont pas de lien produit prouvé. Ils sont importés comme avis de site, jamais utilisés dans `AggregateRating` produit. Seuls des avis publiés et réellement rattachés à un produit peuvent alimenter cette donnée structurée.

**Architecture :** migrations réversibles, modèles, Policies, import idempotent, aucun formulaire public encore.

**Critères vérifiables :** contraintes PostgreSQL, import 6/6 sans doublon, rollback avec données, aucune publication automatique, inventaires de phase rescopés explicitement.

**Dépendances :** validation humaine du schéma et de la réutilisation des témoignages.

### Lot V5 — Template produit « best-seller »

**Périmètre :** un template data-driven unique : accroche, problème/promesse, contenu, preuve sociale, réassurance, FAQ, au moins 3 CTA, CTA mobile collant, SEO Product/Offer et AggregateRating seulement si autorisé.

**BDD :** utilise V4, sans migration additionnelle.

**Critères vérifiables :**

- les 5 produits rendent avec le même template ;
- un bloc/avis/FAQ absent se replie sans espace vide ;
- CTA au-dessus de la ligne de flottaison et deux rappels ;
- le CTA collant ne masque ni focus ni contenu ;
- prix vient de `product_prices`, ventes de l'autorité choisie, jamais d'un float ;
- JSON-LD validable et sans note fictive ;
- tests Pest visibilité/prix/SEO + captures 30 cas (5 produits × 6 largeurs) ou matrice équivalente approuvée.

**Dépendances :** V3, V4.

### Lot V6 — Avis publics et modération

**Périmètre :** affichage des 6 avis importés, dépôt d'avis, états succès/erreur, modération Filament minimale.

**BDD :** schéma V4 déjà approuvé.

**Architecture :** Form Request, Policy, service, throttle, CSRF, texte échappé, publication admin, aucune note modifiable après publication sans audit.

**Critères vérifiables :** soumission ne publie jamais immédiatement ; validation note 1–5 ; réponse uniforme anti-spam ; accessibilité erreurs ; aucun double envoi ; moyenne produit seulement sur avis éligibles.

**Dépendances :** V4, V5.

### Lot V7 — Panier et checkout de conversion

**Périmètre :** refonte fidèle des cartes/récapitulatif, retrait, indisponibilité, e-mail, réassurance paiement/livraison, états de statut. Aucun changement aux autorités financières.

**BDD :** aucune migration.

**Critères vérifiables :**

- panier session/visitor isolé et non énumérable ;
- prix recalculé serveur ; aucun prix client accepté ;
- erreurs rattachées au champ et annoncées ;
- zéro double soumission visible ;
- retour navigateur ne confirme rien ;
- checkout CSP fonctionnel aux 6 largeurs ;
- tests catalogue → produit → panier → checkout simulé → statut.

**Dépendances :** V0, V1, V5.

### Lot V8 — Blog et contenu SEO

**Périmètre :** importer les 19 articles en brouillons, consolider les catégories, relire le rendu, publier par décision humaine, ajouter recherche seulement si validée, préserver slugs et redirections.

**BDD :** schéma existant ; aucune migration prévue. La taxonomie est une décision de données.

**Critères vérifiables :** 19 brouillons, 0 doublon au rejeu, 0 publication automatique, Markdown propre sans `#` parasite, images/alt/canonical/OG, maillage interne, sitemap uniquement pour publiés.

**Dépendances :** V1, arbitrage éditorial des catégories.

### Lot V9 — Pages institutionnelles

**Périmètre :** À propos, FAQ, CGV, confidentialité, livraison, retours/remboursements, support. Les textes Chariow/USB obsolètes sont réécrits et soumis pour validation.

**BDD :** aucune migration initiale ; contenu versionné dans le dépôt ou modèle éditorial existant à arbitrer. Un CMS générique n'est pas ajouté implicitement.

**Critères vérifiables :** toutes les routes footer répondent 200, titres non dupliqués, aucun lien cassé, contenu cohérent avec paiement/livraison internes, accessibilité accordéons et impression correcte.

**Dépendances :** V1, validation juridique/métier des textes.

### Lot V10 — Contact et consentement marketing

**Périmètre :** remplacer le faux succès contact/newsletter par des autorités réelles.

**Proposition BDD :**

- `contact_messages` : UUID public, nom, e-mail, téléphone optionnel, message, statut fermé, timestamps, métadonnées minimales hachées si anti-abus ;
- newsletter : **réutiliser** `crm_contacts` et `crm_marketing_consent_events` append-only, ne pas créer une seconde table de consentement.

**Architecture :** deux Form Requests séparées, services séparés, rate limits, jobs de notification transactionnelle ; aucun envoi marketing dans ce lot.

**Critères vérifiables :** succès seulement après persistance, retrait du consentement possible, preuve source/version/finalité, aucune fusion de contact hasardeuse, pas d'IP brute, anti-spam uniforme.

**Dépendances :** validation migration contact et politique de rétention.

### Lot V11 — Consentement analytics et instrumentation

**Périmètre :** UI de consentement versionné, page views, vues produits, ajout panier, checkout et achat confirmé alimentant la pipeline existante.

**BDD :** aucune migration prévue ; utiliser les événements allowlistés existants. Tout nouvel événement exige rescope d'inventaire.

**Critères vérifiables :** aucun événement avant consentement ; retrait effectif ; same-origin ; propriétés bornées ; aucun PII/payload fournisseur ; dashboard lit les rollups, jamais `events` directement.

**Dépendances :** V1, V3, V7.

### Lot V12 — GeniusPay sandbox de bout en bout

**Périmètre :** initiation réelle sandbox → redirection → retour non autoritatif → webhook signé → contre-appel → commande confirmée → livraison privée. Aucun passage live.

**BDD :** aucune migration prévue. Toute correction de schéma est un gate distinct.

**Sécurité :** clés seulement dans `.env`, webhook exposé via mécanisme approuvé, aucune clé dans commande/log/capture, montant XOF contrôlé, commande de test explicite, fichier non sensible.

**Critères vérifiables :** identifiants fournisseur et order/payment cohérents, webhook idempotent, statut paid seulement après contre-appel, e-mail/lien/grant valides, téléchargement journalisé, rejeu sans seconde livraison.

**Dépendances :** V7, configuration sandbox fournie par Mohammed, rotation préalable des clés exposées dans `_to_delete`.

### Lot V13 — QA finale et gate visuelle

**Périmètre :** régression complète, axe/Lighthouse, clavier, lecteurs d'écran ciblés, performance, conflits d'objets, preuves avant/après.

**Critères vérifiables :** seuils transversaux atteints, aucun échec Pest/Pint/build, parcours mobile complet, rapport des écarts volontaires, captures aux 6 largeurs pour chaque écran.

**Dépendances :** V0 à V12. **Sortie :** validation visuelle explicite de Mohammed.

## 5. Phase 2 — admin Filament inspiré de Chariow

### Lot A0 — Audit Chariow

Navigation réelle du compte fourni, sans mutation non nécessaire. Livrable `docs/refonte/04-AUDIT-CHARIOW.md` : architecture, écrans, indicateurs, densité, états, mobile, parcours. Aucun code.

**Dépendance :** gate visuelle Phase 1.

### Lot A1 — Architecture admin et design system Filament

Coque, navigation, dashboard d'accueil, responsive, permissions, états vides/erreur. Aucun chiffre fictif. **BDD :** aucune migration.

**Acceptation :** parité ergonomique Chariow explicitement mappée, sans copier marque/textes/assets ; admin mobile utilisable ; Policies serveur.

### Lot A2 — Gestion commerciale

Produits, catégories, fichiers, prix, disponibilité, commandes, paiements, remboursements, grants et logs. Commencer read-only pour les autorités financières, puis actions explicites par sous-lots si nécessaire.

**BDD :** existante. Tout champ opérationnel manquant est proposé avant migration. **Sécurité :** confirmations, idempotence, audit, aucune donnée fournisseur brute.

### Lot A3 — Dashboard actionnable

CA par devise, commandes, panier moyen, conversion, top produits, évolution, alertes de réconciliation. Sources : commandes/paiements/rollups. Indisponible si non calculable.

**BDD :** rollups existants ; index/rollup nouveau seulement après mesure et gate.

### Lot A4 — Blog et SEO administrables

Éditeur, brouillon/planifié/publié, aperçu, images, auteurs, produits associés, métadonnées, slugs, redirections, preview Google. Import réel de `blog-articles.json` et arbitrage séparé de `article.json`.

### Lot A5 — CRM unifié

Fiche contact, historique achats par devise, activité autorisée, segments, exports, notes, étiquettes, interactions.

**Proposition BDD à valider :** `crm_contact_notes`, `crm_tags`, pivot tags/contacts et ledger `crm_interaction_events`. Aucune donnée comportementale sensible n'est fusionnée implicitement ; rétention et autorisations sont définies avant migration.

### Lot A6 — Automatisation en mode sec

Déclencheurs → conditions typées → actions : panier abandonné, post-achat, demande d'avis, bienvenue, alerte admin.

**Proposition BDD à valider :** workflows, versions immuables, exécutions, étapes/journal et outbox idempotent. Pas de SQL/closure libre. Tous les workflows sont désactivés par défaut ; mode dry-run prouvé avant tout fournisseur.

### Lot A7 — Affiliation et versements

Consolider cycle affilié, codes, attributions, commissions, compensations et payout administratif dans l'UX admin. Aucun compte bancaire/Mobile Money ne doit entrer sans gate dédié D-029.6/D-058 et revue sécurité.

### Lot A8 — QA admin et gate finale

Tests Policies/actions, responsive mobile, actions destructives, états vide/chargement/erreur, performance, accessibilité et audit des données. Pest/Pint/build complets et preuves visuelles.

## 6. Ordre et dépendances résumé

| Ordre | Lot | Bloque |
|---:|---|---|
| 0 | P0-R | toute Phase 1 |
| 1 | V0 | tous les écrans transactionnels |
| 2 | V1 | toutes les pages publiques |
| 3 | V2 + V3 | découverte et catalogue |
| 4 | V4 | pages de vente et avis |
| 5 | V5 + V6 | conversion produit et preuve sociale |
| 6 | V7 | sandbox paiement |
| 7 | V8 + V9 + V10 + V11 | complétude publique |
| 8 | V12 | confirmation sandbox réelle |
| 9 | V13 | Phase 2 |
| 10 | A0 | tout design admin |
| 11 | A1 | modules admin |
| 12 | A2 → A7 | complétude admin par domaine |
| 13 | A8 | clôture |

Les lots indiqués sur une même ligne restent des PR séparées et sont exécutés séquentiellement conformément à la règle « une feature à la fois ».

## 7. Décisions requises avant Phase 1

1. valider les livrables A–F et l'ordre V0 → V13 ;
2. confirmer que le compteur logiciels autoritaire est 193 et le prix Pack Livres 3 500 XOF ;
3. autoriser ou non la republication des 6 témoignages ;
4. valider la proposition BDD V4 avant toute migration ;
5. décider du maintien du thème clair/sombre et du rôle de la vidéo héro ;
6. confirmer la stratégie de contenu légal et la personne qui valide les textes ;
7. faire tourner les clés GeniusPay sandbox potentiellement exposées avant V12.
