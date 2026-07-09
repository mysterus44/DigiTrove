# UIUX_DESIGN_SYSTEM.md — Design system & front-end
# Blade + Tailwind + Alpine.js · pensé pour accueillir des maquettes Figma pro.

---

## 🎨 DESIGN TOKENS

```js
// tailwind.config.js — la source de vérité visuelle
theme: {
  extend: {
    colors: {
      brand:   { 50:'#F5F3FF', 500:'#7C3AED', 600:'#6D28D9', 900:'#4C1D95' }, // violet : premium, digital
      success: { 500:'#10B981' },   // paiement confirmé, téléchargement prêt
      danger:  { 500:'#EF4444' },   // erreur, lien expiré
      ink:     { 500:'#6B7280', 900:'#111827' },
    },
    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
    borderRadius: { xl: '0.75rem', '2xl': '1rem' },
  }
}
```

Grille d'espacement : multiples de 4px. Jamais de valeur arbitraire (`mt-[13px]`).
Si Figma dit 13px, la maquette est fausse ou le token manque — on tranche, on ne bricole pas.

---

## 📱 MOBILE-FIRST, TOUJOURS

```html
<!-- ❌ pensé desktop, rétro-adapté -->
<div class="text-3xl md:text-xl">

<!-- ✅ pensé mobile, enrichi ensuite -->
<div class="text-xl md:text-3xl">
```

Cibles tactiles : **44×44 px minimum**. Contraste **WCAG AA** minimum.

---

## 🛒 LE TUNNEL DE COMMANDE — les règles qui font le chiffre

```
[ ] Le prix total est visible à chaque étape, sans surprise à la fin
[ ] Aucune création de compte obligatoire (checkout invité + e-mail)
[ ] Un seul écran si possible ; sinon une barre de progression honnête
[ ] Les erreurs de formulaire sont affichées à côté du champ, en français clair
[ ] Le bouton de paiement se désactive pendant la requête (anti double-clic)
[ ] État de chargement explicite : « On confirme ton paiement… »
[ ] En cas d'échec : message actionnable + moyen de réessayer + contact support
[ ] Après paiement : les liens de téléchargement sont visibles ET envoyés par e-mail
```

Le double canal (écran + e-mail) est essentiel : le client ferme souvent l'onglet.

---

## 📄 LA FICHE PRODUIT — ce qui convertit

Ordre visuel, du haut vers le bas :
1. Titre + prix (barré si promo) + note et nombre d'avis
2. Visuel / aperçu du contenu (captures, sommaire du pack)
3. **Bouton d'achat**, visible sans scroll sur mobile
4. Ce qui est inclus (liste des fichiers, format, taille, version)
5. Réassurance : « Téléchargement immédiat », « Paiement sécurisé », « Support »
6. Avis vérifiés (`verified_purchase = true` en priorité)
7. Produits liés (bundles, articles de blog associés)

---

## 🧩 COMPOSANTS BLADE RÉUTILISABLES

```blade
{{-- resources/views/components/price.blade.php --}}
@props(['minor', 'compareAt' => null])

<div class="flex items-baseline gap-2">
    <span class="text-2xl font-bold text-ink-900">{{ money($minor) }}</span>
    @if ($compareAt && $compareAt > $minor)
        <span class="text-sm line-through text-ink-500">{{ money($compareAt) }}</span>
        <span class="rounded-full bg-success-500/10 px-2 py-0.5 text-xs text-success-500">
            -{{ round(100 - ($minor / $compareAt * 100)) }}%
        </span>
    @endif
</div>
```

Composants à créer : `<x-price>` · `<x-product-card>` · `<x-alert>` · `<x-button>`
· `<x-empty-state>` · `<x-download-link>`.

---

## 🎭 ALPINE.JS — juste ce qu'il faut

```html
<!-- Panier : optimiste, sans framework lourd -->
<button
    x-data="{ loading: false }"
    @click="loading = true; $wire.addToCart({{ $product->id }})"
    :disabled="loading"
    class="btn-primary">
    <span x-show="!loading">Ajouter au panier</span>
    <span x-show="loading">Ajout…</span>
</button>
```

Pas de React/Vue pour une boutique server-rendered. Le SEO et le LCP en souffriraient.

---

## 🖼️ INTÉGRER LES MAQUETTES FIGMA

Pour qu'une maquette pro s'intègre sans douleur :
1. **Extraire les tokens Figma** (couleurs, typo, espacements) → `tailwind.config.js`.
   Jamais de valeur codée en dur dans une vue.
2. **Un composant Figma = un composant Blade.** Même nom, même variantes.
3. **Vérifier les états manquants** : Figma ne montre presque jamais le chargement,
   le vide, l'erreur, le trop-long. Les réclamer au design **avant** de coder.
4. **Mobile d'abord** : demander la maquette 375px, pas seulement la 1440px.

---

## ♿ ACCESSIBILITÉ (WCAG 2.1 AA, minimum)

```
[ ] Contraste texte ≥ 4,5:1
[ ] Navigation clavier complète (tab, focus visible)
[ ] alt sur chaque image porteuse de sens ; alt="" si décorative
[ ] Labels associés aux champs (jamais un placeholder comme seul label)
[ ] Messages d'erreur reliés au champ (aria-describedby)
[ ] Pas d'information portée uniquement par la couleur
```

---

## 🌑 THÈME SOMBRE

Prévu dès le départ (classe `dark:` Tailwind). Le back-office Filament le gère nativement.
