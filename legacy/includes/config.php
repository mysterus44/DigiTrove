<?php
// includes/config.php

// 1. Définition des chemins absolus (Pour les inclusions PHP)
define('ROOT_PATH', realpath(__DIR__ . '/../')); 
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('DATA_PATH', ROOT_PATH . '/data');
define('UPLOADS_PATH', ROOT_PATH . '/uploads');

// 2. Définition de l'URL de base (Pour les liens HTML/CSS/JS)
// Détection dynamique du protocole (http ou https)
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
// Détection dynamique du dossier racine (ex: localhost/DIGITROVE2)
$host = $_SERVER['HTTP_HOST'];
$script_dir = dirname(dirname($_SERVER['SCRIPT_NAME']));
// Nettoyage des slashes pour éviter les doubles //
$base_url = rtrim($protocol . '://' . $host . $script_dir, '/\\');

define('BASE_URL', $base_url);

// 3. Configuration du Site
define('SITE_NAME', 'DigiTrove');
define('SITE_EMAIL', 'contact@digitrove.com');
define('SITE_PHONE', '+225 07 58 42 14 83');

// 4. Démarrage de session sécurisé (si pas déjà démarrée)
if (session_status() === PHP_SESSION_NONE) {
    // Paramètres de sécurité des cookies de session
    session_set_cookie_params([
        'lifetime' => 86400, // 24 heures
        'path' => '/',
        'domain' => '', // Domaine courant
        'secure' => false, // Mettre à true si en HTTPS
        'httponly' => true, // Empêche l'accès JS au cookie (Anti-XSS)
        'samesite' => 'Lax'
    ]);
    session_start();
}

// 5. Gestion des erreurs (Afficher en dev, cacher en prod)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 6. Fonction utilitaire pour les URLs d'assets (images, css, js)
function asset($path) {
    return BASE_URL . '/' . ltrim($path, '/');
}

// 7. Fonction utilitaire pour les liens internes
function url($path) {
    return BASE_URL . '/' . ltrim($path, '/');
}
?>