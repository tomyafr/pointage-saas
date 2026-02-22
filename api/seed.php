<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_GET['token']) || $_GET['token'] !== 'lenoir123!') {
    die('Unauthorized');
}

$db = getDB();

try {
    // Liste des OFs fictifs
    $ofs = ['OF-MAGNET-A1', 'OF-MAGNET-B2', 'OF-ELEC-404', 'OF-MEC-999', 'OF-BOBIN-X7', 'OF-TEST-2026', 'OF-URGENT-01'];

    // Récupérer les utilisateurs
    $stmt = $db->query('SELECT id, nom, prenom FROM users WHERE actif = TRUE');
    $users = $stmt->fetchAll();

    if (empty($users)) {
        die("Aucun utilisateur actif trouvé.");
    }

    $db->beginTransaction();

    $count = 0;
    $today = time();

    foreach ($users as $user) {
        $uid = $user['id'];

        // Créer 3 à 5 pointages par utilisateur pour la semaine en cours
        $numPointages = rand(3, 5);
        for ($i = 0; $i < $numPointages; $i++) {
            // Jours aléatoires dans les 7 derniers jours
            $offsetJours = rand(0, 5);
            $datePointage = date('Y-m-d', strtotime("-$offsetJours days", $today));

            $of = $ofs[array_rand($ofs)];

            // Heures aléatoires entre 1h et 8.5h (par pas de 0.25)
            $heuresDec = rand(4, 34) * 0.25;

            // Sync BC aléatoire
            $synced = (rand(0, 10) > 3) ? 'true' : 'false';

            $insert = $db->prepare('INSERT INTO pointages (user_id, numero_of, heures, date_pointage, synced_bc) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$uid, $of, $heuresDec, $datePointage, filter_var($synced, FILTER_VALIDATE_BOOLEAN)]);
            $count++;
        }
    }

    $db->commit();
    echo "SUCCESS: $count pointages fictifs ajoutés avec succès.";
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "ERROR: " . $e->getMessage();
}
