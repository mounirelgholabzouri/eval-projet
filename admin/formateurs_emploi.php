<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo    = getDB();
$msg    = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'ajouter' || $postAction === 'modifier') {
        $nom              = trim($_POST['nom'] ?? '');
        $prenom           = trim($_POST['prenom'] ?? '');
        $matricule        = trim($_POST['matricule'] ?? '') ?: null;
        $specialite       = trim($_POST['specialite'] ?? '') ?: null;
        $email            = trim($_POST['email'] ?? '') ?: null;
        $telephone        = trim($_POST['telephone'] ?? '') ?: null;
        $volumeMax        = max(0, (int)($_POST['volume_horaire_max'] ?? 30));

        if (strlen($nom) < 2 || strlen($prenom) < 2) {
            $msg = "Nom et prénom obligatoires (≥ 2 caractères).";
            $msgType = 'danger';
        } elseif ($postAction === 'modifier') {
            $fid = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE formateurs SET nom=?, prenom=?, matricule=?, specialite=?, email=?, telephone=?, volume_horaire_max=? WHERE id=?")
                ->execute([$nom, $prenom, $matricule, $specialite, $email, $telephone, $volumeMax, $fid]);
            $msg = "Formateur mis à jour.";
        } else {
            $pdo->prepare("INSERT INTO formateurs (nom, prenom, matricule, specialite, email, telephone, volume_horaire_max) VALUES (?,?,?,?,?,?,?)")
                ->execute([$nom, $prenom, $matricule, $specialite, $email, $telephone, $volumeMax]);
            $msg = "Formateur <strong>" . sanitize($prenom . ' ' . $nom) . "</strong> créé.";
        }

    } elseif ($postAction === 'toggle_actif') {
        $fid = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE formateurs SET actif = 1 - actif WHERE id=?")->execute([$fid]);
        $msg = "Statut mis à jour.";

    } elseif ($postAction === 'supprimer') {
        $fid = (int)($_POST['id'] ?? 0);
        if ($fid) {
            $pdo->prepare("DELETE FROM formateurs WHERE id=?")->execute([$fid]);
            $msg = "Formateur supprimé.";
        }
    } elseif ($postAction === 'bulk_delete') {
        $ids = array_map('intval', $_POST['selected_ids'] ?? []);
        foreach ($ids as $fid) {
            $pdo->prepare("DELETE FROM formateurs WHERE id=?")->execute([$fid]);
        }
        $msg = count($ids) . " formateur(s) supprimé(s).";
    }
}

$formateurs = $pdo->query("SELECT * FROM formateurs ORDER BY nom, prenom")->fetchAll();
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
            <i class="bi bi-person-workspace me-2 text-primary"></i>Formateurs
        </h2>
        <div class="d-flex gap-2">
            <span class="badge bg-primary fs-6" id="frmCounter"><?= count($formateurs) ?> formateur(s)</span>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAjouter">
                <i class="bi bi-plus-lg me-1"></i>Nouveau formateur
            </button>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> alert-dismissible fade show rounded-3">
            <i class="bi bi-<?= $msgType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i><?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Barre de recherche -->
    <div class="card border-0 shadow-sm rounded-4 mb-3">
        <div class="card-body py-2 px-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-5">
                    <input type="text" id="searchNom" class="form-control form-control-sm" placeholder="Rechercher nom, prénom, matricule...">
                </div>
                <div class="col-md-4">
                    <input type="text" id="searchSpec" class="form-control form-control-sm" placeholder="Filtrer par spécialité...">
                </div>
                <div class="col-md-2">
                    <select id="filterStatut" class="form-select form-select-sm">
                        <option value="">— Tous statuts —</option>
                        <option value="1">Actif</option>
                        <option value="0">Inactif</option>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="button" class="btn btn-outline-secondary btn-sm w-100" onclick="resetFilters()" title="Réinitialiser">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div id="bulkActionBar" class="card-header bg-light border-bottom py-3 px-4" style="display:none">
            <div class="d-flex gap-2 align-items-center">
                <span class="text-muted"><span id="bulkCount">0</span> sélectionné(s)</span>
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="bulkDelete()">
                    <i class="bi bi-trash me-1"></i>Supprimer
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" onclick="clearSelection()">Annuler</button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" style="width:40px">
                            <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll()">
                        </th>
                        <th class="ps-4">Nom / Prénom</th>
                        <th>Matricule</th>
                        <th>Spécialité</th>
                        <th>Email</th>
                        <th>Téléphone</th>
                        <th class="text-center">Vol. max (h)</th>
                        <th class="text-center">Statut</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($formateurs)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Aucun formateur.</td></tr>
                <?php endif; ?>
                <?php foreach ($formateurs as $f): ?>
                    <tr class="<?= $f['actif'] ? '' : 'table-secondary text-muted' ?>"
                        data-nom="<?= strtolower(htmlspecialchars($f['prenom'].' '.$f['nom'].' '.($f['matricule']??''), ENT_QUOTES)) ?>"
                        data-spec="<?= strtolower(htmlspecialchars($f['specialite'] ?? '', ENT_QUOTES)) ?>"
                        data-actif="<?= (int)$f['actif'] ?>">
                        <td class="ps-4">
                            <input type="checkbox" class="form-check-input f-checkbox" value="<?= $f['id'] ?>" onchange="updateBulkBar()">
                        </td>
                        <td class="ps-4 fw-semibold">
                            <i class="bi bi-person-circle me-2 text-primary"></i>
                            <?= sanitize($f['prenom'] . ' ' . $f['nom']) ?>
                        </td>
                        <td class="font-monospace small"><?= $f['matricule'] ? sanitize($f['matricule']) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= $f['specialite'] ? sanitize($f['specialite']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="small"><?= $f['email'] ? '<a href="mailto:'.sanitize($f['email']).'">'.sanitize($f['email']).'</a>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="small"><?= $f['telephone'] ? sanitize($f['telephone']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-center">
                            <span class="badge bg-info-subtle text-info border border-info-subtle"><?= (int)$f['volume_horaire_max'] ?>h</span>
                        </td>
                        <td class="text-center">
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_actif">
                                <input type="hidden" name="id" value="<?= $f['id'] ?>">
                                <button type="submit" class="badge border-0 <?= $f['actif'] ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border border-secondary-subtle' ?>" style="cursor:pointer">
                                    <?= $f['actif'] ? 'Actif' : 'Inactif' ?>
                                </button>
                            </form>
                        </td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary me-1 btn-modifier"
                                    data-id="<?= $f['id'] ?>"
                                    data-nom="<?= sanitize($f['nom']) ?>"
                                    data-prenom="<?= sanitize($f['prenom']) ?>"
                                    data-matricule="<?= sanitize($f['matricule'] ?? '') ?>"
                                    data-specialite="<?= sanitize($f['specialite'] ?? '') ?>"
                                    data-email="<?= sanitize($f['email'] ?? '') ?>"
                                    data-telephone="<?= sanitize($f['telephone'] ?? '') ?>"
                                    data-volume="<?= (int)$f['volume_horaire_max'] ?>"
                                    title="Modifier">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger btn-supprimer"
                                    data-id="<?= $f['id'] ?>"
                                    data-nom="<?= sanitize($f['prenom'] . ' ' . $f['nom']) ?>"
                                    title="Supprimer">
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

<!-- Modal Ajouter -->
<div class="modal fade" id="modalAjouter" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="ajouter">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Nouveau formateur</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body row g-3">
                <div class="col-6">
                    <label class="form-label fw-semibold">Nom <span class="text-danger">*</span></label>
                    <input type="text" name="nom" class="form-control" required minlength="2" placeholder="Ex : Alami">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Prénom <span class="text-danger">*</span></label>
                    <input type="text" name="prenom" class="form-control" required minlength="2" placeholder="Ex : Mohamed">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Matricule (Mle DRIF)</label>
                    <input type="text" name="matricule" class="form-control font-monospace" placeholder="Ex : 1234567">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Vol. horaire max (h/sem)</label>
                    <input type="number" name="volume_horaire_max" class="form-control" value="30" min="0" max="60">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Spécialité</label>
                    <input type="text" name="specialite" class="form-control" placeholder="Ex : Développement web">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="m.alami@example.com">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Téléphone</label>
                    <input type="text" name="telephone" class="form-control" placeholder="06XXXXXXXX">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-2"></i>Créer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Modifier -->
<div class="modal fade" id="modalModifier" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" id="formModifier">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="modifier">
            <input type="hidden" name="id" id="mod_id">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-2"></i>Modifier le formateur</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body row g-3">
                <div class="col-6">
                    <label class="form-label fw-semibold">Nom <span class="text-danger">*</span></label>
                    <input type="text" name="nom" id="mod_nom" class="form-control" required minlength="2">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Prénom <span class="text-danger">*</span></label>
                    <input type="text" name="prenom" id="mod_prenom" class="form-control" required minlength="2">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Matricule (Mle DRIF)</label>
                    <input type="text" name="matricule" id="mod_matricule" class="form-control font-monospace">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Vol. horaire max (h/sem)</label>
                    <input type="number" name="volume_horaire_max" id="mod_volume" class="form-control" min="0" max="60">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Spécialité</label>
                    <input type="text" name="specialite" id="mod_specialite" class="form-control">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Email</label>
                    <input type="email" name="email" id="mod_email" class="form-control">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Téléphone</label>
                    <input type="text" name="telephone" id="mod_telephone" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-2"></i>Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Supprimer -->
<div class="modal fade" id="modalSupprimer" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <form method="POST" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="supprimer">
            <input type="hidden" name="id" id="suppr_id">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Supprimer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Supprimer <strong id="suppr_nom"></strong> ?</p>
                <p class="text-muted small mb-0">Cette action est irréversible.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-danger btn-sm">Supprimer</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Filtres live
function applyFilters() {
    const nom    = document.getElementById('searchNom').value.trim().toLowerCase();
    const spec   = document.getElementById('searchSpec').value.trim().toLowerCase();
    const statut = document.getElementById('filterStatut').value;
    let visible  = 0;
    document.querySelectorAll('tbody tr[data-nom]').forEach(tr => {
        const match = (!nom    || tr.dataset.nom.includes(nom))
                   && (!spec   || tr.dataset.spec.includes(spec))
                   && (!statut || tr.dataset.actif === statut);
        tr.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    document.getElementById('frmCounter').textContent = visible + ' formateur(s)';
}
function resetFilters() {
    document.getElementById('searchNom').value    = '';
    document.getElementById('searchSpec').value   = '';
    document.getElementById('filterStatut').value = '';
    applyFilters();
}
document.getElementById('searchNom').addEventListener('input', applyFilters);
document.getElementById('searchSpec').addEventListener('input', applyFilters);
document.getElementById('filterStatut').addEventListener('change', applyFilters);

document.querySelectorAll('.btn-modifier').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('mod_id').value         = btn.dataset.id;
        document.getElementById('mod_nom').value        = btn.dataset.nom;
        document.getElementById('mod_prenom').value     = btn.dataset.prenom;
        document.getElementById('mod_matricule').value  = btn.dataset.matricule;
        document.getElementById('mod_specialite').value = btn.dataset.specialite;
        document.getElementById('mod_email').value      = btn.dataset.email;
        document.getElementById('mod_telephone').value  = btn.dataset.telephone;
        document.getElementById('mod_volume').value     = btn.dataset.volume;
        new bootstrap.Modal(document.getElementById('modalModifier')).show();
    });
});

document.querySelectorAll('.btn-supprimer').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('suppr_id').value        = btn.dataset.id;
        document.getElementById('suppr_nom').textContent = btn.dataset.nom;
        new bootstrap.Modal(document.getElementById('modalSupprimer')).show();
    });
});

function updateBulkBar() {
    const checked = document.querySelectorAll('.f-checkbox:checked');
    const all     = document.querySelectorAll('.f-checkbox');
    document.getElementById('bulkCount').textContent = checked.length;
    document.getElementById('bulkActionBar').style.display = checked.length ? 'block' : 'none';
    const sa = document.getElementById('selectAll');
    sa.checked = all.length > 0 && checked.length === all.length;
    sa.indeterminate = checked.length > 0 && checked.length < all.length;
}

function toggleSelectAll() {
    const v = document.getElementById('selectAll').checked;
    document.querySelectorAll('.f-checkbox').forEach(cb => cb.checked = v);
    updateBulkBar();
}

function clearSelection() {
    document.querySelectorAll('.f-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateBulkBar();
}

function bulkDelete() {
    const checked = document.querySelectorAll('.f-checkbox:checked');
    if (!checked.length) return;
    if (!confirm('Supprimer ' + checked.length + ' formateur(s) ?')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    const csrf = document.querySelector('input[name="csrf_token"]');
    if (csrf) { const c = csrf.cloneNode(); form.appendChild(c); }
    const a = document.createElement('input'); a.type='hidden'; a.name='action'; a.value='bulk_delete'; form.appendChild(a);
    checked.forEach(cb => {
        const i = document.createElement('input'); i.type='hidden'; i.name='selected_ids[]'; i.value=cb.value; form.appendChild(i);
    });
    document.body.appendChild(form); form.submit();
}
</script>
</body>
</html>
