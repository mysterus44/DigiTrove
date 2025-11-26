<?php
// includes/promo-products.php
// Ce module affiche les 4 produits les plus vendus

// 1. Récupération des produits
$produits = get_json_data('produits.json');
// Trier les produits par nombre de ventes (décroissant)
usort($produits, fn($a, $b) => ($b['ventes'] ?? 0) - ($a['ventes'] ?? 0));
$meilleures_ventes = array_slice($produits, 0, 4);

?>

<section id="meilleures-ventes" class="py-20 bg-light-card dark:bg-dark-card">
        <div class="container mx-auto px-6 text-center">
        <div class="text-center mb-10">
            <span class="text-brand-orange font-bold uppercase tracking-widest text-sm">Nos meilleures pépites</span>
            <h2 class="text-3xl font-extrabold text-light-text dark:text-white mt-2">Passez à l'action dès maintenant</h2>
        </div>

        <div class="w-30 h-90 gap-6">
            <div id="products-grid" class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-8">
                <?php foreach ($meilleures_ventes as $produit): ?>
                    <div class="bg-white dark:bg-white/35 rounded-2xl shadow-lg overflow-hidden border border-gray-300 dark:border-black-800 hover:shadow-xl hover:-translate-y-1 transition-all duration-300 group flex flex-col text-left">
                        
                        <div class="relative overflow-hidden bg-white p-2 flex items-center justify-center">
                            <img src="<?php echo asset(str_replace('../', '', $produit['image'])); ?>" 
                                alt="<?php echo e($produit['nom']); ?>" 
                                class="w-full h-full rounded-xl object-contain transition-transform duration-500 group-hover:scale-110"
                                onerror="this.src='https://placehold.co/600x400?text=Image+Manquante'">
                            
                            <?php if (!empty($produit['usb'])): ?>
                                <div class="absolute top-3 right-3 bg-white/30 dark:bg-black/30 backdrop-blur px-3 py-1 rounded-full shadow-sm flex items-center gap-1">
                                    <i data-lucide="usb" class="w-3 h-3 text-brand-orange"></i>
                                    <span class="text-xs font-bold text-brand-orange">USB Dispo</span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($produit['type'])): ?>
                                <div class="absolute bottom-3 left-3 bg-black/20 backdrop-blur px-2 py-1 rounded text-xs text-white uppercase tracking-wide">
                                    <?php echo e($produit['type']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="p-5 flex flex-col flex-grow">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2 line-clamp-2" title="<?php echo e($produit['nom']); ?>"><?php echo e($produit['nom']); ?></h3>
                            
                            <div class="mt-auto pt-4 border-t border-gray-100 dark:border-gray-700">
                                <div class="flex justify-between items-end mb-4">
                                    <div>
                                        <p class="text-xs text-gray-500 dark:text-black">Ventes: <span class="font-semibold text-brand-blue"><?php echo $produit['ventes']; ?></span></p>
                                    </div>
                                    <div class="text-right">
                                        <span class="block text-xs text-gray-400 line-through dark:text-black"><?php echo format_price($produit['prix_original']); ?></span>
                                        <span class="block text-xl font-bold text-brand-orange"><?php echo format_price($produit['prix_reduit']); ?></span>
                                    </div>
                                </div>
                                
                                <a href="<?php echo htmlspecialchars($produit['url'] ?? '#'); ?>" target="_blank" 
                                    class="track-click block w-full py-3 px-4 bg-brand-blue hover:bg-brand-orange text-white text-center font-semibold rounded-xl transition-colors duration-300 flex items-center justify-center gap-2"
                                    data-id="<?php echo $produit['id']; ?>">
                                    <span>Profiter de l'offre</span>
                                    <i data-lucide="external-link" class="w-4 h-4"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
        </div>
        
        <div class="mt-16">
                <a href="<?php echo url('page/boutique.php'); ?>" class="inline-block bg-brand-orange hover:opacity-90 text-white font-bold py-3 px-8 rounded-lg text-lg transform hover:scale-105 duration-300 shadow-lg">
                    Voir toute la boutique
                </a>
        </div>
    </div>
</section>