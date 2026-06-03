<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$msg = '';
$erreur = '';
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ── Traitement formulaires ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom      = trim($_POST['nom'] ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $duree    = (int)($_POST['duree_minutes'] ?? 30);
    $noteMax  = in_array((int)($_POST['note_max'] ?? 20), [20, 40]) ? (int)$_POST['note_max'] : 20;
    $actif    = isset($_POST['actif']) ? 1 : 0;
    $nbQCtrl  = trim($_POST['nb_questions_controle'] ?? '') !== '' ? max(1, (int)$_POST['nb_questions_controle']) : null;

    if (strlen($nom) < 2) {
        $erreur = "Le nom du module est requis.";
    } else {
        if ($action === 'edit' && $id > 0) {
            $pdo->prepare("UPDATE modules SET nom=?, description=?, actif=?, nb_questions_controle=? WHERE id=?")
                ->execute([$nom, $desc, $actif, $nbQCtrl, $id]);
            // Met aussi à jour l'évaluation associée
            $pdo->prepare("UPDATE evaluations SET nom=?, duree_minutes=?, note_max=? WHERE module_id=?")
                ->execute([$nom, $duree, $noteMax, $id]);
            $msg = "Module mis à jour avec succès.";
        } else {
            $pdo->prepare("INSERT INTO modules (nom, description, actif, nb_questions_controle) VALUES (?,?,?,?)")
                ->execute([$nom, $desc, $actif, $nbQCtrl]);
            $newId = (int)$pdo->lastInsertId();
            // Crée l'évaluation associée automatiquement
            $pdo->prepare("INSERT INTO evaluations (module_id, nom, type, duree_minutes, note_max) VALUES (?,?,'qcm',?,?)")
                ->execute([$newId, $nom, $duree, $noteMax]);
            // Crée la partie par défaut
            $pdo->prepare("INSERT INTO parties (module_id, nom, ordre) VALUES (?, 'Général', 1)")
                ->execute([$newId]);
            $msg = "Module créé avec succès.";
        }
        $action = 'list';
    }
}

// ── Suppression (POST obligatoire) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete_module'])) {
    $delId = (int)($_POST['delete_id'] ?? 0);
    if ($delId > 0) {
        supprimerModule($delId);
        $msg = "Module et toutes ses données supprimés.";
    }
    $action = 'list';
}

// ── Suppression en masse ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $bulkAction = $_POST['bulk_action'] ?? '';
    $selectedIds = array_map('intval', $_POST['selected_ids'] ?? []);

    if (!empty($selectedIds)) {
        if ($bulkAction === 'delete') {
            foreach ($selectedIds as $id) {
                supprimerModule($id);
            }
            $msg = count($selectedIds) . " module(s) supprimé(s).";
        } elseif ($bulkAction === 'toggle_status') {
            foreach ($selectedIds as $id) {
                $pdo->prepare("UPDATE modules SET actif = NOT actif WHERE id = ?")->execute([$id]);
            }
            $msg = count($selectedIds) . " module(s) activé(s)/désactivé(s).";
        }
    }
    $action = 'list';
}

// ── Toggle actif ─────────────────────────────────────────────
if ($action === 'toggle' && $id > 0) {
    $pdo->prepare("UPDATE modules SET actif = NOT actif WHERE id = ?")->execute([$id]);
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    $action = 'list';
}

$modules = getAllModules();
$editModule = null;
if ($action === 'edit' && $id > 0) {
    $editModule = getModule($id);
    if (!$editModule) {
        header("Location: modules.php");
        exit;
    }
}
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
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($erreur): ?>
        <div class="alert alert-danger rounded-3"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($erreur) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Formulaire -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3 px-4">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-<?= $editModule ? 'pencil' : 'plus-circle' ?> me-2 text-primary"></i>
                        <?= $editModule ? 'Modifier' : 'Nouveau' ?> module
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="modules.php?action=<?= $editModule ? 'edit&id='.$id : 'add' ?>">
                        <?= csrfField() ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Nom du module <span class="text-danger">*</span></label>
                            <input type="text" name="nom" class="form-control" required
                                   value="<?= htmlspecialchars($editModule['nom'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($editModule['description'] ?? '') ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Durée (minutes)</label>
                            <input type="number" name="duree_minutes" class="form-control" min="5" max="300"
                                   value="<?= (int)($editModule['duree_minutes'] ?? 30) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Notation</label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="note_max" value="20"
                                           id="nm20" <?= (!$editModule || $editModule['note_max'] == 20) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="nm20">Sur 20</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="note_max" value="40"
                                           id="nm40" <?= ($editModule && $editModule['note_max'] == 40) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="nm40">Sur 40</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Questions par contrôle</label>
                            <input type="number" name="nb_questions_controle" class="form-control" min="1" max="500"
                                   placeholder="Laisser vide = toutes"
                                   value="<?= isset($editModule['nb_questions_controle']) ? (int)$editModule['nb_questions_controle'] : '' ?>">
                            <div class="form-text">Nombre de questions tirées aléatoirement à chaque lancement. Vide = toutes les questions.</div>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="actif" class="form-check-input" id="actif"
                                   <?= (!$editModule || $editModule['actif']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="actif">Module actif (visible aux stagiaires)</label>
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
        <div class="col-md-8">
            <div class="card border-0 shadow-sm rounded-4">
                <!-- Barre d'actions en masse -->
                <div id="bulkActionBar" class="card-header bg-light border-bottom py-3 px-4" style="display: none;">
                    <div class="d-flex gap-2 align-items-center">
                        <span class="text-muted">
                            <span id="bulkCount">0</span> sélectionné(s)
                        </span>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-danger" onclick="bulkDelete()">
                                <i class="bi bi-trash me-1"></i>Supprimer
                            </button>
                            <button type="button" class="btn btn-outline-success" onclick="bulkToggleStatus()">
                                <i class="bi bi-arrow-repeat me-1"></i>Basculer statut
                            </button>
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" onclick="clearSelection()">
                            Annuler
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4" style="width: 40px;">
                                    <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll()">
                                </th>
                                <th class="ps-4">Module</th>
                                <th class="text-center">Durée</th>
                                <th class="text-center">Questions</th>
                                <th class="text-center">Parties</th>
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
                                <td class="ps-4">
                                    <div class="fw-semibold d-flex align-items-center gap-2 flex-wrap">
                                        <?= htmlspecialchars($m['nom']) ?>
                                        <?php if (($m['type'] ?? 'qcm') === 'efm'): ?>
                                            <span class="badge bg-danger">EFM</span>
                                        <?php elseif (($m['type'] ?? 'qcm') === 'pratique'): ?>
                                            <span class="badge bg-warning text-dark">Pratique</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($m['description']): ?>
                                    <div class="text-muted small"><?= htmlspecialchars(mb_substr($m['description'], 0, 60)) ?>...</div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center text-muted small"><?= $m['duree_minutes'] ?> min</td>
                                <td class="text-center">
                                    <a href="questions.php?module_id=<?= $m['id'] ?>" class="badge bg-primary-subtle text-primary text-decoration-none">
                                        <?= $m['nb_questions'] ?> Q
                                    </a>
                                </td>
                                <td class="text-center">
                                    <a href="questions.php?module_id=<?= $m['id'] ?>"
                                       class="btn btn-sm btn-outline-secondary rounded-pill px-3"
                                       title="Gérer les questions">
                                        <i class="bi bi-question-circle me-1"></i><?= (int)($m['nb_questions'] ?? 0) ?>
                                    </a>
                                </td>
                                <td class="text-center">
                                    <div class="form-check form-switch d-flex justify-content-center align-items-center gap-2 mb-0">
                                        <input class="form-check-input toggle-actif fs-5" type="checkbox"
                                               role="switch"
                                               data-id="<?= $m['id'] ?>"
                                               <?= $m['actif'] ? 'checked' : '' ?>
                                               title="<?= $m['actif'] ? 'Publié — cliquer pour dépublier' : 'Non publié — cliquer pour publier' ?>">
                                        <span class="badge toggle-label <?= $m['actif'] ? 'bg-success' : 'bg-secondary' ?>" id="label-<?= $m['id'] ?>">
                                            <?= $m['actif'] ? 'Publié' : 'Non publié' ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex gap-1 justify-content-center flex-wrap">
                                        <a href="modules.php?action=edit&id=<?= $m['id'] ?>"
                                           class="btn btn-sm btn-outline-primary rounded-3" title="Modifier">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="questions.php?module_id=<?= $m['id'] ?>"
                                           class="btn btn-sm btn-outline-success rounded-3" title="Questions">
                                            <i class="bi bi-list-check"></i>
                                        </a>
                                        <?php if (($m['type'] ?? 'qcm') === 'efm'): ?>
                                        <?php
                                        $efmPrintUrl = 'print_efm.php?' . http_build_query([
                                            'module_id'     => $m['id'],
                                            'etablissement' => $m['efm_etablissement'] ?? '',
                                            'filiere'       => $m['efm_filiere'] ?? '',
                                            'duree'         => ($m['duree_minutes'] ?? 120) . ' min',
                                            'annee'         => $m['efm_annee'] ?? '',
                                            'note_max'      => $m['note_max'] ?? 40,
                                            'code_module'   => $m['efm_code_module'] ?? '',
                                            'intitule'      => $m['nom'],
                                            'shuffle'       => 0, 'shuffle_choix' => 0, 'corrige' => 0,
                                        ]);
                                        ?>
                                        <a href="<?= $efmPrintUrl ?>" target="_blank"
                                           class="btn btn-sm btn-danger rounded-3" title="Imprimer EFM">
                                            <i class="bi bi-file-earmark-ruled"></i>
                                        </a>
                                        <?php else: ?>
                                        <a href="print_blank.php?module_id=<?= $m['id'] ?>"
                                           target="_blank"
                                           class="btn btn-sm btn-outline-secondary rounded-3"
                                           title="Imprimer sujet vierge">
                                            <i class="bi bi-printer"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a href="results.php?module_id=<?= $m['id'] ?>"
                                           class="btn btn-sm btn-outline-info rounded-3"
                                           title="Voir les résultats">
                                            <i class="bi bi-bar-chart-line"></i>
                                        </a>
                                        <a href="delete_module.php?id=<?= $m['id'] ?>"
                                           class="btn btn-sm btn-outline-danger rounded-3"
                                           title="Supprimer (avec options)">
                                            <i class="bi bi-trash"></i>
                                        </a>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.toggle-actif').forEach(function(toggle) {
    toggle.addEventListener('change', function() {
        const id    = this.dataset.id;
        const actif = this.checked ? 1 : 0;
        const label = document.getElementById('label-' + id);

        // Désactiver pendant la requête
        toggle.disabled = true;

        fetch('modules.php?action=toggle&id=' + id + '&ajax=1')
            .then(r => r.json())
            .then(data => {
                toggle.disabled = false;
                if (data.success) {
                    // Mettre à jour le label
                    if (actif) {
                        label.textContent = 'Publié';
                        label.className   = 'badge toggle-label bg-success';
                        toggle.title      = 'Publié — cliquer pour dépublier';
                    } else {
                        label.textContent = 'Non publié';
                        label.className   = 'badge toggle-label bg-secondary';
                        toggle.title      = 'Non publié — cliquer pour publier';
                    }
                } else {
                    // Annuler le changement visuel si erreur
                    toggle.checked = !toggle.checked;
                    alert('Erreur lors de la mise à jour.');
                }
            })
            .catch(() => {
                toggle.disabled = false;
                toggle.checked  = !toggle.checked;
                alert('Erreur de connexion.');
            });
    });
});

// ── Fonctions sélection multiple ─────────────────────────────
function updateBulkActionBar() {
    const checkboxes = document.querySelectorAll('.module-checkbox:checked');
    const bar = document.getElementById('bulkActionBar');
    const count = document.getElementById('bulkCount');
    const selectAll = document.getElementById('selectAll');

    count.textContent = checkboxes.length;
    bar.style.display = checkboxes.length > 0 ? 'block' : 'none';

    // Mettre à jour le checkbox "Sélectionner tout"
    const allCheckboxes = document.querySelectorAll('.module-checkbox');
    selectAll.checked = allCheckboxes.length > 0 && checkboxes.length === allCheckboxes.length;
    selectAll.indeterminate = checkboxes.length > 0 && checkboxes.length < allCheckboxes.length;
}

function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    document.querySelectorAll('.module-checkbox').forEach(cb => {
        cb.checked = selectAll.checked;
    });
    updateBulkActionBar();
}

function clearSelection() {
    document.querySelectorAll('.module-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateBulkActionBar();
}

function bulkDelete() {
    const checkboxes = document.querySelectorAll('.module-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Sélectionnez au moins un module.');
        return;
    }
    if (confirm('Êtes-vous sûr de vouloir supprimer ' + checkboxes.length + ' module(s) ?\nCette action est irréversible.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        const input1 = document.createElement('input');
        input1.type = 'hidden';
        input1.name = 'bulk_action';
        input1.value = 'delete';
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

function bulkToggleStatus() {
    const checkboxes = document.querySelectorAll('.module-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Sélectionnez au moins un module.');
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    const input1 = document.createElement('input');
    input1.type = 'hidden';
    input1.name = 'bulk_action';
    input1.value = 'toggle_status';
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
</script>

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
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Supprimer définitivement
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDeleteModule(id, nom, nbQ, nbP) {
    document.getElementById('delModId').value  = id;
    document.getElementById('delModNom').textContent = nom;
    document.getElementById('delModStats').textContent =
        nbQ + ' question(s), ' + nbP + ' partie(s)';
    new bootstrap.Modal(document.getElementById('deleteModuleModal')).show();
}
</script>
</body>
</html>
