<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$msg    = '';
$erreur = '';

// ── Suppression d'un résultat ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete_session'])) {
    $delId = (int)($_POST['delete_id'] ?? 0);
    if ($delId > 0) {
        supprimerSession($delId);
        $msg = "Résultat supprimé.";
    }
}

// ── Suppression en masse ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $bulkAction = $_POST['bulk_action'] ?? '';
    $selectedIds = array_map('intval', $_POST['selected_ids'] ?? []);

    if (!empty($selectedIds)) {
        if ($bulkAction === 'delete') {
            foreach ($selectedIds as $id) {
                supprimerSession($id);
            }
            $msg = count($selectedIds) . " résultat(s) supprimé(s).";
        }
    }
}

// Filtres
$filterModule    = (int)($_GET['module_id'] ?? 0);
$filterGroupe    = trim($_GET['groupe'] ?? '');
$filterStatut    = $_GET['statut'] ?? '';
$filterCorrection = $_GET['correction'] ?? '';

// Export CSV
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="resultats_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
    fputcsv($out, ['Nom', 'Prénom', 'Groupe', 'Module', 'Date', 'Score', 'Total', 'Pourcentage', 'Statut'], ';');

    $csvWhere  = ['1=1'];
    $csvParams = [];
    $csvWhereStr = implode(' AND ', $csvWhere);
    $stmt = $pdo->prepare("
        SELECT COALESCE(st.nom,    s.nom)    AS nom,
               COALESCE(st.prenom, s.prenom) AS prenom,
               COALESCE(g.nom, s.groupe_libre) AS groupe,
               m.nom AS module,
               s.date_debut, s.score, s.total_points, s.pourcentage, s.statut
        FROM sessions_eval s
        JOIN modules m ON m.id = s.module_id
        LEFT JOIN stagiaires st ON st.id = s.stagiaire_id
        LEFT JOIN groupes g ON g.id = s.groupe_id
        WHERE $csvWhereStr
        ORDER BY s.date_debut DESC
    ");
    $stmt->execute($csvParams);
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
$where  = ['1=1'];
$params = [];

if ($filterModule > 0) { $where[] = 's.module_id = ?'; $params[] = $filterModule; }
if ($filterGroupe)     { $where[] = "(g.nom LIKE ? OR s.groupe_libre LIKE ?)"; $params[] = "%$filterGroupe%"; $params[] = "%$filterGroupe%"; }
if ($filterStatut)     { $where[] = "s.statut = ?"; $params[] = $filterStatut; }

// Filtre correction
if ($filterCorrection === 'non_corrigee') {
    $where[] = "EXISTS (SELECT 1 FROM reponses_stagiaires rs2 JOIN questions q2 ON q2.id=rs2.question_id WHERE rs2.session_id=s.id AND q2.type='texte_libre' AND rs2.source_correction IS NULL)";
} elseif ($filterCorrection === 'corrigee_ia') {
    $where[] = "NOT EXISTS (SELECT 1 FROM reponses_stagiaires rs2 JOIN questions q2 ON q2.id=rs2.question_id WHERE rs2.session_id=s.id AND q2.type='texte_libre' AND rs2.source_correction IS NULL)
                AND EXISTS (SELECT 1 FROM reponses_stagiaires rs3 JOIN questions q3 ON q3.id=rs3.question_id WHERE rs3.session_id=s.id AND q3.type='texte_libre' AND rs3.source_correction='ia')";
} elseif ($filterCorrection === 'corrigee') {
    $where[] = "NOT EXISTS (SELECT 1 FROM reponses_stagiaires rs2 JOIN questions q2 ON q2.id=rs2.question_id WHERE rs2.session_id=s.id AND q2.type='texte_libre' AND rs2.source_correction IS NULL)
                AND EXISTS (SELECT 1 FROM reponses_stagiaires rs3 JOIN questions q3 ON q3.id=rs3.question_id WHERE rs3.session_id=s.id AND q3.type='texte_libre' AND rs3.source_correction IS NOT NULL)";
}

$whereStr = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT s.*,
           COALESCE(st.nom,    s.nom)    AS nom,
           COALESCE(st.prenom, s.prenom) AS prenom,
           m.nom AS module_nom, m.type AS module_type,
           COALESCE(g.nom, s.groupe_libre) AS groupe_nom,
           (SELECT COUNT(*) FROM reponses_stagiaires rs JOIN questions q ON q.id=rs.question_id
            WHERE rs.session_id=s.id AND q.type='texte_libre') AS nb_tl,
           (SELECT COUNT(*) FROM reponses_stagiaires rs JOIN questions q ON q.id=rs.question_id
            WHERE rs.session_id=s.id AND q.type='texte_libre' AND rs.source_correction IS NOT NULL) AS nb_tl_corrige,
           (SELECT COUNT(*) FROM reponses_stagiaires rs JOIN questions q ON q.id=rs.question_id
            WHERE rs.session_id=s.id AND q.type='texte_libre' AND rs.source_correction='ia') AS nb_tl_ia
    FROM sessions_eval s
    JOIN modules m ON m.id = s.module_id
    LEFT JOIN stagiaires st ON st.id = s.stagiaire_id
    LEFT JOIN groupes g ON g.id = s.groupe_id
    WHERE $whereStr
    ORDER BY s.date_debut DESC
    LIMIT 200
");
$stmt->execute($params);
$sessions = $stmt->fetchAll();

$allModules = getAllModules();

// Déterminer si le module filtré est un EFM et construire l'URL d'impression
$filteredModuleIsEfm = false;
$printEfmUrl = '';
if ($filterModule) {
    foreach ($allModules as $m) {
        if ((int)$m['id'] === $filterModule && ($m['type'] ?? '') === 'efm') {
            $filteredModuleIsEfm = true;
            $dureeMin = (int)($m['duree_minutes'] ?? 0);
            $dureeStr = $dureeMin >= 60
                ? floor($dureeMin/60).'h'.($dureeMin%60>0 ? sprintf('%02d',$dureeMin%60) : '')
                : ($dureeMin > 0 ? $dureeMin.' min' : '');
            $printEfmUrl = 'print_efm.php?' . http_build_query([
                'module_id'     => $filterModule,
                'shuffle'       => 0,
                'shuffle_choix' => 0,
                'corrige'       => 0,
                'code_module'   => $m['efm_code_module']   ?? '',
                'filiere'       => $m['efm_filiere']        ?? '',
                'etablissement' => $m['efm_etablissement']  ?? '',
                'annee'         => $m['efm_annee']          ?? '',
                'note_max'      => (int)($m['note_max']     ?? 40),
                'intitule'      => $m['nom'],
                'duree'         => $dureeStr,
            ]);
            break;
        }
    }
}
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
    <?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show rounded-3 mb-4">
        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h2 class="h4 fw-bold mb-0"><i class="bi bi-bar-chart me-2 text-primary"></i>Résultats des évaluations</h2>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($filterModule): ?>
            <?php if ($filteredModuleIsEfm): ?>
            <a href="<?= htmlspecialchars($printEfmUrl) ?>"
               target="_blank" class="btn btn-dark">
                <i class="bi bi-printer me-2"></i>Imprimer sujet EFM
            </a>
            <a href="generate_efm_pdf.php?module_id=<?= $filterModule ?>"
               class="btn btn-danger">
                <i class="bi bi-file-earmark-pdf me-2"></i>Générer tous les PDFs EFM
            </a>
            <?php else: ?>
            <a href="print_exams.php?module_id=<?= $filterModule ?><?= $filterGroupe ? '&groupe='.urlencode($filterGroupe) : '' ?>"
               target="_blank" class="btn btn-dark">
                <i class="bi bi-printer me-2"></i>Imprimer les tests
            </a>
            <?php endif; ?>
            <?php endif; ?>
            <button type="button" class="btn btn-info" id="btn-valider-tout" onclick="validerTousVisible()">
                <i class="bi bi-check-all me-1"></i>Valider toutes notes IA
            </button>
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
                    <select name="module_id" class="form-select form-select-sm" style="min-width:200px"
                            onchange="this.form.submit()">
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
                    <select name="statut" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Tous</option>
                        <option value="termine" <?= $filterStatut === 'termine' ? 'selected' : '' ?>>Terminé</option>
                        <option value="en_cours" <?= $filterStatut === 'en_cours' ? 'selected' : '' ?>>En cours</option>
                    </select>
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1">Correction</label>
                    <select name="correction" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Toutes</option>
                        <option value="non_corrigee"  <?= $filterCorrection === 'non_corrigee'  ? 'selected' : '' ?>>Non corrigée</option>
                        <option value="corrigee"      <?= $filterCorrection === 'corrigee'      ? 'selected' : '' ?>>Corrigée</option>
                        <option value="corrigee_ia"   <?= $filterCorrection === 'corrigee_ia'   ? 'selected' : '' ?>>Corrigée par IA</option>
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
        <!-- Barre d'actions en masse -->
        <div id="bulkActionBar" class="card-header bg-light border-bottom py-3 px-4" style="display: none;">
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <span class="text-muted">
                    <span id="bulkCount">0</span> sélectionné(s)
                </span>
                <button type="button" class="btn btn-outline-info btn-sm" onclick="bulkCorrigerIA()">
                    <i class="bi bi-robot me-1"></i>Corriger IA
                </button>
                <button type="button" class="btn btn-outline-success btn-sm" onclick="bulkValiderNotes()">
                    <i class="bi bi-check-all me-1"></i>Valider notes IA
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="bulkDeleteResults()">
                    <i class="bi bi-trash me-1"></i>Supprimer
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" onclick="clearSelection()">
                    Annuler
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" style="width: 40px;">
                            <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll()">
                        </th>
                        <th class="ps-4">Stagiaire</th>
                        <th>Groupe</th>
                        <th>Module</th>
                        <th>Date</th>
                        <th class="text-center">Score</th>
                        <th class="text-center">%</th>
                        <th class="text-center">Mention</th>
                        <th class="text-center">Statut</th>
                        <th class="text-center">Correction</th>
                        <th class="text-center">Détail</th>
                        <th class="text-center">Suppr.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sessions)): ?>
                    <tr><td colspan="12" class="text-center text-muted py-4">Aucun résultat trouvé</td></tr>
                    <?php endif; ?>
                    <?php foreach ($sessions as $s): ?>
                    <?php $mention = getMention((float)$s['pourcentage']); ?>
                    <tr>
                        <td class="ps-4">
                            <input type="checkbox" class="form-check-input result-checkbox" value="<?= $s['id'] ?>" onchange="updateBulkActionBar()">
                        </td>
                        <td class="ps-4 fw-semibold">
                            <?= htmlspecialchars($s['prenom'] . ' ' . $s['nom']) ?>
                        </td>
                        <td class="small text-muted"><?= htmlspecialchars($s['groupe_nom'] ?? '—') ?></td>
                        <td class="small"><?= htmlspecialchars($s['module_nom']) ?></td>
                        <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($s['date_debut'])) ?></td>
                        <td class="text-center small fw-semibold" id="score-<?= $s['id'] ?>">
                            <?= $s['statut'] === 'termine'
                                ? number_format($s['score'], 1) . ' / ' . number_format($s['total_points'], 1)
                                : '—' ?>
                        </td>
                        <td class="text-center" id="pct-<?= $s['id'] ?>">
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
                        <?php
                        $nbTl      = (int)($s['nb_tl'] ?? 0);
                        $nbCorrige = (int)($s['nb_tl_corrige'] ?? 0);
                        $nbIa      = (int)($s['nb_tl_ia'] ?? 0);
                        ?>
                        <td class="text-center correction-cell"
                            id="corr-cell-<?= $s['id'] ?>"
                            data-session-id="<?= $s['id'] ?>"
                            data-nb-tl="<?= $nbTl ?>"
                            data-nb-corrige="<?= $nbCorrige ?>"
                            data-nb-ia="<?= $nbIa ?>">
                            <div id="corr-badge-<?= $s['id'] ?>">
                            <?php if ($nbTl === 0): ?>
                                <span class="text-muted small">—</span>
                            <?php elseif ($nbCorrige === 0): ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle small">Non corrigée</span>
                            <?php elseif ($nbCorrige < $nbTl): ?>
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle small">Partielle</span>
                            <?php elseif ($nbIa === $nbTl): ?>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle small"><i class="bi bi-robot me-1"></i>Corrigée IA</span>
                            <?php else: ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle small">Corrigée</span>
                            <?php endif; ?>
                            </div>
                            <?php if ($nbTl > 0): ?>
                            <div class="mt-1 d-flex gap-1 justify-content-center" id="corr-btns-<?= $s['id'] ?>">
                                <?php if ($nbCorrige < $nbTl): ?>
                                <button type="button" class="btn btn-outline-info py-0 px-1" style="font-size:11px;"
                                        title="Corriger avec IA"
                                        onclick="corrigerSessionIA(<?= $s['id'] ?>, this)">
                                    <i class="bi bi-robot"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($nbIa > 0): ?>
                                <button type="button" class="btn btn-outline-success py-0 px-1" style="font-size:11px;"
                                        title="Valider notes IA"
                                        onclick="validerSessionNotes(<?= $s['id'] ?>, this)">
                                    <i class="bi bi-check-all"></i>
                                </button>
                                <?php endif; ?>
                                <a href="detail.php?id=<?= $s['id'] ?>" class="btn btn-outline-warning py-0 px-1" style="font-size:11px;"
                                   title="Correction manuelle">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="d-flex gap-1 justify-content-center">
                                <a href="detail.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary rounded-3" title="Détail">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if ($s['statut'] === 'termine'): ?>
                                <?php if (($s['module_type'] ?? '') === 'efm'): ?>
                                <a href="print_efm_result.php?session_id=<?= $s['id'] ?>" target="_blank"
                                   class="btn btn-sm btn-outline-dark rounded-3" title="Voir fiche EFM">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="generate_efm_pdf.php?session_id=<?= $s['id'] ?>"
                                   class="btn btn-sm btn-danger rounded-3" title="Télécharger PDF EFM">
                                    <i class="bi bi-file-earmark-pdf"></i>
                                </a>
                                <?php else: ?>
                                <a href="print_exams.php?session_id=<?= $s['id'] ?>" target="_blank"
                                   class="btn btn-sm btn-outline-dark rounded-3" title="Imprimer ce test">
                                    <i class="bi bi-printer"></i>
                                </a>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-danger rounded-3"
                                    title="Supprimer ce résultat"
                                    onclick='confirmDeleteSession(<?= $s['id'] ?>, <?= json_encode($s['prenom'] . ' ' . $s['nom']) ?>, <?= json_encode($s['module_nom']) ?>)'>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Modal suppression résultat -->
<div class="modal fade" id="deleteSessionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Supprimer ce résultat</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Supprimer définitivement le résultat de :</p>
                <p class="fw-bold fs-5 mb-1" id="delSessNom"></p>
                <p class="text-muted mb-3" id="delSessModule"></p>
                <p class="text-danger fw-semibold mb-0">Toutes les réponses associées seront effacées. Cette action est irréversible.</p>
            </div>
            <div class="modal-footer border-0">
                <form method="POST" action="results.php?<?= http_build_query(array_filter(['module_id'=>$filterModule,'groupe'=>$filterGroupe,'statut'=>$filterStatut])) ?>">
                    <input type="hidden" name="confirm_delete_session" value="1">
                    <input type="hidden" name="delete_id" id="delSessId">
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
function confirmDeleteSession(id, nom, module) {
    document.getElementById('delSessId').value = id;
    document.getElementById('delSessNom').textContent    = nom;
    document.getElementById('delSessModule').textContent = module;
    new bootstrap.Modal(document.getElementById('deleteSessionModal')).show();
}

// ── Fonctions sélection multiple ─────────────────────────────
function updateBulkActionBar() {
    const checkboxes = document.querySelectorAll('.result-checkbox:checked');
    const bar = document.getElementById('bulkActionBar');
    const count = document.getElementById('bulkCount');
    const selectAll = document.getElementById('selectAll');

    count.textContent = checkboxes.length;
    bar.style.display = checkboxes.length > 0 ? 'block' : 'none';

    const allCheckboxes = document.querySelectorAll('.result-checkbox');
    selectAll.checked = allCheckboxes.length > 0 && checkboxes.length === allCheckboxes.length;
    selectAll.indeterminate = checkboxes.length > 0 && checkboxes.length < allCheckboxes.length;
}

function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    document.querySelectorAll('.result-checkbox').forEach(cb => {
        cb.checked = selectAll.checked;
    });
    updateBulkActionBar();
}

function clearSelection() {
    document.querySelectorAll('.result-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateBulkActionBar();
}

function bulkDeleteResults() {
    const checkboxes = document.querySelectorAll('.result-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Sélectionnez au moins un résultat.');
        return;
    }
    if (confirm('Êtes-vous sûr de vouloir supprimer ' + checkboxes.length + ' résultat(s) ?\nToutes les réponses associées seront effacées.')) {
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

// ── Rendu badge correction ────────────────────────────────────
function renderBadge(nbTl, nbCorrige, nbIa) {
    if (nbTl === 0) return '<span class="text-muted small">—</span>';
    if (nbCorrige === 0) return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle small">Non corrigée</span>';
    if (nbCorrige < nbTl) return '<span class="badge bg-warning-subtle text-warning border border-warning-subtle small">Partielle</span>';
    if (nbIa === nbTl)   return '<span class="badge bg-primary-subtle text-primary border border-primary-subtle small"><i class="bi bi-robot me-1"></i>Corrigée IA</span>';
    return '<span class="badge bg-success-subtle text-success border border-success-subtle small">Corrigée</span>';
}

function renderCorrBtns(sessionId, nbTl, nbCorrige, nbIa) {
    if (nbTl === 0) return '';
    let btns = '';
    if (nbCorrige < nbTl) {
        btns += `<button type="button" class="btn btn-outline-info py-0 px-1" style="font-size:11px;" title="Corriger avec IA" onclick="corrigerSessionIA(${sessionId}, this)"><i class="bi bi-robot"></i></button>`;
    }
    if (nbIa > 0) {
        btns += `<button type="button" class="btn btn-outline-success py-0 px-1" style="font-size:11px;" title="Valider notes IA" onclick="validerSessionNotes(${sessionId}, this)"><i class="bi bi-check-all"></i></button>`;
    }
    btns += `<a href="detail.php?id=${sessionId}" class="btn btn-outline-warning py-0 px-1" style="font-size:11px;" title="Correction manuelle"><i class="bi bi-pencil"></i></a>`;
    return btns;
}

function updateCorrCell(sessionId, nbTl, nbCorrige, nbIa) {
    const cell = document.getElementById('corr-cell-' + sessionId);
    if (!cell) return;
    cell.dataset.nbTl      = nbTl;
    cell.dataset.nbCorrige = nbCorrige;
    cell.dataset.nbIa      = nbIa;
    document.getElementById('corr-badge-' + sessionId).innerHTML = renderBadge(nbTl, nbCorrige, nbIa);
    const btnsEl = document.getElementById('corr-btns-' + sessionId);
    if (btnsEl) btnsEl.innerHTML = renderCorrBtns(sessionId, nbTl, nbCorrige, nbIa);
}

function updateRowScore(sessionId, score, total, pct) {
    const scoreEl = document.getElementById('score-' + sessionId);
    const pctEl   = document.getElementById('pct-'   + sessionId);
    if (scoreEl) scoreEl.textContent = parseFloat(score).toFixed(1) + ' / ' + parseFloat(total).toFixed(1);
    if (pctEl)   pctEl.textContent   = parseFloat(pct).toFixed(1) + '%';
}

// ── Toast notification ────────────────────────────────────────
function showToast(message, type = 'success') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;';
        document.body.appendChild(container);
    }
    const t = document.createElement('div');
    t.className = `alert alert-${type} alert-dismissible shadow mb-0 py-2 px-3`;
    t.style.cssText = 'min-width:240px;font-size:13px;animation:fadeInUp .2s ease;';
    t.innerHTML = message + `<button type="button" class="btn-close btn-sm" onclick="this.parentElement.remove()"></button>`;
    container.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

// ── Corriger IA une session ───────────────────────────────────
async function corrigerSessionIA(sessionId, btn) {
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';

    try {
        const res  = await fetch('api_correct_ia_all.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'session_id=' + sessionId
        });
        const data = await res.json();
        if (!data.success) { showToast('<i class="bi bi-x-circle me-1"></i>' + data.error, 'danger'); btn.innerHTML = orig; btn.disabled = false; return; }

        const cell  = document.getElementById('corr-cell-' + sessionId);
        const nbTl  = parseInt(cell.dataset.nbTl || 0);
        // corriges = réponses avec texte corrigées par IA
        // les sans-réponse ont été validées à 0 (source=manuel) → nbCorrige = nbTl
        const nbIa      = data.corriges || 0;
        const nbCorrige = nbTl; // toutes sont maintenant traitées
        updateCorrCell(sessionId, nbTl, nbCorrige, nbIa);
        updateRowScore(sessionId, data.score, data.total, data.pct);

        const pts = parseFloat(data.score).toFixed(1);
        const pct = parseFloat(data.pct).toFixed(1);
        if (nbIa > 0) {
            showToast(`<i class="bi bi-robot me-1"></i>Correction IA : ${nbIa} réponse(s) — ${pts} pts (${pct}%)`, 'info');
        } else {
            showToast(`<i class="bi bi-check-circle me-1"></i>Validé : aucune réponse saisie — 0 pt`, 'success');
        }

    } catch (e) {
        showToast('<i class="bi bi-x-circle me-1"></i>Erreur réseau : ' + e.message, 'danger');
        btn.innerHTML = orig;
        btn.disabled  = false;
    }
}

// ── Valider notes IA une session ─────────────────────────────
async function validerSessionNotes(sessionId, btn) {
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';

    try {
        const res  = await fetch('api_validate_all_notes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'session_id=' + sessionId
        });
        const data = await res.json();
        if (!data.success) { showToast('<i class="bi bi-x-circle me-1"></i>' + data.error, 'danger'); btn.innerHTML = orig; btn.disabled = false; return; }

        const cell  = document.getElementById('corr-cell-' + sessionId);
        const nbTl  = parseInt(cell.dataset.nbTl || 0);
        // Après validation manuelle : toutes corrigées, aucune marquée IA
        updateCorrCell(sessionId, nbTl, nbTl, 0);
        updateRowScore(sessionId, data.score, data.total, data.pct);

        const pts = parseFloat(data.score).toFixed(1);
        const pct = parseFloat(data.pct).toFixed(1);
        showToast(`<i class="bi bi-check-all me-1"></i>${data.validees} note(s) validée(s) — ${pts} pts (${pct}%)`, 'success');

    } catch (e) {
        showToast('<i class="bi bi-x-circle me-1"></i>Erreur réseau : ' + e.message, 'danger');
        btn.innerHTML = orig;
        btn.disabled  = false;
    }
}

// ── Bulk IA ───────────────────────────────────────────────────
async function bulkCorrigerIA() {
    const ids = [...document.querySelectorAll('.result-checkbox:checked')].map(cb => parseInt(cb.value));
    if (!ids.length) { alert('Sélectionnez au moins un résultat.'); return; }
    if (!confirm('Corriger avec IA les ' + ids.length + ' session(s) sélectionnée(s) ?')) return;

    for (const id of ids) {
        const cell = document.getElementById('corr-cell-' + id);
        if (!cell || parseInt(cell.dataset.nbTl) === 0) continue;
        const fakeBtn = { innerHTML: '', disabled: false };
        await corrigerSessionIA(id, fakeBtn);
    }
}

async function bulkValiderNotes() {
    const ids = [...document.querySelectorAll('.result-checkbox:checked')].map(cb => parseInt(cb.value));
    if (!ids.length) { alert('Sélectionnez au moins un résultat.'); return; }
    if (!confirm('Valider les notes IA pour les ' + ids.length + ' session(s) sélectionnée(s) ?')) return;

    for (const id of ids) {
        const cell = document.getElementById('corr-cell-' + id);
        if (!cell || parseInt(cell.dataset.nbIa) === 0) continue;
        const fakeBtn = { innerHTML: '', disabled: false };
        await validerSessionNotes(id, fakeBtn);
    }
}

// ── Valider toutes les sessions visibles ──────────────────────
async function validerTousVisible() {
    const cells = document.querySelectorAll('.correction-cell[data-nb-ia]');
    const toValidate = [...cells].filter(c => parseInt(c.dataset.nbIa) > 0);
    if (!toValidate.length) { alert('Aucune session avec correction IA à valider.'); return; }
    if (!confirm('Valider les notes IA pour ' + toValidate.length + ' session(s) ?')) return;

    const btn  = document.getElementById('btn-valider-tout');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Validation en cours...';

    let done = 0;
    for (const cell of toValidate) {
        const id = parseInt(cell.dataset.sessionId);
        const fakeBtn = { innerHTML: '', disabled: false };
        await validerSessionNotes(id, fakeBtn);
        done++;
        btn.innerHTML = `<i class="bi bi-hourglass-split me-1"></i>${done}/${toValidate.length}...`;
    }

    btn.innerHTML = `<i class="bi bi-check-circle-fill me-1"></i>${done} validée(s)`;
    setTimeout(() => { btn.innerHTML = orig; btn.disabled = false; }, 3000);
}
</script>
</body>
</html>
