<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$msg    = '';
$erreur = '';

$filterModule = (int)($_GET['filter_module'] ?? 0);
$searchText = trim($_GET['search'] ?? '');

// Traitement du déplacement de questions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_questions'])) {
    $questionIds = $_POST['question_ids'] ?? [];
    $targetModuleId = (int)($_POST['target_module_id'] ?? 0);

    if (empty($questionIds)) {
        $erreur = "Sélectionnez au moins une question.";
    } elseif ($targetModuleId <= 0) {
        $erreur = "Module cible requis.";
    } else {
        // Vérifier que le module cible existe
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM modules WHERE id = ?");
        $stmt->execute([$targetModuleId]);
        if ($stmt->fetchColumn() == 0) {
            $erreur = "Le module cible n'existe pas.";
        } else {
            // Vérifier que toutes les questions existent
            $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE id IN ($placeholders)");
            $stmt->execute($questionIds);
            if ((int)$stmt->fetchColumn() !== count($questionIds)) {
                $erreur = "Une ou plusieurs questions n'existent pas.";
            } else {
                // Déplacer les questions (module seulement)
                $stmt = $pdo->prepare("UPDATE questions SET module_id = ? WHERE id IN ($placeholders)");
                $params = array_merge([$targetModuleId], $questionIds);
                if ($stmt->execute($params)) {
                    $msg = count($questionIds) . " question(s) déplacée(s) avec succès.";
                } else {
                    $erreur = "Erreur lors du déplacement.";
                }
            }
        }
    }
}

// Récupération des données
$allModules = getAllModules();
$questions = [];

// Requête principale pour les questions
$baseQuery = "
    SELECT q.*, m.nom AS module_nom
    FROM questions q
    JOIN modules m ON m.id = q.module_id
    WHERE 1=1
";
$params = [];

if ($filterModule > 0) {
    $baseQuery .= " AND q.module_id = ?";
    $params[] = $filterModule;
}

if ($searchText !== '') {
    $baseQuery .= " AND q.texte LIKE ?";
    $params[] = "%$searchText%";
}

$baseQuery .= " ORDER BY m.nom, q.ordre, q.id";

$stmt = $pdo->prepare($baseQuery);
$stmt->execute($params);
$questions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Déplacer les questions — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .question-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin-bottom: 8px; background: #fff; cursor: pointer; transition: all 0.15s; }
        .question-card:hover { background: #f9fafb; border-color: #6366f1; }
        .question-card.selected { background: #eef2ff; border-color: #4f46e5; }
        .question-card input[type="checkbox"] { cursor: pointer; }
        .question-text { flex: 1; word-break: break-word; color: #374151; font-size: 14px; line-height: 1.4; }
        .question-meta { display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; }
        .badge-meta { font-size: 11px; padding: 3px 8px; }
    </style>
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>

<div class="container-fluid py-4 px-4">
    <h2 class="h4 fw-bold mb-4">
        <i class="bi bi-arrow-left-right me-2 text-primary"></i>Déplacer les questions entre modules
    </h2>

    <?php if ($msg): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($erreur): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3">
            <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($erreur) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Filtres et sélection cible (gauche) -->
        <div class="col-lg-3">
            <!-- Filtres -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 py-3 px-4">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-funnel me-2"></i>Filtres</h6>
                </div>
                <div class="card-body p-4">
                    <form method="GET" class="d-flex flex-column gap-3">
                        <div>
                            <label class="form-label fw-semibold small">Module</label>
                            <select name="filter_module" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">— Tous les modules —</option>
                                <?php foreach ($allModules as $m): ?>
                                <option value="<?= $m['id'] ?>" <?= $m['id'] == $filterModule ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['nom']) ?> (<?= $m['nb_questions'] ?> Q)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>


                        <div>
                            <label class="form-label fw-semibold small">Recherche</label>
                            <input type="text" name="search" class="form-control form-control-sm"
                                   placeholder="Texte de question..."
                                   value="<?= htmlspecialchars($searchText) ?>"
                                   onkeyup="this.form.submit()">
                        </div>

                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise me-1"></i>Rafraîchir
                        </button>
                    </form>
                </div>
            </div>

            <!-- Sélection cible (pour le déplacement) -->
            <div class="card border-0 shadow-sm rounded-4 sticky-top" style="top: 20px;">
                <div class="card-header bg-white border-0 py-3 px-4">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-target me-2"></i>Destination</h6>
                </div>
                <div class="card-body p-4">
                    <form method="POST" id="moveForm">
                        <?= csrfField() ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Module cible <span class="text-danger">*</span></label>
                            <select name="target_module_id" class="form-select form-select-sm" id="targetModule" onchange="updateMoveButtonCount()">
                                <option value="">— Choisir un module —</option>
                                <?php foreach ($allModules as $m): ?>
                                <option value="<?= $m['id'] ?>">
                                    <?= htmlspecialchars($m['nom']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="button" name="move_questions" class="btn btn-primary btn-sm"
                                    id="moveBtn" disabled onclick="submitMove()">
                                <i class="bi bi-arrow-right me-1"></i>Déplacer (0 sélectionnée)
                            </button>
                        </div>

                        <small class="text-muted d-block mt-2">
                            <i class="bi bi-info-circle me-1"></i>
                            Sélectionnez des questions à gauche, puis choisissez le module cible et cliquez sur "Déplacer".
                        </small>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tableau des questions (droite) -->
        <div class="col-lg-9">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3 px-4 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold">
                        <i class="bi bi-list-check me-2"></i>
                        Questions
                        <span class="badge bg-primary-subtle text-primary"><?= count($questions) ?></span>
                    </h6>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAllQuestions()">
                        <i class="bi bi-check2-square me-1"></i>Tout sélectionner
                    </button>
                </div>
                <div class="card-body p-0">
                    <form id="questionsForm">
                        <div style="max-height: 600px; overflow-y: auto;">
                            <?php if (empty($questions)): ?>
                            <div class="p-4 text-center text-muted">
                                <i class="bi bi-inbox" style="font-size: 32px; opacity: 0.3;"></i>
                                <p class="mt-2">Aucune question trouvée.</p>
                            </div>
                            <?php else: ?>
                                <?php
                                $groupedQuestions = [];
                                foreach ($questions as $q) {
                                    $module = $q['module_nom'];
                                    if (!isset($groupedQuestions[$module])) {
                                        $groupedQuestions[$module] = [];
                                    }
                                    $groupedQuestions[$module][] = $q;
                                }

                                foreach ($groupedQuestions as $module => $moduleQuestions):
                                ?>
                                <div class="px-4 py-2 bg-light border-bottom" style="font-weight: 600; font-size: 13px; position: sticky; top: 0; z-index: 10;">
                                    <i class="bi bi-folder me-1"></i><?= htmlspecialchars($module) ?>
                                </div>
                                <div class="px-4">
                                    <?php foreach ($moduleQuestions as $q): ?>
                                    <div class="question-card d-flex align-items-flex-start gap-3">
                                        <input type="checkbox" name="question_ids" value="<?= $q['id'] ?>"
                                               class="form-check-input question-checkbox mt-1"
                                               onchange="updateMoveButtonCount()">
                                        <div style="flex: 1;">
                                            <div class="question-text">
                                                <?= htmlspecialchars(substr($q['texte'], 0, 120)) ?>
                                                <?= strlen($q['texte']) > 120 ? '…' : '' ?>
                                            </div>
                                            <div class="question-meta">
                                                <span class="badge bg-secondary-subtle text-secondary badge-meta">
                                                    <i class="bi bi-lightning me-1"></i><?= htmlspecialchars($q['type']) ?>
                                                </span>
                                                <span class="badge bg-primary-subtle text-primary badge-meta">
                                                    <i class="bi bi-star me-1"></i><?= $q['points'] ?>pts
                                                </span>
                                                <span class="badge bg-info-subtle text-info badge-meta">
                                                    <i class="bi bi-question-circle me-1"></i>Q#<?= $q['id'] ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleAllQuestions() {
    const checkboxes = document.querySelectorAll('.question-checkbox');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    checkboxes.forEach(cb => cb.checked = !allChecked);
    updateMoveButtonCount();
}

function updateMoveButtonCount() {
    const checkboxes = document.querySelectorAll('.question-checkbox:checked');
    const count = checkboxes.length;
    const btn = document.getElementById('moveBtn');
    btn.textContent = `Déplacer (${count} sélectionnée${count !== 1 ? 's' : ''})`;
    btn.disabled = count === 0 || document.getElementById('targetModule').value === '';

    // Highlight selected cards
    document.querySelectorAll('.question-card').forEach(card => {
        const checkbox = card.querySelector('input[type="checkbox"]');
        card.classList.toggle('selected', checkbox.checked);
    });
}

document.querySelectorAll('.question-checkbox').forEach(cb => {
    cb.addEventListener('change', updateMoveButtonCount);
});

updateMoveButtonCount();

function submitMove() {
    // Supprimer les anciens hidden inputs
    document.querySelectorAll('#moveForm input[name="question_ids"]').forEach(el => el.remove());
    // Injecter les IDs cochés
    document.querySelectorAll('.question-checkbox:checked').forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'question_ids[]';
        input.value = cb.value;
        document.getElementById('moveForm').appendChild(input);
    });
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'move_questions';
    hidden.value = '1';
    document.getElementById('moveForm').appendChild(hidden);
    document.getElementById('moveForm').submit();
}
</script>

</body>
</html>
