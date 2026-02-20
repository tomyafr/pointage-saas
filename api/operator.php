<?php
require_once __DIR__ . '/../includes/config.php';
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
                $stmt = $db->prepare('
                    INSERT INTO pointages (user_id, numero_of, heures, date_pointage)
                    VALUES (?, ?, ?, ?)
                    ON CONFLICT (user_id, date_pointage, numero_of) 
                    DO UPDATE SET heures = EXCLUDED.heures, updated_at = NOW()
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
            $stmt = $db->prepare('DELETE FROM pointages WHERE id = ? AND user_id = ? AND synced_bc IS FALSE');
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

$joursFr = ['Monday' => 'Lundi', 'Tuesday' => 'Mardi', 'Wednesday' => 'Mercredi', 'Thursday' => 'Jeudi', 'Friday' => 'Vendredi', 'Saturday' => 'Samedi', 'Sunday' => 'Dimanche'];
$weeklyTarget = 35;
$weeklyProgress = min(100, round(($totalSemaine / $weeklyTarget) * 100));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Espace Opérateur | Raoul Lenoir</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="assets/icon-192.png">
    <meta name="theme-color" content="#020617">
    <style>
        .of-card { transition: var(--transition-smooth); }
        .of-card:hover { border-color: var(--primary); background: var(--primary-subtle); }
        .date-badge {
            display: inline-block; padding: 0.2rem 0.6rem;
            background: rgba(14, 165, 233, 0.1); color: var(--accent-cyan);
            border-radius: 20px; font-size: 0.7rem; font-weight: 600; margin-bottom: 0.4rem;
        }
        .quick-hour { 
            display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.75rem;
        }
        .quick-hour button {
            flex: 1; min-width: 50px; padding: 0.6rem 0; border-radius: var(--radius-sm);
            background: rgba(255,255,255,0.04); border: 1px solid var(--glass-border);
            color: var(--text-muted); font-family: var(--font-mono); font-size: 0.85rem;
            font-weight: 700; cursor: pointer; transition: var(--transition-fast);
        }
        .quick-hour button:hover {
            background: var(--primary-subtle); border-color: var(--primary); color: var(--primary);
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
                <a href="https://www.118712.fr/professionnels/X0dXWVBRGgI" target="_blank" rel="noopener" class="brand-icon" style="width: 44px; height: 44px; font-size: 1.3rem; margin: 0 0 1rem 0;">🧲</a>
                <h2 style="font-size: 1.15rem;"><span class="text-gradient">Raoul Lenoir</span></h2>
                <p style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; margin-top: 0.25rem;">Espace Opérateur</p>
            </div>

            <nav style="display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 2rem;">
                <button class="btn btn-primary" onclick="switchTab('saisie')" id="nav-saisie" style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <span>📝</span> Saisie Rapide
                </button>
                <button class="btn btn-ghost" onclick="switchTab('semaine')" id="nav-semaine" style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <span>📅</span> Ma Semaine
                </button>
            </nav>

            <!-- Weekly Progress -->
            <div style="margin-bottom: 2rem; padding: 1.25rem; background: rgba(255,255,255,0.02); border-radius: var(--radius-md); border: 1px solid var(--glass-border);">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.4rem;">
                    <span style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.05em;">Objectif Hebdo</span>
                    <span style="font-size: 0.75rem; font-weight: 700; color: var(--primary);"><?= number_format($totalSemaine, 1) ?>h / <?= $weeklyTarget ?>h</span>
                </div>
                <div class="progress-bar-container">
                    <div class="progress-bar-fill" style="width: <?= $weeklyProgress ?>%;"></div>
                </div>
                <p style="font-size: 0.65rem; color: var(--text-dim); margin-top: 0.4rem; text-align: right;"><?= $weeklyProgress ?>% atteint</p>
            </div>

            <div style="margin-top: auto; padding-top: 1.5rem; border-top: 1px solid var(--glass-border);">
                <p style="font-size: 0.65rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.4rem;">Connecté</p>
                <p style="font-weight: 600; font-size: 0.85rem;"><?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?></p>
                <a href="logout.php" class="btn btn-ghost" style="width: 100%; margin-top: 1rem; color: var(--error); border-color: rgba(244, 63, 94, 0.15); font-size: 0.75rem; padding: 0.6rem;">
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

            <div class="stats-grid animate-in">
                <div class="stat-item glass">
                    <span class="stat-label">Aujourd'hui</span>
                    <span class="stat-value"><?= number_format($totalAujourdhui, 1) ?><small style="font-size: 0.45em; opacity: 0.5; margin-left: 2px;">H</small></span>
                </div>
                <div class="stat-item glass">
                    <span class="stat-label">Cette semaine</span>
                    <span class="stat-value"><?= number_format($totalSemaine, 1) ?><small style="font-size: 0.45em; opacity: 0.5; margin-left: 2px;">H</small></span>
                </div>
                <div class="stat-item glass">
                    <span class="stat-label">OFs en cours</span>
                    <span class="stat-value" style="color: var(--accent-cyan);"><?= count($ofsUtilises) ?></span>
                </div>
            </div>

            <!-- Tab Saisie -->
            <div id="tab-saisie" class="animate-in">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                    <section>
                        <form method="POST" class="card glass">
                            <input type="hidden" name="action" value="saisir">
                            <h3 style="margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem;">
                                <span style="font-size: 1.4rem;">⏱</span> Nouveau Pointage
                            </h3>

                            <div class="form-group">
                                <label class="label">Date</label>
                                <input type="date" name="date_pointage" class="input"
                                    value="<?= $today ?>" min="<?= $week['monday'] ?>" max="<?= $week['sunday'] ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="label">Ordre de Fabrication</label>
                                <input type="text" name="numero_of" class="input" id="ofInput"
                                    placeholder="Ex: OF-2025-001" autocapitalize="characters" list="of-list" required>
                                <datalist id="of-list">
                                    <?php foreach ($ofsUtilises as $of): ?>
                                        <option value="<?= htmlspecialchars($of) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>

                            <div class="form-group">
                                <label class="label">Heures travaillées</label>
                                <input type="number" name="heures" id="heuresInput" class="input"
                                    style="font-size: 2.2rem; font-weight: 900; text-align: center; color: var(--primary); height: 5rem;"
                                    placeholder="0.0" step="0.25" min="0.25" max="24" required>
                                <div class="quick-hour">
                                    <button type="button" onclick="setHeures(0.5)">0.5</button>
                                    <button type="button" onclick="setHeures(1)">1</button>
                                    <button type="button" onclick="setHeures(2)">2</button>
                                    <button type="button" onclick="setHeures(4)">4</button>
                                    <button type="button" onclick="setHeures(7)">7</button>
                                    <button type="button" onclick="setHeures(8)">8</button>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary" style="width: 100%; height: 3.5rem; font-size: 0.95rem;">
                                Enregistrer le Pointage →
                            </button>
                        </form>
                    </section>

                    <section>
                        <h3 style="margin-bottom: 1.25rem; color: var(--text-dim); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px;">
                            📋 Pointages du jour — <?= date('d/m/Y') ?>
                        </h3>
                        <?php if (empty($pointagesParJour[$today])): ?>
                            <div class="card glass" style="text-align: center; padding: 4rem 2rem;">
                                <p style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.3;">📝</p>
                                <p style="color: var(--text-dim); font-size: 0.9rem;">Aucun pointage aujourd'hui</p>
                                <p style="color: var(--text-dim); font-size: 0.75rem; margin-top: 0.5rem;">Commencez par enregistrer vos heures ←</p>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                                <?php foreach ($pointagesParJour[$today] as $p): ?>
                                    <div class="card glass of-card" style="padding: 1.25rem 1.5rem; display: flex; align-items: center; justify-content: space-between;">
                                        <div>
                                            <span class="date-badge">Aujourd'hui</span>
                                            <p style="font-family: var(--font-mono); font-weight: 700; font-size: 1rem;"><?= htmlspecialchars($p['numero_of']) ?></p>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 1rem;">
                                            <span style="font-size: 1.4rem; font-weight: 900; color: var(--primary);"><?= number_format($p['heures'], 2) ?>h</span>
                                            <?php if (!$p['synced_bc']): ?>
                                                <form method="POST" onsubmit="return confirm('Supprimer ce pointage ?')">
                                                    <input type="hidden" name="action" value="supprimer">
                                                    <input type="hidden" name="pointage_id" value="<?= $p['id'] ?>">
                                                    <button type="submit" class="btn btn-ghost" style="padding: 0.4rem 0.6rem; color: var(--error); border-color: rgba(244, 63, 94, 0.15); font-size: 0.85rem;">✕</button>
                                                </form>
                                            <?php else: ?>
                                                <span title="Synchronisé BC" style="color:var(--success); font-size: 1.1rem;" data-tooltip="Synchronisé">✓</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </div>

            <!-- Tab Semaine -->
            <div id="tab-semaine" style="display: none;">
                <div class="card glass animate-in">
                    <h3 style="margin-bottom: 2rem;">Semaine du <?= date('d/m', strtotime($week['monday'])) ?> au <?= date('d/m/Y', strtotime($week['sunday'])) ?></h3>
                    
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; min-width: 550px;">
                            <thead>
                                <tr style="text-align: left; color: var(--text-dim); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px;">
                                    <th style="padding: 1rem; border-bottom: 2px solid var(--glass-border);">Jour</th>
                                    <th style="padding: 1rem; border-bottom: 2px solid var(--glass-border);">Détails</th>
                                    <th style="padding: 1rem; border-bottom: 2px solid var(--glass-border); text-align: right;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($week['dates'] as $i => $date):
                                    $dt = new DateTime($date);
                                    $jourNom = $joursFr[$dt->format('l')] ?? $dt->format('l');
                                    $isToday = ($date === $today);
                                    $jourPointages = $pointagesParJour[$date] ?? [];
                                    $jourTotal = 0;
                                    foreach($jourPointages as $p) $jourTotal += $p['heures'];
                                    if ($i >= 5 && empty($jourPointages)) continue;
                                ?>
                                    <tr style="background: <?= $isToday ? 'var(--primary-subtle)' : 'transparent' ?>; border-bottom: 1px solid rgba(255,255,255,0.03);">
                                        <td style="padding: 1.25rem 1rem;">
                                            <p style="font-weight: 700; color: <?= $isToday ? 'var(--primary)' : 'var(--text-main)' ?>;">
                                                <?= $jourNom ?> <?= $isToday ? '•' : '' ?>
                                            </p>
                                            <p style="font-size: 0.7rem; color: var(--text-dim);"><?= $dt->format('d/m') ?></p>
                                        </td>
                                        <td style="padding: 1.25rem 1rem;">
                                            <?php if (empty($jourPointages)): ?>
                                                <span style="color: var(--text-dim); font-size: 0.8rem;">—</span>
                                            <?php else: ?>
                                                <div style="display: flex; flex-wrap: wrap; gap: 0.4rem;">
                                                    <?php foreach ($jourPointages as $p): ?>
                                                        <span style="background: rgba(255,255,255,0.04); padding: 0.2rem 0.5rem; border-radius: 6px; font-family: var(--font-mono); font-size: 0.7rem; border: 1px solid var(--glass-border);">
                                                            <?= htmlspecialchars($p['numero_of']) ?>: <b><?= number_format($p['heures'], 1) ?>h</b>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 1.25rem 1rem; text-align: right; font-weight: 800; font-size: 1.05rem; color: <?= $jourTotal > 0 ? 'var(--primary)' : 'var(--text-dim)' ?>;">
                                            <?= number_format($jourTotal, 1) ?>h
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background: rgba(14, 165, 233, 0.05);">
                                    <td colspan="2" style="padding: 1.25rem 1rem; font-weight: 700; font-size: 1rem;">TOTAL SEMAINE</td>
                                    <td style="padding: 1.25rem 1rem; text-align: right; font-weight: 900; font-size: 1.2rem; color: var(--accent-cyan);"><?= number_format($totalSemaine, 2) ?>h</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div class="app-footer">
                <a href="https://www.118712.fr/professionnels/X0dXWVBRGgI" target="_blank" rel="noopener">Raoul Lenoir SAS</a> · V<?= APP_VERSION ?> · <?= date('Y') ?>
            </div>
        </main>
    </div>

    <script>
        function switchTab(name) {
            document.getElementById('tab-saisie').style.display = name === 'saisie' ? 'block' : 'none';
            document.getElementById('tab-semaine').style.display = name === 'semaine' ? 'block' : 'none';
            
            document.getElementById('nav-saisie').className = name === 'saisie' ? 'btn btn-primary' : 'btn btn-ghost';
            document.getElementById('nav-semaine').className = name === 'semaine' ? 'btn btn-primary' : 'btn btn-ghost';
        }

        function setHeures(val) {
            document.getElementById('heuresInput').value = val;
        }

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('open');
        }

        // Auto-uppercase OF input
        const ofInput = document.getElementById('ofInput');
        if (ofInput) ofInput.addEventListener('input', function() { this.value = this.value.toUpperCase(); });
    </script>
    <script src="assets/notifications.js"></script>
</body>
</html>
