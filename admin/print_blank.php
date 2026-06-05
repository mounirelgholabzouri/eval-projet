<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

$moduleId = (int)($_GET['module_id'] ?? 0);
$module   = $moduleId ? getModule($moduleId) : null;
if (!$module) {
    echo '<p class="text-danger p-4">Module introuvable. <a href="modules.php">Retour</a></p>'; exit;
}

$isEfm  = ($module['type'] ?? 'qcm') === 'efm';
$ccNum  = (int)($_GET['cc_num'] ?? 0);   // 1 = CC1, 2 = CC2, 3 = CC3, 0 = non spécifié

$codeModule  = htmlspecialchars($module['efm_code_module']   ?? '', ENT_QUOTES, 'UTF-8');
$filiere     = htmlspecialchars($module['efm_filiere']       ?? '', ENT_QUOTES, 'UTF-8');
$etabl       = htmlspecialchars($module['efm_etablissement'] ?: 'Direction Régionale RABAT-SALÉ-KENITRA', ENT_QUOTES, 'UTF-8');
$annee       = htmlspecialchars($module['efm_annee']         ?: date('Y') . '/' . (date('Y') + 1), ENT_QUOTES, 'UTF-8');
$noteMax     = (int)($module['note_max'] ?? 20);
$duree       = (int)($module['duree_minutes'] ?? 0);
$intitule    = htmlspecialchars($module['nom'], ENT_QUOTES, 'UTF-8');

// Charger toutes les questions du module
$questions   = [];
$totalPoints = 0;

$qStmt = $pdo->prepare("SELECT * FROM questions WHERE module_id = ? ORDER BY ordre, id");
$qStmt->execute([$moduleId]);
$questions = $qStmt->fetchAll();

foreach ($questions as &$q) {
    $cStmt = $pdo->prepare("SELECT * FROM choix_reponses WHERE question_id = ? ORDER BY ordre, id");
    $cStmt->execute([$q['id']]);
    $q['choix'] = $cStmt->fetchAll();
    $totalPoints += (float)$q['points'];
}
unset($q);

$logoPath = __DIR__ . '/../assets/img/logo_efm.png';
$logoB64  = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : '';
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

        /* ── Badge CC ── */
        .badge-cc {
            display: inline-block;
            padding: 1mm 4mm;
            border-radius: 3mm;
            font-size: 13pt;
            font-weight: 900;
            letter-spacing: 1px;
            vertical-align: middle;
            margin-left: 4mm;
        }
        .badge-cc1 { background: #0d6efd; color: #fff; }
        .badge-cc2 { background: #198754; color: #fff; }
        .badge-cc3 { background: #e67e00; color: #fff; }

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
            margin: 1mm 0 0 14mm;
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
            flex: 0 0 calc(50% - 5mm);   /* 2 par ligne par défaut */
            min-width: 0;
        }
        /* 4 par ligne si textes courts */
        .choix-list.cols-4 .choix-item { flex: 0 0 calc(25% - 5mm); }
        /* 1 par ligne si textes longs */
        .choix-list.cols-1 .choix-item { flex: 0 0 100%; }

        .choix-lettre {
            font-weight: bold;
            min-width: 5mm;
            flex-shrink: 0;
        }
        .choix-circle {
            width: 3.5mm;
            height: 3.5mm;
            border: 1px solid #000;
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 0.8mm;
            display: inline-block;
        }
        .choix-correct { font-weight: bold; color: #1a7a2e; }

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
                        <div class="h-org">
                            <div>Direction Régionale Rabat – Salé – Kénitra</div>
                            <div>ISTA HAY RIAD RABAT</div>
                        </div>
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
                <td class="h-efm">EXAMEN DE FIN DE MODULE</td>
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
    <!-- ══ EN-TÊTE CONTRÔLE CONTINU ══ -->
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
                    <div>Nom : ……………………………………………………………</div>
                    <div>Prénom : ………………………………………………………</div>
                    <div>Groupe : ………………………………………………………</div>
                    <div>Date : ………………………………………………………………</div>
                </td>
            </tr>
            <tr>
                <td class="h-efm" style="font-style:normal;font-weight:bold">
                    <?php if ($ccNum > 0): ?>
                        Contrôle Continu
                        <span class="badge-cc badge-cc<?= $ccNum ?>">CC<?= $ccNum ?></span>
                    <?php else: ?>
                        Contrôle Continu N°&nbsp;<span style="display:inline-block;min-width:12mm;border-bottom:1px solid #000">&nbsp;</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td colspan="2" class="h-code">
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
<?php endif; ?>

    <hr class="section-sep" style="margin-bottom:3mm">

    <!-- ══ QUESTIONS ══ -->
    <?php if (empty($questions)): ?>
        <p style="margin-top:8mm; text-align:center; color:#888; font-style:italic;">Aucune question dans ce module.</p>
    <?php else: ?>
        <?php $qNum = 1; foreach ($questions as $q): ?>
        <div class="question-block">
            <div class="question-header">
                <span class="q-num">Q<?= $qNum++ ?>.</span>
                <span class="q-texte"><?= nl2br(htmlspecialchars($q['texte'], ENT_QUOTES, 'UTF-8')) ?></span>
                <span class="q-points">(<?= $q['points'] ?> pt<?= $q['points'] > 1 ? 's' : '' ?>)</span>
            </div>

            <?php if (in_array($q['type'], ['qcm','multiple','vrai_faux']) && !empty($q['choix'])): ?>
            <?php
                $maxL = max(array_map(fn($c) => mb_strlen($c['texte']), $q['choix']));
                $nbC  = count($q['choix']);
                $colClass = ($maxL <= 20 && $nbC >= 4) ? 'cols-4' : ($maxL > 60 ? 'cols-1' : '');
            ?>
            <ul class="choix-list <?= $colClass ?>">
                <?php foreach ($q['choix'] as $i => $c):
                    $lettre = $lettres[$i] ?? chr(65 + $i);
                ?>
                <li class="choix-item">
                    <span class="choix-circle"></span>
                    <span class="choix-lettre"><?= $lettre ?>)</span>
                    <span><?= htmlspecialchars($c['texte'], ENT_QUOTES, 'UTF-8') ?></span>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php elseif ($q['type'] === 'vrai_faux' && empty($q['choix'])): ?>
            <ul class="choix-list cols-4">
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
    <?php endif; ?>

    <div class="footer-doc">
        <?= $isEfm && $codeModule ? "Module $codeModule — " : '' ?>
        <?= $intitule ?>
        <?= $annee ? ' — Année ' . $annee : '' ?>
        <?= $totalPoints > 0 ? ' — Barème total : ' . $totalPoints . ' pts' : '' ?>
    </div>

</div>

</body>
</html>
