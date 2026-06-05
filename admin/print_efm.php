<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

// ── Paramètres ────────────────────────────────────────────────
$moduleId     = (int)($_GET['module_id'] ?? 0);
$shuffle      = (bool)(int)($_GET['shuffle'] ?? 1);
$shuffleChoix = (bool)(int)($_GET['shuffle_choix'] ?? 1);
$corrige      = (bool)(int)($_GET['corrige'] ?? 0);
$noteMax      = (int)($_GET['note_max'] ?? 20);
$codeModule   = htmlspecialchars(trim($_GET['code_module'] ?? ''), ENT_QUOTES, 'UTF-8');
$intitule     = htmlspecialchars(trim($_GET['intitule'] ?? ''), ENT_QUOTES, 'UTF-8');
$etablissement= htmlspecialchars(trim($_GET['etablissement'] ?? ''), ENT_QUOTES, 'UTF-8');
$filiere      = htmlspecialchars(trim($_GET['filiere'] ?? ''), ENT_QUOTES, 'UTF-8');
$duree        = htmlspecialchars(trim($_GET['duree'] ?? ''), ENT_QUOTES, 'UTF-8');
$annee        = htmlspecialchars(trim($_GET['annee'] ?? ''), ENT_QUOTES, 'UTF-8');

$module = $moduleId ? getModule($moduleId) : null;
if (!$module) {
    echo '<p style="color:red;padding:20px">Module invalide. <a href="efm.php">Retour</a></p>'; exit;
}

// ── Questions + choix ─────────────────────────────────────────
$qStmt = $pdo->prepare("SELECT * FROM questions WHERE module_id = ? ORDER BY ordre, id");
$qStmt->execute([$moduleId]);
$questions = $qStmt->fetchAll();
if ($shuffle) shuffle($questions);

$totalPoints = 0;
foreach ($questions as &$q) {
    $cStmt = $pdo->prepare("SELECT * FROM choix_reponses WHERE question_id = ? ORDER BY ordre, id");
    $cStmt->execute([$q['id']]);
    $choix = $cStmt->fetchAll();
    if ($shuffleChoix && !empty($choix)) shuffle($choix);
    $q['choix'] = $choix;
    $totalPoints += (float)$q['points'];
}
unset($q);

// ── Normalisation vers note_max ────────────────────────────────
if ($noteMax > 0 && $totalPoints > 0.01 && abs($totalPoints - $noteMax) > 0.01) {
    $scale = $noteMax / $totalPoints;
    $lastIdx = count($questions) - 1;
    $runningPts = 0;
    foreach ($questions as $i => &$q) {
        if ($i < $lastIdx) {
            $q['points'] = round((float)$q['points'] * $scale * 2) / 2; // step=0.5
            $runningPts += $q['points'];
        } else {
            $q['points'] = round(($noteMax - $runningPts) * 2) / 2; // step=0.5
        }
    }
    unset($q);
    $totalPoints = $noteMax;
}

// ── Images base64 ─────────────────────────────────────────────
$logoPath   = __DIR__ . '/../assets/img/logo_efm.png';
$logoB64    = file_exists($logoPath)   ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))   : '';
$tamponPath = __DIR__ . '/../assets/img/tampon_ofppt.png';
$tamponB64  = file_exists($tamponPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($tamponPath)) : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>EFM — <?= $codeModule ? $codeModule . ' — ' : '' ?><?= $intitule ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Calibri, Arial, sans-serif; font-size: 11pt; background: #e0e0e0; color: #000; }

        .print-controls {
            position: fixed; top: 10px; right: 10px; z-index: 9999;
            display: flex; gap: 8px;
        }
        .print-controls a, .print-controls button {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 7px 14px; border-radius: 6px; border: none;
            font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none;
        }
        .btn-print   { background: #dc3545; color: #fff; }
        .btn-corrige { background: #198754; color: #fff; }
        .btn-back    { background: #6c757d; color: #fff; }

        .page {
            position: relative; width: 210mm; min-height: 297mm;
            margin: 20px auto; padding: 10mm 13mm 15mm;
            background: #fff; box-shadow: 0 4px 24px rgba(0,0,0,.22);
        }

        /* ── En-tête ── */
        .efm-header { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        .efm-header td { vertical-align: top; }
        .h-logo { width: 55%; border: 1px solid #000; padding: 4px 8px; vertical-align: middle; }
        .efm-logo { height: 38px; }
        .h-efm {
            border: 1px solid #000; border-top: none;
            text-align: center; padding: 6px 8px;
            font-size: 13pt; font-style: italic; vertical-align: middle;
        }
        .h-identity { width: 45%; border: 1px solid #000; padding: 5px 8px; vertical-align: top; font-size: 10.5pt; }
        .h-identity .id-line { margin-bottom: 5px; font-weight: bold; }
        .h-identity .id-line:last-child { margin-bottom: 0; }
        .h-code {
            border: 1px solid #000; border-top: none;
            text-align: center; padding: 4px 8px;
            font-size: 10.5pt; line-height: 1.7;
        }
        .badge-corrige {
            background: #198754; color: #fff;
            padding: 1mm 3mm; font-size: 8pt; font-weight: bold;
            border-radius: 3px; margin-left: 6px;
        }

        /* ── Tableau info ── */
        .info-table { width: 100%; border-collapse: collapse; margin-top: -1px; margin-bottom: 8px; }
        .info-table td { border: 1px solid #000; padding: 3px 7px; font-size: 10.5pt; vertical-align: middle; }
        .info-table .lbl  { font-weight: bold; white-space: nowrap; }
        .info-table .sep  { text-align: center; width: 14px; }
        .info-table .note { font-weight: bold; }

        /* ── Tableau questions (même structure que print_efm_result) ── */
        .questions-table { width: 100%; border-collapse: collapse; font-size: 10.5pt; }
        .questions-table tbody td { border: 1px solid #aaa; padding: 5px 8px; vertical-align: top; }
        .questions-table tbody tr:nth-child(even) td { background: #f9f9f9; }

        .col-note {
            width: 58px; text-align: center; font-weight: bold;
            white-space: nowrap; vertical-align: middle !important;
        }
        .col-note .pts-max { font-weight: normal; font-size: 9pt; color: #555; }

        .q-texte { font-weight: normal; margin-bottom: 4px; }

        /* ── Choix QCM ── */
        .choix-list {
            list-style: none;
            margin: 1mm 0 0 6mm;
            padding: 0;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5mm 5mm;
        }
        .choix-item {
            display: flex;
            align-items: baseline;
            gap: 1.5mm;
            font-size: 10pt;
            line-height: 1.4;
            padding: 0.5mm 0;
            flex: 0 0 calc(50% - 5mm);
            min-width: 0;
        }
        .choix-list.cols-4 .choix-item { flex: 0 0 calc(25% - 5mm); }
        .choix-list.cols-1 .choix-item { flex: 0 0 100%; }
        .choix-lettre { font-weight: bold; min-width: 5mm; flex-shrink: 0; }
        .choix-circle {
            width: 3.5mm; height: 3.5mm;
            border: 1px solid #000; border-radius: 50%;
            flex-shrink: 0; margin-top: 0.8mm; display: inline-block;
        }
        /* Corrigé */
        .correct-choice { font-weight: bold; color: #1a7a2e; }
        .correct-mark   { color: #1a7a2e; }

        /* Tampon */
        .tampon-ofppt {
            position: fixed; bottom: 14mm; right: 14mm;
            width: 38mm; opacity: 0.88; pointer-events: none; z-index: 100;
        }

        @page { size: A4; margin: 0; }
        @media print {
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            body { background: #fff; }
            .print-controls { display: none !important; }
            .page { margin: 0; box-shadow: none; width: 100%; padding: 7mm 10mm 12mm; }
        }
    </style>
</head>
<body>

<!-- ── Boutons (écran seulement) ── -->
<div class="print-controls">
    <a href="efm.php?module_id=<?= $moduleId ?>" class="btn-back">&#8592; Retour</a>
    <?php
    $corrigeParams = $_GET;
    $corrigeParams['corrige'] = $corrige ? 0 : 1;
    ?>
    <a href="print_efm.php?<?= http_build_query($corrigeParams) ?>" class="btn-corrige">
        <?= $corrige ? '&#128065; Masquer le corrigé' : '&#10003; Afficher le corrigé' ?>
    </a>
    <button class="btn-print" onclick="window.print()">&#128438; Imprimer</button>
</div>

<div class="page">

    <!-- EN-TÊTE -->
    <table class="efm-header">
        <colgroup>
            <col style="width:55%">
            <col style="width:45%">
        </colgroup>
        <tbody>
            <tr>
                <td class="h-logo">
                    <table style="width:100%;border-collapse:collapse"><tr>
                        <?php if ($logoB64): ?>
                        <td style="width:52px;vertical-align:middle;padding-right:8px">
                            <img src="<?= $logoB64 ?>" class="efm-logo" alt="OFPPT">
                        </td>
                        <?php endif; ?>
                        <td style="vertical-align:middle;text-align:center">
                            <div style="font-weight:bold;font-size:10pt;line-height:1.5">Direction Régionale</div>
                            <div style="font-weight:bold;font-size:10pt;line-height:1.5">ISTA HAY RIAD RABAT</div>
                        </td>
                    </tr></table>
                </td>
                <td rowspan="2" class="h-identity">
                    <div class="id-line">Nom : ……………………………………………………</div>
                    <div class="id-line">Prénom : …………………………………………………</div>
                    <div class="id-line">Groupe : …………………………………………………</div>
                    <div class="id-line">Etablissement : ………………………………………</div>
                </td>
            </tr>
            <tr>
                <td class="h-efm">
                    EXAMEN DE FIN DE MODULE
                    <?php if ($corrige): ?><span class="badge-corrige">CORRIGÉ</span><?php endif; ?>
                </td>
            </tr>
            <tr>
                <td colspan="2" class="h-code">
                    <?php if ($codeModule): ?><div>Code module : <?= $codeModule ?></div><?php endif; ?>
                    <?php if ($intitule): ?><div><?= $intitule ?></div><?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>

    <!-- INFO MODULE -->
    <table class="info-table">
        <tr>
            <td class="lbl" style="width:13%">Filière</td>
            <td class="sep">:</td>
            <td style="width:47%"><?= $filiere ?></td>
            <td class="lbl" style="width:18%">Durée</td>
            <td style="width:17%;text-align:center">: <?= $duree ?></td>
        </tr>
        <tr>
            <td class="lbl">Année</td>
            <td class="sep">:</td>
            <td><?= $annee ?></td>
            <td class="lbl">Note finale</td>
            <td class="note" style="text-align:center">: <span style="display:inline-block;width:60px;border-bottom:1px solid #000;">&nbsp;</span> / <?= $noteMax ?></td>
        </tr>
    </table>

    <!-- QUESTIONS -->
    <?php if (!empty($questions)): ?>
    <table class="questions-table">
        <tbody>
        <?php foreach ($questions as $idx => $q):
            $ptsMax = (float)$q['points'];
        ?>
            <tr>
                <td class="col-note">
                    <span style="display:block;border-bottom:1px solid #000;min-width:36px;">&nbsp;</span>
                    <span class="pts-max">/ <?= number_format($ptsMax, 2) ?></span>
                </td>
                <td>
                    <div class="q-texte">
                        <strong>Q<?= $idx + 1 ?>.</strong>
                        <?= htmlspecialchars($q['texte'], ENT_QUOTES, 'UTF-8') ?>
                    </div>

                    <?php if ($q['type'] === 'texte_libre'): ?>
                    <div style="margin-left:6px">
                        <div style="border-bottom:1px solid #999;min-height:18px;margin-bottom:4px">&nbsp;</div>
                        <div style="border-bottom:1px solid #999;min-height:18px;margin-bottom:4px">&nbsp;</div>
                        <div style="border-bottom:1px solid #999;min-height:18px">&nbsp;</div>
                    </div>

                    <?php elseif (!empty($q['choix'])): ?>
                    <?php
                        $lettresEfm = ['A','B','C','D','E','F','G','H'];
                        $maxL = max(array_map(fn($c) => mb_strlen($c['texte']), $q['choix']));
                        $nbC  = count($q['choix']);
                        $colCls = ($maxL <= 20 && $nbC >= 4) ? 'cols-4' : ($maxL > 60 ? 'cols-1' : '');
                    ?>
                    <ul class="choix-list <?= $colCls ?>">
                        <?php foreach ($q['choix'] as $i => $c):
                            $isCorrect = $corrige && (int)$c['is_correct'];
                            $lettre = $lettresEfm[$i] ?? chr(65+$i);
                        ?>
                        <li class="choix-item <?= $isCorrect ? 'correct-choice' : '' ?>">
                            <span class="choix-circle"><?= $isCorrect ? '&#10003;' : '' ?></span>
                            <span class="choix-lettre"><?= $lettre ?>)</span>
                            <span><?= htmlspecialchars($c['texte'], ENT_QUOTES, 'UTF-8') ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php else: ?>
                    <div style="margin-left:6px;border-bottom:1px solid #999;min-height:18px">&nbsp;</div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

</div>

<?php if ($tamponB64): ?>
<img class="tampon-ofppt" src="<?= $tamponB64 ?>" alt="Tampon OFPPT">
<?php endif; ?>

</body>
</html>
