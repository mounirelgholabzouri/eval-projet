<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$msg    = '';
$erreur = '';
$action     = $_GET['action'] ?? 'list';
$moduleId   = (int)($_GET['module_id'] ?? 0);
$questionId = (int)($_GET['id'] ?? 0);
$partieId   = (int)($_GET['partie_id'] ?? 0);

if (($_GET['msg'] ?? '') === 'fusion_ok') {
    $msg = "Module synthèse créé avec succès. Vous pouvez vérifier et modifier les questions ci-dessous.";
}

// Sélection du module
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_module'])) {
    $moduleId = (int)$_POST['module_id'];
    header("Location: questions.php?module_id=$moduleId"); exit;
}

// ── Parties : créer ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_partie'])) {
    $nomPartie = trim($_POST['partie_nom'] ?? '');
    if (strlen($nomPartie) >= 2 && $moduleId > 0) {
        $newPartieId = creerPartie($moduleId, $nomPartie);
        header("Location: questions.php?module_id=$moduleId&partie_id=$newPartieId&added_partie=1"); exit;
    }
    $erreur = "Nom de partie trop court (2 caractères minimum).";
}

// ── Parties : renommer ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_partie'])) {
    $pid = (int)$_POST['partie_id'];
    $nouveau = trim($_POST['partie_nom'] ?? '');
    if ($pid > 0 && strlen($nouveau) >= 2) {
        renommerPartie($pid, $nouveau);
        header("Location: questions.php?module_id=$moduleId&partie_id=$pid&renamed=1"); exit;
    }
}

// ── Parties : supprimer ──────────────────────────────────────
if ($action === 'delete_partie' && $partieId > 0) {
    $ok = supprimerPartie($partieId);
    $msg = $ok ? "Partie supprimée (questions déplacées vers une autre partie)." : "Impossible de supprimer : c'est la dernière partie du module.";
    header("Location: questions.php?module_id=$moduleId" . ($ok ? "&deleted_partie=1" : "&last_partie=1")); exit;
}

// ── Question : enregistrer (ajout ou modification) ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_question'])) {
    $texte       = trim($_POST['texte'] ?? '');
    $type        = $_POST['type'] ?? 'qcm';
    $points      = (float)($_POST['points'] ?? 1);
    $ordre       = (int)($_POST['ordre'] ?? 0);
    $qPartieId   = (int)($_POST['partie_id'] ?? 0);
    $choixTextes  = $_POST['choix_texte'] ?? [];
    $choixCorrects = $_POST['choix_correct'] ?? [];

    if (strlen($texte) < 3) {
        $erreur = "Le texte de la question est requis.";
    } elseif ($moduleId <= 0) {
        $erreur = "Module non sélectionné.";
    } elseif ($qPartieId <= 0) {
        $erreur = "Partie requise.";
    } else {
        if ($questionId > 0) {
            $pdo->prepare("UPDATE questions SET texte=?, type=?, points=?, ordre=?, partie_id=? WHERE id=?")
                ->execute([$texte, $type, $points, $ordre, $qPartieId, $questionId]);
            $pdo->prepare("DELETE FROM choix_reponses WHERE question_id=?")->execute([$questionId]);
        } else {
            $pdo->prepare("INSERT INTO questions (module_id, partie_id, texte, type, points, ordre) VALUES (?,?,?,?,?,?)")
                ->execute([$moduleId, $qPartieId, $texte, $type, $points, $ordre]);
            $questionId = (int)$pdo->lastInsertId();
        }

        if (in_array($type, ['qcm', 'vrai_faux', 'multiple'])) {
            foreach ($choixTextes as $i => $ct) {
                $ct = trim($ct);
                if ($ct === '') continue;
                $isC = in_array((string)$i, (array)$choixCorrects) ? 1 : 0;
                $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?,?,?,?)")
                    ->execute([$questionId, $ct, $isC, $i + 1]);
            }
        }
        header("Location: questions.php?module_id=$moduleId&partie_id=$qPartieId&saved=1"); exit;
    }
}

// ── Question : supprimer ─────────────────────────────────────
if ($action === 'delete' && $questionId > 0) {
    $stmt = $pdo->prepare("SELECT module_id, partie_id FROM questions WHERE id=?");
    $stmt->execute([$questionId]);
    $row = $stmt->fetch();
    if ($row) { $moduleId = (int)$row['module_id']; $partieId = (int)$row['partie_id']; }
    $pdo->prepare("DELETE FROM questions WHERE id=?")->execute([$questionId]);
    header("Location: questions.php?module_id=$moduleId&partie_id=$partieId&deleted=1"); exit;
}

// ── Chargement données ───────────────────────────────────────
$allModules = getAllModules();
$module     = $moduleId > 0 ? getModule($moduleId) : null;
$parties    = [];
$currentPartie = null;
$questionsCurrent = [];
$editQuestion = null;

if ($moduleId > 0 && $module) {
    // Garantir qu'une partie existe (auto "Général" si besoin)
    ensurePartieDefault($moduleId);
    $parties = getPartiesModule($moduleId);

    // Partie active (URL ou première)
    if ($partieId > 0) {
        foreach ($parties as $p) if ((int)$p['id'] === $partieId) { $currentPartie = $p; break; }
    }
    if (!$currentPartie && !empty($parties)) $currentPartie = $parties[0];

    // Questions de la partie courante
    if ($currentPartie) {
        $stmt = $pdo->prepare("SELECT * FROM questions WHERE partie_id = ? ORDER BY ordre, id");
        $stmt->execute([$currentPartie['id']]);
        $questionsCurrent = $stmt->fetchAll();
        foreach ($questionsCurrent as &$q) {
            $stmt2 = $pdo->prepare("SELECT * FROM choix_reponses WHERE question_id = ? ORDER BY ordre, id");
            $stmt2->execute([$q['id']]);
            $q['choix'] = $stmt2->fetchAll();
        }
        unset($q);
    }

    if ($action === 'edit' && $questionId > 0) {
        foreach ($questionsCurrent as $q) if ((int)$q['id'] === $questionId) { $editQuestion = $q; break; }
    }
}

// Flash messages
$flash = $_GET;
if (isset($flash['saved']))          $msg = "Question enregistrée.";
if (isset($flash['deleted']))        $msg = "Question supprimée.";
if (isset($flash['added_partie']))   $msg = "Partie créée.";
if (isset($flash['renamed']))        $msg = "Partie renommée.";
if (isset($flash['deleted_partie'])) $msg = "Partie supprimée.";
if (isset($flash['last_partie']))    $erreur = "Impossible : c'est la dernière partie du module (au moins une partie est obligatoire).";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Questions — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .partie-tab { border:1px solid #e5e7eb; background:#fff; border-radius:12px; padding:.65rem 1rem; cursor:pointer; transition:all .15s; display:flex; align-items:center; gap:.5rem; text-decoration:none; color:inherit; }
        .partie-tab:hover { border-color:#6366f1; background:#eef2ff; color:inherit; }
        .partie-tab.active { background:#4f46e5; border-color:#4f46e5; color:#fff !important; box-shadow:0 2px 6px rgba(79,70,229,.25); }
        .partie-tab.active .badge { background:rgba(255,255,255,.25) !important; color:#fff !important; }
    </style>
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
                        <?= htmlspecialchars($m['nom']) ?> (<?= $m['nb_questions'] ?> Q · <?= $m['nb_parties'] ?? 0 ?> parties)
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

    <!-- Onglets parties -->
    <div class="card border-0 shadow-sm rounded-4 mb-4" id="parties-bar">
        <div class="card-body p-3">
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <span class="fw-semibold text-muted me-2"><i class="bi bi-layers me-1"></i>Parties :</span>
                <?php foreach ($parties as $p): ?>
                <a href="questions.php?module_id=<?= $moduleId ?>&partie_id=<?= $p['id'] ?>"
                   class="partie-tab <?= $currentPartie && (int)$currentPartie['id'] === (int)$p['id'] ? 'active' : '' ?>">
                    <i class="bi bi-bookmark-fill"></i>
                    <span><?= htmlspecialchars($p['nom']) ?></span>
                    <span class="badge bg-primary-subtle text-primary"><?= (int)$p['nb_questions'] ?></span>
                </a>
                <?php endforeach; ?>
                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill" data-bs-toggle="modal" data-bs-target="#addPartieModal">
                    <i class="bi bi-plus-lg me-1"></i>Nouvelle partie
                </button>
                <?php if ($currentPartie && count($parties) > 1): ?>
                <div class="ms-auto d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#renamePartieModal">
                        <i class="bi bi-pencil me-1"></i>Renommer
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger"
                            data-bs-toggle="modal" data-bs-target="#deletePartieModal">
                        <i class="bi bi-trash me-1"></i>Supprimer
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Formulaire question -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3 px-4">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-<?= $editQuestion ? 'pencil' : 'plus-circle' ?> me-2 text-primary"></i>
                        <?= $editQuestion ? 'Modifier' : 'Ajouter' ?> une question
                        <?php if ($currentPartie): ?>
                        <small class="text-muted fw-normal">· dans <strong class="text-primary"><?= htmlspecialchars($currentPartie['nom']) ?></strong></small>
                        <?php endif; ?>
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="questions.php?module_id=<?= $moduleId ?>&partie_id=<?= $currentPartie['id'] ?? 0 ?><?= $editQuestion ? '&action=edit&id='.$questionId : '' ?>">

                        <!-- Partie (select) -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Partie <span class="text-danger">*</span></label>
                            <select name="partie_id" class="form-select form-select-sm" required>
                                <?php foreach ($parties as $p): ?>
                                <option value="<?= $p['id'] ?>"
                                    <?= (int)($editQuestion['partie_id'] ?? ($currentPartie['id'] ?? 0)) === (int)$p['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['nom']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

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
                                       value="<?= $editQuestion['ordre'] ?? count($questionsCurrent) + 1 ?>">
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
                            <a href="questions.php?module_id=<?= $moduleId ?>&partie_id=<?= $currentPartie['id'] ?? 0 ?>" class="btn btn-outline-secondary">Annuler</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Liste questions de la partie courante -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3 px-4 d-flex justify-content-between">
                    <h5 class="mb-0 fw-bold">
                        <?php if ($currentPartie): ?>
                        <i class="bi bi-bookmark-fill me-2 text-primary"></i><?= htmlspecialchars($currentPartie['nom']) ?>
                        <?php else: ?>
                        Questions — <?= htmlspecialchars($module['nom']) ?>
                        <?php endif; ?>
                    </h5>
                    <span class="badge bg-primary"><?= count($questionsCurrent) ?> question(s)</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($questionsCurrent)): ?>
                        <p class="text-center text-muted py-4">
                            <i class="bi bi-inbox me-2"></i>Aucune question dans cette partie.
                        </p>
                    <?php endif; ?>
                    <?php foreach ($questionsCurrent as $idx => $q): ?>
                    <div class="p-3 border-bottom d-flex align-items-start gap-3">
                        <div class="question-number small"><?= $idx + 1 ?></div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold small"><?= htmlspecialchars(mb_substr($q['texte'], 0, 100)) ?><?= mb_strlen($q['texte']) > 100 ? '…' : '' ?></div>
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
                            <a href="questions.php?module_id=<?= $moduleId ?>&partie_id=<?= $currentPartie['id'] ?>&action=edit&id=<?= $q['id'] ?>"
                               class="btn btn-sm btn-outline-primary rounded-3" title="Modifier">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="questions.php?module_id=<?= $moduleId ?>&partie_id=<?= $currentPartie['id'] ?>&action=delete&id=<?= $q['id'] ?>"
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

    <!-- ── Modal ajouter partie ── -->
    <div class="modal fade" id="addPartieModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="questions.php?module_id=<?= $moduleId ?>" class="modal-content rounded-4">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2 text-primary"></i>Nouvelle partie</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label fw-semibold">Nom de la partie</label>
                    <input type="text" name="partie_nom" class="form-control" required minlength="2" maxlength="200"
                           placeholder="Ex : Renforcer VM">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="add_partie" class="btn btn-primary">Créer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Modal renommer partie ── -->
    <?php if ($currentPartie): ?>
    <div class="modal fade" id="renamePartieModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="questions.php?module_id=<?= $moduleId ?>" class="modal-content rounded-4">
                <input type="hidden" name="partie_id" value="<?= $currentPartie['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2 text-primary"></i>Renommer la partie</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label fw-semibold">Nouveau nom</label>
                    <input type="text" name="partie_nom" class="form-control" required minlength="2" maxlength="200"
                           value="<?= htmlspecialchars($currentPartie['nom']) ?>">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="rename_partie" class="btn btn-primary">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

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

if (document.getElementById('typeSelect')) toggleChoix();
</script>

<?php if ($currentPartie && count($parties) > 1): ?>
<!-- Modal suppression partie -->
<div class="modal fade" id="deletePartieModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Supprimer la partie</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Vous allez supprimer la partie :</p>
                <p class="fw-bold fs-5 mb-2">« <?= sanitize($currentPartie['nom']) ?> »</p>
                <p class="text-muted mb-3">
                    <?= (int)$currentPartie['nb_questions'] ?> question(s) seront déplacées vers une autre partie du module.
                </p>
                <p class="text-danger fw-semibold mb-0">Cette action est irréversible.</p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <a href="questions.php?module_id=<?= $moduleId ?>&action=delete_partie&partie_id=<?= $currentPartie['id'] ?>"
                   class="btn btn-danger">
                    <i class="bi bi-trash me-1"></i>Supprimer définitivement
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
</body>
</html>
