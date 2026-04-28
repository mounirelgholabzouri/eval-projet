<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

// Filtres
$filterModule = (int)($_GET['module_id'] ?? 0);
$filterGroupe = trim($_GET['groupe'] ?? '');
$filterStatut = $_GET['statut'] ?? '';

// Export CSV
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="resultats_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
    fputcsv($out, ['Nom', 'Prénom', 'Groupe', 'Module', 'Date', 'Score', 'Total', 'Pourcentage', 'Statut'], ';');

    $stmt = $pdo->prepare("
        SELECT s.nom, s.prenom, COALESCE(g.nom, s.groupe_libre) AS groupe, m.nom AS module,
               s.date_debut, s.score, s.total_points, s.pourcentage, s.statut
        FROM sessions_eval s
        JOIN modules m ON m.id = s.module_id
        LEFT JOIN groupes g ON g.id = s.groupe_id
        ORDER BY s.date_debut DESC
    ");
    $stmt->execute();
    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $row['nom'], $row['prenom'], $row['groupe'], $row['module'],
            date('d/m/Y H:i', strtotime($row['date_debut'])),
            number_format($row['score'], 2, ',', ''),
            number_format($row['total_points'], 2, ',', ''),
            number_format($row['pourcentage'], 2, ',', '') . '%',
            $row['statut'] === 'termine' ? 'Terminé' : 'En cours'
        ], ';');
    }
    fclose($out);
    exit;
}

// Requête avec filtres
$where = ['1=1'];
$params = [];
if ($filterModule > 0) { $where[] = 's.module_id = ?'; $params[] = $filterModule; }
if ($filterGroupe)     { $where[] = "(g.nom LIKE ? OR s.groupe_libre LIKE ?)"; $params[] = "%$filterGroupe%"; $params[] = "%$filterGroupe%"; }
if ($filterStatut)     { $where[] = "s.statut = ?"; $params[] = $filterStatut; }

$whereStr = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT s.*, m.nom AS module_nom,
           COALESCE(g.nom, s.groupe_libre) AS groupe_nom
    FROM sessions_eval s
    JOIN modules m ON m.id = s.module_id
    LEFT JOIN groupes g ON g.id = s.groupe_id
    WHERE $whereStr
    ORDER BY s.date_debut DESC
    LIMIT 200
");
$stmt->execute($params);
$sessions = $stmt->fetchAll();

$allModules = getAllModules();
$stats = getStatsGlobales();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Résultats — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container-fluid py-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h2 class="h4 fw-bold mb-0"><i class="bi bi-bar-chart me-2 text-primary"></i>Résultats des évaluations</h2>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($filterModule): ?>
            <a href="print_exams.php?module_id=<?= $filterModule ?><?= $filterGroupe ? '&groupe='.urlencode($filterGroupe) : '' ?>"
               target="_blank" class="btn btn-dark">
                <i class="bi bi-printer me-2"></i>Imprimer les tests
            </a>
            <?php endif; ?>
            <a href="export_excel.php?<?= $filterModule ? "module_id=$filterModule" : '' ?><?= $filterGroupe ? "&groupe_id=".urlencode($filterGroupe) : '' ?>"
               class="btn btn-success">
                <i class="bi bi-file-earmark-excel me-2"></i>Exporter Excel
            </a>
            <a href="results.php?export=1<?= $filterModule ? "&module_id=$filterModule" : '' ?><?= $filterGroupe ? "&groupe=".urlencode($filterGroupe) : '' ?>"
               class="btn btn-outline-success">
                <i class="bi bi-filetype-csv me-2"></i>CSV
            </a>
        </div>
    </div>

    <!-- Stats rapides -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-3 text-center p-3">
                <div class="h3 fw-bold text-primary mb-0"><?= count($sessions) ?></div>
                <div class="text-muted small">Résultats affichés</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-3 text-center p-3">
                <?php
                $terminees = array_filter($sessions, fn($s) => $s['statut'] === 'termine');
                $moy = count($terminees) > 0
                    ? array_sum(array_column(array_values($terminees), 'pourcentage')) / count($terminees)
                    : 0;
                ?>
                <div class="h3 fw-bold text-success mb-0"><?= number_format($moy, 1) ?>%</div>
                <div class="text-muted small">Moyenne (filtre actif)</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-3 text-center p-3">
                <?php $reussi = array_filter($terminees, fn($s) => $s['pourcentage'] >= 50); ?>
                <div class="h3 fw-bold text-warning mb-0"><?= count($reussi) ?></div>
                <div class="text-muted small">Réussis (≥ 50%)</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-3 text-center p-3">
                <?php $enCours = array_filter($sessions, fn($s) => $s['statut'] === 'en_cours'); ?>
                <div class="h3 fw-bold text-info mb-0"><?= count($enCours) ?></div>
                <div class="text-muted small">En cours</div>
            </div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form method="GET" class="d-flex gap-3 flex-wrap align-items-end">
                <div>
                    <label class="form-label small fw-semibold mb-1">Module</label>
                    <select name="module_id" class="form-select form-select-sm" style="min-width:200px">
                        <option value="">Tous les modules</option>
                        <?php foreach ($allModules as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= $m['id'] == $filterModule ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['nom']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1">Groupe</label>
                    <input type="text" name="groupe" class="form-control form-control-sm"
                           placeholder="Rechercher un groupe..." value="<?= htmlspecialchars($filterGroupe) ?>">
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1">Statut</label>
                    <select name="statut" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        <option value="termine" <?= $filterStatut === 'termine' ? 'selected' : '' ?>>Terminé</option>
                        <option value="en_cours" <?= $filterStatut === 'en_cours' ? 'selected' : '' ?>>En cours</option>
                    </select>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-funnel me-1"></i>Filtrer
                    </button>
                    <a href="results.php" class="btn btn-outline-secondary btn-sm">Réinitialiser</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Tableau résultats -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Stagiaire</th>
                        <th>Groupe</th>
                        <th>Module</th>
                        <th>Date</th>
                        <th class="text-center">Score</th>
                        <th class="text-center">%</th>
                        <th class="text-center">Mention</th>
                        <th class="text-center">Statut</th>
                        <th class="text-center">Détail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sessions)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Aucun résultat trouvé</td></tr>
                    <?php endif; ?>
                    <?php foreach ($sessions as $s): ?>
                    <?php $mention = getMention((float)$s['pourcentage']); ?>
                    <tr>
                        <td class="ps-4 fw-semibold">
                            <?= htmlspecialchars($s['prenom'] . ' ' . $s['nom']) ?>
                        </td>
                        <td class="small text-muted"><?= htmlspecialchars($s['groupe_nom'] ?? '—') ?></td>
                        <td class="small"><?= htmlspecialchars($s['module_nom']) ?></td>
                        <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($s['date_debut'])) ?></td>
                        <td class="text-center small fw-semibold">
                            <?= $s['statut'] === 'termine'
                                ? number_format($s['score'], 1) . ' / ' . number_format($s['total_points'], 1)
                                : '—' ?>
                        </td>
                        <td class="text-center">
                            <?= $s['statut'] === 'termine'
                                ? number_format($s['pourcentage'], 1) . '%'
                                : '—' ?>
                        </td>
                        <td class="text-center">
                            <?php if ($s['statut'] === 'termine'): ?>
                            <span class="badge bg-<?= $mention['class'] ?>"><?= $mention['label'] ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($s['statut'] === 'termine'): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle small">Terminé</span>
                            <?php else: ?>
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle small">En cours</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <a href="detail.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary rounded-3">
                                <i class="bi bi-eye"></i>
                            </a>
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
