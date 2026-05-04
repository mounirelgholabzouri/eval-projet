<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$msg    = '';
$erreur = '';

$providers = getProvidersConfig();

// Récupérer la configuration actuelle
$currentProvider = getAIProvider();
$currentModel    = getAIModel();

$apiKeys = [];
try {
    $stmt = $pdo->query("SELECT cle, valeur FROM config WHERE cle IN ('anthropic_api_key','openai_api_key','google_api_key','ai_provider','ai_model')");
    $configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $apiKeys['anthropic'] = $configs['anthropic_api_key'] ?? '';
    $apiKeys['openai']    = $configs['openai_api_key']    ?? '';
    $apiKeys['google']    = $configs['google_api_key']    ?? '';
} catch (Exception $e) {
    $erreur = "Erreur lors de la lecture des configurations";
}

// Sauvegarder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    $newProvider = trim($_POST['ai_provider'] ?? 'anthropic');
    $newModel    = trim($_POST['ai_model']    ?? '');

    if (!array_key_exists($newProvider, $providers)) {
        $erreur = "Fournisseur IA invalide";
    } else {
        $allModels = $providers[$newProvider]['models'];
        if (!array_key_exists($newModel, $allModels)) {
            $newModel = array_key_first($allModels);
        }
        try {
            // Sauvegarder les clés API fournies
            foreach (['anthropic', 'openai', 'google'] as $prov) {
                $keyField = $prov . '_api_key_input';
                $keyValue = trim($_POST[$keyField] ?? '');
                if ($keyValue !== '') {
                    $dbKey = $prov . '_api_key';
                    $pdo->prepare("INSERT INTO config (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = ?")
                        ->execute([$dbKey, $keyValue, $keyValue]);
                    $apiKeys[$prov] = $keyValue;
                }
            }
            // Sauvegarder provider et modèle
            foreach (['ai_provider' => $newProvider, 'ai_model' => $newModel] as $k => $v) {
                $pdo->prepare("INSERT INTO config (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = ?")
                    ->execute([$k, $v, $v]);
            }

            $msg = "Configuration sauvegardée avec succès";
            $currentProvider = $newProvider;
            $currentModel    = $newModel;
        } catch (Exception $e) {
            $erreur = "Erreur lors de la sauvegarde : " . $e->getMessage();
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
    <style>
        .provider-card {
            cursor: pointer;
            border: 2px solid transparent;
            transition: border-color .2s, box-shadow .2s;
        }
        .provider-card:hover  { border-color: #6c757d; }
        .provider-card.active { border-color: #0d6efd; box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
        .provider-section { display: none; }
        .provider-section.active { display: block; }
    </style>
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container py-4" style="max-width:700px">

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

    <form method="POST" id="iaForm">
        <input type="hidden" name="ai_provider" id="hiddenProvider" value="<?= htmlspecialchars($currentProvider) ?>">
        <input type="hidden" name="ai_model"    id="hiddenModel"    value="<?= htmlspecialchars($currentModel) ?>">

        <!-- Choix du fournisseur -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 py-3 px-4">
                <h6 class="mb-0 fw-bold"><i class="bi bi-cpu me-2 text-primary"></i>Fournisseur IA</h6>
            </div>
            <div class="card-body px-4 pb-4 pt-2">
                <div class="row g-3">
                    <?php foreach ($providers as $provId => $prov): ?>
                    <div class="col-md-4">
                        <div class="card provider-card rounded-3 p-3 text-center <?= $currentProvider === $provId ? 'active' : '' ?>"
                             data-provider="<?= $provId ?>">
                            <i class="bi <?= $prov['icon'] ?> fs-2 text-<?= $prov['color'] ?> mb-2"></i>
                            <div class="fw-semibold small"><?= htmlspecialchars($prov['label']) ?></div>
                            <?php if ($apiKeys[$provId] ?? ''): ?>
                            <span class="badge bg-success mt-2"><i class="bi bi-check-circle me-1"></i>Clé configurée</span>
                            <?php else: ?>
                            <span class="badge bg-secondary mt-2">Clé manquante</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Sections par fournisseur -->
        <?php foreach ($providers as $provId => $prov): ?>
        <div class="provider-section <?= $currentProvider === $provId ? 'active' : '' ?>" data-section="<?= $provId ?>">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 py-3 px-4">
                    <h6 class="mb-0 fw-bold">
                        <i class="bi <?= $prov['icon'] ?> me-2 text-<?= $prov['color'] ?>"></i><?= htmlspecialchars($prov['label']) ?>
                    </h6>
                </div>
                <div class="card-body px-4 pb-4 pt-2">

                    <!-- Clé API -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-key me-2 text-warning"></i><?= htmlspecialchars($prov['key_label']) ?>
                        </label>
                        <input type="password" name="<?= $provId ?>_api_key_input"
                               class="form-control form-control-lg"
                               placeholder="<?= htmlspecialchars($prov['key_placeholder']) ?>"
                               value="<?= htmlspecialchars($apiKeys[$provId] ?? '') ?>">
                        <small class="text-muted d-block mt-2"><?= $prov['key_help'] ?></small>
                    </div>

                    <!-- Modèle -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-stars me-2 text-info"></i>Modèle
                        </label>
                        <select class="form-select form-select-lg model-select" data-provider="<?= $provId ?>">
                            <?php foreach ($prov['models'] as $modelId => $modelLabel): ?>
                            <option value="<?= $modelId ?>"
                                <?= ($currentProvider === $provId && $currentModel === $modelId) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($modelLabel) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="d-grid">
            <button type="submit" name="save_config" class="btn btn-primary btn-lg rounded-3">
                <i class="bi bi-check-circle me-2"></i>Sauvegarder Configuration
            </button>
        </div>
    </form>

    <!-- Configuration actuelle -->
    <div class="card border-0 shadow-sm rounded-4 mt-4">
        <div class="card-header bg-light border-0 py-3 px-4">
            <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle me-2 text-info"></i>Configuration Active</h6>
        </div>
        <div class="card-body px-4 py-3">
            <dl class="row mb-0">
                <dt class="col-sm-4">Fournisseur :</dt>
                <dd class="col-sm-8">
                    <span class="badge bg-primary"><?= htmlspecialchars($providers[$currentProvider]['label'] ?? $currentProvider) ?></span>
                </dd>
                <dt class="col-sm-4 mt-3">Modèle :</dt>
                <dd class="col-sm-8 mt-3">
                    <code><?= htmlspecialchars($currentModel) ?></code>
                </dd>
                <dt class="col-sm-4 mt-3">Clé API :</dt>
                <dd class="col-sm-8 mt-3">
                    <?php $activeKey = $apiKeys[$currentProvider] ?? ''; ?>
                    <?php if ($activeKey): ?>
                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Configurée</span>
                    <small class="text-muted ms-2"><?= htmlspecialchars(substr($activeKey, 0, 8)) ?>...<?= htmlspecialchars(substr($activeKey, -6)) ?></small>
                    <?php else: ?>
                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Non configurée</span>
                    <?php endif; ?>
                </dd>
            </dl>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const cards    = document.querySelectorAll('.provider-card');
    const sections = document.querySelectorAll('.provider-section');
    const hiddenProv  = document.getElementById('hiddenProvider');
    const hiddenModel = document.getElementById('hiddenModel');

    function activate(provId) {
        cards.forEach(c => c.classList.toggle('active', c.dataset.provider === provId));
        sections.forEach(s => s.classList.toggle('active', s.dataset.section === provId));
        hiddenProv.value = provId;
        // Sync model from the now-visible select
        const sel = document.querySelector(`.model-select[data-provider="${provId}"]`);
        if (sel) hiddenModel.value = sel.value;
    }

    cards.forEach(card => {
        card.addEventListener('click', () => activate(card.dataset.provider));
    });

    document.querySelectorAll('.model-select').forEach(sel => {
        sel.addEventListener('change', () => {
            if (hiddenProv.value === sel.dataset.provider) {
                hiddenModel.value = sel.value;
            }
        });
    });
})();
</script>
</body>
</html>
