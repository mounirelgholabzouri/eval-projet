<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo      = getDB();
$moduleId = (int)($_GET['module_id'] ?? 0);
$module   = $moduleId ? getModule($moduleId) : null;
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

    if (!$module) {
        $erreur = "Module invalide.";
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

        $qs = http_build_query($params);
        header("Location: print_efm.php?$qs"); exit;
    }
}

$anneeDefaut = getAnneeFormation();
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
                <?= csrfField() ?>
                <input type="hidden" name="select_module" value="1">
                <div class="col-md-7">
                    <label class="form-label fw-semibold">Module</label>
                    <select name="module_id" class="form-select" required>
                        <option value="">— Choisir un module —</option>
                        <?php foreach ($modules as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= $m['id'] == $moduleId ? 'selected' : '' ?>>
                            <?= sanitize($m['nom']) ?> (<?= $m['nb_questions'] ?> Q)
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
        <?= csrfField() ?>
        <input type="hidden" name="generer_efm" value="1">
        <input type="hidden" name="module_id" value="<?= $module['id'] ?>">

        <div class="row g-4">

            <!-- Colonne unique : métadonnées EFM -->
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
</body>
</html>
