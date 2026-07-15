# SEO_BLOG.md — Blog natif & référencement
# Objectif : le blog est un canal d'acquisition, pas une décoration.

---

## 🧱 MODÈLE DE DONNÉES (à ajouter en P7)

```sql
CREATE TABLE articles (
    id              BIGSERIAL PRIMARY KEY,
    slug            TEXT NOT NULL UNIQUE,
    title           TEXT NOT NULL,
    excerpt         TEXT,
    body            TEXT NOT NULL,              -- markdown ou HTML sanitizé
    cover_image_path TEXT,
    author_id       BIGINT REFERENCES users(id) ON DELETE SET NULL,
    status          TEXT NOT NULL DEFAULT 'draft'
                    CHECK (status IN ('draft','published','archived')),
    -- SEO
    meta_title       TEXT,
    meta_description TEXT,
    canonical_url    TEXT,
    -- Rollups
    views_count     INT NOT NULL DEFAULT 0,
    reading_minutes INT,
    published_at    TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ON articles (status, published_at DESC);

-- Lien article ↔ produit : c'est là que le blog devient rentable
CREATE TABLE article_product (
    article_id BIGINT REFERENCES articles(id) ON DELETE CASCADE,
    product_id BIGINT REFERENCES products(id) ON DELETE CASCADE,
    PRIMARY KEY (article_id, product_id)
);

CREATE TABLE tags (id BIGSERIAL PRIMARY KEY, slug TEXT UNIQUE, name TEXT NOT NULL);
CREATE TABLE article_tag (
    article_id BIGINT REFERENCES articles(id) ON DELETE CASCADE,
    tag_id     BIGINT REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (article_id, tag_id)
);
```

`article_product` est la table qui transforme le trafic SEO en ventes : chaque
article recommande les produits pertinents, et on mesure la conversion par article.

---

## 🔎 LES FONDAMENTAUX TECHNIQUES

```
[ ] URLs propres et stables : /blog/mon-article (jamais ?id=42). Slug immuable.
[ ] Une seule balise <h1> par page
[ ] meta title (≤ 60 car.) et meta description (≤ 155 car.) uniques par page
[ ] Balise canonical sur chaque page
[ ] sitemap.xml généré dynamiquement + soumis à Search Console
[ ] robots.txt qui n'interdit pas ce qui doit être indexé
[ ] Open Graph + Twitter Card (partage social)
[ ] Images : WebP, lazy loading, attribut alt renseigné
[ ] Pagination avec rel=prev/next, ou mieux : pages de catégories riches
[ ] Redirections 301 pour toute URL qui change (jamais de 404 sur du contenu déplacé)
```

---

## 📐 DONNÉES STRUCTURÉES (Schema.org)

Les deux qui rapportent vraiment :

```html
<!-- Fiche produit : prix + avis affichés directement dans Google -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Product",
  "name": "{{ $product->name }}",
  "description": "{{ $product->short_description }}",
  "offers": {
    "@type": "Offer",
    "price": "{{ $product->price_minor }}",
    "priceCurrency": "XOF",
    "availability": "https://schema.org/InStock"
  },
  "aggregateRating": {
    "@type": "AggregateRating",
    "ratingValue": "{{ $product->rating_avg }}",
    "reviewCount": "{{ $product->rating_count }}"
  }
}
</script>

<!-- Article de blog -->
{ "@type": "Article", "headline": "…", "datePublished": "…", "author": {...} }
```

⚠️ Ne déclare `aggregateRating` que si tu as de **vrais** avis vérifiés
(`reviews.verified_purchase`). Un faux avis structuré = pénalité Google.

---

## ⚡ PERFORMANCE (Core Web Vitals = facteur de classement)

| Métrique | Cible | Levier principal |
|----------|-------|------------------|
| LCP | < 2,5 s | Cache page, images WebP, CDN |
| CLS | < 0,1 | `width`/`height` sur toutes les images |
| INP | < 200 ms | Moins de JS, Alpine plutôt qu'un framework lourd |

Côté Laravel : cache de vue, `->remember()` sur les requêtes catalogue,
`php artisan optimize` en production, OPcache activé.

---

## ✍️ STRATÉGIE ÉDITORIALE (le vrai levier)

Le SEO technique ne classe rien s'il n'y a rien à classer.

- **Clusters thématiques** : un article pilier (« Comment apprendre X ») + 5-10
  articles satellites qui pointent vers lui. Maillage interne serré.
- **Intention de recherche** : un article qui cible « comment faire X » ne vend rien
  frontalement ; il capture l'e-mail et recommande le produit en fin de parcours.
- **Chaque article lie 1-3 produits** via `article_product`.
- **Mesure** : `article_read` dans `events`, puis conversion par article
  (article → product_view → purchase). Tu sauras quel article rapporte.

---

## 🧭 CE QUE JE CHALLENGE

Beaucoup de boutiques créent un blog « pour le SEO » et publient 3 articles morts.
Mieux vaut **10 articles excellents et maillés** que 100 articles creux. Le budget
crawl et l'autorité de domaine se diluent. Si tu ne peux pas tenir un rythme,
privilégie des pages de catégories riches (elles convertissent mieux, en plus).
