<?php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/functions.php';

$rawProducts = get_json_data('produits.json');
$typeLabels = [
    'formation' => 'Formations',
    'livre' => 'Livres & ebooks',
    'abonnement' => 'Abonnements',
    'logiciel' => 'Logiciels',
    'ressource' => 'Ressources',
    'all' => 'Autres',
];

$products = array_values(array_filter($rawProducts, static function ($product) {
    return ($product['disponible'] ?? true) !== false;
}));

$products = array_map(static function ($product) {
    $imagePath = preg_replace('#^(\.\./)+#', '', $product['image'] ?? '');
    $reducedPrice = (int) preg_replace('/\D+/', '', (string) ($product['prix_reduit'] ?? 0));
    $originalPrice = (int) preg_replace('/\D+/', '', (string) ($product['prix_original'] ?? $reducedPrice));

    $product['prix_reduit'] = $reducedPrice;
    $product['prix_original'] = $originalPrice;
    $product['ventes'] = (int) ($product['ventes'] ?? 0);
    $product['type'] = $product['type'] ?? 'all';
    $product['image_url'] = $imagePath ? asset($imagePath) : 'https://placehold.co/700x500?text=DigiTrove';
    $product['discount_percent'] = $originalPrice > $reducedPrice && $originalPrice > 0
        ? (int) round((($originalPrice - $reducedPrice) / $originalPrice) * 100)
        : 0;

    return $product;
}, $products);

$prices = array_column($products, 'prix_reduit');
$maxPrice = $prices ? max($prices) : 50000;
$minPrice = $prices ? min($prices) : 0;
$totalSales = array_sum(array_column($products, 'ventes'));
$bestSeller = $products ? array_reduce($products, static function ($carry, $product) {
    return $carry === null || $product['ventes'] > $carry['ventes'] ? $product : $carry;
}) : null;

$typeCounts = [];
foreach ($products as $product) {
    $type = $product['type'] ?? 'all';
    $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
}

$page_title = 'Boutique - Packs, formations et ressources digitales';
$page_desc = 'Explorez les packs DigiTrove : formations, logiciels, ebooks et ressources numériques prêts à utiliser.';
$jsonOptions = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$whatsappPhone = preg_replace('/\D+/', '', SITE_PHONE);

include INCLUDES_PATH . '/header.php';
include INCLUDES_PATH . '/navbar.php';
?>

<main class="min-h-screen bg-gray-50 dark:bg-dark-bg">
    <section class="relative overflow-hidden bg-light-bg dark:bg-dark-bg border-b border-gray-200 dark:border-gray-800">
        <div class="absolute inset-x-0 top-0 h-56 animated-gradient-bg opacity-95"></div>
        <div class="relative container mx-auto px-4 sm:px-6 lg:px-8 pt-14 pb-10">
            <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-end">
                <div class="text-white">
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/20 px-4 py-2 text-xs font-bold uppercase tracking-widest backdrop-blur">
                        <i data-lucide="sparkles" class="h-4 w-4"></i>
                        Boutique DigiTrove
                    </span>
                    <h1 class="mt-5 max-w-3xl text-4xl font-extrabold leading-tight md:text-5xl">
                        Des packs digitaux prêts à déployer pour apprendre, créer et vendre plus vite.
                    </h1>
                    <p class="mt-4 max-w-2xl text-base text-white/85 md:text-lg">
                        Filtrez les offres par besoin, comparez les prix et ajoutez vos favoris à une sélection avant de commander.
                    </p>
                </div>

                <div class="rounded-2xl border border-white/25 bg-white/95 p-5 shadow-xl dark:border-white/10 dark:bg-dark-card/95">
                    <p class="text-sm font-semibold uppercase tracking-widest text-brand-orange">En un coup d'oeil</p>
                    <div class="mt-4 grid grid-cols-3 gap-3 text-center">
                        <div class="rounded-xl bg-gray-100 p-3 dark:bg-gray-800">
                            <span class="block text-2xl font-extrabold text-gray-900 dark:text-white"><?php echo count($products); ?></span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">produits</span>
                        </div>
                        <div class="rounded-xl bg-gray-100 p-3 dark:bg-gray-800">
                            <span class="block text-2xl font-extrabold text-gray-900 dark:text-white"><?php echo number_format($totalSales, 0, ',', ' '); ?></span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">ventes</span>
                        </div>
                        <div class="rounded-xl bg-gray-100 p-3 dark:bg-gray-800">
                            <span class="block text-2xl font-extrabold text-gray-900 dark:text-white"><?php echo format_price($minPrice); ?></span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">dès</span>
                        </div>
                    </div>
                    <?php if ($bestSeller): ?>
                        <div class="mt-4 flex items-center gap-3 rounded-xl bg-brand-orange/10 p-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-orange text-white">
                                <i data-lucide="flame" class="h-5 w-5"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-orange">Meilleure vente</p>
                                <p class="truncate text-sm font-bold text-gray-900 dark:text-white"><?php echo e($bestSeller['nom']); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="sticky top-24 z-30 rounded-2xl border border-gray-200 bg-white/95 p-4 shadow-lg backdrop-blur dark:border-gray-700 dark:bg-dark-card/95">
            <div class="grid gap-4 lg:grid-cols-[minmax(220px,1.2fr)_180px_220px_170px] lg:items-end">
                <label class="block">
                    <span class="mb-2 block text-sm font-semibold text-gray-700 dark:text-gray-200">Rechercher</span>
                    <span class="relative block">
                        <i data-lucide="search" class="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400"></i>
                        <input
                            type="search"
                            id="search-input"
                            placeholder="Nom, pack, logiciel..."
                            class="w-full rounded-xl border border-gray-200 bg-gray-50 py-3 pl-10 pr-4 text-gray-900 outline-none transition focus:border-brand-orange focus:ring-2 focus:ring-brand-orange/30 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                        >
                    </span>
                </label>

                <label class="block">
                    <span class="mb-2 block text-sm font-semibold text-gray-700 dark:text-gray-200">Tri</span>
                    <select id="sort-filter" class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-gray-900 outline-none transition focus:border-brand-orange focus:ring-2 focus:ring-brand-orange/30 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                        <option value="recommended">Recommandés</option>
                        <option value="sales">Meilleures ventes</option>
                        <option value="price-asc">Prix croissant</option>
                        <option value="price-desc">Prix décroissant</option>
                        <option value="name">Nom A-Z</option>
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 flex items-center justify-between text-sm font-semibold text-gray-700 dark:text-gray-200">
                        <span>Prix max</span>
                        <span id="price-value" class="text-brand-orange"><?php echo format_price($maxPrice); ?></span>
                    </span>
                    <input
                        type="range"
                        id="price-filter"
                        min="0"
                        max="<?php echo max(1000, $maxPrice); ?>"
                        step="500"
                        value="<?php echo max(1000, $maxPrice); ?>"
                        class="h-2 w-full cursor-pointer appearance-none rounded-lg bg-gray-200 accent-brand-orange dark:bg-gray-700"
                    >
                </label>

                <button id="selection-toggle" class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-blue px-4 py-3 font-bold text-white transition hover:bg-brand-orange">
                    <i data-lucide="shopping-bag" class="h-5 w-5"></i>
                    <span>Sélection</span>
                    <span id="selection-count" class="rounded-full bg-white px-2 py-0.5 text-xs text-brand-blue">0</span>
                </button>
            </div>

            <div class="mt-4 flex flex-wrap gap-2" id="category-filters" aria-label="Catégories">
                <button type="button" data-type="all" class="category-chip active rounded-full border px-4 py-2 text-sm font-semibold transition">
                    Tout voir <span class="ml-1 text-xs opacity-70"><?php echo count($products); ?></span>
                </button>
                <?php foreach ($typeCounts as $type => $count): ?>
                    <button type="button" data-type="<?php echo e($type); ?>" class="category-chip rounded-full border px-4 py-2 text-sm font-semibold transition">
                        <?php echo e($typeLabels[$type] ?? ucfirst($type)); ?>
                        <span class="ml-1 text-xs opacity-70"><?php echo $count; ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p id="results-count" class="text-sm font-semibold text-gray-600 dark:text-gray-300"></p>
                <p class="text-sm text-gray-500 dark:text-gray-400">Paiement via lien sécurisé, livraison digitale selon l'offre.</p>
            </div>
            <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-600 dark:text-gray-300">
                <input id="usb-filter" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-brand-orange focus:ring-brand-orange">
                Afficher uniquement les offres avec USB
            </label>
        </div>

        <div id="products-grid" class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"></div>

        <div id="no-results" class="hidden rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center dark:border-gray-700 dark:bg-dark-card">
            <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                <i data-lucide="search-x" class="h-8 w-8 text-gray-400"></i>
            </div>
            <h2 class="text-xl font-extrabold text-gray-900 dark:text-white">Aucun produit ne correspond</h2>
            <p class="mt-2 text-gray-500 dark:text-gray-400">Essayez une autre catégorie, augmentez le prix max ou videz la recherche.</p>
            <button id="reset-filters" class="mt-5 rounded-xl bg-brand-orange px-5 py-3 font-bold text-white transition hover:opacity-90">Réinitialiser les filtres</button>
        </div>
    </section>
</main>

<div id="quick-view" class="fixed inset-0 z-[80] hidden items-center justify-center bg-black/60 p-4">
    <div class="quick-view-panel max-h-[92vh] w-full max-w-4xl overflow-y-auto rounded-2xl bg-white shadow-2xl dark:bg-dark-card">
        <div class="grid gap-0 md:grid-cols-[0.9fr_1.1fr]">
            <div class="relative bg-gray-100 p-5 dark:bg-gray-900">
                <button type="button" class="modal-close absolute right-4 top-4 z-10 rounded-full bg-white/90 p-2 text-gray-700 shadow hover:text-brand-orange dark:bg-gray-800 dark:text-gray-200">
                    <i data-lucide="x" class="h-5 w-5"></i>
                </button>
                <img id="modal-image" src="" alt="" class="h-72 w-full rounded-xl object-contain md:h-full">
            </div>
            <div class="p-6">
                <div class="flex flex-wrap items-center gap-2">
                    <span id="modal-type" class="rounded-full bg-brand-blue/10 px-3 py-1 text-xs font-bold uppercase tracking-wide text-brand-blue"></span>
                    <span id="modal-discount" class="hidden rounded-full bg-brand-orange px-3 py-1 text-xs font-bold uppercase tracking-wide text-white"></span>
                    <span id="modal-usb" class="hidden rounded-full bg-gray-100 px-3 py-1 text-xs font-bold uppercase tracking-wide text-gray-700 dark:bg-gray-800 dark:text-gray-200">USB disponible</span>
                </div>
                <h2 id="modal-title" class="mt-4 text-2xl font-extrabold text-gray-900 dark:text-white"></h2>
                <p id="modal-summary" class="mt-3 text-gray-600 dark:text-gray-300"></p>
                <div class="mt-5 flex items-end justify-between gap-4 border-y border-gray-200 py-4 dark:border-gray-700">
                    <div>
                        <span id="modal-old-price" class="block text-sm text-gray-400 line-through"></span>
                        <span id="modal-price" class="block text-3xl font-extrabold text-brand-orange"></span>
                    </div>
                    <div class="text-right text-sm text-gray-500 dark:text-gray-400">
                        <span id="modal-sales" class="font-bold text-brand-blue"></span><br>
                        ventes enregistrées
                    </div>
                </div>
                <ul id="modal-benefits" class="mt-5 space-y-3 text-sm text-gray-600 dark:text-gray-300"></ul>
                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    <a id="modal-buy" href="#" target="_blank" rel="noopener" class="track-click inline-flex items-center justify-center gap-2 rounded-xl bg-brand-orange px-5 py-3 font-bold text-white transition hover:opacity-90">
                        <i data-lucide="external-link" class="h-5 w-5"></i>
                        Acheter maintenant
                    </a>
                    <button id="modal-select" type="button" class="inline-flex items-center justify-center gap-2 rounded-xl border border-gray-300 px-5 py-3 font-bold text-gray-800 transition hover:border-brand-blue hover:text-brand-blue dark:border-gray-700 dark:text-gray-100">
                        <i data-lucide="plus" class="h-5 w-5"></i>
                        Ajouter à ma sélection
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<aside id="selection-drawer" class="fixed inset-y-0 right-0 z-[90] hidden w-full max-w-md border-l border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-dark-card">
    <div class="flex h-full flex-col">
        <div class="flex items-center justify-between border-b border-gray-200 p-5 dark:border-gray-700">
            <div>
                <p class="text-sm font-semibold uppercase tracking-widest text-brand-orange">Votre sélection</p>
                <h2 class="text-xl font-extrabold text-gray-900 dark:text-white">Produits à commander</h2>
            </div>
            <button id="selection-close" type="button" class="rounded-full p-2 text-gray-500 hover:bg-gray-100 hover:text-brand-orange dark:hover:bg-gray-800">
                <i data-lucide="x" class="h-5 w-5"></i>
            </button>
        </div>
        <div id="selection-items" class="flex-1 overflow-y-auto p-5"></div>
        <div class="border-t border-gray-200 p-5 dark:border-gray-700">
            <div class="mb-4 flex items-center justify-between text-lg font-extrabold">
                <span class="text-gray-900 dark:text-white">Total estimé</span>
                <span id="selection-total" class="text-brand-orange">0 FCFA</span>
            </div>
            <a id="selection-whatsapp" href="#" target="_blank" rel="noopener" class="flex w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-5 py-3 font-bold text-white transition hover:bg-green-700">
                <i class="fab fa-whatsapp"></i>
                Commander sur WhatsApp
            </a>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">La sélection est mémorisée sur cet appareil. Les achats directs restent disponibles sur chaque fiche.</p>
        </div>
    </div>
</aside>

<script>
    const allProducts = <?php echo json_encode($products, $jsonOptions); ?>;
    const typeLabels = <?php echo json_encode($typeLabels, $jsonOptions); ?>;
    const incrementUrl = '<?php echo url('admin/increment_sale.php'); ?>';
    const whatsappPhone = '<?php echo e($whatsappPhone); ?>';
    const initialMaxPrice = <?php echo (int) max(1000, $maxPrice); ?>;

    document.addEventListener('DOMContentLoaded', () => {
        const grid = document.getElementById('products-grid');
        const searchInput = document.getElementById('search-input');
        const sortFilter = document.getElementById('sort-filter');
        const priceFilter = document.getElementById('price-filter');
        const priceValue = document.getElementById('price-value');
        const usbFilter = document.getElementById('usb-filter');
        const noResults = document.getElementById('no-results');
        const resetFilters = document.getElementById('reset-filters');
        const resultsCount = document.getElementById('results-count');
        const categoryFilters = document.getElementById('category-filters');
        const selectionToggle = document.getElementById('selection-toggle');
        const selectionCount = document.getElementById('selection-count');
        const drawer = document.getElementById('selection-drawer');
        const selectionClose = document.getElementById('selection-close');
        const selectionItems = document.getElementById('selection-items');
        const selectionTotal = document.getElementById('selection-total');
        const selectionWhatsapp = document.getElementById('selection-whatsapp');
        const quickView = document.getElementById('quick-view');
        const modalClose = quickView.querySelector('.modal-close');
        const modalBuy = document.getElementById('modal-buy');
        const modalSelect = document.getElementById('modal-select');

        const formatter = new Intl.NumberFormat('fr-FR');
        const selectionKey = 'digitrove_shop_selection';
        let activeType = 'all';
        let activeProduct = null;
        let selectedIds = readSelection();

        function formatPrice(value) {
            return `${formatter.format(Number(value) || 0)} FCFA`;
        }

        function readSelection() {
            try {
                const stored = JSON.parse(localStorage.getItem(selectionKey) || '[]');
                return new Set(stored.map(String));
            } catch (error) {
                return new Set();
            }
        }

        function persistSelection() {
            localStorage.setItem(selectionKey, JSON.stringify([...selectedIds]));
        }

        function getProduct(id) {
            return allProducts.find(product => String(product.id) === String(id));
        }

        function productTypeLabel(type) {
            return typeLabels[type] || type || 'Produit digital';
        }

        function benefitsFor(product) {
            const common = ['Accès digital rapide après commande', 'Compatible ordinateur et mobile', 'Support via les contacts DigiTrove'];
            const byType = {
                formation: ['Programme structuré pour progresser étape par étape', 'Ressources pratiques et exploitables immédiatement', 'Idéal pour monter en compétence sans perdre de temps'],
                logiciel: ['Outils prêts à installer ou utiliser', 'Pack pensé pour booster votre productivité', 'Bonus inclus selon la fiche produit'],
                livre: ['Bibliothèque numérique facile à consulter', 'Sélection utile pour apprendre à votre rythme', 'Format pratique pour conserver vos ressources'],
                abonnement: ['Accès orienté usage régulier', 'Solution adaptée aux besoins récurrents', 'Accompagnement selon l’offre choisie'],
                ressource: ['Ressource prête à exploiter', 'Gain de temps pour vos projets digitaux', 'Livrable pensé pour passer à l’action'],
            };

            return byType[product.type] || common;
        }

        function productSummary(product) {
            const label = productTypeLabel(product.type).toLowerCase();
            return `${product.nom} est une offre ${label} conçue pour vous faire gagner du temps avec un livrable digital clair, pratique et immédiatement exploitable.`;
        }

        function setCategory(type) {
            activeType = type;
            syncCategoryChips();
            filterProducts();
        }

        function filteredProducts() {
            const search = searchInput.value.trim().toLowerCase();
            const maxPrice = Number(priceFilter.value) || initialMaxPrice;
            const usbOnly = usbFilter.checked;

            return allProducts
                .filter(product => {
                    const name = String(product.nom || '').toLowerCase();
                    const type = product.type || 'all';
                    const matchesSearch = !search || name.includes(search) || productTypeLabel(type).toLowerCase().includes(search);
                    const matchesType = activeType === 'all' || type === activeType;
                    const matchesPrice = Number(product.prix_reduit || 0) <= maxPrice;
                    const matchesUsb = !usbOnly || Boolean(product.usb);

                    return matchesSearch && matchesType && matchesPrice && matchesUsb;
                })
                .sort((a, b) => {
                    switch (sortFilter.value) {
                        case 'sales':
                            return Number(b.ventes || 0) - Number(a.ventes || 0);
                        case 'price-asc':
                            return Number(a.prix_reduit || 0) - Number(b.prix_reduit || 0);
                        case 'price-desc':
                            return Number(b.prix_reduit || 0) - Number(a.prix_reduit || 0);
                        case 'name':
                            return String(a.nom || '').localeCompare(String(b.nom || ''), 'fr');
                        default:
                            return Number(b.discount_percent || 0) - Number(a.discount_percent || 0)
                                || Number(b.ventes || 0) - Number(a.ventes || 0);
                    }
                });
        }

        function renderProducts(products) {
            grid.innerHTML = '';
            noResults.classList.toggle('hidden', products.length > 0);
            resultsCount.textContent = `${products.length} produit${products.length > 1 ? 's' : ''} trouvé${products.length > 1 ? 's' : ''}`;

            const fragment = document.createDocumentFragment();
            products.forEach(product => fragment.appendChild(createProductCard(product)));
            grid.appendChild(fragment);

            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        }

        function createProductCard(product) {
            const card = document.createElement('article');
            const isSelected = selectedIds.has(String(product.id));
            card.className = 'group flex h-full flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-xl dark:border-gray-700 dark:bg-dark-card';
            card.innerHTML = `
                <div class="relative flex h-56 items-center justify-center overflow-hidden bg-white p-4 dark:bg-gray-900">
                    <img src="${product.image_url}" alt="" class="h-full w-full rounded-xl object-contain transition duration-500 group-hover:scale-105" loading="lazy" onerror="this.src='https://placehold.co/700x500?text=DigiTrove'">
                    <div class="absolute left-3 top-3 flex flex-col gap-2">
                        ${product.discount_percent ? `<span class="rounded-full bg-brand-orange px-3 py-1 text-xs font-extrabold text-white">-${product.discount_percent}%</span>` : ''}
                        <span class="type-label rounded-full bg-black/60 px-3 py-1 text-xs font-bold uppercase tracking-wide text-white"></span>
                    </div>
                    ${product.usb ? '<span class="absolute right-3 top-3 rounded-full bg-white/90 px-3 py-1 text-xs font-bold text-brand-orange shadow">USB</span>' : ''}
                </div>
                <div class="flex flex-1 flex-col p-5">
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="text-lg font-extrabold leading-snug text-gray-900 dark:text-white"></h2>
                        <button type="button" class="quick-view-btn shrink-0 rounded-full border border-gray-200 p-2 text-gray-500 transition hover:border-brand-orange hover:text-brand-orange dark:border-gray-700" aria-label="Voir les détails">
                            <i data-lucide="eye" class="h-4 w-4"></i>
                        </button>
                    </div>
                    <p class="product-summary mt-2 line-clamp-2 text-sm text-gray-500 dark:text-gray-400"></p>
                    <div class="mt-4 flex items-end justify-between gap-3">
                        <div>
                            <span class="block text-xs text-gray-400 line-through">${formatPrice(product.prix_original)}</span>
                            <span class="block text-2xl font-extrabold text-brand-orange">${formatPrice(product.prix_reduit)}</span>
                        </div>
                        <span class="rounded-full bg-brand-blue/10 px-3 py-1 text-xs font-bold text-brand-blue">${formatter.format(Number(product.ventes) || 0)} ventes</span>
                    </div>
                    <div class="mt-auto grid gap-2 pt-5">
                        <a href="${product.url || '#'}" target="_blank" rel="noopener" class="track-click inline-flex items-center justify-center gap-2 rounded-xl bg-brand-blue px-4 py-3 text-sm font-bold text-white transition hover:bg-brand-orange" data-id="${product.id}">
                            <i data-lucide="shopping-cart" class="h-4 w-4"></i>
                            Acheter
                        </a>
                        <button type="button" class="select-product inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200 px-4 py-3 text-sm font-bold transition hover:border-brand-orange hover:text-brand-orange dark:border-gray-700 dark:text-gray-100" data-id="${product.id}">
                            <i data-lucide="${isSelected ? 'check' : 'plus'}" class="h-4 w-4"></i>
                            ${isSelected ? 'Dans la sélection' : 'Ajouter à ma sélection'}
                        </button>
                    </div>
                </div>
            `;

            card.querySelector('h2').textContent = product.nom || 'Produit DigiTrove';
            card.querySelector('.type-label').textContent = productTypeLabel(product.type);
            card.querySelector('.product-summary').textContent = productSummary(product);
            card.querySelector('.quick-view-btn').addEventListener('click', () => openQuickView(product));
            card.querySelector('.select-product').addEventListener('click', () => toggleSelection(product.id));
            return card;
        }

        function filterProducts() {
            priceValue.textContent = formatPrice(priceFilter.value);
            renderProducts(filteredProducts());
        }

        function resetAllFilters() {
            searchInput.value = '';
            sortFilter.value = 'recommended';
            priceFilter.value = initialMaxPrice;
            usbFilter.checked = false;
            setCategory('all');
        }

        function toggleSelection(id) {
            const key = String(id);
            if (selectedIds.has(key)) {
                selectedIds.delete(key);
            } else {
                selectedIds.add(key);
            }
            persistSelection();
            updateSelection();
            filterProducts();
        }

        function updateSelection() {
            const selectedProducts = [...selectedIds].map(getProduct).filter(Boolean);
            const total = selectedProducts.reduce((sum, product) => sum + Number(product.prix_reduit || 0), 0);
            selectionCount.textContent = selectedProducts.length;
            selectionTotal.textContent = formatPrice(total);
            selectionItems.innerHTML = '';

            if (selectedProducts.length === 0) {
                selectionItems.innerHTML = `
                    <div class="rounded-2xl border border-dashed border-gray-300 p-8 text-center dark:border-gray-700">
                        <i data-lucide="shopping-bag" class="mx-auto h-10 w-10 text-gray-400"></i>
                        <p class="mt-3 font-bold text-gray-900 dark:text-white">Votre sélection est vide</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Ajoutez des produits depuis la boutique pour préparer votre commande.</p>
                    </div>
                `;
            } else {
                selectedProducts.forEach(product => {
                    const item = document.createElement('div');
                    item.className = 'mb-4 flex gap-3 rounded-2xl border border-gray-200 p-3 dark:border-gray-700';
                    item.innerHTML = `
                        <img src="${product.image_url}" alt="" class="h-20 w-20 rounded-xl bg-gray-100 object-contain dark:bg-gray-900">
                        <div class="min-w-0 flex-1">
                            <p class="line-clamp-2 font-bold text-gray-900 dark:text-white"></p>
                            <p class="mt-1 text-sm text-brand-orange font-extrabold">${formatPrice(product.prix_reduit)}</p>
                            <button type="button" class="remove-selection mt-2 text-xs font-bold text-gray-500 hover:text-red-500" data-id="${product.id}">Retirer</button>
                        </div>
                    `;
                    item.querySelector('p').textContent = product.nom || 'Produit DigiTrove';
                    item.querySelector('.remove-selection').addEventListener('click', () => toggleSelection(product.id));
                    selectionItems.appendChild(item);
                });
            }

            const message = selectedProducts.length
                ? `Bonjour DigiTrove, je veux commander :\n${selectedProducts.map(product => `- ${product.nom} (${formatPrice(product.prix_reduit)})`).join('\n')}\nTotal estimé : ${formatPrice(total)}`
                : 'Bonjour DigiTrove, je souhaite avoir des informations sur vos produits.';
            selectionWhatsapp.href = `https://wa.me/${whatsappPhone}?text=${encodeURIComponent(message)}`;

            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        }

        function openDrawer() {
            drawer.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }

        function closeDrawer() {
            drawer.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }

        function openQuickView(product) {
            activeProduct = product;
            document.getElementById('modal-image').src = product.image_url;
            document.getElementById('modal-image').alt = product.nom || 'Produit DigiTrove';
            document.getElementById('modal-type').textContent = productTypeLabel(product.type);
            document.getElementById('modal-title').textContent = product.nom || 'Produit DigiTrove';
            document.getElementById('modal-summary').textContent = productSummary(product);
            document.getElementById('modal-old-price').textContent = formatPrice(product.prix_original);
            document.getElementById('modal-price').textContent = formatPrice(product.prix_reduit);
            document.getElementById('modal-sales').textContent = formatter.format(Number(product.ventes) || 0);
            modalBuy.href = product.url || '#';
            modalBuy.dataset.id = product.id;

            const discount = document.getElementById('modal-discount');
            discount.classList.toggle('hidden', !product.discount_percent);
            discount.textContent = product.discount_percent ? `-${product.discount_percent}%` : '';
            document.getElementById('modal-usb').classList.toggle('hidden', !product.usb);

            const benefits = document.getElementById('modal-benefits');
            benefits.innerHTML = '';
            benefitsFor(product).forEach(benefit => {
                const item = document.createElement('li');
                item.className = 'flex gap-3';
                item.innerHTML = '<i data-lucide="check-circle-2" class="mt-0.5 h-5 w-5 shrink-0 text-brand-orange"></i><span></span>';
                item.querySelector('span').textContent = benefit;
                benefits.appendChild(item);
            });

            const selected = selectedIds.has(String(product.id));
            modalSelect.innerHTML = `<i data-lucide="${selected ? 'check' : 'plus'}" class="h-5 w-5"></i>${selected ? 'Déjà dans la sélection' : 'Ajouter à ma sélection'}`;
            quickView.classList.remove('hidden');
            quickView.classList.add('flex');
            document.body.classList.add('overflow-hidden');

            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        }

        function closeQuickView() {
            quickView.classList.add('hidden');
            quickView.classList.remove('flex');
            document.body.classList.remove('overflow-hidden');
        }

        function trackClick(id) {
            if (!id) return;
            const formData = new FormData();
            formData.append('id', id);

            if (navigator.sendBeacon) {
                navigator.sendBeacon(incrementUrl, formData);
                return;
            }

            fetch(incrementUrl, {
                method: 'POST',
                body: formData,
                keepalive: true,
            }).catch(() => {});
        }

        searchInput.addEventListener('input', filterProducts);
        sortFilter.addEventListener('change', filterProducts);
        priceFilter.addEventListener('input', filterProducts);
        usbFilter.addEventListener('change', filterProducts);
        resetFilters.addEventListener('click', resetAllFilters);
        selectionToggle.addEventListener('click', openDrawer);
        selectionClose.addEventListener('click', closeDrawer);
        modalClose.addEventListener('click', closeQuickView);
        modalSelect.addEventListener('click', () => {
            if (activeProduct) {
                toggleSelection(activeProduct.id);
                openQuickView(getProduct(activeProduct.id));
            }
        });

        categoryFilters.addEventListener('click', event => {
            const button = event.target.closest('[data-type]');
            if (button) {
                setCategory(button.dataset.type);
            }
        });

        document.addEventListener('click', event => {
            const trackButton = event.target.closest('.track-click');
            if (trackButton) {
                trackClick(trackButton.dataset.id);
            }
        });

        quickView.addEventListener('click', event => {
            if (event.target === quickView) {
                closeQuickView();
            }
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                closeQuickView();
                closeDrawer();
            }
        });

        const chipBase = 'border-gray-200 bg-white text-gray-600 hover:border-brand-orange hover:text-brand-orange dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
        const chipActive = 'border-brand-orange bg-brand-orange text-white shadow';
        function syncCategoryChips() {
            categoryFilters.querySelectorAll('.category-chip').forEach(button => {
                const isActive = button.dataset.type === activeType;
                button.className = `category-chip rounded-full border px-4 py-2 text-sm font-semibold transition ${isActive ? chipActive : chipBase}`;
            });
        }

        syncCategoryChips();
        updateSelection();
        filterProducts();
    });
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
