<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

$filterModule = (int)($_GET['module_id'] ?? 0);
$filterGroupe = trim($_GET['groupe'] ?? '');

if (!$filterModule) {
    header('Location: results.php');
    exit;
}

// Récupérer les infos du module
$stmtMod = $pdo->prepare("SELECT * FROM modules WHERE id = ?");
$stmtMod->execute([$filterModule]);
$module = $stmtMod->fetch();
if (!$module) { header('Location: results.php'); exit; }

// Récupérer toutes les sessions terminées pour ce module
$where  = ['s.module_id = ?', "s.statut = 'termine'"];
$params = [$filterModule];
if ($filterGroupe) {
    $where[]  = "(g.nom LIKE ? OR s.groupe_libre LIKE ?)";
    $params[] = "%$filterGroupe%";
    $params[] = "%$filterGroupe%";
}
$whereStr = implode(' AND ', $where);
$stmtSes = $pdo->prepare("
    SELECT s.*, COALESCE(g.nom, s.groupe_libre) AS groupe_nom
    FROM sessions_eval s
    LEFT JOIN groupes g ON g.id = s.groupe_id
    WHERE $whereStr
    ORDER BY s.nom, s.prenom
");
$stmtSes->execute($params);
$sessions = $stmtSes->fetchAll();

if (empty($sessions)) {
    header('Location: results.php?module_id=' . $filterModule);
    exit;
}

// Récupérer les parties du module
$parties = getPartiesModule($filterModule);
$partiesMap = [];
foreach ($parties as $p) {
    $partiesMap[$p['id']] = $p['nom'];
}

// Pré-charger les réponses de toutes les sessions en une requête
$sessionIds = array_column($sessions, 'id');
if ($sessionIds) {
    $inPlaceholders = implode(',', array_fill(0, count($sessionIds), '?'));
    $stmtAllRep = $pdo->prepare("
        SELECT rs.session_id, rs.question_id, rs.reponse_texte, rs.is_correct, rs.points_obtenus,
               q.texte AS question_texte, q.type, q.points AS points_max, q.partie_id, q.ordre,
               cr.texte AS choix_etudiant
        FROM reponses_stagiaires rs
        JOIN questions q ON q.id = rs.question_id
        LEFT JOIN choix_reponses cr ON cr.id = rs.choix_id
        WHERE rs.session_id IN ($inPlaceholders)
        ORDER BY rs.session_id, q.partie_id, q.ordre, q.id
    ");
    $stmtAllRep->execute($sessionIds);
    $allReponses = [];
    foreach ($stmtAllRep->fetchAll() as $row) {
        $allReponses[$row['session_id']][] = $row;
    }
} else {
    $allReponses = [];
}

function s($str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

$annee = '2025/2026';
$logoPath = '../assets/img/ofppt_logo.png';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Impression — <?= s($module['nom']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 11pt; color: #000; background: #fff; }

        /* ---- En-tête ---- */
        .header { border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 8px; }
        .header-top { display: flex; align-items: center; gap: 12px; }
        .header-logo { width: 70px; height: auto; flex-shrink: 0; }
        .header-org { font-size: 9pt; font-style: italic; font-weight: bold; flex-grow: 1; text-align: center; }
        .header-year { text-align: center; font-size: 9pt; margin-top: 4px; }
        .module-box { border: 1px solid #000; padding: 4px 8px; margin: 6px 0; text-align: center; }
        .module-box .label  { font-size: 8pt; }
        .module-box .titre  { font-size: 10pt; font-weight: bold; }
        .info-row { display: flex; justify-content: space-between; font-size: 10pt; margin: 3px 0; border-bottom: 1px solid #000; padding-bottom: 2px; }
        .info-row span { font-weight: normal; }

        /* ---- Questions ---- */
        .partie-titre { font-weight: bold; font-size: 10pt; background: #f0f0f0; padding: 3px 6px; margin: 10px 0 4px; border-left: 3px solid #000; }
        .question { margin: 6px 0; padding: 5px 8px; border: 1px solid #ccc; page-break-inside: avoid; }

        .q-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
        .q-num  { font-weight: bold; font-size: 10pt; flex-shrink: 0; }
        .q-text { flex-grow: 1; font-size: 10pt; }
        .q-pts  { font-size: 9pt; font-weight: bold; flex-shrink: 0; white-space: nowrap; }

        .q-answer { margin-top: 4px; font-size: 9.5pt; padding-left: 16px; }
        .lbl { color: #333; }
        .ans { font-weight: bold; }

        /* ---- Score final ---- */
        .score-box { margin-top: 10px; padding: 6px 10px; border: 2px solid #000; display: flex; justify-content: space-between; align-items: center; }
        .score-box .total { font-size: 13pt; font-weight: bold; }
        .score-box .mention { font-size: 11pt; font-weight: bold; padding: 2px 10px; border: 2px solid #000; }

        /* ---- Séparateur de pages ---- */
        .page-break { page-break-after: always; break-after: page; }

        /* ---- Barre d'outils (masquée à l'impression) ---- */
        @media print {
            .no-print { display: none !important; }
            body { font-size: 10.5pt; }
            .question { page-break-inside: avoid; }
        }
        .toolbar { background: #2c3e50; color: #fff; padding: 10px 20px; display: flex; gap: 12px; align-items: center; }
        .toolbar h1 { font-size: 14pt; font-weight: bold; flex-grow: 1; }
        .toolbar a, .toolbar button {
            background: #fff; color: #2c3e50; border: none; padding: 6px 14px;
            border-radius: 4px; cursor: pointer; font-size: 10pt; font-weight: bold;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
        }
        .toolbar button:hover, .toolbar a:hover { background: #f0f0f0; }
    </style>
</head>
<body>

<!-- Barre d'outils (masquée à l'impression) -->
<div class="toolbar no-print">
    <h1>📄 Impression des tests — <?= s($module['nom']) ?> (<?= count($sessions) ?> stagiaires)</h1>
    <a href="results.php?module_id=<?= $filterModule ?>">← Retour</a>
    <button onclick="window.print()">🖨 Imprimer / PDF</button>
</div>

<?php foreach ($sessions as $idx => $session):
    $reponses   = $allReponses[$session['id']] ?? [];
    $mention    = getMention((float)$session['pourcentage']);
    $groupe     = s($session['groupe_nom'] ?: '—');
    $nomComplet = s($session['prenom'] . ' ' . $session['nom']);

    // Regrouper les réponses par partie
    $repParPartie = [];
    foreach ($reponses as $r) {
        $repParPartie[$r['partie_id']][] = $r;
    }
    $qNum = 0;

    // Score direct = note /20 (40 q × 0.5 pt = 20 pts)
    $note20 = number_format((float)$session['score'], 2);
?>

<div class="exam-page <?= $idx < count($sessions) - 1 ? 'page-break' : '' ?>">
    <!-- En-tête identique au modèle -->
    <div class="header">
        <div class="header-top">
            <?php if (file_exists(__DIR__ . '/' . $logoPath)): ?>
            <img src="<?= $logoPath ?>" class="header-logo" alt="OFPPT">
            <?php endif; ?>
            <div class="header-org">Direction Régionale Rabat – Salé – Kénitra</div>
        </div>
        <div class="header-year">Année de Formation <?= s($annee) ?></div>
    </div>

    <div class="module-box">
        <div class="label">Contrôle</div>
        <div class="titre"><?= s($module['nom']) ?></div>
    </div>

    <div class="info-row">
        <span>Filière/Groupe&nbsp;: <strong><?= $groupe ?></strong></span>
        <span>Durée&nbsp;: <?= (int)$module['duree_minutes'] ?> min</span>
    </div>
    <div class="info-row">
        <span>Niveau&nbsp;: 2</span>
        <span>Note&nbsp;: <strong><?= $note20 ?></strong> / 20</span>
    </div>
    <div class="info-row">
        <span>Nom et Prénom&nbsp;: <strong><?= $nomComplet ?></strong></span>
        <span>Date&nbsp;: <?= date('d/m/Y', strtotime($session['date_debut'])) ?></span>
    </div>

    <!-- Questions et réponses -->
    <?php if (empty($reponses)): ?>
        <p style="margin-top:12px;color:#888;font-style:italic">Aucune réponse enregistrée.</p>
    <?php else: ?>
        <?php foreach ($parties as $partie):
            if (!isset($repParPartie[$partie['id']])) continue;
        ?>
        <?php if (count($parties) > 1): ?>
        <div class="partie-titre"><?= s($partie['nom']) ?></div>
        <?php endif; ?>

        <?php foreach ($repParPartie[$partie['id']] as $r):
            $qNum++;
            $isTexte  = ($r['type'] === 'texte_libre');
            $qNote = number_format((float)$r['points_obtenus'], 2);
            $qMax  = number_format((float)$r['points_max'], 2);
        ?>
        <div class="question">
            <div class="q-header">
                <span class="q-num">Q<?= $qNum ?>.</span>
                <span class="q-text"><?= s($r['question_texte']) ?></span>
                <span class="q-pts"><strong><?= $qNote ?></strong> / <?= $qMax ?></span>
            </div>
            <div class="q-answer">
                <span class="lbl">Réponse&nbsp;:</span>
                <span class="ans">
                    <?php if ($isTexte): ?>
                        <?= $r['reponse_texte'] ? s($r['reponse_texte']) : '<em>—</em>' ?>
                    <?php else: ?>
                        <?= $r['choix_etudiant'] ? s($r['choix_etudiant']) : '<em>Sans réponse</em>' ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<?php endforeach; ?>

<script>
// Auto-print si param ?auto=1
if (new URLSearchParams(location.search).get('auto') === '1') window.print();
</script>
</body>
</html>
