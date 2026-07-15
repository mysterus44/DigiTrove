<?php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/functions.php';

$page_title = "Politique de Livraison - DigiTrove";
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
            <span class="inline-block py-1 px-3 rounded-full bg-white/20 backdrop-blur text-sm font-semibold mb-6 border border-white/30">Expédition & Réception</span>
            <h1 class="text-4xl md:text-6xl font-extrabold leading-tight">Politique de Livraison</h1>
            <p class="text-lg text-gray-200 max-w-2xl mx-auto">
                Tout ce que vous devez savoir sur la réception de vos trésors numériques et physiques.
            </p>
        </div>
    </section>

    <section class="py-16 px-6 bg-light-bg dark:bg-dark-bg">
        <div class="container mx-auto max-w-4xl text-light-text-secondary dark:text-dark-text-secondary prose dark:prose-invert lg:prose-lg">
            <p class="mb-8 text-lg text-center">
                Chez <strong>DigiTrove</strong>, nous comprenons que vous souhaitez profiter de vos achats le plus rapidement possible. Nous avons optimisé nos processus pour vous offrir une expérience fluide, que ce soit pour un téléchargement ou un colis physique.
            </p>

            <div class="bg-white dark:bg-dark-card p-8 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-8">
                <div class="flex items-start mb-4">
                    <div class="w-10 h-10 rounded-full bg-brand-blue/10 text-brand-blue flex items-center justify-center mr-4 flex-shrink-0">
                        <i data-lucide="zap" class="w-5 h-5"></i>
                    </div>
                    <h3 class="text-xl font-bold text-light-text dark:text-dark-text mt-2">1. Produits Numériques (Téléchargement)</h3>
                </div>
                <div class="pl-14">
                    <p class="mb-2">Pour tous nos logiciels, e-books, formations et abonnements :</p>
                    <ul class="list-disc list-inside space-y-2">
                        <li><strong>Délai :</strong> Immédiat (Automatique).</li>
                        <li><strong>Méthode :</strong> Un lien de téléchargement sécurisé s'affiche sur la page de confirmation de commande et vous est envoyé simultanément par e-mail.</li>
                        <li><strong>Coût :</strong> Gratuit (0 FCFA).</li>
                    </ul>
                </div>
            </div>

            <div class="bg-white dark:bg-dark-card p-8 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-8">
                <div class="flex items-start mb-4">
                    <div class="w-10 h-10 rounded-full bg-brand-orange/10 text-brand-orange flex items-center justify-center mr-4 flex-shrink-0">
                        <i data-lucide="truck" class="w-5 h-5"></i>
                    </div>
                    <h3 class="text-xl font-bold text-light-text dark:text-dark-text mt-2">2. Produits Physiques (Clés USB)</h3>
                </div>
                <div class="pl-14">
                    <p class="mb-2">Pour les packs livrés sur support physique (Clé USB DigiTrove) :</p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm mt-4 border-collapse">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-600">
                                    <th class="py-2 font-semibold">Zone</th>
                                    <th class="py-2 font-semibold">Délai Estimé</th>
                                    <th class="py-2 font-semibold">Tarif</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-600 dark:text-gray-400">
                                <tr class="border-b border-gray-100 dark:border-gray-700">
                                    <td class="py-3">Abidjan</td>
                                    <td class="py-3">24h - 48h</td>
                                    <td class="py-3 text-green-600 font-bold">Gratuit</td>
                                </tr>
                                <tr class="border-b border-gray-100 dark:border-gray-700">
                                    <td class="py-3">Intérieur (Côte d'Ivoire)</td>
                                    <td class="py-3">2 - 4 Jours</td>
                                    <td class="py-3">1 000 FCFA</td>
                                </tr>
                                <tr>
                                    <td class="py-3">International (Sous-région)</td>
                                    <td class="py-3">7 - 15 Jours</td>
                                    <td class="py-3">3 000 FCFA</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-dark-card p-8 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-8">
                <div class="flex items-start mb-4">
                    <div class="w-10 h-10 rounded-full bg-purple-500/10 text-purple-500 flex items-center justify-center mr-4 flex-shrink-0">
                        <i data-lucide="map-pin" class="w-5 h-5"></i>
                    </div>
                    <h3 class="text-xl font-bold text-light-text dark:text-dark-text mt-2">3. Suivi de Commande</h3>
                </div>
                <div class="pl-14">
                    <p>
                        Dès l'expédition de votre commande physique, vous recevrez une notification par <strong>E-mail et WhatsApp</strong>. 
                        Notre service de livraison partenaire vous contactera par téléphone le jour de la livraison pour confirmer votre disponibilité.
                    </p>
                </div>
            </div>

            <div class="bg-white dark:bg-dark-card p-8 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="flex items-start mb-4">
                    <div class="w-10 h-10 rounded-full bg-red-500/10 text-red-500 flex items-center justify-center mr-4 flex-shrink-0">
                        <i data-lucide="alert-triangle" class="w-5 h-5"></i>
                    </div>
                    <h3 class="text-xl font-bold text-light-text dark:text-dark-text mt-2">4. Problèmes de Livraison</h3>
                </div>
                <div class="pl-14">
                    <p class="mb-4">
                        Si vous n'avez pas reçu votre lien de téléchargement après 15 minutes, ou si votre colis est en retard :
                    </p>
                    <ul class="list-disc list-inside space-y-1">
                        <li>Vérifiez votre dossier "Spams" ou "Indésirables".</li>
                        <li>Assurez-vous d'avoir saisi la bonne adresse e-mail/numéro.</li>
                        <li>
                            Contactez immédiatement notre support à <a href="mailto:<?php echo SITE_EMAIL; ?>" class="text-brand-orange hover:underline"><?php echo SITE_EMAIL; ?></a>.
                        </a>
                    </ul>
                </div>
            </div>

        </div>
    </section>

    <section id="newsletter" class="bg-white dark:bg-dark-card border-t border-gray-100 dark:border-gray-800 py-16 sm:py-20">
        <div class="container mx-auto px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center">
                <h2 class="text-3xl md:text-4xl font-extrabold text-light-text dark:text-dark-text tracking-tight">
                    Ne manquez aucune nouveauté
                </h2>
                <p class="mt-4 text-lg text-light-text-secondary dark:text-dark-text-secondary">
                    Rejoignez notre liste VIP pour des offres exclusives sur la livraison et les nouveaux produits.
                </p>

                <div class="mt-8 max-w-xl mx-auto relative">
                    <form id="newsletter-form" class="relative" novalidate>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i data-lucide="mail" class="text-gray-400 w-6 h-6"></i>
                            </div>
                            <input type="email" id="newsletter-email" name="email" required placeholder="Votre adresse e-mail"
                                class="w-full py-4 pl-12 pr-40 text-base text-light-text dark:text-dark-text bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-full shadow-sm focus:outline-none focus:ring-2 focus:ring-brand-orange transition duration-200 ease-in-out">
                            <button type="submit" id="newsletter-submit" class="absolute inset-y-0 right-0 flex items-center justify-center px-6 m-1.5 text-white font-bold bg-brand-orange rounded-full hover:bg-opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-orange transition-transform transform hover:scale-105 duration-300">
                                <span class="hidden sm:inline">S'inscrire</span>
                                <i data-lucide="send" class="w-5 h-5 sm:ml-2"></i>
                            </button>
                        </div>
                    </form>
                    <div id="newsletter-feedback" class="mt-4 text-sm min-h-[1.25rem]"></div>
                </div>
                <div id="newsletter-success-message" class="hidden mt-8 max-w-xl mx-auto p-6 bg-green-50 dark:bg-green-900/20 rounded-xl border border-green-100 dark:border-green-800">
                    <div class="flex flex-col items-center justify-center text-center text-green-700 dark:text-green-400">
                        <i data-lucide="check-circle" class="w-12 h-12 mb-4"></i>
                        <h3 class="text-xl font-bold">Inscription réussie !</h3>
                    </div>
                </div>
            </div>
        </div>
    </section>

</main>

<script>
    if (typeof lucide !== 'undefined') { lucide.createIcons(); }
    
    // Newsletter Logic (Identique aux autres pages)
    const newsletterForm = document.getElementById('newsletter-form');
    const emailInput = document.getElementById('newsletter-email');
    const submitButton = document.getElementById('newsletter-submit');
    const feedbackDiv = document.getElementById('newsletter-feedback');
    const successDiv = document.getElementById('newsletter-success-message');

    if (newsletterForm) {
        newsletterForm.addEventListener('submit', function(event) {
            event.preventDefault();
            const email = emailInput.value;
            feedbackDiv.innerHTML = '';

            if (!email || !/^\S+@\S+\.\S+$/.test(email)) {
                feedbackDiv.innerHTML = `<p class="text-red-600 dark:text-red-400 font-medium">Email invalide.</p>`;
                return;
            }

            submitButton.disabled = true;
            const originalBtnContent = submitButton.innerHTML;
            submitButton.innerHTML = `<i data-lucide="loader-2" class="animate-spin w-5 h-5 mx-auto"></i>`;
            lucide.createIcons();

            setTimeout(() => {
                newsletterForm.classList.add('hidden');
                successDiv.classList.remove('hidden');
            }, 1500);
        });
    }
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>