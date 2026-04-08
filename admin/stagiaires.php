<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$groupes = getGroupes();
$annees  = getAnneesDisponibles();
$anneeActive = $_GET['annee'] ?? getAnneeCourante();
$groupeFiltre = isset($_GET['groupe_id']) ? (int)$_GET['groupe_id'] : null;

$stagiaires = getStagiaires($groupeFiltre ?: null, $anneeActive ?: null);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stagiaires — Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>

<div class="container py-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0"><i class="bi bi-people-fill me-2 text-primary"></i>Stagiaires</h1>
        <span class="badge bg-primary fs-6"><?= count($stagiaires) ?> stagiaire(s)</span>
    </div>

    <!-- Filtres -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Année scolaire</label>
                    <select name="annee" class="form-select">
                        <option value="">Toutes les années</option>
                        <?php foreach ($annees as $a): ?>
                            <option value="<?= $a ?>" <?= $a === $anneeActive ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Groupe</label>
                    <select name="groupe_id" class="form-select">
                        <option value="">Tous les groupes</option>
                        <?php foreach ($groupes as $g): ?>
                            <option value="<?= $g['id'] ?>" <?= $g['id'] == $groupeFiltre ? 'selected' : '' ?>><?= sanitize($g['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filtrer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tableau -->
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Stagiaire</th>
                        <th>Groupe</th>
                        <th>Année</th>
                        <th class="text-center">Évaluations</th>
                        <th class="text-center">Moyenne</th>
                        <th>Inscrit le</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($stagiaires)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Aucun stagiaire trouvé.</td></tr>
                <?php else: ?>
                    <?php foreach ($stagiaires as $s): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= sanitize($s['prenom']) ?> <?= sanitize(strtoupper($s['nom'])) ?></div>
                        </td>
                        <td><span class="badge bg-secondary"><?= sanitize($s['groupe_nom']) ?></span></td>
                        <td><?= sanitize($s['annee_scolaire']) ?></td>
                        <td class="text-center">
                            <?php if ($s['nb_evaluations'] > 0): ?>
                                <span class="badge bg-info"><?= $s['nb_evaluations'] ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($s['moy_pourcentage'] !== null): ?>
                                <?php $mention = getMention((float)$s['moy_pourcentage']); ?>
                                <span class="badge bg-<?= $mention['class'] ?>"><?= round($s['moy_pourcentage'], 1) ?>%</span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= date('d/m/Y', strtotime($s['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
