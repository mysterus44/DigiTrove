# Audit fonctionnel et visuel de `DigiTrove-Ancien`

**Phase 0 — Livrable A**

**Date de constat :** 21 août 2026

**Référence auditée :** `DigiTrove-Ancien/`

**Principe :** observation uniquement. Aucun fichier legacy, compte, avis, produit ou compteur n'a été modifié.

## 1. Périmètre et méthode

L'audit combine quatre sources de preuve :

1. inventaire récursif, y compris les fichiers cachés ;
2. lecture du PHP procédural, des quatre JSON et du schéma SQLite ;
3. navigation réelle sous `http://localhost/digitrove-ancien/` en 1440 × 900 et 375 × 812 ;
4. captures des écrans et états significatifs, sans soumettre de formulaire ni suivre un CTA marchand externe.

Le nombre annoncé de **202 entrées** est exact, mais il ne signifie pas 202 fichiers applicatifs :

| Bloc | Nombre | Commentaire |
|---|---:|---|
| Métadonnées du `.git` imbriqué | 112 | objets, références et journaux Git ; pas du code exécutable |
| Fichiers fonctionnels hors `.git` | 90 | totalité du site, de ses données et de ses médias |
| **Total** | **202** | inventaire récursif complet |

Décomposition des 90 fichiers fonctionnels : 37 PHP, 4 JSON, 1 SQLite, 1 `.htaccess`, 42 PNG, 3 SVG, 1 MP4 et 1 transcription texte (`te.txt`). Les 46 médias pèsent **70 978 341 octets** (environ 67,7 Mio).

## 2. Carte fonctionnelle publique

| Fichier / URL | Fonction observée | Données / dépendances | État |
|---|---|---|---|
| `index.php` | Redirection vers `page/index.php` | `BASE_URL` | Fonctionne |
| `page/index.php` | Accueil : vidéo héro, 6 univers, meilleures ventes, avis, dépôt d'avis, contact | produits + avis JSON, MP4, Swiper | Riche, mais plusieurs actions ne sont pas fiables |
| `page/boutique.php` | Catalogue des 5 produits, recherche, filtre type, prix maximum | `produits.json`, JavaScript client | Fonctionne ; état vide sans message |
| `page/a-propos.php` | Mission, valeurs, équipe et identité africaine | médias `images/equipe` | Fonctionne |
| `page/blog.php` | Listing, vedette, 19 catégories, recherche, publicités fictives | `blog-articles.json` | Fonctionne ; taxonomie trop fragmentée |
| `page/article.php?slug=…` | Lecture d'un article par slug | `blog-articles.json` | Les 19 slugs rendent un H1 exact, sans débordement horizontal |
| `page/faqs.php` | 7 accordéons et CTA WhatsApp | contenu statique | Fonctionne, mais décrit encore Chariow |
| `page/cgv.php` | Conditions générales | contenu statique | Lien `retours.php` cassé, titre dupliqué |
| `page/politique-confidentialite.php` | Politique de confidentialité | contenu statique | Fonctionne, titre dupliqué |
| `page/politique-livraison.php` | Politique de livraison | contenu statique | Fonctionne, titre dupliqué |
| `page/return-refund.php` | Retours et remboursements | contenu statique | Fonctionne |
| `page/test.php` | Variante expérimentale du blog | mêmes articles | Non reliée par la navigation ; doublon probable |
| `admin/login.php` | Connexion et inscription sur carte recto-verso | SQLite, `auth_handler.php` | Formulaires fonctionnels en JavaScript ; logo cassé |
| URL inexistante | 404 Apache | serveur local | Non personnalisée, hors identité DigiTrove |

### Navigation, thème et structure partagée

- `includes/header.php` charge Tailwind par CDN, Google Fonts, Font Awesome, Swiper et Lucide depuis des tiers.
- `includes/navbar.php` fournit une navigation fixe, un menu mobile, un menu compte et un thème clair/sombre.
- `includes/footer.php` porte les liens légaux, WhatsApp, Facebook, TikTok et le bouton de retour en haut.
- L'identité visuelle est reconnaissable : bleu électrique, orange, bleu nuit, logo centré, grands visuels et mouvement.
- Le fichier de favicon `images/logos/logo.svg` manque. Le login demande `digitrove-2.0-bgR.svg`, alors que seul le PNG existe.
- L'accueil demande aussi `images/offres/tools.png` et `images/offres/abonnement.jpg`, absents. Les replis externes masquent partiellement ces ruptures.

## 3. Parcours visiteurs

### 3.1 Découvrir puis acheter

```text
Accueil ou blog → Boutique → filtre/recherche → CTA « Profiter de l'offre »
                                             → nouvel onglet Chariow
```

Il n'existe aucune fiche produit interne, aucun panier, aucune commande et aucun paiement local dans l'ancien site. Les cinq CTA pointent vers `digitrove.mychariow.com/pd1|pd2|pd4|pd5|pd6`.

Le clic marchand déclenche également `admin/increment_sale.php` via `sendBeacon`. Le champ `ventes` mesure donc des **clics sortants**, pas des paiements confirmés. Toute « recette » calculée par `dashboard_data.php` avec `ventes × prix réduit courant` est un indicateur marketing trompeur, pas une donnée comptable.

### 3.2 Rechercher un produit

La boutique filtre instantanément par nom, type et prix maximum. Le rendu est rapide et lisible, mais :

- le HTML des cartes est construit avec `innerHTML` depuis le JSON administrable ;
- un résultat vide retire toutes les cartes sans afficher d'explication ni bouton de remise à zéro ;
- la liste mobile passe à deux colonnes très serrées ;
- les liens restent externes et aucun état de panier n'est conservé.

### 3.3 Lire le blog

Le listing offre une vedette, une recherche et des puces de catégorie. Les 19 articles ont été ouverts directement : titre, H1 et absence de débordement sont conformes pour les 19.

Le parseur d'article échappe le contenu avant ses transformations Markdown, ce qui est protecteur, mais son ordre de remplacement laisse parfois des `#` ou `##` visibles dans les titres. Chaque article possède une catégorie distincte : les pages de catégories seraient donc des pages maigres à un seul article.

### 3.4 Donner un avis ou contacter DigiTrove

- Le formulaire d'avis écrit directement dans `avis.json` via `includes/submit_review.php`.
- Le formulaire n'est pas protégé par un contrôle CSRF effectif.
- Le formulaire contact affiche un succès après validation locale, sans transport d'e-mail ni persistance : le message est perdu.
- La case newsletter ne produit aucune preuve de consentement et aucune inscription réelle.

### 3.5 Créer un compte ou se connecter

Le login et l'inscription utilisent `fetch('auth_handler.php')`. Les mots de passe nouveaux sont hachés avec Argon2id, les requêtes SQLite sont préparées et la session est régénérée à la connexion. Une limitation à 5 essais par 15 minutes existe, mais uniquement dans la session du navigateur.

Risques : formulaires HTML sans `method` explicite avant interception JavaScript, erreurs serveur brutes renvoyées en JSON, autorisation par rôle procédurale, cookie non `Secure` en configuration locale et comptes stockés dans un fichier SQLite historiquement versionné.

## 4. Données legacy

### 4.1 Produits — `data/produits.json`

| ID | Nom | Type | Prix barré | Prix | Compteur `ventes` | USB | URL |
|---:|---|---|---:|---:|---:|---|---|
| 1 | Pack Livres | livre | 7 700 XOF | 3 500 XOF | 202 | non | `pd1` |
| 2 | Pack De Formation Bureautique | formation | 21 000 XOF | 9 000 XOF | 239 | oui | `pd2` |
| 4 | Pack +200 logiciels Pro + Bonus | logiciel | 16 500 XOF | 4 900 XOF | 193 | non | `pd4` |
| 5 | Système de Gestion StarCode Pro avec Droits de Revente | ressource | 27 500 XOF | 9 900 XOF | 308 | non | `pd5` |
| 6 | Pack De Formation Développement Web FullStack | formation | 49 900 XOF | 9 900 XOF | 89 | non | `pd6` |

Total affiché : **1 031** clics historiques. L'ID 3 n'existe plus. Tous les produits sont marqués disponibles.

### 4.2 Avis — `data/avis.json`

Six avis sont présents : 5 notes de 5/5 et 1 note de 4/5, soit une moyenne de **4,83/5**. Champs : `id`, `nom`, `profession`, `note`, `commentaire`, `date`. L'accueil triple artificiellement la collection avec `array_merge($avis, $avis, $avis)` pour alimenter le carrousel : le visiteur voit 18 diapositives, mais seulement 6 témoignages distincts.

### 4.3 Articles — `data/blog-articles.json`

Le fichier contient 19 articles, datés du 25 juillet au 15 novembre 2025, avec `slug`, `title`, `category`, `date`, `author`, `image`, `excerpt`, `tags`, `content`.

| # | Slug | Titre abrégé |
|---:|---|---|
| 1 | `revolution-no-code-creer-apps-sans-coder-2025` | La Révolution No-Code |
| 2 | `freelance-afrique-gagner-devises-guide` | Freelance depuis l'Afrique |
| 3 | `automatiser-whatsapp-ia-machine-a-vendre` | WhatsApp + IA |
| 4 | `10-outils-ia-pour-votre-business-2025` | 10 outils d'IA |
| 5 | `guide-ultime-prompt-engineering-chatgpt-2025` | Prompt Engineering |
| 6 | `guide-creer-vendre-produit-digital-ia-7-jours` | Produit numérique en 7 jours |
| 7 | `lancer-business-ligne-afrique-2025-guide-realiste` | Business en ligne en Afrique |
| 8 | `etudes-et-ia-hacker-ses-revisions` | IA pour les étudiants |
| 9 | `cyberscurite-proteger-business-piratage` | Cybersécurité business |
| 10 | `personal-branding-linkedin-afrique` | Personal Branding |
| 11 | `productivite-deep-work-concentration` | Deep Work |
| 12 | `comprendre-blockchain-crypto-debutant` | Blockchain et crypto |
| 13 | `guide-facebook-ads-rentable-2025` | Facebook Ads |
| 14 | `tiktok-business-viralite-2025` | TikTok Business |
| 15 | `print-on-demand-ia-marque-vetement` | Print on Demand |
| 16 | `devenir-digital-nomad-afrique-guide` | Digital Nomad |
| 17 | `vendre-produits-digitaux-chariow-afrique` | Vendre avec Chariow |
| 18 | `youtube-automation-chaine-sans-visage-ia` | YouTube Automation |
| 19 | `maitriser-canva-ia-design-pro` | Canva + IA |

`data/article.json` contient un article isolé en anglais (`cart-management`) dans une structure incompatible avec le blog. Aucune référence applicative n'a été trouvée : c'est un candidat d'archive ou de suppression, après validation.

### 4.4 Utilisateurs — `data/users.sqlite`

La table `users` contient 3 lignes : 2 rôles `admin`, 1 rôle `user`. Schéma : `id`, `nom`, `prenom`, `email` unique, `mot_de_passe`, `role`, `date_inscription`. Aucune valeur personnelle ni aucun hachage n'a été copié dans cet audit.

## 5. Back-office legacy

| Fichier | Fonction | Constat principal |
|---|---|---|
| `dashboard.php` | coque dashboard et navigation | inaccessible anonymement : redirige vers `/admin/login.php` hors du sous-répertoire, donc 404 |
| `dashboard_data.php` | statistiques et fragments AJAX | chiffre d'affaires calculé à partir de clics et prix actuels |
| `gestion-produits.php` | création/liste produits | écrit le JSON ; upload contrôlé par extension/taille, pas par MIME |
| `edit_produit.php` | édition produit | logique et présentation distinctes du reste |
| `delete_produit.php` | suppression produit | action destructive par GET, sans CSRF |
| `increment_sale.php` | incrément du compteur | endpoint public, sans auth ni CSRF ; appelé avant tout paiement |
| `gestion_avis.php` | gestion des avis | CRUD JSON, sorties et suppressions à durcir |
| `delete_avis.php` | suppression d'avis | lit le mauvais fichier (`produits.json`) et redirige vers un fichier absent |
| `gestion-blog.php` | CRUD blog authentifié | édition JSON, upload, suppressions sans vraie politique |
| `admin-blog.php` | second CRUD blog | mot de passe en clair compromis et authentification séparée |
| `gestion-utilisateurs.php` | CRUD utilisateurs / rôles | SQLite, attribution directe de rôle, suppression par GET |
| `gestion-commandes.php` | commandes | fichier de 15 octets : non implémenté |
| `fix_urls.php` | correction des liens Chariow | utilitaire de mutation exposé dans le répertoire admin |
| `auth_handler.php` | login / inscription | préparé + Argon2id, mais erreurs internes exposables |
| `login.php` | interface compte | logo absent, bascule login/inscription |
| `logout.php` | fin de session | procédural |

Le back-office réellement visible sans utiliser de secret se limite au login principal et au portail à mot de passe de `admin-blog.php`. Aucun mot de passe historique n'a été utilisé. Les autres écrans sont documentés par le code ; le dashboard ne peut pas être atteint dans l'hébergement en sous-répertoire à cause de sa redirection absolue erronée.

## 6. Critique design structurée

### Première impression

L'ancien site communique immédiatement « produits digitaux, ambition, Afrique » grâce à son héro vidéo, son contraste bleu/orange et ses visuels produits. L'opportunité majeure est de conserver cette énergie tout en retirant l'accumulation de CDN, les incohérences de composants et les promesses non reliées à un vrai système.

### Utilisabilité et hiérarchie

| Constat | Sévérité | Recommandation future |
|---|---|---|
| CTA principal visible dès le premier écran | Positif | conserver un CTA catalogue fort |
| Achat sortant sans explication de la rupture Chariow | Critique | tunnel interne continu et rassurant |
| Recherche boutique vide sans retour | Modérée | état vide explicite + remise à zéro |
| Accueil très long et dense | Modérée | hiérarchiser bénéfices, preuves, produits, FAQ et CTA |
| Grille mobile à deux colonnes | Modérée | une carte lisible par ligne ou largeur minimale contrôlée |
| 19 catégories pour 19 articles | Modérée | taxonomie éditoriale consolidée |
| Deux authentifications admin concurrentes | Critique | une seule identité Laravel et des Policies |

### Cohérence et accessibilité

- Les cartes, formulaires et pages légales n'utilisent pas toujours les mêmes espacements ni les mêmes rayons.
- Les boutons de navigation mobile mesurent environ 40 × 40 px ; les puces blog environ 36 px de haut ; les bascules login/inscription environ 24–25 px. Ils sont sous la cible tactile recommandée de 44 px.
- Plusieurs liens de footer sont visuellement et tactilement petits.
- Les pages restent sans débordement horizontal à 375 px sur les écrans contrôlés.
- Les contrastes du bleu électrique sur fond nuit sont expressifs, mais certains textes gris et icônes sont faibles.
- Les images d'équipe ont des textes alternatifs incomplets ou absents sur certaines cartes.
- Le thème clair/sombre et le menu mobile sont de bonnes bases à conserver.

### Ce qui fonctionne bien

- personnalité de marque mémorable ;
- récit orienté réussite et accessibilité africaine ;
- catalogue visuel immédiatement compréhensible ;
- preuve sociale riche ;
- contenus blog substantiels et slugs acquis ;
- navigation publique courte ;
- responsive sans débordement horizontal observé.

## 7. Risques prioritaires

1. **Secrets et données personnelles** : mots de passe en clair dans deux scripts historiques, SQLite de comptes, transcription `te.txt` potentiellement sensible.
2. **Faux indicateurs financiers** : clics Chariow présentés comme ventes et revenus.
3. **Actions destructives** : GET sans CSRF sur produits, avis, utilisateurs et blog.
4. **Téléversements** : validation extension/taille insuffisante et médias dans un répertoire web.
5. **XSS** : cartes boutique construites par `innerHTML` avec données administrables.
6. **Parcours cassés** : dashboard 404, logo/fond/icônes absents, lien CGV erroné.
7. **Formulaires fictifs** : contact et newsletters donnent une impression de succès sans traitement réel.
8. **Dépendance complète à Chariow** : paiement, preuve et livraison hors DigiTrove.

## 8. Captures

Les 39 captures sont conservées sous [`captures/ancien`](./captures/ancien/).

| Écran / état | Desktop | Mobile |
|---|---|---|
| Accueil | [`accueil-1440-hero.png`](./captures/ancien/desktop/accueil-1440-hero.png) | [`accueil-375.png`](./captures/ancien/mobile/accueil-375.png) |
| Boutique | [`boutique-1440.png`](./captures/ancien/desktop/boutique-1440.png) | [`boutique-375.png`](./captures/ancien/mobile/boutique-375.png) |
| À propos | [`a-propos-1440.png`](./captures/ancien/desktop/a-propos-1440.png) | [`a-propos-375.png`](./captures/ancien/mobile/a-propos-375.png) |
| Blog | [`blog-1440.png`](./captures/ancien/desktop/blog-1440.png) | [`blog-375.png`](./captures/ancien/mobile/blog-375.png) |
| Article | [`article-no-code-1440.png`](./captures/ancien/desktop/article-no-code-1440.png) | [`article-no-code-375.png`](./captures/ancien/mobile/article-no-code-375.png) |
| FAQ | [`faqs-1440.png`](./captures/ancien/desktop/faqs-1440.png) | [`faqs-375.png`](./captures/ancien/mobile/faqs-375.png) |
| CGV | [`cgv-1440.png`](./captures/ancien/desktop/cgv-1440.png) | [`cgv-375.png`](./captures/ancien/mobile/cgv-375.png) |
| Confidentialité | [`confidentialite-1440.png`](./captures/ancien/desktop/confidentialite-1440.png) | [`confidentialite-375.png`](./captures/ancien/mobile/confidentialite-375.png) |
| Livraison | [`livraison-1440.png`](./captures/ancien/desktop/livraison-1440.png) | [`livraison-375.png`](./captures/ancien/mobile/livraison-375.png) |
| Retours | [`retours-remboursements-1440.png`](./captures/ancien/desktop/retours-remboursements-1440.png) | [`retours-remboursements-375.png`](./captures/ancien/mobile/retours-remboursements-375.png) |
| Connexion | [`connexion-1440.png`](./captures/ancien/desktop/connexion-1440.png) | [`connexion-375.png`](./captures/ancien/mobile/connexion-375.png) |
| Inscription | [`inscription-1440.png`](./captures/ancien/desktop/inscription-1440.png) | [`inscription-375.png`](./captures/ancien/mobile/inscription-375.png) |
| Blog expérimental | [`test-blog-1440.png`](./captures/ancien/desktop/test-blog-1440.png) | [`test-blog-375.png`](./captures/ancien/mobile/test-blog-375.png) |
| Portail blog admin | [`admin-blog-acces-1440.png`](./captures/ancien/desktop/admin-blog-acces-1440.png) | [`admin-blog-acces-375.png`](./captures/ancien/mobile/admin-blog-acces-375.png) |
| Dashboard anonyme / redirection cassée | [`admin-dashboard-anonyme-1440.png`](./captures/ancien/desktop/admin-dashboard-anonyme-1440.png) | [`admin-dashboard-anonyme-375.png`](./captures/ancien/mobile/admin-dashboard-anonyme-375.png) |
| 404 | [`erreur-404-1440.png`](./captures/ancien/desktop/erreur-404-1440.png) | [`erreur-404-375.png`](./captures/ancien/mobile/erreur-404-375.png) |
| Navigation ouverte | — | [`navigation-ouverte-375.png`](./captures/ancien/mobile/navigation-ouverte-375.png) |
| Thème alternatif | — | [`accueil-theme-alternatif-375.png`](./captures/ancien/mobile/accueil-theme-alternatif-375.png) |
| Formulaire avis ouvert | — | [`avis-formulaire-ouvert-375.png`](./captures/ancien/mobile/avis-formulaire-ouvert-375.png) |
| FAQ ouverte | — | [`faq-ouverte-375.png`](./captures/ancien/mobile/faq-ouverte-375.png) |
| Boutique sans résultat | — | [`boutique-aucun-resultat-375.png`](./captures/ancien/mobile/boutique-aucun-resultat-375.png) |
| Blog sans résultat | — | [`blog-aucun-resultat-375.png`](./captures/ancien/mobile/blog-aucun-resultat-375.png) |

## 9. Conclusion

`DigiTrove-Ancien` est une référence **de contenu, de ton et d'intentions UX**, pas une base technique réutilisable. La refonte doit conserver les 5 offres, les 19 articles, les 6 avis, l'identité visuelle, la clarté des univers et la proximité africaine. Elle doit remplacer intégralement le stockage JSON/SQLite, les deux authentifications admin, les compteurs de clics, les formulaires fictifs et la dépendance Chariow par les autorités Laravel/PostgreSQL déjà présentes.
