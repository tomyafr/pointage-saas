<?php
/**
 * API REST pour intégration externe (Microsoft Business Central, etc.)
 * 
 * Endpoints:
 * GET  /api.php?action=pointages&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD
 * GET  /api.php?action=pointages_of&of=OF-NUMBER
 * POST /api.php?action=mark_synced  (body: {"ids": [1,2,3]})
 * GET  /api.php?action=users
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

// Authentification par API Key simple (à renforcer en production)
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
$validApiKey = 'CHANGEZ_CETTE_CLE_EN_PRODUCTION_2025'; // ← À modifier !

if ($apiKey !== $validApiKey) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'message' => 'Clé API invalide']);
    exit;
}

$db = getDB();
$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // Liste des pointages (filtrés par date)
        case 'pointages':
            $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('monday this week'));
            $dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('sunday this week'));
            $syncedOnly = isset($_GET['synced']) ? (int) $_GET['synced'] : null;

            $query = '
                SELECT p.id, p.numero_of, p.heures, p.date_pointage, p.synced_bc,
                       p.created_at, p.updated_at,
                       u.nom as operateur_nom, u.prenom as operateur_prenom
                FROM pointages p
                JOIN users u ON p.user_id = u.id
                WHERE p.date_pointage BETWEEN ? AND ?
            ';
            $params = [$dateFrom, $dateTo];

            if ($syncedOnly !== null) {
                $query .= ' AND p.synced_bc = ?';
                $params[] = $syncedOnly;
            }

            $query .= ' ORDER BY p.date_pointage, p.numero_of';

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $data = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'count' => count($data),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'pointages' => $data,
            ]);
            break;

        // Pointages pour un OF spécifique
        case 'pointages_of':
            $of = $_GET['of'] ?? '';
            if (empty($of)) {
                http_response_code(400);
                echo json_encode(['error' => 'Paramètre "of" requis']);
                exit;
            }

            $stmt = $db->prepare('
                SELECT p.id, p.heures, p.date_pointage, p.synced_bc,
                       u.nom as operateur_nom, u.prenom as operateur_prenom
                FROM pointages p
                JOIN users u ON p.user_id = u.id
                WHERE p.numero_of = ?
                ORDER BY p.date_pointage
            ');
            $stmt->execute([$of]);
            $data = $stmt->fetchAll();

            $totalHeures = array_sum(array_column($data, 'heures'));

            echo json_encode([
                'success' => true,
                'numero_of' => $of,
                'total_heures' => $totalHeures,
                'nb_pointages' => count($data),
                'details' => $data,
            ]);
            break;

        // Marquer des pointages comme synchronisés (callback de BC)
        case 'mark_synced':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Méthode POST requise']);
                exit;
            }

            $body = json_decode(file_get_contents('php://input'), true);
            $ids = $body['ids'] ?? [];

            if (empty($ids) || !is_array($ids)) {
                http_response_code(400);
                echo json_encode(['error' => 'Paramètre "ids" (array) requis']);
                exit;
            }

            $ids = array_map('intval', $ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $stmt = $db->prepare("UPDATE pointages SET synced_bc = TRUE, synced_at = NOW() WHERE id IN ($placeholders)");
            $stmt->execute($ids);

            echo json_encode([
                'success' => true,
                'updated' => $stmt->rowCount(),
            ]);
            break;

        // Liste des utilisateurs actifs
        case 'users':
            $stmt = $db->prepare('SELECT id, nom, prenom, role FROM users WHERE actif = 1 ORDER BY nom');
            $stmt->execute();

            echo json_encode([
                'success' => true,
                'users' => $stmt->fetchAll(),
            ]);
            break;

        // Résumé hebdomadaire (format adapté BC)
        case 'weekly_summary':
            $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('monday this week'));
            $dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('sunday this week'));

            $stmt = $db->prepare('
                SELECT 
                    p.numero_of as productionOrderNo,
                    SUM(p.heures) as totalQuantity,
                    MIN(p.date_pointage) as startDate,
                    MAX(p.date_pointage) as endDate,
                    COUNT(DISTINCT p.user_id) as resourceCount
                FROM pointages p
                WHERE p.date_pointage BETWEEN ? AND ? AND p.synced_bc = 0
                GROUP BY p.numero_of
                ORDER BY p.numero_of
            ');
            $stmt->execute([$dateFrom, $dateTo]);

            echo json_encode([
                'success' => true,
                'format' => 'bc_compatible',
                'period' => ['from' => $dateFrom, 'to' => $dateTo],
                'summary' => $stmt->fetchAll(),
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'error' => 'Action inconnue',
                'available_actions' => ['pointages', 'pointages_of', 'mark_synced', 'users', 'weekly_summary'],
            ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur', 'message' => 'Erreur base de données']);
}
