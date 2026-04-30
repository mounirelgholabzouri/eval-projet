<?php
require_once __DIR__ . '/includes/functions.php';
session_name(SESSION_EVAL_NAME ?? 'eval_stagiaire');
session_start();

// Auth obligatoire
if (empty($_SESSION['stagiaire_id'])) {
    redirect('login_stagiaire.php');
}

// Infos stagiaire depuis session
$stagiaireId  = (int)$_SESSION['stagiaire_id'];
$stagNom      = $_SESSION['stagiaire_nom'];
$stagPrenom   = $_SESSION['stagiaire_prenom'];
$stagGroupeId = (int)$_SESSION['stagiaire_groupe_id'];
$stagGroupe   = $_SESSION['stagiaire_groupe_nom'];
$stagAnnee    = $_SESSION['stagiaire_annee'];

$erreurs = [];
$modules = getModulesActifs();

// Précharge les parties actives des modules actifs pour le sélecteur JS
$allParties = [];
foreach ($modules as $m) {
    $parties = getPartiesActives((int)$m['id']);
    if (count($parties) > 1) {
        $allParties[(int)$m['id']] = array_map(fn($p) => [
            'id'          => (int)$p['id'],
            'nom'         => $p['nom'],
            'nb_questions'=> (int)$p['nb_questions'],
        ], $parties);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $moduleId = (int)($_POST['module_id'] ?? 0);
    $partieId = (int)($_POST['partie_id'] ?? 0);
    if ($moduleId <= 0) $erreurs[] = "Veuillez sélectionner un module d'évaluation.";

    if (empty($erreurs)) {
        $module = getModule($moduleId);
        if (!$module) {
            $erreurs[] = "Module invalide.";
        } else {
            // Si une partie est choisie, vérifier qu'elle appartient au module et est active
            if ($partieId > 0) {
                $partie = getPartie($partieId);
                if (!$partie || (int)$partie['module_id'] !== $moduleId || !(int)$partie['actif']) {
                    $partieId = 0;
                }
            }

            // Si aucune partie choisie, filtrer sur les parties actives uniquement
            if ($partieId > 0) {
                $questions = array_filter(getQuestionsModule($moduleId), fn($q) => (int)$q['partie_id'] === $partieId);
            } else {
                $partiesActives = array_column(getPartiesActives($moduleId), 'id');
                $questions = array_filter(getQuestionsModule($moduleId), fn($q) => in_array((int)$q['partie_id'], array_map('intval', $partiesActives)));
            }

            if (empty($questions)) {
                $erreurs[] = "Cette sélection ne contient pas encore de questions. Contactez votre formateur.";
            } else {
                $session = creerSession(
                    $stagNom,
                    $stagPrenom,
                    $stagGroupeId,
                    '',
                    $moduleId,
                    $stagiaireId,
                    $partieId ?: null
                );
                $_SESSION['eval_session_id']    = $session['id'];
                $_SESSION['eval_session_token'] = $session['token'];
                $_SESSION['eval_partie_id']     = $partieId ?: null;
                redirect('quiz.php');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Évaluation en ligne</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-gradient-primary min-vh-100 d-flex align-items-center">

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">

            <!-- En-tête -->
            <div class="text-center mb-4">
                <div class="brand-icon mb-3">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
                <h1 class="h3 text-white fw-bold">Évaluation en ligne</h1>
                <p class="text-white-50">Choisissez votre module pour commencer</p>
            </div>

            <!-- Carte formulaire -->
            <div class="card shadow-lg border-0 rounded-4">
                <div class="card-body p-4 p-md-5">

                    <?php if (!empty($_SESSION['must_change_password'])): ?>
                    <div class="alert alert-warning rounded-3 d-flex align-items-center justify-content-between mb-3 py-2">
                        <div class="small">
                            <i class="bi bi-key-fill me-2"></i>
                            <strong>Mot de passe par défaut détecté.</strong>
                            Pour sécuriser votre compte, veuillez le modifier.
                        </div>
                        <a href="changer_password.php" class="btn btn-sm btn-warning ms-3 text-nowrap">
                            <i class="bi bi-pencil-square me-1"></i>Modifier
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- Bannière identité -->
                    <div class="alert alert-success rounded-3 d-flex align-items-center justify-content-between mb-4 py-2">
                        <div>
                            <i class="bi bi-person-check-fill me-2"></i>
                            <strong><?= sanitize($stagPrenom) ?> <?= sanitize(strtoupper($stagNom)) ?></strong>
                            <span class="text-muted ms-2 small">— <?= sanitize($stagGroupe) ?> · <?= sanitize($stagAnnee) ?></span>
                        </div>
                        <a href="logout_stagiaire.php" class="btn btn-sm btn-outline-secondary ms-3" title="Se déconnecter">
                            <i class="bi bi-box-arrow-right"></i>
                        </a>
                    </div>

                    <?php if (!empty($erreurs)): ?>
                        <div class="alert alert-danger rounded-3">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($erreurs as $e): ?>
                                    <li><?= sanitize($e) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($modules)): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-info-circle me-2"></i>
                            Aucune évaluation disponible pour le moment. Contactez votre formateur.
                        </div>
                    <?php else: ?>

                    <form method="POST" action="" novalidate id="eval-form">
                        <div class="row g-3">

                            <div class="col-12">
                                <label class="form-label fw-semibold">
                                    <i class="bi bi-journal-text me-1 text-primary"></i>Module / Évaluation
                                </label>
                                <select name="module_id" id="module_id" class="form-select form-select-lg" required autofocus>
                                    <option value="">— Choisir le module —</option>
                                    <?php foreach ($modules as $m): ?>
                                        <option value="<?= $m['id'] ?>"
                                            <?= (isset($_POST['module_id']) && $_POST['module_id'] == $m['id']) ? 'selected' : '' ?>>
                                            <?= sanitize($m['nom']) ?> (<?= $m['duree_minutes'] ?> min)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12" id="partie-group" style="display:none">
                                <label class="form-label fw-semibold">
                                    <i class="bi bi-bookmark me-1 text-primary"></i>Partie
                                </label>
                                <select name="partie_id" id="partie_id" class="form-select form-select-lg">
                                    <option value="">— Toutes les parties —</option>
                                </select>
                            </div>

                            <div class="col-12 mt-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="accepte" required>
                                    <label class="form-check-label text-muted small" for="accepte">
                                        Je certifie que je réalise cette évaluation de façon individuelle.
                                    </label>
                                </div>
                            </div>

                            <div class="col-12">
                                <button type="submit" id="btn-submit" class="btn btn-primary btn-lg w-100 fw-semibold py-3">
                                    <i class="bi bi-play-circle-fill me-2"></i>Commencer l'évaluation
                                </button>
                            </div>

                        </div>
                    </form>

                    <?php endif; ?>
                </div>
            </div>

            <div class="text-center mt-3">
                <a href="admin/login.php" class="text-white-50 small text-decoration-none">
                    <i class="bi bi-shield-lock me-1"></i>Espace formateur
                </a>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const partiesMap = <?= json_encode($allParties, JSON_UNESCAPED_UNICODE) ?>;

document.addEventListener('DOMContentLoaded', function () {
    const cb          = document.getElementById('accepte');
    const btn         = document.getElementById('btn-submit');
    const moduleSel   = document.getElementById('module_id');
    const partieGroup = document.getElementById('partie-group');
    const partieSel   = document.getElementById('partie_id');

    if (cb && btn) {
        btn.disabled = true;
        cb.addEventListener('change', () => btn.disabled = !cb.checked);
    }

    function updateParties() {
        const mid = parseInt(moduleSel.value, 10);
        partieSel.innerHTML = '<option value="">— Toutes les parties —</option>';
        if (partiesMap[mid]) {
            partiesMap[mid].forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.nom + ' (' + p.nb_questions + ' question' + (p.nb_questions > 1 ? 's' : '') + ')';
                partieSel.appendChild(opt);
            });
            partieGroup.style.display = '';
        } else {
            partieGroup.style.display = 'none';
        }
    }

    if (moduleSel) {
        moduleSel.addEventListener('change', updateParties);
        // Restaurer l'état après erreur POST
        <?php if (isset($_POST['module_id']) && $_POST['module_id'] > 0): ?>
        updateParties();
        partieSel.value = '<?= (int)($_POST['partie_id'] ?? 0) ?>';
        <?php endif; ?>
    }
});
</script>
</body>
</html>
