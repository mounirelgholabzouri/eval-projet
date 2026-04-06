<?php
/**
 * Utilitaire de changement de mot de passe administrateur
 * Accès : http://localhost/eval-projet/changer_mot_de_passe_admin.php
 * IMPORTANT : Supprimer ce fichier après utilisation !
 */
require_once __DIR__ . '/config/database.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user    = trim($_POST['username'] ?? '');
    $newPass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$user || !$newPass) {
        $msg = ['type' => 'danger', 'text' => 'Tous les champs sont requis.'];
    } elseif ($newPass !== $confirm) {
        $msg = ['type' => 'danger', 'text' => 'Les mots de passe ne correspondent pas.'];
    } elseif (strlen($newPass) < 6) {
        $msg = ['type' => 'danger', 'text' => 'Mot de passe trop court (6 caractères min).'];
    } else {
        try {
            $pdo  = getDB();
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE username = ?");
            $stmt->execute([$hash, $user]);
            if ($stmt->rowCount() > 0) {
                $msg = ['type' => 'success', 'text' => "Mot de passe mis à jour pour « $user ». Supprimez ce fichier !"];
            } else {
                $msg = ['type' => 'warning', 'text' => "Utilisateur « $user » non trouvé."];
            }
        } catch (\Throwable $e) {
            $msg = ['type' => 'danger', 'text' => 'Erreur : ' . htmlspecialchars($e->getMessage())];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Changer le mot de passe admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center min-vh-100">
<div class="container" style="max-width:420px">
    <div class="card shadow rounded-4 border-0">
        <div class="card-body p-4">
            <h4 class="fw-bold mb-1"><i class="bi bi-key me-2"></i>Changer le mot de passe</h4>
            <p class="text-muted small mb-4">Supprimer ce fichier après utilisation.</p>
            <?php if ($msg): ?>
            <div class="alert alert-<?= $msg['type'] ?>"><?= $msg['text'] ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Identifiant admin</label>
                    <input type="text" name="username" class="form-control" value="admin" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nouveau mot de passe</label>
                    <input type="password" name="new_password" class="form-control" required>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold">Confirmer</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Mettre à jour</button>
            </form>
        </div>
    </div>
</div>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</body>
</html>
