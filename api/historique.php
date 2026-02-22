<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth('chef');

$db = getDB();
$week = getCurrentWeekDates();

// ── Filtres GET validés ────────────────────────────────────────────────────
$filterPeriod = $_GET['period'] ?? 'current';
$allowedPeriods = ['current', 'last', 'month', 'all'];
if (!in_array($filterPeriod, $allowedPeriods, true))
    $filterPeriod = 'current';

$filterUser = intval($_GET['user'] ?? 0);
$filterOf = substr(preg_replace('/[^\w\s\-\/]/', '', trim($_GET['of'] ?? '')), 0, 50);

// Calcul des dates selon la période
switch ($filterPeriod) {
    case 'last':
        $dateDebut = date('Y-m-d', strtotime($week['monday'] . ' -7 days'));
        $dateFin = date('Y-m-d', strtotime($week['sunday'] . ' -7 days'));
        $labelPeriod = 'Semaine précédente';
        break;
    case 'month':
        $dateDebut = date('Y-m-01');
        $dateFin = date('Y-m-d');
        // Utiliser date() au lieu de strftime() (supprimé en PHP 8.2)
        $moisNoms = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        $labelPeriod = 'Ce mois (' . $moisNoms[(int) date('n')] . ' ' . date('Y') . ')';
        break;
    case 'all':
        $dateDebut = '2020-01-01';
        $dateFin = date('Y-m-d');
        $labelPeriod = 'Tout l\'historique';
        break;
    default: // current
        $dateDebut = $week['monday'];
        $dateFin = $week['sunday'];
        $labelPeriod = 'Semaine en cours';
}

// ── Récupérer tous les utilisateurs actifs ────────────────────────────────
$stmtUsers = $db->prepare('SELECT id, nom, prenom FROM users WHERE actif = TRUE ORDER BY nom');
$stmtUsers->execute();
$allUsers = $stmtUsers->fetchAll();

// ── Requête principale : tous les pointages de la période ─────────────────
$query = '
    SELECT p.id, p.numero_of, p.heures, p.date_pointage, p.synced_bc, p.created_at,
           u.id as user_id, u.nom, u.prenom, u.role
    FROM pointages p
    JOIN users u ON p.user_id = u.id
    WHERE p.date_pointage BETWEEN ? AND ?
';
$params = [$dateDebut, $dateFin];

if ($filterUser > 0) {
    $query .= ' AND u.id = ?';
    $params[] = $filterUser;
}
if (!empty($filterOf)) {
    $query .= ' AND p.numero_of ILIKE ?';
    $params[] = '%' . $filterOf . '%';
}

$query .= ' ORDER BY p.date_pointage DESC, u.nom, p.numero_of';

$stmt = $db->prepare($query);
$stmt->execute($params);
$pointages = $stmt->fetchAll();

// ── Stats récapitulatives par opérateur ───────────────────────────────────
$statsParOperateur = [];
foreach ($pointages as $p) {
    $uid = $p['user_id'];
    if (!isset($statsParOperateur[$uid])) {
        $statsParOperateur[$uid] = [
            'nom' => $p['nom'],
            'prenom' => $p['prenom'],
            'total' => 0,
            'nb_of' => [],
            'nb_jours' => [],
            'synced' => 0,
        ];
    }
    $statsParOperateur[$uid]['total'] += $p['heures'];
    $statsParOperateur[$uid]['nb_of'][$p['numero_of']] = true;
    $statsParOperateur[$uid]['nb_jours'][$p['date_pointage']] = true;
    if ($p['synced_bc'])
        $statsParOperateur[$uid]['synced']++;
}
usort($statsParOperateur, fn($a, $b) => $b['total'] - $a['total']);

$grandTotal = array_sum(array_column($pointages, 'heures'));
$nbPointages = count($pointages);
$nbOperateurs = count($statsParOperateur);
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Historique Général | Raoul Lenoir</title>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#020617">
    <style>
        .hist-table {
            width: 100%;
            border-collapse: collapse;
        }

        .hist-table th {
            padding: 0.85rem 1rem;
            text-align: left;
            border-bottom: 2px solid var(--glass-border);
            color: var(--text-dim);
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            white-space: nowrap;
        }

        .hist-table td {
            padding: 0.9rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
            font-size: 0.82rem;
            vertical-align: middle;
        }

        .hist-table tr:hover td {
            background: var(--primary-subtle);
        }

        .avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--accent-cyan));
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.65rem;
            color: #000;
            flex-shrink: 0;
        }

        .filter-bar {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 1.75rem;
        }

        .filter-bar select,
        .filter-bar input {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-sm);
            color: var(--text-main);
            padding: 0.6rem 1rem;
            font-family: var(--font-main);
            font-size: 0.82rem;
            min-height: 44px;
            cursor: pointer;
        }

        .filter-bar select:focus,
        .filter-bar input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .op-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-md);
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .op-card-stat {
            text-align: center;
        }

        .op-card-stat strong {
            display: block;
            font-size: 1.2rem;
            font-weight: 900;
            color: var(--primary);
        }

        .op-card-stat span {
            font-size: 0.6rem;
            color: var(--text-dim);
            text-transform: uppercase;
        }

        .synced-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
        }

        .tag-of {
            display: inline-block;
            padding: 0.15rem 0.55rem;
            background: rgba(14, 165, 233, 0.08);
            color: var(--accent-cyan);
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
            font-family: var(--font-mono);
            letter-spacing: 0.02em;
        }

        @media (max-width: 768px) {
            .filter-bar {
                flex-direction: column;
            }

            .filter-bar select,
            .filter-bar input {
                width: 100%;
            }

            /* Colonnes masquées sur mobile */
            .col-created {
                display: none;
            }

            .op-cards-grid {
                grid-template-columns: 1fr !important;
            }
        }
    </style>
</head>

<body>
    <!-- Header mobile -->
    <header class="mobile-header">
        <button class="mobile-logo-btn" onclick="toggleSidebar()" aria-label="Menu">
            <img src="/assets/logo-raoul-lenoir.svg" alt="Raoul Lenoir" class="mobile-header-logo"
                style="filter:brightness(0) saturate(100%) invert(73%) sepia(86%) saturate(1063%) hue-rotate(358deg) brightness(101%) contrast(106%);">
        </button>
        <span class="mobile-header-title">Historique</span>
        <span class="mobile-header-user">
            <?= htmlspecialchars($_SESSION['user_prenom']) ?>
        </span>
    </header>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <div class="dashboard-layout">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <button class="sidebar-close-btn" onclick="toggleSidebar()" aria-label="Fermer">&times;</button>
            <div style="margin-bottom: 2.5rem;">
                <a href="chef.php" class="brand-icon" style="display:block;width:180px;height:auto;margin:0 0 1rem 0;">
                    <img src="/assets/logo-raoul-lenoir.svg" alt="Raoul Lenoir">
                </a>
                <h2 style="font-size:1.15rem;"><span class="text-gradient">Raoul Lenoir</span></h2>
                <p
                    style="font-size:0.7rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;margin-top:0.25rem;">
                    Chef d'Atelier</p>
            </div>

            <nav style="display:flex;flex-direction:column;gap:0.4rem;margin-bottom:2rem;">
                <a href="chef.php" class="btn btn-ghost sidebar-link"
                    style="justify-content:flex-start;padding:0.7rem 1.1rem;font-size:0.8rem;">
                    <span>&#128202;</span> Tableau de bord
                </a>
                <a href="historique.php" class="btn btn-primary sidebar-link"
                    style="justify-content:flex-start;padding:0.7rem 1.1rem;font-size:0.8rem;">
                    <span>&#128337;</span> Historique G&eacute;n&eacute;ral
                </a>
                <a href="export-excel.php?week=<?= $filterPeriod === 'last' ? 'last' : 'current' ?>"
                    class="btn btn-ghost sidebar-link"
                    style="justify-content:flex-start;padding:0.7rem 1.1rem;font-size:0.8rem;" target="_blank">
                    <span>&#128229;</span> Export Excel
                </a>
            </nav>

            <div style="margin-top:auto;padding-top:1.5rem;border-top:1px solid var(--glass-border);">
                <p style="font-size:0.65rem;color:var(--text-dim);text-transform:uppercase;margin-bottom:0.4rem;">
                    Connecté</p>
                <p style="font-weight:600;font-size:0.85rem;">
                    <?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?>
                </p>
                <a href="logout.php" class="btn btn-ghost sidebar-link"
                    style="width:100%;margin-top:1rem;color:var(--error);border-color:rgba(244,63,94,0.15);font-size:0.75rem;padding:0.6rem;">
                    Se d&eacute;connecter
                </a>
            </div>
        </aside>

        <main class="main-content">
            <!-- Titre -->
            <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;" class="animate-in">
                <a href="chef.php" style="color:var(--text-dim);text-decoration:none;font-size:0.85rem;">&#8592; Tableau
                    de bord</a>
                <span style="color:var(--glass-border);">|</span>
                <h1
                    style="font-size:1.4rem;background:linear-gradient(135deg,var(--primary),var(--primary-light));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;">
                    &#128337; Historique G&eacute;n&eacute;ral
                </h1>
            </div>

            <!-- Stats rapides -->
            <div class="stats-grid animate-in" style="margin-bottom:1.5rem;">
                <div class="stat-item glass">
                    <span class="stat-label">Total Heures</span>
                    <span class="stat-value">
                        <?= number_format($grandTotal, 1) ?><small style="font-size:1rem;">h</small>
                    </span>
                    <span style="font-size:0.65rem;color:var(--text-dim);margin-top:0.4rem;">
                        <?= $labelPeriod ?>
                    </span>
                </div>
                <div class="stat-item glass">
                    <span class="stat-label">Pointages</span>
                    <span class="stat-value">
                        <?= $nbPointages ?>
                    </span>
                    <span style="font-size:0.65rem;color:var(--text-dim);margin-top:0.4rem;">lignes</span>
                </div>
                <div class="stat-item glass">
                    <span class="stat-label">Op&eacute;rateurs</span>
                    <span class="stat-value">
                        <?= $nbOperateurs ?>
                    </span>
                    <span style="font-size:0.65rem;color:var(--text-dim);margin-top:0.4rem;">actifs sur la
                        p&eacute;riode</span>
                </div>
                <div class="stat-item glass">
                    <span class="stat-label">Moy/Opérateur</span>
                    <span class="stat-value">
                        <?= $nbOperateurs > 0 ? number_format($grandTotal / $nbOperateurs, 1) : '—' ?><small
                            style="font-size:1rem;">h</small>
                    </span>
                    <span style="font-size:0.65rem;color:var(--text-dim);margin-top:0.4rem;">sur la
                        p&eacute;riode</span>
                </div>
            </div>

            <!-- Récap par opérateur -->
            <?php if (!empty($statsParOperateur)): ?>
                <div class="card glass animate-in-delay-1" style="margin-bottom:1.75rem;padding:1.5rem;">
                    <h3 style="font-size:1rem;margin-bottom:1.25rem;color:var(--primary);">&#128101; R&eacute;cap par
                        Op&eacute;rateur</h3>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:0.75rem;"
                        class="op-cards-grid">
                        <?php foreach ($statsParOperateur as $uid => $op): ?>
                            <div class="op-card">
                                <div class="avatar">
                                    <?= strtoupper(substr($op['prenom'], 0, 1) . substr($op['nom'], 0, 1)) ?>
                                </div>
                                <div style="flex:1;min-width:0;">
                                    <div
                                        style="font-weight:700;font-size:0.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?= htmlspecialchars($op['prenom'] . ' ' . $op['nom']) ?>
                                    </div>
                                    <div style="font-size:0.65rem;color:var(--text-dim);">
                                        <?= count($op['nb_of']) ?> OF ·
                                        <?= count($op['nb_jours']) ?> jour
                                        <?= count($op['nb_jours']) > 1 ? 's' : '' ?>
                                    </div>
                                </div>
                                <div class="op-card-stat">
                                    <strong>
                                        <?= number_format($op['total'], 1) ?>h
                                    </strong>
                                    <span>total</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Filtres -->
            <div class="card glass animate-in-delay-1" style="padding:1.5rem;margin-bottom:1.5rem;">
                <form method="GET" class="filter-bar" id="filterForm">
                    <select name="period" onchange="this.form.submit()">
                        <option value="current" <?= $filterPeriod === 'current' ? 'selected' : '' ?>>Semaine en cours
                        </option>
                        <option value="last" <?= $filterPeriod === 'last' ? 'selected' : '' ?>>Semaine
                            pr&eacute;c&eacute;dente</option>
                        <option value="month" <?= $filterPeriod === 'month' ? 'selected' : '' ?>>Ce mois</option>
                        <option value="all" <?= $filterPeriod === 'all' ? 'selected' : '' ?>>Tout l'historique</option>
                    </select>
                    <select name="user" onchange="this.form.submit()">
                        <option value="0">Tous les op&eacute;rateurs</option>
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['prenom'] . ' ' . $u['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="of" placeholder="Filtrer par OF..."
                        value="<?= htmlspecialchars($filterOf) ?>" maxlength="50" oninput="clearDebounce()"
                        style="flex:1;min-width:150px;">
                    <button type="submit" class="btn btn-primary"
                        style="padding:0.6rem 1.25rem;font-size:0.8rem;">Filtrer</button>
                    <?php if ($filterUser || $filterOf): ?>
                        <a href="historique.php?period=<?= $filterPeriod ?>" class="btn btn-ghost"
                            style="padding:0.6rem 1rem;font-size:0.8rem;">Effacer</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Tableau principal -->
            <div class="card glass animate-in-delay-2" style="padding:0;overflow:hidden;">
                <div
                    style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--glass-border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;">
                    <h3 style="font-size:1rem;">D&eacute;tail des pointages
                        <span style="font-size:0.75rem;color:var(--text-dim);font-weight:400;margin-left:0.5rem;">
                            <?= $nbPointages ?> lignes
                        </span>
                    </h3>
                    <a href="export-excel.php?week=<?= $filterPeriod === 'last' ? 'last' : 'current' ?>&of=<?= urlencode($filterOf) ?>"
                        class="btn btn-ghost" style="padding:0.5rem 1rem;font-size:0.75rem;" target="_blank">
                        &#128229; Export Excel
                    </a>
                </div>

                <?php if (empty($pointages)): ?>
                    <div style="padding:3rem;text-align:center;color:var(--text-dim);">
                        <div style="font-size:2.5rem;margin-bottom:1rem;">&#128203;</div>
                        <p>Aucun pointage pour cette p&eacute;riode.</p>
                    </div>
                <?php else: ?>
                    <div class="table-scroll-wrapper" style="overflow-x:auto;">
                        <table class="hist-table">
                            <thead>
                                <tr>
                                    <th>Op&eacute;rateur</th>
                                    <th>Date</th>
                                    <th>OF</th>
                                    <th style="text-align:right;">Heures</th>
                                    <th style="text-align:center;">Sync BC</th>
                                    <th class="col-created">Enregistr&eacute;</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pointages as $p): ?>
                                    <tr>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:0.6rem;">
                                                <div class="avatar">
                                                    <?= strtoupper(substr($p['prenom'], 0, 1) . substr($p['nom'], 0, 1)) ?>
                                                </div>
                                                <span style="font-weight:600;">
                                                    <?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td style="font-family:var(--font-mono);font-size:0.78rem;color:var(--text-muted);">
                                            <?= date('d/m/Y', strtotime($p['date_pointage'])) ?>
                                        </td>
                                        <td><span class="tag-of">
                                                <?= htmlspecialchars($p['numero_of']) ?>
                                            </span></td>
                                        <td
                                            style="text-align:right;font-weight:800;color:var(--primary);font-family:var(--font-mono);">
                                            <?= number_format($p['heures'], 2) ?>h
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($p['synced_bc']): ?>
                                                <span style="color:var(--success);" title="Synchronis&eacute;">&#10003;</span>
                                            <?php else: ?>
                                                <span style="color:var(--text-dim);" title="En attente">&#9679;</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-created"
                                            style="font-size:0.72rem;color:var(--text-dim);font-family:var(--font-mono);">
                                            <?= date('d/m H\hi', strtotime($p['created_at'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:rgba(255,179,0,0.05);">
                                    <td colspan="3" style="padding:1rem;font-weight:700;font-size:0.9rem;">TOTAL</td>
                                    <td
                                        style="padding:1rem;text-align:right;font-weight:900;font-size:1.1rem;color:var(--primary);font-family:var(--font-mono);">
                                        <?= number_format($grandTotal, 2) ?>h
                                    </td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="app-footer">
                Raoul Lenoir SAS &middot; <a href="privacy.php" style="color:inherit;text-decoration:underline;">RGPD
                    &amp; Confidentialit&eacute;</a>
            </div>
        </main>
    </div>

    <!-- Bottom nav mobile -->
    <nav class="mobile-bottom-nav">
        <div class="mobile-bottom-nav-inner">
            <a href="chef.php" class="mobile-nav-item"><span class="mobile-nav-icon">&#128202;</span><span
                    class="mobile-nav-label">Tableau</span></a>
            <a href="historique.php" class="mobile-nav-item active"><span class="mobile-nav-icon">&#128337;</span><span
                    class="mobile-nav-label">Historique</span></a>
            <a href="export-excel.php?week=<?= $filterPeriod === 'last' ? 'last' : 'current' ?>&of=<?= urlencode($filterOf) ?>"
                class="mobile-nav-item" target="_blank"><span class="mobile-nav-icon">&#128196;</span><span
                    class="mobile-nav-label">Export</span></a>
            <a href="logout.php" class="mobile-nav-item" style="color:var(--error);"><span
                    class="mobile-nav-icon">&#x23FB;</span><span class="mobile-nav-label">Quitter</span></a>
        </div>
    </nav>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('open');
            document.body.classList.toggle('sidebar-is-open');
        }

        // Fermer sidebar automatiquement au clic sur un lien
        document.querySelectorAll('.sidebar-link').forEach(link => {
            link.addEventListener('click', () => {
                document.getElementById('sidebar').classList.remove('open');
                document.getElementById('sidebarOverlay').classList.remove('open');
                document.body.classList.remove('sidebar-is-open');
            });
        });

        // Soumission auto filtre OF avec debounce
        let debounceTimer;
        function clearDebounce() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => document.getElementById('filterForm').submit(), 700);
        }
    </script>
</body>

</html>