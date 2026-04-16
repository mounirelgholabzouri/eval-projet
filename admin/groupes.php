<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$msg     = '';
$msgType = 'success';
$action  = $_GET['action'] ?? 'list';
$id      = (int)($_GET['id'] ?? 0);

// ── Traitement POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'ajouter' || $postAction === 'modifier') {
        $nom = trim($_POST['nom'] ?? '');
        if (strlen($nom) < 2) {
            $msg = "Le nom du groupe doit faire au moins 2 caractères.";
            $msgType = 'danger';
        } elseif ($postAction === 'modifier' && $id > 0) {
            $pdo->prepare("UPDATE groupes SET nom=? WHERE id=?")->execute([$nom, $id]);
            $msg = "Groupe mis à jour.";
            $action = 'list';
        } else {
            $pdo->prepare("INSERT INTO groupes (nom) VALUES (?)")->execute([$nom]);
            $msg = "Groupe <strong>" . sanitize($nom) . "</strong> créé.";
            $action = 'list';
        }

    } elseif ($postAction === 'supprimer') {
        $delId = (int)($_POST['id'] ?? 0);
        if ($delId) {
            $nb = (int)$pdo->prepare("SELECT COUNT(*) FROM stagiaires WHERE groupe_id=?")
                           ->execute([$delId]) && $pdo->query("SELECT COUNT(*) FROM stagiaires WHERE groupe_id=$delId")->fetchColumn();
            // Recompter proprement
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM stagiaires WHERE groupe_id=?");
            $stmt->execute([$delId]);
            $nbStag = (int)$stmt->fetchColumn();
            if ($nbStag > 0) {
                $msg = "Impossible de supprimer : ce groupe contient <strong>$nbStag stagiaire(s)</strong>. Réaffectez-les d'abord.";
                $msgType = 'danger';
            } else {
                $pdo->prepare("DELETE FROM groupes WHERE id=?")->execute([$delId]);
                $msg = "Groupe supprimé.";
            }
        }
        $action = 'list';
    }
}

// ── Données ──────────────────────────────────────────────────
$groupes = $pdo->query("
    SELECT g.*,
           COUNT(DISTINCT s.id)  AS nb_stagiaires,
           COUNT(DISTINCT se.id) AS nb_sessions
    FROM groupes g
    LEFT JOIN stagiaires s  ON s.groupe_id  = g.id
    LEFT JOIN sessions_eval se ON se.groupe_id = g.id
    GROUP BY g.id
    ORDER BY g.nom
")->fetchAll();

$editGroupe = null;
if ($action === 'edit' && $id > 0) {
    foreach ($groupes as $g) {
        if ((int)$g['id'] === $id) { $editGroupe = $g; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Groupes — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>

<div class="container py-4" style="max-width:900px">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h2 class="h4 fw-bold mb-0">
            <i class="bi bi-people-fill me-2 text-primary"></i>Groupes
        </h2>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-primary fs-6"><?= count($groupes) ?> groupe(s)</span>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAjout">
                <i class="bi bi-plus-lg me-1"></i>Nouveau groupe
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

    <!-- Liste des groupes -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Groupe</th>
                        <th class="text-center">Stagiaires</th>
                        <th class="text-center">Évaluations</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($groupes)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">Aucun groupe</td></tr>
                <?php endif; ?>
                <?php foreach ($groupes as $g): ?>
                    <tr>
                        <td class="ps-4 fw-semibold"><?= sanitize($g['nom']) ?></td>
                        <td class="text-center">
                            <?php if ($g['nb_stagiaires'] > 0): ?>
                                <a href="stagiaires.php?groupe_id=<?= $g['id'] ?>"
                                   class="badge bg-primary-subtle text-primary text-decoration-none">
                                    <?= $g['nb_stagiaires'] ?> stagiaire(s)
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary-subtle text-secondary"><?= $g['nb_sessions'] ?></span>
                        </td>
                        <td class="text-center">
                            <div class="d-flex gap-1 justify-content-center">
                                <button class="btn btn-sm btn-outline-primary rounded-3 btn-modifier"
                                        data-id="<?= $g['id'] ?>"
                                        data-nom="<?= sanitize($g['nom']) ?>"
                                        title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger rounded-3 btn-supprimer"
                                        data-id="<?= $g['id'] ?>"
                                        data-nom="<?= sanitize($g['nom']) ?>"
                                        data-nb="<?= $g['nb_stagiaires'] ?>"
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
</div>

<!-- Modal : Ajouter un groupe -->
<div class="modal fade" id="modalAjout" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="ajouter">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Nouveau groupe</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold">Nom du groupe <span class="text-danger">*</span></label>
                <input type="text" name="nom" class="form-control" placeholder="Ex: Groupe A BTS SIO" required autofocus>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Créer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal : Modifier un groupe -->
<div class="modal fade" id="modalModifier" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <form method="POST" action="groupes.php?action=edit&id=0" id="formModifier" class="modal-content">
            <input type="hidden" name="action" value="modifier">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Modifier le groupe</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold">Nom du groupe <span class="text-danger">*</span></label>
                <input type="text" name="nom" id="modif_nom" class="form-control" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal : Supprimer un groupe -->
<div class="modal fade" id="modalSupprimer" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="supprimer">
            <input type="hidden" name="id" id="suppr_id">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Supprimer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Supprimer le groupe <strong id="suppr_nom"></strong> ?</p>
                <p class="text-danger small mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Impossible si des stagiaires appartiennent à ce groupe.
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
        const id  = btn.dataset.id;
        const nom = btn.dataset.nom;
        document.getElementById('modif_nom').value = nom;
        document.getElementById('formModifier').action = 'groupes.php?action=edit&id=' + id;
        new bootstrap.Modal(document.getElementById('modalModifier')).show();
    });
});

document.querySelectorAll('.btn-supprimer').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('suppr_id').value      = btn.dataset.id;
        document.getElementById('suppr_nom').textContent = btn.dataset.nom;
        new bootstrap.Modal(document.getElementById('modalSupprimer')).show();
    });
});
</script>
</body>
</html>
