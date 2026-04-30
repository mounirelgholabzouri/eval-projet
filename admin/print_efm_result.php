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
        .efm-header td { vertical-align: top; }

        /* Ligne 1 : Logo + Direction Régionale */
        .h-logo {
            width: 55%;
            border: 1px solid #000;
            padding: 4px 8px;
            vertical-align: middle;
        }
        .logo-row { display: flex; align-items: center; gap: 8px; }
        .efm-logo { height: 38px; }
        .direction-text { font-size: 9.5pt; line-height: 1.4; }

        /* Ligne 2 : Titre EFM */
        .h-efm {
            border: 1px solid #000;
            border-top: none;
            text-align: center;
            padding: 6px 8px;
            font-size: 13pt;
            font-style: italic;
            vertical-align: middle;
        }

        /* Colonne droite : Identité stagiaire (rowspan=2) */
        .h-identity {
            width: 45%;
            border: 1px solid #000;
            padding: 5px 8px;
            vertical-align: top;
            font-size: 10.5pt;
        }
        .h-identity .id-line { margin-bottom: 4px; font-weight: bold; }
        .h-identity .id-line:last-child { margin-bottom: 0; }
        .h-identity .id-lbl { font-weight: bold; }

        /* Ligne 3 : Code module + Intitulé (pleine largeur) */
        .h-code {
            border: 1px solid #000;
            border-top: none;
            text-align: center;
            padding: 4px 8px;
            font-size: 10.5pt;
            line-height: 1.7;
        }

        /* ── Tableau Filière / Durée / Année / Note ── */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: -1px;
            margin-bottom: 8px;
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
        <colgroup>
            <col style="width:55%">
            <col style="width:45%">
        </colgroup>
        <tbody>
            <!-- Ligne 1 : Logo + texte centré | Identité (rowspan=2) -->
            <tr>
                <td class="h-logo">
                    <table style="width:100%;border-collapse:collapse">
                        <tr>
                            <?php if ($logoB64): ?>
                            <td style="width:52px;vertical-align:middle;padding-right:8px">
                                <img src="<?= $logoB64 ?>" class="efm-logo" alt="OFPPT">
                            </td>
                            <?php endif; ?>
                            <td style="vertical-align:middle;text-align:center">
                                <div style="font-weight:bold;font-size:10pt;line-height:1.5">Direction Régionale</div>
                                <div style="font-weight:bold;font-size:10pt;line-height:1.5">ISTA HAY RIAD RABAT</div>
                            </td>
                        </tr>
                    </table>
                </td>
                <td rowspan="2" class="h-identity">
                    <div class="id-line"><span class="id-lbl">Nom :</span> <?= htmlspecialchars(strtoupper($nom), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="id-line"><span class="id-lbl">Prénom :</span> <?= htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="id-line"><span class="id-lbl">Groupe :</span> <?= htmlspecialchars($groupe, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="id-line"><span class="id-lbl">Etablissement :</span> <?= htmlspecialchars($etablissement, ENT_QUOTES, 'UTF-8') ?></div>
                </td>
            </tr>
            <!-- Ligne 2 : Titre EFM -->
            <tr>
                <td class="h-efm">Évaluation de Fin de Module</td>
            </tr>
            <!-- Ligne 3 : Code module + Intitulé (pleine largeur) -->
            <tr>
                <td colspan="2" class="h-code">
                    <div>Code module : <?= htmlspecialchars($codeModule, ENT_QUOTES, 'UTF-8') ?></div>
                    <div><?= htmlspecialchars($intitule, ENT_QUOTES, 'UTF-8') ?></div>
                </td>
            </tr>
        </tbody>
    </table>

    <!-- ── Filière / Durée / Année / Note ── -->
    <table class="info-table">
        <tr>
            <td class="lbl" style="width:13%">Filière</td>
            <td class="sep">:</td>
            <td style="width:47%"><?= htmlspecialchars($filiere, ENT_QUOTES, 'UTF-8') ?></td>
            <td class="lbl" style="width:18%">Durée</td>
            <td style="width:17%; text-align:center">: <?= htmlspecialchars($duree, ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
            <td class="lbl">Année</td>
            <td class="sep">:</td>
            <td><?= htmlspecialchars($annee, ENT_QUOTES, 'UTF-8') ?></td>
            <td class="lbl">Note finale</td>
            <td class="note" style="text-align:center">: <?= number_format($noteFinale, 2) ?> / <?= $noteMax ?></td>
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


</div>
</body>
</html>
