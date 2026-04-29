<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo      = getDB();
$moduleId = (int)($_GET['module_id'] ?? 0);
$module   = $moduleId ? getModule($moduleId) : null;
$parties  = $moduleId ? getPartiesModule($moduleId) : [];
$modules  = getAllModules();
$erreur   = '';

// Sélection rapide de module via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_module'])) {
    $moduleId = (int)$_POST['module_id'];
    header("Location: efm.php?module_id=$moduleId"); exit;
}

// Validation avant impression
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generer_efm'])) {
    $moduleId  = (int)($_POST['module_id'] ?? 0);
    $module    = getModule($moduleId);
    $partieIds = array_map('intval', (array)($_POST['partie_ids'] ?? []));
    $partieIds = array_filter($partieIds, fn($i) => $i > 0);

    if (!$module) {
        $erreur = "Module invalide.";
    } elseif (empty($partieIds)) {
        $erreur = "Sélectionnez au moins une partie.";
    } else {
        // Construire les paramètres GET pour la page d'impression
        $params = [
            'module_id'    => $moduleId,
            'etablissement'=> trim($_POST['etablissement'] ?? ''),
            'filiere'      => trim($_POST['filiere'] ?? ''),
            'duree'        => trim($_POST['duree'] ?? ''),
            'annee'        => trim($_POST['annee'] ?? ''),
            'note_max'     => (int)($_POST['note_max'] ?? $module['note_max']),
            'code_module'  => trim($_POST['code_module'] ?? ''),
            'intitule'     => trim($_POST['intitule'] ?? $module['nom']),
            'shuffle'      => isset($_POST['shuffle']) ? 1 : 0,
            'shuffle_choix'=> isset($_POST['shuffle_choix']) ? 1 : 0,
            'corrige'      => 0,
        ];
        // Parties et nb_questions
        foreach ($partieIds as $pid) {
            $params["p[$pid]"] = max(0, (int)($_POST["nb_q_$pid"] ?? 0));
        }
        $params['partie_ids'] = implode(',', $partieIds);

        $qs = http_build_query($params);
        header("Location: print_efm.php?$qs"); exit;
    }
    $parties = getPartiesModule($moduleId);
}

$anneeDefaut = date('y') . '/' . (date('y') + 1);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EFM — Examen de Fin de Module</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container-fluid py-4 px-4">

    <div class="d-flex align-items-center mb-4 gap-3">
        <h2 class="h4 fw-bold mb-0">
            <i class="bi bi-file-earmark-ruled me-2 text-danger"></i>EFM — Examen de Fin de Module
        </h2>
        <span class="badge bg-danger-subtle text-danger fs-6">Impression officielle</span>
    </div>

    <?php if ($erreur): ?>
        <div class="alert alert-danger rounded-3"><i class="bi bi-exclamation-triangle me-2"></i><?= sanitize($erreur) ?></div>
    <?php endif; ?>

    <!-- ── Sélection du module ── -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white border-0 py-3 px-4">
            <h5 class="mb-0 fw-bold"><i class="bi bi-journal-text me-2 text-primary"></i>Sélectionner un module</h5>
        </div>
        <div class="card-body p-4">
            <form method="POST" class="row g-3 align-items-end">
                <input type="hidden" name="select_module" value="1">
                <div class="col-md-7">
                    <label class="form-label fw-semibold">Module</label>
                    <select name="module_id" class="form-select" required>
                        <option value="">— Choisir un module —</option>
                        <?php foreach ($modules as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= $m['id'] == $moduleId ? 'selected' : '' ?>>
                            <?= sanitize($m['nom']) ?> (<?= $m['nb_questions'] ?> Q — <?= $m['nb_parties'] ?> partie<?= $m['nb_parties'] > 1 ? 's' : '' ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-arrow-right me-1"></i>Configurer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($module): ?>
    <!-- ── Configuration EFM ── -->
    <form method="POST" id="efmForm">
        <input type="hidden" name="generer_efm" value="1">
        <input type="hidden" name="module_id" value="<?= $module['id'] ?>">

        <div class="row g-4">

            <!-- Colonne gauche : parties et questions -->
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-header bg-white border-0 py-3 px-4">
                        <h5 class="mb-0 fw-bold">
                            <i class="bi bi-layers me-2 text-warning"></i>
                            Parties du module
                        </h5>
                        <div class="text-muted small mt-1">
                            Cochez les parties à inclure et définissez le nombre de questions
                            (0 = toutes)
                        </div>
                    </div>
                    <div class="card-body p-3">
                        <?php if (empty($parties)): ?>
                            <div class="text-center text-muted py-4">Aucune partie dans ce module.</div>
                        <?php else: ?>
                        <div class="d-flex gap-2 mb-3">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAllParties">
                                <i class="bi bi-check2-all me-1"></i>Tout sélectionner
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnNoneParties">
                                <i class="bi bi-x-lg me-1"></i>Tout désélectionner
                            </button>
                        </div>
                        <?php foreach ($parties as $p): ?>
                        <div class="card mb-2 border partie-card" data-pid="<?= $p['id'] ?>" data-total="<?= $p['nb_questions'] ?>">
                            <div class="card-body py-2 px-3">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input partie-check" type="checkbox"
                                               name="partie_ids[]"
                                               value="<?= $p['id'] ?>"
                                               id="p<?= $p['id'] ?>"
                                               checked>
                                    </div>
                                    <div class="flex-grow-1">
                                        <label class="fw-semibold mb-0" for="p<?= $p['id'] ?>">
                                            <?= sanitize($p['nom']) ?>
                                        </label>
                                        <span class="badge bg-secondary-subtle text-secondary ms-2"><?= $p['nb_questions'] ?> question<?= $p['nb_questions'] > 1 ? 's' : '' ?></span>
                                    </div>
                                    <div style="width:130px">
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text" title="Nb questions (0 = toutes)">Q</span>
                                            <input type="number"
                                                   class="form-control nb-q-input"
                                                   name="nb_q_<?= $p['id'] ?>"
                                                   value="0"
                                                   min="0"
                                                   max="<?= $p['nb_questions'] ?>"
                                                   title="0 = toutes les questions">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <!-- Résumé -->
                        <div class="alert alert-info rounded-3 py-2 mt-3 mb-0" id="partieSummary">
                            <i class="bi bi-info-circle me-1"></i>
                            <span id="summaryText">Calcul en cours…</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Colonne droite : métadonnées EFM -->
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-header bg-white border-0 py-3 px-4">
                        <h5 class="mb-0 fw-bold">
                            <i class="bi bi-card-text me-2 text-danger"></i>
                            Informations de l'examen
                        </h5>
                    </div>
                    <div class="card-body p-4">

                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Code module</label>
                                <input type="text" name="code_module" class="form-control"
                                       placeholder="Ex : M205"
                                       value="<?= sanitize($_POST['code_module'] ?? '') ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Filière</label>
                                <input type="text" name="filiere" class="form-control"
                                       placeholder="Ex : IDOCC"
                                       value="<?= sanitize($_POST['filiere'] ?? '') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Intitulé du module</label>
                                <input type="text" name="intitule" class="form-control"
                                       value="<?= sanitize($_POST['intitule'] ?? $module['nom']) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Établissement</label>
                                <input type="text" name="etablissement" class="form-control"
                                       placeholder="Ex : ISTA NTIC Rabat"
                                       value="<?= sanitize($_POST['etablissement'] ?? '') ?>">
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label fw-semibold">Durée</label>
                                <input type="text" name="duree" class="form-control"
                                       placeholder="2h"
                                       value="<?= sanitize($_POST['duree'] ?? $module['duree_minutes'] . ' min') ?>">
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label fw-semibold">Année scolaire</label>
                                <input type="text" name="annee" class="form-control"
                                       placeholder="25/26"
                                       value="<?= sanitize($_POST['annee'] ?? $anneeDefaut) ?>">
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label fw-semibold">Note max</label>
                                <div class="d-flex gap-3 mt-1">
                                    <?php foreach ([20, 40] as $n): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="note_max"
                                               value="<?= $n ?>" id="nm<?= $n ?>"
                                               <?= (($_POST['note_max'] ?? $module['note_max']) == $n) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="nm<?= $n ?>">/ <?= $n ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <hr class="my-3">
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="shuffle"
                                       id="shuffle" value="1" checked>
                                <label class="form-check-label" for="shuffle">
                                    Mélanger les questions (ordre aléatoire)
                                </label>
                            </div>
                        </div>
                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="shuffle_choix"
                                       id="shuffle_choix" value="1" checked>
                                <label class="form-check-label" for="shuffle_choix">
                                    Mélanger les choix de réponse
                                </label>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-danger" id="btnGenerer">
                                <i class="bi bi-printer me-2"></i>Générer l'EFM
                            </button>
                            <a href="efm.php?module_id=<?= $module['id'] ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Réinitialiser
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const checks  = document.querySelectorAll('.partie-check');
const inputs  = document.querySelectorAll('.nb-q-input');
const summary = document.getElementById('summaryText');
const cards   = document.querySelectorAll('.partie-card');

function updateSummary() {
    let totalSel = 0, totalQ = 0;
    checks.forEach(cb => {
        if (!cb.checked) return;
        totalSel++;
        const card   = cb.closest('.partie-card');
        const nbTot  = parseInt(card.dataset.total);
        const nbInp  = parseInt(card.querySelector('.nb-q-input').value) || 0;
        totalQ      += (nbInp === 0) ? nbTot : Math.min(nbInp, nbTot);
    });
    if (summary) {
        summary.textContent = totalSel === 0
            ? "Aucune partie sélectionnée."
            : `${totalSel} partie(s) — ${totalQ} question(s) au total dans l'EFM.`;
    }
    const btn = document.getElementById('btnGenerer');
    if (btn) btn.disabled = (totalSel === 0);
}

// Activer/désactiver le champ nb selon la case
checks.forEach(cb => {
    const card  = cb.closest('.partie-card');
    const input = card.querySelector('.nb-q-input');
    cb.addEventListener('change', () => {
        input.disabled = !cb.checked;
        card.classList.toggle('opacity-50', !cb.checked);
        updateSummary();
    });
});

inputs.forEach(inp => inp.addEventListener('input', updateSummary));

document.getElementById('btnAllParties')?.addEventListener('click', () => {
    checks.forEach(cb => {
        cb.checked = true;
        const card = cb.closest('.partie-card');
        card.querySelector('.nb-q-input').disabled = false;
        card.classList.remove('opacity-50');
    });
    updateSummary();
});
document.getElementById('btnNoneParties')?.addEventListener('click', () => {
    checks.forEach(cb => {
        cb.checked = false;
        const card = cb.closest('.partie-card');
        card.querySelector('.nb-q-input').disabled = true;
        card.classList.add('opacity-50');
    });
    updateSummary();
});

updateSummary();
</script>
</body>
</html>
