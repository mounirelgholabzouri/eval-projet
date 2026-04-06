<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$msg    = '';
$erreur = '';
$action    = $_GET['action'] ?? 'list';
$moduleId  = (int)($_GET['module_id'] ?? 0);
$questionId = (int)($_GET['id'] ?? 0);

// Sélection du module
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_module'])) {
    $moduleId = (int)$_POST['module_id'];
    header("Location: questions.php?module_id=$moduleId"); exit;
}

// ── Ajout / Modification question ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_question'])) {
    $texte  = trim($_POST['texte'] ?? '');
    $type   = $_POST['type'] ?? 'qcm';
    $points = (float)($_POST['points'] ?? 1);
    $ordre  = (int)($_POST['ordre'] ?? 0);
    $choixTextes  = $_POST['choix_texte'] ?? [];
    $choixCorrects = $_POST['choix_correct'] ?? [];

    if (strlen($texte) < 3) {
        $erreur = "Le texte de la question est requis.";
    } elseif ($moduleId <= 0) {
        $erreur = "Module non sélectionné.";
    } else {
        if ($questionId > 0) {
            $pdo->prepare("UPDATE questions SET texte=?, type=?, points=?, ordre=? WHERE id=?")
                ->execute([$texte, $type, $points, $ordre, $questionId]);
            // Supprimer anciens choix
            $pdo->prepare("DELETE FROM choix_reponses WHERE question_id=?")->execute([$questionId]);
        } else {
            $pdo->prepare("INSERT INTO questions (module_id, texte, type, points, ordre) VALUES (?,?,?,?,?)")
                ->execute([$moduleId, $texte, $type, $points, $ordre]);
            $questionId = (int)$pdo->lastInsertId();
        }

        // Insérer choix
        if (in_array($type, ['qcm', 'vrai_faux', 'multiple'])) {
            foreach ($choixTextes as $i => $ct) {
                $ct = trim($ct);
                if ($ct === '') continue;
                $isC = in_array((string)$i, (array)$choixCorrects) ? 1 : 0;
                $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?,?,?,?)")
                    ->execute([$questionId, $ct, $isC, $i + 1]);
            }
        }
        $msg = "Question enregistrée.";
        $questionId = 0;
        $action = 'list';
    }
}

// ── Suppression question ─────────────────────────────────────
if ($action === 'delete' && $questionId > 0) {
    $stmt = $pdo->prepare("SELECT module_id FROM questions WHERE id=?");
    $stmt->execute([$questionId]);
    $row = $stmt->fetch();
    if ($row) $moduleId = (int)$row['module_id'];
    $pdo->prepare("DELETE FROM questions WHERE id=?")->execute([$questionId]);
    $msg = "Question supprimée.";
    $action = 'list';
}

$allModules = getAllModules();
$questions  = $moduleId > 0 ? getQuestionsModule($moduleId) : [];
$module     = $moduleId > 0 ? getModule($moduleId) : null;

$editQuestion = null;
if ($action === 'edit' && $questionId > 0) {
    foreach ($questions as $q) {
        if ((int)$q['id'] === $questionId) { $editQuestion = $q; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Questions — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container-fluid py-4 px-4">
    <h2 class="h4 fw-bold mb-4"><i class="bi bi-question-circle me-2 text-primary"></i>Gestion des questions</h2>

    <?php if ($msg): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($erreur): ?>
        <div class="alert alert-danger rounded-3"><?= htmlspecialchars($erreur) ?></div>
    <?php endif; ?>

    <!-- Sélection module -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form method="POST" class="d-flex gap-3 align-items-center flex-wrap">
                <label class="fw-semibold text-nowrap"><i class="bi bi-journal me-1"></i>Module :</label>
                <select name="module_id" class="form-select" style="max-width:350px">
                    <option value="">— Choisir un module —</option>
                    <?php foreach ($allModules as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $m['id'] == $moduleId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($m['nom']) ?> (<?= $m['nb_questions'] ?> questions)
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="select_module" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-right me-1"></i>Sélectionner
                </button>
            </form>
        </div>
    </div>

    <?php if ($moduleId > 0 && $module): ?>
    <div class="row g-4">
        <!-- Formulaire ajout/édition question -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3 px-4">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-<?= $editQuestion ? 'pencil' : 'plus-circle' ?> me-2 text-primary"></i>
                        <?= $editQuestion ? 'Modifier' : 'Ajouter' ?> une question
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="questions.php?module_id=<?= $moduleId ?><?= $editQuestion ? '&action=edit&id='.$questionId : '' ?>" id="questionForm">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Question <span class="text-danger">*</span></label>
                            <textarea name="texte" class="form-control" rows="3" required><?= htmlspecialchars($editQuestion['texte'] ?? '') ?></textarea>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-7">
                                <label class="form-label fw-semibold">Type</label>
                                <select name="type" class="form-select" id="typeSelect" onchange="toggleChoix()">
                                    <option value="qcm" <?= ($editQuestion['type'] ?? '') === 'qcm' ? 'selected' : '' ?>>QCM</option>
                                    <option value="vrai_faux" <?= ($editQuestion['type'] ?? '') === 'vrai_faux' ? 'selected' : '' ?>>Vrai / Faux</option>
                                    <option value="texte_libre" <?= ($editQuestion['type'] ?? '') === 'texte_libre' ? 'selected' : '' ?>>Réponse libre</option>
                                    <option value="multiple" <?= ($editQuestion['type'] ?? '') === 'multiple' ? 'selected' : '' ?>>Choix multiples</option>
                                </select>
                            </div>
                            <div class="col-3">
                                <label class="form-label fw-semibold">Points</label>
                                <input type="number" name="points" class="form-control" step="0.5" min="0.5"
                                       value="<?= $editQuestion['points'] ?? 1 ?>">
                            </div>
                            <div class="col-2">
                                <label class="form-label fw-semibold">Ordre</label>
                                <input type="number" name="ordre" class="form-control" min="0"
                                       value="<?= $editQuestion['ordre'] ?? count($questions) + 1 ?>">
                            </div>
                        </div>

                        <!-- Choix de réponses -->
                        <div id="choixSection">
                            <label class="form-label fw-semibold">Choix de réponses</label>
                            <div id="choixList">
                                <?php
                                $existingChoix = $editQuestion['choix'] ?? [];
                                $defChoix = !empty($existingChoix) ? $existingChoix : [
                                    ['texte'=>'', 'is_correct'=>0],
                                    ['texte'=>'', 'is_correct'=>0],
                                    ['texte'=>'', 'is_correct'=>0],
                                    ['texte'=>'', 'is_correct'=>0],
                                ];
                                foreach ($defChoix as $i => $c): ?>
                                <div class="d-flex align-items-center gap-2 mb-2 choix-row">
                                    <input type="text" name="choix_texte[]" class="form-control form-control-sm"
                                           placeholder="Réponse <?= chr(65+$i) ?>"
                                           value="<?= htmlspecialchars($c['texte']) ?>">
                                    <div class="form-check mb-0">
                                        <input type="checkbox" name="choix_correct[]" value="<?= $i ?>"
                                               class="form-check-input" <?= $c['is_correct'] ? 'checked' : '' ?>>
                                        <label class="form-check-label small text-success">OK</label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="addChoix()">
                                <i class="bi bi-plus me-1"></i>Ajouter un choix
                            </button>
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" name="save_question" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i>Enregistrer
                            </button>
                            <?php if ($editQuestion): ?>
                            <a href="questions.php?module_id=<?= $moduleId ?>" class="btn btn-outline-secondary">Annuler</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Liste des questions -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3 px-4 d-flex justify-content-between">
                    <h5 class="mb-0 fw-bold">
                        Questions — <?= htmlspecialchars($module['nom']) ?>
                    </h5>
                    <span class="badge bg-primary"><?= count($questions) ?> questions</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($questions)): ?>
                        <p class="text-center text-muted py-4">Aucune question. Utilisez le formulaire pour en ajouter.</p>
                    <?php endif; ?>
                    <?php foreach ($questions as $idx => $q): ?>
                    <div class="p-3 border-bottom d-flex align-items-start gap-3">
                        <div class="question-number small"><?= $idx + 1 ?></div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold small"><?= htmlspecialchars(mb_substr($q['texte'], 0, 100)) ?><?= strlen($q['texte']) > 100 ? '…' : '' ?></div>
                            <div class="mt-1 d-flex gap-2 flex-wrap">
                                <span class="badge bg-light text-muted border small">
                                    <?php $types = ['qcm'=>'QCM','vrai_faux'=>'V/F','texte_libre'=>'Libre','multiple'=>'Multiple'];
                                    echo $types[$q['type']] ?? $q['type']; ?>
                                </span>
                                <span class="badge bg-primary-subtle text-primary small"><?= $q['points'] ?> pt(s)</span>
                                <span class="badge bg-secondary-subtle text-secondary small"><?= count($q['choix']) ?> choix</span>
                            </div>
                        </div>
                        <div class="d-flex gap-1 flex-shrink-0">
                            <a href="questions.php?module_id=<?= $moduleId ?>&action=edit&id=<?= $q['id'] ?>"
                               class="btn btn-sm btn-outline-primary rounded-3" title="Modifier">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="questions.php?module_id=<?= $moduleId ?>&action=delete&id=<?= $q['id'] ?>"
                               class="btn btn-sm btn-outline-danger rounded-3"
                               onclick="return confirm('Supprimer cette question ?')" title="Supprimer">
                                <i class="bi bi-trash"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
        <div class="alert alert-info rounded-3">
            <i class="bi bi-arrow-up me-2"></i>Sélectionnez un module pour gérer ses questions.
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleChoix() {
    const type = document.getElementById('typeSelect').value;
    const section = document.getElementById('choixSection');
    section.style.display = (type === 'texte_libre') ? 'none' : 'block';

    if (type === 'vrai_faux') {
        const list = document.getElementById('choixList');
        list.innerHTML = `
            <div class="d-flex align-items-center gap-2 mb-2 choix-row">
                <input type="text" name="choix_texte[]" class="form-control form-control-sm" value="Vrai" readonly>
                <div class="form-check mb-0"><input type="checkbox" name="choix_correct[]" value="0" class="form-check-input" checked><label class="form-check-label small text-success">OK</label></div>
            </div>
            <div class="d-flex align-items-center gap-2 mb-2 choix-row">
                <input type="text" name="choix_texte[]" class="form-control form-control-sm" value="Faux" readonly>
                <div class="form-check mb-0"><input type="checkbox" name="choix_correct[]" value="1" class="form-check-input"><label class="form-check-label small text-success">OK</label></div>
            </div>`;
    }
}

let choixCount = document.querySelectorAll('.choix-row').length;
function addChoix() {
    const list = document.getElementById('choixList');
    const idx  = choixCount++;
    const div  = document.createElement('div');
    div.className = 'd-flex align-items-center gap-2 mb-2 choix-row';
    div.innerHTML = `
        <input type="text" name="choix_texte[]" class="form-control form-control-sm" placeholder="Réponse ${String.fromCharCode(65+idx)}">
        <div class="form-check mb-0">
            <input type="checkbox" name="choix_correct[]" value="${idx}" class="form-check-input">
            <label class="form-check-label small text-success">OK</label>
        </div>
        <button type="button" class="btn btn-sm btn-outline-danger rounded-3" onclick="this.parentElement.remove()">
            <i class="bi bi-x"></i>
        </button>`;
    list.appendChild(div);
}

toggleChoix();
</script>
</body>
</html>
