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

// Filtres
$filterWeek = $_GET['week'] ?? 'current';
$filterOf = trim($_GET['of'] ?? '');

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
        CASE WHEN p.synced_bc = 1 THEN \'Synchronisé\' ELSE \'En attente\' END as statut,
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
        SUM(CASE WHEN p.synced_bc = 1 THEN p.heures ELSE 0 END) as heures_sync,
        SUM(CASE WHEN p.synced_bc = 0 THEN p.heures ELSE 0 END) as heures_pending
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

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">

 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Title>Pointage Atelier - Semaine du <?= $dateDebut ?></Title>
  <Author>Pointage Atelier SaaS</Author>
  <Created><?= date('c') ?></Created>
 </DocumentProperties>

 <Styles>
  <Style ss:ID="header">
   <Font ss:Bold="1" ss:Size="11" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#F59E0B" ss:Pattern="Solid"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D97706"/>
   </Borders>
  </Style>
  <Style ss:ID="title">
   <Font ss:Bold="1" ss:Size="14" ss:Color="#0A0F1A"/>
   <Alignment ss:Horizontal="Left"/>
  </Style>
  <Style ss:ID="subtitle">
   <Font ss:Size="10" ss:Color="#64748B"/>
  </Style>
  <Style ss:ID="number">
   <NumberFormat ss:Format="0.00"/>
   <Alignment ss:Horizontal="Center"/>
  </Style>
  <Style ss:ID="total">
   <Font ss:Bold="1" ss:Size="11" ss:Color="#F59E0B"/>
   <Interior ss:Color="#FEF3C7" ss:Pattern="Solid"/>
   <NumberFormat ss:Format="0.00"/>
   <Alignment ss:Horizontal="Center"/>
   <Borders>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#F59E0B"/>
   </Borders>
  </Style>
  <Style ss:ID="total_label">
   <Font ss:Bold="1" ss:Size="11" ss:Color="#F59E0B"/>
   <Interior ss:Color="#FEF3C7" ss:Pattern="Solid"/>
   <Borders>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#F59E0B"/>
   </Borders>
  </Style>
  <Style ss:ID="synced">
   <Font ss:Color="#10B981"/>
   <Alignment ss:Horizontal="Center"/>
  </Style>
  <Style ss:ID="pending">
   <Font ss:Color="#3B82F6"/>
   <Alignment ss:Horizontal="Center"/>
  </Style>
  <Style ss:ID="center">
   <Alignment ss:Horizontal="Center"/>
  </Style>
  <Style ss:ID="of_bold">
   <Font ss:Bold="1" ss:Color="#D97706"/>
  </Style>
 </Styles>

 <!-- ======= FEUILLE 1 : RÉSUMÉ PAR OF ======= -->
 <Worksheet ss:Name="Résumé par OF">
  <Table>
   <Column ss:Width="120"/>
   <Column ss:Width="100"/>
   <Column ss:Width="100"/>
   <Column ss:Width="80"/>
   <Column ss:Width="110"/>
   <Column ss:Width="110"/>

   <!-- Titre -->
   <Row>
    <Cell ss:StyleID="title"><Data ss:Type="String">Pointage Atelier — Résumé par OF</Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="subtitle"><Data ss:Type="String">Semaine du <?= date('d/m/Y', strtotime($dateDebut)) ?> au <?= date('d/m/Y', strtotime($dateFin)) ?></Data></Cell>
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
    <Cell ss:StyleID="total"><Data ss:Type="Number"><?= array_sum(array_column($resume, 'heures_sync')) ?></Data></Cell>
    <Cell ss:StyleID="total"><Data ss:Type="Number"><?= array_sum(array_column($resume, 'heures_pending')) ?></Data></Cell>
    <Cell ss:StyleID="total_label"></Cell>
    <Cell ss:StyleID="total"><Data ss:Type="Number"><?= array_sum(array_column($resume, 'nb_pointages')) ?></Data></Cell>
   </Row>
  </Table>
 </Worksheet>

 <!-- ======= FEUILLE 2 : DÉTAIL DES POINTAGES ======= -->
 <Worksheet ss:Name="Détail pointages">
  <Table>
   <Column ss:Width="120"/>
   <Column ss:Width="130"/>
   <Column ss:Width="100"/>
   <Column ss:Width="80"/>
   <Column ss:Width="100"/>
   <Column ss:Width="140"/>

   <!-- Titre -->
   <Row>
    <Cell ss:StyleID="title"><Data ss:Type="String">Détail des pointages</Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="subtitle"><Data ss:Type="String">Semaine du <?= date('d/m/Y', strtotime($dateDebut)) ?> au <?= date('d/m/Y', strtotime($dateFin)) ?></Data></Cell>
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
    <Cell><Data ss:Type="String"><?= htmlspecialchars($p['operateur_prenom'] . ' ' . $p['operateur_nom']) ?></Data></Cell>
    <Cell ss:StyleID="center"><Data ss:Type="String"><?= date('d/m/Y', strtotime($p['date_pointage'])) ?></Data></Cell>
    <Cell ss:StyleID="number"><Data ss:Type="Number"><?= $p['heures'] ?></Data></Cell>
    <Cell ss:StyleID="<?= $p['statut'] === 'Synchronisé' ? 'synced' : 'pending' ?>"><Data ss:Type="String"><?= $p['statut'] ?></Data></Cell>
    <Cell ss:StyleID="center"><Data ss:Type="String"><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></Data></Cell>
   </Row>
   <?php endforeach; ?>

   <!-- Total -->
   <Row>
    <Cell ss:StyleID="total_label"><Data ss:Type="String">TOTAL</Data></Cell>
    <Cell ss:StyleID="total_label"></Cell>
    <Cell ss:StyleID="total_label"></Cell>
    <Cell ss:StyleID="total"><Data ss:Type="Number"><?= array_sum(array_column($pointages, 'heures')) ?></Data></Cell>
    <Cell ss:StyleID="total_label"></Cell>
    <Cell ss:StyleID="total_label"></Cell>
   </Row>
  </Table>
 </Worksheet>

 <!-- ======= FEUILLE 3 : FORMAT BC ======= -->
 <Worksheet ss:Name="Import Business Central">
  <Table>
   <Column ss:Width="120"/>
   <Column ss:Width="100"/>
   <Column ss:Width="100"/>
   <Column ss:Width="100"/>
   <Column ss:Width="180"/>
   <Column ss:Width="80"/>

   <Row>
    <Cell ss:StyleID="title"><Data ss:Type="String">Format d'import Business Central</Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="subtitle"><Data ss:Type="String">Données prêtes pour import dans le journal temps BC</Data></Cell>
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
    <Cell><Data ss:Type="String">PTG-<?= str_pad($docNum++, 5, '0', STR_PAD_LEFT) ?></Data></Cell>
    <Cell ss:StyleID="center"><Data ss:Type="String"><?= date('Y-m-d', strtotime($p['date_pointage'])) ?></Data></Cell>
    <Cell ss:StyleID="of_bold"><Data ss:Type="String"><?= htmlspecialchars($p['numero_of']) ?></Data></Cell>
    <Cell ss:StyleID="number"><Data ss:Type="Number"><?= $p['heures'] ?></Data></Cell>
    <Cell><Data ss:Type="String">Pointage <?= htmlspecialchars($p['operateur_prenom'] . ' ' . $p['operateur_nom']) ?></Data></Cell>
    <Cell ss:StyleID="center"><Data ss:Type="String">PROD</Data></Cell>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>

</Workbook>
