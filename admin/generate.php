<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/claude_generator.php';
require_once __DIR__ . '/../config/database.php';

$pdo = getDB();

// Récupérer la clé API stockée en DB (ou depuis la config)
function getApiKey(): string {
    try {
        $pdo  = getDB();
        $stmt = $pdo->query("SELECT valeur FROM config WHERE cle = 'anthropic_api_key' LIMIT 1");
        $row  = $stmt->fetch();
        return $row ? $row['valeur'] : '';
    } catch (\Throwable $e) {
        return defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    }
}

function saveApiKey(string $key): void {
    $pdo = getDB();
    $pdo->prepare("INSERT INTO config (cle, valeur) VALUES ('anthropic_api_key', ?)
                   ON DUPLICATE KEY UPDATE valeur = ?")
        ->execute([$key, $key]);
}

$erreur = '';
$succes = '';
$questionsGenerees = null;
$moduleIdCible = 0;

$allModules = getAllModules();

// ── Sauvegarde clé API ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_api_key'])) {
    $apiKey = trim($_POST['anthropic_api_key'] ?? '');
    if ($apiKey) {
        saveApiKey($apiKey);
        $succes = "Clé API sauvegardée.";
    }
}

// ── Sauvegarde des questions générées ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_questions'])) {
    $questionsJson = $_POST['questions_json'] ?? '';
    $moduleIdCible = (int)($_POST['module_id_save'] ?? 0);

    if ($questionsJson && $moduleIdCible > 0) {
        $questions = json_decode($questionsJson, true);
        if ($questions) {
            $nb = sauvegarderQuestionsGenerees($questions, $moduleIdCible);
            $succes = "$nb question(s) ajoutée(s) au module avec succès !";
        } else {
            $erreur = "Données JSON invalides.";
        }
    }
}

// ── Génération via Claude ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $apiKey      = trim($_POST['api_key_runtime'] ?? '') ?: getApiKey();
    $moduleIdCible = (int)($_POST['module_id'] ?? 0);
    $nbQuestions = max(1, min(30, (int)($_POST['nb_questions'] ?? 10)));
    $typesRaw    = $_POST['types'] ?? ['qcm'];
    $types       = array_intersect((array)$typesRaw, ['qcm', 'vrai_faux', 'texte_libre']);
    if (empty($types)) $types = ['qcm'];
    $niveau  = in_array($_POST['niveau'] ?? 'Mix', ['Débutant', 'Confirmé', 'Mix']) ? $_POST['niveau'] : 'Mix';
    $noteMax = in_array((int)($_POST['note_max'] ?? 20), [20, 40]) ? (int)$_POST['note_max'] : 20;
    $prompt  = trim($_POST['prompt_sujet'] ?? '');

    $hasFile   = !empty($_FILES['document']['name']) && $_FILES['document']['error'] === UPLOAD_ERR_OK;
    $hasPrompt = $prompt !== '';

    if (!$apiKey) {
        $erreur = "Veuillez saisir votre clé API Anthropic.";
    } elseif ($moduleIdCible <= 0) {
        $erreur = "Veuillez sélectionner un module cible.";
    } elseif (!$hasFile && !$hasPrompt) {
        $erreur = "Veuillez uploader un document ou saisir un sujet dans le champ Prompt.";
    } else {
        // Vérification du fichier si présent
        $fileError = '';
        if ($hasFile) {
            $file    = $_FILES['document'];
            $allowed = ['pdf', 'docx', 'txt', 'md'];
            $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) {
                $fileError = "Format non supporté. Utilisez : PDF, DOCX, TXT ou MD.";
            } elseif ($file['size'] > 20 * 1024 * 1024) {
                $fileError = "Fichier trop volumineux (max 20 Mo).";
            }
        }

        if ($fileError) {
            $erreur = $fileError;
        } else {
            try {
                // Contenu du document (null si prompt seul)
                $docContent = $hasFile
                    ? extractDocumentContent($_FILES['document']['tmp_name'], $_FILES['document']['type'])
                    : ['text' => null, 'is_pdf' => false, 'pdf_base64' => null];

                // Appel Claude
                $questionsGenerees = genererQuestionsAvecClaude(
                    $docContent,
                    $nbQuestions,
                    $types,
                    $niveau,
                    $noteMax,
                    $apiKey,
                    $prompt
                );

                $succes = count($questionsGenerees) . " questions générées. Vérifiez et sauvegardez.";

                // Sauvegarder la clé si demandé
                if (!empty($_POST['remember_key'])) saveApiKey($apiKey);

            } catch (\Throwable $e) {
                $erreur = "Erreur : " . $e->getMessage();
            }
        }
    }
}

$apiKeySaved = getApiKey();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Génération IA — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .ai-badge {
            background: linear-gradient(135deg,#4f46e5,#7c3aed);
            color:white; padding:3px 10px; border-radius:20px;
            font-size:.75rem; font-weight:600; letter-spacing:.04em;
        }
        .upload-zone {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            background: #f9fafb;
            cursor: pointer;
            transition: all .2s;
        }
        .upload-zone:hover, .upload-zone.dragover {
            border-color: #4f46e5;
            background: #eef2ff;
        }
        .question-preview-card {
            border-left: 4px solid #4f46e5;
            background: #fafafa;
        }
        .question-preview-card.vrai_faux  { border-color: #0891b2; }
        .question-preview-card.texte_libre{ border-color: #d97706; }
        .spinner-ai {
            width: 1.2rem; height: 1.2rem;
            border: 3px solid rgba(255,255,255,.4);
            border-top-color: white;
            border-radius: 50%;
            animation: spin .7s linear infinite;
            display: inline-block;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .diff-badge-debutant     { background:#d1fae5; color:#065f46; }
        .diff-badge-intermediaire{ background:#fef3c7; color:#92400e; }
        .diff-badge-avance       { background:#fee2e2; color:#991b1b; }
    </style>
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>

<div class="container-fluid py-4 px-4" style="max-width:1100px">

    <!-- Titre -->
    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <h2 class="h4 fw-bold mb-0">
            <i class="bi bi-stars me-2 text-primary"></i>Génération de quiz par IA
        </h2>
        <span class="ai-badge">Claude Opus 4.6</span>
    </div>

    <!-- Alertes -->
    <?php if ($succes): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($succes) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($erreur): ?>
        <div class="alert alert-danger rounded-3">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($erreur) ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- ── Panneau de configuration ─────────────────── -->
        <div class="col-lg-4">

            <!-- Clé API -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3">
                        <i class="bi bi-key me-2 text-warning"></i>Clé API Anthropic
                    </h6>
                    <form method="POST">
                        <div class="input-group input-group-sm">
                            <input type="password" name="anthropic_api_key" class="form-control"
                                   placeholder="sk-ant-api..."
                                   value="<?= $apiKeySaved ? str_repeat('•', 20) : '' ?>">
                            <button type="submit" name="save_api_key" class="btn btn-warning btn-sm">
                                <i class="bi bi-save me-1"></i>Sauvegarder
                            </button>
                        </div>
                        <?php if ($apiKeySaved): ?>
                        <div class="text-success small mt-1">
                            <i class="bi bi-check-circle me-1"></i>Clé configurée
                        </div>
                        <?php else: ?>
                        <div class="text-muted small mt-1">
                            Obtenez votre clé sur
                            <a href="https://console.anthropic.com" target="_blank" rel="noopener">console.anthropic.com</a>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Formulaire de génération -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3 px-4">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-sliders me-2 text-primary"></i>Paramètres
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" enctype="multipart/form-data" id="generateForm">
                        <input type="hidden" name="generate" value="1">

                        <!-- Upload document -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-file-earmark-text me-1 text-primary"></i>Document de cours
                                <span class="text-muted fw-normal small">(optionnel si un sujet est saisi)</span>
                            </label>
                            <div class="upload-zone p-4 text-center" id="dropZone"
                                 onclick="document.getElementById('fileInput').click()">
                                <i class="bi bi-cloud-upload fs-2 text-primary mb-2 d-block"></i>
                                <div class="fw-semibold" id="fileLabel">Cliquez ou glissez votre fichier ici</div>
                                <div class="text-muted small mt-1">PDF, DOCX, TXT — max 20 Mo</div>
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
                            <div class="mt-1">
                                <a href="modules.php" class="text-muted small">
                                    <i class="bi bi-plus-circle me-1"></i>Créer un nouveau module
                                </a>
                            </div>
                        </div>

                        <!-- Prompt / Sujet -->
                        <div class="mb-3">
                            <label for="promptSujet" class="form-label fw-semibold">
                                <i class="bi bi-chat-left-text me-1 text-primary"></i>Sujet / Instructions pour Claude
                            </label>
                            <textarea name="prompt_sujet" id="promptSujet"
                                      class="form-control form-control-sm"
                                      rows="3"
                                      placeholder="Ex : Concentre-toi sur les notions de base des réseaux TCP/IP, évite les questions sur la couche physique…"><?= htmlspecialchars($_POST['prompt_sujet'] ?? '') ?></textarea>
                            <div class="text-muted small mt-1">
                                <i class="bi bi-info-circle me-1"></i>Facultatif — précisez le sujet, les thèmes à cibler ou à exclure.
                            </div>
                        </div>

                        <!-- Nombre de questions -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-123 me-1 text-primary"></i>Nombre de questions
                            </label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="range" name="nb_questions" id="nbSlider" class="form-range flex-grow-1"
                                       min="3" max="30" step="1"
                                       value="<?= (int)($_POST['nb_questions'] ?? 10) ?>"
                                       oninput="document.getElementById('nbVal').textContent=this.value">
                                <span class="badge bg-primary fs-6 px-3" id="nbVal">
                                    <?= (int)($_POST['nb_questions'] ?? 10) ?>
                                </span>
                            </div>
                        </div>

                        <!-- Notation -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-trophy me-1 text-primary"></i>Notation
                            </label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="note_max"
                                           id="note20" value="20"
                                           <?= (($_POST['note_max'] ?? 20) == 20) ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="note20">
                                        Sur 20 points
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="note_max"
                                           id="note40" value="40"
                                           <?= (($_POST['note_max'] ?? 20) == 40) ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="note40">
                                        Sur 40 points
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Types de questions -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-list-check me-1 text-primary"></i>Types de questions
                            </label>
                            <div class="d-flex flex-column gap-2">
                                <?php
                                $selectedTypes = $_POST['types'] ?? ['qcm'];
                                $typeOpts = [
                                    'qcm'         => ['label' => 'QCM (4 choix)', 'icon' => 'ui-radios'],
                                    'vrai_faux'   => ['label' => 'Vrai / Faux', 'icon' => 'toggle-on'],
                                    'texte_libre' => ['label' => 'Réponse libre', 'icon' => 'pencil-square'],
                                ];
                                foreach ($typeOpts as $val => $opt):
                                ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           name="types[]" value="<?= $val ?>"
                                           id="type_<?= $val ?>"
                                           <?= in_array($val, (array)$selectedTypes) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="type_<?= $val ?>">
                                        <i class="bi bi-<?= $opt['icon'] ?> me-1 text-muted"></i><?= $opt['label'] ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
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

                        <!-- Clé API à la volée (si non configurée) -->
                        <?php if (!$apiKeySaved): ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-muted">
                                Clé API (utilisation ponctuelle)
                            </label>
                            <input type="password" name="api_key_runtime" class="form-control form-control-sm"
                                   placeholder="sk-ant-api...">
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" name="remember_key" id="rememberKey">
                                <label class="form-check-label small" for="rememberKey">Mémoriser la clé</label>
                            </div>
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="api_key_runtime" value="">
                        <?php endif; ?>

                        <button type="submit" name="generate" id="generateBtn"
                                class="btn btn-primary w-100 fw-semibold py-2">
                            <span id="btnNormal">
                                <i class="bi bi-stars me-2"></i>Générer avec Claude
                            </span>
                            <span id="btnLoading" class="d-none">
                                <span class="spinner-ai me-2"></span>Génération en cours…
                            </span>
                        </button>

                    </form>
                </div>
            </div>
        </div>

        <!-- ── Prévisualisation des questions générées ───── -->
        <div class="col-lg-8">

            <?php if ($questionsGenerees): ?>
            <!-- Formulaire de sauvegarde -->
            <form method="POST" id="saveForm">
                <input type="hidden" name="questions_json" value="<?= htmlspecialchars(json_encode($questionsGenerees)) ?>">
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
                                — Total points :
                                <strong><?= array_sum(array_column($questionsGenerees, 'points')) ?></strong>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="generate.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise me-1"></i>Regénérer
                            </a>
                            <button type="submit" name="save_questions" class="btn btn-success fw-semibold">
                                <i class="bi bi-cloud-check me-2"></i>Sauvegarder dans le module
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Liste des questions prévisualisées -->
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

                <!-- Bouton sauvegarder bas de page -->
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body p-4 text-center">
                        <button type="submit" name="save_questions" class="btn btn-success btn-lg fw-semibold px-5">
                            <i class="bi bi-cloud-check me-2"></i>Sauvegarder les <?= count($questionsGenerees) ?> questions
                        </button>
                    </div>
                </div>

            </form>

            <?php else: ?>

            <!-- État initial — instructions -->
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-5 d-flex flex-column align-items-center justify-content-center text-center"
                     style="min-height:400px">
                    <div style="font-size:4rem; line-height:1" class="mb-4">🤖</div>
                    <h4 class="fw-bold mb-2">Génération automatique de quiz</h4>
                    <p class="text-muted mb-4" style="max-width:420px">
                        Uploadez un document de cours (PDF, DOCX ou TXT), configurez les paramètres,
                        et Claude Opus 4.6 génère automatiquement un quiz complet.
                    </p>
                    <div class="row g-3 text-start w-100" style="max-width:420px">
                        <?php $steps = [
                            ['bi-file-earmark-arrow-up','Uploadez votre support de cours'],
                            ['bi-chat-left-text','Rédigez un prompt pour guider Claude (facultatif)'],
                            ['bi-sliders','Choisissez le nombre, les types et la notation'],
                            ['bi-stars','Claude analyse et génère les questions'],
                            ['bi-cloud-check','Vérifiez et sauvegardez en un clic'],
                        ]; ?>
                        <?php foreach ($steps as $i => [$icon, $label]): ?>
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
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Drag & Drop upload zone ──────────────────────────────────
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');

['dragenter', 'dragover'].forEach(e =>
    dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.add('dragover'); }));
['dragleave', 'drop'].forEach(e =>
    dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.remove('dragover'); }));
dropZone.addEventListener('drop', ev => {
    const files = ev.dataTransfer.files;
    if (files.length) {
        fileInput.files = files;
        updateFileLabel(fileInput);
    }
});

function updateFileLabel(input) {
    const label = document.getElementById('fileLabel');
    if (input.files && input.files[0]) {
        const f    = input.files[0];
        const size = (f.size / 1024 / 1024).toFixed(2);
        label.innerHTML = `<i class="bi bi-file-earmark-check text-success me-1"></i>
                           <strong>${f.name}</strong> (${size} Mo)`;
        dropZone.classList.add('border-success');
    }
}

// ── Loading state du bouton générer ─────────────────────────
document.getElementById('generateForm').addEventListener('submit', function() {
    document.getElementById('btnNormal').classList.add('d-none');
    document.getElementById('btnLoading').classList.remove('d-none');
    document.getElementById('generateBtn').disabled = true;
});

// ── Au moins un type de question coché ───────────────────────
document.getElementById('generateForm').addEventListener('submit', function(e) {
    const types = this.querySelectorAll('input[name="types[]"]:checked');
    if (types.length === 0) {
        e.preventDefault();
        alert('Veuillez sélectionner au moins un type de question.');
    }
});
</script>
</body>
</html>
