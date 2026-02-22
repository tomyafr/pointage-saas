<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth('operateur');

$db = getDB();
try {
    $db->exec("CREATE TABLE IF NOT EXISTS active_sessions (user_id INT PRIMARY KEY REFERENCES users(id), numero_of VARCHAR(50) NOT NULL, start_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
} catch (Exception $e) {}

$userId = $_SESSION['user_id'];
$week = getCurrentWeekDates();
$today = date('Y-m-d');
$message = '';
$messageType = '';

// Traitement du formulaire de saisie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Vérification CSRF pour toutes les actions POST
    verifyCsrfToken();

    if ($_POST['action'] === 'saisir') {
        $numeroOf = strtoupper(trim($_POST['numero_of'] ?? ''));
        $heures = floatval($_POST['heures'] ?? 0);
        $datePointage = $_POST['date_pointage'] ?? $today;

        // Validation du numéro OF
        if (empty($numeroOf)) {
            $message = 'Le numéro d\'OF est obligatoire.';
            $messageType = 'error';
        } elseif (strlen($numeroOf) > 50) {
            $message = 'Le numéro d\'OF est trop long (max 50 caractères).';
            $messageType = 'error';
        } elseif ($heures <= 0 || $heures > 24) {
            $message = 'Le nombre d\'heures doit être entre 0.25 et 24.';
            $messageType = 'error';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datePointage)) {
            // Validation format de date
            $message = 'Format de date invalide.';
            $messageType = 'error';
        } elseif ($datePointage < $week['monday'] || $datePointage > $week['sunday']) {
            // Validation côté SERVEUR : date doit être dans la semaine courante
            $message = 'La date doit être dans la semaine en cours.';
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
                logAudit('ENTRY_CREATED', "OF: $numeroOf, H: $heures, Date: $datePointage");
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
            // Vérifier que ce pointage appartient bien à l'utilisateur courant (protection IDOR)
            $stmt = $db->prepare('DELETE FROM pointages WHERE id = ? AND user_id = ? AND synced_bc IS FALSE');
            $stmt->execute([$pointageId, $userId]);
            logAudit('ENTRY_DELETED', "Pointage ID: $pointageId");
            $message = 'Pointage supprimé.';
            $messageType = 'success';
        }
    } elseif ($_POST['action'] === 'start_production') {
        $numeroOf = strtoupper(trim($_POST['numero_of'] ?? ''));
        if (empty($numeroOf) || strlen($numeroOf) > 50) {
            $message = 'Numéro OF invalide.';
            $messageType = 'error';
        } else {
            try {
                // Vérifier s'il a déjà une session
                $stmt = $db->prepare('SELECT numero_of FROM active_sessions WHERE user_id = ?');
                $stmt->execute([$userId]);
                if ($stmt->fetch()) {
                    $message = 'Vous avez déjà une production en cours.';
                    $messageType = 'error';
                } else {
                    $stmt = $db->prepare('INSERT INTO active_sessions (user_id, numero_of) VALUES (?, ?)');
                    $stmt->execute([$userId, $numeroOf]);
                    logAudit('PROD_START', "OF: $numeroOf");
                    $message = "Production démarrée sur l'OF {$numeroOf}";
                    $messageType = 'success';
                }
            } catch (PDOException $e) {
                $message = 'Erreur lors du démarrage.';
                $messageType = 'error';
            }
        }
    } elseif ($_POST['action'] === 'stop_production') {
        try {
            $stmt = $db->prepare('SELECT numero_of, start_time FROM active_sessions WHERE user_id = ?');
            $stmt->execute([$userId]);
            $session = $stmt->fetch();
            
            if ($session) {
                // Calculer les heures écoulées de manière PRECISE et EXACTE
                $start = strtotime($session['start_time']);
                $end = time();
                $diffHours = ($end - $start) / 3600;
                // Enregistrement au temps exact (ex: 1h43 = 1.72h), minimum de 0.25h (15min) pour validation
                $heures = max(0.25, round($diffHours, 2));
                $numeroOf = $session['numero_of'];
                $datePointage = date('Y-m-d', $start); // Garder la date de début

                // Insérer/Mettre à jour le pointage (cumulatif si déjà des heures ce jour-là sur cet OF)
                $db->beginTransaction();
                
                $stmtInsert = $db->prepare('
                    INSERT INTO pointages (user_id, numero_of, heures, date_pointage)
                    VALUES (?, ?, ?, ?)
                    ON CONFLICT (user_id, date_pointage, numero_of) 
                    DO UPDATE SET heures = pointages.heures + EXCLUDED.heures, updated_at = NOW()
                ');
                $stmtInsert->execute([$userId, $numeroOf, $heures, $datePointage]);
                
                // Supprimer la session
                $stmtDelete = $db->prepare('DELETE FROM active_sessions WHERE user_id = ?');
                $stmtDelete->execute([$userId]);
                
                $db->commit();
                
                logAudit('PROD_STOP', "OF: $numeroOf, Heures: $heures");
                $message = "Production terminée. {$heures}h enregistrées sur l'OF {$numeroOf}.";
                $messageType = 'success';
            } else {
                $message = 'Aucune production en cours.';
                $messageType = 'error';
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $message = 'Erreur lors de l’arrêt de production.';
            $messageType = 'error';
        }
    }
}

// Vérifier la session de prod en cours pour l'affichage
$stmtActive = $db->prepare('SELECT numero_of, start_time FROM active_sessions WHERE user_id = ?');
$stmtActive->execute([$userId]);
$activeSession = $stmtActive->fetch();

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Espace Opérateur | Raoul Lenoir</title>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/icon-192.png">
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
            flex: 1; min-width: 50px; padding: 0.85rem 0; border-radius: var(--radius-sm);
            background: rgba(255,255,255,0.04); border: 1px solid var(--glass-border);
            color: var(--text-muted); font-family: var(--font-mono); font-size: 0.85rem;
            font-weight: 700; cursor: pointer; transition: var(--transition-fast);
            min-height: 48px; touch-action: manipulation; -webkit-tap-highlight-color: transparent;
        }
        .quick-hour button:hover, .quick-hour button:active {
            background: var(--primary-subtle); border-color: var(--primary); color: var(--primary);
        }
    </style>
</head>
<body>
    <!-- ═══ HEADER MOBILE ═══ -->
    <header class="mobile-header">
        <!-- Logo cliquable = ouvre le menu -->
        <button class="mobile-logo-btn" onclick="toggleSidebar()" aria-label="Menu">
            <img src="/assets/logo-raoul-lenoir.svg" alt="Raoul Lenoir" class="mobile-header-logo"
                 style="filter:brightness(0) saturate(100%) invert(73%) sepia(86%) saturate(1063%) hue-rotate(358deg) brightness(101%) contrast(106%);">
        </button>
        <span class="mobile-header-title">Mon Pointage</span>
        <span class="mobile-header-user">
            <?php if (!empty($_SESSION['avatar'])): ?>
                <img src="<?= htmlspecialchars($_SESSION['avatar']) ?>" style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover; border: 1px solid var(--glass-border);">
            <?php else: ?>
                <?= htmlspecialchars($_SESSION['user_prenom']) ?>
            <?php endif; ?>
        </span>
    </header>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <div class="dashboard-layout">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <!-- Bouton fermer (X rouge) -->
            <button class="sidebar-close-btn" onclick="toggleSidebar()" aria-label="Fermer">&times;</button>
            <div style="margin-bottom: 2.5rem;">
                <a href="operator.php" class="brand-icon" style="display: block; width: 180px; height: auto; margin: 0 0 1rem 0;"><img src="/assets/logo-raoul-lenoir.svg" alt="Raoul Lenoir"></a>
                <h2 style="font-size: 1.15rem;"><span class="text-gradient">Raoul Lenoir</span></h2>
                <p style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; margin-top: 0.25rem;">Espace Opérateur</p>
            </div>

            <nav style="display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 2rem;">
                <button class="btn btn-primary sidebar-link" onclick="switchTab('saisie')" id="nav-saisie" style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <span>&#128221;</span> Saisie Rapide
                </button>
                <button class="btn btn-ghost sidebar-link" onclick="switchTab('semaine')" id="nav-semaine" style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <span>&#128197;</span> Ma Semaine
                </button>
                <a href="profile.php" class="btn btn-ghost sidebar-link" style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem; text-decoration: none; color: inherit;">
                    <span>&#128100;</span> Mon Profil
                </a>
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
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1rem;">
                    <div>
                        <p style="font-size: 0.65rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.4rem;">Connecté</p>
                        <p style="font-weight: 600; font-size: 0.85rem; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px;">
                            <?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?>
                        </p>
                    </div>
                    <?php if (!empty($_SESSION['avatar'])): ?>
                        <img src="<?= htmlspecialchars($_SESSION['avatar']) ?>" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 1px solid var(--glass-border);">
                    <?php else: ?>
                        <div style="width: 38px; height: 38px; border-radius: 50%; background: var(--primary); color: #000; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.85rem;">
                            <?= strtoupper(substr($_SESSION['user_prenom'], 0, 1) . substr($_SESSION['user_nom'], 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <a href="logout.php" class="btn btn-ghost" style="width: 100%; margin-top: 1rem; color: var(--error); border-color: rgba(244, 63, 94, 0.15); font-size: 0.75rem; padding: 0.6rem;">
                    Se déconnecter
                </a>
                <button onclick="if(window.notificationManager) window.notificationManager.requestPermission()" class="btn btn-ghost" style="width: 100%; margin-top: 0.5rem; font-size: 0.65rem; border: none; opacity: 0.5;">
                    🔔 Activer Notifications
                </button>
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
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;" class="saisie-grid">
                    <section>
                        <?php if ($activeSession): ?>
                            <div class="card glass" style="border: 1px solid var(--accent-cyan); background: rgba(14, 165, 233, 0.05); text-align: center;">
                                <h3 style="margin-bottom: 1rem; color: var(--accent-cyan); display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                                    <span class="spinner" style="border-top-color: var(--accent-cyan); width: 16px; height: 16px;"></span>
                                    PRODUCTION EN DIRECT
                                </h3>
                                <div style="font-family: var(--font-mono); font-size: 2.5rem; font-weight: 900; color: #fff; margin-bottom: 0.5rem;" id="liveTimer">
                                    00:00:00
                                </div>
                                <p style="color: var(--text-dim); font-size: 0.9rem; margin-bottom: 1.5rem;">
                                    OF en cours : <strong style="color: #fff;"><?= htmlspecialchars($activeSession['numero_of']) ?></strong>
                                </p>
                                <form method="POST">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="stop_production">
                                    <button type="submit" class="btn" style="width: 100%; background: var(--error); color: white; border: none; font-size: 1rem; font-weight: bold; padding: 1.25rem;">
                                        Arrêter et Sauvegarder (■)
                                    </button>
                                </form>
                            </div>
                            
                            <script>
                                // Timer en direct JavaScript (Synchronisé avec le vrai temps Serveur pour éviter les décalages de fuseau)
                                let diffSeconds = <?= max(0, time() - strtotime($activeSession['start_time'])) ?>;
                                setInterval(() => {
                                    diffSeconds++;
                                    const h = String(Math.floor(diffSeconds / 3600)).padStart(2, '0');
                                    const m = String(Math.floor((diffSeconds % 3600) / 60)).padStart(2, '0');
                                    const s = String(diffSeconds % 60).padStart(2, '0');
                                    document.getElementById('liveTimer').textContent = `${h}:${m}:${s}`;
                                }, 1000);
                            </script>
                        <?php else: ?>
                            <!-- Option 1 : Lancer un chrono en direct -->
                            <form method="POST" class="card glass" style="margin-bottom: 1.5rem; border-color: rgba(255,179,0,0.4);">
                                <input type="hidden" name="action" value="start_production">
                                <?= csrfField() ?>
                                <h3 style="margin-bottom: 1rem; display: flex; align-items: center; gap: 0.75rem;">
                                    <span style="font-size: 1.4rem;">▶️</span> Démarrer un Chrono Live
                                </h3>
                                <div class="form-group" style="margin-bottom: 1rem;">
                                    <input type="text" name="numero_of" class="input" placeholder="Numéro de l'OF (ex: OF-2024-123)"
                                        autocapitalize="characters" list="of-list" required maxlength="50" autocomplete="off">
                                </div>
                                <button type="submit" class="btn" style="width: 100%; background: var(--primary); color: #000; border: none; font-weight: bold;">
                                    Démarrer la Production
                                </button>
                            </form>

                            <!-- Option 2 : Saisie Manuelle Classique -->
                            <form method="POST" class="card glass">
                                <input type="hidden" name="action" value="saisir">
                                <?= csrfField() ?>
                                <h3 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem;">
                                    <span style="font-size: 1.2rem; opacity: 0.7;">&#9203;</span> Saisie Manuelle (A posteriori)
                                </h3>

                                <div class="form-group">
                                    <label class="label">Date</label>
                                    <input type="date" name="date_pointage" class="input"
                                        value="<?= $today ?>" min="<?= $week['monday'] ?>" max="<?= $week['sunday'] ?>" required>
                                </div>

                                <div class="form-group">
                                    <label class="label">Ordre de Fabrication</label>
                                    <input type="text" name="numero_of" class="input" id="ofInput"
                                        placeholder="Numéro OF..." autocapitalize="characters"
                                        list="of-list" required maxlength="50"
                                        inputmode="text" autocomplete="off">
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
                                        placeholder="0.0" step="0.25" min="0.25" max="24" required
                                        inputmode="decimal">
                                    <div class="quick-hour">
                                        <button type="button" onclick="setHeures(0.5)">0.5h</button>
                                        <button type="button" onclick="setHeures(1)">1h</button>
                                        <button type="button" onclick="setHeures(2)">2h</button>
                                        <button type="button" onclick="setHeures(4)">4h</button>
                                        <button type="button" onclick="setHeures(7)">7h</button>
                                        <button type="button" onclick="setHeures(8)">8h</button>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-ghost" style="width: 100%; height: 3.5rem; font-size: 0.95rem;">
                                    Enregistrer le Pointage Manuel
                                </button>
                            </form>
                        <?php endif; ?>
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
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="pointage_id" value="<?= intval($p['id']) ?>">
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
                Raoul Lenoir SAS · <a href="privacy.php" style="color: inherit; text-decoration: underline;">RGPD &amp; Confidentialité</a>
            </div>
        </main>
    </div>

    <script>
        function switchTab(name) {
            document.getElementById('tab-saisie').style.display = name === 'saisie' ? 'block' : 'none';
            document.getElementById('tab-semaine').style.display = name === 'semaine' ? 'block' : 'none';
            document.getElementById('nav-saisie').className = name === 'saisie' ? 'btn btn-primary sidebar-link' : 'btn btn-ghost sidebar-link';
            document.getElementById('nav-semaine').className = name === 'semaine' ? 'btn btn-primary sidebar-link' : 'btn btn-ghost sidebar-link';
            // Fermer la sidebar automatiquement sur mobile
            closeSidebar();
            // Scroll en haut
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function setActiveNav(el) {
            document.querySelectorAll('.mobile-nav-item').forEach(i => i.classList.remove('active'));
            el.classList.add('active');
        }

        function setHeures(val) {
            document.getElementById('heuresInput').value = val;
            // Feedback haptique sur mobile (si supporté)
            if (navigator.vibrate) navigator.vibrate(30);
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('open');
        }

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('open');
            document.body.classList.toggle('sidebar-is-open');
        }

        // Fermer sidebar dès qu'on clique sur un lien ou bouton de nav
        document.querySelectorAll('.sidebar-link').forEach(el => {
            el.addEventListener('click', closeSidebar);
        });

        // Majuscules OF
        const ofInput = document.getElementById('ofInput');
        if (ofInput) ofInput.addEventListener('input', function() { this.value = this.value.toUpperCase(); });
    </script>
    <!-- ═══ BOTTOM NAVIGATION MOBILE ═══ -->
    <nav class="mobile-bottom-nav">
        <div class="mobile-bottom-nav-inner">
            <button class="mobile-nav-item active" onclick="switchTab('saisie'); setActiveNav(this)" id="nav-mob-saisie">
                <span class="mobile-nav-icon">&#128221;</span>
                <span class="mobile-nav-label">Saisie</span>
            </button>
            <button class="mobile-nav-item" onclick="switchTab('semaine'); setActiveNav(this)" id="nav-mob-semaine">
                <span class="mobile-nav-icon">&#128197;</span>
                <span class="mobile-nav-label">Semaine</span>
            </button>
            <a href="logout.php" class="mobile-nav-item" style="color: var(--error);">
                <span class="mobile-nav-icon">&#x23FB;</span>
                <span class="mobile-nav-label">Quitter</span>
            </a>
        </div>
    </nav>
</body>
</html>
</body>
</html>
