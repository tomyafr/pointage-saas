<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_GET['token']) || $_GET['token'] !== 'lenoir123!') {
    die('Unauthorized');
}

$db = getDB();

try {
    // Forcer le rollback au cas où la connexion persistante est dans une transac bloquée (typique de PgBouncer / Neon / Prisma en serverless)
    $db->exec('ROLLBACK');
} catch (Exception $e) {
}

try {
    // Liste des OFs fictifs
    $ofs = ['OF-MAGNET-A1', 'OF-MAGNET-B2', 'OF-ELEC-404', 'OF-MEC-999', 'OF-BOBIN-X7', 'OF-TEST-2026', 'OF-URGENT-01'];

    $stmt = $db->query('SELECT id FROM users WHERE actif = TRUE');
    $users = $stmt->fetchAll();

    if (empty($users)) {
        die("Aucun utilisateur actif trouvé.");
    }

    $count = 0;
    $today = time();

    foreach ($users as $user) {
        $uid = $user['id'];

        $numPointages = rand(2, 4);
        for ($i = 0; $i < $numPointages; $i++) {
            $offsetJours = rand(0, 5);
            $datePointage = date('Y-m-d', strtotime("-$offsetJours days", $today));
            $of = $ofs[array_rand($ofs)];
            $heuresDec = rand(4, 30) * 0.25;

            // Boolean en string ou int pour postgresql
            $synced = (rand(0, 10) > 3) ? 'true' : 'false';

            $insert = $db->prepare('INSERT INTO pointages (user_id, numero_of, heures, date_pointage, synced_bc) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$uid, $of, $heuresDec, $datePointage, filter_var($synced, FILTER_VALIDATE_BOOLEAN) ? 1 : 0]);
            $count++;
        }
    }

    echo "SUCCESS: $count pointages fictifs ajoutés avec succès.";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
