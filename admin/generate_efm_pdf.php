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
        SELECT s.*,
               COALESCE(st.nom,    s.nom)    AS nom,
               COALESCE(st.prenom, s.prenom) AS prenom,
               st.numero_classe,
               m.nom AS module_nom, e.type AS module_type,
               e.note_max, e.duree_minutes,
               e.meta_json AS eval_meta_json,
               COALESCE(g.nom, s.groupe_libre) AS groupe_nom
        FROM sessions_eval s
        JOIN evaluations e  ON e.id  = s.evaluation_id
        JOIN modules     m  ON m.id  = e.module_id
        LEFT JOIN stagiaires st ON st.id = s.stagiaire_id
        LEFT JOIN groupes    g  ON g.id  = s.groupe_id
        WHERE e.module_id = ? AND s.statut = 'termine' AND e.type = 'efm'
        ORDER BY s.nom, s.prenom
    ");
    $stmt->execute([$moduleId]);
    $rawSessions = $stmt->fetchAll();
    // Decode EFM meta for each session
    $sessions = array_map(function($s) {
        $meta = json_decode($s['eval_meta_json'] ?? '{}', true) ?: [];
        $s['efm_code_module']   = $meta['code_module']   ?? '';
        $s['efm_filiere']       = $meta['filiere']       ?? '';
        $s['efm_etablissement'] = $meta['etablissement'] ?? '';
        $s['efm_annee']         = $meta['annee']         ?? '';
        unset($s['eval_meta_json']);
        return $s;
    }, $rawSessions);
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

// ── Tampon OFPPT base64 ───────────────────────────────────────
$tamponPath = __DIR__ . '/../assets/img/tampon_ofppt.png';
$tamponB64  = file_exists($tamponPath)
    ? 'data:image/png;base64,' . base64_encode(file_get_contents($tamponPath))
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
.questions-table thead { display: none; }
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
function buildEfmHtml(array $session, array $questions, string $logoB64, string $tamponB64 = ''): string
{
    $noteMax  = (int)($session['note_max'] ?? 40);
    $total    = (float)$session['total_points'];
    $scoreRaw = (float)$session['score'];
    $noteFinale = arrondiNote($scoreRaw, $total, $noteMax);

    $dureeMin = (int)($session['duree_minutes'] ?? 0);
    $duree    = $dureeMin >= 60
        ? floor($dureeMin/60) . 'h' . ($dureeMin%60>0 ? sprintf('%02d',$dureeMin%60) : '')
        : ($dureeMin > 0 ? $dureeMin.' min' : '');

    $nom           = htmlspecialchars(strtoupper(trim($session['nom']    ?? '')), ENT_QUOTES, 'UTF-8');
    $prenom        = htmlspecialchars(trim($session['prenom'] ?? ''),              ENT_QUOTES, 'UTF-8');
    $groupe        = htmlspecialchars(trim($session['groupe_nom'] ?? ''),          ENT_QUOTES, 'UTF-8');
    $etablissement = htmlspecialchars($session['efm_etablissement'] ?? '',         ENT_QUOTES, 'UTF-8');
    $codeModule    = htmlspecialchars($session['efm_code_module']   ?? '',         ENT_QUOTES, 'UTF-8');
    $filiere       = htmlspecialchars($session['efm_filiere']       ?? '',         ENT_QUOTES, 'UTF-8');
    $annee         = htmlspecialchars($session['efm_annee']         ?? '',         ENT_QUOTES, 'UTF-8');
    $intitule      = htmlspecialchars($session['module_nom'] ?? '',                ENT_QUOTES, 'UTF-8');
    $dureeHtml     = htmlspecialchars($duree,                                      ENT_QUOTES, 'UTF-8');

    $logoImg = $logoB64
        ? '<img src="' . $logoB64 . '" style="height:36px" alt="OFPPT">'
        : '';

    // Lignes questions
    $qRows = '';
    foreach ($questions as $idx => $q) {
        $ptsMaxRaw  = (float)$q['points_max'];
        $ptsMaxScal = $total > 0 ? round($ptsMaxRaw / $total * $noteMax, 2) : 0;
        $choixId    = $q['choix_id'] ? (int)$q['choix_id'] : null;
        $choices    = $q['_choices'] ?? [];
        $bg = ($idx % 2 === 1) ? 'background:#f9f9f9' : '';

        if ($q['type'] === 'texte_libre') {
            $reponseHtml = '<div style="margin-left:6px">'
                . '<div style="border-bottom:1px solid #999;min-height:18px;margin-bottom:4px">&nbsp;</div>'
                . '<div style="border-bottom:1px solid #999;min-height:18px;margin-bottom:4px">&nbsp;</div>'
                . '<div style="border-bottom:1px solid #999;min-height:18px">&nbsp;</div>'
                . '</div>';
        } elseif (!empty($choices)) {
            $reponseHtml = '<div style="margin:3px 0 0 6px;font-size:10pt;line-height:1.7">';
            foreach ($choices as $c) {
                $isCorrect  = (int)$c['is_correct'];
                $isSelected = $choixId !== null && (int)$c['id'] === $choixId;
                $bold   = $isCorrect ? 'font-weight:bold' : '';
                $box    = $isSelected ? '&#9745;' : '&#9744;';
                $marker = $isCorrect ? ' &#10003;' : '';
                $reponseHtml .= '<span style="display:inline-block;' . $bold . ';margin-right:16px;margin-bottom:4px;vertical-align:middle">'
                    . ' ' . $box . $marker . ' '
                    . htmlspecialchars($c['texte'], ENT_QUOTES, 'UTF-8')
                    . '</span>';
            }
            $reponseHtml .= '</div>';
        } else {
            $reponseHtml = '<div class="q-reponse vide">&nbsp;</div>';
        }

        $qRows .= '
        <tr style="' . $bg . '">
            <td class="col-note">
                <span style="display:block;border-bottom:1px solid #000;min-width:36px;">&nbsp;</span>
                <span class="pts-max">/ ' . number_format($ptsMaxScal, 2) . '</span>
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
                        <div style="font-weight:bold;font-size:10pt;line-height:1.6">RABAT-SALÉ-KENITRA</div>
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
            <td class="h-efm">EXAMEN DE FIN DE MODULE</td>
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
            <td class="lbl" style="width:15%">Filière</td>
            <td style="width:45%">' . $filiere . '</td>
            <td class="lbl" style="width:20%">Durée</td>
            <td style="width:20%;text-align:center">: ' . $dureeHtml . '</td>
        </tr>
        <tr>
            <td class="lbl">Année</td>
            <td>' . $annee . '</td>
            <td class="lbl">Note finale</td>
            <td style="text-align:center;font-weight:bold">: <span style="display:inline-block;width:60px;border-bottom:1px solid #000;">&nbsp;</span> / ' . $noteMax . '</td>
        </tr>
    </table>

    <!-- QUESTIONS / RÉPONSES -->
    <table class="questions-table">
        <tbody>' . $qRows . '</tbody>
    </table>';
}

// ── Génération des PDFs ───────────────────────────────────────
$generated = [];
$errors    = [];

foreach ($sessions as $session) {
    try {
        // Récupérer les données évaluation si absentes (cas single session via getSession())
        if (!isset($session['note_max'])) {
            $eval = getEvaluation((int)$session['evaluation_id']);
            $session['note_max']      = $eval['note_max']      ?? 40;
            $session['duree_minutes'] = $eval['duree_minutes'] ?? 0;
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
        // Récupère module_id via l'évaluation de la session
        $evalRow = $pdo->prepare("SELECT module_id FROM evaluations WHERE id = ?");
        $evalRow->execute([$session['evaluation_id']]);
        $evalModuleId = (int)($evalRow->fetchColumn() ?: 0);
        $stmtQ->execute([$session['id'], $evalModuleId]);
        $questions = $stmtQ->fetchAll();

        // Charger tous les choix pour chaque question
        $qIds = array_column($questions, 'id');
        $choicesMap = [];
        if (!empty($qIds)) {
            $in2 = implode(',', array_fill(0, count($qIds), '?'));
            $stmtC = $pdo->prepare("SELECT id, question_id, texte, is_correct, ordre FROM choix_reponses WHERE question_id IN ($in2) ORDER BY question_id, ordre, id");
            $stmtC->execute($qIds);
            foreach ($stmtC->fetchAll() as $c) {
                $choicesMap[(int)$c['question_id']][] = $c;
            }
        }
        foreach ($questions as &$q) {
            $q['_choices'] = $choicesMap[(int)$q['id']] ?? [];
        }
        unset($q);

        $bodyHtml = buildEfmHtml($session, $questions, $logoB64, $tamponB64);

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
            'margin_bottom' => 15,
            'margin_left'   => 13,
            'margin_right'  => 13,
            'tempDir'       => sys_get_temp_dir(),
        ]);
        $mpdf->SetTitle('EFM — ' . ($session['prenom'] ?? '') . ' ' . ($session['nom'] ?? ''));
        if (!empty($session['numero_classe'])) {
            $mpdf->SetHTMLFooter('<div style="font-size:7pt;color:#bbb">' . (int)$session['numero_classe'] . '</div>');
        }

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
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PDFs EFM générés — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container-fluid py-4 px-4" style="max-width:1000px">

    <!-- En-tête -->
    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <a href="<?= htmlspecialchars($redirectBack) ?>" class="btn btn-sm btn-outline-secondary rounded-3">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div>
            <h2 class="h4 fw-bold mb-0">
                <i class="bi bi-file-earmark-pdf text-danger me-2"></i>PDFs EFM générés
            </h2>
            <div class="text-muted small">
                <?= htmlspecialchars($moduleNom) ?>
            </div>
        </div>
        <?php if (!empty($generated)): ?>
        <div class="ms-auto d-flex gap-2">
            <span class="badge bg-success fs-6">
                <i class="bi bi-check-circle me-1"></i><?= count($generated) ?> PDF<?= count($generated) > 1 ? 's' : '' ?>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger rounded-4 mb-4">
        <i class="bi bi-exclamation-triangle me-2"></i><strong><?= count($errors) ?> erreur(s) :</strong><br>
        <?php foreach ($errors as $e): ?>
            <div class="small mt-1"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($generated)): ?>
    <!-- Infos dossier -->
    <div class="alert alert-success rounded-4 mb-3 py-2">
        <i class="bi bi-folder2-open me-2"></i>
        Dossier : <code><?= htmlspecialchars($outDirRel) ?></code>
        <a href="../<?= htmlspecialchars($outDirRel) ?>/" class="ms-3 btn btn-sm btn-outline-success py-0" target="_blank">
            <i class="bi bi-box-arrow-up-right me-1"></i>Ouvrir
        </a>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-3 text-center p-3">
                <div class="h3 fw-bold text-danger mb-0"><?= count($generated) ?></div>
                <div class="text-muted small">PDFs générés</div>
            </div>
        </div>
        <?php if (!empty($errors)): ?>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-3 text-center p-3">
                <div class="h3 fw-bold text-warning mb-0"><?= count($errors) ?></div>
                <div class="text-muted small">Erreurs</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Liste des PDFs -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-light border-bottom px-4 py-3 d-flex align-items-center justify-content-between">
            <span class="fw-semibold"><i class="bi bi-people me-2 text-primary"></i>Fiches générées</span>
            <span class="badge bg-primary rounded-pill"><?= count($generated) ?></span>
        </div>
        <div class="list-group list-group-flush rounded-bottom-4">
            <?php foreach ($generated as $i => $g): ?>
            <div class="list-group-item d-flex justify-content-between align-items-center px-4 py-3">
                <div>
                    <span class="text-muted small me-2"><?= $i + 1 ?>.</span>
                    <i class="bi bi-file-earmark-pdf text-danger me-2"></i>
                    <span class="fw-semibold"><?= htmlspecialchars($g['nom']) ?></span>
                </div>
                <a href="<?= htmlspecialchars($g['url']) ?>" class="btn btn-sm btn-outline-danger rounded-3" download>
                    <i class="bi bi-download me-1"></i>Télécharger
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <a href="<?= htmlspecialchars($redirectBack) ?>" class="btn btn-primary rounded-3">
        <i class="bi bi-arrow-left me-2"></i>Retour aux résultats
    </a>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
