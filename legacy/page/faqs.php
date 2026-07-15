<?php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/functions.php';

// Données des Questions / Réponses
$faqs = [
    [
        "question" => "Comment puis-je passer une commande sur DigiTrove ?",
        "reponse" => "C'est très simple ! Parcourez nos catégories, choisissez le pack ou le logiciel qui vous intéresse, et cliquez sur 'Commander'. Vous serez redirigé vers notre plateforme de paiement sécurisée (Chariow). Une fois le paiement validé, vous recevrez vos accès instantanément par e-mail."
    ],
    [
        "question" => "Quels modes de paiement acceptez-vous ?",
        "reponse" => "Nous acceptons les principaux moyens de paiement locaux et internationaux via Chariow : <strong>Orange Money, MTN Money, Moov Money, Wave</strong>, ainsi que les cartes bancaires (Visa, MasterCard)."
    ],
    [
        "question" => "Quand et comment vais-je recevoir ma commande ?",
        "reponse" => "Pour tous nos produits numériques (e-books, formations, logiciels), la livraison est <strong>immédiate et automatique</strong>. Dès validation, un lien de téléchargement s'affiche et vous est envoyé par e-mail. Pour les clés USB physiques, comptez 24h à 48h pour la livraison à Abidjan."
    ],
    [
        "question" => "Que faire si je ne suis pas satisfait de mon achat ?",
        "reponse" => "Votre satisfaction est notre priorité. Si vous rencontrez un problème technique (fichier corrompu, lien invalide), contactez notre support sur WhatsApp. Pour les produits numériques consommés, le remboursement n'est pas systématique mais nous étudions chaque cas. Consultez notre <a href='return-refund.php' class='text-brand-orange hover:underline'>Politique de Retours</a>."
    ],
    [
        "question" => "Les logiciels sont-ils compatibles Mac et Windows ?",
        "reponse" => "Cela dépend du logiciel. La majorité de nos outils (Pack Office, Adobe, etc.) sont disponibles pour Windows. La compatibilité Mac est toujours spécifiée dans le titre ou la description du produit. Merci de bien lire avant d'acheter."
    ],
    [
        "question" => "Comment fonctionnent les abonnements ?",
        "reponse" => "Nos abonnements (Canva Pro, Netflix, etc.) sont des accès partagés ou privés gérés par notre équipe. Après paiement, vous recevez les identifiants ou le lien d'invitation. Nous garantissons le fonctionnement durant toute la durée payée."
    ],
    [
        "question" => "Mes informations sont-elles en sécurité ?",
        "reponse" => "Absolument. Nous n'avons jamais accès à vos informations bancaires ou codes secrets. Tout passe par la passerelle sécurisée et cryptée de notre partenaire de paiement."
    ]
];

$page_title = "FAQ - Questions Fréquentes";
include INCLUDES_PATH . '/header.php';
include INCLUDES_PATH . '/navbar.php';
?>

<style>
    .faq-answer-content {
        display: grid;
        grid-template-rows: 0fr;
        transition: grid-template-rows 0.3s ease-out;
    }
    .faq-item[open] .faq-answer-content {
        grid-template-rows: 1fr;
    }
    
    /* Animation de la croix */
    .faq-icon-line { transition: transform 0.3s ease; }
    .faq-item[open] .faq-icon-vertical { transform: rotate(90deg); }

    .anime-bg {
        height: 300px;
    }
</style>

<main class="dark:bg-dark-bg overflow-hidden">
    
    <section class="relative py-20 text-center text-white overflow-hidden">
        <div class="anime-bg animated-gradient-bg absolute inset-0 opacity-90"></div>
        <div class="container mx-auto px-1 relative z-10">
            <span class="inline-block py-1 px-3 rounded-full bg-white/20 backdrop-blur text-sm font-semibold mb-6 border border-white/30">Centre d'aide</span>
            <h1 class="text-4xl md:text-6xl font-extrabold leading-tight">Foire Aux Questions</h1>
            <p class="text-lg text-gray-200 max-w-2xl mx-auto">
                Vous avez des questions ? Nous avons les réponses. Trouvez rapidement ce que vous cherchez.
            </p>
        </div>
    </section>

    <section class="py-16 px-6">
        <div class="container mx-auto max-w-4xl">
            <div class="space-y-4">
                <?php foreach ($faqs as $index => $faq): ?>
                    <details class="faq-item group bg-white dark:bg-dark-card rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                        <summary class="flex items-center justify-between p-6 cursor-pointer list-none select-none">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white group-hover:text-brand-orange transition-colors">
                                <?php echo $faq['question']; ?>
                            </h3>
                            
                            <div class="relative w-6 h-6 flex-shrink-0 ml-4 text-brand-orange">
                                <div class="absolute top-1/2 left-0 w-full h-0.5 bg-current transform -translate-y-1/2"></div>
                                <div class="faq-icon-vertical absolute top-0 left-1/2 w-0.5 h-full bg-current transform -translate-x-1/2"></div>
                            </div>
                        </summary>
                        
                        <div class="faq-answer-content border-t border-gray-100 dark:border-gray-700">
                            <div class="overflow-hidden">
                                <div class="p-6 pt-0 text-gray-600 dark:text-gray-300 leading-relaxed">
                                    <br>
                                    <?php echo $faq['reponse']; ?>
                                </div>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>

            <div class="mt-16 text-center">
                <p class="text-gray-600 dark:text-gray-400 mb-6">Vous ne trouvez pas votre réponse ?</p>
                <a href="https://wa.me/<?php echo str_replace(' ', '', SITE_PHONE); ?>" target="_blank" class="inline-flex items-center bg-green-500 hover:bg-green-600 text-white font-bold py-3 px-8 rounded-xl transition transform hover:scale-105 shadow-lg shadow-green-500/30">
                    <i class="fab fa-whatsapp text-xl mr-2"></i> Discuter sur WhatsApp
                </a>
            </div>
        </div>
    </section>

</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Animation fluide de l'accordéon
    const details = document.querySelectorAll("details.faq-item");

    details.forEach((targetDetail) => {
        targetDetail.addEventListener("click", () => {
            // Ferme les autres accordéons
            details.forEach((detail) => {
                if (detail !== targetDetail) {
                    detail.removeAttribute("open");
                }
            });
        });
    });
    
    if (typeof lucide !== 'undefined') { lucide.createIcons(); }
});
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>