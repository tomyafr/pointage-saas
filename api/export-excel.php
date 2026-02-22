<?php
/**
 * Export Excel des pointages
 * 
 * Génère un fichier .xlsx compatible Excel/LibreOffice
 * Utilise le format XML Spreadsheet (pas besoin de librairie externe)
 * 
 * Usage: export-excel.php?week=current|last&of=FILTRE
 */

require_once __DIR__ . '/../includes/config.php';
requireAuth('chef');

$db = getDB();
$week = getCurrentWeekDates();

// Filtres — validation stricte des paramètres GET
$filterWeek = $_GET['week'] ?? 'current';
// Whitelist : seules valeurs autorisées
if (!in_array($filterWeek, ['current', 'last'], true)) {
    $filterWeek = 'current';
}

// Filtre OF : nettoyage et limitation de longueur (anti-injection)
$filterOf = trim($_GET['of'] ?? '');
$filterOf = preg_replace('/[^\w\s\-\/]/', '', $filterOf); // Garde seulement alphanum + tirets + /
$filterOf = substr($filterOf, 0, 50); // Limite à 50 caractères

if ($filterWeek === 'last') {
    $dateDebut = date('Y-m-d', strtotime($week['monday'] . ' -7 days'));
    $dateFin = date('Y-m-d', strtotime($week['sunday'] . ' -7 days'));
} else {
    $dateDebut = $week['monday'];
    $dateFin = $week['sunday'];
}

// Récupérer les pointages
$query = '
    SELECT 
        p.numero_of,
        u.nom as operateur_nom,
        u.prenom as operateur_prenom,
        p.date_pointage,
        p.heures,
        CASE WHEN p.synced_bc IS TRUE THEN \'Synchronisé\' ELSE \'En attente\' END as statut,
        p.created_at
    FROM pointages p
    JOIN users u ON p.user_id = u.id
    WHERE p.date_pointage BETWEEN ? AND ?
';
$params = [$dateDebut, $dateFin];

if (!empty($filterOf)) {
    $query .= ' AND p.numero_of LIKE ?';
    $params[] = '%' . $filterOf . '%';
}

$query .= ' ORDER BY p.numero_of ASC, p.date_pointage ASC, u.nom ASC';

$stmt = $db->prepare($query);
$stmt->execute($params);
$pointages = $stmt->fetchAll();

// Résumé par OF
$queryResume = '
    SELECT 
        p.numero_of,
        SUM(p.heures) as total_heures,
        COUNT(DISTINCT p.user_id) as nb_operateurs,
        COUNT(p.id) as nb_pointages,
        SUM(CASE WHEN p.synced_bc IS TRUE THEN p.heures ELSE 0 END) as heures_sync,
        SUM(CASE WHEN p.synced_bc IS FALSE THEN p.heures ELSE 0 END) as heures_pending
    FROM pointages p
    WHERE p.date_pointage BETWEEN ? AND ?
';
$paramsResume = [$dateDebut, $dateFin];

if (!empty($filterOf)) {
    $queryResume .= ' AND p.numero_of LIKE ?';
    $paramsResume[] = '%' . $filterOf . '%';
}

$queryResume .= ' GROUP BY p.numero_of ORDER BY p.numero_of ASC';

$stmtResume = $db->prepare($queryResume);
$stmtResume->execute($paramsResume);
$resume = $stmtResume->fetchAll();

$totalGlobal = array_sum(array_column($resume, 'total_heures'));

// ──────────────────────────────────
// Génération du fichier Excel (XML Spreadsheet 2003)
// Compatible Excel, LibreOffice, Google Sheets
// ──────────────────────────────────

$filename = 'pointage_semaine_' . $dateDebut . '.xls';

// ── Mode page de téléchargement (sans ?serve=1) ──────────────────────────────
// Sur mobile iOS, ouvrir directement le fichier sur cette page bloque la navigation.
// On affiche donc d'abord une page avec bouton "Télécharger" + bouton "Retour".
if (!isset($_GET['serve'])) {
    $nbLignes = count($pointages);
    $totalHeures = $totalGlobal ?? 0;
    $serveUrl = '?serve=1&week=' . urlencode($filterWeek) . '&of=' . urlencode($filterOf);
    ?>
    <!DOCTYPE html>
    <html lang="fr">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
        <title>Export Excel | Raoul Lenoir</title>
        <link rel="stylesheet" href="/assets/style.css">
        <meta name="theme-color" content="#020617">
        <style>
            body {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100dvh;
                padding: 1.5rem;
            }

            .export-card {
                background: var(--glass-bg);
                border: 1px solid var(--glass-border);
                backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
                border-radius: var(--radius-xl);
                padding: 2.5rem 2rem;
                max-width: 420px;
                width: 100%;
                text-align: center;
                box-shadow: var(--shadow-lg);
            }

            .export-icon {
                font-size: 3.5rem;
                margin-bottom: 1.25rem;
                display: block;
            }

            .export-title {
                font-size: 1.4rem;
                font-weight: 800;
                margin-bottom: 0.4rem;
            }

            .export-meta {
                font-size: 0.82rem;
                color: var(--text-dim);
                margin-bottom: 2rem;
            }

            .export-stats {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 0.75rem;
                margin-bottom: 2rem;
            }

            .export-stat {
                background: rgba(255, 255, 255, 0.03);
                border: 1px solid var(--glass-border);
                border-radius: var(--radius-md);
                padding: 0.85rem;
            }

            .export-stat strong {
                display: block;
                font-size: 1.5rem;
                font-weight: 900;
                color: var(--primary);
            }

            .export-stat span {
                font-size: 0.65rem;
                color: var(--text-dim);
                text-transform: uppercase;
                letter-spacing: .05em;
            }

            .btn-group {
                display: flex;
                flex-direction: column;
                gap: 0.75rem;
            }
        </style>
    </head>

    <body>
        <div class="export-card">
            <span class="export-icon">&#128229;</span>
            <div class="export-title">Export Excel</div>
            <div class="export-meta">
                Semaine du <?= date('d/m', strtotime($dateDebut)) ?> au <?= date('d/m/Y', strtotime($dateFin)) ?>
                <?php if ($filterOf): ?>&nbsp;&middot;&nbsp;OF : <?= htmlspecialchars($filterOf) ?><?php endif; ?>
            </div>

            <div class="export-stats">
                <div class="export-stat">
                    <strong><?= $nbLignes ?></strong>
                    <span>Pointages</span>
                </div>
                <div class="export-stat">
                    <strong><?= number_format((float) $totalHeures, 1) ?>h</strong>
                    <span>Total heures</span>
                </div>
            </div>

            <div class="btn-group">
                <a href="<?= htmlspecialchars($serveUrl) ?>" class="btn btn-primary"
                    download="<?= htmlspecialchars($filename) ?>">
                    &#128229;&nbsp; Télécharger le fichier
                </a>
                <a href="chef.php" class="btn btn-ghost">
                    &#8592;&nbsp; Retour au tableau de bord
                </a>
            </div>
        </div>
    </body>

    </html>
    <?php
    exit;
}

// ── Servir le fichier Excel ────────────────────────────────────────────────
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office"
    xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">

    <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
        <Title>Pointage Atelier - Semaine du <?= $dateDebut ?></Title>
        <Author>Pointage Atelier SaaS</Author>
        <Created><?= date('c') ?></Created>
    </DocumentProperties>

    <Styles>
        <Style ss:ID="header">
            <Font ss:Bold="1" ss:Size="11" ss:Color="#FFFFFF" /><Interior ss:Color="#D97706" ss:Pattern="Solid" /><Alignment ss:Horizontal="Center" ss:Vertical="Center" /><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#B45309" /></Borders>
        </Style>
        <Style ss:ID="title">
            <Font ss:Bold="1" ss:Size="16" ss:Color="#B45309" /><Alignment ss:Horizontal="Left" />
        </Style>
        <Style ss:ID="subtitle">
            <Font ss:Size="10" ss:Color="#475569" />
        </Style>
        <Style ss:ID="number">
            <NumberFormat ss:Format="0.00" /><Alignment ss:Horizontal="Center" /><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0" /></Borders>
        </Style>
        <Style ss:ID="total">
            <Font ss:Bold="1" ss:Size="11" ss:Color="#B45309" /><Interior ss:Color="#FEF3C7" ss:Pattern="Solid" /><NumberFormat ss:Format="0.00" /><Alignment ss:Horizontal="Center" /><Borders><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#D97706" /></Borders>
        </Style>
        <Style ss:ID="total_label">
            <Font ss:Bold="1" ss:Size="11" ss:Color="#B45309" /><Interior ss:Color="#FEF3C7" ss:Pattern="Solid" /><Borders><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#D97706" /></Borders>
        </Style>
        <Style ss:ID="synced">
            <Font ss:Color="#059669" /><Alignment ss:Horizontal="Center" /><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0" /></Borders>
        </Style>
        <Style ss:ID="pending">
            <Font ss:Color="#2563EB" /><Alignment ss:Horizontal="Center" /><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0" /></Borders>
        </Style>
        <Style ss:ID="center">
            <Alignment ss:Horizontal="Center" /><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0" /></Borders>
        </Style>
        <Style ss:ID="of_bold">
            <Font ss:Bold="1" ss:Color="#B45309" /><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0" /></Borders>
        </Style>
        <Style ss:ID="cell_text">
            <Alignment ss:Horizontal="Left" /><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0" /></Borders>
        </Style>
    </Styles>

    <!-- ======= FEUILLE 1 : RÉSUMÉ PAR OF ======= -->
    <Worksheet ss:Name="Résumé par OF">
        <Table>
            <Column ss:Width="120" />
            <Column ss:Width="100" />
            <Column ss:Width="100" />
            <Column ss:Width="80" />
            <Column ss:Width="110" />
            <Column ss:Width="110" />

            <!-- Titre -->
            <Row>
                <Cell ss:StyleID="title"><Data ss:Type="String">Pointage Atelier — Résumé par OF</Data></Cell>
            </Row>
            <Row>
                <Cell ss:StyleID="subtitle"><Data ss:Type="String">Semaine du
                        <?= date('d/m/Y', strtotime($dateDebut)) ?> au <?= date('d/m/Y', strtotime($dateFin)) ?></Data>
                </Cell>
            </Row>
            <Row>
                <Cell ss:StyleID="subtitle"><Data ss:Type="String">Exporté le <?= date('d/m/Y à H:i') ?></Data></Cell>
            </Row>
            <Row></Row>

            <!-- En-têtes -->
            <Row>
                <Cell ss:StyleID="header"><Data ss:Type="String">N° OF</Data></Cell>
                <Cell ss:StyleID="header"><Data ss:Type="String">Total Heures</Data></Cell>
                <Cell ss:StyleID="header"><Data ss:Type="String">H. Synchronisées</Data></Cell>
                <Cell ss:StyleID="header"><Data ss:Type="String">H. En attente</Data></Cell>
                <Cell ss:StyleID="header"><Data ss:Type="String">Opérateurs</Data></Cell>
                <Cell ss:StyleID="header"><Data ss:Type="String">Nb Pointages</Data></Cell>
            </Row>

            <!-- Données -->
            <?php foreach ($resume as $r): ?>
                <Row>
                    <Cell ss:StyleID="of_bold"><Data ss:Type="String"><?= htmlspecialchars($r['numero_of']) ?></Data></Cell>
                    <Cell ss:StyleID="number"><Data ss:Type="Number"><?= $r['total_heures'] ?></Data></Cell>
                    <Cell ss:StyleID="synced"><Data ss:Type="Number"><?= $r['heures_sync'] ?></Data></Cell>
                    <Cell ss:StyleID="pending"><Data ss:Type="Number"><?= $r['heures_pending'] ?></Data></Cell>
                    <Cell ss:StyleID="center"><Data ss:Type="Number"><?= $r['nb_operateurs'] ?></Data></Cell>
                    <Cell ss:StyleID="center"><Data ss:Type="Number"><?= $r['nb_pointages'] ?></Data></Cell>
                </Row>
            <?php endforeach; ?>

            <!-- Total -->
            <Row>
                <Cell ss:StyleID="total_label"><Data ss:Type="String">TOTAL</Data></Cell>
                <Cell ss:StyleID="total"><Data ss:Type="Number"><?= $totalGlobal ?></Data></Cell>
                <Cell ss:StyleID="total"><Data
                        ss:Type="Number"><?= array_sum(array_column($resume, 'heures_sync')) ?></Data></Cell>
                <Cell ss:StyleID="total"><Data
                        ss:Type="Number"><?= array_sum(array_column($resume, 'heures_pending')) ?></Data></Cell>
                <Cell ss:StyleID="total_label"></Cell>
                <Cell ss:StyleID="total"><Data
                        ss:Type="Number"><?= array_sum(array_column($resume, 'nb_pointages')) ?></Data></Cell>
            </Row>
        </Table>
    </Worksheet>

    <!-- ======= FEUILLE 2 : DÉTAIL DES POINTAGES ======= -->
    <Worksheet ss:Name="Détail pointages">
        <Table>
            <Column ss:Width="120" />
            <Column ss:Width="130" />
            <Column ss:Width="100" />
            <Column ss:Width="80" />
            <Column ss:Width="100" />
            <Column ss:Width="140" />

            <!-- Titre -->
            <Row>
                <Cell ss:StyleID="title"><Data ss:Type="String">Détail des pointages</Data></Cell>
            </Row>
            <Row>
                <Cell ss:StyleID="subtitle"><Data ss:Type="String">Semaine du
                        <?= date('d/m/Y', strtotime($dateDebut)) ?> au <?= date('d/m/Y', strtotime($dateFin)) ?></Data>
                </Cell>
            </Row>
            <Row></Row>

            <!-- En-têtes -->
            <Row>
                <Cell ss:StyleID="header"><Data ss:Type="String">N° OF</Data></Cell>
                <Cell ss:StyleID="header"><Data ss:Type="String">Opérateur</Data></Cell>
                <Cell ss:StyleID="header"><Data ss:Type="String">Date</Data></Cell>
                <Cell ss:StyleID="header"><Data ss:Type="String">Heures</Data></Cell>
                <Cell ss:StyleID="header"><Data ss:Type="String">Statut</Data></Cell>
                <Cell ss:StyleID="header"><Data ss:Type="String">Saisi le</Data></Cell>
            </Row>

            <!-- Données -->
            <?php foreach ($pointages as $p): ?>
                <Row>
                    <Cell ss:StyleID="of_bold"><Data ss:Type="String"><?= htmlspecialchars($p['numero_of']) ?></Data></Cell>
                    <Cell ss:StyleID="cell_text"><Data
                            ss:Type="String"><?= htmlspecialchars($p['operateur_prenom'] . ' ' . $p['operateur_nom']) ?></Data>
                    </Cell>
                    <Cell ss:StyleID="center"><Data
                            ss:Type="String"><?= date('d/m/Y', strtotime($p['date_pointage'])) ?></Data></Cell>
                    <Cell ss:StyleID="number"><Data ss:Type="Number"><?= $p['heures'] ?></Data></Cell>
                    <Cell ss:StyleID="<?= $p['statut'] === 'Synchronisé' ? 'synced' : 'pending' ?>"><Data
                            ss:Type="String"><?= $p['statut'] ?></Data></Cell>
                    <Cell ss:StyleID="center"><Data
                            ss:Type="String"><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></Data></Cell>
                </Row>
            <?php endforeach; ?>

            <!-- Total -->
            <Row>
                <Cell ss:StyleID="total_label"><Data ss:Type="String">TOTAL</Data></Cell>
                <Cell ss:StyleID="total_label"></Cell>
                <Cell ss:StyleID="total_label"></Cell>
                <Cell ss:StyleID="total"><Data
                        ss:Type="Number"><?= array_sum(array_column($pointages, 'heures')) ?></Data></Cell>
                <Cell ss:StyleID="total_label"></Cell>
                <Cell ss:StyleID="total_label"></Cell>
            </Row>
        </Table>
    </Worksheet>

    <!-- ======= FEUILLE 3 : FORMAT BC ======= -->
    <Worksheet ss:Name="Import Business Central">
        <Table>
            <Column ss:Width="120" />
            <Column ss:Width="100" />
            <Column ss:Width="100" />
            <Column ss:Width="100" />
            <Column ss:Width="180" />
            <Column ss:Width="80" />

            <Row>
                <Cell ss:StyleID="title"><Data ss:Type="String">Format d'import Business Central</Data></Cell>
            </Row>
            <Row>
                <Cell ss:StyleID="subtitle"><Data ss:Type="String">Données prêtes pour import dans le journal temps
                        BC</Data></Cell>
            </Row>
            <Row></Row>

            <Row>
                <Cell ss:StyleID="header"><Data ss:Type="String">N° Document</Data></Cell>
                <Cell ss:StyleID="header"><Data ss:Type="String">Date comptable</Data></Cell>
                <Cell ss:StyleID="header"><Data ss:Type="String">N° OF Production</Data></Cell>
                <Cell ss:StyleID="header"><Data ss:Type="String">Quantité (h)</Data></Cell>
                <Cell ss:StyleID="header"><Data ss:Type="String">Désignation</Data></Cell>
                <Cell ss:StyleID="header"><Data ss:Type="String">Type travail</Data></Cell>
            </Row>

            <?php
            $docNum = 1;
            foreach ($pointages as $p):
                ?>
                <Row>
                    <Cell ss:StyleID="cell_text"><Data
                            ss:Type="String">PTG-<?= str_pad($docNum++, 5, '0', STR_PAD_LEFT) ?></Data></Cell>
                    <Cell ss:StyleID="center"><Data
                            ss:Type="String"><?= date('Y-m-d', strtotime($p['date_pointage'])) ?></Data></Cell>
                    <Cell ss:StyleID="of_bold"><Data ss:Type="String"><?= htmlspecialchars($p['numero_of']) ?></Data></Cell>
                    <Cell ss:StyleID="number"><Data ss:Type="Number"><?= $p['heures'] ?></Data></Cell>
                    <Cell ss:StyleID="cell_text"><Data ss:Type="String">Pointage
                            <?= htmlspecialchars($p['operateur_prenom'] . ' ' . $p['operateur_nom']) ?></Data></Cell>
                    <Cell ss:StyleID="center"><Data ss:Type="String">PROD</Data></Cell>
                </Row>
            <?php endforeach; ?>
        </Table>
    </Worksheet>

</Workbook>