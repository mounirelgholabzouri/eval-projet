<?php
/**
 * Impression du résultat d'une session EFM au format officiel MODELE EFM.docx
 * Paramètre GET : session_id
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$sessionId = (int)($_GET['session_id'] ?? 0);
if (!$sessionId) { header('Location: results.php'); exit; }

$pdo     = getDB();
$session = getSession($sessionId);
if (!$session) { header('Location: results.php'); exit; }

$module  = getModule((int)$session['module_id']);
if (!$module) { header('Location: results.php'); exit; }

// Métadonnées EFM stockées en meta_json
$meta = [];
if (!empty($module['meta_json'])) {
    $meta = json_decode($module['meta_json'], true) ?? [];
}
$codeModule    = $meta['code_module']    ?? '';
$filiere       = $meta['filiere']        ?? '';
$etablissement = $meta['etablissement']  ?? '';
$annee         = $meta['annee']          ?? date('y') . '/' . (date('y') + 1);
$noteMax       = (int)($module['note_max'] ?? 20);
$duree         = $module['duree_minutes'] ? formatDuration((int)$module['duree_minutes']) : '';
$intitule      = $codeModule ? $module['nom'] : $module['nom'];

// Nom / prénom / groupe du stagiaire
$nom    = $session['nom']    ?? '';
$prenom = $session['prenom'] ?? '';
$groupe = $session['groupe_nom'] ?? $session['groupe_libre'] ?? '';

// Réponses avec question + partie + tous les choix
$stmt = $pdo->prepare("
    SELECT rs.*,
           q.texte  AS question_texte,
           q.type,
           q.points AS points_max,
           q.partie_id,
           p.nom    AS partie_nom,
           p.ordre  AS partie_ordre,
           cr.texte AS choix_texte
    FROM reponses_stagiaires rs
    JOIN questions           q  ON q.id  = rs.question_id
    LEFT JOIN parties        p  ON p.id  = q.partie_id
    LEFT JOIN choix_reponses cr ON cr.id = rs.choix_id
    WHERE rs.session_id = ?
    ORDER BY p.ordre, p.id, q.ordre, q.id
");
$stmt->execute([$sessionId]);
$reponses = $stmt->fetchAll();

// Charger tous les choix pour chaque question (pour afficher A/B/C/D)
$choixParQuestion = [];
if (!empty($reponses)) {
    $qids = array_unique(array_column($reponses, 'question_id'));
    $in   = implode(',', array_fill(0, count($qids), '?'));
    $cStmt = $pdo->prepare("SELECT * FROM choix_reponses WHERE question_id IN ($in) ORDER BY ordre, id");
    $cStmt->execute($qids);
    foreach ($cStmt->fetchAll() as $c) {
        $choixParQuestion[(int)$c['question_id']][] = $c;
    }
}

// Regrouper par partie
$sections = [];
foreach ($reponses as $r) {
    $pid = (int)($r['partie_id'] ?? 0);
    if (!isset($sections[$pid])) {
        $sections[$pid] = [
            'partie_nom'   => $r['partie_nom'] ?? 'Général',
            'partie_ordre' => (int)($r['partie_ordre'] ?? 0),
            'questions'    => [],
        ];
    }
    $r['choix_list'] = $choixParQuestion[(int)$r['question_id']] ?? [];
    $sections[$pid]['questions'][] = $r;
}
// Trier les sections par ordre de partie
uasort($sections, fn($a, $b) => $a['partie_ordre'] <=> $b['partie_ordre']);

// Score / note
$score      = (float)$session['score'];
$totalPts   = (float)$session['total_points'];
$noteFinale = $totalPts > 0 ? round($score / $totalPts * $noteMax, 2) : 0;
$mention    = getMention((float)$session['pourcentage']);

$lettres = ['A','B','C','D','E','F','G','H'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>EFM — <?= htmlspecialchars("$prenom $nom") ?></title>
    <style>
        /* ── Base ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Calibri, Arial, sans-serif;
            font-size: 11pt;
            background: #e0e0e0;
            color: #000;
        }

        /* ── Contrôles écran ── */
        .print-controls {
            position: fixed; top: 10px; right: 10px; z-index: 9999;
            display: flex; gap: 8px;
        }
        .print-controls a,
        .print-controls button {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 7px 14px; border-radius: 6px; border: none;
            font-size: 13px; font-weight: 600; cursor: pointer;
            text-decoration: none;
        }
        .btn-print  { background:#dc3545; color:#fff; }
        .btn-back   { background:#6c757d; color:#fff; }

        /* ── Page A4 ── */
        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            padding: 10mm 13mm 15mm 13mm;
            background: #fff;
            box-shadow: 0 4px 24px rgba(0,0,0,.22);
            position: relative;
        }

        /* ═══════════════════════════════════════════════
           EN-TÊTE — layout identique au MODELE EFM.docx
           Zone gauche + titre centré + box identité flottante
           ═══════════════════════════════════════════════ */
        .efm-header {
            position: relative;
            min-height: 38mm;
            margin-bottom: 0;
        }

        /* Zone gauche : Direction Régionale */
        .header-left {
            position: absolute;
            left: 0; top: 0;
            width: 52%;
        }
        .direction-label {
            font-size: 9pt;
            font-style: italic;
            line-height: 1.5;
            margin-bottom: 3mm;
        }
        .efm-title-block {
            margin-top: 6mm;
            text-align: center;
        }
        .efm-title-block .efm-title {
            font-size: 15pt;
            font-weight: bold;
            letter-spacing: 0.3px;
            line-height: 1.3;
        }

        /* BOX IDENTITÉ STAGIAIRE — positionné en haut à droite,
           correspond à la zone texte flottante du MODELE EFM.docx */
        .identite-box {
            position: absolute;
            right: 0;
            top: 0;
            width: 84mm;          /* ≈ 240pt */
            border: 1.5px solid #000;
            padding: 2.5mm 3mm;
            background: #fff;
        }
        .identite-line {
            font-size: 9.5pt;
            font-weight: bold;
            margin-bottom: 2.2mm;
            white-space: nowrap;
        }
        .identite-line:last-child { margin-bottom: 0; }
        .id-val {
            font-weight: normal;
            border-bottom: 1px solid #555;
            display: inline-block;
            min-width: 42mm;
            padding-bottom: 0.5mm;
        }

        /* ── Séparateur pleine largeur ── */
        hr.sep {
            border: none;
            border-top: 2px solid #000;
            margin: 2.5mm 0;
            clear: both;
        }

        /* ── Tableau infos module ── */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10pt;
            margin-bottom: 0;
        }
        .info-table td {
            border: 1px solid #000;
            padding: 1.8mm 2.5mm;
            vertical-align: middle;
        }
        .info-table .lbl { font-weight: bold; white-space: nowrap; }
        .info-table .note-cell {
            text-align: center;
            font-size: 12pt;
            font-weight: bold;
        }

        /* ── Ligne code module + intitulé ── */
        .module-line {
            text-align: center;
            font-size: 10.5pt;
            padding: 1.5mm 0;
        }
        .module-line strong { margin-right: 2mm; }

        /* ═══════════════════════════════
           PARTIE (section de questions)
           ═══════════════════════════════ */
        .partie-header {
            background: #000;
            color: #fff;
            font-weight: bold;
            font-size: 10.5pt;
            padding: 1.5mm 3mm;
            margin: 3.5mm 0 2mm;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .partie-score { font-size: 9.5pt; font-weight: normal; }

        /* ═══════════════════════════════
           QUESTION
           ═══════════════════════════════ */
        .question-block {
            margin-bottom: 3mm;
            page-break-inside: avoid;
        }
        .question-header {
            display: flex;
            align-items: baseline;
            gap: 3mm;
            margin-bottom: 1.5mm;
        }
        .q-num   { font-weight: bold; font-size: 10.5pt; min-width: 10mm; white-space: nowrap; }
        .q-texte { flex: 1; font-size: 10.5pt; line-height: 1.5; }
        .q-pts   { white-space: nowrap; font-size: 9pt; color: #444; min-width: 22mm; text-align: right; }

        /* ── Résultat question (icône correct/incorrect) ── */
        .q-result {
            display: inline-block;
            width: 4.5mm; height: 4.5mm;
            border-radius: 50%;
            vertical-align: middle;
            margin-left: 2mm;
            font-size: 8pt;
            line-height: 4.5mm;
            text-align: center;
            font-weight: bold;
            flex-shrink: 0;
        }
        .q-correct   { background: #198754; color: #fff; }
        .q-incorrect { background: #dc3545; color: #fff; }
        .q-libre     { background: #fd7e14; color: #fff; }

        /* ── Choix QCM ── */
        .choix-list {
            list-style: none;
            margin: 0 0 0 10mm;
            padding: 0;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5mm 4mm;
        }
        .choix-item {
            display: flex; align-items: flex-start; gap: 2mm;
            font-size: 10pt; line-height: 1.4; padding: 0.5mm 0;
        }
        .choix-circle {
            width: 4mm; height: 4mm;
            border: 1.2px solid #000;
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 1mm;
        }
        /* Bonne réponse = cercle vert */
        .choix-correct .choix-circle  { background: #198754; border-color: #198754; }
        .choix-correct                { color: #198754; font-weight: bold; }
        /* Mauvaise réponse choisie = cercle rouge */
        .choix-wrong-chosen .choix-circle { background: #dc3545; border-color: #dc3545; }
        .choix-wrong-chosen               { color: #dc3545; }
        /* Réponse choisie correcte = cercle vert + underline */
        .choix-chosen-correct .choix-circle { background: #198754; border-color: #198754; }
        .choix-chosen-correct               { color: #198754; font-weight: bold; text-decoration: underline; }

        /* ── Texte libre ── */
        .reponse-libre-box {
            margin: 1.5mm 0 0 10mm;
            font-size: 10pt;
        }
        .reponse-libre-val {
            border-bottom: 1px solid #555;
            padding-bottom: 1mm;
            min-height: 5mm;
            color: #333;
            font-style: italic;
        }
        .reponse-libre-val.vide { color: #999; }

        /* ── Pied de page note finale ── */
        .footer-efm {
            margin-top: 6mm;
            border-top: 2px solid #000;
            padding-top: 3mm;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 10pt;
        }
        .footer-note {
            font-size: 13pt;
            font-weight: bold;
        }
        .footer-mention {
            font-size: 11pt;
        }

        /* ── Impression ── */
        @media print {
            body { background: #fff; }
            .print-controls { display: none !important; }
            .page { margin: 0; box-shadow: none; width: 100%; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>

<!-- Boutons écran -->
<div class="print-controls">
    <a href="detail.php?id=<?= $sessionId ?>" class="btn-back">&#8592; Retour</a>
    <button class="btn-print" onclick="window.print()">&#128438; Imprimer</button>
</div>

<div class="page">

    <!-- ══════════════════════════════════════════════════
         EN-TÊTE
         ══════════════════════════════════════════════════ -->
    <div class="efm-header">

        <!-- Zone gauche : Direction + titre -->
        <div class="header-left">
            <div class="direction-label">
                Direction Régionale<br>
                <strong>RABAT-SALÉ-KÉNITRA</strong>
                <?php if ($etablissement): ?>
                <br><br><?= htmlspecialchars($etablissement, ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </div>
            <div class="efm-title-block">
                <div class="efm-title">Évaluation de Fin de Module</div>
            </div>
        </div>

        <!-- Box identité stagiaire (zone flottante droite — position exacte du MODELE) -->
        <div class="identite-box">
            <div class="identite-line">
                Nom :&nbsp;<span class="id-val"><?= htmlspecialchars($nom, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="identite-line">
                Prénom :&nbsp;<span class="id-val"><?= htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="identite-line">
                Groupe :&nbsp;<span class="id-val"><?= htmlspecialchars($groupe, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="identite-line">
                Établissement :&nbsp;<span class="id-val"><?= htmlspecialchars($etablissement, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>

    </div><!-- /efm-header -->

    <hr class="sep">

    <!-- ── Code module + Intitulé ── -->
    <?php if ($codeModule || $intitule): ?>
    <div class="module-line">
        <?php if ($codeModule): ?><strong>Code module :</strong> <?= htmlspecialchars($codeModule, ENT_QUOTES, 'UTF-8') ?>&nbsp;&nbsp;<?php endif; ?>
        <?php if ($intitule): ?><strong>Intitulé du module :</strong> <?= htmlspecialchars($intitule, ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Tableau infos module ── -->
    <table class="info-table">
        <tr>
            <td class="lbl" style="width:55px">Filière</td>
            <td style="width:30px; text-align:center">:</td>
            <td><?= htmlspecialchars($filiere, ENT_QUOTES, 'UTF-8') ?></td>
            <td class="lbl" style="width:55px">Durée</td>
            <td class="note-cell" style="width:65px">: <?= htmlspecialchars($duree, ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
            <td class="lbl">Année</td>
            <td style="text-align:center">:</td>
            <td><?= htmlspecialchars($annee, ENT_QUOTES, 'UTF-8') ?></td>
            <td class="lbl">Note finale</td>
            <td class="note-cell">: <?= number_format($noteFinale, 1) ?> / <?= $noteMax ?></td>
        </tr>
    </table>

    <hr class="sep" style="margin-top:2.5mm">

    <!-- ══════════════════════════════════════════════════
         QUESTIONS PAR PARTIE
         ══════════════════════════════════════════════════ -->
    <?php
    $qNum = 1;
    foreach ($sections as $pid => $section):
        $partieScore = array_sum(array_column($section['questions'], 'points_obtenus'));
        $partiePts   = array_sum(array_column($section['questions'], 'points_max'));
        $nbQ = count($section['questions']);
        if ($nbQ === 0) continue;
    ?>
    <div class="partie-header">
        <span><?= htmlspecialchars($section['partie_nom'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="partie-score">
            <?= $nbQ ?> question<?= $nbQ > 1 ? 's' : '' ?> —
            <?= number_format($partieScore, 1) ?> / <?= number_format($partiePts, 1) ?> pt<?= $partiePts > 1 ? 's' : '' ?>
        </span>
    </div>

    <?php foreach ($section['questions'] as $r):
        $isTexteLibre = ($r['type'] === 'texte_libre');
        $isCorrect    = !$isTexteLibre && (bool)$r['is_correct'];
        $isLibre      = $isTexteLibre;
    ?>
    <div class="question-block">
        <div class="question-header">
            <!-- Icône résultat -->
            <?php if ($isLibre): ?>
                <span class="q-result q-libre" title="Texte libre">?</span>
            <?php elseif ($isCorrect): ?>
                <span class="q-result q-correct" title="Correct">✓</span>
            <?php else: ?>
                <span class="q-result q-incorrect" title="Incorrect">✗</span>
            <?php endif; ?>
            <span class="q-num">Q<?= $qNum++ ?>.</span>
            <span class="q-texte"><?= nl2br(htmlspecialchars($r['question_texte'] ?? '', ENT_QUOTES, 'UTF-8')) ?></span>
            <span class="q-pts">
                <?= number_format($r['points_obtenus'], 1) ?> / <?= number_format($r['points_max'], 1) ?> pt<?= $r['points_max'] > 1 ? 's' : '' ?>
            </span>
        </div>

        <?php if (!$isTexteLibre && !empty($r['choix_list'])): ?>
        <!-- Choix QCM : afficher tous les choix avec marquage -->
        <ul class="choix-list">
            <?php foreach ($r['choix_list'] as $i => $c):
                $isChoixCorrect = (bool)$c['is_correct'];
                $isChoosen      = ((int)$c['id'] === (int)$r['choix_id']);
                // Classes CSS
                if ($isChoixCorrect && $isChoosen)   $cls = 'choix-chosen-correct';
                elseif ($isChoixCorrect)              $cls = 'choix-correct';
                elseif ($isChoosen && !$isChoixCorrect) $cls = 'choix-wrong-chosen';
                else                                  $cls = '';
            ?>
            <li class="choix-item <?= $cls ?>">
                <span class="choix-circle"></span>
                <span><strong><?= $lettres[$i] ?? chr(65+$i) ?>)</strong> <?= htmlspecialchars($c['texte'], ENT_QUOTES, 'UTF-8') ?></span>
            </li>
            <?php endforeach; ?>
        </ul>

        <?php elseif ($isTexteLibre): ?>
        <div class="reponse-libre-box">
            <?php if ($r['reponse_texte']): ?>
                <div class="reponse-libre-val"><?= htmlspecialchars($r['reponse_texte'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php else: ?>
                <div class="reponse-libre-val vide">(sans réponse)</div>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- QCM sans détail choix disponible -->
        <div style="margin-left:10mm; font-size:10pt; color:#666;">
            Réponse : <?= htmlspecialchars($r['choix_texte'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

    </div>
    <?php endforeach; ?>
    <?php endforeach; ?>

    <!-- ── Pied de page : note finale ── -->
    <div class="footer-efm">
        <div>
            <?= $codeModule ? "Module $codeModule" : '' ?>
            <?= $codeModule && $annee ? ' — ' : '' ?>
            <?= $annee ? "Année $annee" : '' ?>
        </div>
        <div class="footer-note">
            Note finale : <?= number_format($noteFinale, 1) ?> / <?= $noteMax ?>
        </div>
        <div class="footer-mention">
            <?= htmlspecialchars($mention['label'], ENT_QUOTES, 'UTF-8') ?>
            (<?= number_format((float)$session['pourcentage'], 1) ?>%)
        </div>
    </div>

</div><!-- /page -->
</body>
</html>
