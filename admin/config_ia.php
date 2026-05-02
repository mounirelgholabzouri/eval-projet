<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$msg = '';
$erreur = '';

// Modèles disponibles
$modeles_disponibles = [
    'claude-opus-4-7' => 'Claude Opus 4.7 (Plus puissant)',
    'claude-sonnet-4-20250514' => 'Claude Sonnet 4 (Recommandé)',
    'claude-haiku-4-5-20251001' => 'Claude Haiku (Plus rapide, moins cher)',
];

// Récupérer les configurations actuelles
$apiKey = '';
$modelCourant = getAIModel();

try {
    $stmt = $pdo->query("SELECT cle, valeur FROM config WHERE cle IN ('anthropic_api_key', 'ai_model')");
    $configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $apiKey = $configs['anthropic_api_key'] ?? '';
} catch (Exception $e) {
    $erreur = "Erreur lors de la lecture des configurations";
}

// Sauvegarder les configurations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    $newApiKey = trim($_POST['anthropic_api_key'] ?? '');
    $newModel = trim($_POST['ai_model'] ?? 'claude-sonnet-4-20250514');

    // Valider le modèle
    if (!array_key_exists($newModel, $modeles_disponibles)) {
        $erreur = "Modèle IA invalide";
    } else {
        try {
            if ($newApiKey) {
                $pdo->prepare("INSERT INTO config (cle, valeur) VALUES ('anthropic_api_key', ?)
                              ON DUPLICATE KEY UPDATE valeur = ?")
                    ->execute([$newApiKey, $newApiKey]);
            }

            $pdo->prepare("INSERT INTO config (cle, valeur) VALUES ('ai_model', ?)
                          ON DUPLICATE KEY UPDATE valeur = ?")
                ->execute([$newModel, $newModel]);

            $msg = "Configuration sauvegardée avec succès";
            $apiKey = $newApiKey;
            $modelCourant = $newModel;
        } catch (Exception $e) {
            $erreur = "Erreur lors de la sauvegarde: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configuration IA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container py-4" style="max-width:600px">
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="results.php" class="btn btn-sm btn-outline-secondary rounded-3">
            <i class="bi bi-arrow-left"></i>
        </a>
        <h2 class="h4 fw-bold mb-0">
            <i class="bi bi-robot me-2 text-primary"></i>Configuration IA
        </h2>
    </div>

    <?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($erreur): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($erreur) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <form method="POST">
                <!-- Clé API Anthropic -->
                <div class="mb-4">
                    <label for="anthropic_api_key" class="form-label fw-semibold">
                        <i class="bi bi-key me-2 text-warning"></i>Clé API Anthropic
                    </label>
                    <input type="password" id="anthropic_api_key" name="anthropic_api_key"
                           class="form-control form-control-lg"
                           placeholder="sk-ant-..."
                           value="<?= htmlspecialchars($apiKey) ?>">
                    <small class="text-muted d-block mt-2">
                        Obtenir votre clé sur <a href="https://console.anthropic.com/keys" target="_blank" rel="noopener">console.anthropic.com</a>
                    </small>
                </div>

                <!-- Modèle IA -->
                <div class="mb-4">
                    <label for="ai_model" class="form-label fw-semibold">
                        <i class="bi bi-cpu me-2 text-info"></i>Modèle IA
                    </label>
                    <select id="ai_model" name="ai_model" class="form-select form-select-lg">
                        <?php foreach ($modeles_disponibles as $id => $label): ?>
                        <option value="<?= $id ?>" <?= $modelCourant === $id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted d-block mt-2">
                        <strong>Sonnet</strong> : Meilleur rapport qualité/coût<br>
                        <strong>Opus</strong> : Plus puissant pour analyses complexes<br>
                        <strong>Haiku</strong> : Plus rapide et moins cher
                    </small>
                </div>

                <!-- Bouton Save -->
                <div class="d-grid gap-2">
                    <button type="submit" name="save_config" class="btn btn-primary btn-lg rounded-3">
                        <i class="bi bi-check-circle me-2"></i>Sauvegarder Configuration
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Info Configuration -->
    <div class="card border-0 shadow-sm rounded-4 mt-4">
        <div class="card-header bg-light border-0 py-3 px-4">
            <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle me-2 text-info"></i>Configuration Actuelle</h6>
        </div>
        <div class="card-body px-4 py-3">
            <dl class="row mb-0">
                <dt class="col-sm-4">Modèle IA :</dt>
                <dd class="col-sm-8">
                    <code><?= htmlspecialchars($modelCourant) ?></code>
                    <span class="badge bg-success ms-2"><?= htmlspecialchars($modeles_disponibles[$modelCourant] ?? 'Inconnu') ?></span>
                </dd>
                <dt class="col-sm-4 mt-3">Clé API :</dt>
                <dd class="col-sm-8 mt-3">
                    <?php if ($apiKey): ?>
                    <span class="badge bg-success">
                        <i class="bi bi-check-circle me-1"></i>Configurée
                    </span>
                    <small class="text-muted ms-2"><?= htmlspecialchars(substr($apiKey, 0, 10)) ?>...<?= htmlspecialchars(substr($apiKey, -10)) ?></small>
                    <?php else: ?>
                    <span class="badge bg-warning text-dark">
                        <i class="bi bi-exclamation-triangle me-1"></i>Non configurée
                    </span>
                    <?php endif; ?>
                </dd>
            </dl>
        </div>
    </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
