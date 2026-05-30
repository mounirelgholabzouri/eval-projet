<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminRole(); // Seul l'admin peut gérer les formateurs

$pdo    = getDB();
$msg    = '';
$erreur = '';
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ── Traitement POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    // ── Créer formateur ──────────────────────────────────────────
    if ($postAction === 'creer') {
        $username        = trim($_POST['username'] ?? '');
        $nom             = trim($_POST['nom'] ?? '');
        $password        = $_POST['password'] ?? '';
        $etablissementId = (int)($_POST['etablissement_id'] ?? 0) ?: null;

        if (strlen($username) < 3 || strlen($nom) < 2 || strlen($password) < 6) {
            $erreur = "Identifiant (≥3 car.), nom (≥2 car.) et mot de passe (≥6 car.) obligatoires.";
        } elseif (!adminUsernameUnique($username)) {
            $erreur = "Cet identifiant est déjà utilisé.";
        } else {
            $newId = creerFormateur($username, $nom, $password, $etablissementId);
            $groupeIds = array_map('intval', $_POST['groupes'] ?? []);
            setGroupesFormateur($newId, $groupeIds);
            $msg = "Formateur <strong>" . sanitize($nom) . "</strong> créé.";
        }
        $action = 'list';

    // ── Modifier formateur ───────────────────────────────────────
    } elseif ($postAction === 'modifier') {
        $fid             = (int)($_POST['id'] ?? 0);
        $username        = trim($_POST['username'] ?? '');
        $nom             = trim($_POST['nom'] ?? '');
        $password        = $_POST['password'] ?? '';
        $etablissementId = (int)($_POST['etablissement_id'] ?? 0) ?: null;

        if (!$fid || strlen($username) < 3 || strlen($nom) < 2) {
            $erreur = "Données invalides.";
        } elseif (!adminUsernameUnique($username, $fid)) {
            $erreur = "Cet identifiant est déjà utilisé.";
        } else {
            modifierFormateur($fid, $username, $nom, $password ?: null, $etablissementId);
            $groupeIds = array_map('intval', $_POST['groupes'] ?? []);
            setGroupesFormateur($fid, $groupeIds);
            $msg = "Formateur mis à jour.";
        }
        $action = 'list';

    // ── Supprimer formateur ──────────────────────────────────────
    } elseif ($postAction === 'supprimer') {
        $fid = (int)($_POST['id'] ?? 0);
        if ($fid) {
            supprimerFormateur($fid);
            $msg = "Formateur supprimé.";
        }
        $action = 'list';

    // ── Suppression en masse ──────────────────────────────────────
    } elseif ($postAction === 'bulk_delete') {
        $selectedIds = array_map('intval', $_POST['selected_ids'] ?? []);
        foreach ($selectedIds as $id) {
            supprimerFormateur($id);
        }
        $msg = count($selectedIds) . " formateur(s) supprimé(s).";
        $action = 'list';
    }
}

$formateurs     = getAllFormateurs();
$tousGroupes    = getGroupes();
$etablissements = getAllEtablissements();

// Formateur à éditer
$editFormateur      = null;
$editGroupesAssignes = [];
if ($action === 'edit' && $id > 0) {
    $editFormateur       = getFormateur($id);
    $editGroupesAssignes = $editFormateur ? getGroupesFormateur($id) : [];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Formateurs — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container-fluid py-4 px-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h4 fw-bold mb-0">
            <i class="bi bi-person-badge me-2 text-primary"></i>Gestion des formateurs
        </h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreer">
            <i class="bi bi-person-plus me-2"></i>Nouveau formateur
        </button>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3">
            <i class="bi bi-check-circle me-2"></i><?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($erreur): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3">
            <i class="bi bi-exclamation-triangle me-2"></i><?= sanitize($erreur) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Tableau des formateurs -->
    <div class="card border-0 shadow-sm rounded-4">
        <!-- Barre d'actions en masse -->
        <div id="bulkActionBar" class="card-header bg-light border-bottom py-3 px-4" style="display: none;">
            <div class="d-flex gap-2 align-items-center">
                <span class="text-muted">
                    <span id="bulkCount">0</span> sélectionné(s)
                </span>
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="bulkDeleteFormateurs()">
                    <i class="bi bi-trash me-1"></i>Supprimer
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" onclick="clearSelection()">
                    Annuler
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" style="width: 40px;">
                            <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll()">
                        </th>
                        <th class="ps-4">Formateur</th>
                        <th>Identifiant</th>
                        <th>Établissement</th>
                        <th class="text-center">Modules</th>
                        <th>Groupes assignés</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($formateurs)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Aucun formateur. Créez-en un avec le bouton ci-dessus.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($formateurs as $f): ?>
                    <?php $grps = getGroupesFormateur_Details($f['id']); ?>
                    <tr>
                        <td class="ps-4">
                            <input type="checkbox" class="form-check-input formateur-checkbox" value="<?= $f['id'] ?>" onchange="updateBulkActionBar()">
                        </td>
                        <td class="ps-4 fw-semibold">
                            <i class="bi bi-person-circle me-2 text-primary"></i><?= sanitize($f['nom'] ?: $f['username']) ?>
                        </td>
                        <td class="text-muted small font-monospace"><?= sanitize($f['username']) ?></td>
                        <td>
                            <?php if ($f['etablissement_nom']): ?>
                                <span class="badge bg-info-subtle text-info border border-info-subtle">
                                    <i class="bi bi-building me-1"></i><?= sanitize($f['etablissement_nom']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-info-subtle text-info border border-info-subtle"><?= $f['nb_modules'] ?></span>
                        </td>
                        <td>
                            <?php if (empty($grps)): ?>
                                <span class="text-muted small fst-italic">Aucun groupe</span>
                            <?php else: ?>
                                <?php foreach ($grps as $g): ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle me-1">
                                        <?= sanitize($g['nom']) ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <a href="?action=edit&id=<?= $f['id'] ?>"
                               class="btn btn-sm btn-outline-primary me-1" title="Modifier">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    title="Supprimer"
                                    onclick="confirmerSuppression(<?= $f['id'] ?>, '<?= sanitize($f['nom'] ?: $f['username']) ?>')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Modal Créer formateur ─────────────────────────────────── -->
<div class="modal fade" id="modalCreer" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="creer">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus me-2"></i>Nouveau formateur</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nom complet</label>
                    <input type="text" name="nom" class="form-control" required minlength="2" placeholder="Ex : Mohamed Alami">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Identifiant de connexion</label>
                    <input type="text" name="username" class="form-control" required minlength="3" placeholder="Ex : m.alami">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Mot de passe</label>
                    <input type="password" name="password" class="form-control" required minlength="6">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Établissement</label>
                    <select name="etablissement_id" class="form-select" id="creer_etab" onchange="filtrerGroupesModal('creer_etab','grp_new_')">
                        <option value="">— Non défini —</option>
                        <?php foreach ($etablissements as $e): ?>
                            <option value="<?= $e['id'] ?>"><?= sanitize($e['nom']) ?><?= $e['ville'] ? ' — '.sanitize($e['ville']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Groupes assignés</label>
                    <div class="border rounded-3 p-2" style="max-height:180px;overflow-y:auto">
                        <?php if (empty($tousGroupes)): ?>
                            <p class="text-muted small mb-0">Aucun groupe créé.</p>
                        <?php endif; ?>
                        <?php foreach ($tousGroupes as $g): ?>
                        <div class="form-check grp-new-item" data-etab="<?= (int)($g['etablissement_id'] ?? 0) ?>">
                            <input class="form-check-input" type="checkbox" name="groupes[]"
                                   value="<?= $g['id'] ?>" id="grp_new_<?= $g['id'] ?>">
                            <label class="form-check-label" for="grp_new_<?= $g['id'] ?>">
                                <?= sanitize($g['nom']) ?>
                                <?php if ($g['etablissement_nom']): ?><small class="text-muted">(<?= sanitize($g['etablissement_nom']) ?>)</small><?php endif; ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-2"></i>Créer</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Modal Éditer formateur ────────────────────────────────── -->
<?php if ($editFormateur): ?>
<div class="modal fade" id="modalEditer" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="modifier">
            <input type="hidden" name="id" value="<?= $editFormateur['id'] ?>">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-2"></i>Modifier le formateur</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nom complet</label>
                    <input type="text" name="nom" class="form-control" required minlength="2"
                           value="<?= sanitize($editFormateur['nom']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Identifiant de connexion</label>
                    <input type="text" name="username" class="form-control" required minlength="3"
                           value="<?= sanitize($editFormateur['username']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nouveau mot de passe <small class="text-muted">(laisser vide = inchangé)</small></label>
                    <input type="password" name="password" class="form-control" minlength="6" placeholder="••••••">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Établissement</label>
                    <select name="etablissement_id" class="form-select" id="edit_etab" onchange="filtrerGroupesModal('edit_etab','grp_edit_')">
                        <option value="">— Non défini —</option>
                        <?php foreach ($etablissements as $e): ?>
                            <option value="<?= $e['id'] ?>" <?= ($editFormateur && (int)$editFormateur['etablissement_id'] === $e['id']) ? 'selected' : '' ?>>
                                <?= sanitize($e['nom']) ?><?= $e['ville'] ? ' — '.sanitize($e['ville']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Groupes assignés</label>
                    <div class="border rounded-3 p-2" style="max-height:180px;overflow-y:auto">
                        <?php foreach ($tousGroupes as $g): ?>
                        <div class="form-check grp-edit-item" data-etab="<?= (int)($g['etablissement_id'] ?? 0) ?>">
                            <input class="form-check-input" type="checkbox" name="groupes[]"
                                   value="<?= $g['id'] ?>" id="grp_edit_<?= $g['id'] ?>"
                                   <?= in_array($g['id'], $editGroupesAssignes) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="grp_edit_<?= $g['id'] ?>">
                                <?= sanitize($g['nom']) ?>
                                <?php if ($g['etablissement_nom']): ?><small class="text-muted">(<?= sanitize($g['etablissement_nom']) ?>)</small><?php endif; ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="formateurs.php" class="btn btn-secondary">Annuler</a>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-2"></i>Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('modalEditer')).show();
});
</script>
<?php endif; ?>

<!-- ── Form suppression (caché) ────────────────────────────── -->
<form id="formSupprimer" method="POST" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="supprimer">
    <input type="hidden" name="id" id="supprimerId">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function filtrerGroupesModal(selectId, itemClass) {
    const etabId = document.getElementById(selectId)?.value || '';
    document.querySelectorAll('.' + itemClass + 'item').forEach(item => {
        if (!etabId) {
            item.style.display = '';
        } else {
            item.style.display = (item.dataset.etab === etabId) ? '' : 'none';
        }
    });
}

// Appliquer filtres au chargement (modal éditer)
document.addEventListener('DOMContentLoaded', () => {
    const editEtab = document.getElementById('edit_etab');
    if (editEtab?.value) filtrerGroupesModal('edit_etab', 'grp-edit-');
});

function confirmerSuppression(id, nom) {
    if (confirm('Supprimer le formateur "' + nom + '" ?\nSes modules seront conservés (non assignés).')) {
        document.getElementById('supprimerId').value = id;
        document.getElementById('formSupprimer').submit();
    }
}

// ── Fonctions sélection multiple ─────────────────────────────
function updateBulkActionBar() {
    const checkboxes = document.querySelectorAll('.formateur-checkbox:checked');
    const bar = document.getElementById('bulkActionBar');
    const count = document.getElementById('bulkCount');
    const selectAll = document.getElementById('selectAll');

    count.textContent = checkboxes.length;
    bar.style.display = checkboxes.length > 0 ? 'block' : 'none';

    const allCheckboxes = document.querySelectorAll('.formateur-checkbox');
    selectAll.checked = allCheckboxes.length > 0 && checkboxes.length === allCheckboxes.length;
    selectAll.indeterminate = checkboxes.length > 0 && checkboxes.length < allCheckboxes.length;
}

function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    document.querySelectorAll('.formateur-checkbox').forEach(cb => {
        cb.checked = selectAll.checked;
    });
    updateBulkActionBar();
}

function clearSelection() {
    document.querySelectorAll('.formateur-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateBulkActionBar();
}

function bulkDeleteFormateurs() {
    const checkboxes = document.querySelectorAll('.formateur-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Sélectionnez au moins un formateur.');
        return;
    }
    if (confirm('Êtes-vous sûr de vouloir supprimer ' + checkboxes.length + ' formateur(s) ?\nLeurs modules seront conservés (non assignés).')) {
        const form = document.createElement('form');
        form.method = 'POST';
        const csrfInput = document.createElement('input'); csrfInput.type = 'hidden'; csrfInput.name = 'csrf_token'; csrfInput.value = document.querySelector('input[name="csrf_token"]')?.value ?? ''; form.appendChild(csrfInput);
        const input1 = document.createElement('input');
        input1.type = 'hidden';
        input1.name = 'action';
        input1.value = 'bulk_delete';
        form.appendChild(input1);

        checkboxes.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_ids[]';
            input.value = cb.value;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
    }
}
</script>
</body>
</html>
