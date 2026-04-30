<?php
/**
 * Fiche résultat officielle EFM — format OFPPT
 * Accessible : session stagiaire (eval_stagiaire) OU session admin (eval_admin)
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/includes/functions.php';

// ── Auth : admin OU stagiaire ──────────────────────────────────────────────
$isAdmin = false;
session_name(ADMIN_SESSION_NAME);
session_start();
if (!empty($_SESSION['admin_id'])) {
    $isAdmin = true;
} else {
    session_write_close();
    session_name(SESSION_EVAL_NAME);
    session_start();
    if (empty($_SESSION['stagiaire_id'])) {
        http_response_code(403);
        exit('Accès refusé — connectez-vous en tant que stagiaire ou administrateur.');
    }
}

$pdo = getDB();
$sid = (int)($_GET['sid'] ?? 0);
if ($sid <= 0) { http_response_code(400); exit('Paramètre manquant.'); }

$session = getSession($sid);
if (!$session || $session['statut'] !== 'termine') {
    http_response_code(404); exit('Session introuvable ou non terminée.');
}

if (!$isAdmin) {
    if ((int)$session['stagiaire_id'] !== (int)$_SESSION['stagiaire_id']) {
        http_response_code(403); exit('Accès refusé.');
    }
}

if (($session['module_type'] ?? '') !== 'efm') {
    http_response_code(400); exit("Ce module n'est pas un EFM.");
}

// ── Métadonnées EFM ───────────────────────────────────────────────────────
$meta          = json_decode($session['meta_json'] ?? '{}', true);
$codeModule    = $meta['code_module']    ?? '';
$filiere       = $meta['filiere']        ?? '';
$etablissement = $meta['etablissement']  ?? '';
$annee         = $meta['annee']          ?? '';

// ── Note / durée ──────────────────────────────────────────────────────────
$module   = getModule((int)$session['module_id']);
$noteMax  = (int)($module['note_max'] ?? 40);
$total    = (float)$session['total_points'];
$scoreRaw = (float)$session['score'];
$scoreSur = $total > 0 ? round($scoreRaw / $total * $noteMax, 2) : 0;

$dureeMin = (int)$session['duree_minutes'];
$dureeStr = ($dureeMin >= 60)
    ? floor($dureeMin / 60) . 'h' . ($dureeMin % 60 > 0 ? sprintf('%02d', $dureeMin % 60) : '')
    : $dureeMin . ' min';

// ── Questions + réponses stagiaire (toutes les questions, même sans réponse) ──
$stmtQ = $pdo->prepare("
    SELECT q.id, q.texte AS question_texte, q.type, q.points AS points_max, q.ordre,
           COALESCE(rs.points_obtenus, 0)  AS points_obtenus,
           COALESCE(rs.reponse_texte, '')  AS reponse_texte,
           rs.choix_id,
           cr.texte                        AS choix_texte
    FROM questions q
    LEFT JOIN reponses_stagiaires rs ON rs.question_id = q.id AND rs.session_id = ?
    LEFT JOIN choix_reponses cr ON cr.id = rs.choix_id
    WHERE q.module_id = ?
    ORDER BY q.ordre, q.id
");
$stmtQ->execute([$sid, (int)$session['module_id']]);
$questions = $stmtQ->fetchAll();

$dateEval = $session['date_fin']
    ? date('d/m/Y', strtotime($session['date_fin']))
    : date('d/m/Y');

// ── Logo en base64 (chemin relatif ne fonctionne pas à l'impression) ───────
$logoPath = __DIR__ . '/assets/img/logo_efm.png';
$logoB64  = file_exists($logoPath)
    ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))
    : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fiche EFM — <?= sanitize($session['module_nom']) ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Calibri, Arial, sans-serif;
            font-size: 11pt;
            color: #000;
            background: #e8e8e8;
        }

        /* ── Barre écran ── */
        .screen-bar {
            background: #1a3a6b;
            color: #fff;
            padding: 9px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: Arial, sans-serif;
            font-size: 13px;
        }
        .screen-bar a, .screen-bar button {
            background: rgba(255,255,255,.14);
            color: #fff;
            border: 1px solid rgba(255,255,255,.32);
            padding: 5px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
        }
        .screen-bar .btn-print {
            background: #fff;
            color: #1a3a6b;
            font-weight: bold;
            border-color: #fff;
        }
        .screen-bar .spacer { flex: 1; }

        /* ── Feuille A4 ── */
        .page-wrapper {
            max-width: 210mm;
            margin: 18px auto;
            background: #fff;
            padding: 13mm 14mm 18mm;
            box-shadow: 0 2px 14px rgba(0,0,0,.2);
        }

        /* ══════════════════════════════════════
           EN-TÊTE OFFICIEL OFPPT
           ══════════════════════════════════════ */
        .efm-header {
            width: 100%;
            border-collapse: collapse;
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
            border-bottom: 1px solid #000;
            border-right: none !important;
            padding: 6px 10px;
            width: 50%;
        }
        .td-empty {
            border: none !important;
            width: 50%;
        }

        /* Logo OFPPT */
        .logo-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .efm-logo { height: 40px; }
        .direction-text {
            font-size: 9.5pt;
            font-style: italic;
            line-height: 1.35;
        }
        .direction-text strong { font-style: normal; font-size: 9pt; }

        /* Ligne 2 : Titre EFM — pas de bordure bas/côtés */
        .td-title {
            border-left: none !important;
            border-right: none !important;
            border-bottom: none !important;
            border-top: 1px solid #000;
            text-align: center;
            padding: 10px 8px 6px;
            font-size: 14pt;
            font-weight: normal;
            letter-spacing: .3px;
        }
        .td-title strong { font-weight: bold; }

        /* Ligne 3 : Code + Intitulé — bordure complète */
        .td-module {
            border: 1px solid #000 !important;
            text-align: center;
            padding: 6px 10px;
            font-size: 10.5pt;
            line-height: 1.6;
        }
        .td-module .code-line { font-weight: bold; }
        .td-module .intitule-line { }

        /* ── Tableau Filière / Durée / Année / Note ── */
        .efm-info {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #000;
            border-top: none;
            margin-bottom: 0;
        }
        .efm-info td {
            border: 1px solid #000;
            padding: 4px 8px;
            font-size: 10.5pt;
        }
        .efm-info .lbl { font-weight: bold; white-space: nowrap; }
        .efm-info .sep { width: 14px; text-align: center; }

        /* ── Identité stagiaire ── */
        .efm-stagiaire {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #000;
            border-top: none;
            margin-bottom: 16px;
        }
        .efm-stagiaire td {
            border: 1px solid #000;
            padding: 5px 8px;
            font-size: 10.5pt;
        }

        /* ── Questions / réponses ── */
        .questions-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5pt;
        }
        .questions-table thead th {
            background: #1a3a6b;
            color: #fff;
            border: 1px solid #1a3a6b;
            padding: 5px 8px;
            font-size: 10pt;
            text-align: center;
        }
        .questions-table thead th.th-q { text-align: left; }

        .questions-table tbody tr td {
            border: 1px solid #999;
            padding: 6px 8px;
            vertical-align: top;
        }
        .questions-table tbody tr:nth-child(even) td { background: #f8f8f8; }

        .col-note {
            width: 62px;
            text-align: center;
            white-space: nowrap;
            font-weight: bold;
            vertical-align: middle !important;
        }
        .col-q { }

        .q-text {
            font-weight: normal;
            margin-bottom: 4px;
            color: #111;
        }
        .q-reponse {
            border-bottom: 1px solid #555;
            min-height: 18px;
            padding: 1px 2px;
            color: #222;
            font-style: italic;
            margin-left: 8px;
        }
        .q-reponse.empty {
            color: #bbb;
            font-style: normal;
        }

        /* ── IMPRESSION ── */
        @media print {
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            body { background: none; }
            .screen-bar { display: none !important; }
            .page-wrapper {
                margin: 0; padding: 7mm 10mm 12mm;
                box-shadow: none; max-width: none;
            }
            .questions-table thead th {
                background: #1a3a6b !important;
                color: #fff !important;
            }
        }
    </style>
</head>
<body>
<?php if (!empty($_GET['autoprint'])): ?>
<script>window.addEventListener('load', function(){ window.print(); });</script>
<?php endif; ?>

<div class="screen-bar">
    <?php if ($isAdmin): ?>
        <a href="admin/results.php">← Résultats</a>
    <?php else: ?>
        <a href="index.php">← Accueil</a>
    <?php endif; ?>
    <span class="spacer"></span>
    <button class="btn-print" onclick="window.print()">🖨&nbsp; Imprimer</button>
</div>

<div class="page-wrapper">

    <!-- ═══════════════════════════════════
         EN-TÊTE OFFICIEL
         ═══════════════════════════════════ -->
    <table class="efm-header">
        <!-- Ligne 1 : Logo + texte centré | vide droite -->
        <tr>
            <td class="td-logo">
                <table style="width:100%;border-collapse:collapse">
                    <tr>
                        <?php if ($logoB64): ?>
                        <td style="width:52px;vertical-align:middle;padding-right:10px">
                            <img src="<?= $logoB64 ?>" class="efm-logo" alt="OFPPT">
                        </td>
                        <?php endif; ?>
                        <td style="vertical-align:middle;text-align:center">
                            <div style="font-weight:bold;font-size:10.5pt;line-height:1.6">Direction Régionale</div>
                            <div style="font-weight:bold;font-size:10.5pt;line-height:1.6">ISTA HAY RIAD RABAT</div>
                        </td>
                    </tr>
                </table>
            </td>
            <td class="td-empty"></td>
        </tr>
        <!-- Ligne 2 : Titre -->
        <tr>
            <td colspan="2" class="td-title">
                <strong>É</strong>valuation de <strong>F</strong>in de <strong>M</strong>odule
            </td>
        </tr>
        <!-- Ligne 3 : Code + Intitulé sur deux lignes distinctes centrées -->
        <tr>
            <td colspan="2" class="td-module">
                <div class="code-line">Code module&nbsp;: <?= sanitize($codeModule) ?></div>
                <div class="intitule-line"><?= sanitize($session['module_nom']) ?></div>
            </td>
        </tr>
    </table>

    <!-- ── Filière / Durée / Année / Note ── -->
    <table class="efm-info">
        <tr>
            <td class="lbl">Filière</td>
            <td class="sep">:</td>
            <td><?= sanitize($filiere) ?></td>
            <td class="lbl">Durée</td>
            <td class="sep">:</td>
            <td><?= sanitize($dureeStr) ?></td>
        </tr>
        <tr>
            <td class="lbl">Année</td>
            <td class="sep">:</td>
            <td><?= sanitize($annee) ?></td>
            <td class="lbl">Note finale</td>
            <td class="sep">:</td>
            <td style="font-weight:bold"><?= number_format($scoreSur, 2) ?> / <?= $noteMax ?></td>
        </tr>
    </table>

    <!-- ── Identité du stagiaire ── -->
    <table class="efm-stagiaire">
        <tr>
            <td class="lbl" style="width:120px">Nom et Prénom</td>
            <td class="sep" style="width:14px">:</td>
            <td style="font-weight:bold">
                <?= sanitize(strtoupper($session['nom'] ?? '')) ?>
                <?= sanitize($session['prenom'] ?? '') ?>
            </td>
            <td class="lbl" style="width:55px">Groupe</td>
            <td class="sep" style="width:14px">:</td>
            <td style="width:110px"><?= sanitize($session['groupe_nom'] ?: ($session['groupe_libre'] ?? '—')) ?></td>
            <td class="lbl" style="width:45px">Date</td>
            <td class="sep" style="width:14px">:</td>
            <td style="width:80px"><?= $dateEval ?></td>
        </tr>
    </table>

    <?php if (!empty($questions)): ?>
    <!-- ── Réponses du stagiaire ── -->
    <table class="questions-table">
        <thead>
            <tr>
                <th style="width:62px">Note</th>
                <th class="th-q">Question / Réponse du stagiaire</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($questions as $idx => $q):
                $pts     = (float)$q['points_obtenus'];
                $ptsMax  = (float)$q['points_max'];
                $hasResp = ($q['choix_id'] !== null) || trim($q['reponse_texte']) !== '';
                $reponse = '';
                if ($q['type'] === 'texte_libre') {
                    $reponse = trim($q['reponse_texte']);
                } elseif ($q['choix_texte']) {
                    $reponse = $q['choix_texte'];
                }
            ?>
            <tr>
                <td class="col-note">
                    <?= number_format($pts, 1) ?><br>
                    <span style="font-weight:normal;font-size:9pt;color:#555">/ <?= number_format($ptsMax, 1) ?></span>
                </td>
                <td class="col-q">
                    <div class="q-text">
                        <strong>Q<?= $idx + 1 ?>.</strong>
                        <?= sanitize($q['question_texte']) ?>
                    </div>
                    <div class="q-reponse <?= $reponse === '' ? 'empty' : '' ?>">
                        <?= $reponse !== '' ? sanitize($reponse) : '&nbsp;' ?>
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
