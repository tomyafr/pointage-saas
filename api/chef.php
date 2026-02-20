<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth('chef');

$db = getDB();
$week = getCurrentWeekDates();
$message = '';
$messageType = '';

// Filtres
$filterWeek = $_GET['week'] ?? 'current';
$filterOf = trim($_GET['of'] ?? '');

if ($filterWeek === 'current') {
    $dateDebut = $week['monday'];
    $dateFin = $week['sunday'];
} elseif ($filterWeek === 'last') {
    $dateDebut = date('Y-m-d', strtotime($week['monday'] . ' -7 days'));
    $dateFin = date('Y-m-d', strtotime($week['sunday'] . ' -7 days'));
} else {
    $dateDebut = $week['monday'];
    $dateFin = $week['sunday'];
}

// Traitement sync BC
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'sync_bc') {
        $pointageIds = $_POST['pointage_ids'] ?? [];

        if (empty($pointageIds)) {
            $message = 'Aucun pointage sélectionné pour la synchronisation.';
            $messageType = 'error';
        } else {
            // Récupérer les données à synchroniser
            $placeholders = implode(',', array_fill(0, count($pointageIds), '?'));
            $stmt = $db->prepare("
                SELECT p.id, p.numero_of, p.heures, p.date_pointage, u.nom, u.prenom
                FROM pointages p
                JOIN users u ON p.user_id = u.id
                WHERE p.id IN ($placeholders) AND p.synced_bc IS FALSE
            ");
            $stmt->execute($pointageIds);
            $toSync = $stmt->fetchAll();

            if (empty($toSync)) {
                $message = 'Tous les pointages sélectionnés sont déjà synchronisés.';
                $messageType = 'info';
            } else {
                // Préparer les données pour Business Central
                $bcPayload = prepareBCPayload($toSync);
                $result = sendToBCAPI($bcPayload);

                if ($result['success']) {
                    // Marquer comme synchronisé
                    $syncIds = array_column($toSync, 'id');
                    $placeholders2 = implode(',', array_fill(0, count($syncIds), '?'));
                    $stmt = $db->prepare("UPDATE pointages SET synced_bc = TRUE, synced_at = NOW() WHERE id IN ($placeholders2)");
                    $stmt->execute($syncIds);

                    // Log
                    $stmt = $db->prepare('INSERT INTO sync_log (chef_id, nb_pointages, status, response_data) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$_SESSION['user_id'], count($syncIds), 'success', json_encode($result)]);

                    $message = "✓ " . count($syncIds) . " pointage(s) synchronisé(s) avec Business Central.";
                    $messageType = 'success';
                } else {
                    // Log erreur
                    $stmt = $db->prepare('INSERT INTO sync_log (chef_id, nb_pointages, status, response_data) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$_SESSION['user_id'], count($toSync), 'error', json_encode($result)]);

                    $message = "Erreur de synchronisation : " . ($result['error'] ?? 'Erreur inconnue');
                    $messageType = 'error';
                }
            }
        }
    }
}

// Récupérer les totaux par OF
$query = '
    SELECT 
        p.numero_of,
        SUM(p.heures) as total_heures,
        COUNT(DISTINCT p.user_id) as nb_operateurs,
        COUNT(p.id) as nb_pointages,
        MIN(p.date_pointage) as premiere_date,
        MAX(p.date_pointage) as derniere_date,
        SUM(CASE WHEN p.synced_bc IS TRUE THEN 1 ELSE 0 END) as nb_synced,
        SUM(CASE WHEN p.synced_bc IS FALSE THEN 1 ELSE 0 END) as nb_pending
    FROM pointages p
    WHERE p.date_pointage BETWEEN ? AND ?
';
$params = [$dateDebut, $dateFin];

if (!empty($filterOf)) {
    $query .= ' AND p.numero_of LIKE ?';
    $params[] = '%' . $filterOf . '%';
}

$query .= ' GROUP BY p.numero_of ORDER BY p.numero_of ASC';

$stmt = $db->prepare($query);
$stmt->execute($params);
$ofsData = $stmt->fetchAll();

// Totaux globaux
$totalHeures = array_sum(array_column($ofsData, 'total_heures'));
$totalOfs = count($ofsData);
$totalPending = array_sum(array_column($ofsData, 'nb_pending'));
$totalSynced = array_sum(array_column($ofsData, 'nb_synced'));

// Détails par OF (pour expansion)
$detailsParOf = [];
$stmt = $db->prepare('
    SELECT p.id, p.numero_of, p.heures, p.date_pointage, p.synced_bc, u.nom, u.prenom
    FROM pointages p
    JOIN users u ON p.user_id = u.id
    WHERE p.date_pointage BETWEEN ? AND ?
    ORDER BY p.numero_of, p.date_pointage, u.nom
');
$stmt->execute([$dateDebut, $dateFin]);
foreach ($stmt->fetchAll() as $row) {
    $detailsParOf[$row['numero_of']][] = $row;
}

// Dernier sync log
$stmt = $db->prepare('SELECT * FROM sync_log ORDER BY created_at DESC LIMIT 5');
$stmt->execute();
$syncLogs = $stmt->fetchAll();

// ----- Fonctions Business Central -----

function prepareBCPayload($pointages)
{
    $journalLines = [];
    foreach ($pointages as $p) {
        $journalLines[] = [
            'documentNo' => 'PTG-' . $p['id'],
            'postingDate' => $p['date_pointage'],
            'description' => "Pointage {$p['prenom']} {$p['nom']} - OF {$p['numero_of']}",
            'quantity' => floatval($p['heures']),
            'productionOrderNo' => $p['numero_of'],
            'workType' => 'PROD',
        ];
    }
    return ['journalLines' => $journalLines];
}

function sendToBCAPI($payload)
{
    // ========================================
    // INTÉGRATION MICROSOFT BUSINESS CENTRAL
    // ========================================
    // 
    // Cette fonction envoie les pointages vers BC via l'API REST standard.
    // Elle utilise le flux OAuth2 Client Credentials.
    //
    // ÉTAPES DE CONFIGURATION DANS BC :
    // 1. Azure AD : Enregistrer une application (App Registration)
    // 2. Donner les permissions "Dynamics 365 Business Central" → API.ReadWrite.All
    // 3. Dans BC : configurer une feuille temps (Time Sheet) ou un journal projet
    // 4. Renseigner les constantes dans config.php
    //
    // En mode SIMULATION (tant que BC n'est pas configuré), 
    // les données sont marquées comme synchronisées localement.
    // ========================================

    // --- Mode simulation (à désactiver en production) ---
    $simulationMode = true;

    if ($simulationMode) {
        // Simule une réponse réussie
        return [
            'success' => true,
            'mode' => 'simulation',
            'message' => 'Simulation - Données prêtes pour BC',
            'data' => $payload,
        ];
    }

    // --- Mode production ---
    try {
        // 1. Obtenir le token OAuth2
        $tokenData = [
            'grant_type' => 'client_credentials',
            'client_id' => BC_CLIENT_ID,
            'client_secret' => BC_CLIENT_SECRET,
            'scope' => BC_SCOPE,
        ];

        $ch = curl_init(BC_TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($tokenData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $tokenResponse = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!isset($tokenResponse['access_token'])) {
            return ['success' => false, 'error' => 'Impossible d\'obtenir le token BC'];
        }

        $accessToken = $tokenResponse['access_token'];

        // 2. Envoyer chaque ligne au journal BC
        $endpoint = BC_BASE_URL . '/companies(' . BC_COMPANY_ID . ')/journals';

        foreach ($payload['journalLines'] as $line) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($line),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                ],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 400) {
                return [
                    'success' => false,
                    'error' => "Erreur BC HTTP {$httpCode}",
                    'response' => $response,
                ];
            }
        }

        return ['success' => true, 'mode' => 'production'];

    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0a0f1a">
    <title>Chef d'atelier - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>

<body>
    <div class="app-container wide">
        <!-- Header -->
        <header class="app-header">
            <div class="app-logo">
                <div class="app-logo-icon">⏱</div>
                <div>
                    <div class="app-logo-text"><?= APP_NAME ?></div>
                    <div class="app-logo-sub">Chef d'atelier</div>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <span
                    class="user-badge"><?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?></span>
                <a href="logout.php" class="btn-logout">Quitter</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $totalOfs ?></div>
                <div class="stat-label">OFs actifs</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($totalHeures, 1) ?></div>
                <div class="stat-label">Heures totales</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color:var(--info);"><?= $totalPending ?></div>
                <div class="stat-label">À synchroniser</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color:var(--success);"><?= $totalSynced ?></div>
                <div class="stat-label">Synchronisés</div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="filter-bar">
            <select class="form-input"
                onchange="window.location='chef.php?week='+this.value+'&of=<?= urlencode($filterOf) ?>'">
                <option value="current" <?= $filterWeek === 'current' ? 'selected' : '' ?>>Semaine en cours</option>
                <option value="last" <?= $filterWeek === 'last' ? 'selected' : '' ?>>Semaine dernière</option>
            </select>
            <form method="GET" style="display:flex;gap:8px;flex:1;">
                <input type="hidden" name="week" value="<?= htmlspecialchars($filterWeek) ?>">
                <input type="text" name="of" class="form-input" placeholder="Filtrer par OF..."
                    value="<?= htmlspecialchars($filterOf) ?>">
            </form>
            <a href="export-excel.php?week=<?= urlencode($filterWeek) ?>&of=<?= urlencode($filterOf) ?>"
                class="btn btn-secondary" style="width:auto;padding:10px 18px;font-size:0.75rem;white-space:nowrap;">
                📊 Export Excel
            </a>
        </div>

        <!-- Tableau des OF -->
        <form method="POST" id="syncForm">
            <input type="hidden" name="action" value="sync_bc">

            <div class="card">
                <div class="card-title">
                    Récapitulatif par OF — <?= date('d/m', strtotime($dateDebut)) ?> au
                    <?= date('d/m/Y', strtotime($dateFin)) ?>
                </div>

                <?php if (empty($ofsData)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📊</div>
                        <p class="empty-state-text">Aucun pointage sur cette période</p>
                    </div>
                <?php else: ?>
                    <table class="of-table">
                        <thead>
                            <tr>
                                <th style="width:30px;">
                                    <input type="checkbox" class="sync-check" id="checkAll" onchange="toggleAll(this)">
                                </th>
                                <th>N° OF</th>
                                <th>Heures</th>
                                <th>Opér.</th>
                                <th>Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ofsData as $of): ?>
                                <tr onclick="toggleDetail('detail-<?= htmlspecialchars($of['numero_of']) ?>')"
                                    style="cursor:pointer;">
                                    <td onclick="event.stopPropagation();">
                                        <?php if ($of['nb_pending'] > 0): ?>
                                            <?php
                                            $pendingIds = [];
                                            foreach ($detailsParOf[$of['numero_of']] ?? [] as $d) {
                                                if (!$d['synced_bc'])
                                                    $pendingIds[] = $d['id'];
                                            }
                                            ?>
                                            <input type="checkbox" class="sync-check of-check"
                                                data-ids="<?= implode(',', $pendingIds) ?>" onchange="updateHiddenInputs()">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="of-number"><?= htmlspecialchars($of['numero_of']) ?></span>
                                    </td>
                                    <td>
                                        <span class="of-hours"><?= number_format($of['total_heures'], 2) ?>h</span>
                                    </td>
                                    <td><?= $of['nb_operateurs'] ?></td>
                                    <td>
                                        <?php if ($of['nb_pending'] > 0): ?>
                                            <span class="badge badge-pending"><?= $of['nb_pending'] ?> en attente</span>
                                        <?php endif; ?>
                                        <?php if ($of['nb_synced'] > 0): ?>
                                            <span class="badge badge-synced"><?= $of['nb_synced'] ?> sync</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <!-- Détails cachés -->
                                <tr id="detail-<?= htmlspecialchars($of['numero_of']) ?>" style="display:none;">
                                    <td colspan="5" style="padding:0 10px 14px 40px; background:var(--bg-secondary);">
                                        <table class="week-table" style="margin-top:8px;">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Opérateur</th>
                                                    <th style="text-align:right;">Heures</th>
                                                    <th>Sync</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($detailsParOf[$of['numero_of']] ?? [] as $d): ?>
                                                    <tr>
                                                        <td><?= date('d/m', strtotime($d['date_pointage'])) ?></td>
                                                        <td><?= htmlspecialchars($d['prenom'] . ' ' . $d['nom']) ?></td>
                                                        <td class="hours-cell" style="text-align:right;">
                                                            <?= number_format($d['heures'], 2) ?>h
                                                        </td>
                                                        <td>
                                                            <?php if ($d['synced_bc']): ?>
                                                                <span style="color:var(--success);">✓</span>
                                                            <?php else: ?>
                                                                <span style="color:var(--text-muted);">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Bouton sync BC -->
            <?php if ($totalPending > 0): ?>
                <div id="syncContainer" style="display:none;">
                    <button type="submit" class="btn btn-success" id="syncBtn"
                        onclick="return confirm('Confirmer la synchronisation vers Business Central ?')">
                        ↑ Synchroniser vers Business Central (<span id="syncCount">0</span> pointages)
                    </button>
                </div>
            <?php endif; ?>

            <div id="hiddenInputsContainer"></div>
        </form>

        <!-- Historique des syncs -->
        <?php if (!empty($syncLogs)): ?>
            <div class="card" style="margin-top:16px;">
                <div class="card-title">Dernières synchronisations</div>
                <table class="week-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Pointages</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($syncLogs as $log): ?>
                            <tr>
                                <td><?= date('d/m H:i', strtotime($log['created_at'])) ?></td>
                                <td><?= $log['nb_pointages'] ?></td>
                                <td>
                                    <span class="badge <?= $log['status'] === 'success' ? 'badge-synced' : 'badge-pending' ?>">
                                        <?= $log['status'] === 'success' ? '✓ OK' : '✕ Erreur' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleDetail(id) {
            const row = document.getElementById(id);
            row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
        }

        function toggleAll(source) {
            document.querySelectorAll('.of-check').forEach(cb => {
                cb.checked = source.checked;
            });
            updateHiddenInputs();
        }

        function updateHiddenInputs() {
            const container = document.getElementById('hiddenInputsContainer');
            container.innerHTML = '';
            let count = 0;

            document.querySelectorAll('.of-check:checked').forEach(cb => {
                const ids = cb.dataset.ids.split(',');
                ids.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'pointage_ids[]';
                    input.value = id;
                    container.appendChild(input);
                    count++;
                });
            });

            const syncContainer = document.getElementById('syncContainer');
            const syncCount = document.getElementById('syncCount');

            if (syncContainer) {
                syncContainer.style.display = count > 0 ? 'block' : 'none';
                if (syncCount) syncCount.textContent = count;
            }
        }
    </script>
</body>

</html>