<?php
/**
 * Génération PDF fiche résultat EFM — format officiel OFPPT
 * Même mise en page que print_efm_result.php, rendu par mPDF.
 *
 * GET session_id=X  → génère 1 PDF et le télécharge
 * GET module_id=X   → génère tous les PDFs du module, les sauve dans pdfs/efm/
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

$pdo     = getDB();
$baseDir = realpath(__DIR__ . '/../pdfs/efm');

// ── Collecter les sessions ────────────────────────────────────
$sessionId = (int)($_GET['session_id'] ?? 0);
$moduleId  = (int)($_GET['module_id']  ?? 0);

if ($sessionId > 0) {
    $sess = getSession($sessionId);
    if (!$sess || $sess['statut'] !== 'termine' || ($sess['module_type'] ?? '') !== 'efm') {
        http_response_code(400);
        exit('<p style="color:red;padding:20px">Session invalide ou non EFM. <a href="results.php">Retour</a></p>');
    }
    $sessions     = [$sess];
    $redirectBack = "results.php?module_id={$sess['module_id']}";
    $moduleNom    = $sess['module_nom'];
} elseif ($moduleId > 0) {
    $stmt = $pdo->prepare("
        SELECT s.*, m.nom AS module_nom, m.type AS module_type, m.meta_json,
               m.note_max, m.duree_minutes,
               COALESCE(g.nom, s.groupe_libre) AS groupe_nom
        FROM sessions_eval s
        JOIN modules m ON m.id = s.module_id
        LEFT JOIN groupes g ON g.id = s.groupe_id
        WHERE s.module_id = ? AND s.statut = 'termine' AND m.type = 'efm'
        ORDER BY s.nom, s.prenom
    ");
    $stmt->execute([$moduleId]);
    $sessions     = $stmt->fetchAll();
    $redirectBack = "results.php?module_id=$moduleId";
    if (empty($sessions)) { header("Location: $redirectBack"); exit; }
    $moduleNom = $sessions[0]['module_nom'];
} else {
    http_response_code(400);
    exit('<p style="color:red;padding:20px">Paramètre manquant. <a href="results.php">Retour</a></p>');
}

// ── Sous-dossier : {nom_module}_{date} ───────────────────────
$slugModule = preg_replace('/[^a-zA-Z0-9]+/', '_', $moduleNom);
$slugModule = trim($slugModule, '_');
$subFolder  = $slugModule . '_' . date('Y-m-d');
$outDir     = $baseDir . DIRECTORY_SEPARATOR . $subFolder;
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
$outDirRel = 'pdfs/efm/' . $subFolder;

// ── Logo base64 ───────────────────────────────────────────────
$logoPath = __DIR__ . '/../assets/img/logo_efm.png';
$logoB64  = file_exists($logoPath)
    ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))
    : '';

// ── CSS partagé (identique à print_efm_result.php) ───────────
$css = '
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; font-size: 10.5pt; color: #000; }

.efm-header { width: 100%; border-collapse: collapse; }
.h-logo { width: 55%; border: 1px solid #000; padding: 4px 8px; vertical-align: middle; }
.h-efm  { border: 1px solid #000; border-top: none; text-align: center;
           padding: 6px 8px; font-size: 13pt; font-style: italic; vertical-align: middle; }
.h-identity { width: 45%; border: 1px solid #000; padding: 5px 8px; vertical-align: top; font-size: 10.5pt; }
.h-code { border: 1px solid #000; border-top: none; text-align: center; padding: 4px 8px;
          font-size: 10.5pt; line-height: 1.7; }

.info-table { width: 100%; border-collapse: collapse; margin-top: -1px; margin-bottom: 8px; }
.info-table td { border: 1px solid #000; padding: 3px 7px; font-size: 10.5pt; vertical-align: middle; }
.lbl  { font-weight: bold; white-space: nowrap; }
.sep  { text-align: center; width: 14px; }

.questions-table { width: 100%; border-collapse: collapse; font-size: 10.5pt; }
.questions-table thead th {
    background: #000; color: #fff; padding: 4px 8px;
    border: 1px solid #000; font-size: 10pt; text-align: center;
}
.th-q { text-align: left !important; }
.questions-table tbody td { border: 1px solid #aaa; padding: 5px 8px; vertical-align: top; }
.col-note { width: 58px; text-align: center; font-weight: bold;
            white-space: nowrap; vertical-align: middle; }
.pts-max  { font-weight: normal; font-size: 9pt; color: #555; }
.q-texte  { font-weight: normal; margin-bottom: 4px; }
.q-reponse { margin-left: 6px; border-bottom: 1px solid #444;
             min-height: 17px; padding: 1px 3px; font-style: italic; }
.q-reponse.vide { color: #bbb; font-style: normal; }

.sig-table { width: 100%; border-collapse: collapse; border: 1px solid #000; margin-top: 16px; }
.sig-table td { border: 1px solid #000; text-align: center; padding: 7px;
                height: 50px; vertical-align: top; font-size: 10pt; font-weight: bold; }
';

// ── Fonction : HTML d'une fiche EFM ──────────────────────────
function buildEfmHtml(array $session, array $questions, string $logoB64): string
{
    $meta     = json_decode($session['meta_json'] ?? '{}', true) ?? [];
    $noteMax  = (int)($session['note_max'] ?? 40);
    $total    = (float)$session['total_points'];
    $scoreRaw = (float)$session['score'];
    $noteFinale = $total > 0 ? round($scoreRaw / $total * $noteMax, 2) : 0;

    $dureeMin = (int)($session['duree_minutes'] ?? 0);
    $duree    = $dureeMin >= 60
        ? floor($dureeMin/60) . 'h' . ($dureeMin%60>0 ? sprintf('%02d',$dureeMin%60) : '')
        : ($dureeMin > 0 ? $dureeMin.' min' : '');

    $nom           = htmlspecialchars(strtoupper(trim($session['nom']    ?? '')), ENT_QUOTES, 'UTF-8');
    $prenom        = htmlspecialchars(trim($session['prenom'] ?? ''),              ENT_QUOTES, 'UTF-8');
    $groupe        = htmlspecialchars(trim($session['groupe_nom'] ?? ''),          ENT_QUOTES, 'UTF-8');
    $etablissement = htmlspecialchars($meta['etablissement'] ?? '',                ENT_QUOTES, 'UTF-8');
    $codeModule    = htmlspecialchars($meta['code_module']   ?? '',                ENT_QUOTES, 'UTF-8');
    $filiere       = htmlspecialchars($meta['filiere']       ?? '',                ENT_QUOTES, 'UTF-8');
    $annee         = htmlspecialchars($meta['annee']         ?? '',                ENT_QUOTES, 'UTF-8');
    $intitule      = htmlspecialchars($session['module_nom'] ?? '',                ENT_QUOTES, 'UTF-8');
    $dureeHtml     = htmlspecialchars($duree,                                      ENT_QUOTES, 'UTF-8');

    $logoImg = $logoB64
        ? '<img src="' . $logoB64 . '" style="height:36px" alt="OFPPT">'
        : '';

    // Lignes questions
    $qRows = '';
    foreach ($questions as $idx => $q) {
        $pts    = (float)$q['points_obtenus'];
        $ptsMax = (float)$q['points_max'];
        $reponse = ($q['type'] === 'texte_libre')
            ? trim($q['reponse_texte'])
            : ($q['choix_texte'] ?? '');
        $vide        = ($reponse === '');
        $reponseHtml = $vide
            ? '<div class="q-reponse vide">&nbsp;</div>'
            : '<div class="q-reponse">' . htmlspecialchars($reponse, ENT_QUOTES, 'UTF-8') . '</div>';
        $bg = ($idx % 2 === 1) ? 'background:#f9f9f9' : '';

        $qRows .= '
        <tr style="' . $bg . '">
            <td class="col-note">
                ' . number_format($pts, 1) . '
                <br><span class="pts-max">/ ' . number_format($ptsMax, 1) . '</span>
            </td>
            <td>
                <div class="q-texte"><strong>Q' . ($idx+1) . '.</strong> '
                . htmlspecialchars($q['question_texte'], ENT_QUOTES, 'UTF-8') . '</div>
                ' . $reponseHtml . '
            </td>
        </tr>';
    }

    $logoCell = $logoImg
        ? '<td style="width:52px;vertical-align:middle;padding-right:8px">' . $logoImg . '</td>'
        : '';

    return '
    <!-- EN-TÊTE OFFICIEL OFPPT -->
    <table class="efm-header">
        <tr>
            <td class="h-logo">
                <table style="width:100%;border-collapse:collapse"><tr>
                    ' . $logoCell . '
                    <td style="vertical-align:middle;text-align:center">
                        <div style="font-weight:bold;font-size:10pt;line-height:1.6">Direction Régionale</div>
                        <div style="font-weight:bold;font-size:10pt;line-height:1.6">ISTA HAY RIAD RABAT</div>
                    </td>
                </tr></table>
            </td>
            <td rowspan="2" class="h-identity">
                <div style="margin-bottom:4px;font-weight:bold"><span class="lbl">Nom :</span> ' . $nom . '</div>
                <div style="margin-bottom:4px;font-weight:bold"><span class="lbl">Prénom :</span> ' . $prenom . '</div>
                <div style="margin-bottom:4px;font-weight:bold"><span class="lbl">Groupe :</span> ' . $groupe . '</div>
                <div style="font-weight:bold"><span class="lbl">Etablissement :</span> ' . $etablissement . '</div>
            </td>
        </tr>
        <tr>
            <td class="h-efm">Évaluation de Fin de Module</td>
        </tr>
        <tr>
            <td colspan="2" class="h-code">
                <div>Code module : ' . $codeModule . '</div>
                <div>' . $intitule . '</div>
            </td>
        </tr>
    </table>

    <!-- FILIÈRE / DURÉE / ANNÉE / NOTE -->
    <table class="info-table">
        <tr>
            <td class="lbl" style="width:13%">Filière</td>
            <td class="sep">:</td>
            <td style="width:47%">' . $filiere . '</td>
            <td class="lbl" style="width:18%">Durée</td>
            <td style="width:17%;text-align:center">: ' . $dureeHtml . '</td>
        </tr>
        <tr>
            <td class="lbl">Année</td>
            <td class="sep">:</td>
            <td>' . $annee . '</td>
            <td class="lbl">Note finale</td>
            <td style="text-align:center;font-weight:bold">: ' . number_format($noteFinale, 2) . ' / ' . $noteMax . '</td>
        </tr>
    </table>

    <!-- QUESTIONS / RÉPONSES -->
    <table class="questions-table">
        <thead>
            <tr>
                <th style="width:58px">Note</th>
                <th class="th-q">Question / Réponse du stagiaire</th>
            </tr>
        </thead>
        <tbody>' . $qRows . '</tbody>
    </table>';
}

// ── Génération des PDFs ───────────────────────────────────────
$generated = [];
$errors    = [];

foreach ($sessions as $session) {
    try {
        // Récupérer module complet si session partielle (cas module_id)
        if (!isset($session['note_max'])) {
            $mod = getModule((int)$session['module_id']);
            $session['note_max']      = $mod['note_max']      ?? 40;
            $session['duree_minutes'] = $mod['duree_minutes'] ?? 0;
        }

        // Questions + réponses
        $stmtQ = $pdo->prepare("
            SELECT q.id, q.texte AS question_texte, q.type,
                   q.points AS points_max, q.ordre,
                   COALESCE(rs.points_obtenus, 0) AS points_obtenus,
                   COALESCE(rs.reponse_texte, '')  AS reponse_texte,
                   rs.choix_id, cr.texte AS choix_texte
            FROM questions q
            LEFT JOIN reponses_stagiaires rs ON rs.question_id = q.id AND rs.session_id = ?
            LEFT JOIN choix_reponses cr ON cr.id = rs.choix_id
            WHERE q.module_id = ?
            ORDER BY q.ordre, q.id
        ");
        $stmtQ->execute([$session['id'], (int)$session['module_id']]);
        $questions = $stmtQ->fetchAll();

        $bodyHtml = buildEfmHtml($session, $questions, $logoB64);

        $fullHtml = '<!DOCTYPE html><html lang="fr"><head>
            <meta charset="UTF-8">
            <style>' . $css . '</style>
        </head><body>' . $bodyHtml . '</body></html>';

        // Nom du fichier
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_',
            strtoupper(trim($session['nom'] ?? '')) . '_' .
            trim($session['prenom'] ?? '') . '_sess' . $session['id']
        );
        $pdfFile = $outDir . DIRECTORY_SEPARATOR . $safeName . '.pdf';

        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_top'    => 10,
            'margin_bottom' => 12,
            'margin_left'   => 13,
            'margin_right'  => 13,
            'tempDir'       => sys_get_temp_dir(),
        ]);
        $mpdf->SetTitle('EFM — ' . ($session['prenom'] ?? '') . ' ' . ($session['nom'] ?? ''));
        $mpdf->WriteHTML($fullHtml);
        $mpdf->Output($pdfFile, \Mpdf\Output\Destination::FILE);

        $generated[] = [
            'nom'    => trim(($session['prenom'] ?? '') . ' ' . strtoupper($session['nom'] ?? '')),
            'file'   => basename($pdfFile),
            'path'   => $pdfFile,
            'url'    => '../' . $outDirRel . '/' . rawurlencode(basename($pdfFile)),
        ];

    } catch (\Exception $e) {
        $errors[] = ($session['prenom'] ?? '') . ' ' . ($session['nom'] ?? '') . ' : ' . $e->getMessage();
    }
}

// ── 1 seul PDF → téléchargement direct ───────────────────────
if ($sessionId > 0 && count($generated) === 1 && empty($errors)) {
    $file = $generated[0]['path'];
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>PDFs EFM générés</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container py-5" style="max-width:800px">
    <h2 class="h4 fw-bold mb-4">
        <i class="bi bi-file-earmark-pdf text-danger me-2"></i>PDFs EFM générés
    </h2>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <strong>Erreurs :</strong><br>
        <?php foreach ($errors as $e): echo htmlspecialchars($e) . '<br>'; endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($generated)): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle me-2"></i>
        <strong><?= count($generated) ?> PDF<?= count($generated) > 1 ? 's' : '' ?> généré<?= count($generated) > 1 ? 's' : '' ?></strong>
        — dossier : <code><?= htmlspecialchars($outDirRel) ?></code>
    </div>
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="list-group list-group-flush rounded-4">
            <?php foreach ($generated as $g): ?>
            <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                <span><i class="bi bi-file-earmark-pdf text-danger me-2"></i><?= htmlspecialchars($g['nom']) ?></span>
                <a href="<?= htmlspecialchars($g['url']) ?>" class="btn btn-sm btn-outline-danger" download>
                    <i class="bi bi-download me-1"></i>Télécharger
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="d-flex gap-2">
        <a href="<?= htmlspecialchars($redirectBack) ?>" class="btn btn-primary">
            <i class="bi bi-arrow-left me-2"></i>Retour aux résultats
        </a>
        <a href="../<?= htmlspecialchars($outDirRel) ?>/" class="btn btn-outline-secondary" target="_blank">
            <i class="bi bi-folder2-open me-2"></i>Ouvrir le dossier
        </a>
    </div>
</div>
</body>
</html>
