<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_GET['token']) || $_GET['token'] !== 'lenoir123!') {
    die('Unauthorized');
}

$db = getDB();

try {
    // Liste des OFs qui ne matchent pas /^4\d{5}$/
    $stmt = $db->query("SELECT DISTINCT numero_of FROM pointages WHERE numero_of !~ '^4[0-9]{5}$'");
    $ofsToUpdate = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($ofsToUpdate)) {
        echo "Aucun OF à corriger.";
        exit;
    }

    $count = 0;
    foreach ($ofsToUpdate as $wrongOf) {
        $newOf = '4' . str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT);

        $update = $db->prepare("UPDATE pointages SET numero_of = ? WHERE numero_of = ?");
        $update->execute([$newOf, $wrongOf]);
        $count++;
    }

    echo "SUCCESS: $count types d'OF distincts corrigés.";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
