<?php
// Définit le type de contenu de la réponse comme étant du JSON
header('Content-Type: application/json');

// Chemin vers votre fichier de produits
$filePath = __DIR__ . '/../data/produits.json';

// Vérifie si la requête est bien de type POST et si l'ID est présent
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    // Si non, renvoie une erreur
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Requête invalide.']);
    exit;
}

$productId = $_POST['id'];
$products_json = @file_get_contents($filePath);
$products = $products_json ? json_decode($products_json, true) : [];

$productFound = false;
$newSalesCount = 0;

// Parcourt le tableau des produits pour trouver le bon
foreach ($products as $key => $product) {
    if (isset($product['id']) && (string)$product['id'] === (string)$productId) {
        // Incrémente le nombre de ventes (s'assure que c'est bien un nombre)
        $currentSales = isset($product['ventes']) ? intval($product['ventes']) : 0;
        $products[$key]['ventes'] = $currentSales + 1;
        
        $productFound = true;
        $newSalesCount = $products[$key]['ventes'];
        break; // Arrête la boucle une fois le produit trouvé
    }
}

// Si le produit a été trouvé et mis à jour
if ($productFound) {
    // Réécrit le fichier JSON avec les nouvelles données
    // LOCK_EX assure que personne d'autre n'écrit dans le fichier en même temps
    file_put_contents($filePath, json_encode($products, JSON_PRETTY_PRINT), LOCK_EX);
    
    // Renvoie une réponse de succès avec le nouveau compte de ventes
    echo json_encode(['success' => true, 'new_count' => $newSalesCount]);
} else {
    // Si le produit n'a pas été trouvé
    http_response_code(404); // Not Found
    echo json_encode(['success' => false, 'message' => 'Produit non trouvé.']);
}