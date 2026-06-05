<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo     = getDB();
$msg     = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'ajouter' || $postAction === 'modifier') {
        $code          = trim($_POST['code'] ?? '');
        $annee         = trim($_POST['annee'] ?? '');
        $filiereId     = (int)($_POST['filiere_id'] ?? 0) ?: null;
        $effectif      = max(0, (int)($_POST['effectif'] ?? 0));
        $modeFormation = $_POST['mode_formation'] ?? 'presentiel';
        $creneau       = trim($_POST['creneau'] ?? '') ?: null;

        if (strlen($code) < 2 || strlen($annee) < 1) {
            $msg = "Code et année obligatoires.";
            $msgType = 'danger';
        } elseif ($filiereId === null) {
            $msg = "La filière est obligatoire.";
            $msgType = 'danger';
        } elseif ($postAction === 'modifier') {
            $gid = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE groupes_emploi SET code=?, annee=?, filiere_id=?, effectif=?, mode_formation=?, creneau=? WHERE id=?")
                ->execute([$code, $annee, $filiereId, $effectif, $modeFormation, $creneau, $gid]);
            $msg = "Groupe mis à jour.";
        } else {
            $pdo->prepare("INSERT INTO groupes_emploi (code, annee, filiere_id, effectif, mode_formation, creneau) VALUES (?,?,?,?,?,?)")
                ->execute([$code, $annee, $filiereId, $effectif, $modeFormation, $creneau]);
            $msg = "Groupe <strong>" . sanitize($code) . "</strong> créé.";
        }

    } elseif ($postAction === 'toggle_actif') {
        $gid = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE groupes_emploi SET actif = 1 - actif WHERE id=?")->execute([$gid]);
        $msg = "Statut mis à jour.";

    } elseif ($postAction === 'supprimer') {
        $gid = (int)($_POST['id'] ?? 0);
        if ($gid) {
            $pdo->prepare("DELETE FROM groupes_emploi WHERE id=?")->execute([$gid]);
            $msg = "Groupe supprimé.";
        }
    } elseif ($postAction === 'bulk_delete') {
        $ids = array_map('intval', $_POST['selected_ids'] ?? []);
        foreach ($ids as $gid) {
            $pdo->prepare("DELETE FROM groupes_emploi WHERE id=?")->execute([$gid]);
        }
        $msg = count($ids) . " groupe(s) supprimé(s).";
    }
}

$groupesRaw = $pdo->query("
    SELECT ge.*, rf.code AS filiere_code, rf.nom AS filiere_nom, rf.secteur AS filiere_secteur
    FROM groupes_emploi ge
    LEFT JOIN ref_filieres rf ON rf.id = ge.filiere_id
    ORDER BY rf.nom, ge.annee_formation, ge.code
")->fetchAll();

// Grouper par filière
$grouped = [];
foreach ($groupesRaw as $g) {
    $key = $g['filiere_id'] ?? 0;
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'filiere_id'      => $g['filiere_id'],
            'filiere_code'    => $g['filiere_code'] ?? '—',
            'filiere_nom'     => $g['filiere_nom']  ?? 'Sans filière',
            'filiere_secteur' => $g['filiere_secteur'] ?? '',
            'groupes'         => [],
        ];
    }
    $grouped[$key]['groupes'][] = $g;
}

$totalGroupes = count($groupesRaw);
$filieres = $pdo->query("SELECT id, code, nom FROM ref_filieres ORDER BY nom")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Groupes — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container-fluid py-4" style="max-width:1400px">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h2 class="h4 fw-bold mb-0">
            <i class="bi bi-calendar3 me-2 text-primary"></i>Groupes
        </h2>
        <div class="d-flex gap-2">
            <span class="badge bg-primary fs-6" id="grpCounter"><?= $totalGroupes ?> groupe(s)</span>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAjouter">
                <i class="bi bi-plus-lg me-1"></i>Nouveau groupe
            </button>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> alert-dismissible fade show rounded-3">
            <i class="bi bi-<?= $msgType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i><?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Barre de filtre -->
    <div class="card border-0 shadow-sm rounded-4 mb-3">
        <div class="card-body py-2 px-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-4">
                    <input type="text" id="searchCode" class="form-control form-control-sm" placeholder="Rechercher code groupe...">
                </div>
                <div class="col-md-5">
                    <select id="filterFiliere" class="form-select form-select-sm">
                        <option value="">— Toutes les filières —</option>
                        <?php foreach ($filieres as $f): ?>
                            <option value="<?= htmlspecialchars($f['code'], ENT_QUOTES) ?>"><?= htmlspecialchars($f['code'], ENT_QUOTES) ?> — <?= htmlspecialchars($f['nom'], ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select id="filterAnnee" class="form-select form-select-sm">
                        <option value="">— Toutes années —</option>
                        <option value="1A">1ère année</option>
                        <option value="2A">2ème année</option>
                        <option value="3A">3ème année</option>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="button" class="btn btn-outline-secondary btn-sm w-100" onclick="resetFilters()" title="Réinitialiser les filtres">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Barre bulk -->
    <div id="bulkActionBar" class="card border-0 shadow-sm rounded-3 mb-2 py-2 px-3" style="display:none">
        <div class="d-flex gap-2 align-items-center">
            <span class="text-muted"><span id="bulkCount">0</span> sélectionné(s)</span>
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="bulkDelete()">
                <i class="bi bi-trash me-1"></i>Supprimer
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" onclick="clearSelection()">Annuler</button>
        </div>
    </div>

    <?php if (empty($grouped)): ?>
        <div class="card border-0 shadow-sm rounded-4 p-4 text-center text-muted">Aucun groupe.</div>
    <?php endif; ?>

    <?php
    $modeIcons  = ['presentiel' => 'bi-building', 'distanciel' => 'bi-wifi', 'hybride' => 'bi-shuffle'];
    $modeColors = ['presentiel' => 'success', 'distanciel' => 'warning', 'hybride' => 'info'];
    $sectorColors = [
        'Digital et Intelligence Artificielle' => 'primary',
        'Gestion et Commerce'                  => 'success',
        'Santé'                                => 'danger',
        'Développement Personnel et Professionnel' => 'warning',
    ];
    $idx = 0;
    foreach ($grouped as $filId => $section):
        $idx++;
        $acId = 'acc_fil_' . $idx;
        $nbGrp = count($section['groupes']);
        $secColor = $sectorColors[$section['filiere_secteur']] ?? 'secondary';
    ?>
    <div class="accordion-filiere mb-2"
         data-filiere-code="<?= htmlspecialchars($section['filiere_code'], ENT_QUOTES) ?>">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <!-- En-tête filière -->
            <button class="btn btn-light w-100 text-start px-4 py-3 d-flex align-items-center gap-3 border-0 rounded-0"
                    type="button" data-bs-toggle="collapse" data-bs-target="#<?= $acId ?>" aria-expanded="true">
                <i class="bi bi-diagram-3 text-<?= $secColor ?>"></i>
                <div class="flex-grow-1">
                    <span class="fw-bold"><?= htmlspecialchars($section['filiere_code']) ?></span>
                    <span class="text-muted ms-2 small"><?= htmlspecialchars($section['filiere_nom']) ?></span>
                    <?php if ($section['filiere_secteur']): ?>
                        <span class="badge bg-<?= $secColor ?>-subtle text-<?= $secColor ?> border border-<?= $secColor ?>-subtle ms-2 small">
                            <?= htmlspecialchars($section['filiere_secteur']) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <span class="badge bg-primary rounded-pill grp-section-count" data-section="<?= $acId ?>"><?= $nbGrp ?></span>
                <i class="bi bi-chevron-down text-muted ms-1" style="transition:.2s"></i>
            </button>

            <!-- Tableau des groupes -->
            <div class="collapse show" id="<?= $acId ?>">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-sm">
                        <thead class="table-light border-top">
                            <tr>
                                <th class="ps-4" style="width:40px">
                                    <input type="checkbox" class="form-check-input sec-selectall" data-section="<?= $acId ?>" onchange="toggleSectionAll(this)">
                                </th>
                                <th class="ps-2">Code</th>
                                <th class="text-center">Année</th>
                                <th class="text-center">Créneau</th>
                                <th class="text-center">Effectif</th>
                                <th>Mode</th>
                                <th class="text-center">Statut</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($section['groupes'] as $g): ?>
                            <tr class="grp-row <?= $g['actif'] ? '' : 'table-secondary text-muted' ?>"
                                data-code="<?= strtolower(htmlspecialchars($g['code'], ENT_QUOTES)) ?>"
                                data-filiere="<?= htmlspecialchars($section['filiere_code'], ENT_QUOTES) ?>"
                                data-annee="<?= htmlspecialchars($g['annee'], ENT_QUOTES) ?>"
                                data-section="<?= $acId ?>">
                                <td class="ps-4">
                                    <input type="checkbox" class="form-check-input g-checkbox" value="<?= $g['id'] ?>" onchange="updateBulkBar()">
                                </td>
                                <td class="ps-2 fw-semibold font-monospace"><?= sanitize($g['code']) ?></td>
                                <td class="text-center">
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"><?= sanitize($g['annee']) ?></span>
                                </td>
                                <td class="text-center small"><?= $g['creneau'] ? sanitize($g['creneau']) : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-center">
                                    <span class="badge bg-info-subtle text-info border border-info-subtle"><?= (int)$g['effectif'] ?></span>
                                </td>
                                <td>
                                    <?php $mode = $g['mode_formation']; ?>
                                    <span class="badge bg-<?= $modeColors[$mode] ?? 'secondary' ?>-subtle text-<?= $modeColors[$mode] ?? 'secondary' ?> border border-<?= $modeColors[$mode] ?? 'secondary' ?>-subtle">
                                        <i class="bi <?= $modeIcons[$mode] ?? 'bi-question' ?> me-1"></i><?= ucfirst($mode) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle_actif">
                                        <input type="hidden" name="id" value="<?= $g['id'] ?>">
                                        <button type="submit" class="badge border-0 <?= $g['actif'] ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>" style="cursor:pointer">
                                            <?= $g['actif'] ? 'Actif' : 'Inactif' ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-primary me-1 btn-modifier"
                                            data-id="<?= $g['id'] ?>"
                                            data-code="<?= sanitize($g['code']) ?>"
                                            data-annee="<?= sanitize($g['annee']) ?>"
                                            data-filiere="<?= (int)$g['filiere_id'] ?>"
                                            data-effectif="<?= (int)$g['effectif'] ?>"
                                            data-mode="<?= $g['mode_formation'] ?>"
                                            data-creneau="<?= sanitize($g['creneau'] ?? '') ?>"
                                            title="Modifier">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger btn-supprimer"
                                            data-id="<?= $g['id'] ?>"
                                            data-code="<?= sanitize($g['code']) ?>"
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
    </div>
    <?php endforeach; ?>
</div>

<!-- Modal Ajouter -->
<div class="modal fade" id="modalAjouter" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="ajouter">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Nouveau groupe</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body row g-3">
                <div class="col-6">
                    <label class="form-label fw-semibold">Code groupe <span class="text-danger">*</span></label>
                    <input type="text" name="code" class="form-control font-monospace" required placeholder="Ex : DEV201">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Année <span class="text-danger">*</span></label>
                    <select name="annee" class="form-select" required>
                        <option value="1A">1ère année</option>
                        <option value="2A">2ème année</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Filière <span class="text-danger">*</span></label>
                    <select name="filiere_id" class="form-select" required>
                        <option value="">— Choisir une filière —</option>
                        <?php foreach ($filieres as $f): ?>
                            <option value="<?= $f['id'] ?>"><?= sanitize($f['code']) ?> — <?= sanitize($f['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Effectif</label>
                    <input type="number" name="effectif" class="form-control" value="0" min="0" max="50">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Créneau</label>
                    <input type="text" name="creneau" class="form-control" placeholder="Ex : M, S, I">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Mode de formation</label>
                    <select name="mode_formation" class="form-select">
                        <option value="presentiel">Présentiel</option>
                        <option value="distanciel">Distanciel</option>
                        <option value="hybride">Hybride</option>
                    </select>
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
        <form method="POST" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="modifier">
            <input type="hidden" name="id" id="mod_id">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-2"></i>Modifier le groupe</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body row g-3">
                <div class="col-6">
                    <label class="form-label fw-semibold">Code groupe <span class="text-danger">*</span></label>
                    <input type="text" name="code" id="mod_code" class="form-control font-monospace" required>
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Année <span class="text-danger">*</span></label>
                    <select name="annee" id="mod_annee" class="form-select" required>
                        <option value="1A">1ère année</option>
                        <option value="2A">2ème année</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Filière <span class="text-danger">*</span></label>
                    <select name="filiere_id" id="mod_filiere" class="form-select" required>
                        <option value="">— Choisir une filière —</option>
                        <?php foreach ($filieres as $f): ?>
                            <option value="<?= $f['id'] ?>"><?= sanitize($f['code']) ?> — <?= sanitize($f['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Effectif</label>
                    <input type="number" name="effectif" id="mod_effectif" class="form-control" min="0" max="50">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Créneau</label>
                    <input type="text" name="creneau" id="mod_creneau" class="form-control">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Mode de formation</label>
                    <select name="mode_formation" id="mod_mode" class="form-select">
                        <option value="presentiel">Présentiel</option>
                        <option value="distanciel">Distanciel</option>
                        <option value="hybride">Hybride</option>
                    </select>
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
                <p>Supprimer le groupe <strong id="suppr_code"></strong> ?</p>
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
// Filtres live — gestion accordéon par filière
function applyFilters() {
    const code    = document.getElementById('searchCode').value.trim().toLowerCase();
    const filiere = document.getElementById('filterFiliere').value;
    const annee   = document.getElementById('filterAnnee').value;
    let total = 0;

    document.querySelectorAll('.accordion-filiere').forEach(section => {
        const sFiliere = section.dataset.filiereCode || '';
        // Si le filtre filière ne correspond pas à cette section, cacher toute la section
        if (filiere && sFiliere !== filiere) {
            section.style.display = 'none';
            return;
        }
        section.style.display = '';

        let visibleInSection = 0;
        const collapseId = section.querySelector('[data-bs-target]')?.dataset.bsTarget?.replace('#','');

        section.querySelectorAll('tr.grp-row').forEach(tr => {
            const trCode  = (tr.dataset.code  || '').toLowerCase();
            const trAnnee = (tr.dataset.annee || '');
            const match   = (!code  || trCode.includes(code))
                         && (!annee || trAnnee === annee);
            tr.style.display = match ? '' : 'none';
            if (match) { visibleInSection++; total++; }
        });

        // Cacher la section si aucun groupe visible
        section.style.display = visibleInSection ? '' : 'none';

        // Ouvrir automatiquement si filtre actif
        if ((code || annee) && visibleInSection && collapseId) {
            const colEl = document.getElementById(collapseId);
            if (colEl && !colEl.classList.contains('show'))
                new bootstrap.Collapse(colEl, {toggle: false}).show();
        }

        // Mettre à jour le badge compteur de section
        const badge = section.querySelector('.grp-section-count');
        if (badge) badge.textContent = visibleInSection;
    });

    document.getElementById('grpCounter').textContent = total + ' groupe(s)';
}
function resetFilters() {
    document.getElementById('searchCode').value    = '';
    document.getElementById('filterFiliere').value = '';
    document.getElementById('filterAnnee').value   = '';
    applyFilters();
}
document.getElementById('searchCode').addEventListener('input', applyFilters);
document.getElementById('filterFiliere').addEventListener('change', applyFilters);
document.getElementById('filterAnnee').addEventListener('change', applyFilters);

document.querySelectorAll('.btn-modifier').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('mod_id').value       = btn.dataset.id;
        document.getElementById('mod_code').value     = btn.dataset.code;
        document.getElementById('mod_annee').value    = btn.dataset.annee;
        document.getElementById('mod_filiere').value  = btn.dataset.filiere;
        document.getElementById('mod_effectif').value = btn.dataset.effectif;
        document.getElementById('mod_mode').value     = btn.dataset.mode;
        document.getElementById('mod_creneau').value  = btn.dataset.creneau;
        new bootstrap.Modal(document.getElementById('modalModifier')).show();
    });
});

document.querySelectorAll('.btn-supprimer').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('suppr_id').value        = btn.dataset.id;
        document.getElementById('suppr_code').textContent = btn.dataset.code;
        new bootstrap.Modal(document.getElementById('modalSupprimer')).show();
    });
});

function toggleSectionAll(cb) {
    const secId = cb.dataset.section;
    document.querySelectorAll(`tr[data-section="${secId}"] .g-checkbox`).forEach(box => {
        if (box.closest('tr').style.display !== 'none') box.checked = cb.checked;
    });
    updateBulkBar();
}

function updateBulkBar() {
    const checked = document.querySelectorAll('.g-checkbox:checked');
    const all     = document.querySelectorAll('.g-checkbox');
    document.getElementById('bulkCount').textContent = checked.length;
    document.getElementById('bulkActionBar').style.display = checked.length ? 'block' : 'none';
    const sa = document.getElementById('selectAll');
    sa.checked = all.length > 0 && checked.length === all.length;
    sa.indeterminate = checked.length > 0 && checked.length < all.length;
}

function toggleSelectAll() {
    const v = document.getElementById('selectAll').checked;
    document.querySelectorAll('.g-checkbox').forEach(cb => cb.checked = v);
    updateBulkBar();
}

function clearSelection() {
    document.querySelectorAll('.g-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateBulkBar();
}

function bulkDelete() {
    const checked = document.querySelectorAll('.g-checkbox:checked');
    if (!checked.length) return;
    if (!confirm('Supprimer ' + checked.length + ' groupe(s) ?')) return;
    const form = document.createElement('form'); form.method = 'POST';
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
