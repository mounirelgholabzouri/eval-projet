<?php
/**
 * Export Excel (SpreadsheetML) — Récapitulatif des évaluations
 * Feuille 1 : Toutes les évaluations (filtrables par module/groupe)
 * Feuille 2 : Moyenne par stagiaire
 * Accessible admin + depuis result.php via token de session
 */
require_once __DIR__ . '/../includes/functions.php';

// ── Auth : admin OU stagiaire connecté ──────────────────────────────────────
$isAdmin = false;
session_name(ADMIN_SESSION_NAME);
session_start();
if (!empty($_SESSION['admin_id'])) {
    $isAdmin = true;
} else {
    // Tenter session stagiaire
    session_write_close();
    session_name(SESSION_EVAL_NAME);
    session_start();
    if (empty($_SESSION['stagiaire_id'])) {
        http_response_code(403);
        exit('Accès refusé.');
    }
}

$pdo = getDB();
$moduleId = (int)($_GET['module_id'] ?? 0);
$groupeId = (int)($_GET['groupe_id'] ?? 0);

// ── Requête évaluations ──────────────────────────────────────────────────────
$where  = ["se.statut = 'termine'"];
$params = [];
if ($moduleId > 0) { $where[] = 'se.module_id = ?'; $params[] = $moduleId; }
if ($groupeId > 0) { $where[] = 'se.groupe_id = ?'; $params[] = $groupeId; }

$whereStr = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT
        se.id,
        COALESCE(st.nom,  se.nom)    AS nom,
        COALESCE(st.prenom, se.prenom) AS prenom,
        COALESCE(g.nom, se.groupe_libre, '—') AS groupe,
        m.nom  AS module,
        m.note_max,
        se.date_debut,
        se.date_fin,
        se.score,
        se.total_points,
        se.pourcentage
    FROM sessions_eval se
    JOIN modules m ON m.id = se.module_id
    LEFT JOIN stagiaires st ON st.id = se.stagiaire_id
    LEFT JOIN groupes    g  ON g.id  = se.groupe_id
    WHERE $whereStr
    ORDER BY g.nom, se.nom, se.prenom, se.date_debut
");
$stmt->execute($params);
$evaluations = $stmt->fetchAll();

// ── Requête moyennes par stagiaire ───────────────────────────────────────────
$whereStag  = ["se.statut = 'termine'"];
$paramsStag = [];
if ($moduleId > 0) { $whereStag[] = 'se.module_id = ?'; $paramsStag[] = $moduleId; }
if ($groupeId > 0) { $whereStag[] = 'se.groupe_id = ?'; $paramsStag[] = $groupeId; }

$stmtMoy = $pdo->prepare("
    SELECT
        MAX(COALESCE(st.nom,  se.nom))    AS nom,
        MAX(COALESCE(st.prenom, se.prenom)) AS prenom,
        MAX(COALESCE(g.nom, se.groupe_libre, '—')) AS groupe,
        COUNT(se.id)              AS nb_evaluations,
        AVG(se.pourcentage)       AS moy_pourcentage,
        MAX(se.pourcentage)       AS max_pourcentage,
        MIN(se.pourcentage)       AS min_pourcentage,
        SUM(se.score)             AS total_score,
        SUM(se.total_points)      AS total_points_possibles
    FROM sessions_eval se
    LEFT JOIN stagiaires st ON st.id = se.stagiaire_id
    LEFT JOIN groupes    g  ON g.id  = se.groupe_id
    WHERE " . implode(' AND ', $whereStag) . "
    GROUP BY COALESCE(se.stagiaire_id, CONCAT(se.nom, '|', se.prenom))
    ORDER BY MAX(g.nom), MAX(se.nom), MAX(se.prenom)
");
$stmtMoy->execute($paramsStag);
$moyennes = $stmtMoy->fetchAll();

// ── Nom du fichier ────────────────────────────────────────────────────────────
$suffix = $moduleId > 0
    ? '_module' . $moduleId
    : ($groupeId > 0 ? '_groupe' . $groupeId : '');
$filename = 'recap_evaluations' . $suffix . '_' . date('Y-m-d') . '.xls';

// ── Headers HTTP ─────────────────────────────────────────────────────────────
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');

// ── Helpers XML ──────────────────────────────────────────────────────────────
function xlCell(string $value, string $type = 'String', string $style = ''): string {
    $v = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $s = $style ? " ss:StyleID=\"$style\"" : '';
    return "<Cell$s><Data ss:Type=\"$type\">$v</Data></Cell>";
}
function xlNum(float $value, string $style = ''): string {
    $s = $style ? " ss:StyleID=\"$style\"" : '';
    return "<Cell$s><Data ss:Type=\"Number\">$value</Data></Cell>";
}
function xlRow(array $cells): string {
    return '<Row>' . implode('', $cells) . '</Row>' . "\n";
}

function getMentionLabel(float $pct): string {
    if ($pct >= 90) return 'Excellent';
    if ($pct >= 75) return 'Très bien';
    if ($pct >= 60) return 'Bien';
    if ($pct >= 50) return 'Passable';
    return 'Insuffisant';
}

// ── Sortie SpreadsheetML ─────────────────────────────────────────────────────
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
  xmlns:o="urn:schemas-microsoft-com:office:office"
  xmlns:x="urn:schemas-microsoft-com:office:excel"
  xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
  xmlns:html="http://www.w3.org/TR/REC-html40">

<Styles>
  <!-- En-tête bleu foncé -->
  <Style ss:ID="header">
    <Font ss:Bold="1" ss:Color="#FFFFFF" ss:Size="11"/>
    <Interior ss:Color="#1e3a5f" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/>
    </Borders>
  </Style>
  <!-- En-tête vert pour moyennes -->
  <Style ss:ID="header2">
    <Font ss:Bold="1" ss:Color="#FFFFFF" ss:Size="11"/>
    <Interior ss:Color="#1a5c38" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
  </Style>
  <!-- Ligne paire -->
  <Style ss:ID="even">
    <Interior ss:Color="#f0f4fa" ss:Pattern="Solid"/>
  </Style>
  <!-- Nombre centré -->
  <Style ss:ID="numcenter">
    <Alignment ss:Horizontal="Center"/>
  </Style>
  <!-- Nombre centré pair -->
  <Style ss:ID="numcentereven">
    <Alignment ss:Horizontal="Center"/>
    <Interior ss:Color="#f0f4fa" ss:Pattern="Solid"/>
  </Style>
  <!-- Pourcentage -->
  <Style ss:ID="pct">
    <NumberFormat ss:Format="0.00&quot;%&quot;"/>
    <Alignment ss:Horizontal="Center"/>
  </Style>
  <Style ss:ID="pcteven">
    <NumberFormat ss:Format="0.00&quot;%&quot;"/>
    <Alignment ss:Horizontal="Center"/>
    <Interior ss:Color="#f0f4fa" ss:Pattern="Solid"/>
  </Style>
  <!-- Titre principal -->
  <Style ss:ID="title">
    <Font ss:Bold="1" ss:Size="14" ss:Color="#1e3a5f"/>
  </Style>
  <!-- Sous-titre -->
  <Style ss:ID="subtitle">
    <Font ss:Italic="1" ss:Size="10" ss:Color="#666666"/>
  </Style>
  <!-- Succès -->
  <Style ss:ID="success">
    <Font ss:Color="#155724" ss:Bold="1"/>
    <Interior ss:Color="#d4edda" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center"/>
  </Style>
  <!-- Danger -->
  <Style ss:ID="danger">
    <Font ss:Color="#721c24" ss:Bold="1"/>
    <Interior ss:Color="#f8d7da" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center"/>
  </Style>
</Styles>

<?php
// ════════════════════════════════════════════════════════════════
// FEUILLE 1 — Toutes les évaluations
// ════════════════════════════════════════════════════════════════
?>
<Worksheet ss:Name="Évaluations">
<Table ss:DefaultRowHeight="18">
  <!-- Largeurs colonnes -->
  <Column ss:Width="120"/><!-- Nom -->
  <Column ss:Width="100"/><!-- Prénom -->
  <Column ss:Width="130"/><!-- Groupe -->
  <Column ss:Width="160"/><!-- Module -->
  <Column ss:Width="110"/><!-- Date -->
  <Column ss:Width="80"/> <!-- Score brut -->
  <Column ss:Width="70"/> <!-- Note /20 -->
  <Column ss:Width="70"/> <!-- % -->
  <Column ss:Width="90"/> <!-- Mention -->

  <!-- Titre -->
  <Row ss:Height="28">
    <Cell ss:MergeAcross="8" ss:StyleID="title">
      <Data ss:Type="String">Récapitulatif des évaluations — <?= htmlspecialchars(date('d/m/Y')) ?></Data>
    </Cell>
  </Row>
  <Row ss:Height="16">
    <Cell ss:MergeAcross="8" ss:StyleID="subtitle">
      <Data ss:Type="String">Généré le <?= htmlspecialchars(date('d/m/Y à H:i')) ?> — <?= count($evaluations) ?> évaluation(s) terminée(s)</Data>
    </Cell>
  </Row>
  <Row ss:Height="6"/>

  <!-- En-têtes -->
  <?= xlRow([
    xlCell('Nom',        'String', 'header'),
    xlCell('Prénom',     'String', 'header'),
    xlCell('Groupe',     'String', 'header'),
    xlCell('Module',     'String', 'header'),
    xlCell('Date',       'String', 'header'),
    xlCell('Score brut', 'String', 'header'),
    xlCell('Note /20',   'String', 'header'),
    xlCell('%',          'String', 'header'),
    xlCell('Mention',    'String', 'header'),
  ]) ?>

<?php foreach ($evaluations as $i => $e):
    $noteMax   = (int)($e['note_max'] ?? 20);
    $total     = (float)$e['total_points'];
    $score     = (float)$e['score'];
    $pct       = (float)$e['pourcentage'];
    $noteSur   = $total > 0 ? round($score / $total * $noteMax, 2) : 0;
    $mention   = getMentionLabel($pct);
    $stylePct  = $pct >= 50 ? 'success' : 'danger';
    $even      = ($i % 2 === 1);
    $styleBase = $even ? 'even' : '';
    $styleNum  = $even ? 'numcentereven' : 'numcenter';
?>
  <?= xlRow([
    xlCell(strtoupper($e['nom']),   'String', $styleBase),
    xlCell($e['prenom'],            'String', $styleBase),
    xlCell($e['groupe'],            'String', $styleBase),
    xlCell($e['module'],            'String', $styleBase),
    xlCell(date('d/m/Y H:i', strtotime($e['date_debut'])), 'String', $styleBase),
    xlCell(number_format($score, 1, ',', '') . ' / ' . number_format($total, 1, ',', ''), 'String', $styleNum),
    xlCell(number_format($noteSur, 2, ',', '') . ' / ' . $noteMax, 'String', $styleNum),
    xlCell(number_format($pct, 2, ',', '') . '%', 'String', $stylePct),
    xlCell($mention, 'String', $stylePct),
  ]) ?>
<?php endforeach; ?>

</Table>
</Worksheet>

<?php
// ════════════════════════════════════════════════════════════════
// FEUILLE 2 — Moyennes par stagiaire
// ════════════════════════════════════════════════════════════════
?>
<Worksheet ss:Name="Moyennes par stagiaire">
<Table ss:DefaultRowHeight="18">
  <Column ss:Width="120"/><!-- Nom -->
  <Column ss:Width="100"/><!-- Prénom -->
  <Column ss:Width="130"/><!-- Groupe -->
  <Column ss:Width="70"/> <!-- Nb éval -->
  <Column ss:Width="90"/> <!-- Moy % -->
  <Column ss:Width="90"/> <!-- Meilleure -->
  <Column ss:Width="90"/> <!-- Moins bonne -->
  <Column ss:Width="90"/> <!-- Mention moy -->

  <!-- Titre -->
  <Row ss:Height="28">
    <Cell ss:MergeAcross="7" ss:StyleID="title">
      <Data ss:Type="String">Moyennes par stagiaire — <?= htmlspecialchars(date('d/m/Y')) ?></Data>
    </Cell>
  </Row>
  <Row ss:Height="6"/>

  <!-- En-têtes -->
  <?= xlRow([
    xlCell('Nom',           'String', 'header2'),
    xlCell('Prénom',        'String', 'header2'),
    xlCell('Groupe',        'String', 'header2'),
    xlCell('Nb éval.',      'String', 'header2'),
    xlCell('Moyenne %',     'String', 'header2'),
    xlCell('Meilleure note','String', 'header2'),
    xlCell('Note la + basse','String','header2'),
    xlCell('Mention moy.',  'String', 'header2'),
  ]) ?>

<?php foreach ($moyennes as $i => $m):
    $moy     = (float)$m['moy_pourcentage'];
    $max     = (float)$m['max_pourcentage'];
    $min     = (float)$m['min_pourcentage'];
    $mention = getMentionLabel($moy);
    $stylePct = $moy >= 50 ? 'success' : 'danger';
    $even    = ($i % 2 === 1);
    $styleBase = $even ? 'even' : '';
    $styleNum  = $even ? 'numcentereven' : 'numcenter';
?>
  <?= xlRow([
    xlCell(strtoupper($m['nom']),    'String', $styleBase),
    xlCell($m['prenom'],             'String', $styleBase),
    xlCell($m['groupe'],             'String', $styleBase),
    xlCell((string)$m['nb_evaluations'], 'String', $styleNum),
    xlCell(number_format($moy, 2, ',', '') . '%', 'String', $stylePct),
    xlCell(number_format($max, 2, ',', '') . '%', 'String', $styleNum),
    xlCell(number_format($min, 2, ',', '') . '%', 'String', $styleNum),
    xlCell($mention, 'String', $stylePct),
  ]) ?>
<?php endforeach; ?>

<?php if (!empty($moyennes)):
    $globalMoy = array_sum(array_column($moyennes, 'moy_pourcentage')) / count($moyennes);
?>
  <Row ss:Height="6"/>
  <?= xlRow([
    xlCell('MOYENNE GÉNÉRALE', 'String', 'header2'),
    xlCell('', 'String', 'header2'),
    xlCell('', 'String', 'header2'),
    xlCell((string)count($moyennes) . ' stagiaire(s)', 'String', 'header2'),
    xlCell(number_format($globalMoy, 2, ',', '') . '%', 'String', 'header2'),
    xlCell('', 'String', 'header2'),
    xlCell('', 'String', 'header2'),
    xlCell(getMentionLabel($globalMoy), 'String', 'header2'),
  ]) ?>
<?php endif; ?>

</Table>
</Worksheet>

</Workbook>
