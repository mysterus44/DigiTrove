<?php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/functions.php';

$slug = $_GET['slug'] ?? '';
$articles = get_json_data('blog-articles.json');
$article = null;

foreach ($articles as $a) {
    if ($a['slug'] === $slug) {
        $article = $a;
        break;
    }
}

if (!$article) redirect('blog.php');

function get_img_src($path) {
    return (strpos($path, 'http') === 0) ? $path : asset(str_replace('../', '', $path));
}

function parse_content($text) {
    $html = e($text);
    $html = nl2br($html);
    $html = preg_replace('/##\s*(.+)/', '<h2 class="text-2xl font-bold text-gray-900 dark:text-white mt-10 mb-4 pb-2 border-b border-gray-200 dark:border-gray-700">$1</h2>', $html);
    $html = preg_replace('/###\s*(.+)/', '<h3 class="text-xl font-bold text-gray-800 dark:text-gray-200 mt-8 mb-3 border-l-4 border-brand-orange pl-3">$1</h3>', $html);
    $html = preg_replace('/\*\*(.+?)\*\*/', '<strong class="text-brand-orange font-bold">$1</strong>', $html);
    $html = preg_replace('/^-\s*(.+)/m', '<li class="ml-6 list-disc marker:text-brand-orange mb-2">$1</li>', $html);
    $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" class="text-brand-blue hover:underline font-medium decoration-2 underline-offset-2" target="_blank">$1</a>', $html);
    return $html;
}

$page_title = $article['title'];
include INCLUDES_PATH . '/header.php';
include INCLUDES_PATH . '/navbar.php';
?>

<main class="dark:bg-dark-bg min-h-screen">

    <div class="relative h-[400px] w-full overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-t from-gray-900 to-transparent z-10"></div>
        <img src="<?php echo get_img_src($article['image']); ?>" class="w-full h-full object-cover" alt="<?php echo e($article['title']); ?>">
        <div class="absolute bottom-0 left-0 w-full z-20 p-6 md:p-12">
            <div class="container mx-auto">
                <a href="blog.php" class="inline-flex items-center text-white/80 hover:text-brand-orange mb-4 transition font-medium bg-white/10 border border-white/50 hover:bg-white/20 text-white font-bold py-3 px-8 rounded-lg text-lg transition-colors duration-300 backdrop-blur-sm">
                    <i data-lucide="arrow-left" class="w-4 h-4 mr-2 "></i> Retour au blog
                </a>
                <span class="block text-brand-orange font-bold uppercase tracking-widest mb-2 text-sm"><?php echo e($article['category']); ?></span>
                <h1 class="text-3xl md:text-5xl font-extrabold text-white leading-tight mb-4 max-w-4xl"><?php echo e($article['title']); ?></h1>
            </div>
        </div>
    </div>

    <div class="container mx-auto px-6 py-12">
        <div class="flex flex-col lg:flex-row gap-12">
            
            <article class="w-full lg:w-3/4">
                <div class="bg-white dark:bg-dark-card p-8 md:p-10 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700">
                    
                    <div class="flex items-center text-gray-500 dark:text-gray-400 mb-8 pb-8 border-b border-gray-100 dark:border-gray-700 text-sm">
                        <div class="flex items-center mr-6">
                            <div class="w-8 h-8 rounded-full bg-brand-blue/10 text-brand-blue flex items-center justify-center font-bold mr-2">
                                <?php echo substr($article['author'], 0, 1); ?>
                            </div>
                            <span><?php echo e($article['author']); ?></span>
                        </div>
                        <div class="flex items-center">
                            <i data-lucide="calendar" class="w-4 h-4 mr-2"></i>
                            <time><?php echo date("d F Y", strtotime($article['date'])); ?></time>
                        </div>
                    </div>

                    <div class="text-xl text-gray-600 dark:text-gray-300 font-medium leading-relaxed mb-10 italic">
                        <?php echo e($article['excerpt']); ?>
                    </div>

                    <div class="prose prose-lg dark:prose-invert max-w-none text-gray-700 dark:text-gray-300 leading-loose space-y-6">
                        <?php echo parse_content($article['content']); ?>
                    </div>

                    <?php if (!empty($article['tags'])): ?>
                    <div class="mt-12 pt-8 border-t border-gray-100 dark:border-gray-700">
                        <div class="flex flex-wrap gap-2">
                            <?php foreach(explode(',', $article['tags']) as $tag): ?>
                            <span class="px-3 py-1 bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 rounded-md text-sm hover:bg-brand-blue hover:text-white transition cursor-default">#<?php echo trim(e($tag)); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </article>

            <aside class="w-full lg:w-1/4 space-y-8">
                
                <div class="sticky top-24 bg-white dark:bg-dark-card p-4 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 text-center">
                    <span class="text-xs text-gray-400 uppercase tracking-widest mb-3 block text-left">Publicité</span>
                    
                    <div class="w-full h-[600px] bg-gray-100 dark:bg-gray-800 rounded-lg flex flex-col items-center justify-center text-gray-400 text-sm">
                        <i data-lucide="monitor" class="w-8 h-8 mb-2 opacity-50"></i>
                        <span>Espace Pub<br>300x600</span>
                    </div>
                    </div>

            </aside>

        </div>
    </div>

    <?php include INCLUDES_PATH . '/promo-products.php'; ?>

</main>

<script>lucide.createIcons();</script>
<?php include INCLUDES_PATH . '/footer.php'; ?>