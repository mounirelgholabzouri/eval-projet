<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

// ── Paramètres ────────────────────────────────────────────────
$moduleId     = (int)($_GET['module_id'] ?? 0);
$partieIds    = array_filter(array_map('intval', explode(',', $_GET['partie_ids'] ?? '')));
$shuffle      = (bool)(int)($_GET['shuffle'] ?? 1);
$shuffleChoix = (bool)(int)($_GET['shuffle_choix'] ?? 1);
$corrige      = (bool)(int)($_GET['corrige'] ?? 0);

$etablissement = htmlspecialchars(trim($_GET['etablissement'] ?? ''), ENT_QUOTES, 'UTF-8');
$filiere       = htmlspecialchars(trim($_GET['filiere'] ?? ''), ENT_QUOTES, 'UTF-8');
$duree         = htmlspecialchars(trim($_GET['duree'] ?? ''), ENT_QUOTES, 'UTF-8');
$annee         = htmlspecialchars(trim($_GET['annee'] ?? ''), ENT_QUOTES, 'UTF-8');
$noteMax       = (int)($_GET['note_max'] ?? 20);
$codeModule    = htmlspecialchars(trim($_GET['code_module'] ?? ''), ENT_QUOTES, 'UTF-8');
$intitule      = htmlspecialchars(trim($_GET['intitule'] ?? ''), ENT_QUOTES, 'UTF-8');

$module = $moduleId ? getModule($moduleId) : null;
if (!$module || empty($partieIds)) {
    echo '<p class="text-danger p-4">Paramètres invalides. <a href="efm.php">Retour</a></p>'; exit;
}

// ── Charger les questions par partie sélectionnée ─────────────
$sections = [];
$totalPoints = 0;

foreach ($partieIds as $pid) {
    $pStmt = $pdo->prepare("SELECT * FROM parties WHERE id = ? AND module_id = ?");
    $pStmt->execute([$pid, $moduleId]);
    $partie = $pStmt->fetch();
    if (!$partie) continue;

    $nbDemande = (int)($_GET["p[$pid]"] ?? 0);

    $qStmt = $pdo->prepare("SELECT * FROM questions WHERE partie_id = ? AND module_id = ? ORDER BY ordre, id");
    $qStmt->execute([$pid, $moduleId]);
    $questions = $qStmt->fetchAll();

    if ($shuffle) shuffle($questions);
    if ($nbDemande > 0) $questions = array_slice($questions, 0, $nbDemande);

    foreach ($questions as &$q) {
        $cStmt = $pdo->prepare("SELECT * FROM choix_reponses WHERE question_id = ? ORDER BY ordre, id");
        $cStmt->execute([$q['id']]);
        $choix = $cStmt->fetchAll();
        if ($shuffleChoix && !empty($choix)) shuffle($choix);
        $q['choix'] = $choix;
        $totalPoints += (float)$q['points'];
    }
    unset($q);

    $sections[] = ['partie' => $partie, 'questions' => $questions];
}

// Numérotation globale des questions
$qNum = 1;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EFM — <?= $codeModule ? $codeModule . ' — ' : '' ?><?= $intitule ?></title>
    <style>
        /* ── Reset & base ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Calibri', Arial, sans-serif;
            font-size: 11pt;
            background: #e0e0e0;
            color: #000;
        }

        /* ── Contrôle impression (masqué à l'écran) ── */
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
        .btn-corrige { background: #198754; color: #fff; }
        .btn-back { background: #6c757d; color: #fff; }

        /* ── Page A4 ── */
        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            padding: 10mm 12mm 15mm 12mm;
            background: #fff;
            box-shadow: 0 4px 20px rgba(0,0,0,.2);
        }

        /* ── En-tête principal (table unique) ── */
        .efm-header {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
            font-size: 10pt;
        }
        .efm-header td { vertical-align: top; }

        /* Cellule logo + Direction Régionale */
        .h-logo {
            width: 55%;
            border: 1px solid #000;
            padding: 2mm 3mm;
            vertical-align: middle;
        }
        .h-logo-inner {
            display: flex;
            align-items: center;
            gap: 3mm;
        }
        .h-logo-inner img { height: 16mm; }
        .h-org { font-size: 9.5pt; line-height: 1.4; }

        /* Cellule titre EFM */
        .h-efm {
            border: 1px solid #000;
            border-top: none;
            padding: 3mm;
            text-align: center;
            font-size: 13pt;
            font-style: italic;
            vertical-align: middle;
        }

        /* Cellule identité stagiaire (colonne droite, rowspan=2) */
        .h-identity {
            width: 45%;
            border: 1px solid #000;
            padding: 4mm 5mm;
            vertical-align: top;
            font-weight: bold;
        }
        .h-identity div { margin-bottom: 3mm; }
        .h-identity div:last-child { margin-bottom: 0; }

        /* Cellule Code module + Intitulé (pleine largeur) */
        .h-code {
            border: 1px solid #000;
            border-top: none;
            text-align: center;
            padding: 2mm 3mm;
            font-size: 10.5pt;
        }
        .h-code div { line-height: 1.6; }

        /* ── Tableau infos module ── */
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

        /* ── Partie (section) ── */
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
        .choix-item.correct-choice .choix-circle {
            background: #198754;
            border-color: #198754;
        }
        .choix-item.correct-choice {
            color: #198754;
            font-weight: bold;
        }

        /* ── Question texte libre ── */
        .reponse-libre {
            margin: 2mm 0 0 14mm;
        }
        .reponse-ligne {
            border-bottom: 1px solid #999;
            height: 6mm;
            margin-bottom: 1.5mm;
        }

        /* ── Pied de page ── */
        .footer-efm {
            text-align: center;
            font-size: 8.5pt;
            color: #555;
            margin-top: 5mm;
            border-top: 1px solid #ccc;
            padding-top: 2mm;
        }

        /* ── Mode corrigé ── */
        .badge-corrige {
            background: #198754;
            color: #fff;
            padding: 1mm 3mm;
            font-size: 8pt;
            font-weight: bold;
            border-radius: 3px;
            margin-left: 4mm;
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

<!-- ── Boutons (écran seulement) ── -->
<div class="print-controls">
    <a href="efm.php?module_id=<?= $moduleId ?>" class="btn-back">&#8592; Retour</a>
    <?php
    // Lien corrigé (inverser le param corrige)
    $corrigeParams = $_GET;
    $corrigeParams['corrige'] = $corrige ? 0 : 1;
    $corrigeUrl = 'print_efm.php?' . http_build_query($corrigeParams);
    ?>
    <a href="<?= $corrigeUrl ?>" class="btn-corrige">
        <?= $corrige ? '&#128065; Masquer le corrigé' : '&#10003; Afficher le corrigé' ?>
    </a>
    <button class="btn-print" onclick="window.print()">&#128438; Imprimer</button>
</div>

<!-- ── Page A4 ── -->
<div class="page">

    <!-- EN-TÊTE -->
    <?php
    $logoPath = __DIR__ . '/../assets/img/logo_efm.png';
    $logoB64  = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : '';
    ?>
    <table class="efm-header">
        <colgroup>
            <col style="width:55%">
            <col style="width:45%">
        </colgroup>
        <tbody>
            <!-- Ligne 1 : Logo + Direction | Zone identité (rowspan=2) -->
            <tr>
                <td class="h-logo">
                    <div class="h-logo-inner">
                        <?php if ($logoB64): ?>
                        <img src="<?= $logoB64 ?>" alt="OFPPT">
                        <?php endif; ?>
                        <div class="h-org">Direction Régionale RABAT-SALÉ-KENITRA</div>
                    </div>
                </td>
                <td rowspan="2" class="h-identity">
                    <div>Nom : ………………………………………………………………</div>
                    <div>Prénom : ……………………………………………………………</div>
                    <div>Groupe : ……………………………………………………………</div>
                    <div>Etablissement : …………………………………………………</div>
                </td>
            </tr>
            <!-- Ligne 2 : Titre EFM -->
            <tr>
                <td class="h-efm">
                    Évaluation de Fin de Module
                    <?php if ($corrige): ?><span class="badge-corrige">CORRIGÉ</span><?php endif; ?>
                </td>
            </tr>
            <!-- Ligne 3 : Code module + Intitulé (pleine largeur) -->
            <tr>
                <td colspan="2" class="h-code">
                    <?php if ($codeModule): ?><div>Code module : <?= $codeModule ?></div><?php endif; ?>
                    <?php if ($intitule): ?><div>Intitulé du module : <?= $intitule ?></div><?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>

    <!-- TABLEAU INFOS MODULE -->
    <table class="info-table">
        <tr>
            <td class="label" style="width:13%">Filière</td>
            <td class="sep">:</td>
            <td style="width:47%"><?= $filiere ?></td>
            <td class="label" style="width:18%">Durée</td>
            <td class="val-r" style="width:17%">: <?= $duree ?></td>
        </tr>
        <tr>
            <td class="label">Année</td>
            <td class="sep">:</td>
            <td><?= $annee ?></td>
            <td class="label">Note finale</td>
            <td class="val-r">: / <?= $noteMax ?></td>
        </tr>
    </table>

    <hr class="section-sep" style="margin-bottom:3mm">

    <!-- QUESTIONS PAR PARTIE -->
    <?php
    $lettres = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    foreach ($sections as $section):
        $partiePoints = array_sum(array_column($section['questions'], 'points'));
        $nbQ = count($section['questions']);
        if ($nbQ === 0) continue;
    ?>
    <!-- Partie : <?= htmlspecialchars($section['partie']['nom'], ENT_QUOTES, 'UTF-8') ?> -->
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
            <?php foreach ($q['choix'] as $i => $c):
                $isCorrect = $corrige && $c['is_correct'];
            ?>
            <li class="choix-item <?= $isCorrect ? 'correct-choice' : '' ?>">
                <span class="choix-circle"></span>
                <span><strong><?= $lettres[$i] ?? chr(65 + $i) ?>)</strong> <?= htmlspecialchars($c['texte'], ENT_QUOTES, 'UTF-8') ?></span>
            </li>
            <?php endforeach; ?>
        </ul>

        <?php elseif ($q['type'] === 'vrai_faux'): ?>
        <ul class="choix-list">
            <?php foreach (['Vrai', 'Faux'] as $vf):
                $isCorrect = $corrige && (
                    ($vf === 'Vrai' && !empty($q['choix']) && $q['choix'][0]['is_correct']) ||
                    ($vf === 'Faux' && !empty($q['choix']) && !$q['choix'][0]['is_correct'])
                );
            ?>
            <li class="choix-item <?= $isCorrect ? 'correct-choice' : '' ?>">
                <span class="choix-circle"></span>
                <span><?= $vf ?></span>
            </li>
            <?php endforeach; ?>
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

    <!-- PIED DE PAGE -->
    <div class="footer-efm">
        <?= $codeModule ? "Module $codeModule" : '' ?>
        <?= $codeModule && $annee ? ' — ' : '' ?>
        <?= $annee ? "Année $annee" : '' ?>
        <?= $totalPoints > 0 ? ' — Barème total : ' . $totalPoints . ' pts' : '' ?>
    </div>

</div><!-- .page -->

</body>
</html>
