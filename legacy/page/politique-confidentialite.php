<?php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/functions.php';

$page_title = "Politique de Confidentialité - DigiTrove";
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
            <span class="inline-block py-1 px-3 rounded-full bg-white/20 backdrop-blur text-sm font-semibold mb-6 border border-white/30">Sécurité & Transparence</span>
            <h1 class="text-4xl md:text-6xl font-extrabold leading-tight">Politique de Confidentialité</h1>
            <p class="text-lg text-gray-200 max-w-2xl mx-auto">
                Votre confiance est notre priorité. Découvrez comment nous protégeons vos données personnelles.
            </p>
        </div>
    </section>

    <section class="py-16 px-6 bg-light-bg dark:bg-dark-bg">
        <div class="container mx-auto max-w-4xl text-light-text-secondary dark:text-dark-text-secondary prose dark:prose-invert lg:prose-lg">
            <p class="mb-8 text-lg">
                <strong>DigiTrove</strong> accorde une grande importance à la protection de vos données. Cette politique de confidentialité explique en détail comment nous collectons, utilisons et protégeons vos informations lorsque vous naviguez ou effectuez des achats sur notre site.
            </p>

            <h2 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4 mt-12 border-l-4 border-brand-orange pl-4">1. Collecte des informations</h2>
            <p>Nous collectons différentes catégories de données personnelles nécessaires au bon fonctionnement de nos services :</p>
            <ul class="list-disc list-inside space-y-2 mt-4 ml-4">
                <li>
                    <strong>Informations de contact :</strong> Votre nom, prénom, adresse e-mail et numéro de téléphone (pour le support WhatsApp).
                </li>
                <li>
                    <strong>Informations de commande :</strong> Détails des produits achetés et historique des transactions.
                </li>
                <li>
                    <strong>Informations de paiement :</strong> Lors de vos achats, nous utilisons des plateformes de paiement sécurisées (Chariow). Nous ne stockons <strong>jamais</strong> vos informations bancaires complètes sur nos serveurs.
                </li>
            </ul>

            <h2 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4 mt-12 border-l-4 border-brand-blue pl-4">2. Utilisation des informations</h2>
            <p>Les informations collectées sont utilisées exclusivement pour les finalités suivantes :</p>
            <ul class="list-disc list-inside space-y-2 mt-4 ml-4">
                <li>
                    <strong>Traitement des commandes :</strong> Pour vous livrer vos produits numériques instantanément ou expédier vos clés USB.
                </li>
                <li>
                    <strong>Service client :</strong> Pour répondre efficacement à vos questions, demandes de support ou réclamations.
                </li>
                <li>
                    <strong>Amélioration du service :</strong> Pour analyser l'utilisation du site et améliorer votre expérience utilisateur.
                </li>
                <li>
                    <strong>Marketing (Optionnel) :</strong> Uniquement avec votre consentement explicite (via la case à cocher newsletter), pour vous envoyer des offres promotionnelles et des actualités.
                </li>
            </ul>

            <h2 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4 mt-12 border-l-4 border-brand-orange pl-4">3. Partage des informations</h2>
            <p>Nous ne vendons, n'échangeons et ne louons jamais vos informations personnelles à des tiers. Vos données peuvent être partagées uniquement dans les cas suivants :</p>
            <ul class="list-disc list-inside space-y-2 mt-4 ml-4">
                <li>
                    Avec nos <strong>prestataires de services tiers de confiance</strong> (hébergeurs, processeurs de paiement) uniquement dans la mesure nécessaire pour réaliser leurs services.
                </li>
                <li>
                    Si la loi l'exige, pour se conformer à une procédure judiciaire ou protéger nos droits.
                </li>
            </ul>

            <h2 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4 mt-12 border-l-4 border-brand-blue pl-4">4. Protection des données</h2>
            <p>
                Nous mettons en œuvre une variété de mesures de sécurité pour préserver la sécurité de vos informations personnelles. Nous utilisons un cryptage à la pointe de la technologie (SSL) pour protéger les informations sensibles transmises en ligne. Seuls les employés qui ont besoin d’effectuer un travail spécifique (par exemple, la facturation ou le service client) ont accès aux informations personnelles identifiables.
            </p>

            <h2 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4 mt-12 border-l-4 border-brand-orange pl-4">5. Vos Droits</h2>
            <p>Conformément à la réglementation en vigueur, vous disposez des droits suivants concernant vos données :</p>
            <ul class="list-disc list-inside space-y-2 mt-4 ml-4">
                <li>Droit d'accès et de rectification de vos données.</li>
                <li>Droit à l'effacement (droit à l'oubli).</li>
                <li>Droit de retirer votre consentement marketing à tout moment.</li>
            </ul>

            <div class="mt-12 bg-blue-50 dark:bg-blue-900/20 p-6 rounded-xl border border-blue-100 dark:border-blue-800/30 text-center">
                <p class="text-blue-800 dark:text-blue-200 mb-4">
                    Pour toute question concernant notre politique de confidentialité ou pour exercer vos droits, veuillez nous contacter directement :
                </p>
                <a href="mailto:<?php echo SITE_EMAIL; ?>" class="inline-flex items-center text-brand-orange font-bold hover:underline text-lg">
                    <i data-lucide="mail" class="w-5 h-5 mr-2"></i> <?php echo SITE_EMAIL; ?>
                </a>
            </div>
        </div>
    </section>

    <section id="newsletter" class="bg-white dark:bg-dark-card border-t border-gray-100 dark:border-gray-800 py-16 sm:py-20">
        <div class="container mx-auto px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center">
                <h2 class="text-3xl md:text-4xl font-extrabold text-light-text dark:text-dark-text tracking-tight">
                    Restez informé
                </h2>
                <p class="mt-4 text-lg text-light-text-secondary dark:text-dark-text-secondary">
                    Recevez des astuces exclusives, des codes promotionnels et les nouveautés produits avant tout le monde.
                </p>

                <div class="mt-8 max-w-xl mx-auto relative">
                    <form id="newsletter-form" class="relative" novalidate>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i data-lucide="mail" class="text-gray-400 w-6 h-6"></i>
                            </div>
                            <input
                                type="email"
                                id="newsletter-email"
                                name="email"
                                required
                                placeholder="Votre adresse e-mail"
                                class="w-full py-4 pl-12 pr-40 text-base text-light-text dark:text-dark-text bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-full shadow-sm focus:outline-none focus:ring-2 focus:ring-brand-orange transition duration-200 ease-in-out"
                            >
                            <button 
                                type="submit" 
                                id="newsletter-submit"
                                class="absolute inset-y-0 right-0 flex items-center justify-center px-6 m-1.5 text-white font-bold bg-brand-orange rounded-full hover:bg-opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-orange transition-transform transform hover:scale-105 duration-300 disabled:opacity-70 disabled:cursor-not-allowed"
                            >
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
                        <p class="mt-2">Merci ! Surveillez votre boîte de réception pour nos prochaines pépites numériques.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

</main>

<script>
    // Initialisation des icônes Lucide
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }

    // Logique Newsletter (copiée pour que ce soit autonome sur cette page aussi)
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
                feedbackDiv.innerHTML = `<p class="text-red-600 dark:text-red-400 font-medium">Veuillez entrer une adresse e-mail valide.</p>`;
                return;
            }

            submitButton.disabled = true;
            const originalBtnContent = submitButton.innerHTML;
            submitButton.innerHTML = `<i data-lucide="loader-2" class="animate-spin w-5 h-5 mx-auto"></i>`;
            lucide.createIcons();

            setTimeout(() => {
                const isSuccess = true; // Simulation succès
                if (isSuccess) {
                    newsletterForm.classList.add('hidden');
                    successDiv.classList.remove('hidden');
                } else {
                    feedbackDiv.innerHTML = `<p class="text-red-600 dark:text-red-400 font-medium">Une erreur est survenue.</p>`;
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalBtnContent;
                }
            }, 1500);
        });
    }
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>