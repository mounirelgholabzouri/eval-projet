<?php
require_once __DIR__ . '/includes/functions.php';
session_name(SESSION_EVAL_NAME ?? 'eval_stagiaire');
session_start();

$erreurs = [];
$modules = getModulesActifs();
$groupes = getGroupes();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom      = trim($_POST['nom'] ?? '');
    $prenom   = trim($_POST['prenom'] ?? '');
    $moduleId = (int)($_POST['module_id'] ?? 0);
    $groupeId = (int)($_POST['groupe_id'] ?? 0);
    $groupeLibre = trim($_POST['groupe_libre'] ?? '');

    if (strlen($nom) < 2)    $erreurs[] = "Le nom est requis (2 caractères minimum).";
    if (strlen($prenom) < 2) $erreurs[] = "Le prénom est requis (2 caractères minimum).";
    if ($moduleId <= 0)      $erreurs[] = "Veuillez sélectionner un module d'évaluation.";
    if ($groupeId <= 0 && strlen($groupeLibre) < 2) $erreurs[] = "Veuillez sélectionner ou saisir votre groupe.";

    if (empty($erreurs)) {
        $module = getModule($moduleId);
        if (!$module) {
            $erreurs[] = "Module invalide.";
        } else {
            $questions = getQuestionsModule($moduleId);
            if (empty($questions)) {
                $erreurs[] = "Ce module ne contient pas encore de questions. Contactez votre formateur.";
            } else {
                // Trouver ou créer le groupe si saisi manuellement
                $annee = trim($_POST['annee_scolaire'] ?? getAnneeCourante());
                if ($groupeId <= 0 && strlen($groupeLibre) >= 2) {
                    $groupeId = trouverOuCreerGroupe($groupeLibre, $annee);
                }
                // Trouver ou créer le stagiaire
                $nomSanitize    = htmlspecialchars($nom, ENT_QUOTES, 'UTF-8');
                $prenomSanitize = htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8');
                $stagiaireId = trouverOuCreerStagiaire($nomSanitize, $prenomSanitize, $groupeId, $annee);

                $session = creerSession(
                    $nomSanitize,
                    $prenomSanitize,
                    $groupeId > 0 ? $groupeId : null,
                    htmlspecialchars($groupeLibre, ENT_QUOTES, 'UTF-8'),
                    $moduleId,
                    $stagiaireId
                );
                $_SESSION['eval_session_id']    = $session['id'];
                $_SESSION['eval_session_token'] = $session['token'];
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
    <title>Identification — Évaluation en ligne</title>
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
                <p class="text-white-50">Saisissez vos informations pour commencer</p>
            </div>

            <!-- Carte formulaire -->
            <div class="card shadow-lg border-0 rounded-4">
                <div class="card-body p-4 p-md-5">

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

                    <!-- Bannière identité mémorisée (affichée par JS) -->
                    <div id="identity-banner" class="alert alert-success rounded-3 d-flex align-items-center justify-content-between mb-4" style="display:none!important">
                        <div>
                            <i class="bi bi-person-check-fill me-2"></i>
                            <strong id="identity-name"></strong>
                            <span class="text-muted ms-2 small" id="identity-group"></span>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-3" id="btn-change-identity">
                            <i class="bi bi-pencil me-1"></i>Changer
                        </button>
                    </div>

                    <form method="POST" action="" novalidate id="eval-form">
                        <div class="row g-3">

                            <!-- Bloc identité (masqué si mémorisée) -->
                            <div id="bloc-identite">
                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-person me-1 text-primary"></i>Nom
                                        </label>
                                        <input type="text" name="nom" id="input-nom" class="form-control form-control-lg"
                                               placeholder="Dupont"
                                               value="<?= sanitize($_POST['nom'] ?? '') ?>"
                                               required autofocus>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-person-fill me-1 text-primary"></i>Prénom
                                        </label>
                                        <input type="text" name="prenom" id="input-prenom" class="form-control form-control-lg"
                                               placeholder="Jean"
                                               value="<?= sanitize($_POST['prenom'] ?? '') ?>"
                                               required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-people me-1 text-primary"></i>Groupe
                                        </label>
                                        <?php if (!empty($groupes)): ?>
                                        <select name="groupe_id" id="groupe_select" class="form-select form-select-lg"
                                                onchange="toggleGroupeLibre(this)">
                                            <option value="">— Sélectionner votre groupe —</option>
                                            <?php foreach ($groupes as $g): ?>
                                                <option value="<?= $g['id'] ?>"
                                                    <?= (isset($_POST['groupe_id']) && $_POST['groupe_id'] == $g['id']) ? 'selected' : '' ?>>
                                                    <?= sanitize($g['nom']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <option value="-1">Autre (saisir manuellement)</option>
                                        </select>
                                        <div id="groupe_libre_wrap" class="mt-2" style="display:none;">
                                            <input type="text" name="groupe_libre" id="input-groupe-libre" class="form-control"
                                                   placeholder="Nom de votre groupe"
                                                   value="<?= sanitize($_POST['groupe_libre'] ?? '') ?>">
                                        </div>
                                        <?php else: ?>
                                        <input type="text" name="groupe_libre" id="input-groupe-libre" class="form-control form-control-lg"
                                               placeholder="Ex: Groupe A BTS SIO"
                                               value="<?= sanitize($_POST['groupe_libre'] ?? '') ?>"
                                               required>
                                        <input type="hidden" name="groupe_id" value="0">
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">
                                    <i class="bi bi-calendar2-week me-1 text-primary"></i>Année scolaire
                                </label>
                                <select name="annee_scolaire" id="input-annee" class="form-select form-select-lg">
                                    <?php foreach (getAnneesDisponibles() as $a): ?>
                                        <option value="<?= $a ?>" <?= $a === getAnneeCourante() ? 'selected' : '' ?>>
                                            <?= $a ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">
                                    <i class="bi bi-journal-text me-1 text-primary"></i>Module / Évaluation
                                </label>
                                <select name="module_id" class="form-select form-select-lg" required>
                                    <option value="">— Choisir le module —</option>
                                    <?php foreach ($modules as $m): ?>
                                        <option value="<?= $m['id'] ?>"
                                            <?= (isset($_POST['module_id']) && $_POST['module_id'] == $m['id']) ? 'selected' : '' ?>>
                                            <?= sanitize($m['nom']) ?>
                                            (<?= $m['duree_minutes'] ?> min)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12 mt-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="accepte" required>
                                    <label class="form-check-label text-muted small" for="accepte">
                                        Je certifie que les informations saisies sont exactes et que je réalise cette évaluation de façon individuelle.
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

            <!-- Lien admin -->
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
const LS_KEY = 'eval_stagiaire_identity';

function toggleGroupeLibre(sel) {
    const wrap = document.getElementById('groupe_libre_wrap');
    if (sel.value === '-1') {
        wrap.style.display = 'block';
        wrap.querySelector('input').required = true;
        sel.value = '0';
    } else {
        wrap.style.display = 'none';
        wrap.querySelector('input').required = false;
    }
}

function sauvegarderIdentite() {
    const nom         = document.getElementById('input-nom')?.value.trim();
    const prenom      = document.getElementById('input-prenom')?.value.trim();
    const groupeSel   = document.getElementById('groupe_select');
    const groupeLibre = document.getElementById('input-groupe-libre')?.value.trim();
    if (!nom || !prenom) return;
    const data = {
        nom,
        prenom,
        groupe_id:      groupeSel ? groupeSel.value : '0',
        groupe_label:   groupeSel ? groupeSel.options[groupeSel.selectedIndex]?.text : groupeLibre,
        groupe_libre:   groupeLibre || '',
        annee_scolaire: document.getElementById('input-annee')?.value || ''
    };
    localStorage.setItem(LS_KEY, JSON.stringify(data));
}

function chargerIdentite() {
    const raw = localStorage.getItem(LS_KEY);
    if (!raw) return;
    let id;
    try { id = JSON.parse(raw); } catch(e) { return; }
    if (!id.nom || !id.prenom) return;

    // Pré-remplir les champs cachés
    const inputNom    = document.getElementById('input-nom');
    const inputPrenom = document.getElementById('input-prenom');
    const groupeSel   = document.getElementById('groupe_select');
    const groupeLibre = document.getElementById('input-groupe-libre');

    if (inputNom)    inputNom.value    = id.nom;
    if (inputPrenom) inputPrenom.value = id.prenom;
    if (groupeSel && id.groupe_id) {
        groupeSel.value = id.groupe_id;
        if (id.groupe_libre && groupeSel.value === '0') {
            const wrap = document.getElementById('groupe_libre_wrap');
            if (wrap) wrap.style.display = 'block';
        }
    }
    if (groupeLibre && id.groupe_libre) groupeLibre.value = id.groupe_libre;
    const inputAnnee = document.getElementById('input-annee');
    if (inputAnnee && id.annee_scolaire) inputAnnee.value = id.annee_scolaire;

    // Afficher bannière, masquer bloc identité
    const banner = document.getElementById('identity-banner');
    const bloc   = document.getElementById('bloc-identite');
    const nameEl = document.getElementById('identity-name');
    const grpEl  = document.getElementById('identity-group');

    if (banner) {
        nameEl.textContent = id.prenom + ' ' + id.nom.toUpperCase();
        grpEl.textContent  = id.groupe_label && !id.groupe_label.includes('Sélectionner')
                             ? '— ' + id.groupe_label : '';
        banner.style.removeProperty('display');
        banner.classList.remove('d-none');
    }
    if (bloc) bloc.style.display = 'none';

    // Supprimer le required sur les champs masqués
    [inputNom, inputPrenom, groupeSel].forEach(el => el && el.removeAttribute('required'));
}

function reinitialiserIdentite() {
    localStorage.removeItem(LS_KEY);
    const banner = document.getElementById('identity-banner');
    const bloc   = document.getElementById('bloc-identite');
    if (banner) banner.style.display = 'none';
    if (bloc)   bloc.style.display   = 'block';
    const inputNom    = document.getElementById('input-nom');
    const inputPrenom = document.getElementById('input-prenom');
    const groupeSel   = document.getElementById('groupe_select');
    [inputNom, inputPrenom, groupeSel].forEach(el => { if(el) { el.value = ''; el.setAttribute('required',''); }});
    if (inputNom) inputNom.focus();
}

document.addEventListener('DOMContentLoaded', function () {
    // Bouton désactivé tant que checkbox non cochée
    const cb  = document.getElementById('accepte');
    const btn = document.getElementById('btn-submit');
    if (cb && btn) {
        btn.disabled = true;
        cb.addEventListener('change', () => btn.disabled = !cb.checked);
    }

    // Charger identité mémorisée
    chargerIdentite();

    // Bouton "Changer d'identité"
    document.getElementById('btn-change-identity')
        ?.addEventListener('click', reinitialiserIdentite);

    // Sauvegarder identité à la soumission
    document.getElementById('eval-form')
        ?.addEventListener('submit', sauvegarderIdentite);
});
</script>
</body>
</html>
