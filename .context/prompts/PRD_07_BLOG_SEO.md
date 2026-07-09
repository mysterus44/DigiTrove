# PRD_07 — BLOG & SEO (contenu, sitemap, données structurées)
# Phase P7. Prérequis : P2 (catalogue) au minimum. Peut se faire en parallèle du CRM.

---

## 🎯 OBJECTIF
Un blog natif performant qui propulse le SEO, avec migration du contenu legacy
(blog-articles.json, article.json), URLs propres, sitemap, et données structurées.

---

## 📄 PROMPT PRÊT À COLLER

```
Lis AGENTS.md, DigiTrove_Schema_BDD_v1.md et .context/skills/SEO_BLOG.md.
Le contenu legacy à importer est décrit dans .context/context/AUDIT_LEGACY.md.

MISSION P7 — Blog natif + SEO.

BDD D'ABORD :
- articles (slug UNIQUE, title, excerpt, body, cover_image_path, status
  draft/published, author_id, published_at, meta_title, meta_description)
- article_categories + table pivot (optionnel selon le legacy)
- redirects (from_path, to_path, code 301) pour préserver le SEO des anciennes URLs

Puis :
- Import des articles depuis blog-articles.json / article.json (commande artisan
  d'import idempotente ; ne pas dupliquer si relancée).
- Front blog : liste paginée, page article, catégories. Blade + Tailwind, pensé
  pour accueillir des maquettes Figma.
- SEO : balises title/meta/canonical par page, Open Graph, sitemap.xml dynamique,
  robots.txt, données structurées JSON-LD (Article pour le blog, Product pour les
  fiches produits du catalogue).
- ArticleResource Filament pour la rédaction (brouillon → publié).
- Redirections 301 des anciennes URLs vers les nouvelles (préserver l'acquis SEO).

CONTRAINTES :
- URLs par slug, jamais par id. Échappement Blade (XSS) sur tout contenu rendu.
- Sitemap et JSON-LD générés côté serveur, valides.

LIVRABLES :
- Migrations + commande d'import + front blog + resource Filament + sitemap + JSON-LD
- Tests Pest : import idempotent · slug unique · article publié visible / brouillon
  masqué · sitemap contient les articles publiés · redirect 301 fonctionne
- Trackers mis à jour (PROCHAINE TÂCHE = durcissement / pré-production)

PROCESSUS : plan d'abord, validation, puis implémentation.
```

---

## ✅ CRITÈRES D'ACCEPTATION
- Le contenu legacy est importé sans doublon.
- Chaque page a ses balises SEO + JSON-LD valides.
- Les anciennes URLs redirigent en 301.

## 🚫 HORS PÉRIMÈTRE
Newsletter, emailing (à cadrer). Optimisation Core Web Vitals fine (itération ultérieure).
