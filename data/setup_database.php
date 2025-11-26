<?php
// --- CONFIGURATION ---
// Chemin vers le fichier de la base de données SQLite
$db_path = __DIR__ . '/../data/users.sqlite';
// Email de l'administrateur par défaut
$admin_email = 'koudamohammedbouey@gmail.com'; 
// Mot de passe de l'administrateur par défaut (sera haché)
$admin_password = 'KingKouda86037221';

// --- SCRIPT DE CRÉATION ---
try {
    // Crée une nouvelle connexion à la base de données (crée le fichier s'il n'existe pas)
    $pdo = new PDO('sqlite:' . $db_path);

    // Configure PDO pour qu'il lance des exceptions en cas d'erreur
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Connexion à la base de données établie avec succès.<br>";

    // Requête SQL pour créer la table 'users' si elle n'existe pas déjà
    $sql_create_table = "
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nom TEXT NOT NULL,
        prenom TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        mot_de_passe TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'user',
        date_inscription DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    ";

    // Exécute la requête de création de table
    $pdo->exec($sql_create_table);
    echo "Table 'users' créée ou déjà existante.<br>";

    // --- CRÉATION DE L'ADMINISTRATEUR PAR DÉFAUT ---

    // Vérifier si l'administrateur existe déjà
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
    $stmt->execute([$admin_email]);
    $admin_exists = $stmt->fetchColumn();

    if ($admin_exists == 0) {
        // Hacher le mot de passe de l'administrateur de manière sécurisée
        $hashed_password = password_hash($admin_password, PASSWORD_ARGON2ID);

        // Préparer la requête d'insertion pour l'administrateur
        $stmt_insert_admin = $pdo->prepare(
            "INSERT INTO users (nom, prenom, email, mot_de_passe, role) VALUES (?, ?, ?, ?, ?)"
        );

        // Exécuter la requête avec les informations de l'admin
        $stmt_insert_admin->execute(['Admin', 'DigiTrove', $admin_email, $hashed_password, 'admin']);
        
        echo "Administrateur par défaut créé avec succès.<br>";
        echo "Email: " . htmlspecialchars($admin_email) . "<br>";
        echo "Mot de passe: " . htmlspecialchars($admin_password) . "<br>";
    } else {
        echo "L'administrateur par défaut existe déjà.<br>";
    }

    echo "<br><strong>Configuration terminée ! Vous pouvez maintenant supprimer ce fichier.</strong>";

} catch (PDOException $e) {
    // Affiche un message d'erreur en cas de problème
    die("Erreur de base de données : " . $e->getMessage());
}
?>
