<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$msg    = '';
$erreur = '';
$moduleId = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

if ($moduleId <= 0) {
    header("Location: modules.php");
    exit;
}

$module = getModule($moduleId);
if (!$module) {
    header("Location: modules.php");
    exit;
}

// Récupérer le nombre de questions
$stmt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE module_id = ?");
$stmt->execute([$moduleId]);
$nbQuestions = (int)$stmt->fetchColumn();

// Traitement suppression
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    try {
        $pdo->beginTransaction();

        // Récupérer tous les IDs des questions du module
        $stmt = $pdo->prepare("SELECT id FROM questions WHERE module_id = ?");
        $stmt->execute([$moduleId]);
        $questionIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($questionIds)) {
            // Créer les placeholders pour les requêtes IN
            $placeholders = implode(',', array_fill(0, count($questionIds), '?'));

            // Supprimer les réponses des stagiaires
            $stmt = $pdo->prepare("DELETE FROM reponses_stagiaires WHERE question_id IN ($placeholders)");
            $stmt->execute($questionIds);

            // Supprimer les choix de réponses
            $stmt = $pdo->prepare("DELETE FROM choix_reponses WHERE question_id IN ($placeholders)");
            $stmt->execute($questionIds);

            // Supprimer les questions
            $stmt = $pdo->prepare("DELETE FROM questions WHERE id IN ($placeholders)");
            $stmt->execute($questionIds);
        }

        // Supprimer le module
        $stmt = $pdo->prepare("DELETE FROM modules WHERE id = ?");
        $stmt->execute([$moduleId]);

        $pdo->commit();
        $msg = "Module et toutes ses données supprimés définitivement.";

        header("Location: modules.php?deleted=1&msg=" . urlencode($msg));
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $erreur = "Erreur lors de la suppression : " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Supprimer Module — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>

<div class="container-fluid py-4 px-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3 px-4">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>
                        Supprimer un module
                    </h5>
                </div>
                <div class="card-body p-4">
                    <?php if ($erreur): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($erreur) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <div class="alert alert-warning rounded-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Attention !</strong> Vous êtes sur le point de supprimer le module :
                        <br><strong class="text-danger"><?= htmlspecialchars($module['nom']) ?></strong>
                    </div>

                    <div class="bg-light p-3 rounded-3 mb-4">
                        <div class="text-center">
                            <h6 class="fw-bold text-muted">Questions</h6>
                            <p class="display-6 fw-bold text-primary"><?= $nbQuestions ?></p>
                        </div>
                    </div>

                    <div class="alert alert-danger rounded-3 mb-4">
                        <i class="bi bi-trash-fill me-2"></i>
                        <strong>Attention !</strong> Cette action supprimera le module, toutes ses questions et les réponses des stagiaires.
                        <br><span class="text-danger fw-bold">⚠️ IRRÉVERSIBLE</span>
                    </div>

                    <form method="POST">
                        <?= csrfField() ?>
                        <div class="d-flex gap-2 justify-content-end">
                            <a href="modules.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-1"></i>Annuler
                            </a>
                            <button type="submit" name="confirm_delete" class="btn btn-danger">
                                <i class="bi bi-check-lg me-1"></i>Confirmer la suppression
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 mt-4">
                <div class="card-header bg-white border-0 py-3 px-4">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-shield-exclamation me-2"></i>Informations importantes</h6>
                </div>
                <div class="card-body p-4">
                    <ul class="list-unstyled">
                        <li class="mb-2">
                            <i class="bi bi-exclamation-triangle text-danger me-2"></i>
                            <strong>Suppression définitive :</strong> Le module, toutes ses questions et les réponses des stagiaires seront supprimés.
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-info-circle text-info me-2"></i>
                            <strong>Sessions actives :</strong> Les sessions d'évaluation en cours ne seront pas affectées, mais les données seront supprimées.
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>


</body>
</html>
