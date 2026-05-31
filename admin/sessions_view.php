<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

$filterModule = (int)($_GET['module_id'] ?? 0);
$filterJour   = trim($_GET['jour'] ?? '');

// ── Vue détail d'une session (module + jour) ──────────────────
if ($filterModule && $filterJour) {

    $module = getModule($filterModule);
    $noteMax = (int)($module['note_max'] ?? 20);

    $stmt = $pdo->prepare("
        SELECT s.*,
               COALESCE(st.nom,    s.nom)    AS nom,
               COALESCE(st.prenom, s.prenom) AS prenom,
               COALESCE(g.nom, s.groupe_libre) AS groupe_nom,
               e.note_max
        FROM sessions_eval s
        JOIN evaluations e ON e.id = s.evaluation_id
        JOIN modules     m ON m.id = e.module_id
        LEFT JOIN stagiaires st ON st.id = s.stagiaire_id
        LEFT JOIN groupes    g  ON g.id  = s.groupe_id
        WHERE e.module_id = ? AND DATE(s.date_debut) = ?
        ORDER BY s.pourcentage DESC, s.nom
    ");
    $stmt->execute([$filterModule, $filterJour]);
    $stagiaires = $stmt->fetchAll();

    $nbTotal   = count($stagiaires);
    $terminees = array_filter($stagiaires, fn($s) => $s['statut'] === 'termine');
    $moy       = count($terminees) > 0
        ? array_sum(array_column(array_values($terminees), 'pourcentage')) / count($terminees)
        : 0;
    $reussis   = count(array_filter($terminees, fn($s) => $s['pourcentage'] >= 50));

    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Session — <?= htmlspecialchars($module['nom']) ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        <link href="../assets/css/style.css" rel="stylesheet">
    </head>
    <body class="bg-light">
    <?php include __DIR__ . '/partials/navbar.php'; ?>
    <div class="container-fluid py-4 px-4">

        <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
            <a href="sessions_view.php" class="btn btn-sm btn-outline-secondary rounded-3">
                <i class="bi bi-arrow-left"></i>
            </a>
            <div>
                <h2 class="h4 fw-bold mb-0">
                    <i class="bi bi-calendar-event me-2 text-primary"></i>
                    <?= htmlspecialchars($module['nom']) ?>
                </h2>
                <div class="text-muted small">
                    <?= date('l d/m/Y', strtotime($filterJour)) ?> — <?= $nbTotal ?> stagiaire(s)
                </div>
            </div>
            <div class="ms-auto d-flex gap-2">
                <a href="results.php?module_id=<?= $filterModule ?>"
                   class="btn btn-sm btn-outline-primary rounded-3">
                    <i class="bi bi-list-ul me-1"></i>Vue liste
                </a>
                <a href="export_excel.php?module_id=<?= $filterModule ?>&jour=<?= urlencode($filterJour) ?>"
                   class="btn btn-sm btn-success rounded-3">
                    <i class="bi bi-file-earmark-excel me-1"></i>Excel
                </a>
            </div>
        </div>

        <!-- Stats rapides -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm rounded-3 text-center p-3">
                    <div class="h3 fw-bold text-primary mb-0"><?= $nbTotal ?></div>
                    <div class="text-muted small">Stagiaires</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm rounded-3 text-center p-3">
                    <div class="h3 fw-bold text-success mb-0"><?= number_format($moy, 1) ?>%</div>
                    <div class="text-muted small">Moyenne</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm rounded-3 text-center p-3">
                    <div class="h3 fw-bold text-warning mb-0"><?= $reussis ?></div>
                    <div class="text-muted small">Réussis ≥ 50%</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm rounded-3 text-center p-3">
                    <div class="h3 fw-bold text-danger mb-0"><?= count($terminees) - $reussis ?></div>
                    <div class="text-muted small">Insuffisants</div>
                </div>
            </div>
        </div>

        <!-- Tableau stagiaires -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">#</th>
                            <th>Stagiaire</th>
                            <th>Groupe</th>
                            <th class="text-center">Heure</th>
                            <th class="text-center">Note / <?= $noteMax ?></th>
                            <th class="text-center">%</th>
                            <th class="text-center">Mention</th>
                            <th class="text-center">Durée</th>
                            <th class="text-center">Statut</th>
                            <th class="text-center">Détail</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($stagiaires)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">Aucune session</td></tr>
                    <?php endif; ?>
                    <?php foreach ($stagiaires as $i => $s):
                        $mention = getMention((float)$s['pourcentage']);
                        $nm      = (int)($s['note_max'] ?? 20);
                        $sc      = $s['total_points'] > 0 ? round($s['score'] / $s['total_points'] * $nm, 2) : 0;
                        $duree   = ($s['date_fin'] && $s['date_debut'])
                            ? round((strtotime($s['date_fin']) - strtotime($s['date_debut'])) / 60) . ' min'
                            : '—';
                    ?>
                    <tr>
                        <td class="ps-4 text-muted small"><?= $i + 1 ?></td>
                        <td class="fw-semibold">
                            <?= htmlspecialchars($s['prenom'] . ' ' . strtoupper($s['nom'])) ?>
                        </td>
                        <td class="small text-muted"><?= htmlspecialchars($s['groupe_nom'] ?? '—') ?></td>
                        <td class="text-center small text-muted">
                            <?= date('H:i', strtotime($s['date_debut'])) ?>
                        </td>
                        <td class="text-center fw-bold">
                            <?= $s['statut'] === 'termine' ? number_format($sc, 2) . ' / ' . $nm : '—' ?>
                        </td>
                        <td class="text-center">
                            <?php if ($s['statut'] === 'termine'): ?>
                            <span class="fw-semibold text-<?= $mention['class'] ?>">
                                <?= number_format($s['pourcentage'], 1) ?>%
                            </span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($s['statut'] === 'termine'): ?>
                            <span class="badge bg-<?= $mention['class'] ?>"><?= $mention['label'] ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="text-center small text-muted"><?= $duree ?></td>
                        <td class="text-center">
                            <?php if ($s['statut'] === 'termine'): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Terminé</span>
                            <?php else: ?>
                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle">En cours</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <a href="detail.php?id=<?= $s['id'] ?>"
                               class="btn btn-sm btn-outline-primary rounded-3" title="Voir le détail">
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
    <?php
    exit;
}

// ── Vue liste des sessions (module + jour) ────────────────────
$whereModule = $filterModule ? "AND e.module_id = $filterModule" : '';

$sessions = $pdo->query("
    SELECT e.module_id,
           MAX(m.nom)       AS module_nom,
           MAX(e.note_max)  AS note_max,
           DATE(s.date_debut) AS jour,
           COUNT(*) AS nb_total,
           SUM(CASE WHEN s.statut='termine' THEN 1 ELSE 0 END) AS nb_terminees,
           ROUND(AVG(CASE WHEN s.statut='termine' THEN s.pourcentage END), 1) AS moy_pct,
           SUM(CASE WHEN s.statut='termine' AND s.pourcentage >= 50 THEN 1 ELSE 0 END) AS nb_reussis,
           MIN(s.date_debut) AS heure_debut,
           MAX(s.date_fin)   AS heure_fin
    FROM sessions_eval s
    JOIN evaluations e ON e.id = s.evaluation_id
    JOIN modules     m ON m.id = e.module_id
    WHERE 1=1 $whereModule
    GROUP BY e.module_id, DATE(s.date_debut)
    ORDER BY jour DESC, module_nom
")->fetchAll();

$allModules = getAllModules();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sessions d'évaluation — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container-fluid py-4 px-4">

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h2 class="h4 fw-bold mb-0">
            <i class="bi bi-calendar-check me-2 text-primary"></i>Sessions d'évaluation
        </h2>
    </div>

    <!-- Filtre module -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form method="GET" class="d-flex gap-3 align-items-end flex-wrap">
                <div>
                    <label class="form-label small fw-semibold mb-1">Module</label>
                    <select name="module_id" class="form-select form-select-sm" style="min-width:220px"
                            onchange="this.form.submit()">
                        <option value="">Tous les modules</option>
                        <?php foreach ($allModules as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= $m['id'] == $filterModule ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['nom']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-funnel me-1"></i>Filtrer
                    </button>
                    <a href="sessions_view.php" class="btn btn-outline-secondary btn-sm">Réinitialiser</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Tableau sessions -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Date</th>
                        <th>Module</th>
                        <th class="text-center">Stagiaires</th>
                        <th class="text-center">Terminées</th>
                        <th class="text-center">Moyenne</th>
                        <th class="text-center">Réussis ≥ 50%</th>
                        <th class="text-center">Taux réussite</th>
                        <th class="text-center">Voir résultats</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($sessions)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-5">Aucune session trouvée</td></tr>
                <?php endif; ?>
                <?php foreach ($sessions as $s):
                    $tauxReussite = $s['nb_terminees'] > 0
                        ? round($s['nb_reussis'] / $s['nb_terminees'] * 100) : 0;
                    $moyClass = $s['moy_pct'] >= 75 ? 'success'
                              : ($s['moy_pct'] >= 50 ? 'warning' : 'danger');
                ?>
                <tr>
                    <td class="ps-4">
                        <div class="fw-semibold"><?= date('d/m/Y', strtotime($s['jour'])) ?></div>
                        <div class="text-muted small"><?= date('l', strtotime($s['jour'])) ?></div>
                    </td>
                    <td>
                        <span class="fw-semibold"><?= htmlspecialchars($s['module_nom']) ?></span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-primary rounded-pill fs-6"><?= $s['nb_total'] ?></span>
                    </td>
                    <td class="text-center">
                        <?php if ($s['nb_terminees'] < $s['nb_total']): ?>
                        <span class="text-warning fw-semibold"><?= $s['nb_terminees'] ?> / <?= $s['nb_total'] ?></span>
                        <?php else: ?>
                        <span class="text-success fw-semibold"><?= $s['nb_terminees'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="fw-bold text-<?= $moyClass ?>">
                            <?= $s['moy_pct'] !== null ? number_format($s['moy_pct'], 1) . '%' : '—' ?>
                        </span>
                    </td>
                    <td class="text-center fw-semibold text-success"><?= $s['nb_reussis'] ?></td>
                    <td class="text-center">
                        <?php if ($s['nb_terminees'] > 0): ?>
                        <div class="d-flex align-items-center gap-2 justify-content-center">
                            <div class="progress flex-grow-1" style="height:8px; max-width:80px">
                                <div class="progress-bar bg-<?= $moyClass ?>"
                                     style="width:<?= $tauxReussite ?>%"></div>
                            </div>
                            <span class="small fw-semibold text-<?= $moyClass ?>"><?= $tauxReussite ?>%</span>
                        </div>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="text-center">
                        <a href="sessions_view.php?module_id=<?= $s['module_id'] ?>&jour=<?= urlencode($s['jour']) ?>"
                           class="btn btn-sm btn-primary rounded-3">
                            <i class="bi bi-people me-1"></i>Voir les <?= $s['nb_total'] ?> stagiaires
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
