<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

$filterModule  = (int)($_GET['module_id'] ?? 0);
$filterGroupe  = trim($_GET['groupe'] ?? '');
$filterSession = (int)($_GET['session_id'] ?? 0); // impression d'un seul stagiaire

// Mode session unique : on déduit le module depuis la session (via évaluation)
if ($filterSession && !$filterModule) {
    $row = $pdo->prepare("SELECT e.module_id FROM sessions_eval s JOIN evaluations e ON e.id = s.evaluation_id WHERE s.id = ?");
    $row->execute([$filterSession]);
    $filterModule = (int)($row->fetchColumn() ?: 0);
}

if (!$filterModule) {
    header('Location: results.php');
    exit;
}

// Récupérer les infos du module
$stmtMod = $pdo->prepare("SELECT * FROM modules WHERE id = ?");
$stmtMod->execute([$filterModule]);
$module = $stmtMod->fetch();
if (!$module) { header('Location: results.php'); exit; }

// Récupérer les sessions (une seule ou toutes)
if ($filterSession) {
    $where  = ['s.id = ?', "s.statut = 'termine'"];
    $params = [$filterSession];
} else {
    $where  = ['e.module_id = ?', "s.statut = 'termine'"];
    $params = [$filterModule];
    if ($filterGroupe) {
        $where[]  = "(g.nom LIKE ? OR s.groupe_libre LIKE ?)";
        $params[] = "%$filterGroupe%";
        $params[] = "%$filterGroupe%";
    }
}
$stmtSes = $pdo->prepare("
    SELECT s.*, COALESCE(g.nom, s.groupe_libre) AS groupe_nom
    FROM sessions_eval s
    JOIN evaluations e ON e.id = s.evaluation_id
    LEFT JOIN groupes g ON g.id = s.groupe_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY s.nom, s.prenom
");
$stmtSes->execute($params);
$sessions = $stmtSes->fetchAll();

if (empty($sessions)) {
    header('Location: results.php?module_id=' . $filterModule);
    exit;
}

// Pré-charger les réponses de toutes les sessions en une requête
$sessionIds = array_column($sessions, 'id');
if ($sessionIds) {
    $inPlaceholders = implode(',', array_fill(0, count($sessionIds), '?'));
    $stmtAllRep = $pdo->prepare("
        SELECT rs.session_id, rs.question_id, rs.reponse_texte, rs.is_correct, rs.points_obtenus,
               q.texte AS question_texte, q.type, q.points AS points_max, q.ordre,
               cr.texte AS choix_etudiant
        FROM reponses_stagiaires rs
        JOIN questions q ON q.id = rs.question_id
        LEFT JOIN choix_reponses cr ON cr.id = rs.choix_id
        WHERE rs.session_id IN ($inPlaceholders)
        ORDER BY rs.session_id, q.ordre, q.id
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

$annee    = '2025/2026';
$isEfm    = ($module['type'] ?? 'qcm') === 'efm';
$noteMax  = (int)($module['note_max'] ?? 20);
$filiere  = $module['efm_filiere'] ?? '';
$codeModule = $module['efm_code_module'] ?? '';
$etabl    = $module['efm_etablissement'] ?: 'Direction Régionale RABAT-SALÉ-KENITRA';
$dureeMin = (int)($module['duree_minutes'] ?? 0);
$dureeStr = $dureeMin ? $dureeMin . ' min' : '';

$logoPath = __DIR__ . '/../assets/img/logo_efm.png';
$logoB64  = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : '';

$tamponPath = __DIR__ . '/../assets/img/tampon_ofppt.png';
$tamponB64  = file_exists($tamponPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($tamponPath)) : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Impression — <?= s($module['nom']) ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Calibri', Arial, sans-serif; font-size: 11pt; color: #000; background: #e0e0e0; }

        /* ── En-tête OFPPT ── */
        .efm-header { width: 100%; border-collapse: collapse; font-size: 10pt; }
        .efm-header td { vertical-align: top; }
        .h-logo { width: 55%; border: 1px solid #000; padding: 2mm 3mm; vertical-align: middle; }
        .h-logo-inner { display: flex; align-items: center; gap: 3mm; }
        .h-logo-inner img { height: 16mm; }
        .h-org { font-size: 9.5pt; line-height: 1.4; }
        .h-efm { border: 1px solid #000; border-top: none; padding: 3mm; text-align: center; font-size: 13pt; font-style: italic; vertical-align: middle; }
        .h-identity { width: 45%; border: 1px solid #000; padding: 4mm 5mm; vertical-align: top; font-weight: bold; }
        .h-identity div { margin-bottom: 3mm; }
        .h-identity div:last-child { margin-bottom: 0; }
        .h-code { border: 1px solid #000; border-top: none; text-align: center; padding: 2mm 3mm; font-size: 10.5pt; }
        .h-code div { line-height: 1.6; }

        /* ── Infos module ── */
        .info-table { width: 100%; border-collapse: collapse; margin-top: -1px; margin-bottom: 3mm; font-size: 10pt; }
        .info-table td { border: 1px solid #000; padding: 1.5mm 2.5mm; vertical-align: middle; }
        .info-table .label { font-weight: bold; white-space: nowrap; }
        .info-table .sep   { text-align: center; width: 8mm; }
        .info-table .val-r { text-align: center; }

        /* ── Page A4 ── */
        .exam-page {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            padding: 10mm 12mm 15mm 12mm;
            background: #fff;
            box-shadow: 0 4px 20px rgba(0,0,0,.2);
        }

        /* ── Questions ── */
        hr.section-sep { border: none; border-top: 2px solid #000; margin: 2mm 0 3mm; }
        .partie-titre { font-weight: bold; font-size: 10pt; background: #f0f0f0; padding: 3px 6px; margin: 10px 0 4px; border-left: 3px solid #000; }
        .question { margin: 6px 0; padding: 5px 8px; border: 1px solid #ccc; page-break-inside: avoid; }
        .q-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
        .q-num  { font-weight: bold; font-size: 10pt; flex-shrink: 0; }
        .q-text { flex-grow: 1; font-size: 10pt; }
        .q-pts  { font-size: 9pt; font-weight: bold; flex-shrink: 0; white-space: nowrap; }
        .q-answer { margin-top: 4px; font-size: 9.5pt; padding-left: 16px; }
        .lbl { color: #333; }
        .ans { font-weight: bold; }

        /* ── Score final ── */
        .score-box { margin-top: 10px; padding: 6px 10px; border: 2px solid #000; display: flex; justify-content: space-between; align-items: center; }
        .score-box .total { font-size: 13pt; font-weight: bold; }
        .score-box .mention { font-size: 11pt; font-weight: bold; padding: 2px 10px; border: 2px solid #000; }

        /* ── Tampon ── */
        .tampon-ofppt { position: fixed; bottom: 14mm; right: 14mm; width: 38mm; opacity: 0.88; pointer-events: none; z-index: 100; }

        /* ── Séparateur de pages ── */
        .page-break { page-break-after: always; break-after: page; }

        /* ── Barre d'outils ── */
        @page { size: A4; margin: 0; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .exam-page { margin: 0; padding: 10mm 12mm 15mm 12mm; box-shadow: none; width: 100%; }
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
    $nomPrenom  = s(strtoupper($session['nom']) . ' ' . $session['prenom']);
    $totalPts   = (float)$session['total_points'];
    $noteSur    = $totalPts > 0 ? round((float)$session['score'] / $totalPts * $noteMax, 2) : 0;
    $qNum = 0;
    preg_match('/^([A-Za-zÀ-ÿ]+)/u', $session['groupe_nom'] ?? '', $fm);
    $sessionFiliere = isset($fm[1]) ? strtoupper($fm[1]) : ($filiere ?: '');
?>

<div class="exam-page <?= $idx < count($sessions) - 1 ? 'page-break' : '' ?>">

    <!-- ══ EN-TÊTE OFPPT ══ -->
    <table class="efm-header">
        <colgroup>
            <col style="width:55%">
            <col style="width:45%">
        </colgroup>
        <tbody>
            <tr>
                <td class="h-logo">
                    <div class="h-logo-inner">
                        <?php if ($logoB64): ?>
                        <img src="<?= $logoB64 ?>" alt="OFPPT">
                        <?php endif; ?>
                        <div class="h-org">
                            <div>Direction Régionale Rabat – Salé – Kénitra</div>
                            <div>ISTA HAY RIAD RABAT</div>
                        </div>
                    </div>
                </td>
                <td rowspan="2" class="h-identity">
                    <div>Nom : <?= s(strtoupper($session['nom'])) ?></div>
                    <div>Prénom : <?= s($session['prenom']) ?></div>
                    <div>Groupe : <?= $groupe ?></div>
                    <?php if ($isEfm): ?>
                    <div>Etablissement : ISTA HAY RIAD RABAT</div>
                    <?php else: ?>
                    <div>Date : ………………………………………………………………</div>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <?php if ($isEfm): ?>
                <td class="h-efm">EXAMEN DE FIN DE MODULE</td>
                <?php else: ?>
                <td class="h-efm" style="font-style:normal;font-weight:bold">
                    Contrôle Continue N°&nbsp;<span style="display:inline-block;min-width:12mm;border-bottom:1px solid #000">&nbsp;</span>
                </td>
                <?php endif; ?>
            </tr>
            <tr>
                <td colspan="2" class="h-code">
                    <?php if ($codeModule): ?><div>Code module : <?= s($codeModule) ?></div><?php endif; ?>
                    <div>Intitulé du module : <?= s($module['nom']) ?></div>
                </td>
            </tr>
        </tbody>
    </table>

    <table class="info-table">
        <tr>
            <td class="label" style="width:13%">Filière</td>
            <td class="sep">:</td>
            <td style="width:47%"><?= $sessionFiliere ?: '………………………………………' ?></td>
            <td class="label" style="width:18%">Durée</td>
            <td class="val-r" style="width:17%">: <?= $dureeStr ?: '…… min' ?></td>
        </tr>
        <tr>
            <td class="label">Année</td>
            <td class="sep">:</td>
            <td><?= $annee ?></td>
            <td class="label">Note finale</td>
            <td class="val-r">: <?= number_format($noteSur, 2) ?> / <?= $noteMax ?></td>
        </tr>
    </table>

    <hr class="section-sep">

    <!-- Questions et réponses -->
    <?php if (empty($reponses)): ?>
        <p style="margin-top:12px;color:#888;font-style:italic">Aucune réponse enregistrée.</p>
    <?php else: ?>
        <?php foreach ($reponses as $r):
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
    <?php endif; ?>

</div>

<?php endforeach; ?>

<?php if ($tamponB64): ?>
<img src="<?= $tamponB64 ?>" class="tampon-ofppt" alt="">
<?php endif; ?>

<script>
if (new URLSearchParams(location.search).get('auto') === '1') window.print();
</script>
</body>
</html>
