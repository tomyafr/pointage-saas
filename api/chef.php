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
    <title>Espace Chef d'Atelier | Raoul Lenoir</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .of-row:hover {
            background: rgba(255, 179, 0, 0.05);
            cursor: pointer;
        }

        .detail-row {
            background: rgba(15, 23, 42, 0.5);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.3rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-pending {
            background: rgba(14, 165, 233, 0.1);
            color: var(--accent-cyan);
            border: 1px solid rgba(14, 165, 233, 0.2);
        }

        .badge-synced {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        /* Custom Table Style for Chef */
        .chef-table th {
            padding: 1rem;
            text-align: left;
            border-bottom: 2px solid var(--glass-border);
            color: var(--text-muted);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .chef-table td {
            padding: 1.25rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
        }
    </style>
</head>

<body>
    <div class="dashboard-layout animate-in">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="login-header" style="text-align: left; margin-bottom: 3rem;">
                <div class="brand-icon" style="width: 48px; height: 48px; font-size: 1.5rem; margin: 0 0 1rem 0;">🧲
                </div>
                <h2 style="font-size: 1.25rem;"><span class="text-gradient">Raoul Lenoir</span></h2>
                <p style="font-size: 0.75rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px;">
                    Chef d'Atelier</p>
            </div>

            <nav style="display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 3rem;">
                <a href="chef.php" class="btn btn-primary"
                    style="justify-content: flex-start; padding: 0.75rem 1.25rem;">
                    <span>📊</span> Tableau de bord
                </a>
                <a href="operator.php" class="btn btn-ghost"
                    style="justify-content: flex-start; padding: 0.75rem 1.25rem;">
                    <span>📝</span> Mode Saisie
                </a>
            </nav>

            <div style="margin-top: auto; padding-top: 2rem; border-top: 1px solid var(--glass-border);">
                <div style="margin-bottom: 1.5rem;">
                    <p
                        style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.5rem;">
                        Session active</p>
                    <p style="font-weight: 600; font-size: 0.9rem;">
                        <?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?></p>
                </div>
                <a href="logout.php" class="btn btn-ghost"
                    style="width: 100%; color: var(--error); border-color: rgba(244, 63, 94, 0.2);">
                    Se déconnecter
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?> animate-in">
                    <span><?= $messageType === 'success' ? '✓' : '⚠' ?></span>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-item glass">
                    <span class="stat-label">OFs sur la période</span>
                    <span class="stat-value"><?= $totalOfs ?></span>
                </div>
                <div class="stat-item glass">
                    <span class="stat-label">Total Heures</span>
                    <span class="stat-value"><?= number_format($totalHeures, 1) ?>h</span>
                </div>
                <div class="stat-item glass">
                    <span class="stat-label">Pointages en attente</span>
                    <span class="stat-value" style="color: var(--accent-cyan);"><?= $totalPending ?></span>
                </div>
            </div>

            <div class="card glass">
                <div
                    style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2.5rem; flex-wrap: wrap; gap: 1.5rem;">
                    <h3 style="font-size: 1.5rem;">Récapitulatif par Ordre de Fabrication</h3>

                    <form method="GET" style="display: flex; gap: 0.75rem;">
                        <select name="week" class="input" style="width: auto; padding-0.5rem 1rem;"
                            onchange="this.form.submit()">
                            <option value="current" <?= $filterWeek === 'current' ? 'selected' : '' ?>>Semaine en cours
                            </option>
                            <option value="last" <?= $filterWeek === 'last' ? 'selected' : '' ?>>Semaine dernière</option>
                        </select>
                        <input type="text" name="of" class="input" style="width: 180px;" placeholder="Rechercher OF..."
                            value="<?= htmlspecialchars($filterOf) ?>">
                        <button type="submit" class="btn btn-ghost" style="padding: 0.5rem 1rem;">Go</button>
                    </form>
                </div>

                <form method="POST" id="syncForm">
                    <input type="hidden" name="action" value="sync_bc">

                    <div style="overflow-x: auto;">
                        <table class="chef-table" style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="width: 40px; text-align: center;">
                                        <input type="checkbox" id="checkAll" onchange="toggleAll(this)"
                                            style="width: 18px; height: 18px; accent-color: var(--primary);">
                                    </th>
                                    <th>Numéro d'OF</th>
                                    <th>Période</th>
                                    <th style="text-align: right;">Total Heures</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($ofsData)): ?>
                                    <tr>
                                        <td colspan="5" style="padding: 4rem; text-align: center; opacity: 0.5;">Aucune
                                            donnée sur cette période.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($ofsData as $of): ?>
                                        <tr class="of-row"
                                            onclick="toggleDetail('detail-<?= htmlspecialchars($of['numero_of']) ?>')">
                                            <td style="text-align: center;" onclick="event.stopPropagation()">
                                                <?php if ($of['nb_pending'] > 0): ?>
                                                    <?php
                                                    $pIds = array_column($detailsParOf[$of['numero_of']] ?? [], 'id');
                                                    $pendingIds = [];
                                                    foreach ($detailsParOf[$of['numero_of']] ?? [] as $det)
                                                        if (!$det['synced_bc'])
                                                            $pendingIds[] = $det['id'];
                                                    ?>
                                                    <input type="checkbox" class="of-check"
                                                        data-ids="<?= implode(',', $pendingIds) ?>" onchange="updateHiddenInputs()"
                                                        style="width: 18px; height: 18px; accent-color: var(--primary);">
                                                <?php endif; ?>
                                            </td>
                                            <td
                                                style="font-family: var(--font-mono); font-weight: 700; color: var(--primary); font-size: 1.1rem;">
                                                <?= htmlspecialchars($of['numero_of']) ?>
                                            </td>
                                            <td style="font-size: 0.85rem; color: var(--text-muted);">
                                                Du <?= date('d/m', strtotime($of['premiere_date'])) ?> au
                                                <?= date('d/m', strtotime($of['derniere_date'])) ?>
                                            </td>
                                            <td style="text-align: right; font-weight: 800; font-size: 1.1rem;">
                                                <?= number_format($of['total_heures'], 2) ?>h
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 0.5rem;">
                                                    <?php if ($of['nb_pending'] > 0): ?>
                                                        <span class="status-badge badge-pending"><?= $of['nb_pending'] ?> En
                                                            attente</span>
                                                    <?php endif; ?>
                                                    <?php if ($of['nb_synced'] > 0): ?>
                                                        <span class="status-badge badge-synced"><?= $of['nb_synced'] ?> OK</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <!-- Détails -->
                                        <tr id="detail-<?= htmlspecialchars($of['numero_of']) ?>" class="detail-row"
                                            style="display: none;">
                                            <td colspan="5" style="padding: 0;">
                                                <div style="padding: 1.5rem; border-bottom: 1px solid var(--glass-border);">
                                                    <table style="width: 100%; font-size: 0.85rem; border-collapse: collapse;">
                                                        <thead>
                                                            <tr style="text-align: left; color: var(--text-dim);">
                                                                <th style="padding: 0.5rem;">Date</th>
                                                                <th style="padding: 0.5rem;">Opérateur</th>
                                                                <th style="padding: 0.5rem; text-align: right;">Heures</th>
                                                                <th style="padding: 0.5rem; text-align: center;">Sync</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($detailsParOf[$of['numero_of']] ?? [] as $d): ?>
                                                                <tr>
                                                                    <td style="padding: 0.5rem;">
                                                                        <?= date('d/m/Y', strtotime($d['date_pointage'])) ?></td>
                                                                    <td style="padding: 0.5rem;">
                                                                        <?= htmlspecialchars($d['prenom'] . ' ' . $d['nom']) ?></td>
                                                                    <td
                                                                        style="padding: 0.5rem; text-align: right; font-weight: 600;">
                                                                        <?= number_format($d['heures'], 2) ?>h</td>
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
                            style="margin-top: 2.5rem; display: none; align-items: center; justify-content: space-between; padding: 1.5rem; background: rgba(14, 165, 233, 0.05); border-radius: var(--radius-lg); border: 1px solid rgba(14, 165, 233, 0.2);">
                            <div>
                                <p style="font-weight: 700; color: var(--accent-cyan);">Synchronisation avec Business
                                    Central</p>
                                <p style="font-size: 0.8rem; color: var(--text-muted);"><span id="syncCount">0</span>
                                    pointage(s) sélectionné(s)</p>
                            </div>
                            <button type="submit" class="btn btn-primary"
                                onclick="return confirm('Lancer la synchronisation vers Business Central ?')">
                                Valider et Synchroniser →
                            </button>
                        </div>
                    <?php endif; ?>

                    <div id="hiddenInputsContainer"></div>
                </form>

                <!-- Historique -->
                <?php if (!empty($syncLogs)): ?>
                    <div style="margin-top: 4rem;">
                        <h4
                            style="color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem; letter-spacing: 1px; margin-bottom: 1.5rem;">
                            Dernières Synchronisations</h4>
                        <div class="glass" style="border-radius: var(--radius-md); padding: 1rem;">
                            <?php foreach ($syncLogs as $log): ?>
                                <div
                                    style="display: flex; justify-content: space-between; padding: 0.75rem; border-bottom: 1px solid rgba(255,255,255,0.03); font-size: 0.85rem;">
                                    <span><?= date('d/m H:i', strtotime($log['created_at'])) ?></span>
                                    <span style="font-weight: 600;"><?= $log['nb_pointages'] ?> pointage(s)</span>
                                    <span
                                        style="color: <?= $log['status'] === 'success' ? 'var(--success)' : 'var(--error)' ?>"><?= strtoupper($log['status']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
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
                countEl.textContent = allIds.length;
            }
        }
    </script>
</body>

</html>