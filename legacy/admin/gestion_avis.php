<?php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/functions.php';

if (!is_admin()) redirect('login.php');

$avis = get_json_data('avis.json');
$message = '';
$edit_mode = false;
$current_avis = [];

// --- SUPPRESSION ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = $_GET['id'];
    foreach ($avis as $key => $a) {
        if (isset($a['id']) && $a['id'] === $id) {
            unset($avis[$key]);
            break;
        }
    }
    $avis = array_values($avis);
    save_json_data('avis.json', $avis);
    redirect('gestion_avis.php');
}

// --- ÉDITION ---
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    foreach ($avis as $a) {
        if (isset($a['id']) && $a['id'] === $_GET['id']) {
            $current_avis = $a;
            $edit_mode = true;
            break;
        }
    }
}

// --- TRAITEMENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? uniqid();
    
    $new_data = [
        'id' => $id,
        'nom' => strip_tags(trim($_POST['nom'])),
        'profession' => strip_tags(trim($_POST['profession'])), // Nouveau champ
        'note' => (int)$_POST['note'],
        'commentaire' => strip_tags(trim($_POST['commentaire'])),
        'date' => date('Y-m-d H:i:s')
    ];

    if ($edit_mode) {
        foreach ($avis as $key => $a) {
            if (isset($a['id']) && $a['id'] === $id) {
                $avis[$key] = $new_data;
                break;
            }
        }
        $message = "Avis mis à jour !";
    } else {
        array_unshift($avis, $new_data);
        $message = "Avis ajouté !";
    }

    save_json_data('avis.json', $avis);
    if(!$edit_mode) {
        header("Location: gestion_avis.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="fr" class="dark">
<head>
    <meta charset="UTF-8">
    <title>Gestion Avis - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-gray-900 text-gray-200 p-6">

    <div class="max-w-4xl mx-auto">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-white">
                <?php echo $edit_mode ? 'Modifier l\'avis' : 'Ajouter un Avis'; ?>
            </h1>
            <a href="dashboard.php#reviews" class="text-gray-400 hover:text-white flex items-center transition">
                <i data-lucide="arrow-left" class="w-5 h-5 mr-2"></i> Retour Dashboard
            </a>
        </div>

        <?php if ($message): ?>
            <div class="bg-green-600/20 text-green-400 p-4 rounded-lg mb-6 border border-green-600/50">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="bg-gray-800 p-8 rounded-xl shadow-lg border border-gray-700">
            <form method="POST" class="space-y-6">
                <?php if ($edit_mode): ?>
                    <input type="hidden" name="id" value="<?php echo $current_avis['id']; ?>">
                <?php endif; ?>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Nom du Client</label>
                        <input type="text" name="nom" value="<?php echo $current_avis['nom'] ?? ''; ?>" required 
                               class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-orange-500 outline-none">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Profession</label>
                        <input type="text" name="profession" value="<?php echo $current_avis['profession'] ?? ''; ?>" placeholder="Ex: Entrepreneur" required 
                               class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-orange-500 outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Note</label>
                        <select name="note" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-orange-500 outline-none">
                            <?php for($i=5; $i>=1; $i--): ?>
                                <option value="<?php echo $i; ?>" <?php echo (isset($current_avis['note']) && $current_avis['note'] == $i) ? 'selected' : ''; ?>>
                                    <?php echo $i; ?> Étoiles
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-2">Commentaire</label>
                    <textarea name="commentaire" rows="4" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:ring-2 focus:ring-orange-500 outline-none"><?php echo $current_avis['commentaire'] ?? ''; ?></textarea>
                </div>

                <div class="flex gap-4 pt-2">
                    <button type="submit" class="flex-1 bg-orange-600 hover:bg-orange-700 text-white font-bold py-3 rounded-lg transition">
                        <?php echo $edit_mode ? 'Mettre à jour' : 'Ajouter l\'avis'; ?>
                    </button>
                    <?php if ($edit_mode): ?>
                        <a href="gestion_avis.php" class="px-6 py-3 bg-gray-700 hover:bg-gray-600 text-white rounded-lg">Annuler</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <script>lucide.createIcons();</script>
</body>
</html>