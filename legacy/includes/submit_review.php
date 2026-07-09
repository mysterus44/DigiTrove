<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = strip_tags(trim($_POST['review_name']));
    // Ajout du champ profession
    $profession = strip_tags(trim($_POST['review_profession']));
    $note = (int)$_POST['review_rating'];
    $commentaire = strip_tags(trim($_POST['review_comment']));

    if ($nom && $note && $commentaire) {
        $avis = get_json_data('avis.json');
        
        // Nouvel avis avec profession
        $new_review = [
            'id' => uniqid(),
            'nom' => $nom,
            'profession' => $profession ?: 'Client', // Valeur par défaut si vide
            'note' => $note,
            'commentaire' => $commentaire,
            'date' => date('Y-m-d H:i:s')
        ];

        // Ajouter au début
        array_unshift($avis, $new_review);
        save_json_data('avis.json', $avis);

        // Redirection avec succès
        header("Location: ../page/index.php?review=success#avis-clients");
        exit;
    }
}

// En cas d'erreur
header("Location: ../page/index.php?review=error#avis-clients");
exit;
?>