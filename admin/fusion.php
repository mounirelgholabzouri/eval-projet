<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$msg    = '';
$erreur = '';

// ── Récupérer tous les modules avec nb questions ────────────────
$modules = getAllModules();

// ── Traitement POST : création du module synthèse ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom      = trim($_POST['nom'] ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $duree    = max(5, min(300, (int)($_POST['duree_minutes'] ?? 60)));
    $noteMax  = in_array((int)($_POST['note_max'] ?? 20), [20, 40]) ? (int)$_POST['note_max'] : 20;
    $actif    = isset($_POST['actif']) ? 1 : 0;
    $ids      = array_map('intval', (array)($_POST['module_ids'] ?? []));
    $ids      = array_filter($ids, fn($i) => $i > 0);

    if (strlen($nom) < 2) {
        $erreur = "Le nom du module synthèse est requis (minimum 2 caractères).";
    } elseif (count($ids) < 2) {
        $erreur = "Sélectionnez au moins 2 modules à fusionner.";
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Créer le module synthèse
            $stmt = $pdo->prepare("INSERT INTO modules (nom, description, duree_minutes, note_max, actif) VALUES (?,?,?,?,?)");
            $stmt->execute([$nom, $desc, $duree, $noteMax, $actif]);
            $newModuleId = (int)$pdo->lastInsertId();

            // 2. Copier les questions + choix — 1 partie par module source (flatten)
            $partieOrdre = 1;
            $qOrdre = 1;
            foreach ($ids as $srcModuleId) {
                $mCheck = $pdo->prepare("SELECT id, nom FROM modules WHERE id = ?");
                $mCheck->execute([$srcModuleId]);
                $srcModule = $mCheck->fetch();
                if (!$srcModule) continue;

                // Une seule partie par module source, portant son nom
                $newPartieId = creerPartie($newModuleId, $srcModule['nom'], $partieOrdre++);

                $qStmt = $pdo->prepare("SELECT * FROM questions WHERE module_id = ? ORDER BY partie_id, ordre, id");
                $qStmt->execute([$srcModuleId]);
                $questions = $qStmt->fetchAll();

                foreach ($questions as $q) {
                    $insQ = $pdo->prepare(
                        "INSERT INTO questions (module_id, partie_id, texte, type, points, ordre, image_path)
                         VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $insQ->execute([
                        $newModuleId,
                        $newPartieId,
                        $q['texte'],
                        $q['type'],
                        $q['points'],
                        $qOrdre++,
                        $q['image_path'] ?? null,
                    ]);
                    $newQId = (int)$pdo->lastInsertId();

                    $cStmt = $pdo->prepare("SELECT * FROM choix_reponses WHERE question_id = ? ORDER BY ordre, id");
                    $cStmt->execute([$q['id']]);
                    foreach ($cStmt->fetchAll() as $c) {
                        $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?,?,?,?)")
                            ->execute([$newQId, $c['texte'], $c['is_correct'], $c['ordre']]);
                    }
                }
            }

            $pdo->commit();

            // Rediriger vers la gestion des questions du nouveau module
            header("Location: questions.php?module_id={$newModuleId}&msg=fusion_ok");
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $erreur = "Erreur lors de la fusion : " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fusion QCM — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container-fluid py-4 px-4">
    <div class="d-flex align-items-center mb-4 gap-3">
        <h2 class="h4 fw-bold mb-0">
            <i class="bi bi-intersect me-2 text-primary"></i>Fusion de modules QCM
        </h2>
        <span class="badge bg-primary-subtle text-primary fs-6">Nouveau module synthèse</span>
    </div>

    <?php if ($erreur): ?>
        <div class="alert alert-danger rounded-3"><i class="bi bi-exclamation-triangle me-2"></i><?= sanitize($erreur) ?></div>
    <?php endif; ?>

    <form method="POST" id="fusionForm">
        <div class="row g-4">

            <!-- ── Colonne gauche : sélection modules ── -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-header bg-white border-0 py-3 px-4">
                        <h5 class="mb-0 fw-bold">
                            <i class="bi bi-collection me-2 text-primary"></i>
                            Modules à fusionner
                        </h5>
                        <div class="text-muted small mt-1">Sélectionnez au moins 2 modules</div>
                    </div>
                    <div class="card-body p-3">
                        <?php if (empty($modules)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-journal-x fs-2 d-block mb-2"></i>
                                Aucun module disponible
                            </div>
                        <?php else: ?>
                        <div class="d-flex gap-2 mb-3">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAll">
                                <i class="bi bi-check2-all me-1"></i>Tout sélectionner
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnNone">
                                <i class="bi bi-x-lg me-1"></i>Tout désélectionner
                            </button>
                        </div>
                        <div class="list-group list-group-flush" id="moduleList">
                            <?php foreach ($modules as $m): ?>
                            <label class="list-group-item list-group-item-action rounded-3 mb-1 border module-item" style="cursor:pointer">
                                <div class="d-flex align-items-center gap-3">
                                    <input class="form-check-input flex-shrink-0 module-checkbox"
                                           type="checkbox"
                                           name="module_ids[]"
                                           value="<?= $m['id'] ?>"
                                           data-questions="<?= (int)$m['nb_questions'] ?>"
                                           data-name="<?= sanitize($m['nom']) ?>">
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold"><?= sanitize($m['nom']) ?></div>
                                        <?php if ($m['description']): ?>
                                        <div class="text-muted small"><?= sanitize(mb_substr($m['description'], 0, 60)) ?><?= mb_strlen($m['description']) > 60 ? '…' : '' ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end flex-shrink-0">
                                        <span class="badge bg-primary-subtle text-primary"><?= (int)$m['nb_questions'] ?> Q</span>
                                        <div class="text-muted small"><?= $m['duree_minutes'] ?> min</div>
                                    </div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── Colonne droite : config module synthèse ── -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-header bg-white border-0 py-3 px-4">
                        <h5 class="mb-0 fw-bold">
                            <i class="bi bi-gear me-2 text-primary"></i>
                            Configuration du module synthèse
                        </h5>
                    </div>
                    <div class="card-body p-4">

                        <!-- Récapitulatif sélection -->
                        <div id="selectionSummary" class="alert alert-info rounded-3 d-flex align-items-center gap-3 mb-4">
                            <i class="bi bi-info-circle-fill fs-5 flex-shrink-0"></i>
                            <div>
                                <span id="summaryText">Aucun module sélectionné.</span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Nom du module synthèse <span class="text-danger">*</span></label>
                            <input type="text" name="nom" id="nomModule" class="form-control"
                                   placeholder="Ex : Révision complète — Modules 1 à 3"
                                   value="<?= sanitize($_POST['nom'] ?? '') ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea name="description" class="form-control" rows="2"
                                      placeholder="Module synthèse regroupant plusieurs évaluations"><?= sanitize($_POST['description'] ?? '') ?></textarea>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Durée (minutes)</label>
                                <input type="number" name="duree_minutes" id="dureeInput" class="form-control"
                                       min="5" max="300" value="<?= (int)($_POST['duree_minutes'] ?? 60) ?>">
                                <div class="form-text" id="dureeHint">Durée combinée estimée : —</div>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Notation</label>
                                <div class="d-flex gap-3 mt-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="note_max" value="20"
                                               id="nm20" <?= (($_POST['note_max'] ?? '20') == '20') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="nm20">Sur 20</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="note_max" value="40"
                                               id="nm40" <?= (($_POST['note_max'] ?? '') == '40') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="nm40">Sur 40</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4 form-check">
                            <input type="checkbox" name="actif" class="form-check-input" id="actif"
                                   <?= isset($_POST['actif']) || !isset($_POST['nom']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="actif">Module actif (visible aux stagiaires)</label>
                        </div>

                        <div class="d-flex gap-2 align-items-center">
                            <button type="submit" class="btn btn-primary" id="btnSubmit" disabled>
                                <i class="bi bi-intersect me-2"></i>Créer le module synthèse
                            </button>
                            <a href="modules.php" class="btn btn-outline-secondary">Annuler</a>
                            <span class="text-muted small ms-2" id="btnHint">Sélectionnez au moins 2 modules</span>
                        </div>
                    </div>
                </div>

                <!-- Prévisualisation des modules sélectionnés -->
                <div class="card border-0 shadow-sm rounded-4 mt-4" id="previewCard" style="display:none!important">
                    <div class="card-header bg-white border-0 py-3 px-4">
                        <h5 class="mb-0 fw-bold">
                            <i class="bi bi-eye me-2 text-primary"></i>Aperçu de la fusion
                        </h5>
                    </div>
                    <div class="card-body p-3">
                        <div id="previewContent"></div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const checkboxes   = document.querySelectorAll('.module-checkbox');
const btnSubmit    = document.getElementById('btnSubmit');
const btnHint      = document.getElementById('btnHint');
const summaryText  = document.getElementById('summaryText');
const summaryBox   = document.getElementById('selectionSummary');
const dureeInput   = document.getElementById('dureeInput');
const dureeHint    = document.getElementById('dureeHint');
const previewCard  = document.getElementById('previewCard');
const previewCont  = document.getElementById('previewContent');
const nomInput     = document.getElementById('nomModule');

// Données modules côté JS
const modulesData  = <?= json_encode(array_map(fn($m) => [
    'id'       => (int)$m['id'],
    'nom'      => $m['nom'],
    'nb'       => (int)$m['nb_questions'],
    'duree'    => (int)$m['duree_minutes'],
], $modules), JSON_UNESCAPED_UNICODE) ?>;

function getSelected() {
    return [...checkboxes].filter(c => c.checked);
}

function updateUI() {
    const sel = getSelected();
    const count = sel.length;
    const totalQ = sel.reduce((s, c) => s + parseInt(c.dataset.questions), 0);
    const totalD = sel.reduce((s, c) => {
        const mod = modulesData.find(m => m.id == c.value);
        return s + (mod ? mod.duree : 0);
    }, 0);

    // Bouton submit
    if (count >= 2) {
        btnSubmit.disabled = false;
        btnHint.textContent = '';
    } else {
        btnSubmit.disabled = true;
        btnHint.textContent = count === 1 ? 'Sélectionnez encore 1 module' : 'Sélectionnez au moins 2 modules';
    }

    // Résumé
    if (count === 0) {
        summaryText.innerHTML = 'Aucun module sélectionné.';
        summaryBox.className = 'alert alert-info rounded-3 d-flex align-items-center gap-3 mb-4';
    } else {
        const names = sel.map(c => `<strong>${c.dataset.name}</strong>`).join(', ');
        summaryText.innerHTML = `${count} module(s) sélectionné(s) : ${names} — <strong>${totalQ} questions</strong> au total.`;
        summaryBox.className = 'alert alert-success rounded-3 d-flex align-items-center gap-3 mb-4';
    }

    // Durée estimée
    if (count > 0) {
        dureeHint.textContent = `Durée combinée estimée : ${totalD} min`;
        if (!dureeInput._userEdited) dureeInput.value = totalD;
    } else {
        dureeHint.textContent = 'Durée combinée estimée : —';
    }

    // Nom auto si vide
    if (count >= 2 && !nomInput.value.trim()) {
        const names = sel.map(c => c.dataset.name).join(' + ');
        nomInput.placeholder = `Synthèse : ${names}`;
    }

    // Prévisualisation
    updatePreview(sel);
}

function updatePreview(sel) {
    if (sel.length === 0) {
        previewCard.style.setProperty('display', 'none', 'important');
        return;
    }
    previewCard.style.removeProperty('display');

    let html = '<div class="d-flex flex-column gap-2">';
    let order = 1;
    sel.forEach(c => {
        const mod = modulesData.find(m => m.id == c.value);
        if (!mod) return;
        html += `
        <div class="d-flex align-items-center gap-2 p-2 bg-light rounded-3">
            <span class="badge bg-primary rounded-pill">${order}</span>
            <div class="flex-grow-1 fw-semibold">${escHtml(mod.nom)}</div>
            <span class="badge bg-secondary-subtle text-secondary">${mod.nb} questions</span>
            <span class="badge bg-light text-muted border">${mod.duree} min</span>
        </div>`;
        order++;
    });
    html += '</div>';
    previewCont.innerHTML = html;
}

function escHtml(str) {
    return str.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// Marquer si l'utilisateur a modifié la durée manuellement
dureeInput.addEventListener('input', () => { dureeInput._userEdited = true; });

// Checkboxes
checkboxes.forEach(cb => {
    cb.addEventListener('change', () => {
        // Highlight visuel
        cb.closest('.module-item').classList.toggle('border-primary', cb.checked);
        cb.closest('.module-item').classList.toggle('bg-primary-subtle', cb.checked);
        updateUI();
    });
});

// Boutons tout / rien
document.getElementById('btnAll').addEventListener('click', () => {
    checkboxes.forEach(c => {
        c.checked = true;
        c.closest('.module-item').classList.add('border-primary', 'bg-primary-subtle');
    });
    updateUI();
});
document.getElementById('btnNone').addEventListener('click', () => {
    checkboxes.forEach(c => {
        c.checked = false;
        c.closest('.module-item').classList.remove('border-primary', 'bg-primary-subtle');
    });
    updateUI();
});

// Init
updateUI();
</script>
</body>
</html>
