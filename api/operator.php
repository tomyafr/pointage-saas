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
                // Upsert: met à jour si déjà existant pour ce jour/OF, sinon insère (Syntaxe Postgres)
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
    <style>
        .of-card {
            transition: var(--transition-smooth);
        }
        .of-card:hover {
            border-color: var(--primary);
            background: rgba(255, 179, 0, 0.05);
        }
        .date-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: rgba(14, 165, 233, 0.1);
            color: var(--accent-cyan);
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="dashboard-layout animate-in">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="login-header" style="text-align: left; margin-bottom: 3rem;">
                <div class="brand-icon" style="width: 48px; height: 48px; font-size: 1.5rem; margin: 0 0 1rem 0;">🧲</div>
                <h2 style="font-size: 1.25rem;"><span class="text-gradient">Raoul Lenoir</span></h2>
                <p style="font-size: 0.75rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px;">Espace Opérateur</p>
            </div>

            <nav style="display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 3rem;">
                <button class="btn btn-primary" onclick="switchTab('saisie')" style="justify-content: flex-start; padding: 0.75rem 1.25rem;">
                    <span>📝</span> Saisie Rapide
                </button>
                <button class="btn btn-ghost" onclick="switchTab('semaine')" style="justify-content: flex-start; padding: 0.75rem 1.25rem;">
                    <span>📅</span> Ma Semaine
                </button>
            </nav>

            <div style="margin-top: auto; padding-top: 2rem; border-top: 1px solid var(--glass-border);">
                <div style="margin-bottom: 1.5rem;">
                    <p style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.5rem;">Session active</p>
                    <p style="font-weight: 600; font-size: 0.9rem;"><?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?></p>
                </div>
                <a href="logout.php" class="btn btn-ghost" style="width: 100%; color: var(--error); border-color: rgba(244, 63, 94, 0.2);">
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
                    <span class="stat-label">Aujourd'hui</span>
                    <span class="stat-value"><?= number_format($totalAujourdhui, 1) ?> <small style="font-size: 0.5em; opacity: 0.6;">H</small></span>
                </div>
                <div class="stat-item glass">
                    <span class="stat-label">Total Semaine</span>
                    <span class="stat-value"><?= number_format($totalSemaine, 1) ?> <small style="font-size: 0.5em; opacity: 0.6;">H</small></span>
                </div>
            </div>

            <!-- Tab Saisie -->
            <div id="tab-saisie" class="tab-content active animate-in">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                    <section>
                        <form method="POST" class="card glass">
                            <input type="hidden" name="action" value="saisir">
                            <h3 style="margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem;">
                                <span style="font-size: 1.5rem;">⏱</span> Nouveau Pointage
                            </h3>

                            <div class="form-group">
                                <label class="label">Période du jour</label>
                                <input type="date" name="date_pointage" class="input"
                                    value="<?= $today ?>" min="<?= $week['monday'] ?>" max="<?= $week['sunday'] ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="label">Ordre de Fabrication (OF)</label>
                                <input type="text" name="numero_of" class="input"
                                    placeholder="Ex: OF-2025-001" autocapitalize="characters" list="of-list" required>
                                <datalist id="of-list">
                                    <?php foreach ($ofsUtilises as $of): ?>
                                        <option value="<?= htmlspecialchars($of) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>

                            <div class="form-group">
                                <label class="label">Durée de l'opération (Heures)</label>
                                <input type="number" name="heures" class="input"
                                    style="font-size: 2rem; font-weight: 800; text-align: center; color: var(--primary);"
                                    placeholder="0.0" step="0.25" min="0.25" max="24" required>
                            </div>

                            <button type="submit" class="btn btn-primary" style="width: 100%; height: 4rem;">
                                Enregistrer le Pointage
                            </button>
                        </form>
                    </section>

                    <section>
                        <h3 style="margin-bottom: 1.5rem; color: var(--text-muted); font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px;">
                            Pointages récents
                        </h3>
                        <?php if (empty($pointagesParJour[$today])): ?>
                            <div class="card glass" style="text-align: center; padding: 4rem 2rem; opacity: 0.5;">
                                <p>Aucun pointage enregistré aujourd'hui.</p>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 1rem;">
                                <?php foreach ($pointagesParJour[$today] as $p): ?>
                                    <div class="card glass of-card" style="padding: 1.25rem 1.5rem; display: flex; align-items: center; justify-content: space-between;">
                                        <div>
                                            <span class="date-badge">Aujourd'hui</span>
                                            <p style="font-family: var(--font-mono); font-weight: 700; font-size: 1.1rem;"><?= htmlspecialchars($p['numero_of']) ?></p>
                                        </div>
                                        <div style="text-align: right; display: flex; align-items: center; gap: 1.5rem;">
                                            <span style="font-size: 1.5rem; font-weight: 800; color: var(--primary);"><?= number_format($p['heures'], 2) ?>h</span>
                                            
                                            <?php if (!$p['synced_bc']): ?>
                                                <form method="POST" onsubmit="return confirm('Supprimer ce pointage ?')">
                                                    <input type="hidden" name="action" value="supprimer">
                                                    <input type="hidden" name="pointage_id" value="<?= $p['id'] ?>">
                                                    <button type="submit" class="btn btn-ghost" style="padding: 0.5rem; color: var(--error); border-color: rgba(244, 63, 94, 0.2);">✕</button>
                                                </form>
                                            <?php else: ?>
                                                <span title="Synchronisé avec Business Central" style="color:var(--success); font-size: 1.2rem;">✓</span>
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
            <div id="tab-semaine" class="tab-content animate-in">
                <div class="card glass">
                    <h3 style="margin-bottom: 2rem;">Vue hebdomadaire <small style="font-weight: 400; color: var(--text-dim);">du <?= date('d/m', strtotime($week['monday'])) ?> au <?= date('d/m/Y', strtotime($week['sunday'])) ?></small></h3>
                    
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; min-width: 600px;">
                            <thead>
                                <tr style="text-align: left; color: var(--text-dim); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px;">
                                    <th style="padding: 1rem; border-bottom: 1px solid var(--glass-border);">Jour</th>
                                    <th style="padding: 1rem; border-bottom: 1px solid var(--glass-border);">Détails des OF</th>
                                    <th style="padding: 1rem; border-bottom: 1px solid var(--glass-border); text-align: right;">Total Heures</th>
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
                                    foreach($jourPointages as $p) $jourTotal += $p['heures'];
                                ?>
                                    <tr style="background: <?= $isToday ? 'rgba(255,179,0,0.05)' : 'transparent' ?>; border-bottom: 1px solid rgba(255,255,255,0.03);">
                                        <td style="padding: 1.5rem 1rem;">
                                            <p style="font-weight: 700; color: <?= $isToday ? 'var(--primary)' : 'var(--text-main)' ?>;"><?= $jourNom ?></p>
                                            <p style="font-size: 0.75rem; color: var(--text-dim);"><?= $dt->format('d/m/Y') ?></p>
                                        </td>
                                        <td style="padding: 1.5rem 1rem;">
                                            <?php if (empty($jourPointages)): ?>
                                                <span style="color: var(--text-dim); font-size: 0.85rem;">—</span>
                                            <?php else: ?>
                                                <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                                                    <?php foreach ($jourPointages as $p): ?>
                                                        <span style="background: rgba(255,255,255,0.05); padding: 0.25rem 0.6rem; border-radius: 6px; font-family: var(--font-mono); font-size: 0.75rem; border: 1px solid var(--glass-border);">
                                                            <?= htmlspecialchars($p['numero_of']) ?>: <b><?= number_format($p['heures'], 1) ?>h</b>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 1.5rem 1rem; text-align: right; font-weight: 800; color: <?= $jourTotal > 0 ? 'var(--primary)' : 'var(--text-dim)' ?>;">
                                            <?= number_format($jourTotal, 2) ?>h
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background: rgba(14, 165, 233, 0.05); font-size: 1.1rem;">
                                    <td colspan="2" style="padding: 1.5rem 1rem; font-weight: 700;">TOTAL DE LA SEMAINE</td>
                                    <td style="padding: 1.5rem 1rem; text-align: right; font-weight: 900; color: var(--accent-cyan);"><?= number_format($totalSemaine, 2) ?>h</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        function switchTab(name) {
            document.querySelectorAll('.tab-content').forEach(t => t.style.display = 'none');
            document.querySelectorAll('.sidebar .btn').forEach(b => {
                b.classList.remove('btn-primary');
                b.classList.add('btn-ghost');
            });

            document.getElementById('tab-' + name).style.display = 'block';
            const btn = event.currentTarget;
            btn.classList.remove('btn-ghost');
            btn.classList.add('btn-primary');
        }
        
        // Initial state
        document.getElementById('tab-semaine').style.display = 'none';
    </script>
</body>
</html>
