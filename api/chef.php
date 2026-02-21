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
                $bcPayload = prepareBCPayload($toSync);
                $result = sendToBCAPI($bcPayload);

                if ($result['success']) {
                    $syncIds = array_column($toSync, 'id');
                    $placeholders2 = implode(',', array_fill(0, count($syncIds), '?'));
                    $stmt = $db->prepare("UPDATE pointages SET synced_bc = TRUE, synced_at = NOW() WHERE id IN ($placeholders2)");
                    $stmt->execute($syncIds);

                    $stmt = $db->prepare('INSERT INTO sync_log (chef_id, nb_pointages, status, response_data) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$_SESSION['user_id'], count($syncIds), 'success', json_encode($result)]);

                    logAudit('SYNC_SUCCESS', "Count: " . count($syncIds));
                    $message = "✓ " . count($syncIds) . " pointage(s) synchronisé(s) avec Business Central.";
                    $messageType = 'success';
                } else {
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

$totalHeures = array_sum(array_column($ofsData, 'total_heures'));
$totalOfs = count($ofsData);
$totalPending = array_sum(array_column($ofsData, 'nb_pending'));
$totalSynced = array_sum(array_column($ofsData, 'nb_synced'));
$totalOperateurs = 0;
foreach ($ofsData as $of)
    $totalOperateurs = max($totalOperateurs, $of['nb_operateurs']);

// Détails par OF
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

// Sync logs
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
    $simulationMode = true;

    if ($simulationMode) {
        return [
            'success' => true,
            'mode' => 'simulation',
            'message' => 'Simulation - Données prêtes pour BC',
            'data' => $payload,
        ];
    }

    try {
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

$syncRate = ($totalSynced + $totalPending) > 0 ? round(($totalSynced / ($totalSynced + $totalPending)) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Espace Chef d'Atelier | Raoul Lenoir</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="assets/icon-192.png">
    <meta name="theme-color" content="#020617">
    <style>
        .of-row {
            transition: var(--transition-smooth);
            cursor: pointer;
        }

        .of-row:hover {
            background: var(--primary-subtle) !important;
        }

        .detail-row {
            background: rgba(15, 23, 42, 0.5);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.3rem 0.65rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .badge-pending {
            background: rgba(14, 165, 233, 0.1);
            color: var(--accent-cyan);
            border: 1px solid rgba(14, 165, 233, 0.15);
        }

        .badge-synced {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.15);
        }

        .chef-table th {
            padding: 1rem;
            text-align: left;
            border-bottom: 2px solid var(--glass-border);
            color: var(--text-dim);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .chef-table td {
            padding: 1.15rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
        }

        .sync-bar {
            height: 4px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .sync-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success), var(--accent-cyan));
            border-radius: 4px;
            transition: width 1s cubic-bezier(0.16, 1, 0.3, 1);
        }
    </style>
</head>

<body>
    <!-- Mobile menu toggle -->
    <button class="mobile-menu-toggle" onclick="toggleSidebar()">☰</button>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <div class="dashboard-layout">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div style="margin-bottom: 2.5rem;">
                <div class="brand-icon" style="width: 180px; height: auto; margin: 0 0 1rem 0;"><img
                        src="assets/logo-raoul-lenoir.svg" alt="Raoul Lenoir"></div>
                <h2 style="font-size: 1.15rem;"><span class="text-gradient">Raoul Lenoir</span></h2>
                <p
                    style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; margin-top: 0.25rem;">
                    Chef d'Atelier</p>
            </div>

            <nav style="display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 2rem;">
                <a href="chef.php" class="btn btn-primary"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <span>📊</span> Tableau de bord
                </a>
                <a href="operator.php" class="btn btn-ghost"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <span>📝</span> Mode Saisie
                </a>
                <a href="export-excel.php?week=<?= $filterWeek ?>&of=<?= urlencode($filterOf) ?>" class="btn btn-ghost"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;" target="_blank">
                    <span>📥</span> Export Excel
                </a>
            </nav>

            <!-- Sync Status -->
            <div
                style="margin-bottom: 2rem; padding: 1.25rem; background: rgba(255,255,255,0.02); border-radius: var(--radius-md); border: 1px solid var(--glass-border);">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.4rem;">
                    <span
                        style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.05em;">Sync
                        BC</span>
                    <span style="font-size: 0.75rem; font-weight: 700; color: var(--success);"><?= $syncRate ?>%</span>
                </div>
                <div class="sync-bar">
                    <div class="sync-bar-fill" style="width: <?= $syncRate ?>%;"></div>
                </div>
                <p style="font-size: 0.65rem; color: var(--text-dim); margin-top: 0.5rem;">
                    <?= $totalSynced ?> sync · <?= $totalPending ?> en attente
                </p>
            </div>

            <div style="margin-top: auto; padding-top: 1.5rem; border-top: 1px solid var(--glass-border);">
                <p
                    style="font-size: 0.65rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.4rem;">
                    Connecté</p>
                <p style="font-weight: 600; font-size: 0.85rem;">
                    <?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?>
                </p>
                <a href="logout.php" class="btn btn-ghost"
                    style="width: 100%; margin-top: 1rem; color: var(--error); border-color: rgba(244, 63, 94, 0.15); font-size: 0.75rem; padding: 0.6rem;">
                    Se déconnecter
                </a>
                <a href="profile.php" class="btn btn-ghost" style="width: 100%; margin-top: 0.5rem; font-size: 0.75rem; padding: 0.6rem; text-decoration: none; color: inherit; border: 1px solid var(--glass-border);">
                    👤 Mon Profil
                </a>
                <button onclick="if(window.notificationManager) window.notificationManager.requestPermission()"
                    class="btn btn-ghost"
                    style="width: 100%; margin-top: 0.5rem; font-size: 0.65rem; border: none; opacity: 0.5;">
                    🔔 Activer Notifications
                </button>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?> animate-in">
                    <span><?= $messageType === 'success' ? '✓' : ($messageType === 'info' ? 'ℹ' : '⚠') ?></span>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>

            <div class="stats-grid animate-in">
                <div class="stat-item glass">
                    <span class="stat-label">OFs Actifs</span>
                    <span class="stat-value"><?= $totalOfs ?></span>
                </div>
                <div class="stat-item glass">
                    <span class="stat-label">Total Heures</span>
                    <span class="stat-value"><?= number_format($totalHeures, 1) ?><small
                            style="font-size: 0.45em; opacity: 0.5; margin-left: 2px;">H</small></span>
                </div>
                <div class="stat-item glass">
                    <span class="stat-label">En attente</span>
                    <span class="stat-value" style="color: var(--accent-cyan);"><?= $totalPending ?></span>
                </div>
                <div class="stat-item glass">
                    <span class="stat-label">Synchronisés</span>
                    <span class="stat-value" style="color: var(--success);"><?= $totalSynced ?></span>
                </div>
            </div>

            <div class="card glass animate-in">
                <div
                    style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <h3 style="font-size: 1.3rem; margin-bottom: 0.3rem;">Récapitulatif Production</h3>
                        <p style="font-size: 0.8rem; color: var(--text-dim);">
                            Semaine du <?= date('d/m', strtotime($dateDebut)) ?> au
                            <?= date('d/m/Y', strtotime($dateFin)) ?>
                        </p>
                    </div>

                    <form method="GET" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <select name="week" class="input"
                            style="width: auto; padding: 0.5rem 0.75rem; font-size: 0.85rem;"
                            onchange="this.form.submit()">
                            <option value="current" <?= $filterWeek === 'current' ? 'selected' : '' ?>>Semaine en cours
                            </option>
                            <option value="last" <?= $filterWeek === 'last' ? 'selected' : '' ?>>Semaine dernière</option>
                        </select>
                        <input type="text" name="of" class="input"
                            style="width: 160px; padding: 0.5rem 0.75rem; font-size: 0.85rem;"
                            placeholder="Filtrer OF..." value="<?= htmlspecialchars($filterOf) ?>">
                        <button type="submit" class="btn btn-ghost"
                            style="padding: 0.5rem 0.75rem; font-size: 0.8rem;">Filtrer</button>
                    </form>
                </div>

                <form method="POST" id="syncForm">
                    <input type="hidden" name="action" value="sync_bc">

                    <div style="overflow-x: auto;">
                        <table class="chef-table" style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="width: 36px; text-align: center;">
                                        <input type="checkbox" id="checkAll" onchange="toggleAll(this)">
                                    </th>
                                    <th>N° OF</th>
                                    <th>Période</th>
                                    <th>Opérateurs</th>
                                    <th style="text-align: right;">Heures</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($ofsData)): ?>
                                    <tr>
                                        <td colspan="6" style="padding: 4rem; text-align: center; color: var(--text-dim);">
                                            <p style="font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.3;">📦</p>
                                            Aucun OF sur cette période.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($ofsData as $of): ?>
                                        <tr class="of-row"
                                            onclick="toggleDetail('detail-<?= htmlspecialchars($of['numero_of']) ?>')">
                                            <td style="text-align: center;" onclick="event.stopPropagation()">
                                                <?php if ($of['nb_pending'] > 0):
                                                    $pendingIds = [];
                                                    foreach ($detailsParOf[$of['numero_of']] ?? [] as $det) {
                                                        if (!$det['synced_bc'])
                                                            $pendingIds[] = $det['id'];
                                                    }
                                                    ?>
                                                    <input type="checkbox" class="of-check"
                                                        data-ids="<?= implode(',', $pendingIds) ?>" onchange="updateHiddenInputs()">
                                                <?php endif; ?>
                                            </td>
                                            <td
                                                style="font-family: var(--font-mono); font-weight: 700; color: var(--primary); font-size: 1.05rem;">
                                                <?= htmlspecialchars($of['numero_of']) ?>
                                            </td>
                                            <td style="font-size: 0.8rem; color: var(--text-muted);">
                                                <?= date('d/m', strtotime($of['premiere_date'])) ?> →
                                                <?= date('d/m', strtotime($of['derniere_date'])) ?>
                                            </td>
                                            <td>
                                                <span style="font-size: 0.8rem;"><?= $of['nb_operateurs'] ?> op.</span>
                                            </td>
                                            <td style="text-align: right; font-weight: 900; font-size: 1.05rem;">
                                                <?= number_format($of['total_heures'], 2) ?>h
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                                                    <?php if ($of['nb_pending'] > 0): ?>
                                                        <span class="status-badge badge-pending"><?= $of['nb_pending'] ?> en
                                                            attente</span>
                                                    <?php endif; ?>
                                                    <?php if ($of['nb_synced'] > 0): ?>
                                                        <span class="status-badge badge-synced">✓ <?= $of['nb_synced'] ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <!-- Détails -->
                                        <tr id="detail-<?= htmlspecialchars($of['numero_of']) ?>" class="detail-row"
                                            style="display: none;">
                                            <td colspan="6" style="padding: 0;">
                                                <div style="padding: 1.25rem 1.5rem; border-left: 3px solid var(--primary);">
                                                    <table style="width: 100%; font-size: 0.8rem; border-collapse: collapse;">
                                                        <thead>
                                                            <tr
                                                                style="text-align: left; color: var(--text-dim); font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.08em;">
                                                                <th style="padding: 0.5rem;">Date</th>
                                                                <th style="padding: 0.5rem;">Opérateur</th>
                                                                <th style="padding: 0.5rem; text-align: right;">Heures</th>
                                                                <th style="padding: 0.5rem; text-align: center;">BC</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($detailsParOf[$of['numero_of']] ?? [] as $d): ?>
                                                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.02);">
                                                                    <td
                                                                        style="padding: 0.5rem; font-family: var(--font-mono); font-size: 0.75rem;">
                                                                        <?= date('d/m/Y', strtotime($d['date_pointage'])) ?>
                                                                    </td>
                                                                    <td style="padding: 0.5rem;">
                                                                        <?= htmlspecialchars($d['prenom'] . ' ' . $d['nom']) ?>
                                                                    </td>
                                                                    <td
                                                                        style="padding: 0.5rem; text-align: right; font-weight: 700;">
                                                                        <?= number_format($d['heures'], 2) ?>h
                                                                    </td>
                                                                    <td style="padding: 0.5rem; text-align: center;">
                                                                        <?= $d['synced_bc'] ? '<span style="color:var(--success)">✓</span>' : '<span style="color:var(--text-dim)">—</span>' ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Sync Action -->
                    <?php if ($totalPending > 0): ?>
                        <div id="syncFooter"
                            style="margin-top: 2rem; display: none; align-items: center; justify-content: space-between; padding: 1.25rem 1.5rem; background: rgba(14, 165, 233, 0.05); border-radius: var(--radius-md); border: 1px solid rgba(14, 165, 233, 0.15); gap: 1rem; flex-wrap: wrap;">
                            <div>
                                <p style="font-weight: 700; color: var(--accent-cyan); font-size: 0.9rem;">Synchronisation
                                    BC</p>
                                <p style="font-size: 0.75rem; color: var(--text-muted);"><span id="syncCount">0</span>
                                    pointage(s) sélectionné(s)</p>
                            </div>
                            <button type="submit" class="btn btn-primary"
                                style="font-size: 0.8rem; padding: 0.75rem 1.25rem;"
                                onclick="return confirm('Confirmer la synchronisation vers Business Central ?')">
                                Synchroniser →
                            </button>
                        </div>
                    <?php endif; ?>

                    <div id="hiddenInputsContainer"></div>
                </form>

                <!-- Historique Sync -->
                <?php if (!empty($syncLogs)): ?>
                    <div style="margin-top: 3rem;">
                        <h4
                            style="color: var(--text-dim); text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.08em; margin-bottom: 1rem;">
                            Dernières Synchronisations</h4>
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <?php foreach ($syncLogs as $log): ?>
                                <div class="glass"
                                    style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; border-radius: var(--radius-sm); font-size: 0.8rem;">
                                    <span
                                        style="font-family: var(--font-mono); font-size: 0.7rem; color: var(--text-dim);"><?= date('d/m H:i', strtotime($log['created_at'])) ?></span>
                                    <span style="font-weight: 600;"><?= $log['nb_pointages'] ?> ptg(s)</span>
                                    <span
                                        class="status-badge <?= $log['status'] === 'success' ? 'badge-synced' : 'badge-pending' ?>"
                                        style="<?= $log['status'] !== 'success' ? 'background: rgba(244,63,94,0.1); color: var(--error); border-color: rgba(244,63,94,0.15);' : '' ?>">
                                        <?= $log['status'] === 'success' ? '✓ OK' : '✕ Erreur' ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="app-footer">
                Raoul Lenoir SAS · <a href="privacy.php" style="color: inherit; text-decoration: underline;">RGPD &
                    Confidentialité</a> · V<?= APP_VERSION ?>
            </div>
        </main>
    </div>

    <script>
        function toggleDetail(id) {
            const el = document.getElementById(id);
            el.style.display = el.style.display === 'none' ? 'table-row' : 'none';
        }

        function toggleAll(source) {
            document.querySelectorAll('.of-check').forEach(cb => cb.checked = source.checked);
            updateHiddenInputs();
        }

        function updateHiddenInputs() {
            const container = document.getElementById('hiddenInputsContainer');
            const footer = document.getElementById('syncFooter');
            const countEl = document.getElementById('syncCount');
            container.innerHTML = '';

            let allIds = [];
            document.querySelectorAll('.of-check:checked').forEach(cb => {
                const ids = cb.getAttribute('data-ids').split(',');
                allIds = allIds.concat(ids);
            });

            allIds.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'pointage_ids[]';
                input.value = id;
                container.appendChild(input);
            });

            if (footer) {
                footer.style.display = allIds.length > 0 ? 'flex' : 'none';
                if (countEl) countEl.textContent = allIds.length;
            }
        }

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('open');
        }
    </script>
    <script src="assets/notifications.js"></script>
</body>

</html>