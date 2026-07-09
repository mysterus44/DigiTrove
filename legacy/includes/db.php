<?php
// includes/db.php
require_once __DIR__ . '/config.php';

/**
 * 1. Connexion PDO à SQLite (Utilisateurs & Admin)
 */
function get_db_connection() {
    $db_path = DATA_PATH . '/users.sqlite';
    
    try {
        $pdo = new PDO('sqlite:' . $db_path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        // En production, on log l'erreur au lieu de l'afficher
        error_log("Erreur DB : " . $e->getMessage());
        die("Désolé, une erreur de connexion est survenue.");
    }
}

/**
 * 2. Gestion des données JSON (Produits, Blog, Avis)
 */

// Lire un fichier JSON et retourner un tableau
function get_json_data($filename) {
    $filepath = DATA_PATH . '/' . $filename;
    if (!file_exists($filepath)) {
        return [];
    }
    $json = file_get_contents($filepath);
    return json_decode($json, true) ?? [];
}

// Sauvegarder un tableau dans un fichier JSON
function save_json_data($filename, $data) {
    $filepath = DATA_PATH . '/' . $filename;
    // JSON_PRETTY_PRINT pour la lisibilité, LOCK_EX pour éviter les conflits d'écriture
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($filepath, $json, LOCK_EX);
}

// Récupérer un élément spécifique par son ID dans un fichier JSON
function get_item_by_id($filename, $id, $id_key = 'id') {
    $items = get_json_data($filename);
    foreach ($items as $item) {
        if (isset($item[$id_key]) && $item[$id_key] == $id) {
            return $item;
        }
    }
    return null;
}
?>