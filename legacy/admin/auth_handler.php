<?php
// admin/auth_handler.php

// 1. Initialisation sécurisée
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/functions.php';

header('Content-Type: application/json');

// 2. Protection Anti-Brute Force (Basique par session)
if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] >= 5) {
    // Si bloqué, vérifier si le temps est écoulé (15 minutes)
    if (time() - $_SESSION['last_attempt_time'] < 900) {
        $remaining = 900 - (time() - $_SESSION['last_attempt_time']);
        $minutes = ceil($remaining / 60);
        echo json_encode(['success' => false, 'message' => "Trop de tentatives. Réessayez dans $minutes minutes."]);
        exit;
    } else {
        // Reset après 15 min
        $_SESSION['login_attempts'] = 0;
    }
}

// Vérification de la méthode
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Requête invalide.']);
    exit;
}

// 3. Vérification CSRF (Si le token est envoyé)
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Session expirée, veuillez recharger la page.']);
    exit;
}

$pdo = get_db_connection();
$action = $_POST['action'] ?? '';

try {
    // ==========================
    // LOGIQUE DE CONNEXION
    // ==========================
    if ($action === 'login') {
        // Nettoyage des entrées
        $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
        $password = $_POST['password']; // On ne nettoie pas le mot de passe (caractères spéciaux autorisés)

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Format d'email invalide.");
        }

        // Récupération de l'utilisateur
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Vérification du mot de passe (Hash Argon2id)
        if ($user && password_verify($password, $user['mot_de_passe'])) {
            
            // --- SUCCÈS ---
            
            // 1. Protection Session Fixation (On génère un nouvel ID de session)
            session_regenerate_id(true);
            
            // 2. Reset des tentatives de piratage
            $_SESSION['login_attempts'] = 0;

            // 3. Stockage des infos en session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_nom'] = htmlspecialchars($user['prenom'] . ' ' . $user['nom']); // Anti-XSS
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['last_activity'] = time(); // Pour gérer l'expiration d'inactivité

            // 4. LOGIQUE DE REDIRECTION CIBLÉE
            if ($user['role'] === 'admin') {
                $redirect = 'dashboard.php';
            } else {
                $redirect = '../page/index.php'; // Les clients vont à la boutique
            }
            
            echo json_encode(['success' => true, 'redirect' => $redirect]);

        } else {
            // --- ÉCHEC ---
            
            // Incrémenter les tentatives
            $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
            $_SESSION['last_attempt_time'] = time();
            
            throw new Exception("Email ou mot de passe incorrect.");
        }
    } 
    
    // ==========================
    // LOGIQUE D'INSCRIPTION
    // ==========================
    elseif ($action === 'signup') {
        // Nettoyage strict
        $nom = strip_tags(trim($_POST['nom']));
        $prenom = strip_tags(trim($_POST['prenom']));
        $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'];
        $confirm = $_POST['confirm_password'];

        // Validations
        if (empty($nom) || empty($prenom) || empty($email) || empty($password)) {
            throw new Exception("Tous les champs sont obligatoires.");
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Email invalide.");
        }
        if ($password !== $confirm) {
            throw new Exception("Les mots de passe ne correspondent pas.");
        }
        if (strlen($password) < 8) {
            throw new Exception("Le mot de passe doit contenir au moins 8 caractères.");
        }

        // Vérifier doublon
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Cette adresse email est déjà utilisée.");
        }

        // Hachage fort (Argon2id est le standard actuel)
        $hashed_password = password_hash($password, PASSWORD_ARGON2ID);

        // Insertion (Rôle 'user' par défaut)
        $stmt = $pdo->prepare("INSERT INTO users (nom, prenom, email, mot_de_passe, role, date_inscription) VALUES (?, ?, ?, ?, 'user', datetime('now'))");
        
        if ($stmt->execute([$nom, $prenom, $email, $hashed_password])) {
            echo json_encode(['success' => true, 'message' => 'Compte créé avec succès ! Vous pouvez vous connecter.']);
        } else {
            throw new Exception("Erreur technique lors de l'inscription.");
        }
    } else {
        throw new Exception("Action non autorisée.");
    }

} catch (Exception $e) {
    // Simulation d'un délai pour empêcher le "Timing Attack" (on répond toujours dans le même temps approx)
    usleep(rand(100000, 300000)); 
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>