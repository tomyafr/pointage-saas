<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth('chef');

$db = getDB();
try {
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS statut VARCHAR(20) DEFAULT 'actif'");
} catch (Exception $e) {
}

$week = getCurrentWeekDates();

$dateDebutStr = $week['monday'];
$dateFinStr = $week['sunday'];
$dateDebut = date('Y-m-d', strtotime($dateDebutStr));
$dateFin = date('Y-m-d', strtotime($dateFinStr));

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

// Récupérer les données de l'équipe (opérateurs et chefs) mais on peut se concentrer sur tout le monde
$stmtUsers = $db->prepare('
    SELECT 
        u.id, 
        u.nom, 
        u.prenom, 
        u.role,
        u.statut,
        u.created_at,
        (SELECT date_pointage FROM pointages p WHERE p.user_id = u.id ORDER BY p.date_pointage DESC LIMIT 1) as dernier_pointage,
        (SELECT SUM(heures) FROM pointages p WHERE p.user_id = u.id AND p.date_pointage BETWEEN ? AND ?) as heures_semaine,
        (SELECT SUM(heures) FROM pointages p WHERE p.user_id = u.id AND p.date_pointage BETWEEN ? AND ?) as heures_mois,
        (SELECT start_time FROM active_sessions act WHERE act.user_id = u.id LIMIT 1) as session_start,
        (SELECT numero_of FROM active_sessions act WHERE act.user_id = u.id LIMIT 1) as session_of
    FROM users u
    WHERE u.actif = TRUE
    ORDER BY u.role DESC, u.nom ASC
');
$stmtUsers->execute([$dateDebut, $dateFin, $monthStart, $monthEnd]);
$equipe = $stmtUsers->fetchAll();

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Mon Équipe | Raoul Lenoir</title>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/icon-192.png">
    <meta name="theme-color" content="#020617">
    <style>
        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .team-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            transition: var(--transition-smooth);
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
        }

        .team-card:hover {
            transform: translateY(-5px);
            border-color: rgba(255, 179, 0, 0.4);
            box-shadow: 0 10px 40px -10px rgba(255, 179, 0, 0.15);
        }

        .team-card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .team-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(255, 179, 0, 0.2), rgba(255, 179, 0, 0.05));
            border: 1px solid var(--primary);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            font-weight: 800;
        }

        .team-info h3 {
            font-size: 1.15rem;
            color: var(--text-main);
            margin-bottom: 0.2rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .team-info p {
            font-size: 0.75rem;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .team-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
            background: rgba(0, 0, 0, 0.2);
            padding: 1rem;
            border-radius: var(--radius-md);
            border: 1px solid rgba(255, 255, 255, 0.03);
        }

        .team-stat {
            display: flex;
            flex-direction: column;
        }

        .team-stat-label {
            font-size: 0.65rem;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.2rem;
        }

        .team-stat-val {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--text-main);
            font-family: var(--font-mono);
        }

        .team-active-indicator {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--success);
            box-shadow: 0 0 10px var(--success);
            animation: pulse-green 2s infinite;
        }

        .team-active-bg {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at top right, rgba(16, 185, 129, 0.05), transparent 60%);
            pointer-events: none;
        }

        @keyframes pulse-green {
            0% {
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4);
            }

            70% {
                box-shadow: 0 0 0 6px rgba(16, 185, 129, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
            }
        }

        .role-badge {
            display: inline-block;
            font-size: 0.6rem;
            padding: 0.2rem 0.5rem;
            border-radius: var(--radius-sm);
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-main);
            margin-top: 0.25rem;
        }
    </style>
    <script>
        if (localStorage.getItem('theme') === 'light') {
            document.documentElement.classList.add('light-mode');
        }
    </script>
</head>

<body>
    <!-- ═══ HEADER MOBILE ═══ -->
    <header class="mobile-header">
        <button class="mobile-logo-btn" onclick="toggleSidebar()" aria-label="Menu">
            <img src="/assets/logo-raoul-lenoir.svg" alt="Raoul Lenoir" class="mobile-header-logo"
                style="filter:brightness(0) saturate(100%) invert(73%) sepia(86%) saturate(1063%) hue-rotate(358deg) brightness(101%) contrast(106%);">
        </button>
        <span class="mobile-header-title">Mon Équipe</span>
        <span class="mobile-header-user">
            <?php if (!empty($_SESSION['avatar'])): ?>
                <img src="<?= htmlspecialchars($_SESSION['avatar']) ?>"
                    style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover; border: 1px solid var(--glass-border);">
            <?php else: ?>
                <?= htmlspecialchars($_SESSION['user_prenom']) ?>
            <?php endif; ?>
        </span>
    </header>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <div class="dashboard-layout">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <button class="sidebar-close-btn" onclick="toggleSidebar()" aria-label="Fermer">&times;</button>
            <div style="margin-bottom: 2.5rem;">
                <a href="chef.php" class="brand-icon"
                    style="display: block; width: 180px; height: auto; margin: 0 0 1rem 0;"><img
                        src="/assets/logo-raoul-lenoir.svg" alt="Raoul Lenoir"></a>
                <h2 style="font-size: 1.15rem;"><span class="text-gradient">Raoul Lenoir</span></h2>
                <p
                    style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; margin-top: 0.25rem;">
                    Chef d'Atelier
                </p>
            </div>

            <nav style="display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 2rem;">
                <a href="chef.php" class="btn btn-ghost sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <span>&#128202;</span> Tableau de bord
                </a>
                <a href="historique.php" class="btn btn-ghost sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <span>&#128337;</span> Historique
                </a>
                <a href="equipe.php" class="btn btn-primary sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <span>&#128101;</span> Mon Équipe
                </a>
                <a href="export-excel.php?week=current&amp;of=" class="btn btn-ghost sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;" target="_blank">
                    <span>&#128229;</span> Export Excel
                </a>
            </nav>

            <div style="margin-top: auto; padding-top: 1.5rem; border-top: 1px solid var(--glass-border);">
                <p
                    style="font-size: 0.65rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.75rem;">
                    Connecté</p>
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <?php if (!empty($_SESSION['avatar'])): ?>
                        <img src="<?= htmlspecialchars($_SESSION['avatar']) ?>"
                            style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 1px solid var(--glass-border);">
                    <?php else: ?>
                        <div
                            style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary); color: #000; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.8rem;">
                            <?= strtoupper(substr($_SESSION['user_prenom'], 0, 1) . substr($_SESSION['user_nom'], 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <p
                        style="font-weight: 600; font-size: 0.85rem; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px;">
                        <?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?>
                    </p>
                </div>
                <a href="logout.php" class="btn btn-ghost"
                    style="width: 100%; margin-top: 1rem; color: var(--error); border-color: rgba(244, 63, 94, 0.15); font-size: 0.75rem; padding: 0.6rem;">
                    Se déconnecter
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div style="display:flex;align-items:center;gap:1rem;margin-bottom:2rem;" class="animate-in">
                <h1
                    style="font-size:1.6rem;background:linear-gradient(135deg,var(--primary),var(--primary-light));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;">
                    👥 Mon Équipe
                </h1>
            </div>

            <div class="team-grid animate-in-delay-1">
                <?php foreach ($equipe as $membre):
                    $isActive = !empty($membre['session_start']);
                    $heuresSem = (float) ($membre['heures_semaine'] ?? 0);
                    $heuresMois = (float) ($membre['heures_mois'] ?? 0);
                    ?>
                    <div class="team-card">
                        <?php if ($isActive): ?>
                            <div class="team-active-bg"></div>
                            <div class="team-active-indicator"
                                title="En train de pointer sur <?= htmlspecialchars($membre['session_of']) ?>"></div>
                        <?php endif; ?>

                        <div class="team-card-header">
                            <div class="team-avatar">
                                <?= strtoupper(substr($membre['prenom'], 0, 1) . substr($membre['nom'], 0, 1)) ?>
                            </div>
                            <div class="team-info">
                                <h3>
                                    <?= htmlspecialchars($membre['prenom'] . ' ' . $membre['nom']) ?>
                                </h3>
                                <?php if ($membre['role'] === 'chef'): ?>
                                    <span class="role-badge"
                                        style="background: rgba(255, 179, 0, 0.2); color: var(--primary);">Chef d'Atelier</span>
                                <?php else: ?>
                                    <span class="role-badge" style="margin-right:0.25rem;">Opérateur</span>
                                <?php endif; ?>
                                <?php if ($membre['statut'] === 'pause' && !$isActive): ?>
                                    <span class="role-badge" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b;">En
                                        pause</span>
                                <?php elseif ($membre['statut'] === 'absent' && !$isActive): ?>
                                    <span class="role-badge"
                                        style="background: rgba(244, 63, 94, 0.2); color: #f43f5e;">Absent</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="team-stats">
                            <div class="team-stat">
                                <span class="team-stat-label">Cette Semaine</span>
                                <span class="team-stat-val">
                                    <?= number_format($heuresSem, 1) ?>h
                                </span>
                            </div>
                            <div class="team-stat">
                                <span class="team-stat-label">Ce Mois</span>
                                <span class="team-stat-val">
                                    <?= number_format($heuresMois, 1) ?>h
                                </span>
                            </div>
                        </div>

                        <?php if ($isActive): ?>
                            <div
                                style="margin-bottom: 1rem; font-size: 0.8rem; border-left: 2px solid var(--success); padding-left: 0.75rem; color: var(--success);">
                                <strong>En ligne :</strong> OF
                                <?= htmlspecialchars($membre['session_of']) ?>
                            </div>
                        <?php elseif ($membre['dernier_pointage']): ?>
                            <div
                                style="margin-bottom: 1rem; font-size: 0.75rem; color: var(--text-dim); display: flex; align-items: center; gap: 0.4rem;">
                                🕒 Dernier pointage le
                                <?= date('d/m/Y', strtotime($membre['dernier_pointage'])) ?>
                            </div>
                        <?php else: ?>
                            <div style="margin-bottom: 1rem; font-size: 0.75rem; color: var(--text-dim); opacity: 0.5;">
                                Aucun pointage récent
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: auto;">
                            <a href="historique.php?user=<?= $membre['id'] ?>&period=month" class="btn btn-ghost"
                                style="width: 100%; font-size: 0.8rem; justify-content: center; padding: 0.6rem;">
                                Voir l'historique →
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="app-footer">
                Raoul Lenoir SAS · <a href="privacy.php" style="color: inherit; text-decoration: underline;">RGPD &
                    Confidentialité</a>
            </div>
        </main>
    </div>

    <!-- ═══ BOTTOM NAVIGATION MOBILE ═══ -->
    <nav class="mobile-bottom-nav">
        <div class="mobile-bottom-nav-inner">
            <a href="chef.php" class="mobile-nav-item">
                <span class="mobile-nav-icon">&#128202;</span>
                <span class="mobile-nav-label">Tableau</span>
            </a>
            <a href="historique.php" class="mobile-nav-item">
                <span class="mobile-nav-icon">&#128337;</span>
                <span class="mobile-nav-label">Historique</span>
            </a>
            <a href="equipe.php" class="mobile-nav-item active">
                <span class="mobile-nav-icon">&#128101;</span>
                <span class="mobile-nav-label">Équipe</span>
            </a>
            <a href="export-excel.php?week=current&of=" class="mobile-nav-item">
                <span class="mobile-nav-icon">&#128196;</span>
                <span class="mobile-nav-label">Export</span>
            </a>
        </div>
    </nav>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('open');
            document.body.classList.toggle('sidebar-is-open');
        }
    </script>
</body>

</html>