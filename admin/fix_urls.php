<?php
// admin/fix_urls.php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/functions.php';

// 1. Sécurité : Seul l'admin peut lancer ce script
if (!is_admin()) {
    redirect('admin/login.php');
}

$page_title = "Maintenance - Réparation des Liens";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Fixer les URLs Chariow</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-gray-200 p-10">
    <div class="max-w-2xl mx-auto bg-gray-800 p-8 rounded-xl shadow-lg border border-gray-700">
        <h1 class="text-2xl font-bold text-white mb-6">🔧 Réparateur de Liens Chariow</h1>

        <?php
        // 2. Chargement des produits
        $produits = get_json_data('produits.json');
        $count_updated = 0;
        $logs = [];

        foreach ($produits as $key => $p) {
            // On s'assure que le produit a un ID
            if (!isset($p['id'])) {
                $logs[] = "<span class='text-red-400'>Erreur: Produit sans ID trouvé (Index: $key). Ignoré.</span>";
                continue;
            }

            // Le lien cible théorique
            $targetUrl = "https://digitrove.mychariow.com/pd" . $p['id'];

            // Vérification : Est-ce que l'URL actuelle est différente ou absente ?
            if (!isset($p['url']) || $p['url'] !== $targetUrl) {
                $oldUrl = $p['url'] ?? 'Aucune';
                
                // Mise à jour
                $produits[$key]['url'] = $targetUrl;
                
                $logs[] = "<span class='text-yellow-400'>Mise à jour Produit ID {$p['id']} :</span> <span class='text-gray-400'>$oldUrl</span> ➔ <span class='text-green-400 font-bold'>$targetUrl</span>";
                $count_updated++;
            }
        }

        // 3. Sauvegarde si changements
        if ($count_updated > 0) {
            if (save_json_data('produits.json', $produits)) {
                echo "<div class='bg-green-900/50 border border-green-500 text-green-100 p-4 rounded-lg mb-6'>✅ Succès : <strong>$count_updated</strong> produits ont été mis à jour avec les nouveaux liens Chariow.</div>";
            } else {
                echo "<div class='bg-red-900/50 border border-red-500 text-red-100 p-4 rounded-lg mb-6'>❌ Erreur critique : Impossible d'écrire dans le fichier produits.json. Vérifiez les permissions.</div>";
            }
        } else {
            echo "<div class='bg-blue-900/50 border border-blue-500 text-blue-100 p-4 rounded-lg mb-6'>👌 Tout est en ordre. Aucun produit n'avait besoin de modification.</div>";
        }
        ?>

        <div class="bg-gray-900 p-4 rounded-lg border border-gray-700 h-64 overflow-y-auto font-mono text-sm">
            <?php 
            if (empty($logs)) {
                echo "<span class='text-gray-500'>Aucune modification nécessaire...</span>";
            } else {
                foreach ($logs as $log) echo $log . "<br>"; 
            }
            ?>
        </div>

        <div class="mt-8 text-center">
            <a href="dashboard.php" class="bg-orange-600 hover:bg-orange-700 text-white font-bold py-2 px-6 rounded-lg transition">
                Retour au Dashboard
            </a>
        </div>
    </div>
</body>
</html>
