<?php
// Vérifier si l'ID est passé dans l'URL
if (isset($_GET['id'])) {
    $id = $_GET['id'];

    // Charger les produits depuis le fichier JSON
    $file_path = '../data/produits.json'; // Chemin relatif vers le fichier JSON
    if (file_exists($file_path)) {
        $produits = json_decode(file_get_contents($file_path), true);
    } else {
        echo "Le fichier produits.json est introuvable.";
        exit();
    }

    // Trouver le produit à modifier
    foreach ($produits as $key => $produit) {
        if ($produit['id'] == $id) {
            $produitToEdit = $produit;
            break;
        }
    }

    // Si le formulaire est soumis, modifier le produit
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Récupérer les nouvelles informations du produit
        $produitToEdit['nom'] = $_POST['nom'];
        $produitToEdit['prix_original'] = $_POST['prix_original'];
        $produitToEdit['prix_reduit'] = $_POST['prix_reduit'];
        $produitToEdit['ventes'] = $_POST['ventes'];
        $produitToEdit['disponible'] = isset($_POST['disponible']) ? true : false;
        $produitToEdit['type'] = $_POST['type'];  // Ajouter le type
        $produitToEdit['usb'] = isset($_POST['usb']) ? true : false;  // Ajouter la disponibilité en clé USB

        // Vérifier si une nouvelle image est téléchargée
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            // Récupérer les informations du fichier téléchargé
            $imageTmpName = $_FILES['image']['tmp_name'];
            $imageName = $_FILES['image']['name'];
            $imagePath = '../uploads/' . $imageName;

            // Déplacer le fichier téléchargé dans le dossier uploads/
            if (move_uploaded_file($imageTmpName, $imagePath)) {
                // Si l'image est téléchargée avec succès, mettre à jour le chemin dans le produit
                $produitToEdit['image'] = $imagePath;
            } else {
                echo "Erreur lors du téléchargement de l'image.";
            }
        }

        // Sauvegarder les produits mis à jour dans le fichier JSON
        $produits[$key] = $produitToEdit;
        file_put_contents($file_path, json_encode($produits, JSON_PRETTY_PRINT));
        header('Location: gestion-produits.php');
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier le produit</title>

    <!-- Intégration de Bootstrap CSS -->
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background-color: #f8f9fa;
        }
        .container {
            margin:auto;
        }
        .form-group label {
            font-weight: bold;
        }
        .btn-custom {
            background-color: #007bff;
            color: white;
        }
        .btn-custom:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>

    <div class="container">
        <h1 class="text-center">Modifier le produit</h1>
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="nom">Nom du produit :</label>
                <input type="text" class="form-control" name="nom" value="<?= htmlspecialchars($produitToEdit['nom']); ?>" required>
            </div>

            <div class="form-group">
                <label for="prix_original">Prix original :</label>
                <input type="number" class="form-control" name="prix_original" value="<?= htmlspecialchars($produitToEdit['prix_original']); ?>" required>
            </div>

            <div class="form-group">
                <label for="prix_reduit">Prix réduit :</label>
                <input type="number" class="form-control" name="prix_reduit" value="<?= htmlspecialchars($produitToEdit['prix_reduit']); ?>">
            </div>

            <div class="form-group">
                <label for="ventes">Nombre de ventes :</label>
                <input type="number" class="form-control" name="ventes" value="<?= htmlspecialchars($produitToEdit['ventes']); ?>" required>
            </div>

            <div class="form-group">
                <label for="image">Télécharger une nouvelle image :</label>
                <input type="file" class="form-control" name="image" accept="image/*">
            </div>

            <div class="form-group">
                <label for="disponible">Disponible :</label>
                <input type="checkbox" name="disponible" <?= $produitToEdit['disponible'] ? 'checked' : ''; ?>>
            </div>

            <div class="form-group">
                <label for="type">Type de produit :</label>
                <select name="type" class="form-control" required>
                    <option value="all" <?= $produitToEdit['type'] == 'all' ? 'selected' : ''; ?>>Tous les types</option>
                    <option value="formation" <?= $produitToEdit['type'] == 'formation' ? 'selected' : ''; ?>>Formation</option>
                    <option value="livre" <?= $produitToEdit['type'] == 'livre' ? 'selected' : ''; ?>>Livre</option>
                    <option value="logiciel" <?= $produitToEdit['type'] == 'logiciel' ? 'selected' : ''; ?>>Logiciel</option>
                    <option value="abonnement" <?= $produitToEdit['type'] == 'abonnement' ? 'selected' : ''; ?>>Abonnement</option>
                    <option value="ressource" <?= $produitToEdit['type'] == 'ressource' ? 'selected' : ''; ?>>Ressource</option>
                </select>
            </div>

            <div class="form-group">
                <label for="usb">Disponible en clé USB :</label>
                <input type="checkbox" name="usb" <?= $produitToEdit['usb'] ? 'checked' : ''; ?>>
            </div>

            <button type="submit" class="btn btn-custom btn-block">Modifier le produit</button>
        </form>
    </div>

    <!-- Intégration de Bootstrap JS et dépendances -->
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

</body>
</html>
