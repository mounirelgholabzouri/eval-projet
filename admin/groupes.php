<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$msg = '';
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = trim($_POST['nom'] ?? '');
    if (strlen($nom) >= 2) {
        if ($action === 'edit' && $id > 0) {
            $pdo->prepare("UPDATE groupes SET nom=? WHERE id=?")->execute([$nom, $id]);
            $msg = "Groupe mis à jour.";
        } else {
            $pdo->prepare("INSERT INTO groupes (nom) VALUES (?)")->execute([$nom]);
            $msg = "Groupe ajouté.";
        }
        $action = 'list';
    }
}

if ($action === 'delete' && $id > 0) {
    $pdo->prepare("DELETE FROM groupes WHERE id=?")->execute([$id]);
    $msg = "Groupe supprimé.";
    $action = 'list';
}

$groupes = $pdo->query("SELECT g.*, COUNT(s.id) AS nb_sessions FROM groupes g LEFT JOIN sessions_eval s ON s.groupe_id = g.id GROUP BY g.id ORDER BY g.nom")->fetchAll();
$editGroupe = null;
if ($action === 'edit' && $id > 0) {
    foreach ($groupes as $g) { if ((int)$g['id'] === $id) { $editGroupe = $g; break; } }
}
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
<div class="container-fluid py-4 px-4" style="max-width:900px">
    <h2 class="h4 fw-bold mb-4"><i class="bi bi-people me-2 text-primary"></i>Gestion des groupes</h2>
    <?php if ($msg): ?><div class="alert alert-success rounded-3"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <div class="row g-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><?= $editGroupe ? 'Modifier' : 'Nouveau' ?> groupe</h5>
                    <form method="POST" action="groupes.php?action=<?= $editGroupe ? 'edit&id='.$id : 'add' ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Nom du groupe</label>
                            <input type="text" name="nom" class="form-control" required
                                   placeholder="Ex: Groupe A BTS SIO"
                                   value="<?= htmlspecialchars($editGroupe['nom'] ?? '') ?>">
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i><?= $editGroupe ? 'Mettre à jour' : 'Ajouter' ?>
                            </button>
                            <?php if ($editGroupe): ?>
                            <a href="groupes.php" class="btn btn-outline-secondary">Annuler</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Groupe</th>
                                <th class="text-center">Évaluations</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($groupes)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">Aucun groupe</td></tr>
                        <?php endif; ?>
                        <?php foreach ($groupes as $g): ?>
                            <tr>
                                <td class="ps-4 fw-semibold"><?= htmlspecialchars($g['nom']) ?></td>
                                <td class="text-center"><span class="badge bg-primary-subtle text-primary"><?= $g['nb_sessions'] ?></span></td>
                                <td class="text-center">
                                    <div class="d-flex gap-1 justify-content-center">
                                        <a href="groupes.php?action=edit&id=<?= $g['id'] ?>" class="btn btn-sm btn-outline-primary rounded-3">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="groupes.php?action=delete&id=<?= $g['id'] ?>"
                                           class="btn btn-sm btn-outline-danger rounded-3"
                                           onclick="return confirm('Supprimer ce groupe ?')">
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
</body>
</html>
