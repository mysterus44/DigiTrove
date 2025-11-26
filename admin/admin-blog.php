<?php
session_start();

// --- SÉCURITÉ SIMPLE PAR MOT DE PASSE ---
$password = "DigiTroveAdmin2025!"; // CHANGEZ CE MOT DE PASSE
$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

if (isset($_POST['password'])) {
    if ($_POST['password'] === $password) {
        $_SESSION['loggedin'] = true;
        header('Location: admin-blog.php'); // Redirige pour nettoyer le POST
        exit();
    }
}

if (!$is_logged_in) {
    // Le formulaire de connexion reste le même
    echo '<!DOCTYPE html><html lang="fr"><head><title>Connexion Admin</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-gray-100 flex items-center justify-center h-screen"><div class="w-full max-w-xs"><form class="bg-white shadow-md rounded px-8 pt-6 pb-8 mb-4" method="post"><div class="mb-4"><label class="block text-gray-700 text-sm font-bold mb-2" for="password">Mot de passe</label><input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="password" type="password" name="password" placeholder="************"></div><div class="flex items-center justify-between"><button class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline" type="submit">Connexion</button></div></form></div></body></html>';
    exit();
}

$json_file_path = __DIR__ . '/../data/blog-articles.json';

// Fonction pour générer un slug propre
function createSlug($string) {
    $string = strtolower(trim($string));
    $string = preg_replace('/[^a-z0-9-]+/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    return $string;
}

// Gérer la soumission du formulaire (Ajout ou Édition)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'])) {
    $articles = json_decode(file_get_contents($json_file_path), true);
    $original_slug = $_POST['original_slug'] ?? null;

    if ($original_slug) { // --- C'EST UNE MISE À JOUR ---
        foreach ($articles as $index => $article) {
            if ($article['slug'] === $original_slug) {
                $articles[$index]['title'] = $_POST['title'];
                $articles[$index]['category'] = $_POST['category'];
                $articles[$index]['author'] = $_POST['author'];
                $articles[$index]['image'] = $_POST['image'];
                $articles[$index]['excerpt'] = $_POST['excerpt'];
                $articles[$index]['tags'] = $_POST['tags'];
                $articles[$index]['content'] = $_POST['content'];
                // On ne change pas le slug ou la date pour une édition
                break;
            }
        }
    } else { // --- C'EST UN NOUVEL ARTICLE ---
        $new_article = [
            "slug" => createSlug($_POST['title']),
            "title" => $_POST['title'],
            "category" => $_POST['category'],
            "date" => date("Y-m-d"),
            "author" => $_POST['author'],
            "image" => $_POST['image'],
            "excerpt" => $_POST['excerpt'],
            "tags" => $_POST['tags'],
            "content" => $_POST['content']
        ];
        array_unshift($articles, $new_article); // Ajoute au début
    }

    file_put_contents($json_file_path, json_encode($articles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    header('Location: admin-blog.php');
    exit();
}

// Gérer la suppression
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['slug'])) {
    $articles = json_decode(file_get_contents($json_file_path), true);
    $articles = array_filter($articles, fn($article) => $article['slug'] !== $_GET['slug']);
    file_put_contents($json_file_path, json_encode(array_values($articles), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    header('Location: admin-blog.php');
    exit();
}

// Pré-remplir le formulaire pour l'édition
$edit_article = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['slug'])) {
    $articles = json_decode(file_get_contents($json_file_path), true);
    foreach ($articles as $article) {
        if ($article['slug'] === $_GET['slug']) {
            $edit_article = $article;
            break;
        }
    }
}

// Lire les articles pour les afficher dans la liste
$articles = json_decode(file_get_contents($json_file_path), true);
usort($articles, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Admin - Gestion du Blog</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 font-sans">
    <div class="container mx-auto p-8">
        <h1 class="text-3xl font-bold mb-6 text-gray-800">Gestion du Blog DigiTrove</h1>

        <div class="bg-white p-6 rounded-lg shadow-md mb-8">
            <h2 class="text-2xl font-semibold mb-4"><?php echo $edit_article ? 'Éditer l\'article' : 'Ajouter un nouvel article'; ?></h2>
            <form method="post" action="admin-blog.php" class="space-y-4">
                <?php if ($edit_article): ?>
                    <input type="hidden" name="original_slug" value="<?php echo htmlspecialchars($edit_article['slug']); ?>">
                <?php endif; ?>
                
                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700">Titre</label>
                    <input type="text" name="title" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" value="<?php echo htmlspecialchars($edit_article['title'] ?? ''); ?>">
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="category" class="block text-sm font-medium text-gray-700">Catégorie</label>
                        <input type="text" name="category" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" value="<?php echo htmlspecialchars($edit_article['category'] ?? ''); ?>">
                    </div>
                    <div>
                        <label for="author" class="block text-sm font-medium text-gray-700">Auteur</label>
                        <input type="text" name="author" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" value="<?php echo htmlspecialchars($edit_article['author'] ?? ''); ?>">
                    </div>
                </div>
                <div>
                    <label for="image" class="block text-sm font-medium text-gray-700">URL de l'image de couverture</label>
                    <input type="url" name="image" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" value="<?php echo htmlspecialchars($edit_article['image'] ?? ''); ?>">
                </div>
                <div>
                    <label for="tags" class="block text-sm font-medium text-gray-700">Tags (séparés par des virgules)</label>
                    <input type="text" name="tags" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" value="<?php echo htmlspecialchars($edit_article['tags'] ?? ''); ?>">
                </div>
                <div>
                    <label for="excerpt" class="block text-sm font-medium text-gray-700">Extrait (Résumé)</label>
                    <textarea name="excerpt" rows="3" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"><?php echo htmlspecialchars($edit_article['excerpt'] ?? ''); ?></textarea>
                </div>
                <div>
                    <label for="content" class="block text-sm font-medium text-gray-700">Contenu complet (Markdown simple supporté : ## Titre)</label>
                    <textarea name="content" rows="10" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"><?php echo htmlspecialchars($edit_article['content'] ?? ''); ?></textarea>
                </div>
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700">
                    <?php echo $edit_article ? 'Mettre à jour l\'article' : 'Ajouter l\'article'; ?>
                </button>
                <?php if ($edit_article): ?>
                    <a href="admin-blog.php" class="ml-4 text-gray-600 hover:text-gray-800">Annuler l'édition</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="bg-white p-6 rounded-lg shadow-md">
            <h2 class="text-2xl font-semibold mb-4">Articles Existants</h2>
            <div class="space-y-3">
                <?php foreach($articles as $article): ?>
                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-md hover:bg-gray-100">
                        <div>
                            <strong class="text-gray-900"><?php echo htmlspecialchars($article['title']); ?></strong>
                            <span class="text-sm text-gray-500 ml-2">(<?php echo htmlspecialchars($article['category']); ?>)</span>
                        </div>
                        <div class="flex items-center space-x-4">
                            <a href="?action=edit&slug=<?php echo $article['slug']; ?>" class="text-blue-600 hover:text-blue-800 text-sm font-semibold">Éditer</a>
                            <a href="?action=delete&slug=<?php echo $article['slug']; ?>" onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet article ?')" class="text-red-600 hover:text-red-800 text-sm font-semibold">Supprimer</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>