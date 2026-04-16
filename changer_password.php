<?php
require_once __DIR__ . '/includes/functions.php';
session_name(SESSION_EVAL_NAME ?? 'eval_stagiaire');
session_start();

if (empty($_SESSION['stagiaire_id'])) {
    redirect('login_stagiaire.php');
}

$stagiaireId = (int)$_SESSION['stagiaire_id'];
$erreurs = [];
$succes  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nouveau  = $_POST['nouveau'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (strlen($nouveau) < 6) {
        $erreurs[] = "Le mot de passe doit faire au moins 6 caractères.";
    }
    if ($nouveau !== $confirm) {
        $erreurs[] = "Les mots de passe ne correspondent pas.";
    }

    if (empty($erreurs)) {
        $pdo  = getDB();
        $hash = password_hash($nouveau, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE stagiaires SET password_hash=?, must_change_password=0 WHERE id=?")
            ->execute([$hash, $stagiaireId]);
        $_SESSION['must_change_password'] = false;
        $succes = true;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Changer mon mot de passe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-gradient-primary min-vh-100 d-flex align-items-center">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">

            <div class="text-center mb-4">
                <div class="brand-icon mb-3"><i class="bi bi-shield-lock-fill"></i></div>
                <h1 class="h4 text-white fw-bold">Changer mon mot de passe</h1>
                <p class="text-white-50 small">
                    Connecté en tant que <strong><?= sanitize($_SESSION['stagiaire_prenom'] ?? '') ?> <?= sanitize(strtoupper($_SESSION['stagiaire_nom'] ?? '')) ?></strong>
                </p>
            </div>

            <div class="card border-0 shadow-lg rounded-4">
                <div class="card-body p-4">

                    <?php if ($succes): ?>
                        <div class="alert alert-success rounded-3">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            Mot de passe modifié avec succès !
                        </div>
                        <a href="index.php" class="btn btn-primary w-100">
                            <i class="bi bi-arrow-left me-2"></i>Retour à l'accueil
                        </a>
                    <?php else: ?>

                        <?php if (!empty($erreurs)): ?>
                            <div class="alert alert-danger rounded-3 py-2 small">
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($erreurs as $e): ?>
                                        <li><?= sanitize($e) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form method="POST" novalidate>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Nouveau mot de passe <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                    <input type="password" name="nouveau" class="form-control" placeholder="6 caractères minimum" required autofocus>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Confirmer <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                    <input type="password" name="confirm" class="form-control" placeholder="Répéter le mot de passe" required>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">
                                <i class="bi bi-check-lg me-2"></i>Enregistrer
                            </button>
                        </form>

                        <hr class="my-3">
                        <div class="text-center small">
                            <a href="index.php" class="text-muted text-decoration-none">
                                <i class="bi bi-arrow-left me-1"></i>Plus tard
                            </a>
                        </div>

                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
