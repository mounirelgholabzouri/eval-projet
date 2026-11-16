<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

$filterModule  = (int)($_GET['module_id'] ?? 0);
$filterGroupe  = trim($_GET['groupe'] ?? '');
$filterSession = (int)($_GET['session_id'] ?? 0); // impression d'un seul stagiaire
$filterEval    = (int)($_GET['evaluation_id'] ?? 0);

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
    if ($filterEval) { $where[] = 'e.id = ?'; $params[] = $filterEval; }
    if ($filterGroupe) {
        $where[]  = "(g.nom LIKE ? OR s.groupe_libre LIKE ?)";
        $params[] = "%$filterGroupe%";
        $params[] = "%$filterGroupe%";
    }
}
$stmtSes = $pdo->prepare("
    SELECT s.*, COALESCE(g.nom, s.groupe_libre) AS groupe_nom, st.numero_classe
    FROM sessions_eval s
    JOIN evaluations e ON e.id = s.evaluation_id
    LEFT JOIN groupes g ON g.id = s.groupe_id
    LEFT JOIN stagiaires st ON st.id = s.stagiaire_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY s.nom, s.prenom
");
$stmtSes->execute($params);
$sessions = $stmtSes->fetchAll();

if (empty($sessions)) {
    header('Location: results.php?module_id=' . $filterModule);
    exit;
}

// Réponses indexées par (session_id → question_id)
$sessionIds = array_column($sessions, 'id');
$repBySessionQ = [];
if ($sessionIds) {
    $in = implode(',', array_fill(0, count($sessionIds), '?'));
    $stmtRep = $pdo->prepare("
        SELECT rs.session_id, rs.question_id, rs.reponse_texte,
               COALESCE(rs.is_correct, 0)     AS is_correct,
               COALESCE(rs.points_obtenus, 0) AS points_obtenus,
               COALESCE(cr.texte, IF(q.type = 'multiple', rs.reponse_texte, NULL)) AS choix_etudiant
        FROM reponses_stagiaires rs
        JOIN questions q ON q.id = rs.question_id
        LEFT JOIN choix_reponses cr ON cr.id = rs.choix_id
        WHERE rs.session_id IN ($in)
    ");
    $stmtRep->execute($sessionIds);
    foreach ($stmtRep->fetchAll() as $row) {
        $repBySessionQ[$row['session_id']][$row['question_id']] = $row;
    }
}

// Questions concernées (tirages figés + questions répondues), indexées par id — y compris d'autres modules
$idsConcernes = [];
foreach ($sessions as $s) {
    foreach (json_decode($s['questions_ids'] ?? '', true) ?: [] as $qid) $idsConcernes[(int)$qid] = true;
}
foreach ($repBySessionQ as $reps) {
    foreach (array_keys($reps) as $qid) $idsConcernes[(int)$qid] = true;
}
$allModuleQuestions = [];
if ($idsConcernes) {
    $ids  = array_keys($idsConcernes);
    $stmtAllQ = $pdo->prepare("SELECT id, texte AS question_texte, type, points AS points_max, ordre FROM questions WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $stmtAllQ->execute($ids);
    $allModuleQuestions = array_column($stmtAllQ->fetchAll(), null, 'id');
}

// Choix par question (corrigé QCM/V-F/multiple + étiquettes glisser-déposer)
$choixByQ = [];
if ($idsConcernes) {
    $ids = array_keys($idsConcernes);
    $stmtCh = $pdo->prepare("SELECT id, question_id, texte, is_correct, ordre FROM choix_reponses WHERE question_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY ordre, id");
    $stmtCh->execute($ids);
    foreach ($stmtCh->fetchAll() as $c) $choixByQ[(int)$c['question_id']][] = $c;
}

/** Gabarit glisser-déposer rempli : étiquette juste en gras, fausse barrée + attendue entre crochets. */
function renderGlisserPrint(string $gabarit, array $choix, ?string $reponseJson): string {
    $byId = []; $attendu = [];
    foreach ($choix as $c) {
        $byId[(int)$c['id']] = $c['texte'];
        if ($c['is_correct']) $attendu[(int)$c['ordre']] = (int)$c['id'];
    }
    $donne = $reponseJson ? (json_decode($reponseJson, true) ?: []) : [];
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    return preg_replace_callback('/\{\{(\d+)\}\}|[^{]+|\{/', function ($m) use ($byId, $attendu, $donne, $e) {
        if (!isset($m[1]) || $m[1] === '') return $e($m[0]);
        $n = (int)$m[1];
        $d = (int)($donne[$n] ?? 0);
        $bon = $attendu[$n] ?? 0;
        if ($d && $d === $bon) return '<span class="dd-ok">' . $e($byId[$d]) . ' ✓</span>';
        $txt = $d ? '<s>' . $e($byId[$d] ?? '?') . '</s>' : '____';
        return '<span class="dd-ko">' . $txt . '</span> <span class="dd-exp">[' . $e($byId[$bon] ?? '?') . ']</span>';
    }, $gabarit);
}

// questions_ids figés par session
$qIdsBySession = [];
foreach ($sessions as $s) {
    if ($s['questions_ids']) {
        $qIdsBySession[$s['id']] = json_decode($s['questions_ids'], true) ?: null;
    }
}

function s($str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

$evalInfo = getEvaluation((int)$sessions[0]['evaluation_id']);
$annee    = $evalInfo['efm_annee'] ?: getAnneeFormation();
$isEfm    = $evalInfo['type'] === 'efm';
$noteMax  = (int)$evalInfo['note_max'];
$filiere  = $evalInfo['efm_filiere'];
$codeModule = $evalInfo['efm_code_module'];
$etabl    = $evalInfo['efm_etablissement'] ?: 'ISTA HAY RIAD RABAT';
$ccNum    = $isEfm ? 0 : (int)($_GET['cc_num'] ?? $evalInfo['numero']);
$retourUrl = $filterSession ? 'detail.php?id=' . $filterSession : 'results.php?module_id=' . $filterModule;
$dureeMin = (int)$evalInfo['duree_minutes'];
$dureeStr = $dureeMin ? $dureeMin . ' min' : '';

$logoPath = __DIR__ . '/../assets/img/logo_efm.png';
$logoB64  = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : '';


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
            position: relative;
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            padding: 10mm 12mm 15mm 12mm;
            background: #fff;
            box-shadow: 0 4px 20px rgba(0,0,0,.2);
        }
        .num-classe {
            position: absolute;
            bottom: 4mm;
            left: 6mm;
            font-size: 7pt;
            color: #bbb;
        }

        /* ── Questions ── */
        hr.section-sep { border: none; border-top: 2px solid #000; margin: 2mm 0 3mm; }
        .question { margin: 6px 0; padding: 5px 8px; border: 1px solid #ccc; page-break-inside: avoid; }
        .q-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
        .q-num  { font-weight: bold; font-size: 10pt; flex-shrink: 0; }
        .q-text { flex-grow: 1; font-size: 10pt; }
        .q-pts  { font-size: 9pt; font-weight: bold; flex-shrink: 0; white-space: nowrap; }
        .q-answer { margin-top: 4px; font-size: 9.5pt; padding-left: 16px; }
        .lbl { color: #333; }
        .ans { font-weight: bold; }
        .q-mark { font-weight: bold; font-size: 10pt; flex-shrink: 0; width: 5mm; text-align: center; }
        .q-mark.ok { color: #198754; }
        .q-mark.ko { color: #c0392b; }
        .q-mark.man { color: #b8860b; }
        .q-expected { margin-top: 2px; font-size: 9pt; padding-left: 16px; color: #198754; }
        .q-gabarit { margin-top: 4px; font-family: Consolas, 'Courier New', monospace; font-size: 9pt; white-space: pre-wrap; line-height: 1.7; padding: 3px 6px; background: #f5f5f5; border: 1px solid #ddd; }
        .dd-ok  { font-weight: bold; color: #198754; }
        .dd-ko  { font-weight: bold; color: #c0392b; }
        .dd-exp { color: #198754; }

        /* ── Score final ── */
        .score-box { margin-top: 10px; padding: 6px 10px; border: 2px solid #000; display: flex; justify-content: space-between; align-items: center; }
        .score-box .total { font-size: 13pt; font-weight: bold; }
        .score-box .mention { font-size: 11pt; font-weight: bold; padding: 2px 10px; border: 2px solid #000; }

        /* ── Tampon ── */


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
    <a href="<?= s($retourUrl) ?>">← Retour</a>
    <button onclick="window.print()">🖨 Imprimer / PDF</button>
</div>

<?php foreach ($sessions as $idx => $session):
    $repByQ      = $repBySessionQ[$session['id']] ?? [];
    $sessionQIds = $qIdsBySession[$session['id']] ?? null;

    if ($sessionQIds) {
        // Tirage figé → toutes les questions du tirage (répondues + non répondues)
        $questions = [];
        foreach ($sessionQIds as $qid) {
            if (isset($allModuleQuestions[$qid])) $questions[] = $allModuleQuestions[$qid];
        }
        usort($questions, fn($a, $b) => $a['ordre'] <=> $b['ordre']);
    } else {
        // Tirage non stocké (ancienne session) → seulement les questions répondues
        $questions = array_map(
            fn($rep) => isset($allModuleQuestions[$rep['question_id']]) ? $allModuleQuestions[$rep['question_id']] : null,
            $repByQ
        );
        $questions = array_values(array_filter($questions));
        usort($questions, fn($a, $b) => $a['ordre'] <=> $b['ordre']);
    }
    $mention    = getMention((float)$session['pourcentage']);
    $groupe     = s($session['groupe_nom'] ?: '—');
    $nomPrenom  = s(strtoupper($session['nom']) . ' ' . $session['prenom']);
    $totalPts   = (float)$session['total_points'];
    $noteSur    = arrondiNote((float)$session['score'], $totalPts, $noteMax);
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
                            <div><?= s($etabl) ?></div>
                        </div>
                    </div>
                </td>
                <td rowspan="2" class="h-identity">
                    <div>Nom : <?= s(mb_strtoupper($session['nom'], 'UTF-8')) ?></div>
                    <div>Prénom : <?= s($session['prenom']) ?></div>
                    <div>Groupe : <?= $groupe ?></div>
                    <?php if ($isEfm): ?>
                    <div>Etablissement : <?= s($etabl) ?></div>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <?php if ($isEfm): ?>
                <td class="h-efm">EXAMEN DE FIN DE MODULE</td>
                <?php else: ?>
                <td class="h-efm" style="font-style:normal;font-weight:bold">
                    Contrôle Continu N°&nbsp;<span style="display:inline-block;min-width:12mm;border-bottom:1px solid #000"><?= $ccNum ?: '&nbsp;' ?></span>
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
    <?php if (empty($questions)): ?>
        <p style="margin-top:12px;color:#888;font-style:italic">Aucune question trouvée pour ce module.</p>
    <?php else: ?>
        <?php foreach ($questions as $q):
            $qNum++;
            $rep      = $repByQ[$q['id']] ?? null;
            $isTexte  = ($q['type'] === 'texte_libre');
            $isDD     = ($q['type'] === 'glisser_deposer');
            $choixQ   = $choixByQ[(int)$q['id']] ?? [];
            $qNote    = number_format((float)($rep['points_obtenus'] ?? 0), 2);
            $qMax     = number_format((float)$q['points_max'], 2);
            $enonce   = $isDD ? splitGlisserDeposer($q['question_texte'])[0] : $q['question_texte'];
            $ok       = $rep && (int)$rep['is_correct'] === 1;
            $mark     = $isTexte ? ['man', '✎'] : ($ok ? ['ok', '✓'] : ['ko', '✗']);
            $bonnes   = $isDD ? [] : array_column(array_filter($choixQ, fn($c) => (int)$c['is_correct'] === 1), 'texte');
        ?>
        <div class="question">
            <div class="q-header">
                <span class="q-mark <?= $mark[0] ?>"><?= $mark[1] ?></span>
                <span class="q-num">Q<?= $qNum ?>.</span>
                <span class="q-text"><?= s($enonce) ?></span>
                <span class="q-pts"><strong><?= $qNote ?></strong> / <?= $qMax ?></span>
            </div>
            <?php if ($isDD): ?>
            <div class="q-gabarit"><?= renderGlisserPrint(splitGlisserDeposer($q['question_texte'])[1], $choixQ, $rep['reponse_texte'] ?? null) ?></div>
            <?php else: ?>
            <div class="q-answer">
                <span class="lbl">Réponse&nbsp;:</span>
                <span class="ans">
                    <?php if ($isTexte): ?>
                        <?= ($rep && $rep['reponse_texte']) ? s($rep['reponse_texte']) : '<em style="color:#aaa">Sans réponse</em>' ?>
                    <?php else: ?>
                        <?= ($rep && $rep['choix_etudiant']) ? s($rep['choix_etudiant']) : '<em style="color:#aaa">Sans réponse</em>' ?>
                    <?php endif; ?>
                </span>
            </div>
            <?php if (!$isTexte && !$ok && $bonnes): ?>
            <div class="q-expected">Bonne réponse&nbsp;: <?= s(implode(' ; ', $bonnes)) ?></div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <div class="score-box">
            <span>Total : <strong><?= number_format((float)$session['score'], 2) ?></strong> / <?= number_format($totalPts, 2) ?> pts
                (<?= number_format((float)$session['pourcentage'], 0) ?>&nbsp;%)</span>
            <span class="total">Note : <?= number_format($noteSur, 2) ?> / <?= $noteMax ?></span>
            <span class="mention"><?= s($mention['label']) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($session['numero_classe']): ?>
    <span class="num-classe"><?= (int)$session['numero_classe'] ?></span>
    <?php endif; ?>

</div>

<?php endforeach; ?>


<script>
if (new URLSearchParams(location.search).get('auto') === '1') window.print();
</script>
</body>
</html>
