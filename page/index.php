<?php
// 1. Chargement des fondations
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/functions.php';

// 2. Logique de données
$produits = get_json_data('produits.json');
$avis = get_json_data('avis.json');

// Fallback si pas d'avis
if (empty($avis)) {
    $avis = [
        ['nom' => 'Marc D.', 'note' => 5, 'commentaire' => 'Service client impeccable et produits de qualité. Je recommande !'],
        ['nom' => 'Sophie T.', 'note' => 4, 'commentaire' => 'J\'ai reçu mon logiciel instantanément. Très satisfaite.'],
        ['nom' => 'Jean-Yves', 'note' => 5, 'commentaire' => 'Le pack formation est très complet. Merci DigiTrove.'],
        ['nom' => 'Amina K.', 'note' => 5, 'commentaire' => 'Une vraie mine d\'or pour les entrepreneurs.'],
        ['nom' => 'Paul B.', 'note' => 4, 'commentaire' => 'Très bon rapport qualité prix.']
    ];
}

// Trier les produits par nombre de ventes (décroissant)
usort($produits, fn($a, $b) => ($b['ventes'] ?? 0) - ($a['ventes'] ?? 0));
$meilleures_ventes = array_slice($produits, 0, 4);

// 3. Traitement du Formulaire de Contact
$msg_status = '';
$msg_text = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['review_rating'])) {
    $fullname = e(trim($_POST["fullname"]));
    $email = filter_var(trim($_POST["email"]), FILTER_SANITIZE_EMAIL);
    $whatsapp = e(trim($_POST["whatsapp"]));
    $message = e(trim($_POST["message"]));
    
    if (empty($fullname) || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($message)) {
        $msg_status = 'error';
        $msg_text = 'Veuillez remplir tous les champs correctement.';
    } else {
        $msg_status = 'success';
        $msg_text = 'Merci ! Votre message a été envoyé avec succès.';
    }
}

// 4. Configuration de la page
$page_title = "Accueil - Votre Trésor Numérique";
include INCLUDES_PATH . '/header.php';
include INCLUDES_PATH . '/navbar.php';
?>

<style>
    .swiper-slide { width: auto; }
    
    /* --- Boutons de Navigation Avis --- */
    .custom-swiper-button {
        position: absolute; top: 50%; transform: translateY(-50%); z-index: 30;
        width: 50px; height: 50px; border-radius: 50%;
        background: rgba(255, 255, 255, 0.1); backdrop-filter: blur(8px);
        border: 1px solid rgba(255, 255, 255, 0.2); color: white;
        display: flex; align-items: center; justify-content: center;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); cursor: pointer;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }
    .custom-swiper-button:hover {
        background: #F76507; border-color: #F76507;
        transform: translateY(-50%) scale(1.15);
        box-shadow: 0 8px 20px rgba(247, 101, 7, 0.3);
    }
    .swiper-button-prev-custom { left: 10px; }
    .swiper-button-next-custom { right: 10px; }
    @media (min-width: 768px) {
        .swiper-button-prev-custom { left: 40px; }
        .swiper-button-next-custom { right: 40px; }
    }

    /* --- Etoiles Formulaire --- */
    .rating { display: flex; flex-direction: row-reverse; justify-content: center; gap: 4px; }
    .rating input { display: none; }
    .rating label { cursor: pointer; width: 30px; height: 30px; color: #ddd; transition: color 0.2s; }
    .rating label:hover, .rating label:hover ~ label, .rating input:checked ~ label { color: #FBBF24; }
    .rating label svg { width: 100%; height: 100%; fill: currentColor; }
</style>

<main class="dark:bg-dark-bg overflow-hidden">
    
    <section class="relative h-[100vh] max-h-[620px] flex flex-col items-center justify-center text-center overflow-hidden">
        <video autoplay loop muted playsinline class="absolute inset-0 w-full h-full object-cover z-0">
            <source src="<?php echo asset('videos/video-banner.mp4'); ?>" type="video/mp4">
        </video>
        <div class="absolute inset-0 bg-black/50 z-10"></div>
        
        <div class="relative z-20 p-6 text-white">
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold leading-tight mb-4 h-24 md:h-36 flex items-center justify-center">
                <span id="typewriter-text"></span>
            </h1>
            <div class="flex flex-col sm:flex-row justify-center gap-5 mt-8">
                <a href="<?php echo url('page/boutique.php'); ?>" class="bg-brand-orange hover:opacity-90 text-white font-bold py-3 px-8 rounded-lg text-lg transform hover:scale-105 duration-300 shadow-lg">
                    Explorer la Boutique
                </a>
                <a href="<?php echo url('page/a-propos.php'); ?>" class="bg-white/10 border border-white/50 hover:bg-white/20 text-white font-bold py-3 px-8 rounded-lg text-lg transition-colors duration-300 backdrop-blur-sm">
                    Qui sommes-nous ?
                </a>
            </div>
        </div>
    </section>

    <section id="offres" class="py-20 sm:py-28 bg-light-bg dark:bg-dark-bg">
        <div class="container mx-auto px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-4xl font-bold text-brand-blue dark:text-brand-blue">Conçu pour votre Succès</h2>
                <p class="text-lg text-light-text-secondary dark:text-dark-text-secondary mt-2">Des ressources pour chaque étape de votre parcours.</p>
            </div>
            
            <div class="grid md:grid-cols-3 gap-8">
                <?php 
                $offres = [
                    ['img' => 'logiciel.png', 'title' => 'Logiciels', 'desc' => "Solutions performantes pour booster votre productivité. <br> La collection ultime de logiciels pour les créatifs, entrepreneurs et technophiles."],
                    ['img' => 'livres.png', 'title' => 'Livres & Audios', 'desc' => "Changez votre esprit. Changez vos finances. Changez votre vie. <br> Votre bibliothèque Numerique personnelle pour transformer votre vie, atteindre la liberté financière et bâtir votre empire."],
                    ['img' => 'formation.png', 'title' => 'Formations', 'desc' => 'Montez en compétence avec des cours créés par des experts.<br> Chaque pack est conçu pour offrir une expérience d’apprentissage optimale, avec un accès aux ressources exclusives.'],
                    ['img' => 'resourcebank.png', 'title' => 'Ressources', 'desc' => "La bibliothèque de ressources ultime pour arrêter de chercher et commencer à créer. <br>  Codes sources, templates, vidéos, logos... Tout ce dont vous avez besoin est ici."],
                    ['img' => 'tools.png', 'title' => 'Outils & Marketing', 'desc' => "Pour votre Marketing. Et pour vos Revenus. <br> Utilisez la puissance de nos outils premium d'automatisation, de scraping, d'analyse et de communication pour faire exploser la croissance de vos propres projets."],
                    ['img' => 'abonnement.png', 'title' => 'Abonnements Premium', 'desc' => "Canva Pro + ChatGPT Plus + 20 autres... Pour le prix d'un seul. <br> Passez à la vitesse supérieure avec notre Pack d'Abonnements Premium."],
                ];
                foreach($offres as $offre): ?>
                <div class="bg-light-card dark:bg-dark-card border border-gray-200 dark:border-gray-700 rounded-xl p-8 text-center hover:border-brand-orange dark:hover:border-brand-blue hover:-translate-y-2 transition-all duration-300 shadow-sm hover:shadow-md">
                    <img src="<?php echo asset('images/offres/' . $offre['img']); ?>" alt="<?php echo $offre['title']; ?>" class="w-24 h-24 mx-auto rounded-full mb-4 object-cover border-4 border-white dark:border-gray-700 shadow-md" onerror="this.src='https://placehold.co/100?text=Offre'">
                    <h3 class="text-xl font-bold text-light-text dark:text-dark-text mb-2"><?php echo $offre['title']; ?></h3>
                    <p class="text-light-text-secondary dark:text-dark-text-secondary"><?php echo $offre['desc']; ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section id="meilleures-ventes" class="py-20 bg-light-card dark:bg-dark-card">
        <div class="container mx-auto px-6 text-center">
            <h2 class="text-4xl md:text-5xl font-extrabold text-brand-orange mb-6">Les Meilleures Ventes</h2>
            <p class="text-lg text-light-text-secondary dark:text-dark-text-secondary mb-16">Les trésors préférés de nos clients.</p>
            
            <div id="products-grid" class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-8">
                <?php foreach ($meilleures_ventes as $produit): ?>
                    <div class="w-35 bg-white dark:bg-white/35 rounded-2xl shadow-lg overflow-hidden border border-gray-300 dark:border-white-700 hover:shadow-xl hover:-translate-y-1 transition-all duration-300 group flex flex-col text-left">
                        
                        <div class="relative h-90  overflow-hidden bg-white p-2 flex items-center justify-center">
                            <img src="<?php echo asset(str_replace('../', '', $produit['image'])); ?>" 
                                alt="<?php echo e($produit['nom']); ?>" 
                                class="w-full h-full rounded-xl object-contain transition-transform duration-500 group-hover:scale-110"
                                onerror="this.src='https://placehold.co/600x400?text=Image+Manquante'">
                            
                            <?php if (!empty($produit['usb'])): ?>
                                <div class="absolute top-3 right-3 bg-white/30 dark:bg-black/30 backdrop-blur px-3 py-1 rounded-full shadow-sm flex items-center gap-1">
                                    
                                    <span class="text-xs font-bold text-brand-orange">USB Dispo</span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($produit['type'])): ?>
                                <div class="absolute bottom-3 left-3 bg-black/30 backdrop-blur px-2 py-1 rounded text-xs text-white uppercase tracking-wide">
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
                                        <span class="block text-xs text-gray-400 dark:text-black line-through"><?php echo format_price($produit['prix_original']); ?></span>
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
            
            <div class="mt-16">
                <a href="<?php echo url('page/boutique.php'); ?>" class="inline-block bg-brand-orange hover:opacity-90 text-white font-bold py-3 px-8 rounded-lg text-lg transform hover:scale-105 duration-300 shadow-lg">
                    Voir toute la boutique
                </a>
            </div>
        </div>
    </section>

    <section id="avis-clients" class="py-20 relative overflow-hidden bg-gray-900">
        <div class="absolute inset-0 bg-fixed bg-cover bg-center z-0 opacity-30" style="background-image: url('<?php echo asset('images/offres/abonnement.jpg'); ?>');"></div>
        <div class="absolute inset-0 bg-gradient-to-b from-gray-900/80 via-gray-900/50 to-gray-900/80 z-10"></div>
        
        <div class="container mx-auto px-6 relative z-20">
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-4xl font-bold text-white">Ils nous font confiance</h2>
                <p class="mt-4 text-lg text-gray-300">Découvrez ce que nos clients pensent de nous.</p>
            </div>
            
            <div class="relative px-4 md:px-12 mb-16">
                
                <div class="swiper-button-prev-custom custom-swiper-button group">
                    <svg class="w-6 h-6 group-hover:-translate-x-1 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16l-4-4m0 0l4-4m-4 4h18"></path></svg>
                </div>
                <div class="swiper-button-next-custom custom-swiper-button group">
                    <svg class="w-6 h-6 group-hover:translate-x-1 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path></svg>
                </div>

                <div class="swiper w-full overflow-hidden pb-10">
                    <div class="swiper-wrapper">
                        <?php 
                        $display_avis = array_merge($avis, $avis, $avis); 
                        foreach ($display_avis as $item): 
                        ?>
                        <div class="swiper-slide w-80 md:w-96 p-4 h-auto">
                            <div class="bg-white dark:bg-dark-card p-8 rounded-2xl shadow-2xl h-full min-h-[380px] flex flex-col justify-between border border-gray-100 dark:border-gray-700 transform transition-transform hover:scale-105">
                                <div>
                                    <div class="flex text-yellow-400 mb-6">
                                        <?php for($i=0; $i<5; $i++) echo ($i < intval($item['note'])) ? '<i class="fas fa-star"></i>' : '<i class="far fa-star"></i>'; ?>
                                    </div>
                                    <div class="relative">
                                        <svg class="absolute -top-4 -left-4 w-8 h-8 text-gray-200 dark:text-gray-700 transform -scale-x-100" fill="currentColor" viewBox="0 0 32 32"><path d="M9.352 4C4.456 7.456 1 13.12 1 19.36c0 5.088 3.072 8.064 6.624 8.064 3.36 0 5.856-2.688 5.856-5.856 0-3.168-2.208-5.472-5.088-5.472-.576 0-1.344.096-1.536.192.48-3.264 3.552-7.104 6.624-9.024L9.352 4zm16.512 0c-4.8 3.456-8.256 9.12-8.256 15.36 0 5.088 3.072 8.064 6.624 8.064 3.264 0 5.856-2.688 5.856-5.856 0-3.168-2.304-5.472-5.184-5.472-.576 0-1.248.096-1.44.192.48-3.264 3.456-7.104 6.528-9.024L25.864 4z"></path></svg>
                                        <p class="text-gray-600 dark:text-gray-300 italic text-lg leading-relaxed break-words whitespace-normal relative z-10 pl-4" style="max-width: 30ch;">
                                            "<?php echo e($item['commentaire']); ?>"
                                        </p>
                                    </div>
                                </div>
                                <div class="mt-8 pt-6 border-t border-gray-100 dark:border-gray-700 flex items-center">
                                    <div class="w-12 h-12 rounded-full bg-brand-blue/10 text-brand-blue flex items-center justify-center font-bold text-xl mr-4 shrink-0">
                                        <?php echo strtoupper(substr($item['nom'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <p class="font-bold text-gray-900 dark:text-white text-base"><?php echo e($item['nom']); ?></p>
                                        <p class="text-xs text-gray-400"><?php echo e($item['profession'] ?? 'Client Vérifié'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="max-w-2xl mx-auto">
                <button id="toggle-review-form" class="w-full bg-white/10 hover:bg-white/20 text-white font-bold py-4 px-6 rounded-xl border border-white/30 backdrop-blur-sm transition flex items-center justify-center gap-2 mb-6">
                    <i data-lucide="message-circle-plus" class="w-5 h-5"></i>
                    Laisser un avis
                </button>

                <div id="review-form-container" class="hidden bg-white dark:bg-dark-card p-8 rounded-2xl shadow-2xl">
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-4 text-center">Votre avis compte pour nous</h3>
                    <form action="../includes/submit_review.php" method="POST" class="space-y-4">
                        <div class="text-center mb-4">
                            <div class="rating">
                                <input type="radio" name="review_rating" id="star5" value="5" required><label for="star5"><svg viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg></label>
                                <input type="radio" name="review_rating" id="star4" value="4"><label for="star4"><svg viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg></label>
                                <input type="radio" name="review_rating" id="star3" value="3"><label for="star3"><svg viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg></label>
                                <input type="radio" name="review_rating" id="star2" value="2"><label for="star2"><svg viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg></label>
                                <input type="radio" name="review_rating" id="star1" value="1"><label for="star1"><svg viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg></label>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Cliquez sur une étoile pour noter</p>
                        </div>

                        <div class="grid md:grid-cols-2 gap-4">
                            <input type="text" name="review_name" placeholder="Votre Nom" required class="w-full px-4 py-3 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 focus:ring-2 focus:ring-brand-orange outline-none">
                            <input type="text" name="review_profession" placeholder="Votre Profession" required class="w-full px-4 py-3 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 focus:ring-2 focus:ring-brand-orange outline-none">
                        </div>
                        <div>
                            <textarea name="review_comment" rows="3" placeholder="Partagez votre expérience..." required class="w-full px-4 py-3 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 focus:ring-2 focus:ring-brand-orange outline-none"></textarea>
                        </div>
                        <button type="submit" class="w-full bg-brand-blue hover:bg-brand-orange text-white font-bold py-3 rounded-lg transition">Publier mon avis</button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <section id="contact" class="py-20 sm:py-28 bg-light-bg dark:bg-dark-bg">
        <div class="container mx-auto px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-4xl font-bold text-brand-blue dark:text-brand-orange">Contactez-nous</h2>
                <p class="text-lg text-light-text-secondary dark:text-dark-text-secondary mt-2">Une question ? Une suggestion ? <br> N'hésitez pas à nous écrire.</p>
            </div>

            <div class="max-w-2xl mx-auto bg-light-card dark:bg-dark-card p-8 rounded-2xl shadow-lg border border-gray-300 dark:border-gray-700">
                <?php if ($msg_text): ?>
                    <div class="mb-6 p-4 rounded-lg text-center font-medium <?php echo $msg_status === 'success' ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-red-100 text-red-800 border border-red-200'; ?>">
                        <?php echo $msg_text; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="#contact" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <div class="grid md:grid-cols-2 gap-6">
                        <div><label class="block text-sm font-medium mb-2 text-light-text dark:text-dark-text">Nom complet</label><input type="text" name="fullname" required class="w-full px-4 py-3 rounded-lg bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 focus:ring-2 focus:ring-brand-orange focus:border-transparent outline-none transition-all"></div>
                        <div><label class="block text-sm font-medium mb-2 text-light-text dark:text-dark-text">E-mail</label><input type="email" name="email" required class="w-full px-4 py-3 rounded-lg bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 focus:ring-2 focus:ring-brand-orange focus:border-transparent outline-none transition-all"></div>
                    </div>
                    <div><label class="block text-sm font-medium mb-2 text-light-text dark:text-dark-text">WhatsApp</label><input type="tel" name="whatsapp" required class="w-full px-4 py-3 rounded-lg bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 focus:ring-2 focus:ring-brand-orange focus:border-transparent outline-none transition-all"></div>
                    <div><label class="block text-sm font-medium mb-2 text-light-text dark:text-dark-text">Message</label><textarea name="message" rows="4" required class="w-full px-4 py-3 rounded-lg bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 focus:ring-2 focus:ring-brand-orange focus:border-transparent outline-none transition-all"></textarea></div>
                    <div class="flex items-start"><div class="flex items-center h-5"><input id="newsletter" name="newsletter" type="checkbox" class="w-4 h-4 border border-gray-300 rounded bg-gray-50 focus:ring-3 focus:ring-brand-orange dark:bg-gray-700 dark:border-gray-600 dark:focus:ring-brand-orange"></div><label for="newsletter" class="ml-3 text-sm font-medium text-gray-900 dark:text-gray-300">Je m'inscris à la newsletter.</label></div>
                    <button type="submit" class="w-full bg-brand-orange hover:opacity-90 text-white font-bold py-3 px-8 rounded-lg text-lg transition-transform transform hover:scale-105 duration-300 shadow-lg">Envoyer le message</button>
                </form>
            </div>
        </div>
    </section>
</main>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Toggle Formulaire Avis
        const toggleReviewBtn = document.getElementById('toggle-review-form');
        const reviewContainer = document.getElementById('review-form-container');
        if(toggleReviewBtn && reviewContainer) {
            toggleReviewBtn.addEventListener('click', () => {
                reviewContainer.classList.toggle('hidden');
                toggleReviewBtn.style.display = 'none'; 
            });
        }

        // Tracking Clics Produits
        const productGrid = document.getElementById('products-grid');
        const incrementUrl = '<?php echo url('admin/increment_sale.php'); ?>';
        if (productGrid) {
            productGrid.addEventListener('click', (e) => {
                const btn = e.target.closest('.track-click');
                if (btn) {
                    const id = btn.getAttribute('data-id');
                    const formData = new FormData();
                    formData.append('id', id);
                    navigator.sendBeacon(incrementUrl, formData);
                }
            });
        }

        // Typewriter
        const textElement = document.getElementById("typewriter-text");
        const texts = ["DigiTrove, Votre Trésor Numérique.", "Explorez l'Innovation.", "Développez Vos Compétences.", "Explosez Vos Revenus."];
        let textIndex = 0, charIndex = 0, isDeleting = false;

        function type() {
            const currentText = texts[textIndex];
            if (!textElement) return;
            if (isDeleting) { textElement.textContent = currentText.substring(0, charIndex - 1); charIndex--; } 
            else { textElement.textContent = currentText.substring(0, charIndex + 1); charIndex++; }
            let typeSpeed = isDeleting ? 20 : 40;
            if (!isDeleting && charIndex === currentText.length) { isDeleting = true; typeSpeed = 2000; } 
            else if (isDeleting && charIndex === 0) { isDeleting = false; textIndex = (textIndex + 1) % texts.length; typeSpeed = 500; }
            setTimeout(type, typeSpeed);
        }
        if(textElement) type();

        // Swiper Avis (Classe 'swiper' mise à jour)
        if (document.querySelector('.swiper')) {
            const swiper = new Swiper('.swiper', {
                loop: true, slidesPerView: 'auto', spaceBetween: 24, centeredSlides: true, speed: 500,
                autoplay: { delay: 1500, disableOnInteraction: false },
                navigation: { nextEl: '.swiper-button-next-custom', prevEl: '.swiper-button-prev-custom' },
                grabCursor: true, breakpoints: { 640: { centeredSlides: false } }
            });
            let resumeTimer;
            const pauseAndResume = () => {
                swiper.autoplay.stop();
                if (resumeTimer) clearTimeout(resumeTimer);
                resumeTimer = setTimeout(() => { swiper.autoplay.start(); }, 5000);
            };
            const prevBtn = document.querySelector('.swiper-button-prev-custom');
            const nextBtn = document.querySelector('.swiper-button-next-custom');
            if(prevBtn) prevBtn.addEventListener('click', pauseAndResume);
            if(nextBtn) nextBtn.addEventListener('click', pauseAndResume);
            swiper.on('touchStart', pauseAndResume);
        }

        if (typeof lucide !== 'undefined') { lucide.createIcons(); }
    });
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>