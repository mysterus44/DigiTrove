<?php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/functions.php';

$page_title = "Conditions Générales de Vente (CGV) - DigiTrove";
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
            <span class="inline-block py-1 px-3 rounded-full bg-white/20 backdrop-blur text-sm font-semibold mb-6 border border-white/30">Cadre Légal</span>
            <h1 class="text-4xl md:text-6xl font-extrabold leading-tight">Conditions Générales de Vente (CGV)</h1>
            <p class="text-lg text-gray-200 max-w-2xl mx-auto">
                Les règles qui régissent notre relation de confiance.
            </p>
        </div>
    </section>

    <section class="py-16 px-6 bg-light-bg dark:bg-dark-bg">
        <div class="container mx-auto max-w-4xl text-light-text-secondary dark:text-dark-text-secondary prose dark:prose-invert lg:prose-lg">
            
            <p class="text-center italic mb-12 text-sm">Dernière mise à jour : <?php echo date('d/m/Y'); ?></p>

            <div class="bg-white dark:bg-dark-card p-8 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 mb-8">
                <h2 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4">1. Objet</h2>
                <p>
                    Les présentes Conditions Générales de Vente (CGV) régissent les relations contractuelles entre <strong>DigiTrove</strong> (le "Vendeur") et toute personne physique ou morale (le "Client") souhaitant effectuer un achat via le site internet DigiTrove.
                </p>
                <p class="mt-2">
                    L'acquisition d'un produit à travers le présent site implique une acceptation sans réserve par le Client des présentes conditions de vente.
                </p>
            </div>

            <div class="space-y-12">
                <div>
                    <h2 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4 border-l-4 border-brand-orange pl-4">2. Produits et Services</h2>
                    <p>DigiTrove propose deux types de produits :</p>
                    <ul class="list-disc list-inside space-y-2 mt-2 ml-4">
                        <li><strong>Produits Numériques :</strong> Logiciels, e-books, packs de formation, ressources graphiques et abonnements. Ces produits sont livrés par voie électronique (téléchargement).</li>
                        <li><strong>Produits Physiques :</strong> Clés USB contenant des packs de données pré-chargés. Ces produits sont livrés par transporteur.</li>
                    </ul>
                    <p class="mt-2 text-sm italic">Les caractéristiques essentielles des produits sont présentées sur chaque fiche produit.</p>
                </div>

                <div>
                    <h2 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4 border-l-4 border-brand-blue pl-4">3. Tarifs</h2>
                    <p>
                        Les prix figurant sur le site sont indiqués en <strong>Francs CFA (XOF)</strong>. DigiTrove se réserve le droit de modifier ses prix à tout moment, étant toutefois entendu que le prix figurant au catalogue le jour de la commande sera le seul applicable au Client.
                    </p>
                </div>

                <div>
                    <h2 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4 border-l-4 border-brand-orange pl-4">4. Commandes et Paiement</h2>
                    <p>Le paiement est exigible immédiatement à la commande. Le Client peut régler sa commande par :</p>
                    <ul class="list-disc list-inside space-y-2 mt-2 ml-4">
                        <li><strong>Mobile Money :</strong> Orange Money, MTN Money, Wave, Moov Money (via notre partenaire de paiement sécurisé Chariow).</li>
                        <li><strong>Carte Bancaire :</strong> Visa, MasterCard.</li>
                    </ul>
                    <p class="mt-2">La commande ne sera validée qu'après confirmation du paiement par l'organisme bancaire.</p>
                </div>

                <div>
                    <h2 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4 border-l-4 border-brand-blue pl-4">5. Livraison</h2>
                    <ul class="list-disc list-inside space-y-2 mt-2 ml-4">
                        <li><strong>Numérique :</strong> Livraison immédiate et automatique par e-mail et sur la page de confirmation.</li>
                        <li><strong>Physique :</strong> Expédition sous 24h à 48h ouvrées à Abidjan, et selon les délais transporteur pour l'intérieur et l'international.</li>
                    </ul>
                    <p class="mt-2">Pour plus de détails, consultez notre <a href="politique-livraison.php" class="text-brand-orange hover:underline">Politique de Livraison</a>.</p>
                </div>

                <div>
                    <h2 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4 border-l-4 border-brand-orange pl-4">6. Rétractation et Remboursement</h2>
                    <p>
                        Conformément à la législation en vigueur sur les biens numériques, le droit de rétractation ne peut être exercé pour les contrats de fourniture d'un contenu numérique non fourni sur un support matériel dont l'exécution a commencé après accord préalable exprès du consommateur.
                    </p>
                    <p class="mt-2">
                        Pour les produits physiques, le Client dispose d'un délai de 14 jours. Voir notre <a href="retours.php" class="text-brand-orange hover:underline">Politique de Retours</a> pour les conditions complètes.
                    </p>
                </div>

                <div>
                    <h2 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4 border-l-4 border-brand-blue pl-4">7. Propriété Intellectuelle</h2>
                    <p>
                        Tous les éléments du site DigiTrove sont et restent la propriété intellectuelle et exclusive de DigiTrove. Nul n'est autorisé à reproduire, exploiter, rediffuser, ou utiliser à quelque titre que ce soit, même partiellement, des éléments du site.
                    </p>
                    <p class="mt-2">
                        L'achat d'un produit numérique confère une licence d'utilisation personnelle. Sauf mention contraire ("Droits de Revente"), toute diffusion publique ou revente est strictement interdite.
                    </p>
                </div>

                <div>
                    <h2 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4 border-l-4 border-brand-orange pl-4">8. Données Personnelles</h2>
                    <p>
                        DigiTrove s'engage à préserver la confidentialité des informations fournies par le Client. Elles ne seront utilisées que pour le traitement des commandes et la communication commerciale (si acceptée). Voir notre <a href="politique-confidentialite.php" class="text-brand-orange hover:underline">Politique de Confidentialité</a>.
                    </p>
                </div>

                 <div>
                    <h2 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4 border-l-4 border-brand-blue pl-4">9. Droit Applicable</h2>
                    <p>
                        Les présentes conditions sont soumises à la loi ivoirienne. En cas de litige, les tribunaux d'Abidjan seront seuls compétents.
                    </p>
                </div>
            </div>

        </div>
    </section>

    <section id="newsletter" class="bg-white dark:bg-dark-card border-t border-gray-100 dark:border-gray-800 py-16 sm:py-20">
        <div class="container mx-auto px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center">
                <h2 class="text-3xl md:text-4xl font-extrabold text-light-text dark:text-dark-text tracking-tight">
                    Faites partie de notre liste VIP Gratuitement!!!
                </h2>
                <p class="mt-4 text-lg text-light-text-secondary dark:text-dark-text-secondary">
                    Recevez des astuces exclusives, des codes promotionnels, des offres spéciales et les nouveautés produits avant tout le monde.
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
    // Initialisation des icônes Lucide
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }

    // Logique Newsletter (Standardisée)
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
            if(typeof lucide !== 'undefined') lucide.createIcons();

            setTimeout(() => {
                newsletterForm.classList.add('hidden');
                successDiv.classList.remove('hidden');
            }, 1500);
        });
    }
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>