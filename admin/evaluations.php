<?php
/**
 * evaluations.php — Création et gestion des évaluations CC / EFM d'un module.
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo      = getDB();
$msg      = '';
$erreur   = '';
$moduleId = (int)($_GET['module_id'] ?? $_POST['module_id'] ?? 0);
$evalId   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$action   = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Activation (AJAX) ────────────────────────────────────────
if ($action === 'toggle' && $evalId > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->prepare("UPDATE evaluations SET actif = NOT actif WHERE id = ?")->execute([$evalId]);
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// ── Suppression ──────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST' && $evalId > 0) {
    $e = getEvaluation($evalId);
    if ($e) {
        supprimerEvaluation($evalId);
        header("Location: evaluations.php?module_id={$e['module_id']}&deleted=1");
        exit;
    }
}

// ── Enregistrement ───────────────────────────────────────────
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $existante = $evalId ? getEvaluation($evalId) : null;
    $meta = $existante ? (json_decode($existante['meta_json'] ?? '{}', true) ?: []) : [];
    foreach (['etablissement', 'annee'] as $k) {
        $v = trim($_POST[$k] ?? '');
        if ($v !== '') $meta[$k] = $v; else unset($meta[$k]);
    }
    $d = [
        'module_id'     => $moduleId,
        'type'          => $_POST['type'] ?? 'cc',
        'mode'          => $_POST['mode'] ?? 'theorique',
        'numero'        => $_POST['numero'] ?? null,
        'contenu'       => $_POST['contenu'] ?? '',
        'duree_minutes' => $_POST['duree_minutes'] ?? null,
        'note_max'      => $_POST['note_max'] ?? null,
        'nb_questions'  => $_POST['nb_questions'] ?? null,
        'meta'          => $meta ?: null,
        'actif'         => isset($_POST['actif']),
    ];
    $estPratique = $d['type'] === 'cc' && $d['mode'] === 'pratique';
    if (!$estPratique) $d['question_ids'] = $_POST['q'] ?? [];

    if (!getModule($moduleId)) {
        $erreur = "Choisissez un module.";
    } elseif ($d['type'] === 'cc' && (int)$d['numero'] < 1) {
        $erreur = "Indiquez le numéro du CC.";
    } else {
        try {
            if ($existante) {
                modifierEvaluation($evalId, $d);
            } else {
                $evalId = creerEvaluation($d);
            }
            header("Location: evaluations.php?module_id=$moduleId&saved=$evalId");
            exit;
        } catch (InvalidArgumentException $ex) {
            $erreur = $ex->getMessage();
        }
    }
}

if (isset($_GET['saved']))          $msg = "Évaluation enregistrée.";
if (isset($_GET['deleted']))        $msg = "Évaluation supprimée.";
if (isset($_GET['created_module'])) $msg = "Module créé. Ajoutez maintenant ses évaluations CC / EFM.";

// Formulaire : édition, nouvelle évaluation ou ré-affichage après erreur
$showForm = $action === 'new' || $action === 'edit' || $erreur;
$edit     = ($action === 'edit' && $evalId) ? getEvaluation($evalId) : null;
if ($edit) $moduleId = (int)$edit['module_id'];

$modules = getAllModules();
$module  = $moduleId ? getModule($moduleId) : null;
$evals   = $module ? getEvaluationsModule($moduleId) : [];
$f = $erreur ? array_merge($_POST, ['meta' => ['etablissement' => $_POST['etablissement'] ?? '', 'annee' => $_POST['annee'] ?? '']])
             : ($edit ? $edit + ['meta' => json_decode($edit['meta_json'] ?? '{}', true) ?: []]
                      : ['type' => $_GET['type'] ?? 'cc', 'mode' => 'theorique', 'actif' => 1]);
$fType = ($f['type'] ?? 'cc') === 'efm' ? 'efm' : 'cc';
$fMode = ($f['mode'] ?? 'theorique') === 'pratique' ? 'pratique' : 'theorique';
if (!$edit && !$erreur && $module && $fType === 'cc') {
    $maxNum = 0;
    foreach ($evals as $e) if ($e['type'] === 'cc' && $e['mode'] === 'theorique') $maxNum = max($maxNum, (int)$e['numero']);
    $f['numero'] = $maxNum + 1;
}

$selection = [];
$banque    = [];
if ($showForm) {
    $selection = $erreur ? array_map('intval', $_POST['q'] ?? []) : ($edit ? getQuestionIdsEvaluation($evalId) : []);
    $banque = $pdo->query("SELECT q.id, q.module_id, q.type, q.points, LEFT(q.texte, 220) AS texte
                           FROM questions q ORDER BY q.module_id, q.ordre, q.id")->fetchAll();
}
$selSet = array_flip($selection);
$typeLabels = ['qcm' => 'QCM', 'vrai_faux' => 'V/F', 'texte_libre' => 'Libre', 'multiple' => 'Multiple', 'glisser_deposer' => 'Glisser'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Évaluations CC / EFM — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .q-picker { max-height: 460px; overflow-y: auto; }
        .q-picker label { cursor: pointer; }
        .q-row.d-none-filter { display: none !important; }
    </style>
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container-fluid py-4 px-4">

    <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
        <h2 class="h4 fw-bold mb-0"><i class="bi bi-clipboard-check me-2 text-primary"></i>Évaluations CC / EFM</h2>
        <form method="GET" class="ms-auto d-flex gap-2">
            <select name="module_id" class="form-select" onchange="this.form.submit()" style="min-width:320px">
                <option value="">— Choisir un module —</option>
                <?php foreach ($modules as $m): ?>
                <option value="<?= $m['id'] ?>" <?= $m['id'] == $moduleId ? 'selected' : '' ?>><?= sanitize($m['libelle']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3">
            <i class="bi bi-check-circle me-2"></i><?= sanitize($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($erreur): ?>
        <div class="alert alert-danger rounded-3"><i class="bi bi-exclamation-triangle me-2"></i><?= sanitize($erreur) ?></div>
    <?php endif; ?>

    <?php if (!$module): ?>
        <div class="alert alert-info rounded-3">
            <i class="bi bi-info-circle me-2"></i>Sélectionnez un module, ou <a href="modules.php">créez-en un</a>.
        </div>
    <?php else: ?>

    <!-- Liste des évaluations du module -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white border-0 py-3 px-4 d-flex align-items-center gap-2 flex-wrap">
            <h5 class="mb-0 fw-bold me-auto"><?= sanitize($module['libelle']) ?></h5>
            <a href="evaluations.php?module_id=<?= $moduleId ?>&action=new&type=cc" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-circle me-1"></i>Nouveau CC
            </a>
            <a href="evaluations.php?module_id=<?= $moduleId ?>&action=new&type=efm" class="btn btn-danger btn-sm">
                <i class="bi bi-plus-circle me-1"></i>Nouvel EFM
            </a>
            <a href="gestion_questions.php?tab=gerer&module_id=<?= $moduleId ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-list-check me-1"></i>Banque (<?= (int)$module['nb_questions'] ?> Q)
            </a>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Évaluation</th>
                        <th class="text-center">Type</th>
                        <th class="text-center">Questions</th>
                        <th class="text-center">Durée</th>
                        <th class="text-center">Note</th>
                        <th class="text-center">Sessions</th>
                        <th class="text-center">Active</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$evals): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Aucune évaluation pour ce module.</td></tr>
                <?php endif; ?>
                <?php foreach ($evals as $e):
                    $pratique = $e['mode'] === 'pratique'; ?>
                    <tr>
                        <td class="ps-4 fw-semibold"><?= sanitize($e['nom']) ?></td>
                        <td class="text-center">
                            <?php if ($e['type'] === 'efm'): ?>
                                <span class="badge bg-danger">EFM</span>
                            <?php else: ?>
                                <span class="badge bg-primary">CC<?= (int)$e['numero'] ?></span>
                                <span class="badge bg-<?= $pratique ? 'warning text-dark' : 'info text-dark' ?>"><?= $pratique ? 'Pratique' : 'Théorique' ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($pratique): ?>—<?php else: ?>
                                <?= (int)$e['nb_questions_posees'] ?><?php if ($e['nb_questions']): ?><span class="text-muted small"> / <?= (int)$e['nb_questions_total'] ?> (tirage)</span><?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-center small"><?= $pratique ? '—' : (int)$e['duree_minutes'] . ' min' ?></td>
                        <td class="text-center small">/<?= (int)$e['note_max'] ?></td>
                        <td class="text-center"><span class="badge bg-light text-dark border"><?= (int)$e['nb_sessions'] ?></span></td>
                        <td class="text-center">
                            <?php if (!$pratique): ?>
                            <div class="form-check form-switch d-flex justify-content-center mb-0">
                                <input class="form-check-input toggle-eval" type="checkbox" role="switch" data-id="<?= $e['id'] ?>" <?= $e['actif'] ? 'checked' : '' ?>>
                            </div>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="d-flex gap-1 justify-content-center">
                                <a href="evaluations.php?action=edit&id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-primary" title="Modifier"><i class="bi bi-pencil"></i></a>
                                <?php if ($e['type'] === 'efm'): ?>
                                    <a href="print_efm.php?evaluation_id=<?= $e['id'] ?>" target="_blank" class="btn btn-sm btn-outline-danger" title="Imprimer le sujet EFM"><i class="bi bi-printer"></i></a>
                                <?php elseif (!$pratique): ?>
                                    <a href="print_blank.php?evaluation_id=<?= $e['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Imprimer le sujet"><i class="bi bi-printer"></i></a>
                                <?php endif; ?>
                                <?php if (!$pratique && $e['nb_questions_total'] > 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" title="Rééquilibrer les points sur /<?= (int)$e['note_max'] ?>"
                                            onclick="reequilibrer(<?= $e['id'] ?>, <?= (int)$e['note_max'] ?>, this)"><i class="bi bi-distribute-vertical"></i></button>
                                <?php endif; ?>
                                <?php if ($pratique): ?>
                                    <?php $gid = (int)(json_decode($e['meta_json'] ?? '{}', true)['groupe_id'] ?? 0); ?>
                                    <a href="gestion_impression.php?type=cc<?= min(2, max(1, (int)$e['numero'])) ?>_pratique&module_id=<?= $moduleId ?><?= $gid ? '&groupe_id=' . $gid : '' ?>" class="btn btn-sm btn-outline-warning" title="Saisir les notes"><i class="bi bi-grid-3x3-gap"></i></a>
                                <?php endif; ?>
                                <?php if (!$pratique): ?>
                                    <a href="preview_eval.php?id=<?= $e['id'] ?>" target="_blank" class="btn btn-sm btn-outline-warning" title="Prévisualiser comme stagiaire"><i class="bi bi-eye"></i></a>
                                <?php endif; ?>
                                <a href="results.php?evaluation_id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-info" title="Résultats"><i class="bi bi-bar-chart-line"></i></a>
                                <button type="button" class="btn btn-sm btn-outline-danger" title="Supprimer"
                                        onclick="supprimer(<?= $e['id'] ?>, <?= sanitize(json_encode($e['nom'], JSON_UNESCAPED_UNICODE)) ?>, <?= (int)$e['nb_sessions'] ?>)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($showForm && $module): ?>
    <!-- Formulaire évaluation -->
    <form method="POST" action="evaluations.php" id="evalForm" class="card border-0 shadow-sm rounded-4">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
        <div class="card-header bg-white border-0 py-3 px-4">
            <h5 class="mb-1 fw-bold"><?= $edit ? 'Modifier l’évaluation' : 'Nouvelle évaluation' ?></h5>
            <div class="small text-muted">Nom généré : <code id="nomApercu"></code></div>
        </div>
        <div class="card-body p-4">
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Module</label>
                        <select name="module_id" id="fModule" class="form-select">
                            <?php foreach ($modules as $m): ?>
                            <option value="<?= $m['id'] ?>" data-prefix="<?= sanitize(implode('-', array_filter([$m['filiere'], $m['code']]))) ?>"
                                <?= $m['id'] == $moduleId ? 'selected' : '' ?>><?= sanitize($m['libelle']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold d-block">Type</label>
                        <div class="btn-group w-100">
                            <input type="radio" class="btn-check" name="type" value="cc" id="tCC" <?= $fType === 'cc' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary" for="tCC">CC — Contrôle continu</label>
                            <input type="radio" class="btn-check" name="type" value="efm" id="tEFM" <?= $fType === 'efm' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-danger" for="tEFM">EFM</label>
                        </div>
                    </div>
                    <div class="row g-2 mb-3 cc-only">
                        <div class="col-7">
                            <label class="form-label fw-semibold">Mode</label>
                            <select name="mode" id="fMode" class="form-select">
                                <option value="theorique" <?= $fMode === 'theorique' ? 'selected' : '' ?>>Théorique (en ligne)</option>
                                <option value="pratique"  <?= $fMode === 'pratique'  ? 'selected' : '' ?>>Pratique (grille)</option>
                            </select>
                        </div>
                        <div class="col-5">
                            <label class="form-label fw-semibold">N° CC</label>
                            <input type="number" name="numero" id="fNumero" class="form-control" min="1" max="9" value="<?= (int)($f['numero'] ?? 1) ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Contenu <span class="text-muted fw-normal small">(thème du CC, variante d'EFM)</span></label>
                        <input type="text" name="contenu" id="fContenu" class="form-control" maxlength="255"
                               placeholder="ex. Multi-Cloud et réplication Azure" value="<?= sanitize($f['contenu'] ?? '') ?>">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-4">
                            <label class="form-label fw-semibold">Durée</label>
                            <div class="input-group">
                                <input type="number" name="duree_minutes" class="form-control" min="5" max="300"
                                       value="<?= (int)($f['duree_minutes'] ?? ($fType === 'efm' ? 120 : 30)) ?>">
                                <span class="input-group-text">min</span>
                            </div>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold">Note sur</label>
                            <select name="note_max" class="form-select">
                                <?php $nm = (int)($f['note_max'] ?? ($fType === 'efm' ? 40 : 20)); ?>
                                <option value="20" <?= $nm === 20 ? 'selected' : '' ?>>20</option>
                                <option value="40" <?= $nm === 40 ? 'selected' : '' ?>>40</option>
                            </select>
                        </div>
                        <div class="col-4 theo-only">
                            <label class="form-label fw-semibold">Tirage</label>
                            <input type="number" name="nb_questions" class="form-control" min="1" placeholder="toutes"
                                   value="<?= !empty($f['nb_questions']) ? (int)$f['nb_questions'] : '' ?>">
                        </div>
                    </div>
                    <div class="row g-2 mb-3 efm-only">
                        <div class="col-7">
                            <label class="form-label fw-semibold">Établissement</label>
                            <input type="text" name="etablissement" class="form-control" value="<?= sanitize($f['meta']['etablissement'] ?? '') ?>">
                        </div>
                        <div class="col-5">
                            <label class="form-label fw-semibold">Année</label>
                            <input type="text" name="annee" class="form-control" placeholder="<?= sanitize(getAnneeFormation()) ?>" value="<?= sanitize($f['meta']['annee'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-check mb-3 theo-only">
                        <input type="checkbox" name="actif" class="form-check-input" id="fActif" <?= !empty($f['actif']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="fActif">Active (proposée aux stagiaires si le module est publié)</label>
                    </div>
                    <div class="alert alert-warning small py-2 pratique-only">
                        <i class="bi bi-info-circle me-1"></i>CC pratique : les notes sont saisies dans <strong>Gestion CC/EFM</strong>.
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Enregistrer</button>
                        <a href="evaluations.php?module_id=<?= $moduleId ?>" class="btn btn-outline-secondary">Annuler</a>
                    </div>
                </div>

                <!-- Sélection des questions -->
                <div class="col-lg-8 theo-only">
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                        <strong class="me-2">Questions</strong>
                        <span class="badge bg-primary" id="qCount">0</span>
                        <span class="small text-muted">sélectionnée(s) — <span id="qPts">0</span> pts</span>
                        <select id="qSource" class="form-select form-select-sm ms-auto" style="max-width:260px">
                            <?php foreach ($modules as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= $m['id'] == $moduleId ? 'selected' : '' ?>><?= sanitize(implode('-', array_filter([$m['filiere'], $m['code']])) ?: $m['nom']) ?> (<?= (int)$m['nb_questions'] ?>)</option>
                            <?php endforeach; ?>
                            <option value="sel">Sélection actuelle</option>
                        </select>
                        <input type="search" id="qFiltre" class="form-control form-control-sm" placeholder="Filtrer…" style="max-width:180px">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="cocher(true)">Tout</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cocher(false)">Aucune</button>
                    </div>
                    <div class="q-picker border rounded-3 bg-white">
                        <?php foreach ($banque as $q): ?>
                        <label class="q-row d-flex gap-2 px-3 py-2 border-bottom small" data-module="<?= (int)$q['module_id'] ?>">
                            <input type="checkbox" class="form-check-input mt-1 q-check" name="q[]" value="<?= (int)$q['id'] ?>"
                                   data-pts="<?= (float)$q['points'] ?>" <?= isset($selSet[$q['id']]) ? 'checked' : '' ?>>
                            <span class="badge bg-light text-dark border align-self-start"><?= $typeLabels[$q['type']] ?? $q['type'] ?></span>
                            <span class="flex-grow-1 q-texte"><?= sanitize(strtok($q['texte'], "\n")) ?></span>
                            <span class="text-muted text-nowrap"><?= rtrim(rtrim(number_format((float)$q['points'], 2, ',', ''), '0'), ',') ?> pt</span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text">Les questions d'autres modules peuvent être ajoutées (ex. EFM couvrant plusieurs modules). Les sessions déjà passées ne sont pas modifiées.</div>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<form method="POST" action="evaluations.php" id="deleteForm" class="d-none">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode(csrfToken()) ?>;

document.querySelectorAll('.toggle-eval').forEach(t => t.addEventListener('change', function () {
    const fd = new FormData();
    fd.append('action', 'toggle'); fd.append('id', this.dataset.id); fd.append('csrf_token', CSRF);
    this.disabled = true;
    fetch('evaluations.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .catch(() => { this.checked = !this.checked; _toast('Erreur lors de la mise à jour.', 'danger'); })
        .finally(() => this.disabled = false);
}));

function reequilibrer(id, noteMax, btn) {
    confirmAction('Répartir ' + noteMax + ' points entre les questions de cette évaluation (les questions partagées avec d\'autres évaluations seront aussi modifiées) ?', async () => {
        btn.disabled = true;
        const fd = new FormData();
        fd.append('evaluation_id', id); fd.append('csrf_token', CSRF);
        try {
            const data = await (await fetch('api_redistribute_points.php', { method: 'POST', body: fd })).json();
            if (data.error) throw new Error(data.error);
            _toast('Points redistribués : ' + data.desc + ' = ' + noteMax + ' pts', 'success');
        } catch (e) {
            _toast('Erreur : ' + e.message, 'danger');
        }
        btn.disabled = false;
    });
}

function supprimer(id, nom, nbSessions) {
    const txt = 'Supprimer « ' + nom + ' »' + (nbSessions ? ' et ses ' + nbSessions + ' session(s) de stagiaires' : '') + ' ? Action irréversible.';
    confirmAction(txt, () => { document.getElementById('deleteId').value = id; document.getElementById('deleteForm').submit(); });
}

const form = document.getElementById('evalForm');
if (form) {
    const $ = s => form.querySelector(s);
    const show = (sel, on) => form.querySelectorAll(sel).forEach(el => el.style.display = on ? '' : 'none');

    function majForm() {
        const efm = $('#tEFM').checked;
        const pratique = !efm && $('#fMode').value === 'pratique';
        show('.cc-only', !efm);
        show('.efm-only', efm);
        show('.theo-only', !pratique);
        show('.pratique-only', pratique);
        const prefix = $('#fModule').selectedOptions[0].dataset.prefix;
        const details = [pratique ? 'Pratique' : '', $('#fContenu').value.trim()].filter(Boolean);
        $('#nomApercu').textContent = (prefix ? prefix + '-' : '') + (efm ? 'EFM' : 'CC' + ($('#fNumero').value || ''))
            + (details.length ? ' (' + details.join(' — ') + ')' : '');
    }
    function majCompteur() {
        const sel = [...form.querySelectorAll('.q-check:checked')];
        $('#qCount').textContent = sel.length;
        $('#qPts').textContent = sel.reduce((s, c) => s + parseFloat(c.dataset.pts), 0).toLocaleString('fr-FR');
    }
    function filtrer() {
        const src = $('#qSource').value, txt = $('#qFiltre').value.toLowerCase();
        form.querySelectorAll('.q-row').forEach(row => {
            const okSrc = src === 'sel' ? row.querySelector('.q-check').checked : row.dataset.module === src;
            const okTxt = !txt || row.querySelector('.q-texte').textContent.toLowerCase().includes(txt);
            row.classList.toggle('d-none-filter', !(okSrc && okTxt));
        });
    }
    window.cocher = v => form.querySelectorAll('.q-row:not(.d-none-filter) .q-check').forEach(c => c.checked = v) || majCompteur();

    form.addEventListener('input', majForm);
    form.addEventListener('change', e => { majForm(); if (e.target.classList.contains('q-check')) majCompteur(); });
    $('#fModule').addEventListener('change', () => { $('#qSource').value = $('#fModule').value; filtrer(); });
    $('#qSource').addEventListener('change', filtrer);
    $('#qFiltre').addEventListener('input', filtrer);
    form.querySelectorAll('input[name=type]').forEach(r => r.addEventListener('change', () => {
        const efm = $('#tEFM').checked;
        form.querySelector('[name=duree_minutes]').value = efm ? 120 : 30;
        form.querySelector('[name=note_max]').value = efm ? 40 : 20;
    }));
    majForm(); majCompteur(); filtrer();
    form.scrollIntoView({ behavior: 'smooth' });
}
</script>
</body>
</html>
