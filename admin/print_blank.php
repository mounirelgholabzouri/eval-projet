<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

$moduleId = (int)($_GET['module_id'] ?? 0);
$module   = $moduleId ? getModule($moduleId) : null;
if (!$module) {
    echo '<p class="text-danger p-4">Module introuvable. <a href="modules.php">Retour</a></p>'; exit;
}

$isEfm = ($module['type'] ?? 'qcm') === 'efm';
$meta  = json_decode($module['meta_json'] ?? '{}', true) ?: [];

$codeModule  = htmlspecialchars($meta['code_module'] ?? '', ENT_QUOTES, 'UTF-8');
$filiere     = htmlspecialchars($meta['filiere']     ?? '', ENT_QUOTES, 'UTF-8');
$etabl       = htmlspecialchars($meta['etablissement'] ?? 'Direction Régionale RABAT-SALÉ-KENITRA', ENT_QUOTES, 'UTF-8');
$annee       = htmlspecialchars($meta['annee']       ?? date('Y') . '/' . (date('Y') + 1), ENT_QUOTES, 'UTF-8');
$noteMax     = (int)($module['note_max'] ?? 20);
$duree       = (int)($module['duree_minutes'] ?? 0);
$intitule    = htmlspecialchars($module['nom'], ENT_QUOTES, 'UTF-8');

// Charger toutes les parties + questions
$sections    = [];
$totalPoints = 0;
$parties     = getPartiesModule($moduleId);

foreach ($parties as $partie) {
    $qStmt = $pdo->prepare("SELECT * FROM questions WHERE partie_id = ? AND module_id = ? ORDER BY ordre, id");
    $qStmt->execute([$partie['id'], $moduleId]);
    $questions = $qStmt->fetchAll();

    foreach ($questions as &$q) {
        $cStmt = $pdo->prepare("SELECT * FROM choix_reponses WHERE question_id = ? ORDER BY ordre, id");
        $cStmt->execute([$q['id']]);
        $q['choix'] = $cStmt->fetchAll();
        $totalPoints += (float)$q['points'];
    }
    unset($q);

    if (!empty($questions)) {
        $sections[] = ['partie' => $partie, 'questions' => $questions];
    }
}

$logoPath = __DIR__ . '/../assets/img/logo_efm.png';
$logoB64  = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : '';
$qNum = 1;
$lettres = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $isEfm ? 'EFM' : 'Évaluation' ?> — <?= $intitule ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Calibri', Arial, sans-serif;
            font-size: 11pt;
            background: #e0e0e0;
            color: #000;
        }

        /* ── Contrôles impression ── */
        .print-controls {
            position: fixed; top: 10px; right: 10px; z-index: 9999;
            display: flex; gap: 8px;
        }
        .print-controls a,
        .print-controls button {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; border-radius: 6px; border: none;
            font-size: 13px; font-weight: 600; cursor: pointer;
            text-decoration: none;
        }
        .btn-print { background: #dc3545; color: #fff; }
        .btn-back  { background: #6c757d; color: #fff; }

        /* ── Page A4 ── */
        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            padding: 10mm 12mm 15mm 12mm;
            background: #fff;
            box-shadow: 0 4px 20px rgba(0,0,0,.2);
        }

        /* ── En-tête EFM ── */
        .efm-header {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
            font-size: 10pt;
        }
        .efm-header td { vertical-align: top; }
        .h-logo {
            width: 55%;
            border: 1px solid #000;
            padding: 2mm 3mm;
            vertical-align: middle;
        }
        .h-logo-inner { display: flex; align-items: center; gap: 3mm; }
        .h-logo-inner img { height: 16mm; }
        .h-org { font-size: 9.5pt; line-height: 1.4; }
        .h-efm {
            border: 1px solid #000;
            border-top: none;
            padding: 3mm;
            text-align: center;
            font-size: 13pt;
            font-style: italic;
            vertical-align: middle;
        }
        .h-identity {
            width: 45%;
            border: 1px solid #000;
            padding: 4mm 5mm;
            vertical-align: top;
            font-weight: bold;
        }
        .h-identity div { margin-bottom: 3mm; }
        .h-identity div:last-child { margin-bottom: 0; }
        .h-code {
            border: 1px solid #000;
            border-top: none;
            text-align: center;
            padding: 2mm 3mm;
            font-size: 10.5pt;
        }
        .h-code div { line-height: 1.6; }

        /* ── En-tête QCM ordinaire ── */
        .qcm-header {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
            font-size: 10pt;
        }
        .qcm-header td { vertical-align: top; }
        .h-qcm-org {
            width: 55%;
            border: 1px solid #000;
            padding: 2mm 3mm;
            vertical-align: middle;
        }
        .h-qcm-org-inner { display: flex; align-items: center; gap: 3mm; }
        .h-qcm-org-inner img { height: 16mm; }
        .h-qcm-titre {
            border: 1px solid #000;
            border-top: none;
            padding: 3mm;
            text-align: center;
            font-size: 13pt;
            font-weight: bold;
            vertical-align: middle;
        }
        .h-qcm-identity {
            width: 45%;
            border: 1px solid #000;
            padding: 4mm 5mm;
            vertical-align: top;
            font-weight: bold;
        }
        .h-qcm-identity div { margin-bottom: 3mm; }
        .h-qcm-identity div:last-child { margin-bottom: 0; }
        .h-qcm-module {
            border: 1px solid #000;
            border-top: none;
            text-align: center;
            padding: 2mm 3mm;
            font-size: 10.5pt;
        }
        .h-qcm-module div { line-height: 1.6; }

        /* ── Tableau infos ── */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: -1px;
            margin-bottom: 3mm;
            font-size: 10pt;
        }
        .info-table td {
            border: 1px solid #000;
            padding: 1.5mm 2.5mm;
            vertical-align: middle;
        }
        .info-table .label { font-weight: bold; white-space: nowrap; }
        .info-table .sep   { text-align: center; width: 8mm; }
        .info-table .val-r { text-align: center; }

        /* ── Séparateur ── */
        hr.section-sep {
            border: none;
            border-top: 2px solid #000;
            margin: 2mm 0;
        }

        /* ── Partie ── */
        .partie-header {
            background: #000;
            color: #fff;
            font-weight: bold;
            font-size: 10.5pt;
            padding: 1.5mm 3mm;
            margin: 3mm 0 2mm;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .partie-bareme { font-size: 9.5pt; font-weight: normal; }

        /* ── Question ── */
        .question-block {
            margin-bottom: 3mm;
            page-break-inside: avoid;
        }
        .question-header {
            display: flex;
            align-items: baseline;
            gap: 4mm;
            margin-bottom: 1.5mm;
        }
        .q-num {
            font-weight: bold;
            font-size: 10.5pt;
            white-space: nowrap;
            min-width: 14mm;
        }
        .q-texte {
            flex: 1;
            font-size: 10.5pt;
            line-height: 1.5;
        }
        .q-points {
            white-space: nowrap;
            font-size: 9pt;
            color: #444;
            min-width: 20mm;
            text-align: right;
        }

        /* ── Choix QCM ── */
        .choix-list {
            list-style: none;
            margin: 0 0 0 14mm;
            padding: 0;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5mm 4mm;
        }
        .choix-item {
            display: flex;
            align-items: flex-start;
            gap: 2mm;
            font-size: 10pt;
            line-height: 1.4;
            padding: 0.5mm 0;
        }
        .choix-circle {
            width: 4mm;
            height: 4mm;
            border: 1px solid #000;
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 1mm;
        }

        /* ── Réponse texte libre ── */
        .reponse-libre { margin: 2mm 0 0 14mm; }
        .reponse-ligne {
            border-bottom: 1px solid #999;
            height: 6mm;
            margin-bottom: 1.5mm;
        }

        /* ── Pied de page ── */
        .footer-doc {
            text-align: center;
            font-size: 8.5pt;
            color: #555;
            margin-top: 5mm;
            border-top: 1px solid #ccc;
            padding-top: 2mm;
        }

        /* ── Impression ── */
        @media print {
            body { background: #fff; }
            .print-controls { display: none !important; }
            .page {
                margin: 0;
                padding: 10mm 12mm 15mm 12mm;
                box-shadow: none;
                width: 100%;
            }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>

<div class="print-controls">
    <a href="modules.php" class="btn-back">&#8592; Retour</a>
    <button class="btn-print" onclick="window.print()">&#128438; Imprimer</button>
</div>

<div class="page">

<?php if ($isEfm): ?>
    <!-- ══ EN-TÊTE EFM ══ -->
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
                        <div class="h-org"><?= $etabl ?></div>
                    </div>
                </td>
                <td rowspan="2" class="h-identity">
                    <div>Nom : ……………………………………………………………</div>
                    <div>Prénom : ………………………………………………………</div>
                    <div>Groupe : ………………………………………………………</div>
                    <div>Établissement : ……………………………………………</div>
                </td>
            </tr>
            <tr>
                <td class="h-efm">Évaluation de Fin de Module</td>
            </tr>
            <tr>
                <td colspan="2" class="h-code">
                    <?php if ($codeModule): ?><div>Code module : <?= $codeModule ?></div><?php endif; ?>
                    <div>Intitulé du module : <?= $intitule ?></div>
                </td>
            </tr>
        </tbody>
    </table>

    <table class="info-table">
        <tr>
            <td class="label" style="width:13%">Filière</td>
            <td class="sep">:</td>
            <td style="width:47%"><?= $filiere ?: '………………………………………' ?></td>
            <td class="label" style="width:18%">Durée</td>
            <td class="val-r" style="width:17%">: <?= $duree ? $duree . ' min' : '…… min' ?></td>
        </tr>
        <tr>
            <td class="label">Année</td>
            <td class="sep">:</td>
            <td><?= $annee ?></td>
            <td class="label">Note finale</td>
            <td class="val-r">: &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/ <?= $noteMax ?></td>
        </tr>
    </table>

<?php else: ?>
    <!-- ══ EN-TÊTE ÉVALUATION ORDINAIRE ══ -->
    <table class="qcm-header">
        <colgroup>
            <col style="width:55%">
            <col style="width:45%">
        </colgroup>
        <tbody>
            <tr>
                <td class="h-qcm-org">
                    <div class="h-qcm-org-inner">
                        <?php if ($logoB64): ?>
                        <img src="<?= $logoB64 ?>" alt="OFPPT">
                        <?php endif; ?>
                        <div class="h-org"><?= $etabl ?></div>
                    </div>
                </td>
                <td rowspan="2" class="h-qcm-identity">
                    <div>Nom : ……………………………………………………………</div>
                    <div>Prénom : ………………………………………………………</div>
                    <div>Groupe : ………………………………………………………</div>
                    <div>Date : ……………………………………………………………</div>
                </td>
            </tr>
            <tr>
                <td class="h-qcm-titre">Évaluation</td>
            </tr>
            <tr>
                <td colspan="2" class="h-qcm-module">
                    <div>Module : <?= $intitule ?></div>
                </td>
            </tr>
        </tbody>
    </table>

    <table class="info-table">
        <tr>
            <td class="label" style="width:13%">Filière</td>
            <td class="sep">:</td>
            <td style="width:47%"><?= $filiere ?: '………………………………………' ?></td>
            <td class="label" style="width:18%">Durée</td>
            <td class="val-r" style="width:17%">: <?= $duree ? $duree . ' min' : '…… min' ?></td>
        </tr>
        <tr>
            <td class="label">Année</td>
            <td class="sep">:</td>
            <td><?= $annee ?></td>
            <td class="label">Note maximale</td>
            <td class="val-r">: &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/ <?= $noteMax ?></td>
        </tr>
    </table>
<?php endif; ?>

    <hr class="section-sep" style="margin-bottom:3mm">

    <!-- ══ QUESTIONS PAR PARTIE ══ -->
    <?php if (empty($sections)): ?>
        <p style="margin-top:8mm; text-align:center; color:#888; font-style:italic;">Aucune question dans ce module.</p>
    <?php endif; ?>

    <?php foreach ($sections as $section):
        $partiePoints = array_sum(array_column($section['questions'], 'points'));
        $nbQ = count($section['questions']);
    ?>
    <div class="partie-header">
        <span><?= htmlspecialchars($section['partie']['nom'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="partie-bareme"><?= $nbQ ?> question<?= $nbQ > 1 ? 's' : '' ?> — <?= $partiePoints ?> pt<?= $partiePoints > 1 ? 's' : '' ?></span>
    </div>

    <?php foreach ($section['questions'] as $q): ?>
    <div class="question-block">
        <div class="question-header">
            <span class="q-num">Q<?= $qNum++ ?>.</span>
            <span class="q-texte"><?= nl2br(htmlspecialchars($q['texte'], ENT_QUOTES, 'UTF-8')) ?></span>
            <span class="q-points">(<?= $q['points'] ?> pt<?= $q['points'] > 1 ? 's' : '' ?>)</span>
        </div>

        <?php if ($q['type'] === 'qcm' && !empty($q['choix'])): ?>
        <ul class="choix-list">
            <?php foreach ($q['choix'] as $i => $c): ?>
            <li class="choix-item">
                <span class="choix-circle"></span>
                <span><strong><?= $lettres[$i] ?? chr(65 + $i) ?>)</strong> <?= htmlspecialchars($c['texte'], ENT_QUOTES, 'UTF-8') ?></span>
            </li>
            <?php endforeach; ?>
        </ul>

        <?php elseif ($q['type'] === 'vrai_faux'): ?>
        <ul class="choix-list">
            <li class="choix-item"><span class="choix-circle"></span><span>Vrai</span></li>
            <li class="choix-item"><span class="choix-circle"></span><span>Faux</span></li>
        </ul>

        <?php else: ?>
        <div class="reponse-libre">
            <div class="reponse-ligne"></div>
            <div class="reponse-ligne"></div>
            <div class="reponse-ligne"></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="footer-doc">
        <?= $isEfm && $codeModule ? "Module $codeModule — " : '' ?>
        <?= $intitule ?>
        <?= $annee ? ' — Année ' . $annee : '' ?>
        <?= $totalPoints > 0 ? ' — Barème total : ' . $totalPoints . ' pts' : '' ?>
    </div>

</div>

</body>
</html>
