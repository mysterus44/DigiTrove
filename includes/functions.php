<?php
// includes/functions.php

/**
 * Formater un prix en FCFA
 * Ex: 5000 -> 5 000 FCFA
 */
function format_price($amount) {
    return number_format($amount, 0, ',', ' ') . ' FCFA';
}

/**
 * Sécuriser les données affichées (Anti-XSS)
 */
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Redirection sécurisée
 */
function redirect($path) {
    // Si le chemin commence par http, c'est une URL externe
    if (strpos($path, 'http') === 0) {
        header("Location: $path");
    } else {
        // Sinon c'est interne, on utilise l'URL de base
        header("Location: " . BASE_URL . '/' . ltrim($path, '/'));
    }
    exit;
}

/**
 * Vérifier si l'utilisateur est connecté
 */
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * Vérifier si l'utilisateur est admin
 */
function is_admin() {
    return is_logged_in() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

/**
 * Générer un Token CSRF (Sécurité formulaires)
 */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Input hidden pour le token CSRF
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}
?>