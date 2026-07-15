<?php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/functions.php';

$page_title = "Politique de Retours et Remboursements";
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
            <span class="inline-block py-1 px-3 rounded-full bg-white/20 backdrop-blur text-sm font-semibold mb-6 border border-white/30">Garantie & Confiance</span>
            <h1 class="text-3xl md:text-5xl font-extrabold leading-tight">Politique de Retours</h1>
            <p class="text-lg text-gray-200 max-w-2xl mx-auto">
                Nous nous engageons à votre satisfaction. Voici comment nous gérons les retours et les remboursements en toute transparence.
            </p>
        </div>
    </section>

    <section class="py-16 px-6">
        <div class="container mx-auto max-w-4xl">
            
            <div class="bg-white dark:bg-dark-card p-8 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 mb-8">
                <p class="text-gray-600 dark:text-gray-300 leading-relaxed">
                    Chez <strong>DigiTrove</strong>, nous faisons tout notre possible pour vous fournir des produits de haute qualité. Cependant, nous comprenons que des situations exceptionnelles peuvent survenir. Cette politique détaille vos droits et nos procédures de remboursement.
                </p>
            </div>

            <div class="flex flex-col md:flex-row gap-6 mb-8">
                <div>
                    <h3 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4 border-l-4 border-brand-orange pl-4">1. Produits Numériques (Téléchargements)</h3>
                    <div class="prose dark:prose-invert text-gray-600 dark:text-gray-400 text-sm leading-relaxed">
                        <p class="mb-2">En raison de la nature irrévocable des produits numériques (logiciels, e-books, formations en ligne), nous n'offrons généralement pas de remboursement une fois la commande validée et le lien de téléchargement envoyé, sauf dans les cas suivants :</p>
                        <ul class="list-disc list-inside space-y-1 ml-2">
                            <li>Le fichier téléchargé est corrompu ou défectueux et notre support n'a pas pu résoudre le problème.</li>
                            <li>Le produit ne correspond pas du tout à sa description (erreur majeure).</li>
                            <li>Vous avez acheté le même produit deux fois par erreur.</li>
                        </ul>
                        <p class="mt-2 italic text-brand-orange">Note : Un changement d'avis après téléchargement n'est pas un motif valable de remboursement pour les biens numériques.</p>
                    </div>
                </div>
            </div>

            <hr class="border-gray-200 dark:border-gray-700 my-8">

            <div class="flex flex-col md:flex-row gap-6 mb-8">
                <div>
                    <h3 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4 border-l-4 border-brand-blue pl-4">2. Produits Physiques (Clés USB)</h3>
                    <div class="prose dark:prose-invert text-gray-600 dark:text-gray-400 text-sm leading-relaxed">
                        <p class="mb-2">Pour les produits physiques livrés (Clés USB DigiTrove), vous bénéficiez d'un droit de rétractation.</p>
                        <ul class="list-disc list-inside space-y-1 ml-2">
                            <li><strong>Délai :</strong> Vous avez 14 jours après réception pour demander un retour.</li>
                            <li><strong>Condition :</strong> Le produit doit être retourné dans son état d'origine, non endommagé.</li>
                            <li><strong>Frais :</strong> Les frais de retour sont à la charge du client, sauf si le produit est arrivé défectueux (dans ce cas, nous prenons tout en charge).</li>
                        </ul>
                    </div>
                </div>
            </div>

            <hr class="border-gray-200 dark:border-gray-700 my-8">

            <div class="flex flex-col md:flex-row gap-6 mb-8">
                <div>
                    <h3 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4 border-l-4 border-brand-orange pl-4">3. Comment demander un remboursement ?</h3>
                    <div class="prose dark:prose-invert text-gray-600 dark:text-gray-400 text-sm leading-relaxed">
                        <p class="mb-4">Contactez simplement notre service client. Pour accélérer le traitement, merci de fournir :</p>
                        <ol class="list-decimal list-inside space-y-1 ml-2 mb-4">
                            <li>Votre numéro de commande.</li>
                            <li>L'adresse e-mail utilisée lors de l'achat.</li>
                            <li>La raison détaillée de la demande (avec captures d'écran si problème technique).</li>
                        </ol>
                        <a href="https://wa.me/<?php echo str_replace(' ', '', SITE_PHONE); ?>" target="_blank" class="inline-flex items-center text-brand-blue font-bold hover:underline">
                            Contacter le support sur WhatsApp <i data-lucide="arrow-right" class="ml-2 w-4 h-4"></i>
                        </a>
                    </div>
                </div>
            </div>

            <div class="bg-blue-50 dark:bg-blue-900/20 p-6 rounded-xl border border-blue-100 dark:border-blue-800/30">
                <h4 class="text-2xl font-bold text-light-text dark:text-dark-text mb-4 border-l-4 border-brand-blue pl-4">Délais de traitement
                </h4>
                <p class="text-sm text-blue-700 dark:text-blue-200">
                    Une fois votre demande approuvée, le remboursement est effectué immédiatement. Selon votre banque ou opérateur Mobile Money, les fonds peuvent mettre entre <strong>3 à 7 jours ouvrables</strong> pour apparaître sur votre compte.
                </p>
            </div>

        </div>
    </section>

</main>

<script>
    // Initialisation des icônes Lucide
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>