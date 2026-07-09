<?php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/functions.php';

// Sécurité : Seul l'admin peut accéder
if (!is_admin()) redirect('login.php');

$pdo = get_db_connection();
$message = '';
$error = '';
$edit_mode = false;
$user_to_edit = [];

// --- 1. SUPPRESSION ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    // Protection : Impossible de se supprimer soi-même
    if ($id == $_SESSION['user_id']) {
        $error = "Vous ne pouvez pas supprimer votre propre compte !";
    } else {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        if ($stmt->execute([$id])) {
            header("Location: gestion-utilisateurs.php?msg=deleted");
            exit;
        }
    }
}

// --- 2. PRÉPARATION ÉDITION ---
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $user_to_edit = $stmt->fetch();
    if ($user_to_edit) {
        $edit_mode = true;
    }
}

// --- 3. TRAITEMENT DU FORMULAIRE (AJOUT / MAJ) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = strip_tags(trim($_POST['nom']));
    $prenom = strip_tags(trim($_POST['prenom']));
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $role = $_POST['role'];
    $password = $_POST['password']; // Peut être vide en édition
    
    // Validation de base
    if (!$nom || !$prenom || !$email) {
        $error = "Veuillez remplir tous les champs obligatoires.";
    } else {
        try {
            if ($edit_mode) {
                // --- MISE À JOUR ---
                $id = $_POST['user_id'];
                
                // Si un mot de passe est fourni, on le met à jour, sinon on garde l'ancien
                if (!empty($password)) {
                    if (strlen($password) < 8) throw new Exception("Le mot de passe doit faire 8 caractères min.");
                    $hashed_password = password_hash($password, PASSWORD_ARGON2ID);
                    $sql = "UPDATE users SET nom=?, prenom=?, email=?, role=?, mot_de_passe=? WHERE id=?";
                    $params = [$nom, $prenom, $email, $role, $hashed_password, $id];
                } else {
                    $sql = "UPDATE users SET nom=?, prenom=?, email=?, role=? WHERE id=?";
                    $params = [$nom, $prenom, $email, $role, $id];
                }
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $message = "Utilisateur mis à jour avec succès.";
                
            } else {
                // --- CRÉATION ---
                if (empty($password)) throw new Exception("Le mot de passe est obligatoire pour un nouveau compte.");
                if (strlen($password) < 8) throw new Exception("Le mot de passe doit faire 8 caractères min.");

                // Vérifier doublon email
                $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                $check->execute([$email]);
                if ($check->fetchColumn() > 0) throw new Exception("Cet email existe déjà.");

                $hashed_password = password_hash($password, PASSWORD_ARGON2ID);
                $stmt = $pdo->prepare("INSERT INTO users (nom, prenom, email, mot_de_passe, role, date_inscription) VALUES (?, ?, ?, ?, ?, datetime('now'))");
                $stmt->execute([$nom, $prenom, $email, $hashed_password, $role]);
                $message = "Utilisateur créé avec succès.";
            }
            
            // Reset après succès si ajout
            if (!$edit_mode) {
                $nom = $prenom = $email = ''; 
            } else {
                // Si édition, on rafraichit les données affichées ou on redirige
                header("Location: gestion-utilisateurs.php?msg=updated");
                exit;
            }

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Messages URL
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'deleted') $message = "Utilisateur supprimé.";
    if ($_GET['msg'] == 'updated') $message = "Utilisateur mis à jour.";
}
?>

<!DOCTYPE html>
<html lang="fr" class="dark">
<head>
    <meta charset="UTF-8">
    <title>Gestion Utilisateurs - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-gray-900 text-gray-200 p-6">

    <div class="max-w-5xl mx-auto">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-white">
                <?php echo $edit_mode ? 'Modifier l\'utilisateur' : 'Ajouter un Utilisateur'; ?>
            </h1>
            <a href="dashboard.php#users" class="text-gray-400 hover:text-white flex items-center transition">
                <i data-lucide="arrow-left" class="w-5 h-5 mr-2"></i> Retour Dashboard
            </a>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-900/30 border border-red-500 text-red-300 p-4 rounded-lg mb-6 flex items-center">
                <i data-lucide="alert-circle" class="w-5 h-5 mr-2"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="bg-green-900/30 border border-green-500 text-green-300 p-4 rounded-lg mb-6 flex items-center">
                <i data-lucide="check-circle" class="w-5 h-5 mr-2"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="bg-gray-800 p-8 rounded-xl shadow-lg border border-gray-700 mb-10">
            <form method="POST" class="space-y-6">
                <?php if ($edit_mode): ?>
                    <input type="hidden" name="user_id" value="<?php echo $user_to_edit['id']; ?>">
                <?php endif; ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Prénom</label>
                        <input type="text" name="prenom" value="<?php echo $edit_mode ? htmlspecialchars($user_to_edit['prenom']) : ''; ?>" required 
                               class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2.5 text-white focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Nom</label>
                        <input type="text" name="nom" value="<?php echo $edit_mode ? htmlspecialchars($user_to_edit['nom']) : ''; ?>" required 
                               class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2.5 text-white focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Email</label>
                        <input type="email" name="email" value="<?php echo $edit_mode ? htmlspecialchars($user_to_edit['email']) : ''; ?>" required 
                               class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2.5 text-white focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Rôle</label>
                        <select name="role" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2.5 text-white focus:ring-2 focus:ring-orange-500 outline-none">
                            <option value="user" <?php echo ($edit_mode && $user_to_edit['role'] === 'user') ? 'selected' : ''; ?>>Utilisateur / Client</option>
                            <option value="admin" <?php echo ($edit_mode && $user_to_edit['role'] === 'admin') ? 'selected' : ''; ?>>Administrateur</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-2">
                        <?php echo $edit_mode ? 'Nouveau Mot de passe (Laisser vide pour ne pas changer)' : 'Mot de passe'; ?>
                    </label>
                    <input type="password" name="password" <?php echo $edit_mode ? '' : 'required'; ?> minlength="8"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2.5 text-white focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none placeholder-gray-500"
                        placeholder="Minimum 8 caractères">
                </div>

                <div class="flex gap-4 pt-4">
                    <button type="submit" class="flex-1 bg-orange-600 hover:bg-orange-700 text-white font-bold py-3 rounded-lg transition transform hover:scale-[1.01]">
                        <?php echo $edit_mode ? 'Mettre à jour' : 'Créer l\'utilisateur'; ?>
                    </button>
                    <?php if ($edit_mode): ?>
                        <a href="gestion-utilisateurs.php" class="px-6 py-3 bg-gray-700 hover:bg-gray-600 text-white rounded-lg font-medium">Annuler</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <script>lucide.createIcons();</script>
</body>
</html>