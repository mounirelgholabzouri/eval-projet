<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/claude_generator.php';
require_once __DIR__ . '/../config/database.php';

$pdo        = getDB();
$msg        = '';
$erreur     = '';
$questionsGenerees = null;
$moduleIdCible     = 0;
$allModules = getAllModules();

// ── Onglet actif ─────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'generate';
if (!in_array($activeTab, ['generate', 'config'])) $activeTab = 'generate';

// ── Lecture config IA ─────────────────────────────────────────
$providers       = getProvidersConfig();
$currentProvider = getAIProvider();
$currentModel    = getAIModel();
$apiKeys = ['anthropic' => '', 'openai' => '', 'google' => '', 'ollama' => ''];
try {
    $stmt = $pdo->query("SELECT cle, valeur FROM config WHERE cle IN
        ('anthropic_api_key','openai_api_key','google_api_key','ai_provider','ai_model','ollama_base_url')");
    $cfgs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $apiKeys['anthropic'] = $cfgs['anthropic_api_key'] ?? '';
    $apiKeys['openai']    = $cfgs['openai_api_key']    ?? '';
    $apiKeys['google']    = $cfgs['google_api_key']    ?? '';
    // Ollama : pas de clé API — considéré comme "configuré" si l'URL est définie (ou valeur par défaut)
    $apiKeys['ollama']    = $cfgs['ollama_base_url'] ?? 'http://ollama:11434';
} catch (Exception $e) {
    $erreur = "Erreur lecture config : " . $e->getMessage();
}

// Ollama n'a pas besoin de clé — on vérifie juste si le provider actif en a une
$activeApiKey    = $currentProvider === 'ollama' ? 'ok' : ($apiKeys[$currentProvider] ?? '');
$noApiKeyWarning = !$activeApiKey;

// Message après redirection
if (isset($_GET['saved'])) $msg = "Configuration sauvegardée avec succès";

// ── POST : Sauvegarder la configuration ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    $newProvider = trim($_POST['ai_provider'] ?? 'anthropic');
    $newModel    = trim($_POST['ai_model']    ?? '');

    if (!array_key_exists($newProvider, $providers)) {
        $erreur    = "Fournisseur IA invalide";
        $activeTab = 'config';
    } else {
        $allModelsForProv = $providers[$newProvider]['models'];
        if (!array_key_exists($newModel, $allModelsForProv)) {
            $newModel = array_key_first($allModelsForProv);
        }
        try {
            foreach (['anthropic', 'openai', 'google'] as $prov) {
                $keyValue = trim($_POST[$prov . '_api_key_input'] ?? '');
                if ($keyValue !== '') {
                    $dbKey = $prov . '_api_key';
                    $pdo->prepare("INSERT INTO config (cle, valeur) VALUES (?,?) ON DUPLICATE KEY UPDATE valeur=?")
                        ->execute([$dbKey, $keyValue, $keyValue]);
                    $apiKeys[$prov] = $keyValue;
                }
            }
            // Ollama : sauvegarder l'URL de base (pas de clé API)
            $ollamaUrl = trim($_POST['ollama_base_url_input'] ?? '');
            if ($ollamaUrl !== '') {
                $pdo->prepare("INSERT INTO config (cle, valeur) VALUES (?,?) ON DUPLICATE KEY UPDATE valeur=?")
                    ->execute(['ollama_base_url', $ollamaUrl, $ollamaUrl]);
            }
            foreach (['ai_provider' => $newProvider, 'ai_model' => $newModel] as $k => $v) {
                $pdo->prepare("INSERT INTO config (cle, valeur) VALUES (?,?) ON DUPLICATE KEY UPDATE valeur=?")
                    ->execute([$k, $v, $v]);
            }
            header("Location: generate.php?tab=config&saved=1");
            exit;
        } catch (Exception $e) {
            $erreur    = "Erreur sauvegarde : " . $e->getMessage();
            $activeTab = 'config';
        }
    }
}

// ── POST : Sauvegarder les questions générées ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_questions'])) {
    $activeTab     = 'generate';
    $questionsJson = $_POST['questions_json'] ?? '';
    $moduleIdCible = (int)($_POST['module_id_save'] ?? 0);
    if ($questionsJson && $moduleIdCible > 0) {
        $questions = json_decode($questionsJson, true);
        if ($questions) {
            $nb    = sauvegarderQuestionsGenerees($questions, $moduleIdCible);
            $msg   = "$nb question(s) ajoutée(s) au module !";
        } else {
            $erreur = "Données JSON invalides.";
        }
    }
}

// ── POST : Générer les questions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $activeTab     = 'generate';
    $provider      = getAIProvider();
    $apiKey        = getAPIKeyForProvider($provider);
    $model         = getAIModel();
    $moduleIdCible = (int)($_POST['module_id'] ?? 0);
    $nbQuestions   = max(1, min(30, (int)($_POST['nb_questions'] ?? 10)));
    $typesRaw      = $_POST['types'] ?? ['qcm'];
    $types         = array_intersect((array)$typesRaw, ['qcm', 'vrai_faux', 'texte_libre']);
    if (empty($types)) $types = ['qcm'];
    $niveau  = in_array($_POST['niveau'] ?? 'Mix', ['Débutant', 'Confirmé', 'Mix']) ? $_POST['niveau'] : 'Mix';
    $noteMax = in_array((int)($_POST['note_max'] ?? 20), [20, 40]) ? (int)$_POST['note_max'] : 20;
    $prompt  = trim($_POST['prompt_sujet'] ?? '');

    $hasFile   = !empty($_FILES['document']['name']) && $_FILES['document']['error'] === UPLOAD_ERR_OK;
    $hasPrompt = $prompt !== '';

    // Ollama ne nécessite pas de clé API (serveur local)
    if (!$apiKey && $provider !== 'ollama') {
        $erreur    = "Clé API non configurée pour le fournisseur « " . ($providers[$provider]['label'] ?? $provider) . " ». Configurez-la dans l'onglet Configuration.";
        $activeTab = 'config';
    } elseif ($moduleIdCible <= 0) {
        $erreur = "Veuillez sélectionner un module cible.";
    } elseif (!$hasFile && !$hasPrompt) {
        $erreur = "Veuillez uploader un document ou saisir un sujet.";
    } else {
        $fileError = '';
        if ($hasFile) {
            $file    = $_FILES['document'];
            $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'docx', 'txt', 'md'];
            if (!in_array($ext, $allowed))        $fileError = "Format non supporté. Utilisez : PDF, DOCX, TXT ou MD.";
            elseif ($file['size'] > 20*1024*1024) $fileError = "Fichier trop volumineux (max 20 Mo).";
        }
        if ($fileError) {
            $erreur = $fileError;
        } else {
            try {
                $docContent = $hasFile
                    ? extractDocumentContent($_FILES['document']['tmp_name'], $_FILES['document']['type'], $_FILES['document']['name'])
                    : ['text' => null, 'is_pdf' => false, 'pdf_base64' => null];

                $questionsGenerees = genererQuestionsAvecClaude(
                    $docContent, $nbQuestions, $types, $niveau, $noteMax,
                    $apiKey, $prompt, $model, $provider
                );
                $msg = count($questionsGenerees) . " questions générées. Vérifiez et sauvegardez.";
            } catch (\Throwable $e) {
                $erreur = "Erreur : " . $e->getMessage();
            }
        }
    }
}

// ── Labels utiles ─────────────────────────────────────────────
$allProviderModels = array_merge(
    $providers['anthropic']['models'],
    $providers['openai']['models'],
    $providers['google']['models']
);
$providerLabel = $providers[$currentProvider]['label'] ?? $currentProvider;
$modelLabel    = $allProviderModels[$currentModel]     ?? $currentModel;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IA — Génération & Configuration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        /* ── Badges & états ── */
        .ai-badge {
            background: linear-gradient(135deg,#4f46e5,#7c3aed);
            color: white; padding: 3px 10px; border-radius: 20px;
            font-size: .75rem; font-weight: 600; letter-spacing: .04em;
        }

        /* ── Upload zone ── */
        .upload-zone {
            border: 2px dashed #d1d5db; border-radius: 12px;
            background: #f9fafb; cursor: pointer; transition: all .2s;
        }
        .upload-zone:hover, .upload-zone.dragover {
            border-color: #4f46e5; background: #eef2ff;
        }

        /* ── Preview questions ── */
        .question-preview-card { border-left: 4px solid #4f46e5; background: #fafafa; }
        .question-preview-card.vrai_faux   { border-color: #0891b2; }
        .question-preview-card.texte_libre { border-color: #d97706; }

        /* ── Spinner ── */
        .spinner-ai {
            width: 1.2rem; height: 1.2rem;
            border: 3px solid rgba(255,255,255,.4); border-top-color: white;
            border-radius: 50%; animation: spin .7s linear infinite;
            display: inline-block;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Badges difficulté ── */
        .diff-badge-debutant      { background: #d1fae5; color: #065f46; }
        .diff-badge-intermediaire { background: #fef3c7; color: #92400e; }
        .diff-badge-avance        { background: #fee2e2; color: #991b1b; }

        /* ── Provider cards ── */
        .provider-card {
            cursor: pointer; border: 2px solid transparent;
            transition: border-color .2s, box-shadow .2s;
        }
        .provider-card:hover  { border-color: #6c757d; }
        .provider-card.active { border-color: #0d6efd; box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }

        /* ── Sections config ── */
        .provider-section         { display: none; }
        .provider-section.active  { display: block; }

        /* ── Tabs personnalisés ── */
        .nav-tabs-ia .nav-link {
            color: #6c757d; border: none; border-bottom: 2px solid transparent;
            padding: .6rem 1.2rem; font-weight: 600;
        }
        .nav-tabs-ia .nav-link.active {
            color: #4f46e5; border-bottom-color: #4f46e5; background: transparent;
        }
        .nav-tabs-ia .nav-link:hover:not(.active) { color: #333; }
    </style>
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>

<div class="container-fluid py-4 px-4" style="max-width:1100px">

    <!-- ── En-tête ─────────────────────────────────────────── -->
    <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
        <h2 class="h4 fw-bold mb-0">
            <i class="bi bi-robot me-2 text-primary"></i>Intelligence Artificielle
        </h2>
        <span class="ai-badge">
            <i class="bi <?= htmlspecialchars($providers[$currentProvider]['icon'] ?? 'bi-cpu') ?> me-1"></i>
            <?= htmlspecialchars($providerLabel . ' — ' . $modelLabel) ?>
        </span>
        <?php if (!$activeApiKey): ?>
        <span class="badge bg-warning text-dark">
            <i class="bi bi-exclamation-triangle me-1"></i>Clé API manquante
        </span>
        <?php endif; ?>
    </div>

    <!-- ── Onglets ──────────────────────────────────────────── -->
    <ul class="nav nav-tabs-ia border-bottom mb-4" id="iaTabs">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'generate' ? 'active' : '' ?>"
               href="?tab=generate">
                <i class="bi bi-stars me-2"></i>Génération de quiz
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'config' ? 'active' : '' ?>"
               href="?tab=config">
                <i class="bi bi-sliders me-2"></i>Configuration
                <?php if (!$activeApiKey): ?>
                <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem">!</span>
                <?php endif; ?>
            </a>
        </li>
    </ul>

    <!-- ── Alertes globales ──────────────────────────────────── -->
    <?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show rounded-3">
        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($erreur): ?>
    <div class="alert alert-danger alert-dismissible fade show rounded-3">
        <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($erreur) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════════════════
         ONGLET : GÉNÉRATION
    ════════════════════════════════════════════════════════ -->
    <div <?= $activeTab !== 'generate' ? 'style="display:none"' : '' ?> id="tabGenerate">

    <?php if ($noApiKeyWarning): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-4 rounded-3" role="alert">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div>Aucune clé API configurée pour le fournisseur actuel.
            <a href="?tab=config" class="alert-link ms-1">Configurer une clé API →</a>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- ── Panneau gauche : formulaire ─────────────────── -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3 px-4">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-sliders me-2 text-primary"></i>Paramètres de génération
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" enctype="multipart/form-data" id="generateForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="generate" value="1">

                        <!-- Upload document -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-file-earmark-text me-1 text-primary"></i>Document de cours
                                <span class="text-muted fw-normal small">(optionnel si sujet saisi)</span>
                            </label>
                            <div class="upload-zone p-4 text-center" id="dropZone"
                                 onclick="document.getElementById('fileInput').click()">
                                <i class="bi bi-cloud-upload fs-2 text-primary mb-2 d-block"></i>
                                <div class="fw-semibold" id="fileLabel">Cliquez ou glissez votre fichier ici</div>
                                <div class="text-muted small mt-1">
                                    PDF, DOCX, TXT — max 20 Mo
                                    <?php if ($currentProvider !== 'anthropic'): ?>
                                    <br><span class="text-warning"><i class="bi bi-info-circle me-1"></i>PDF natif = Anthropic uniquement</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <input type="file" name="document" id="fileInput" class="d-none"
                                   accept=".pdf,.docx,.txt,.md" onchange="updateFileLabel(this)">
                        </div>

                        <!-- Module cible -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-journal me-1 text-primary"></i>Module cible
                            </label>
                            <select name="module_id" class="form-select form-select-sm" required>
                                <option value="">— Choisir un module —</option>
                                <?php foreach ($allModules as $m): ?>
                                <option value="<?= $m['id'] ?>"
                                    <?= (isset($_POST['module_id']) && $_POST['module_id'] == $m['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['nom']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <a href="modules.php" class="text-muted small d-block mt-1">
                                <i class="bi bi-plus-circle me-1"></i>Créer un nouveau module
                            </a>
                        </div>

                        <!-- Prompt / Sujet -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-chat-left-text me-1 text-primary"></i>Sujet / Instructions
                            </label>
                            <textarea name="prompt_sujet" class="form-control form-control-sm" rows="3"
                                placeholder="Ex : Concentre-toi sur TCP/IP, évite la couche physique…"><?= htmlspecialchars($_POST['prompt_sujet'] ?? '') ?></textarea>
                            <div class="text-muted small mt-1">
                                <i class="bi bi-info-circle me-1"></i>Facultatif — précisez thèmes à cibler ou exclure.
                            </div>
                        </div>

                        <!-- Nombre de questions -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-123 me-1 text-primary"></i>Nombre de questions
                            </label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="range" id="nbSlider" class="form-range flex-grow-1"
                                       min="3" max="30" step="1"
                                       value="<?= (int)($_POST['nb_questions'] ?? 10) ?>"
                                       oninput="syncNb(this.value)" onchange="syncNb(this.value)">
                                <input type="number" name="nb_questions" id="nbInput"
                                       class="form-control form-control-sm text-center fw-bold"
                                       style="width:60px" min="3" max="30" step="1"
                                       value="<?= (int)($_POST['nb_questions'] ?? 10) ?>"
                                       oninput="syncNb(this.value, true)">
                            </div>
                        </div>

                        <!-- Notation -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-trophy me-1 text-primary"></i>Notation
                            </label>
                            <div class="d-flex gap-3">
                                <?php foreach ([20, 40] as $n): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="note_max"
                                           id="note<?= $n ?>" value="<?= $n ?>"
                                           <?= (($_POST['note_max'] ?? 20) == $n) ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="note<?= $n ?>">
                                        Sur <?= $n ?> pts
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Types de questions -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-list-check me-1 text-primary"></i>Types de questions
                            </label>
                            <?php
                            $selectedTypes = (array)($_POST['types'] ?? ['qcm']);
                            $typeOpts = [
                                'qcm'         => ['label' => 'QCM (4 choix)',    'icon' => 'ui-radios'],
                                'vrai_faux'   => ['label' => 'Vrai / Faux',      'icon' => 'toggle-on'],
                                'texte_libre' => ['label' => 'Réponse libre',    'icon' => 'pencil-square'],
                            ];
                            foreach ($typeOpts as $val => $opt): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="types[]" value="<?= $val ?>" id="type_<?= $val ?>"
                                       <?= in_array($val, $selectedTypes) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="type_<?= $val ?>">
                                    <i class="bi bi-<?= $opt['icon'] ?> me-1 text-muted"></i><?= $opt['label'] ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Niveau -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-bar-chart-steps me-1 text-primary"></i>Niveau
                            </label>
                            <select name="niveau" class="form-select form-select-sm">
                                <?php foreach (['Mix', 'Débutant', 'Confirmé'] as $niv): ?>
                                <option <?= (($_POST['niveau'] ?? 'Mix') === $niv) ? 'selected' : '' ?>>
                                    <?= $niv ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" name="generate" id="generateBtn"
                                class="btn btn-primary w-100 fw-semibold py-2"
                                <?= !$activeApiKey ? 'disabled title="Configurez d\'abord votre clé API"' : '' ?>>
                            <span id="btnNormal">
                                <i class="bi bi-stars me-2"></i>Générer avec l'IA
                            </span>
                            <span id="btnLoading" class="d-none">
                                <span class="spinner-ai me-2"></span>Génération en cours…
                            </span>
                        </button>
                        <?php if (!$activeApiKey): ?>
                        <div class="text-center mt-2">
                            <a href="?tab=config" class="small text-warning">
                                <i class="bi bi-gear me-1"></i>Configurez votre clé API d'abord
                            </a>
                        </div>
                        <?php endif; ?>

                    </form>
                </div>
            </div>
        </div>

        <!-- ── Panneau droit : prévisualisation ────────────── -->
        <div class="col-lg-8">

            <?php if ($questionsGenerees): ?>
            <form method="POST" id="saveForm">
                <?= csrfField() ?>
                <input type="hidden" name="questions_json"
                       value="<?= htmlspecialchars(json_encode($questionsGenerees)) ?>">
                <input type="hidden" name="module_id_save" value="<?= $moduleIdCible ?>">

                <div class="card border-0 shadow-sm rounded-4 mb-3">
                    <div class="card-body p-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div>
                            <h5 class="fw-bold mb-1">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                <?= count($questionsGenerees) ?> questions générées
                            </h5>
                            <div class="text-muted small">
                                Module : <strong><?= htmlspecialchars(getModule($moduleIdCible)['nom'] ?? 'N/A') ?></strong>
                                — Total : <strong><?= array_sum(array_column($questionsGenerees, 'points')) ?> pts</strong>
                            </div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="?tab=generate" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise me-1"></i>Regénérer
                            </a>
                            <button type="submit" name="save_questions" class="btn btn-success fw-semibold">
                                <i class="bi bi-cloud-check me-2"></i>Sauvegarder dans le module
                            </button>
                        </div>
                    </div>
                </div>

                <?php foreach ($questionsGenerees as $idx => $q): ?>
                <div class="card border-0 shadow-sm rounded-4 mb-3 question-preview-card <?= $q['type'] ?>">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-start gap-3 mb-2">
                            <div class="question-number"><?= $idx + 1 ?></div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                                    <p class="fw-semibold mb-1"><?= htmlspecialchars($q['texte']) ?></p>
                                    <div class="d-flex gap-1 flex-shrink-0">
                                        <?php $typeLabels = ['qcm'=>'QCM','vrai_faux'=>'V/F','texte_libre'=>'Libre']; ?>
                                        <span class="badge bg-light text-muted border small">
                                            <?= $typeLabels[$q['type']] ?? $q['type'] ?>
                                        </span>
                                        <span class="badge bg-primary-subtle text-primary small">
                                            <?= $q['points'] ?> pt<?= $q['points'] > 1 ? 's' : '' ?>
                                        </span>
                                        <?php if (!empty($q['difficulte'])): ?>
                                        <span class="badge diff-badge-<?= $q['difficulte'] ?> small">
                                            <?= ucfirst($q['difficulte']) ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($q['choix'])): ?>
                        <div class="ps-5">
                            <?php foreach ($q['choix'] as $c): ?>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <?php if ($c['is_correct']): ?>
                                    <i class="bi bi-check-circle-fill text-success flex-shrink-0"></i>
                                <?php else: ?>
                                    <i class="bi bi-circle text-muted flex-shrink-0"></i>
                                <?php endif; ?>
                                <span class="<?= $c['is_correct'] ? 'fw-semibold text-success' : 'text-muted' ?>">
                                    <?= htmlspecialchars($c['texte']) ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($q['corrige'])): ?>
                        <div class="ps-5 mt-2">
                            <div class="alert alert-info py-2 px-3 mb-0 small rounded-3">
                                <i class="bi bi-lightbulb me-1"></i>
                                <strong>Corrigé :</strong> <?= htmlspecialchars($q['corrige']) ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body p-4 text-center">
                        <button type="submit" name="save_questions" class="btn btn-success btn-lg fw-semibold px-5">
                            <i class="bi bi-cloud-check me-2"></i>Sauvegarder les <?= count($questionsGenerees) ?> questions
                        </button>
                    </div>
                </div>
            </form>

            <?php else: ?>
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-5 d-flex flex-column align-items-center justify-content-center text-center"
                     style="min-height:400px">
                    <div style="font-size:4rem; line-height:1" class="mb-4">🤖</div>
                    <h4 class="fw-bold mb-2">Génération automatique de quiz</h4>
                    <p class="text-muted mb-4" style="max-width:420px">
                        Uploadez un support de cours, configurez les paramètres, et l'IA génère
                        automatiquement un quiz complet prêt à l'emploi.
                    </p>
                    <div class="row g-3 text-start w-100" style="max-width:420px">
                        <?php foreach ([
                            ['bi-file-earmark-arrow-up', 'Uploadez votre support de cours'],
                            ['bi-chat-left-text',        'Rédigez un prompt pour guider l\'IA (facultatif)'],
                            ['bi-sliders',               'Choisissez nombre, types et notation'],
                            ['bi-stars',                 'L\'IA analyse et génère les questions'],
                            ['bi-cloud-check',           'Vérifiez et sauvegardez en un clic'],
                        ] as $i => [$icon, $label]): ?>
                        <div class="col-12">
                            <div class="d-flex align-items-center gap-3">
                                <div class="question-number flex-shrink-0"><?= $i+1 ?></div>
                                <div class="text-muted small"><?= $label ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    </div><!-- /tabGenerate -->

    <!-- ════════════════════════════════════════════════════════
         ONGLET : CONFIGURATION
    ════════════════════════════════════════════════════════ -->
    <div <?= $activeTab !== 'config' ? 'style="display:none"' : '' ?> id="tabConfig"
         style="max-width:700px; margin:0 auto">

        <form method="POST" id="iaForm">
            <?= csrfField() ?>
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
                                <?php if ($provId === 'ollama'): ?>
                                <span class="badge bg-success mt-2">
                                    <i class="bi bi-house-fill me-1"></i>Local · Gratuit
                                </span>
                                <?php elseif ($apiKeys[$provId]): ?>
                                <span class="badge bg-success mt-2">
                                    <i class="bi bi-check-circle me-1"></i>Clé configurée
                                </span>
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
            <div class="provider-section <?= $currentProvider === $provId ? 'active' : '' ?>"
                 data-section="<?= $provId ?>">
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 py-3 px-4 d-flex align-items-center gap-2">
                        <h6 class="mb-0 fw-bold flex-grow-1">
                            <i class="bi <?= $prov['icon'] ?> me-2 text-<?= $prov['color'] ?>"></i>
                            <?= htmlspecialchars($prov['label']) ?>
                        </h6>
                        <?php if (!empty($prov['local'])): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                            <i class="bi bi-house-fill me-1"></i>100% local · Gratuit
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body px-4 pb-4 pt-2">

                        <?php if ($provId === 'ollama'): ?>
                        <!-- Ollama : URL au lieu de clé API -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-link-45deg me-2 text-secondary"></i>URL du serveur Ollama
                            </label>
                            <input type="text" name="ollama_base_url_input"
                                   class="form-control form-control-lg font-monospace"
                                   placeholder="http://ollama:11434"
                                   value="<?= htmlspecialchars(getOllamaBaseUrl()) ?>">
                            <small class="text-muted d-block mt-2"><?= $prov['key_help'] ?></small>
                        </div>
                        <div class="alert alert-secondary py-2 px-3 rounded-3 mb-3">
                            <small><i class="bi bi-info-circle me-1"></i>
                                Ollama doit tourner dans Docker : <code>docker compose up -d ollama</code><br>
                                Puis télécharger le modèle : <code>docker exec eval_ollama ollama pull llama3.2</code>
                            </small>
                        </div>
                        <?php else: ?>
                        <!-- Clé API cloud -->
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
                        <?php endif; ?>

                        <!-- Modèle -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-stars me-2 text-info"></i>Modèle
                            </label>
                            <select class="form-select form-select-lg model-select"
                                    data-provider="<?= $provId ?>">
                                <?php foreach ($prov['models'] as $modelId => $modelLbl): ?>
                                <option value="<?= $modelId ?>"
                                    <?= ($currentProvider === $provId && $currentModel === $modelId) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($modelLbl) ?>
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

        <!-- Configuration active -->
        <div class="card border-0 shadow-sm rounded-4 mt-4">
            <div class="card-header bg-light border-0 py-3 px-4">
                <h6 class="mb-0 fw-bold">
                    <i class="bi bi-info-circle me-2 text-info"></i>Configuration Active
                </h6>
            </div>
            <div class="card-body px-4 py-3">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Fournisseur :</dt>
                    <dd class="col-sm-8">
                        <span class="badge bg-primary"><?= htmlspecialchars($providerLabel) ?></span>
                    </dd>
                    <dt class="col-sm-4 mt-3">Modèle :</dt>
                    <dd class="col-sm-8 mt-3"><code><?= htmlspecialchars($currentModel) ?></code></dd>
                    <dt class="col-sm-4 mt-3">Clé API :</dt>
                    <dd class="col-sm-8 mt-3">
                        <?php if ($activeApiKey): ?>
                        <span class="badge bg-success">
                            <i class="bi bi-check-circle me-1"></i>Configurée
                        </span>
                        <small class="text-muted ms-2">
                            <?= htmlspecialchars(substr($activeApiKey, 0, 8)) ?>...<?= htmlspecialchars(substr($activeApiKey, -6)) ?>
                        </small>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark">
                            <i class="bi bi-exclamation-triangle me-1"></i>Non configurée
                        </span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>

    </div><!-- /tabConfig -->

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Drag & Drop upload zone ──────────────────────────────────
const dropZone  = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
if (dropZone) {
    ['dragenter','dragover'].forEach(e =>
        dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.add('dragover'); }));
    ['dragleave','drop'].forEach(e =>
        dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.remove('dragover'); }));
    dropZone.addEventListener('drop', ev => {
        const files = ev.dataTransfer.files;
        if (files.length) { fileInput.files = files; updateFileLabel(fileInput); }
    });
}

function updateFileLabel(input) {
    const label = document.getElementById('fileLabel');
    if (input.files && input.files[0]) {
        const f = input.files[0];
        const size = (f.size / 1024 / 1024).toFixed(2);
        label.innerHTML = `<i class="bi bi-file-earmark-check text-success me-1"></i>
                           <strong>${f.name}</strong> (${size} Mo)`;
        dropZone.classList.add('border-success');
    }
}

// ── Slider ↔ input nombre de questions ───────────────────────
function syncNb(val, fromInput) {
    val = Math.max(3, Math.min(30, parseInt(val) || 3));
    document.getElementById('nbSlider').value = val;
    document.getElementById('nbInput').value  = val;
}

// ── Loading state bouton Générer ─────────────────────────────
const genForm = document.getElementById('generateForm');
if (genForm) {
    genForm.addEventListener('submit', function(e) {
        const types = this.querySelectorAll('input[name="types[]"]:checked');
        if (types.length === 0) {
            e.preventDefault();
            alert('Veuillez sélectionner au moins un type de question.');
            return;
        }
        document.getElementById('btnNormal').classList.add('d-none');
        document.getElementById('btnLoading').classList.remove('d-none');
        document.getElementById('generateBtn').disabled = true;
    });
}

// ── Provider cards (onglet Config) ───────────────────────────
(function () {
    const cards    = document.querySelectorAll('.provider-card');
    const sections = document.querySelectorAll('.provider-section');
    const hiddenProv  = document.getElementById('hiddenProvider');
    const hiddenModel = document.getElementById('hiddenModel');
    if (!hiddenProv) return;

    function activate(provId) {
        cards.forEach(c => c.classList.toggle('active', c.dataset.provider === provId));
        sections.forEach(s => s.classList.toggle('active', s.dataset.section === provId));
        hiddenProv.value = provId;
        const sel = document.querySelector(`.model-select[data-provider="${provId}"]`);
        if (sel) hiddenModel.value = sel.value;
    }

    cards.forEach(card => card.addEventListener('click', () => activate(card.dataset.provider)));

    document.querySelectorAll('.model-select').forEach(sel => {
        sel.addEventListener('change', () => {
            if (hiddenProv.value === sel.dataset.provider) hiddenModel.value = sel.value;
        });
    });
})();
</script>
</body>
</html>
