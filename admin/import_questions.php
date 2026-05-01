<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$msg = '';
$msgType = 'success';
$moduleId = (int)($_GET['module_id'] ?? 0);
$allModules = getAllModules();

// Traitement import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    $moduleId = (int)($_POST['module_id'] ?? 0);

    if ($moduleId <= 0) {
        $msg = "Sélectionnez un module.";
        $msgType = 'danger';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = "Erreur d'upload de fichier.";
        $msgType = 'danger';
    } else {
        $tmpFile = $file['tmp_name'];
        $fileName = $file['name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        try {
            $questions = [];

            // Debug: vérifier le fichier temporaire
            if (!file_exists($tmpFile)) {
                $msg = "Erreur : fichier temporaire non trouvé.";
                $msgType = 'danger';
            } else {
                // Déterminer le format et parser
                if ($ext === 'xlsx' || $ext === 'csv') {
                    $questions = parseExcelFile($tmpFile);
                } elseif ($ext === 'docx') {
                    $questions = parseDocxFile($tmpFile);
                } elseif ($ext === 'pdf') {
                    $questions = parsePdfFile($tmpFile);
                } elseif ($ext === 'md') {
                    // Pour Markdown, lire le contenu directement
                    $content = file_get_contents($tmpFile);
                    if ($content === false) {
                        $msg = "Erreur : impossible de lire le fichier Markdown.";
                        $msgType = 'danger';
                    } else {
                        $questions = extractQuestionsFromMarkdown($content);
                    }
                } else {
                    $msg = "Format non supporté. Utilisez : Excel, DOCX, PDF ou Markdown.";
                    $msgType = 'danger';
                }

                if (!isset($msg) && !empty($questions)) {
                    $result = importQuestionsToModule($questions, $moduleId);
                    $msg = "{$result['imported']} question(s) importée(s).";
                    if (!empty($result['errors'])) {
                        $msg .= " " . count($result['errors']) . " erreur(s).";
                        $msgType = 'warning';
                    }
                } elseif (!isset($msg)) {
                    $msg = "Aucune question trouvée dans le fichier.";
                    $msgType = 'warning';
                }
            }
        } catch (Exception $e) {
            $msg = "Erreur : " . $e->getMessage();
            $msgType = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Importer des questions — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .format-info { background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px; padding: 15px; }
        .upload-zone { border: 2px dashed #dee2e6; border-radius: 8px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.3s; }
        .upload-zone:hover { border-color: #0d6efd; background: #f8f9fa; }
        .upload-zone.dragover { border-color: #0d6efd; background: #e7f1ff; }
    </style>
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>

<div class="container-fluid py-4 px-4">
    <h2 class="h4 fw-bold mb-4">
        <i class="bi bi-cloud-upload me-2 text-primary"></i>Importer des questions
    </h2>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> alert-dismissible fade show rounded-3">
            <i class="bi bi-<?= $msgType === 'success' ? 'check-circle' : ($msgType === 'danger' ? 'exclamation-circle' : 'info-circle') ?> me-2"></i>
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Formulaire import -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3 px-4">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-upload me-2"></i>Télécharger un fichier
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Module cible <span class="text-danger">*</span></label>
                            <select name="module_id" class="form-select" required>
                                <option value="">— Choisir un module —</option>
                                <?php foreach ($allModules as $m): ?>
                                <option value="<?= $m['id'] ?>" <?= $m['id'] == $moduleId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['nom']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">Fichier <span class="text-danger">*</span></label>
                            <div class="upload-zone" onclick="document.getElementById('fileInput').click()" id="uploadZone">
                                <i class="bi bi-cloud-arrow-up" style="font-size: 32px; color: #0d6efd; display: block; margin-bottom: 10px;"></i>
                                <p class="mb-1"><strong>Cliquez pour télécharger</strong></p>
                                <p class="text-muted small mb-0">ou glissez votre fichier</p>
                            </div>
                            <input type="file" id="fileInput" name="import_file" class="form-control mt-3"
                                   accept=".xlsx,.csv,.docx,.pdf,.md" required style="display: none;">
                            <small class="text-muted d-block mt-2">
                                Formats acceptés : Excel (.xlsx, .csv), Word (.docx), PDF, Markdown (.md)
                            </small>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-cloud-upload me-1"></i>Importer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Guide des formats -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3 px-4">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-info-circle me-2"></i>Formats acceptés
                    </h5>
                </div>
                <div class="card-body p-4">
                    <!-- Excel -->
                    <div class="format-info mb-3">
                        <h6 class="fw-bold mb-2"><i class="bi bi-file-earmark-spreadsheet me-2 text-success"></i>Excel (.xlsx, .csv)</h6>
                        <p class="small mb-2">Format recommandé pour les données structurées.</p>
                        <div class="small text-muted">
                            <p class="mb-1"><strong>Colonnes :</strong></p>
                            <ul class="mb-2" style="padding-left: 20px;">
                                <li>texte (obligatoire)</li>
                                <li>type (qcm, vrai_faux, texte_libre)</li>
                                <li>points</li>
                                <li>choix_1, choix_2, etc.</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Word -->
                    <div class="format-info mb-3">
                        <h6 class="fw-bold mb-2"><i class="bi bi-file-earmark-word me-2 text-primary"></i>Word (.docx)</h6>
                        <p class="small mb-2">Détecte les questions et réponses automatiquement.</p>
                        <div class="small text-muted">
                            Format : <br>
                            <code>1) Question</code><br>
                            <code>a) Réponse 1</code><br>
                            <code>*b) Réponse correcte</code>
                        </div>
                    </div>

                    <!-- PDF -->
                    <div class="format-info mb-3">
                        <h6 class="fw-bold mb-2"><i class="bi bi-file-earmark-pdf me-2 text-danger"></i>PDF</h6>
                        <p class="small mb-2">Extraction simple du texte.</p>
                        <div class="small text-muted">
                            Format similaire à Word : numéros suivis de questions.
                        </div>
                    </div>

                    <!-- Markdown -->
                    <div class="format-info">
                        <h6 class="fw-bold mb-2"><i class="bi bi-markdown me-2 text-secondary"></i>Markdown (.md)</h6>
                        <p class="small mb-2">Format texte avec support Markdown.</p>
                        <div class="small text-muted">
                            <code>## Question</code><br>
                            <code>- Réponse 1</code><br>
                            <code>- **Réponse correcte**</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Drag and drop
const uploadZone = document.getElementById('uploadZone');
const fileInput = document.getElementById('fileInput');

uploadZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    uploadZone.classList.add('dragover');
});

uploadZone.addEventListener('dragleave', () => {
    uploadZone.classList.remove('dragover');
});

uploadZone.addEventListener('drop', (e) => {
    e.preventDefault();
    uploadZone.classList.remove('dragover');
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        fileInput.files = files;
        updateFileName();
    }
});

fileInput.addEventListener('change', updateFileName);

function updateFileName() {
    const fileName = fileInput.files[0]?.name;
    if (fileName) {
        uploadZone.innerHTML = `<i class="bi bi-check-circle" style="font-size: 32px; color: #28a745; display: block; margin-bottom: 10px;"></i>
                                <p class="mb-1"><strong>${fileName}</strong></p>
                                <p class="text-muted small mb-0">Cliquez pour changer</p>`;
    }
}
</script>
</body>
</html>
