<?php
/**
 * Impression du résultat d'une session EFM — format officiel OFPPT
 * Paramètre GET : session_id
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$sessionId = (int)($_GET['session_id'] ?? 0);
if (!$sessionId) { header('Location: results.php'); exit; }

$pdo     = getDB();
$session = getSession($sessionId);
if (!$session) { header('Location: results.php'); exit; }

$module = getModule((int)$session['module_id']);
if (!$module) { header('Location: results.php'); exit; }

$meta          = json_decode($module['meta_json'] ?? '{}', true) ?? [];
$codeModule    = $meta['code_module']    ?? '';
$filiere       = $meta['filiere']        ?? '';
$etablissement = $meta['etablissement']  ?? '';
$annee         = $meta['annee']          ?? '';
$noteMax       = (int)($module['note_max'] ?? 20);
$duree         = $module['duree_minutes'] ? formatDuration((int)$module['duree_minutes']) : '';
$intitule      = $module['nom'];

$nom    = $session['nom']    ?? '';
$prenom = $session['prenom'] ?? '';
$groupe = $session['groupe_nom'] ?? $session['groupe_libre'] ?? '';

$score      = (float)$session['score'];
$totalPts   = (float)$session['total_points'];
$noteFinale = $totalPts > 0 ? round($score / $totalPts * $noteMax, 2) : 0;

// ── Toutes les questions du module + réponse du stagiaire ──────────────────
$stmt = $pdo->prepare("
    SELECT q.id, q.texte AS question_texte, q.type,
           q.points AS points_max, q.ordre,
           COALESCE(rs.points_obtenus, 0) AS points_obtenus,
           COALESCE(rs.reponse_texte, '') AS reponse_texte,
           rs.choix_id,
           cr.texte AS choix_texte
    FROM questions q
    LEFT JOIN reponses_stagiaires rs ON rs.question_id = q.id AND rs.session_id = ?
    LEFT JOIN choix_reponses cr ON cr.id = rs.choix_id
    WHERE q.module_id = ?
    ORDER BY q.ordre, q.id
");
$stmt->execute([$sessionId, (int)$session['module_id']]);
$questions = $stmt->fetchAll();

// ── Logo base64 (invisible via chemin relatif à l'impression) ─────────────
$logoPath = __DIR__ . '/../assets/img/logo_efm.png';
$logoB64  = file_exists($logoPath)
    ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))
    : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>EFM — <?= htmlspecialchars("$prenom $nom") ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Calibri, Arial, sans-serif;
            font-size: 11pt;
            background: #e0e0e0;
            color: #000;
        }

        /* ── Boutons écran ── */
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
        .btn-print { background: #dc3545; color: #fff; }
        .btn-back  { background: #6c757d; color: #fff; }

        /* ── Page A4 ── */
        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            padding: 10mm 13mm 15mm;
            background: #fff;
            box-shadow: 0 4px 24px rgba(0,0,0,.22);
        }

        /* ═══════════════════════════════════════════════
           EN-TÊTE — modèle OFPPT
           ═══════════════════════════════════════════════ */
        .efm-header {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }
        .efm-header td {
            border: 1px solid #000;
            padding: 0;
            vertical-align: middle;
        }

        /* Ligne 1 : logo | vide — pas de bordure extérieure haut/côtés */
        .td-logo {
            border-top: none !important;
            border-left: none !important;
            border-right: none !important;
            border-bottom: 1px solid #000;
            padding: 5px 10px;
            width: 50%;
        }
        .td-vide {
            border: none !important;
            width: 50%;
        }
        .logo-row { display: flex; align-items: center; gap: 9px; }
        .efm-logo { height: 40px; }
        .direction-text { font-size: 9pt; font-style: italic; line-height: 1.4; }
        .direction-text strong { font-style: normal; }

        /* Ligne 2 : titre — pas de bordure bas/côtés */
        .td-title {
            border-left: none !important;
            border-right: none !important;
            border-bottom: none !important;
            border-top: 1px solid #000;
            text-align: center;
            padding: 9px 8px 5px;
            font-size: 14pt;
        }

        /* Ligne 3 : code + intitulé — bordure complète, 2 lignes centrées */
        .td-module {
            border: 1px solid #000 !important;
            text-align: center;
            padding: 5px 10px;
            font-size: 10.5pt;
            line-height: 1.7;
        }
        .td-module .code-line    { font-weight: bold; }
        .td-module .intitule-line { font-weight: normal; }

        /* ── Tableau Filière / Durée / Année / Note ── */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #000;
            border-top: none;
        }
        .info-table td {
            border: 1px solid #000;
            padding: 3px 7px;
            font-size: 10.5pt;
            vertical-align: middle;
        }
        .info-table .lbl  { font-weight: bold; white-space: nowrap; }
        .info-table .sep  { text-align: center; width: 14px; }
        .info-table .note { font-weight: bold; }

        /* ── Identité stagiaire ── */
        .identite-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #000;
            border-top: none;
            margin-bottom: 10px;
        }
        .identite-table td {
            border: 1px solid #000;
            padding: 4px 7px;
            font-size: 10.5pt;
        }

        /* ── Questions ── */
        .questions-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5pt;
        }
        .questions-table thead th {
            background: #000;
            color: #fff;
            padding: 4px 8px;
            border: 1px solid #000;
            font-size: 10pt;
            text-align: center;
        }
        .questions-table thead th.th-q { text-align: left; }
        .questions-table tbody td {
            border: 1px solid #aaa;
            padding: 5px 8px;
            vertical-align: top;
        }
        .questions-table tbody tr:nth-child(even) td { background: #f9f9f9; }

        /* Colonne note à gauche */
        .col-note {
            width: 58px;
            text-align: center;
            font-weight: bold;
            white-space: nowrap;
            vertical-align: middle !important;
        }
        .col-note .pts-max { font-weight: normal; font-size: 9pt; color: #555; }

        /* Colonne question + réponse */
        .q-texte  { font-weight: normal; margin-bottom: 4px; }
        .q-reponse {
            margin-left: 6px;
            border-bottom: 1px solid #444;
            min-height: 17px;
            padding: 1px 3px;
        }
        .q-reponse.vide { color: #bbb; }

        /* ── Signatures ── */
        .sign-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #000;
            margin-top: 18px;
        }
        .sign-table td {
            border: 1px solid #000;
            text-align: center;
            padding: 6px 8px;
            height: 50px;
            vertical-align: top;
            font-size: 10pt;
            font-weight: bold;
        }

        /* ── Impression ── */
        @media print {
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            body { background: #fff; }
            .print-controls { display: none !important; }
            .page { margin: 0; box-shadow: none; width: 100%; padding: 7mm 10mm 12mm; }
            @page { size: A4; margin: 0; }
            .questions-table thead th {
                background: #000 !important;
                color: #fff !important;
            }
        }
    </style>
</head>
<body>

<div class="print-controls">
    <a href="detail.php?id=<?= $sessionId ?>" class="btn-back">&#8592; Retour</a>
    <button class="btn-print" onclick="window.print()">&#128438; Imprimer</button>
</div>

<div class="page">

    <!-- ═══════════════════════════════════════════
         EN-TÊTE OFFICIEL OFPPT
         ═══════════════════════════════════════════ -->
    <table class="efm-header">
        <!-- Ligne 1 : logo gauche | vide droite -->
        <tr>
            <td class="td-logo">
                <div class="logo-row">
                    <?php if ($logoB64): ?>
                        <img src="<?= $logoB64 ?>" class="efm-logo" alt="OFPPT">
                    <?php endif; ?>
                    <div class="direction-text">
                        Direction Régionale
                        <?php if ($etablissement): ?>
                            <br><strong><?= htmlspecialchars($etablissement, ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
            <td class="td-vide"></td>
        </tr>
        <!-- Ligne 2 : titre EFM -->
        <tr>
            <td colspan="2" class="td-title">
                <strong>É</strong>valuation de <strong>F</strong>in de <strong>M</strong>odule
            </td>
        </tr>
        <!-- Ligne 3 : code + intitulé — 2 lignes distinctes centrées -->
        <tr>
            <td colspan="2" class="td-module">
                <div class="code-line">Code module&nbsp;: <?= htmlspecialchars($codeModule, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="intitule-line"><?= htmlspecialchars($intitule, ENT_QUOTES, 'UTF-8') ?></div>
            </td>
        </tr>
    </table>

    <!-- ── Filière / Durée / Année / Note ── -->
    <table class="info-table">
        <tr>
            <td class="lbl">Filière</td>
            <td class="sep">:</td>
            <td><?= htmlspecialchars($filiere, ENT_QUOTES, 'UTF-8') ?></td>
            <td class="lbl">Durée</td>
            <td class="sep">:</td>
            <td><?= htmlspecialchars($duree, ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
            <td class="lbl">Année</td>
            <td class="sep">:</td>
            <td><?= htmlspecialchars($annee, ENT_QUOTES, 'UTF-8') ?></td>
            <td class="lbl">Note finale</td>
            <td class="sep">:</td>
            <td class="note"><?= number_format($noteFinale, 2) ?> / <?= $noteMax ?></td>
        </tr>
    </table>

    <!-- ── Identité du stagiaire ── -->
    <table class="identite-table">
        <tr>
            <td class="lbl" style="width:120px">Nom et Prénom</td>
            <td class="sep" style="width:14px">:</td>
            <td style="font-weight:bold">
                <?= htmlspecialchars(strtoupper($nom) . ' ' . $prenom, ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td class="lbl" style="width:55px">Groupe</td>
            <td style="width:14px; text-align:center">:</td>
            <td style="width:110px"><?= htmlspecialchars($groupe, ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
    </table>

    <!-- ── Réponses du stagiaire ── -->
    <?php if (!empty($questions)): ?>
    <table class="questions-table">
        <thead>
            <tr>
                <th style="width:58px">Note</th>
                <th class="th-q">Question / Réponse du stagiaire</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($questions as $idx => $q):
                $pts    = (float)$q['points_obtenus'];
                $ptsMax = (float)$q['points_max'];
                // Réponse : texte libre ou choix sélectionné
                if ($q['type'] === 'texte_libre') {
                    $reponse = trim($q['reponse_texte']);
                } else {
                    $reponse = $q['choix_texte'] ?? '';
                }
            ?>
            <tr>
                <td class="col-note">
                    <?= number_format($pts, 1) ?>
                    <br><span class="pts-max">/ <?= number_format($ptsMax, 1) ?></span>
                </td>
                <td>
                    <div class="q-texte">
                        <strong>Q<?= $idx + 1 ?>.</strong>
                        <?= htmlspecialchars($q['question_texte'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="q-reponse <?= $reponse === '' ? 'vide' : '' ?>">
                        <?= $reponse !== '' ? htmlspecialchars($reponse, ENT_QUOTES, 'UTF-8') : '&nbsp;' ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- ── Signatures ── -->
    <table class="sign-table">
        <tr>
            <td>Signature du stagiaire</td>
            <td>Signature du formateur</td>
            <td>Cachet de l'établissement</td>
        </tr>
    </table>

</div>
</body>
</html>
