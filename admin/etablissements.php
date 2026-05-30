<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo     = getDB();
$msg     = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'ajouter') {
        $nom   = trim($_POST['nom']   ?? '');
        $ville = trim($_POST['ville'] ?? '');
        if (strlen($nom) < 2) {
            $msg = "Le nom doit faire au moins 2 caractères.";
            $msgType = 'danger';
        } else {
            $pdo->prepare("INSERT INTO etablissements (nom, ville, actif) VALUES (?, ?, 1)")
                ->execute([$nom, $ville]);
            $msg = "Établissement <strong>" . sanitize($nom) . "</strong> créé.";
        }

    } elseif ($postAction === 'modifier') {
        $id    = (int)($_POST['id']    ?? 0);
        $nom   = trim($_POST['nom']    ?? '');
        $ville = trim($_POST['ville']  ?? '');
        if ($id > 0 && strlen($nom) >= 2) {
            $pdo->prepare("UPDATE etablissements SET nom=?, ville=? WHERE id=?")
                ->execute([$nom, $ville, $id]);
            $msg = "Établissement mis à jour.";
        }

    } elseif ($postAction === 'toggle_actif') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE etablissements SET actif = NOT actif WHERE id=?")->execute([$id]);
            $msg = "Statut mis à jour.";
        }

    } elseif ($postAction === 'supprimer') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM etablissements WHERE id=?")->execute([$id]);
            $msg = "Établissement supprimé.";
        }
    }
}

$etablissements = $pdo->query("SELECT * FROM etablissements ORDER BY nom")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Établissements — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>

<div class="container py-4" style="max-width:800px">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h2 class="h4 fw-bold mb-0">
            <i class="bi bi-building me-2 text-primary"></i>Établissements
        </h2>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-primary fs-6"><?= count($etablissements) ?> établissement(s)</span>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAjout">
                <i class="bi bi-plus-lg me-1"></i>Nouvel établissement
            </button>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> alert-dismissible fade show rounded-3">
            <i class="bi bi-<?= $msgType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
            <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Nom</th>
                        <th>Ville</th>
                        <th class="text-center">Statut</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($etablissements)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">Aucun établissement</td></tr>
                <?php endif; ?>
                <?php foreach ($etablissements as $e): ?>
                    <tr>
                        <td class="ps-4 fw-semibold"><?= sanitize($e['nom']) ?></td>
                        <td class="text-muted"><?= sanitize($e['ville']) ?></td>
                        <td class="text-center">
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_actif">
                                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                <button type="submit" class="badge border-0 <?= $e['actif'] ? 'bg-success' : 'bg-secondary' ?>" title="Cliquer pour changer">
                                    <?= $e['actif'] ? 'Actif' : 'Inactif' ?>
                                </button>
                            </form>
                        </td>
                        <td class="text-center">
                            <div class="d-flex gap-1 justify-content-center">
                                <button class="btn btn-sm btn-outline-primary rounded-3 btn-modifier"
                                        data-id="<?= $e['id'] ?>"
                                        data-nom="<?= sanitize($e['nom']) ?>"
                                        data-ville="<?= sanitize($e['ville']) ?>"
                                        title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger rounded-3 btn-supprimer"
                                        data-id="<?= $e['id'] ?>"
                                        data-nom="<?= sanitize($e['nom']) ?>"
                                        title="Supprimer">
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

    <p class="text-muted small mt-3">
        <i class="bi bi-info-circle me-1"></i>
        Le premier établissement <strong>actif</strong> est utilisé par défaut sur toutes les pages.
    </p>
</div>

<!-- Modal : Ajouter -->
<div class="modal fade" id="modalAjout" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="ajouter">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-building me-2"></i>Nouvel établissement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nom <span class="text-danger">*</span></label>
                    <input type="text" name="nom" class="form-control" placeholder="Ex: ISTA HAY RIAD" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Ville</label>
                    <input type="text" name="ville" class="form-control" placeholder="Ex: Rabat">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Créer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal : Modifier -->
<div class="modal fade" id="modalModifier" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="formModifier" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="modifier">
            <input type="hidden" name="id" id="modif_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Modifier l'établissement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nom <span class="text-danger">*</span></label>
                    <input type="text" name="nom" id="modif_nom" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Ville</label>
                    <input type="text" name="ville" id="modif_ville" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal : Supprimer -->
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
                <p class="text-danger small mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i>Cette action est irréversible.
                </p>
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
document.querySelectorAll('.btn-modifier').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('modif_id').value    = btn.dataset.id;
        document.getElementById('modif_nom').value   = btn.dataset.nom;
        document.getElementById('modif_ville').value = btn.dataset.ville;
        new bootstrap.Modal(document.getElementById('modalModifier')).show();
    });
});

document.querySelectorAll('.btn-supprimer').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('suppr_id').value         = btn.dataset.id;
        document.getElementById('suppr_nom').textContent  = btn.dataset.nom;
        new bootstrap.Modal(document.getElementById('modalSupprimer')).show();
    });
});
</script>
</body>
</html>
