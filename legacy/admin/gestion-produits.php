<?php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/functions.php';

// Sécurité
if (!is_admin()) {
    redirect('login.php');
}

$produits = get_json_data('produits.json');
$message = '';

// --- TRAITEMENT DU FORMULAIRE ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // 1. Gestion de l'image
    $imagePath = '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
        $file = $_FILES['image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        
        if (in_array($ext, $allowed) && $file['size'] <= 5000000) {
            // Nom unique : ex: 65a4b1c8-mon_image.jpg
            $filename = uniqid() . '-' . preg_replace('/[^a-z0-9\-_]/', '', strtolower(pathinfo($file['name'], PATHINFO_FILENAME))) . '.' . $ext;
            $target = UPLOADS_PATH . '/' . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $target)) {
                // On stocke le chemin relatif pour le JSON
                $imagePath = '../uploads/' . $filename; 
            } else {
                $message = '<div class="bg-red-100 text-red-700 p-3 rounded mb-4">Erreur lors de l\'upload de l\'image.</div>';
            }
        } else {
            $message = '<div class="bg-red-100 text-red-700 p-3 rounded mb-4">Format invalide ou image trop lourde (Max 5Mo).</div>';
        }
    }

    if ($imagePath || empty($_FILES['image']['name'])) { // Si image ok ou pas d'image (cas d'édition rare)
        
        // 2. Calcul du Nouvel ID
        // On cherche l'ID le plus élevé actuel et on ajoute 1
        $maxId = 0;
        foreach ($produits as $p) {
            if (isset($p['id']) && $p['id'] > $maxId) {
                $maxId = intval($p['id']);
            }
        }
        $newId = $maxId + 1;

        // 3. Génération Automatique du lien Chariow
        $chariowUrl = "https://digitrove.mychariow.com/pd" . $newId;

        // 4. Création du produit
        $nouveauProduit = [
            'id' => $newId,
            'nom' => trim($_POST['nom']),
            'prix_original' => (int)$_POST['prix_original'],
            'prix_reduit' => (int)$_POST['prix_reduit'],
            'ventes' => (int)$_POST['ventes'],
            'image' => $imagePath,
            'disponible' => isset($_POST['disponible']),
            'type' => $_POST['type'],
            'usb' => isset($_POST['usb']),
            'url' => $chariowUrl // <--- L'URL est scellée ici
        ];

        // 5. Sauvegarde
        $produits[] = $nouveauProduit;
        if (save_json_data('produits.json', $produits)) {
            $message = '<div class="bg-green-100 text-green-700 p-3 rounded mb-4">Produit ajouté avec succès ! (ID: '.$newId.')</div>';
        } else {
            $message = '<div class="bg-red-100 text-red-700 p-3 rounded mb-4">Erreur lors de l\'écriture dans le fichier JSON.</div>';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion Produits - DigiTrove Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-gray-900 text-gray-200 p-6">

    <div class="max-w-4xl mx-auto">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-white">Ajouter un Produit</h1>
            <a href="dashboard.php" class="text-gray-400 hover:text-white flex items-center">
                <i data-lucide="arrow-left" class="w-5 h-5 mr-2"></i> Retour Dashboard
            </a>
        </div>

        <?php echo $message; ?>

        <div class="bg-gray-800 p-8 rounded-xl shadow-lg border border-gray-700">
            <form method="POST" enctype="multipart/form-data" class="space-y-6">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Nom du Produit</label>
                        <input type="text" name="nom" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Type</label>
                        <select name="type" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-orange-500 outline-none">
                            <option value="formation">Formation</option>
                            <option value="livre">Livre / Ebook</option>
                            <option value="logiciel">Logiciel</option>
                            <option value="abonnement">Abonnement</option>
                            <option value="ressource">Ressource</option>
                            <option value="all">Autre</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Prix Original (Barré)</label>
                        <input type="number" name="prix_original" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-orange-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Prix Réduit (Vente)</label>
                        <input type="number" name="prix_reduit" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-orange-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Ventes (Fake counter)</label>
                        <input type="number" name="ventes" value="0" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-orange-500 outline-none">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-2">Image du Produit</label>
                    <div class="flex items-center justify-center w-full">
                        <label for="dropzone-file" class="flex flex-col items-center justify-center w-full h-32 border-2 border-gray-600 border-dashed rounded-lg cursor-pointer bg-gray-700 hover:bg-gray-600 transition">
                            <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                <i data-lucide="cloud-upload" class="w-8 h-8 text-gray-400 mb-2"></i>
                                <p class="text-sm text-gray-400">Cliquez pour uploader (JPG, PNG, WEBP)</p>
                            </div>
                            <input id="dropzone-file" name="image" type="file" class="hidden" accept="image/*" required />
                        </label>
                    </div>
                </div>

                <div class="flex gap-6">
                    <label class="flex items-center space-x-3 cursor-pointer">
                        <input type="checkbox" name="disponible" checked class="w-5 h-5 rounded bg-gray-700 border-gray-600 text-orange-500 focus:ring-orange-500">
                        <span class="text-gray-300">Disponible à la vente</span>
                    </label>
                    <label class="flex items-center space-x-3 cursor-pointer">
                        <input type="checkbox" name="usb" class="w-5 h-5 rounded bg-gray-700 border-gray-600 text-orange-500 focus:ring-orange-500">
                        <span class="text-gray-300">Version USB Disponible</span>
                    </label>
                </div>

                <div class="bg-gray-700/50 p-4 rounded-lg border border-gray-600">
                    <p class="text-sm text-gray-400 flex items-center">
                        <i data-lucide="link" class="w-4 h-4 mr-2"></i>
                        Lien Chariow généré automatiquement : <span class="text-orange-400 font-mono ml-2">https://digitrove.mychariow.com/pd{ID}</span>
                    </p>
                </div>

                <button type="submit" class="w-full bg-orange-600 hover:bg-orange-700 text-white font-bold py-3 px-4 rounded-lg transition transform hover:scale-[1.02]">
                    Ajouter le Produit
                </button>
            </form>
        </div>
    </div>

    <script>lucide.createIcons();</script>
</body>
</html>