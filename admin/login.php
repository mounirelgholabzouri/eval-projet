<?php
require_once __DIR__ . '/../config/database.php';
session_name(ADMIN_SESSION_NAME);
session_start();

if (!empty($_SESSION['admin_id'])) {
    header('Location: index.php'); exit;
}

$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_name'] = $admin['nom'] ?: $admin['username'];
            header('Location: index.php'); exit;
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
    <title>Connexion Formateur</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-gradient-primary min-vh-100 d-flex align-items-center">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="text-center mb-4">
                <div class="brand-icon mb-3"><i class="bi bi-shield-lock-fill"></i></div>
                <h1 class="h4 text-white fw-bold">Espace Formateur</h1>
                <p class="text-white-50 small">Administration des évaluations</p>
            </div>
            <div class="card border-0 shadow-lg rounded-4">
                <div class="card-body p-4">
                    <?php if ($erreur): ?>
                        <div class="alert alert-danger rounded-3 py-2 small">
                            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($erreur) ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Identifiant</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" name="username" class="form-control" autofocus
                                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Mot de passe</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 fw-semibold">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Se connecter
                        </button>
                    </form>
                </div>
            </div>
            <div class="text-center mt-3">
                <a href="../index.php" class="text-white-50 small text-decoration-none">
                    <i class="bi bi-arrow-left me-1"></i>Retour à l'accueil
                </a>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
