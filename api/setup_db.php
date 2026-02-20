<?php
require_once __DIR__ . '/../includes/config.php';

echo "<h1>Configuration de la base de données</h1>";

try {
    $db = getDB();

    // 1. Recréation des tables
    echo "Nettoyage des tables...<br>";
    $db->exec("DROP TABLE IF EXISTS sync_log CASCADE");
    $db->exec("DROP TABLE IF EXISTS pointages CASCADE");
    $db->exec("DROP TABLE IF EXISTS users CASCADE");

    echo "Création des tables...<br>";
    $db->exec("
        CREATE TABLE users (
            id SERIAL PRIMARY KEY,
            nom VARCHAR(100) NOT NULL UNIQUE,
            prenom VARCHAR(100) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'operateur' CHECK (role IN ('operateur', 'chef')),
            actif BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $db->exec("
        CREATE TABLE pointages (
            id SERIAL PRIMARY KEY,
            user_id INT NOT NULL REFERENCES users(id),
            numero_of VARCHAR(50) NOT NULL,
            heures DECIMAL(5,2) NOT NULL,
            date_pointage DATE NOT NULL,
            synced_bc BOOLEAN NOT NULL DEFAULT FALSE,
            synced_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (user_id, date_pointage, numero_of)
        )
    ");

    $db->exec("
        CREATE TABLE sync_log (
            id SERIAL PRIMARY KEY,
            chef_id INT NOT NULL REFERENCES users(id),
            nb_pointages INT NOT NULL,
            status VARCHAR(20) NOT NULL CHECK (status IN ('success', 'error')),
            response_data TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // 2. Insertion des utilisateurs avec hachage SERVEUR
    echo "Insertion des utilisateurs...<br>";
    $password = 'password123';
    $hash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $db->prepare("INSERT INTO users (nom, prenom, password_hash, role) VALUES (?, ?, ?, ?)");

    $stmt->execute(['ADMIN', 'Chef Atelier', $hash, 'chef']);
    $stmt->execute(['DUPONT', 'Jean', $hash, 'operateur']);
    $stmt->execute(['MARTIN', 'Pierre', $hash, 'operateur']);
    $stmt->execute(['DURAND', 'Sophie', $hash, 'operateur']);

    echo "<h2 style='color:green;'>✅ Configuration réussie !</h2>";
    echo "<p>Vous pouvez maintenant vous connecter sur <a href='index.php'>la page d'accueil</a> avec :</p>";
    echo "<ul><li>Identifiant : <b>ADMIN</b></li><li>Mot de passe : <b>password123</b></li></ul>";
    echo "<p style='color:red;'><b>IMPORTANT :</b> Une fois connecté, supprimez ce fichier (api/setup_db.php) pour la sécurité.</p>";

} catch (Exception $e) {
    echo "<h2 style='color:red;'>❌ Erreur :</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
