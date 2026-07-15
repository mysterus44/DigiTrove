<?php
require_once __DIR__ . '/../includes/config.php';
// Configuration de la page
$page_title = "À Propos - Notre Vision";
include INCLUDES_PATH . '/header.php';
include INCLUDES_PATH . '/navbar.php';
?>
<style>
    .anime-bg {
        height: 300px;
    }
</style>

<main class="dark:bg-dark-bg overflow-hidden">
    
    <section class="relative py-20 text-center text-white overflow-hidden">
        <div class="anime-bg animated-gradient-bg absolute inset-0 opacity-90"></div>
        <div class="container mx-auto px-1 relative z-10">
            <span class="inline-block py-1 px-3 rounded-full bg-white/20 backdrop-blur text-sm font-semibold mb-6">Notre Histoire</span>
            <h1 class="text-4xl md:text-6xl font-extrabold leading-tight ">Au cœur de l'innovation</h1>
            <p class="text-lg md:text-xl text-gray-200 mx-auto">
                DigiTrove est né d'une vision simple : rendre les outils numériques d'élite accessibles à tous les créateurs africains.
            </p>
        </div>
    </section>

    <section class="bg-white dark:bg-dark-bg" style="margin-top: 100px;">
        <div class="container mx-auto px-6 ">
            <div class="max-w-3xl mx-auto text-center">
                <h2 class="text-3xl font-bold text-gray-900 dark:text-white mb-6">Notre Mission</h2>
                <p class="text-lg text-gray-600 dark:text-gray-300 leading-relaxed">
                    Nous croyons que le talent est universel, mais les opportunités ne le sont pas. 
                    Chez <span class="text-brand-orange font-bold">DigiTrove</span>, nous comblons ce fossé en fournissant les logiciels, 
                    les formations et les ressources dont les entrepreneurs, étudiants et créatifs ont besoin pour bâtir leur empire numérique.
                </p>
            </div>
        </div>
    </section>

    <section class="py-20 bg-gray-50 dark:bg-dark-card">
        <div class="container mx-auto px-6">
            <div class="grid md:grid-cols-3 gap-12 text-center">
                <div class="p-6">
                    <div class="w-16 h-16 mx-auto bg-brand-orange/10 rounded-full flex items-center justify-center mb-6 text-brand-orange">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-8 h-8">
                            <path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"></path>
                            <path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"></path>
                            <path d="M9 12H4s.55-3.03 2-4c1.62-1.1 4-1 4-1s.38-2.38-1-4"></path>
                            <path d="M12 15v5s3.03-.55 4-2c1.1-1.62 1-4 1-4s2.38-.38 4 1"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Rapidité</h3>
                    <p class="text-gray-600 dark:text-gray-400">Livraison instantanée pour les produits numériques. Pas d'attente, commencez à créer tout de suite.</p>
                </div>

                <div class="p-6">
                    <div class="w-16 h-16 mx-auto bg-brand-blue/10 rounded-full flex items-center justify-center mb-6 text-brand-blue">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-8 h-8">
                            <path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.78 4.78 4 4 0 0 1-6.74 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.74Z"></path>
                            <path d="m9 12 2 2 4-4"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Qualité</h3>
                    <p class="text-gray-600 dark:text-gray-400">Chaque logiciel et formation est testé et validé par nos experts avant d'arriver dans notre catalogue.</p>
                </div>

                <div class="p-6">
                    <div class="w-16 h-16 mx-auto bg-purple-500/10 rounded-full flex items-center justify-center mb-6 text-purple-500">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-8 h-8">
                            <path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"></path>
                            <path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Accessibilité</h3>
                    <p class="text-gray-600 dark:text-gray-400">Des prix adaptés au marché local pour permettre à chacun de s'équiper comme un pro.</p>
                </div>
            </div>
        </div>
    </section>


    <section class="py-24 bg-white dark:bg-dark-bg">
        <div class="container mx-auto px-6">
            <h2 class="text-3xl md:text-4xl font-bold text-center text-gray-900 dark:text-white mb-16">Les Visages de DigiTrove</h2>
            
            <div class="space-y-20">
                <div class="flex flex-col md:flex-row items-center gap-10">
                    <div class="md:w-1/3">
                        <div class="relative group">
                            <div class="absolute -inset-1 bg-gradient-to-r from-brand-orange to-brand-blue rounded-2xl blur opacity-25 group-hover:opacity-75 transition duration-1000 group-hover:duration-200"></div>
                            <img src="../images/equipe/ceo3.png" alt="CEO" class="relative rounded-2xl shadow-xl w-full object-cover aspect-[3/4]" onerror="this.src='https://placehold.co/400x500?text=CEO'">
                        </div>
                    </div>
                    <div class="md:w-2/3 text-center md:text-left">
                        <h3 class="text-2xl font-bold text-brand-orange mb-1">Kouda Mohamed .B</h3>
                        <p class="text-sm font-semibold text-gray-500 uppercase tracking-widest mb-6">Fondateur & Visionnaire</p>
                        <blockquote class="text-xl text-gray-700 dark:text-gray-300 italic border-l-4 border-brand-blue pl-6">
                            "Je veux façonner un avenir où chaque africain peut entreprendre et innover librement, sans être bloqué par le manque d'outils."
                        </blockquote>
                    </div>
                </div>

                <div class="flex flex-col md:flex-row-reverse items-center gap-10">
                    <div class="md:w-1/3">
                        <img src="../images/equipe/cm.png" alt="CM" class="rounded-2xl shadow-xl w-full object-cover aspect-[3/4] grayscale hover:grayscale-0 transition duration-500" onerror="this.src='https://placehold.co/400x500?text=CM'">
                    </div>
                    <div class="md:w-2/3 text-center md:text-right">
                        <h3 class="text-2xl font-bold text-brand-orange mb-1">Konan Jean Albert</h3>
                        <p class="text-sm font-semibold text-gray-500 uppercase tracking-widest mb-6">Community Manager</p>
                        <p class="text-lg text-gray-600 dark:text-gray-400">
                            "Bâtir une communauté engagée, c'est créer un espace où l'innovation se partage. Je suis la voix de DigiTrove et l'oreille de nos clients."
                        </p>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 md:grid-cols-4 gap-6 pt-10 border-t border-gray-100 dark:border-gray-800">
                    <div class="text-center">
                        <img src="../images/equipe/marketing1.png" class="w-24 h-24 rounded-full mx-auto mb-3 object-cover border-2 border-brand-orange" onerror="this.src='https://placehold.co/100?text=MKT'">
                        <h4 class="font-bold text-gray-900 dark:text-white">Bamba Aliman</h4>
                        <p class="text-xs text-gray-500">Marketing Digital</p>
                    </div>
                    <div class="text-center">
                        <img src="../images/equipe/graphiste1.png" class="w-24 h-24 rounded-full mx-auto mb-3 object-cover border-2 border-brand-blue" onerror="this.src='https://placehold.co/100?text=DSN'">
                        <h4 class="font-bold text-gray-900 dark:text-white">Kouassi Christ</h4>
                        <p class="text-xs text-gray-500">Design Graphique</p>
                    </div>
                    <div class="text-center">
                        <img src="../images/equipe/consult.png" class="w-24 h-24 rounded-full mx-auto mb-3 object-cover border-2 border-brand-orange" onerror="this.src='https://placehold.co/100?text=CST'">
                        <h4 class="font-bold text-gray-900 dark:text-white">Diawara Seydou</h4>
                        <p class="text-xs text-gray-500">Consultant</p>
                    </div>
                    <div class="text-center">
                        <img src="../images/equipe/client.png" class="w-24 h-24 rounded-full mx-auto mb-3 object-cover border-2 border-brand-blue" onerror="this.src='https://placehold.co/100?text=SVC'">
                        <h4 class="font-bold text-gray-900 dark:text-white">Touré Awa</h4>
                        <p class="text-xs text-gray-500">Service Client</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

</main>

<?php include INCLUDES_PATH . '/footer.php'; ?>