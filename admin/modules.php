<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo    = getDB();
$msg    = '';
$erreur = '';
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

// ── Toggle publication (AJAX POST) ───────────────────────────
if ($action === 'toggle' && $id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->prepare("UPDATE modules SET actif = NOT actif WHERE id = ?")->execute([$id]);
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// ── Suppression unitaire ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete_module'])) {
    $delId = (int)($_POST['delete_id'] ?? 0);
    if ($delId > 0) {
        supprimerModule($delId);
        $msg = "Module et toutes ses données supprimés.";
    }
    $action = 'list';
}
// ── Actions en masse ─────────────────────────────────────────
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $selectedIds = array_map('intval', $_POST['selected_ids'] ?? []);
    if ($selectedIds && $_POST['bulk_action'] === 'delete') {
        foreach ($selectedIds as $sid) supprimerModule($sid);
        $msg = count($selectedIds) . " module(s) supprimé(s).";
    } elseif ($selectedIds && $_POST['bulk_action'] === 'toggle_status') {
        $upd = $pdo->prepare("UPDATE modules SET actif = NOT actif WHERE id = ?");
        foreach ($selectedIds as $sid) $upd->execute([$sid]);
        $msg = count($selectedIds) . " module(s) publié(s)/dépublié(s).";
    }
    $action = 'list';
}
// ── Création / modification ──────────────────────────────────
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code     = strtoupper(trim($_POST['code'] ?? ''));
    $filiere  = strtoupper(trim($_POST['filiere'] ?? ''));
    $intitule = trim($_POST['nom'] ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $actif    = isset($_POST['actif']) ? 1 : 0;

    if (!preg_match('/^[A-Z0-9]{2,20}$/', $code)) {
        $erreur = "Code module invalide (ex. M207, EGTS206).";
    } elseif ($filiere !== '' && !preg_match('/^[A-Z0-9]{1,20}$/', $filiere)) {
        $erreur = "Code filière invalide (ex. IDOCC).";
    } elseif (mb_strlen($intitule) < 2) {
        $erreur = "L'intitulé du module est requis.";
    } else {
        $doublon = $pdo->prepare("SELECT COUNT(*) FROM modules WHERE code = ? AND id <> ?");
        $doublon->execute([$code, $action === 'edit' ? $id : 0]);
        if ($doublon->fetchColumn() > 0) {
            $erreur = "Le code $code existe déjà.";
        } elseif ($action === 'edit' && $id > 0) {
            $pdo->prepare("UPDATE modules SET code = ?, filiere = ?, nom = ?, description = ?, actif = ? WHERE id = ?")
                ->execute([$code, $filiere ?: null, $intitule, $desc, $actif, $id]);
            renommerEvaluationsModule($id);
            $msg = "Module mis à jour (noms des évaluations recalculés).";
            $action = 'list';
        } else {
            $newId = creerModule($code, $filiere, $intitule, $desc, (bool)$actif);
            header("Location: evaluations.php?module_id=$newId&created_module=1");
            exit;
        }
    }
}

$modules    = getAllModules();
$editModule = ($action === 'edit' && $id > 0) ? getModule($id) : null;
if ($action === 'edit' && !$editModule) { header("Location: modules.php"); exit; }
$form = $erreur ? $_POST : ($editModule ?? []);

$filieresConnues = array_unique(array_merge(['ID', 'IDOCC', 'IDOCS', 'IDOSR'],
    $pdo->query("SELECT DISTINCT filiere FROM modules WHERE filiere IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN)));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Modules — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container-fluid py-4 px-4">
    <h2 class="h4 fw-bold mb-4"><i class="bi bi-journal-text me-2 text-primary"></i>Gestion des modules</h2>

    <?php if ($msg): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3">
            <i class="bi bi-check-circle me-2"></i><?= sanitize($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($erreur): ?>
        <div class="alert alert-danger rounded-3"><i class="bi bi-exclamation-triangle me-2"></i><?= sanitize($erreur) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Formulaire -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3 px-4">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-<?= $editModule ? 'pencil' : 'plus-circle' ?> me-2 text-primary"></i>
                        <?= $editModule ? 'Modifier' : 'Nouveau' ?> module
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="modules.php?action=<?= $editModule ? 'edit&id=' . $id : 'add' ?>">
                        <?= csrfField() ?>
                        <div class="row g-2 mb-3">
                            <div class="col-5">
                                <label class="form-label fw-semibold">Filière</label>
                                <input type="text" name="filiere" class="form-control text-uppercase" list="filieres"
                                       placeholder="IDOCC" maxlength="20" value="<?= sanitize($form['filiere'] ?? '') ?>">
                                <datalist id="filieres">
                                    <?php foreach ($filieresConnues as $f): ?><option value="<?= sanitize($f) ?>"><?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="col-7">
                                <label class="form-label fw-semibold">Code module <span class="text-danger">*</span></label>
                                <input type="text" name="code" class="form-control text-uppercase" required
                                       placeholder="M207" maxlength="20" value="<?= sanitize($form['code'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Intitulé <span class="text-danger">*</span></label>
                            <input type="text" name="nom" class="form-control" required
                                   value="<?= sanitize($form['nom'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea name="description" class="form-control" rows="2"><?= sanitize($form['description'] ?? '') ?></textarea>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="actif" class="form-check-input" id="actif"
                                   <?= (!$form || !empty($form['actif'])) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="actif">Publié (ses évaluations actives sont visibles des stagiaires)</label>
                        </div>
                        <div class="form-text mb-3">
                            Nomenclature des évaluations : <code>FILIÈRE-MODULE-CCn (contenu)</code> ou <code>FILIÈRE-MODULE-EFM</code>.
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i><?= $editModule ? 'Mettre à jour' : 'Créer' ?>
                            </button>
                            <?php if ($editModule): ?>
                            <a href="modules.php" class="btn btn-outline-secondary">Annuler</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Liste des modules -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div id="bulkActionBar" class="card-header bg-light border-bottom py-3 px-4" style="display:none">
                    <div class="d-flex gap-2 align-items-center">
                        <span class="text-muted"><span id="bulkCount">0</span> sélectionné(s)</span>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-danger" onclick="bulkSubmit('delete')">
                                <i class="bi bi-trash me-1"></i>Supprimer
                            </button>
                            <button type="button" class="btn btn-outline-success" onclick="bulkSubmit('toggle_status')">
                                <i class="bi bi-arrow-repeat me-1"></i>Publier / dépublier
                            </button>
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" onclick="clearSelection()">Annuler</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4" style="width:40px">
                                    <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll()">
                                </th>
                                <th>Module</th>
                                <th class="text-center">Questions</th>
                                <th class="text-center">Évaluations</th>
                                <th class="text-center">Statut</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($modules)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">Aucun module créé</td></tr>
                        <?php endif; ?>
                        <?php foreach ($modules as $m): ?>
                            <tr>
                                <td class="ps-4">
                                    <input type="checkbox" class="form-check-input module-checkbox" value="<?= $m['id'] ?>" onchange="updateBulkActionBar()">
                                </td>
                                <td>
                                    <div class="fw-semibold">
                                        <span class="badge bg-dark-subtle text-dark border me-1"><?= sanitize(implode('-', array_filter([$m['filiere'], $m['code']])) ?: '—') ?></span>
                                        <?= sanitize($m['nom']) ?>
                                    </div>
                                    <?php if ($m['description']): ?>
                                    <div class="text-muted small"><?= sanitize(mb_strimwidth($m['description'], 0, 80, '…')) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="gestion_questions.php?tab=gerer&module_id=<?= $m['id'] ?>" class="badge bg-primary-subtle text-primary text-decoration-none">
                                        <?= (int)$m['nb_questions'] ?> Q
                                    </a>
                                </td>
                                <td class="text-center">
                                    <a href="evaluations.php?module_id=<?= $m['id'] ?>" class="badge bg-success-subtle text-success text-decoration-none">
                                        <?= (int)$m['nb_evaluations'] ?> éval.
                                    </a>
                                </td>
                                <td class="text-center">
                                    <div class="form-check form-switch d-flex justify-content-center align-items-center gap-2 mb-0">
                                        <input class="form-check-input toggle-actif fs-5" type="checkbox" role="switch"
                                               data-id="<?= $m['id'] ?>" <?= $m['actif'] ? 'checked' : '' ?>>
                                        <span class="badge toggle-label <?= $m['actif'] ? 'bg-success' : 'bg-secondary' ?>" id="label-<?= $m['id'] ?>">
                                            <?= $m['actif'] ? 'Publié' : 'Non publié' ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex gap-1 justify-content-center flex-wrap">
                                        <a href="modules.php?action=edit&id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary rounded-3" title="Modifier">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="evaluations.php?module_id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-success rounded-3" title="Évaluations CC / EFM">
                                            <i class="bi bi-clipboard-check"></i>
                                        </a>
                                        <a href="gestion_questions.php?tab=gerer&module_id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-secondary rounded-3" title="Banque de questions">
                                            <i class="bi bi-list-check"></i>
                                        </a>
                                        <a href="results.php?module_id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-info rounded-3" title="Résultats">
                                            <i class="bi bi-bar-chart-line"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-danger rounded-3" title="Supprimer"
                                                onclick="confirmDeleteModule(<?= $m['id'] ?>, <?= sanitize(json_encode($m['libelle'], JSON_UNESCAPED_UNICODE)) ?>, <?= (int)$m['nb_questions'] ?>, <?= (int)$m['nb_evaluations'] ?>)">
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
        </div>
    </div>
</div>

<!-- Modal suppression module -->
<div class="modal fade" id="deleteModuleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Supprimer le module</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Vous allez supprimer définitivement :</p>
                <ul class="mb-2">
                    <li>Le module <strong id="delModNom"></strong></li>
                    <li id="delModStats" class="text-muted small"></li>
                    <li class="text-danger fw-semibold">Tous les résultats des stagiaires pour ce module</li>
                </ul>
                <p class="text-danger fw-semibold mb-0">Cette action est irréversible.</p>
            </div>
            <div class="modal-footer border-0">
                <form method="POST" action="modules.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="confirm_delete_module" value="1">
                    <input type="hidden" name="delete_id" id="delModId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Supprimer définitivement</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode(csrfToken()) ?>;

document.querySelectorAll('.toggle-actif').forEach(function (toggle) {
    toggle.addEventListener('change', function () {
        const id = this.dataset.id, actif = this.checked;
        const label = document.getElementById('label-' + id);
        const fd = new FormData();
        fd.append('action', 'toggle'); fd.append('id', id); fd.append('csrf_token', CSRF);
        toggle.disabled = true;
        fetch('modules.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(() => {
                label.textContent = actif ? 'Publié' : 'Non publié';
                label.className   = 'badge toggle-label ' + (actif ? 'bg-success' : 'bg-secondary');
            })
            .catch(() => { toggle.checked = !actif; _toast('Erreur lors de la mise à jour.', 'danger'); })
            .finally(() => toggle.disabled = false);
    });
});

function updateBulkActionBar() {
    const checked = document.querySelectorAll('.module-checkbox:checked').length;
    const all     = document.querySelectorAll('.module-checkbox').length;
    document.getElementById('bulkCount').textContent = checked;
    document.getElementById('bulkActionBar').style.display = checked ? 'block' : 'none';
    const selectAll = document.getElementById('selectAll');
    selectAll.checked = all > 0 && checked === all;
    selectAll.indeterminate = checked > 0 && checked < all;
}
function toggleSelectAll() {
    const v = document.getElementById('selectAll').checked;
    document.querySelectorAll('.module-checkbox').forEach(cb => cb.checked = v);
    updateBulkActionBar();
}
function clearSelection() {
    document.querySelectorAll('.module-checkbox').forEach(cb => cb.checked = false);
    updateBulkActionBar();
}
function bulkSubmit(action) {
    const ids = [...document.querySelectorAll('.module-checkbox:checked')].map(cb => cb.value);
    if (!ids.length) return;
    const go = () => {
        const form = document.createElement('form');
        form.method = 'POST';
        const add = (n, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; form.appendChild(i); };
        add('csrf_token', CSRF); add('bulk_action', action);
        ids.forEach(id => add('selected_ids[]', id));
        document.body.appendChild(form);
        form.submit();
    };
    action === 'delete'
        ? confirmAction('Supprimer ' + ids.length + ' module(s) et tous leurs résultats ? Action irréversible.', go)
        : go();
}
function confirmDeleteModule(id, nom, nbQ, nbE) {
    document.getElementById('delModId').value = id;
    document.getElementById('delModNom').textContent = nom;
    document.getElementById('delModStats').textContent = nbQ + ' question(s), ' + nbE + ' évaluation(s)';
    new bootstrap.Modal(document.getElementById('deleteModuleModal')).show();
}
</script>
</body>
</html>
