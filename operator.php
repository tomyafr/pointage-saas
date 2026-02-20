<?php
require_once __DIR__ . '/includes/config.php';
requireAuth('operateur');

$db = getDB();
$userId = $_SESSION['user_id'];
$week = getCurrentWeekDates();
$today = date('Y-m-d');
$message = '';
$messageType = '';

// Traitement du formulaire de saisie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'saisir') {
        $numeroOf = strtoupper(trim($_POST['numero_of'] ?? ''));
        $heures = floatval($_POST['heures'] ?? 0);
        $datePointage = $_POST['date_pointage'] ?? $today;
        
        if (empty($numeroOf)) {
            $message = 'Le numéro d\'OF est obligatoire.';
            $messageType = 'error';
        } elseif ($heures <= 0 || $heures > 24) {
            $message = 'Le nombre d\'heures doit être entre 0.25 et 24.';
            $messageType = 'error';
        } else {
            try {
                // Upsert: met à jour si déjà existant pour ce jour/OF, sinon insère
                $stmt = $db->prepare('
                    INSERT INTO pointages (user_id, numero_of, heures, date_pointage)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE heures = VALUES(heures), updated_at = NOW()
                ');
                $stmt->execute([$userId, $numeroOf, $heures, $datePointage]);
                $message = "✓ {$heures}h enregistrées sur OF {$numeroOf}";
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = 'Erreur lors de l\'enregistrement.';
                $messageType = 'error';
            }
        }
    } elseif ($_POST['action'] === 'supprimer') {
        $pointageId = intval($_POST['pointage_id'] ?? 0);
        if ($pointageId > 0) {
            $stmt = $db->prepare('DELETE FROM pointages WHERE id = ? AND user_id = ? AND synced_bc = 0');
            $stmt->execute([$pointageId, $userId]);
            $message = 'Pointage supprimé.';
            $messageType = 'success';
        }
    }
}

// Récupérer les pointages de la semaine
$stmt = $db->prepare('
    SELECT id, numero_of, heures, date_pointage, synced_bc 
    FROM pointages 
    WHERE user_id = ? AND date_pointage BETWEEN ? AND ?
    ORDER BY date_pointage ASC, numero_of ASC
');
$stmt->execute([$userId, $week['monday'], $week['sunday']]);
$pointages = $stmt->fetchAll();

// Calculer les totaux
$totalSemaine = 0;
$totalAujourdhui = 0;
$pointagesParJour = [];
$ofsUtilises = [];

foreach ($pointages as $p) {
    $totalSemaine += $p['heures'];
    if ($p['date_pointage'] === $today) {
        $totalAujourdhui += $p['heures'];
    }
    $pointagesParJour[$p['date_pointage']][] = $p;
    if (!in_array($p['numero_of'], $ofsUtilises)) {
        $ofsUtilises[] = $p['numero_of'];
    }
}

$joursFr = ['Monday'=>'Lundi','Tuesday'=>'Mardi','Wednesday'=>'Mercredi','Thursday'=>'Jeudi','Friday'=>'Vendredi','Saturday'=>'Samedi','Sunday'=>'Dimanche'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#0a0f1a">
    <title>Pointage - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body>
    <div class="app-container">
        <!-- Header -->
        <header class="app-header">
            <div class="app-logo">
                <div class="app-logo-icon">⏱</div>
                <div>
                    <div class="app-logo-text"><?= APP_NAME ?></div>
                    <div class="app-logo-sub">Opérateur</div>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <button onclick="NotifManager.testNotification(new Date().getDay()===5)" title="Tester la notification" style="background:none;border:1px solid var(--border);border-radius:50%;width:32px;height:32px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;" class="btn-logout">🔔</button>
                <span class="user-badge"><?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?></span>
                <a href="logout.php" class="btn-logout">Quitter</a>
            </div>
        </header>
        
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <!-- Stats rapides -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= number_format($totalAujourdhui, 1) ?></div>
                <div class="stat-label">Heures aujourd'hui</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($totalSemaine, 1) ?></div>
                <div class="stat-label">Total semaine</div>
            </div>
        </div>
        
        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="switchTab('saisie')">Saisie</button>
            <button class="tab" onclick="switchTab('semaine')">Ma semaine</button>
        </div>
        
        <!-- Tab Saisie -->
        <div id="tab-saisie" class="tab-content active">
            <form method="POST">
                <input type="hidden" name="action" value="saisir">
                
                <div class="card">
                    <div class="card-title">Nouvelle saisie</div>
                    
                    <div class="form-group">
                        <label class="form-label" for="date_pointage">Date</label>
                        <input 
                            type="date" 
                            id="date_pointage" 
                            name="date_pointage" 
                            class="form-input"
                            value="<?= $today ?>"
                            min="<?= $week['monday'] ?>"
                            max="<?= $week['sunday'] ?>"
                            required
                        >
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="numero_of">Numéro d'OF</label>
                        <input 
                            type="text" 
                            id="numero_of" 
                            name="numero_of" 
                            class="form-input" 
                            placeholder="Ex: OF-2025-001"
                            autocapitalize="characters"
                            list="of-list"
                            required
                        >
                        <?php if (!empty($ofsUtilises)): ?>
                        <datalist id="of-list">
                            <?php foreach ($ofsUtilises as $of): ?>
                                <option value="<?= htmlspecialchars($of) ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="heures">Heures travaillées</label>
                        <input 
                            type="number" 
                            id="heures" 
                            name="heures" 
                            class="form-input form-input-large" 
                            placeholder="0.0"
                            step="0.25"
                            min="0.25"
                            max="24"
                            required
                        >
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        Enregistrer le pointage →
                    </button>
                </div>
            </form>
            
            <!-- Pointages du jour -->
            <?php if (!empty($pointagesParJour[$today])): ?>
            <div class="card">
                <div class="card-title">Aujourd'hui — <?= date('d/m/Y') ?></div>
                <table class="week-table">
                    <thead>
                        <tr>
                            <th>OF</th>
                            <th style="text-align:right;">Heures</th>
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pointagesParJour[$today] as $p): ?>
                        <tr>
                            <td class="of-number"><?= htmlspecialchars($p['numero_of']) ?></td>
                            <td class="hours-cell" style="text-align:right;"><?= number_format($p['heures'], 2) ?>h</td>
                            <td>
                                <?php if (!$p['synced_bc']): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer ce pointage ?')">
                                    <input type="hidden" name="action" value="supprimer">
                                    <input type="hidden" name="pointage_id" value="<?= $p['id'] ?>">
                                    <button type="submit" style="background:none;border:none;color:var(--error);cursor:pointer;font-size:1rem;">✕</button>
                                </form>
                                <?php else: ?>
                                    <span style="color:var(--success);">✓</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Tab Semaine -->
        <div id="tab-semaine" class="tab-content">
            <div class="card">
                <div class="card-title">Semaine du <?= date('d/m', strtotime($week['monday'])) ?> au <?= date('d/m/Y', strtotime($week['sunday'])) ?></div>
                
                <?php if (empty($pointages)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📋</div>
                        <p class="empty-state-text">Aucun pointage cette semaine</p>
                    </div>
                <?php else: ?>
                    <table class="week-table">
                        <thead>
                            <tr>
                                <th>Jour</th>
                                <th>OF</th>
                                <th style="text-align:right;">Heures</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            foreach ($week['dates'] as $i => $date):
                                $dt = new DateTime($date);
                                $jourNom = $joursFr[$dt->format('l')] ?? $dt->format('l');
                                $isToday = ($date === $today);
                                $jourPointages = $pointagesParJour[$date] ?? [];
                                $jourTotal = 0;
                                
                                if (!empty($jourPointages)):
                                    foreach ($jourPointages as $idx => $p):
                                        $jourTotal += $p['heures'];
                            ?>
                            <tr class="<?= $isToday ? 'today' : '' ?>">
                                <td><?= $idx === 0 ? $jourNom . ' ' . $dt->format('d') : '' ?></td>
                                <td style="font-family:var(--font-mono);font-size:0.8rem;"><?= htmlspecialchars($p['numero_of']) ?></td>
                                <td class="hours-cell" style="text-align:right;"><?= number_format($p['heures'], 2) ?>h</td>
                            </tr>
                            <?php 
                                    endforeach;
                                else:
                                    // Afficher les jours ouvrés sans pointage (lun-ven)
                                    if ($i < 5):
                            ?>
                            <tr class="<?= $isToday ? 'today' : '' ?>">
                                <td><?= $jourNom . ' ' . $dt->format('d') ?></td>
                                <td style="color:var(--text-muted);">—</td>
                                <td style="text-align:right;color:var(--text-muted);">0.00h</td>
                            </tr>
                            <?php 
                                    endif;
                                endif;
                            endforeach;
                            ?>
                            <tr class="total-row">
                                <td colspan="2">TOTAL SEMAINE</td>
                                <td style="text-align:right;"><?= number_format($totalSemaine, 2) ?>h</td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    function switchTab(name) {
        // Update tabs
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        
        event.target.classList.add('active');
        document.getElementById('tab-' + name).classList.add('active');
    }
    </script>
    <script src="assets/notifications.js"></script>
</body>
</html>
