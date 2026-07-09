<?php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/functions.php';

// Détruire la session
session_destroy();

// Rediriger vers login
redirect('admin/login.php');
?>