<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$stats    = getStatsGlobales();
$sessions = getAllSessions(10, 0);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">

<?php include __DIR__ . '/partials/navbar.php'; ?>

<div class="container-fluid py-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h4 fw-bold mb-0">
            <i class="bi bi-speedometer2 me-2 text-primary"></i>Tableau de bord
        </h2>
        <span class="text-muted small">Bonjour, <?= htmlspecialchars($_SESSION['admin_name']) ?></span>
    </div>

    <!-- Statistiques -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm rounded-4 text-center p-3">
                <div class="text-primary display-6 mb-1"><i class="bi bi-person-badge-fill"></i></div>
                <div class="h3 fw-bold mb-0"><?= $stats['nb_stagiaires'] ?></div>
                <div class="text-muted small">Stagiaires</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm rounded-4 text-center p-3">
                <div class="text-secondary display-6 mb-1"><i class="bi bi-people-fill"></i></div>
                <div class="h3 fw-bold mb-0"><?= $stats['nb_groupes'] ?></div>
                <div class="text-muted small">Groupes</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm rounded-4 text-center p-3">
                <div class="text-info display-6 mb-1"><i class="bi bi-journal-text"></i></div>
                <div class="h3 fw-bold mb-0"><?= $stats['nb_modules'] ?></div>
                <div class="text-muted small">Modules actifs</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm rounded-4 text-center p-3">
                <div class="text-warning display-6 mb-1"><i class="bi bi-pencil-square"></i></div>
                <div class="h3 fw-bold mb-0"><?= $stats['total_sessions'] ?></div>
                <div class="text-muted small">Évaluations</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm rounded-4 text-center p-3">
                <div class="text-success display-6 mb-1"><i class="bi bi-check-circle-fill"></i></div>
                <div class="h3 fw-bold mb-0"><?= $stats['terminees'] ?></div>
                <div class="text-muted small">Terminées</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm rounded-4 text-center p-3">
                <div class="text-danger display-6 mb-1"><i class="bi bi-graph-up"></i></div>
                <div class="h3 fw-bold mb-0"><?= number_format($stats['moy_pourcentage'], 1) ?>%</div>
                <div class="text-muted small">Moyenne générale</div>
            </div>
        </div>
    </div>

    <!-- Accès rapides -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <a href="stagiaires.php" class="card border-0 shadow-sm rounded-4 text-decoration-none h-100">
                <div class="card-body p-4 d-flex align-items-center gap-3">
                    <div class="quick-icon bg-primary-subtle text-primary">
                        <i class="bi bi-person-plus-fill fs-4"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">Gérer les stagiaires</div>
                        <div class="text-muted small">Ajouter, modifier, réinitialiser</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="groupes.php" class="card border-0 shadow-sm rounded-4 text-decoration-none h-100">
                <div class="card-body p-4 d-flex align-items-center gap-3">
                    <div class="quick-icon bg-secondary-subtle text-secondary">
                        <i class="bi bi-people-fill fs-4"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">Gérer les groupes</div>
                        <div class="text-muted small">Créer et organiser les groupes</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="modules.php" class="card border-0 shadow-sm rounded-4 text-decoration-none h-100">
                <div class="card-body p-4 d-flex align-items-center gap-3">
                    <div class="quick-icon bg-info-subtle text-info">
                        <i class="bi bi-journal-plus fs-4"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">Gérer les modules</div>
                        <div class="text-muted small">Créer et publier les modules QCM</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="questions.php" class="card border-0 shadow-sm rounded-4 text-decoration-none h-100">
                <div class="card-body p-4 d-flex align-items-center gap-3">
                    <div class="quick-icon bg-success-subtle text-success">
                        <i class="bi bi-question-circle fs-4"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">Gérer les questions</div>
                        <div class="text-muted small">Ajouter et éditer les questions</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="generate.php" class="card border-0 shadow-sm rounded-4 text-decoration-none h-100">
                <div class="card-body p-4 d-flex align-items-center gap-3">
                    <div class="quick-icon bg-warning-subtle text-warning">
                        <i class="bi bi-stars fs-4"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">Génération IA</div>
                        <div class="text-muted small">Créer des QCM avec Claude</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="results.php" class="card border-0 shadow-sm rounded-4 text-decoration-none h-100">
                <div class="card-body p-4 d-flex align-items-center gap-3">
                    <div class="quick-icon bg-danger-subtle text-danger">
                        <i class="bi bi-bar-chart-fill fs-4"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">Voir les résultats</div>
                        <div class="text-muted small">Consulter et corriger</div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Dernières sessions -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-0 py-3 px-4 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2 text-primary"></i>Dernières évaluations</h5>
            <a href="results.php" class="btn btn-sm btn-outline-primary">Voir tout</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Stagiaire</th>
                        <th>Groupe</th>
                        <th>Module</th>
                        <th>Date</th>
                        <th class="text-center">Score</th>
                        <th class="text-center">Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sessions)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Aucune évaluation pour le moment</td></tr>
                    <?php endif; ?>
                    <?php foreach ($sessions as $s): ?>
                    <tr>
                        <td class="ps-4 fw-semibold">
                            <?= htmlspecialchars($s['prenom'] . ' ' . $s['nom']) ?>
                        </td>
                        <td class="text-muted small"><?= htmlspecialchars($s['groupe_nom'] ?? '—') ?></td>
                        <td class="small"><?= htmlspecialchars($s['module_nom']) ?></td>
                        <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($s['date_debut'])) ?></td>
                        <td class="text-center">
                            <?php if ($s['statut'] === 'termine'): ?>
                                <?php $m = getMention((float)$s['pourcentage']); ?>
                                <span class="badge bg-<?= $m['class'] ?>">
                                    <?= number_format((float)$s['pourcentage'], 0) ?>%
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($s['statut'] === 'termine'): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">Terminé</span>
                            <?php else: ?>
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle">En cours</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
