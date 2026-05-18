<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$allModules = getAllModules();

$provider = getAIProvider();
$apiKey   = getAPIKeyForProvider($provider);
$noProvider = (!$apiKey && $provider !== 'ollama');

$csrfToken = csrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IA — Pile de questions</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .ai-badge {
            background: linear-gradient(135deg,#4f46e5,#7c3aed);
            color: white; padding: 3px 10px; border-radius: 20px;
            font-size: .75rem; font-weight: 600; letter-spacing: .04em;
        }
        .module-check-card {
            cursor: pointer; border: 2px solid #e5e7eb;
            border-radius: 10px; transition: border-color .15s, background .15s;
        }
        .module-check-card:hover { border-color: #6366f1; background: #f5f3ff; }
        .module-check-card.selected { border-color: #4f46e5; background: #eef2ff; }
        .question-result-card {
            border-left: 4px solid #4f46e5;
            background: #fafafa;
            border-radius: 8px;
        }
        .question-result-card.nouvelle { border-left-color: #10b981; }
        .badge-existante { background: #dbeafe; color: #1e40af; }
        .badge-nouvelle  { background: #d1fae5; color: #065f46; }
        .spinner-ai {
            width: 1.2rem; height: 1.2rem;
            border: 3px solid rgba(255,255,255,.4); border-top-color: white;
            border-radius: 50%; animation: spin .7s linear infinite;
            display: inline-block;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        #resultZone { display: none; }
        #loadingZone { display: none; }
    </style>
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>

<div class="container-fluid py-4 px-4" style="max-width:1200px">

    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <h2 class="h4 fw-bold mb-0">
            <i class="bi bi-layers me-2 text-primary"></i>Pile de questions IA
        </h2>
        <span class="ai-badge">
            <i class="bi bi-robot me-1"></i>
            <?= sanitize($provider) ?>
        </span>
        <a href="generate.php" class="btn btn-sm btn-outline-secondary ms-auto">
            <i class="bi bi-arrow-left me-1"></i>Retour IA
        </a>
    </div>

    <?php if ($noProvider): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-4 rounded-3">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div>Aucune clé API configurée pour le fournisseur actuel.
            <a href="generate.php?tab=config" class="alert-link ms-1">Configurer →</a>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- ── Panneau gauche : paramètres ─────────────────── -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3 px-4">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-sliders me-2 text-primary"></i>Paramètres
                    </h5>
                </div>
                <div class="card-body p-4">

                    <!-- Modules sources -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-journal-text me-1 text-primary"></i>Modules sources
                            <span class="text-muted fw-normal small">(base de questions disponibles)</span>
                        </label>
                        <?php if (empty($allModules)): ?>
                        <p class="text-muted small">Aucun module disponible.</p>
                        <?php else: ?>
                        <div class="d-flex flex-column gap-2" id="moduleList">
                            <?php foreach ($allModules as $m): ?>
                            <label class="module-check-card p-3 d-flex align-items-center gap-3 mb-0">
                                <input type="checkbox" class="form-check-input flex-shrink-0 module-checkbox"
                                       name="module_ids[]" value="<?= (int)$m['id'] ?>">
                                <div class="flex-grow-1 overflow-hidden">
                                    <div class="fw-semibold text-truncate small"><?= sanitize($m['nom']) ?></div>
                                    <div class="text-muted" style="font-size:.75rem">
                                        <?= (int)$m['nb_questions'] ?> question<?= $m['nb_questions'] != 1 ? 's' : '' ?>
                                    </div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-link btn-sm p-0 mt-2 text-muted" id="toggleAll">
                            Tout sélectionner
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Demande libre -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-chat-left-text me-1 text-primary"></i>Demande libre
                        </label>
                        <textarea id="demande" class="form-control form-control-sm" rows="4"
                            placeholder="Ex : Génère-moi 15 questions sur la sécurité réseau, niveau avancé, en évitant les doublons avec M205"></textarea>
                    </div>

                    <!-- Nombre de questions -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-123 me-1 text-primary"></i>Nombre de questions souhaité
                        </label>
                        <input type="number" id="nbQuestions" class="form-control form-control-sm"
                               min="1" max="100" value="20">
                    </div>

                    <!-- Mode -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-toggles me-1 text-primary"></i>Mode
                        </label>
                        <div class="d-flex flex-column gap-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="mode" id="modeSelection"
                                       value="selection" checked>
                                <label class="form-check-label" for="modeSelection">
                                    <strong>Sélection depuis la base</strong>
                                    <div class="text-muted small">L'IA choisit parmi les questions existantes</div>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="mode" id="modeGeneration"
                                       value="generation">
                                <label class="form-check-label" for="modeGeneration">
                                    <strong>Génération de nouvelles questions</strong>
                                    <div class="text-muted small">L'IA crée des questions originales</div>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="mode" id="modeMixte"
                                       value="mixte">
                                <label class="form-check-label" for="modeMixte">
                                    <strong>Mixte (sélection + génération)</strong>
                                    <div class="text-muted small">Combine questions existantes et nouvelles</div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <button type="button" id="btnGenerer" class="btn btn-primary w-100 fw-semibold py-2"
                            <?= $noProvider ? 'disabled' : '' ?>>
                        <span id="btnNormal">
                            <i class="bi bi-stars me-2"></i>Générer la pile
                        </span>
                        <span id="btnLoading" class="d-none">
                            <span class="spinner-ai me-2"></span>Génération en cours…
                        </span>
                    </button>

                </div>
            </div>
        </div>

        <!-- ── Panneau droit : résultats ────────────────────── -->
        <div class="col-lg-8">

            <!-- Zone de chargement -->
            <div id="loadingZone" class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-5 text-center">
                    <div class="spinner-border text-primary mb-3" role="status"></div>
                    <p class="text-muted mb-0">L'IA analyse votre demande et compose la pile…</p>
                </div>
            </div>

            <!-- Zone d'erreur -->
            <div id="errorZone" class="alert alert-danger d-flex gap-2 rounded-3" style="display:none!important">
                <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0"></i>
                <div id="errorMsg"></div>
            </div>

            <!-- Zone de résultats -->
            <div id="resultZone">
                <div class="card border-0 shadow-sm rounded-4 mb-3">
                    <div class="card-body p-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div>
                            <h5 class="fw-bold mb-1">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                <span id="resultCount">0</span> questions dans la pile
                            </h5>
                            <div id="resultStats" class="text-muted small"></div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" id="btnRetry" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise me-1"></i>Réessayer
                            </button>
                            <button type="button" id="btnCreateModule" class="btn btn-success fw-semibold">
                                <i class="bi bi-plus-circle me-2"></i>Créer un module depuis cette pile
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Explication IA -->
                <div class="card border-0 shadow-sm rounded-4 mb-3">
                    <div class="card-body p-4">
                        <h6 class="fw-bold mb-2">
                            <i class="bi bi-chat-quote me-2 text-primary"></i>Justification de l'IA
                        </h6>
                        <p id="resultExplication" class="text-muted mb-0 fst-italic small"></p>
                    </div>
                </div>

                <!-- Liste des questions -->
                <div id="questionsList"></div>

                <!-- Formulaire caché pour créer le module -->
                <form method="POST" action="fusion.php" id="formCreateModule" style="display:none">
                    <?= csrfField() ?>
                    <input type="hidden" name="from_ia_pile" value="1">
                    <input type="hidden" name="questions_pile_json" id="questionsPileJson">
                </form>
            </div>

            <!-- État initial -->
            <div id="emptyState" class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-5 d-flex flex-column align-items-center justify-content-center text-center"
                     style="min-height:400px">
                    <i class="bi bi-layers fs-1 text-primary mb-3"></i>
                    <h4 class="fw-bold mb-2">Composez votre pile de questions</h4>
                    <p class="text-muted mb-4" style="max-width:420px">
                        Sélectionnez des modules sources, décrivez votre besoin en langage naturel
                        et l'IA compose une pile de questions sur mesure.
                    </p>
                    <div class="row g-3 text-start w-100" style="max-width:420px">
                        <?php foreach ([
                            ['bi-journal-check', 'Sélectionnez les modules sources'],
                            ['bi-chat-left-text', 'Décrivez votre demande en langage naturel'],
                            ['bi-toggles',        'Choisissez le mode (sélection / génération / mixte)'],
                            ['bi-stars',          'L\'IA compose la pile adaptée'],
                            ['bi-plus-circle',    'Créez un module directement depuis la pile'],
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

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

// ── Module checkboxes ─────────────────────────────────────────
document.querySelectorAll('.module-check-card').forEach(label => {
    const cb = label.querySelector('input[type="checkbox"]');
    cb.addEventListener('change', () => {
        label.classList.toggle('selected', cb.checked);
    });
});

const toggleAllBtn = document.getElementById('toggleAll');
if (toggleAllBtn) {
    let allSelected = false;
    toggleAllBtn.addEventListener('click', () => {
        allSelected = !allSelected;
        document.querySelectorAll('.module-checkbox').forEach(cb => {
            cb.checked = allSelected;
            cb.closest('.module-check-card').classList.toggle('selected', allSelected);
        });
        toggleAllBtn.textContent = allSelected ? 'Tout désélectionner' : 'Tout sélectionner';
    });
}

// ── Générer ───────────────────────────────────────────────────
document.getElementById('btnGenerer').addEventListener('click', generer);
document.getElementById('btnRetry').addEventListener('click', generer);

function generer() {
    const moduleIds = [...document.querySelectorAll('.module-checkbox:checked')].map(c => c.value);
    const demande   = document.getElementById('demande').value.trim();
    const nb        = parseInt(document.getElementById('nbQuestions').value) || 20;
    const mode      = document.querySelector('input[name="mode"]:checked')?.value || 'selection';

    showLoading(true);
    hideError();
    hideResult();

    const data = new FormData();
    data.append('csrf_token', CSRF_TOKEN);
    moduleIds.forEach(id => data.append('module_ids[]', id));
    data.append('demande', demande);
    data.append('nb_questions', nb);
    data.append('mode', mode);

    fetch('api_ia_pile.php', { method: 'POST', body: data })
        .then(r => r.json())
        .then(json => {
            showLoading(false);
            if (!json.success) {
                showError(json.error || 'Erreur inconnue');
                return;
            }
            renderResult(json);
        })
        .catch(err => {
            showLoading(false);
            showError('Erreur réseau : ' + err.message);
        });
}

function renderResult(json) {
    const questions = json.questions || [];
    document.getElementById('resultCount').textContent = questions.length;

    const nbEx  = questions.filter(q => q.source === 'existante').length;
    const nbNew = questions.filter(q => q.source === 'nouvelle').length;
    let stats = [];
    if (nbEx)  stats.push(`${nbEx} existante${nbEx>1?'s':''}`);
    if (nbNew) stats.push(`${nbNew} nouvelle${nbNew>1?'s':''}`);
    document.getElementById('resultStats').textContent = stats.join(' · ');
    document.getElementById('resultExplication').textContent = json.explication || '';

    const list = document.getElementById('questionsList');
    list.innerHTML = '';

    questions.forEach((q, idx) => {
        const isNew = q.source === 'nouvelle';
        const card  = document.createElement('div');
        card.className = 'card border-0 shadow-sm rounded-4 mb-3 question-result-card' + (isNew ? ' nouvelle' : '');

        const choixHtml = (q.choix || []).map(c => `
            <div class="d-flex align-items-start gap-2 mb-1">
                <i class="bi bi-${c.is_correct ? 'check-circle-fill text-success' : 'circle text-muted'} flex-shrink-0 mt-1" style="font-size:.85rem"></i>
                <span class="${c.is_correct ? 'fw-semibold text-success' : 'text-muted'} small">${escHtml(c.texte)}</span>
            </div>
        `).join('');

        card.innerHTML = `
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3 mb-2">
                    <div class="question-number flex-shrink-0">${idx+1}</div>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-1">
                            <p class="fw-semibold mb-0">${escHtml(q.texte)}</p>
                            <div class="d-flex gap-1 flex-shrink-0">
                                <span class="badge ${isNew ? 'badge-nouvelle' : 'badge-existante'} small">
                                    ${isNew ? 'Nouvelle' : 'Existante'}
                                </span>
                                <span class="badge bg-light text-muted border small">${escHtml(q.type || 'qcm')}</span>
                                <span class="badge bg-primary-subtle text-primary small">${q.points||1} pt${q.points>1?'s':''}</span>
                            </div>
                        </div>
                        ${choixHtml ? `<div class="mt-2">${choixHtml}</div>` : ''}
                    </div>
                </div>
            </div>`;
        list.appendChild(card);
    });

    document.getElementById('questionsPileJson').value = JSON.stringify(questions);
    document.getElementById('resultZone').style.display = 'block';
    document.getElementById('emptyState').style.display = 'none';
}

document.getElementById('btnCreateModule').addEventListener('click', () => {
    document.getElementById('formCreateModule').submit();
});

// ── Helpers ───────────────────────────────────────────────────
function showLoading(on) {
    document.getElementById('loadingZone').style.display = on ? 'block' : 'none';
    document.getElementById('emptyState').style.display  = on ? 'none'  : 'block';
    const btn = document.getElementById('btnGenerer');
    document.getElementById('btnNormal').classList.toggle('d-none', on);
    document.getElementById('btnLoading').classList.toggle('d-none', !on);
    btn.disabled = on;
    if (on) document.getElementById('resultZone').style.display = 'none';
}

function showError(msg) {
    const z = document.getElementById('errorZone');
    document.getElementById('errorMsg').textContent = msg;
    z.style.cssText = '';
    document.getElementById('emptyState').style.display = 'none';
}

function hideError() {
    document.getElementById('errorZone').style.cssText = 'display:none!important';
}

function hideResult() {
    document.getElementById('resultZone').style.display = 'none';
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
