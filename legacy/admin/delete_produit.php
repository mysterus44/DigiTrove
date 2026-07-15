<?php
if (isset($_GET['id'])) {
    $id = $_GET['id'];
    $produits = json_decode(file_get_contents('../data/produits.json'), true);
    foreach ($produits as $key => $produit) {
        if ($produit['id'] == $id) {
            unset($produits[$key]);
            break;
        }
    }
    file_put_contents('../data/produits.json', json_encode(array_values($produits), JSON_PRETTY_PRINT));
}
header("Location: gestion-produits.php");
exit();
?>
