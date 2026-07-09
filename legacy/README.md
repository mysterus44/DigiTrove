# Legacy DigiTrove

Ce dossier archive l'ancien DigiTrove PHP procédural pour migration de contenu
uniquement. Il ne fait pas partie de l'application Laravel active.

Contenu :
- `admin/` : ancien back-office PHP, conservé pour audit fonctionnel.
- `data/` : sources JSON de migration (`blog-articles.json`, `produits.json`,
  `article.json`, `avis.json`) et éventuelle SQLite locale ignorée par Git.
- `includes/` et `page/` : ancien front PHP, conservé pour extraction de contenu.
- `images/`, `uploads/`, `videos/` : assets legacy à trier avant import.
- `index.php` : ancien point d'entrée racine, archivé hors du flux Laravel.

Règles :
- Ne pas servir ce dossier publiquement.
- Ne pas exécuter le code PHP legacy.
- Ne pas réutiliser les secrets ou mots de passe historiques : ils sont compromis.
- Ne pas supprimer d'asset avant validation humaine de la migration de contenu.
