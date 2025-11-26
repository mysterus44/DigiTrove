<?php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/functions.php';

// Récupération des produits
$products = get_json_data('produits.json');

// Configuration de la page
$page_title = "Boutique - Tous nos Packs et Logiciels";
include INCLUDES_PATH . '/header.php';
include INCLUDES_PATH . '/navbar.php';
?>
<style>
    .anime-bg {
        height: 300px;
    }
</style>
<main class="dark:bg-dark-bg min-h-screen bg-gray-50">
    
    <section class="relative anime-bg py-20 animated-gradient-bg  opacity-90">
        <div class="container m-auto text-center ">
            <h1 class="text-4xl md:text-5xl font-extrabold text-white mb-4">
            Notre <span class="text-brand-orange">Catalogue</span>
            </h1>
            <p class="text-lg text-white max-w-2xl mx-auto">
                Explorez notre sélection d'outils premium pour accélérer votre réussite numérique.
            </p>
        </div>
    </section>

    <div class="container mx-auto px-6 mt-9 mb-9">
        
        <div class="bg-white dark:bg-dark-card p-6 rounded-xl shadow-md mb-10 border border-gray dark:border-gray  top-24 z-30">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="relative">
                    <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-5 h-5"></i>
                    <input type="text" id="search-input" placeholder="Rechercher un produit..." 
                        class="w-full pl-10 pr-4 py-2 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-700  focus:ring-2 focus:ring-brand-orange outline-none transition text-light-text dark:text-white">
                </div>
                
                <div class="relative">
                    <i data-lucide="filter" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-5 h-5"></i>
                    <select id="type-filter" class="w-full pl-10 pr-4 py-2 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-700 dark:border-gray-700 focus:ring-2 focus:ring-brand-orange outline-none transition text-light-text dark:text-white appearance-none">
                        <option value="all">Toutes les catégories</option>
                        <option value="formation">Formations</option>
                        <option value="livre">Livres & Ebooks</option>
                        <option value="abonnement">Abonnements</option>
                        <option value="logiciel">Logiciels</option>
                        <option value="ressource">Ressources</option>
                    </select>
                </div>

                <div>
                    <div class="flex justify-between mb-2">
                        <label class="text-sm font-medium text-gray-600 dark:text-gray-400">Prix Max</label>
                        <span id="price-value" class="text-sm font-bold text-brand-orange">50 000 FCFA</span>
                    </div>
                    <input type="range" id="price-filter" min="0" max="50000" step="1000" value="50000" 
                        class="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-lg appearance-none cursor-pointer accent-brand-orange">
                </div>
            </div>
        </div>

        <div id="products-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8">
            </div>

        <div id="no-results" class="hidden text-center py-20">
            <div class="inline-block p-4 rounded-full bg-gray-100 dark:bg-gray-800 mb-4">
                <i data-lucide="search-x" class="w-12 h-12 text-gray-400"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-900 dark:text-white">Aucun résultat trouvé</h3>
            <p class="text-gray-500 dark:text-gray-400 mt-2">Essayez d'ajuster vos filtres.</p>
        </div>

    </div>
</main>

<script>
    // Données injectées depuis PHP
    const allProducts = <?php echo json_encode($products); ?>;
    const incrementUrl = '<?php echo url('admin/increment_sale.php'); ?>';

    document.addEventListener('DOMContentLoaded', () => {
        const grid = document.getElementById('products-grid');
        const searchInput = document.getElementById('search-input');
        const typeFilter = document.getElementById('type-filter');
        const priceFilter = document.getElementById('price-filter');
        const priceValue = document.getElementById('price-value');
        const noResults = document.getElementById('no-results');

        // Fonction de rendu
        function renderProducts(products) {
            grid.innerHTML = '';
            
            if (products.length === 0) {
                noResults.classList.remove('hidden');
                return;
            }
            noResults.classList.add('hidden');

            products.forEach(p => {
                // Nettoyage image path (supprime les ../)
                const imgSrc = p.image.replace(/^(\.\.\/)+/, ''); 
                // Formatage prix
                const price = new Intl.NumberFormat('fr-FR').format(p.prix_reduit);
                const oldPrice = new Intl.NumberFormat('fr-FR').format(p.prix_original);
                
                const card = document.createElement('div');
                // Classes identiques à l'index.php : text-left, flex flex-col, hover translate, etc.
                card.className = 'w-35 bg-white dark:bg-white/35 rounded-2xl shadow-lg overflow-hidden border border-gray-300 dark:border-white-700 hover:shadow-xl hover:-translate-y-1 transition-all duration-300 group flex flex-col text-left';
                
                card.innerHTML = `
                    <div class="relative h-90 overflow-hidden bg-white p-2 flex items-center justify-center">
                        <img src="../${imgSrc}" alt="${p.nom}" class="w-full h-full rounded-xl object-contain transition-transform duration-500 group-hover:scale-110" onerror="this.src='https://placehold.co/600x400?text=Image+Non+Trouvée'">
                        
                        ${p.usb ? `
                        <div class="absolute top-3 right-3 bg-white/30 dark:bg-black/30 backdrop-blur px-3 py-1 rounded-full shadow-sm flex items-center gap-1">
                            
                            <span class="text-xs font-bold text-brand-orange">USB Dispo</span>
                        </div>` : ''}

                        ${p.type ? `
                        <div class="absolute bottom-3 left-3 bg-black/30 backdrop-blur px-2 py-1 rounded text-xs text-white uppercase tracking-wide">
                            ${p.type}
                        </div>` : ''}
                    </div>
                    
                    <div class="p-5 flex flex-col flex-grow">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2 line-clamp-2" title="${p.nom}">${p.nom}</h3>
                        
                        <div class="mt-auto pt-4 border-t border-gray-100 dark:border-gray-700">
                            <div class="flex justify-between items-end mb-4">
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-black">Ventes: <span class="font-semibold text-brand-blue">${p.ventes}</span></p>
                                </div>
                                <div class="text-right">
                                    <span class="block text-xs text-gray-400 dark:text-black line-through">${oldPrice} F</span>
                                    <span class="block text-xl font-bold text-brand-orange">${price} F</span>
                                </div>
                            </div>
                            
                            <a href="${p.url || '#'}" target="_blank" 
                                class="track-click block w-full py-3 px-4 bg-brand-blue hover:bg-brand-orange text-white text-center font-semibold rounded-xl transition-colors duration-300 flex items-center justify-center gap-2"
                                data-id="${p.id}">
                                <span>Profiter de l'offre</span>
                                <i data-lucide="external-link" class="w-4 h-4"></i>
                            </a>
                        </div>
                    </div>
                `;
                grid.appendChild(card);
            });
            lucide.createIcons();
        }
        // Logique de filtrage
        function filterProducts() {
            const search = searchInput.value.toLowerCase();
            const type = typeFilter.value;
            const maxPrice = parseInt(priceFilter.value);

            const filtered = allProducts.filter(p => {
                const matchSearch = p.nom.toLowerCase().includes(search);
                const matchType = type === 'all' || p.type === type;
                // Nettoyage du prix pour la comparaison (enlève les espaces)
                const pPrice = parseInt(p.prix_reduit.toString().replace(/\s/g, ''));
                const matchPrice = pPrice <= maxPrice;

                return matchSearch && matchType && matchPrice;
            });

            renderProducts(filtered);
        }

        // Écouteurs d'événements Filtres
        searchInput.addEventListener('input', filterProducts);
        typeFilter.addEventListener('change', filterProducts);
        priceFilter.addEventListener('input', (e) => {
            priceValue.textContent = new Intl.NumberFormat('fr-FR').format(e.target.value) + ' FCFA';
            filterProducts();
        });

        // --- TRACKING DES CLICS (La magie Chariow) ---
        // On utilise la délégation d'événement sur la grille
        grid.addEventListener('click', (e) => {
            const btn = e.target.closest('.track-click');
            if (btn) {
                const id = btn.getAttribute('data-id');
                // Envoi silencieux des données (Beacon API)
                // Cela permet au navigateur d'envoyer la requête même si la page change
                const formData = new FormData();
                formData.append('id', id);
                navigator.sendBeacon(incrementUrl, formData);
                // Le lien s'ouvre normalement grâce au href="..."
            }
        });

        // Initialisation
        renderProducts(allProducts);
    });
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>