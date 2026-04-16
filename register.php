<?php
require_once __DIR__ . '/includes/functions.php';
session_name(SESSION_EVAL_NAME ?? 'eval_stagiaire');
session_start();

if (!empty($_SESSION['stagiaire_id'])) {
    redirect('index.php');
}

$groupes = getGroupes();
$annees  = getAnneesDisponibles();
$erreurs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom        = trim($_POST['nom'] ?? '');
    $prenom     = trim($_POST['prenom'] ?? '');
    $groupeId   = (int)($_POST['groupe_id'] ?? 0);
    $groupeLibre= trim($_POST['groupe_libre'] ?? '');
    $annee      = trim($_POST['annee_scolaire'] ?? getAnneeCourante());
    $login      = trim($_POST['login'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm'] ?? '';

    if (strlen($nom) < 2)    $erreurs[] = "Le nom est requis (2 caractères min).";
    if (strlen($prenom) < 2) $erreurs[] = "Le prénom est requis (2 caractères min).";
    if (strlen($login) < 3)  $erreurs[] = "L'identifiant doit faire au moins 3 caractères.";
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $login)) $erreurs[] = "L'identifiant ne doit contenir que des lettres, chiffres, points, tirets.";
    if (strlen($password) < 6) $erreurs[] = "Le mot de passe doit faire au moins 6 caractères.";
    if ($password !== $confirm)  $erreurs[] = "Les mots de passe ne correspondent pas.";
    if ($groupeId <= 0 && strlen($groupeLibre) < 2) $erreurs[] = "Veuillez sélectionner ou saisir votre groupe.";
    if (loginExists($login)) $erreurs[] = "Cet identifiant est déjà utilisé.";

    if (empty($erreurs)) {
        // Créer ou trouver le groupe
        if ($groupeId <= 0 && strlen($groupeLibre) >= 2) {
            $groupeId = trouverOuCreerGroupe($groupeLibre, $annee);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo  = getDB();

        // Chercher stagiaire existant (même identité)
        $stmt = $pdo->prepare("SELECT id FROM stagiaires WHERE nom=? AND prenom=? AND groupe_id=? AND annee_scolaire=? LIMIT 1");
        $stmt->execute([$nom, $prenom, $groupeId, $annee]);
        $existing = $stmt->fetchColumn();

        if ($existing) {
            // Mettre à jour login/password
            $pdo->prepare("UPDATE stagiaires SET login=?, password_hash=? WHERE id=?")
                ->execute([$login, $hash, $existing]);
            $stagiaireId = (int)$existing;
        } else {
            // Créer nouveau stagiaire
            $pdo->prepare("INSERT INTO stagiaires (nom, prenom, groupe_id, annee_scolaire, login, password_hash) VALUES (?,?,?,?,?,?)")
                ->execute([$nom, $prenom, $groupeId, $annee, $login, $hash]);
            $stagiaireId = (int)$pdo->lastInsertId();
        }

        // Connecter automatiquement
        $stagiaire = getStagiaireByLogin($login);
        session_regenerate_id(true);
        $_SESSION['stagiaire_id']        = $stagiaire['id'];
        $_SESSION['stagiaire_nom']        = $stagiaire['nom'];
        $_SESSION['stagiaire_prenom']     = $stagiaire['prenom'];
        $_SESSION['stagiaire_groupe_id']  = $stagiaire['groupe_id'];
        $_SESSION['stagiaire_groupe_nom'] = $stagiaire['groupe_nom'];
        $_SESSION['stagiaire_annee']      = $stagiaire['annee_scolaire'];
        redirect('index.php');
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inscription — Évaluation en ligne</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-gradient-primary min-vh-100 d-flex align-items-center py-5">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">

            <div class="text-center mb-4">
                <div class="brand-icon mb-3"><i class="bi bi-person-plus-fill"></i></div>
                <h1 class="h4 text-white fw-bold">Créer un compte</h1>
                <p class="text-white-50 small">Inscrivez-vous pour accéder aux évaluations</p>
            </div>

            <div class="card border-0 shadow-lg rounded-4">
                <div class="card-body p-4">

                    <?php if (!empty($erreurs)): ?>
                        <div class="alert alert-danger rounded-3 py-2 small">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($erreurs as $e): ?>
                                    <li><?= sanitize($e) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="POST" novalidate>

                        <!-- Identité -->
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Nom <span class="text-danger">*</span></label>
                                <input type="text" name="nom" id="nom" class="form-control"
                                       value="<?= sanitize($_POST['nom'] ?? '') ?>" placeholder="DUPONT" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Prénom <span class="text-danger">*</span></label>
                                <input type="text" name="prenom" id="prenom" class="form-control"
                                       value="<?= sanitize($_POST['prenom'] ?? '') ?>" placeholder="Jean" required>
                            </div>
                        </div>

                        <!-- Groupe -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Groupe <span class="text-danger">*</span></label>
                            <select name="groupe_id" id="groupe_select" class="form-select" onchange="toggleGroupeLibre(this)">
                                <option value="">— Sélectionner —</option>
                                <?php foreach ($groupes as $g): ?>
                                    <option value="<?= $g['id'] ?>" <?= (isset($_POST['groupe_id']) && $_POST['groupe_id'] == $g['id']) ? 'selected' : '' ?>>
                                        <?= sanitize($g['nom']) ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="-1">Autre (saisir manuellement)</option>
                            </select>
                            <div id="groupe_libre_wrap" class="mt-2" style="display:none;">
                                <input type="text" name="groupe_libre" class="form-control"
                                       placeholder="Nom de votre groupe"
                                       value="<?= sanitize($_POST['groupe_libre'] ?? '') ?>">
                            </div>
                        </div>

                        <!-- Année scolaire -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Année scolaire</label>
                            <select name="annee_scolaire" class="form-select">
                                <?php foreach ($annees as $a): ?>
                                    <option value="<?= $a ?>" <?= $a === getAnneeCourante() ? 'selected' : '' ?>><?= $a ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <hr class="my-3">

                        <!-- Compte -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Identifiant <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-at"></i></span>
                                <input type="text" name="login" id="login" class="form-control"
                                       value="<?= sanitize($_POST['login'] ?? '') ?>"
                                       placeholder="ex: Jean.DUPONT" required>
                            </div>
                            <div class="form-text">Généré automatiquement depuis votre prénom et nom.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Mot de passe <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" name="password" id="password" class="form-control"
                                       placeholder="6 caractères minimum" required>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold small">Confirmer le mot de passe <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                <input type="password" name="confirm" id="confirm" class="form-control"
                                       placeholder="Répétez le mot de passe" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">
                            <i class="bi bi-person-check me-2"></i>Créer mon compte
                        </button>
                    </form>

                    <hr class="my-3">
                    <div class="text-center small">
                        Déjà inscrit ?
                        <a href="login_stagiaire.php" class="text-primary fw-semibold">Se connecter</a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
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
</script>
</body>
</html>
