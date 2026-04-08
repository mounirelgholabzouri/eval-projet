<?php
require_once __DIR__ . '/includes/functions.php';
session_name(SESSION_EVAL_NAME ?? 'eval_stagiaire');
session_start();

// Déjà connecté → accueil
if (!empty($_SESSION['stagiaire_id'])) {
    redirect('index.php');
}

$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login && $password) {
        $stagiaire = getStagiaireByLogin($login);
        if ($stagiaire && $stagiaire['password_hash'] && password_verify($password, $stagiaire['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['stagiaire_id']       = $stagiaire['id'];
            $_SESSION['stagiaire_nom']       = $stagiaire['nom'];
            $_SESSION['stagiaire_prenom']    = $stagiaire['prenom'];
            $_SESSION['stagiaire_groupe_id'] = $stagiaire['groupe_id'];
            $_SESSION['stagiaire_groupe_nom']= $stagiaire['groupe_nom'];
            $_SESSION['stagiaire_annee']     = $stagiaire['annee_scolaire'];
            redirect('index.php');
        } else {
            $erreur = "Identifiants invalides.";
        }
    } else {
        $erreur = "Veuillez remplir tous les champs.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion Stagiaire — Évaluation en ligne</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-gradient-primary min-vh-100 d-flex align-items-center">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">

            <div class="text-center mb-4">
                <div class="brand-icon mb-3"><i class="bi bi-mortarboard-fill"></i></div>
                <h1 class="h4 text-white fw-bold">Espace Stagiaire</h1>
                <p class="text-white-50 small">Connectez-vous pour accéder à vos évaluations</p>
            </div>

            <div class="card border-0 shadow-lg rounded-4">
                <div class="card-body p-4">
                    <?php if ($erreur): ?>
                        <div class="alert alert-danger rounded-3 py-2 small">
                            <i class="bi bi-exclamation-triangle me-2"></i><?= sanitize($erreur) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Identifiant</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" name="login" class="form-control" autofocus
                                       value="<?= sanitize($_POST['login'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Mot de passe</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Se connecter
                        </button>
                    </form>

                    <hr class="my-3">
                    <div class="text-center small">
                        Pas encore inscrit ?
                        <a href="register.php" class="text-primary fw-semibold">Créer un compte</a>
                    </div>
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
</body>
</html>
