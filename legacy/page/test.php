<?php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/functions.php';

// Données Blog
$articles = get_json_data('blog-articles.json');
usort($articles, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
$categories = array_unique(array_column($articles, 'category'));
sort($categories);

// Séparation
$featured = !empty($articles) ? $articles[0] : null;
$others = !empty($articles) ? array_slice($articles, 1) : [];

function get_img_src($path) {
    return (strpos($path, 'http') === 0) ? $path : asset(str_replace('../', '', $path));
}

$page_title = "Le Blog - Astuces et Stratégies";
include INCLUDES_PATH . '/header.php';
include INCLUDES_PATH . '/navbar.php';
?>
<style>
    .anime-bg {
        height: 300px;
    }
</style>
<main class="dark:bg-dark-bg min-h-screen">
    
    <section class="relative anime-bg bg-gray-900 text-center text-white overflow-hidden">
        <div class="animated-gradient-bg absolute inset-0 opacity-90"></div>
        <div class="container mt-20 mx-auto px-6 relative z-10">
            <h1 class="text-4xl md:text-6xl font-extrabold leading-tight mb-4">Le Blog DigiTrove</h1>
            <p class="text-lg text-gray-200 max-w-2xl mx-auto">Stratégies, Business, outils et inspirations.</p>
        </div>
    </section>

    <section class="sticky top-20 z-30 bg-white/90 dark:bg-dark-card/90 backdrop-blur border-b border-gray-200 dark:border-gray-700 py-4 shadow-sm">
        <div class="container mx-auto px-6">
            <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                <div class="flex space-x-2 overflow-x-auto no-scrollbar w-full md:w-auto pb-2 md:pb-0">
                    <button class="category-filter active px-4 py-2 rounded-full text-sm font-bold transition-all bg-brand-orange text-white whitespace-nowrap" data-category="all">Tout</button>
                    <?php foreach($categories as $cat): ?>
                    <button class="category-filter px-4 py-2 rounded-full text-sm font-bold transition-all bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 whitespace-nowrap" data-category="<?php echo e($cat); ?>">
                        <?php echo e($cat); ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div class="relative w-full md:w-64">
                    <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-4 h-4"></i>
                    <input type="text" id="blog-search" placeholder="Rechercher..." 
                        class="w-full pl-9 pr-4 py-2 rounded-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-600 focus:ring-2 focus:ring-brand-orange outline-none text-sm text-light-text dark:text-white transition">
                </div>
            </div>
        </div>
    </section>

    <section class="py-12 bg-gray-50 dark:bg-dark-bg">
        <div class="container mx-auto px-6">
            
            <div class="flex flex-col lg:flex-row gap-12">
                
                <div class="w-full lg:w-3/4">
                    <?php if (empty($articles)): ?>
                        <div class="text-center py-20"><p class="text-xl text-gray-500">Aucun article publié.</p></div>
                    <?php else: ?>

                        <?php if ($featured): ?>
                        <article class="article-card mb-12 group" data-category="<?php echo e($featured['category']); ?>" data-title="<?php echo strtolower(e($featured['title'])); ?>">
                            <a href="article.php?slug=<?php echo $featured['slug']; ?>" class="block md:flex bg-white dark:bg-dark-card rounded-2xl overflow-hidden shadow-lg hover:shadow-2xl transition-all duration-300 border border-gray-100 dark:border-gray-700">
                                <div class="md:w-2/3 relative overflow-hidden h-64 md:h-auto">
                                    <img src="<?php echo get_img_src($featured['image']); ?>" alt="<?php echo e($featured['title']); ?>" class="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover:scale-105">
                                </div>
                                <div class="md:w-1/3 p-6 flex flex-col justify-center">
                                    <span class="text-brand-orange font-bold text-xs uppercase tracking-wider mb-2"><?php echo e($featured['category']); ?></span>
                                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-3 leading-tight group-hover:text-brand-blue transition-colors"><?php echo e($featured['title']); ?></h2>
                                    <p class="text-gray-600 dark:text-gray-300 text-sm mb-4 line-clamp-3"><?php echo e($featured['excerpt']); ?></p>
                                    <div class="flex items-center text-xs text-gray-500 dark:text-gray-400">
                                        <span class="font-semibold"><?php echo e($featured['author']); ?></span>
                                        <span class="mx-2">•</span>
                                        <time><?php echo date("d M Y", strtotime($featured['date'])); ?></time>
                                    </div>
                                </div>
                            </a>
                        </article>
                        <?php endif; ?>

                        <div id="articles-grid" class="grid md:grid-cols-2 gap-8 auto-rows-fr">
                            <?php foreach($others as $article): ?>
                            <article class="article-card group h-full" data-category="<?php echo e($article['category']); ?>" data-title="<?php echo strtolower(e($article['title'])); ?>">
                                <a href="article.php?slug=<?php echo $article['slug']; ?>" class="flex flex-col h-full bg-white dark:bg-dark-card rounded-xl overflow-hidden shadow-md hover:shadow-xl transition-all duration-300 border border-gray-100 dark:border-gray-700">
                                    <div class="relative h-48 shrink-0 overflow-hidden">
                                        <img src="<?php echo get_img_src($article['image']); ?>" alt="<?php echo e($article['title']); ?>" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110">
                                        <div class="absolute top-3 left-3 bg-white/90 dark:bg-black/80 backdrop-blur px-2 py-1 rounded-md text-xs font-bold text-brand-orange">
                                            <?php echo e($article['category']); ?>
                                        </div>
                                    </div>
                                    <div class="p-5 flex flex-col flex-grow">
                                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2 line-clamp-2 group-hover:text-brand-blue transition-colors"><?php echo e($article['title']); ?></h3>
                                        <p class="text-gray-600 dark:text-gray-400 text-sm line-clamp-3 mb-4 flex-grow"><?php echo e($article['excerpt']); ?></p>
                                        <div class="pt-4 border-t border-gray-100 dark:border-gray-700 flex justify-between items-center text-xs text-gray-500 mt-auto">
                                            <span><?php echo e($article['author']); ?></span>
                                            <time><?php echo date("d M Y", strtotime($article['date'])); ?></time>
                                        </div>
                                    </div>
                                </a>
                            </article>
                            <?php endforeach; ?>
                        </div>
                        
                        <div id="no-search-results" class="hidden text-center py-12">
                            <p class="text-lg text-gray-500">Aucun article ne correspond à votre recherche.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <aside class="w-full lg:w-1/4 space-y-8">
                    
                    <div class="sticky top-40 bg-white dark:bg-dark-card p-4 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 text-center">
                        <span class="text-xs text-gray-400 uppercase tracking-widest mb-2 block">Publicité</span>
                        
                        <div class="w-full h-[600px] bg-gray-100 dark:bg-gray-800 rounded-lg flex flex-col items-center justify-center text-gray-400 text-sm">
                            <i data-lucide="monitor" class="w-8 h-8 mb-2 opacity-50"></i>
                            <span>Espace Pub<br>Large Skyscraper<br>300x600</span>
                        </div>
                        </div>

                </aside>

            </div>
        </div>
    </section>

    <?php include INCLUDES_PATH . '/promo-products.php'; ?>

</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const filters = document.querySelectorAll('.category-filter');
    const cards = document.querySelectorAll('.article-card');
    const searchInput = document.getElementById('blog-search');
    const noResults = document.getElementById('no-search-results');

    function filterArticles() {
        const activeBtn = document.querySelector('.category-filter.bg-brand-orange');
        const currentCategory = activeBtn ? activeBtn.dataset.category : 'all';
        const searchTerm = searchInput.value.toLowerCase().trim();
        let visibleCount = 0;

        cards.forEach(card => {
            const cardCat = card.dataset.category;
            const cardTitle = card.dataset.title;
            const matchCat = currentCategory === 'all' || cardCat === currentCategory;
            const matchSearch = cardTitle.includes(searchTerm);

            if (matchCat && matchSearch) {
                card.classList.remove('hidden');
                visibleCount++;
            } else {
                card.classList.add('hidden');
            }
        });

        if (noResults) noResults.classList.toggle('hidden', visibleCount > 0);
    }

    filters.forEach(btn => {
        btn.addEventListener('click', () => {
            filters.forEach(b => {
                b.classList.remove('bg-brand-orange', 'text-white');
                b.classList.add('bg-gray-100', 'dark:bg-gray-800', 'text-gray-600', 'dark:text-gray-300');
            });
            btn.classList.remove('bg-gray-100', 'dark:bg-gray-800', 'text-gray-600', 'dark:text-gray-300');
            btn.classList.add('bg-brand-orange', 'text-white');
            filterArticles();
        });
    });

    if (searchInput) searchInput.addEventListener('input', filterArticles);
    lucide.createIcons();
});
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>