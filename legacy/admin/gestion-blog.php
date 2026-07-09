<?php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/functions.php';

// Sécurité
if (!is_admin()) redirect('login.php');

$articles = get_json_data('blog-articles.json');
$message = '';
$edit_mode = false;
$current_article = [];

// --- 1. SUPPRESSION ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['slug'])) {
    $slug = $_GET['slug'];
    foreach ($articles as $key => $a) {
        if ($a['slug'] === $slug) {
            unset($articles[$key]);
            break;
        }
    }
    // Réindexer et sauvegarder
    $articles = array_values($articles);
    save_json_data('blog-articles.json', $articles);
    redirect('gestion-blog.php');
}

// --- 2. PRÉPARATION ÉDITION ---
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['slug'])) {
    foreach ($articles as $a) {
        if ($a['slug'] === $_GET['slug']) {
            $current_article = $a;
            $edit_mode = true;
            break;
        }
    }
}

// --- 3. TRAITEMENT FORMULAIRE (AJOUT / MODIF) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Gestion Image (Upload ou Lien)
    $imagePath = $_POST['image_url'] ?? '';
    if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === 0) {
        $ext = pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION);
        $filename = uniqid('blog_') . '.' . $ext;
        if (move_uploaded_file($_FILES['image_file']['tmp_name'], UPLOADS_PATH . '/' . $filename)) {
            $imagePath = '../uploads/' . $filename;
        }
    }
    // Si pas de nouvelle image, on garde l'ancienne en édition
    if (empty($imagePath) && $edit_mode) {
        $imagePath = $current_article['image'];
    }

    // Création du Slug
    $slug = $_POST['slug'];
    if (empty($slug)) {
        // Générer slug depuis le titre
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $_POST['title'])));
    }

    $new_data = [
        'slug' => $slug,
        'title' => $_POST['title'],
        'category' => $_POST['category'],
        'date' => $_POST['date'] ?: date('Y-m-d'),
        'author' => $_POST['author'],
        'image' => $imagePath,
        'excerpt' => $_POST['excerpt'],
        'tags' => $_POST['tags'],
        'content' => $_POST['content']
    ];

    if ($edit_mode) {
        // Mise à jour
        foreach ($articles as $key => $a) {
            if ($a['slug'] === $current_article['slug']) {
                $articles[$key] = $new_data;
                break;
            }
        }
        $message = "Article mis à jour !";
    } else {
        // Ajout en haut de liste
        array_unshift($articles, $new_data);
        $message = "Article publié !";
    }

    save_json_data('blog-articles.json', $articles);
    // Recharger pour vider le POST
    if(!$edit_mode) {
        header("Location: gestion-blog.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="fr" class="dark">
<head>
    <meta charset="UTF-8">
    <title>Gestion Blog - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-gray-900 text-gray-200 p-6">

    <div class="max-w-6xl mx-auto">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-white">
                <?php echo $edit_mode ? 'Modifier l\'article' : 'Gestion du Blog'; ?>
            </h1>
            <a href="dashboard.php" class="text-gray-400 hover:text-white flex items-center transition">
                <i data-lucide="arrow-left" class="w-5 h-5 mr-2"></i> Dashboard
            </a>
        </div>

        <?php if ($message): ?>
            <div class="bg-green-600/20 text-green-400 p-4 rounded-lg mb-6 border border-green-600/50">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <div class="lg:col-span-2">
                <div class="bg-gray-800 p-6 rounded-xl border border-gray-700 shadow-lg">
                    <form method="POST" enctype="multipart/form-data" class="space-y-5">
                        
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Titre de l'article</label>
                            <input type="text" name="title" value="<?php echo $current_article['title'] ?? ''; ?>" required 
                                   class="w-full bg-gray-700 border-gray-600 rounded-lg px-4 py-2 focus:ring-2 focus:ring-orange-500 outline-none text-white">
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">Catégorie</label>
                                <input type="text" name="category" value="<?php echo $current_article['category'] ?? ''; ?>" required 
                                       class="w-full bg-gray-700 border-gray-600 rounded-lg px-4 py-2 focus:ring-2 focus:ring-orange-500 outline-none text-white">
                            </div>
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">Auteur</label>
                                <input type="text" name="author" value="<?php echo $current_article['author'] ?? $_SESSION['user_nom'] ?? 'Admin'; ?>" required 
                                       class="w-full bg-gray-700 border-gray-600 rounded-lg px-4 py-2 focus:ring-2 focus:ring-orange-500 outline-none text-white">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">Date (Optionnel)</label>
                                <input type="date" name="date" value="<?php echo $current_article['date'] ?? ''; ?>" 
                                       class="w-full bg-gray-700 border-gray-600 rounded-lg px-4 py-2 focus:ring-2 focus:ring-orange-500 outline-none text-white">
                            </div>
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">Slug (URL) - <em>Laisser vide pour auto</em></label>
                                <input type="text" name="slug" value="<?php echo $current_article['slug'] ?? ''; ?>" 
                                       class="w-full bg-gray-700 border-gray-600 rounded-lg px-4 py-2 focus:ring-2 focus:ring-orange-500 outline-none text-white text-sm">
                            </div>
                        </div>

                        <div class="bg-gray-700/30 p-4 rounded-lg border border-gray-600">
                            <label class="block text-sm text-orange-400 mb-2 font-bold">Image de couverture</label>
                            
                            <div class="mb-3">
                                <span class="text-xs text-gray-400">Option A : Lien externe (Unsplash, etc.)</span>
                                <input type="url" name="image_url" placeholder="https://..." value="<?php echo (strpos($current_article['image'] ?? '', 'http') === 0) ? $current_article['image'] : ''; ?>" 
                                       class="w-full bg-gray-700 border-gray-600 rounded px-3 py-1 text-sm mt-1 text-white">
                            </div>
                            
                            <div class="flex items-center">
                                <span class="text-xs text-gray-400 mr-2">Option B : Upload local</span>
                                <input type="file" name="image_file" accept="image/*" class="text-sm text-gray-400">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Extrait (Résumé court)</label>
                            <textarea name="excerpt" rows="2" required class="w-full bg-gray-700 border-gray-600 rounded-lg px-4 py-2 text-white text-sm"><?php echo $current_article['excerpt'] ?? ''; ?></textarea>
                        </div>

                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Contenu Complet (Markdown supporté : ## Titre, **Gras**)</label>
                            <textarea name="content" rows="10" required class="w-full bg-gray-700 border-gray-600 rounded-lg px-4 py-2 text-white font-mono text-sm"><?php echo $current_article['content'] ?? ''; ?></textarea>
                        </div>

                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Tags (séparés par des virgules)</label>
                            <input type="text" name="tags" value="<?php echo $current_article['tags'] ?? ''; ?>" 
                                   class="w-full bg-gray-700 border-gray-600 rounded-lg px-4 py-2 text-white text-sm">
                        </div>

                        <div class="flex gap-4 pt-4">
                            <button type="submit" class="flex-1 bg-orange-600 hover:bg-orange-700 text-white font-bold py-3 rounded-lg transition">
                                <?php echo $edit_mode ? 'Mettre à jour' : 'Publier l\'article'; ?>
                            </button>
                            <?php if ($edit_mode): ?>
                                <a href="gestion-blog.php" class="px-6 py-3 bg-gray-700 hover:bg-gray-600 text-white rounded-lg">Annuler</a>
                            <?php endif; ?>
                        </div>

                    </form>
                </div>
            </div>

            <div class="lg:col-span-1">
                <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-lg overflow-hidden sticky top-6">
                    <div class="p-4 bg-gray-700/50 border-b border-gray-700">
                        <h2 class="font-bold text-white">Articles Existants (<?php echo count($articles); ?>)</h2>
                    </div>
                    <div class="max-h-[80vh] overflow-y-auto p-2 space-y-2">
                        <?php foreach ($articles as $a): ?>
                            <div class="p-3 bg-gray-700/30 hover:bg-gray-700 rounded-lg transition group">
                                <h3 class="font-semibold text-sm text-gray-200 mb-1 line-clamp-1"><?php echo htmlspecialchars($a['title']); ?></h3>
                                <div class="flex justify-between items-center text-xs text-gray-500">
                                    <span><?php echo $a['date']; ?></span>
                                    <div class="flex gap-2 opacity-0 group-hover:opacity-100 transition">
                                        <a href="?action=edit&slug=<?php echo $a['slug']; ?>" class="text-blue-400 hover:text-blue-300">Éditer</a>
                                        <span class="text-gray-600">|</span>
                                        <a href="?action=delete&slug=<?php echo $a['slug']; ?>" onclick="return confirm('Supprimer cet article ?')" class="text-red-400 hover:text-red-300">Suppr.</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>lucide.createIcons();</script>
</body>
</html>