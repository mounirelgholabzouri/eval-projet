<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$modules = $pdo->query("SELECT id, nom, (SELECT COUNT(*) FROM questions q WHERE q.module_id=modules.id) AS nb_q FROM modules ORDER BY nom")->fetchAll();
$groupes = $pdo->query("SELECT * FROM groupes ORDER BY nom")->fetchAll();

$qcmModules = $modules;
$efmModules = $modules;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agent IA — Génération d'évaluations</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .step-card { border: 2px solid #e5e7eb; border-radius: 12px; padding: 24px; margin-bottom: 20px; transition: border-color .2s; }
        .step-card.active { border-color: #6366f1; }
        .preview-q { border-left: 3px solid #6366f1; padding: 8px 12px; margin: 6px 0; background: #f8f9ff; border-radius: 0 6px 6px 0; font-size: 13px; }
        .preview-partie { font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: .5px; color: #6366f1; margin-top: 14px; margin-bottom: 4px; }
        .badge-type { font-size: 10px; padding: 2px 6px; border-radius: 4px; }
        .spinner-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 9999; align-items: center; justify-content: center; flex-direction: column; color: #fff; }
        .spinner-overlay.show { display: flex; }
    </style>
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>

<div class="spinner-overlay" id="spinnerOverlay">
    <div class="spinner-border text-light mb-3" style="width:3rem;height:3rem;"></div>
    <div id="spinnerMsg" class="fw-semibold fs-5">L'agent IA analyse les questions…</div>
    <div class="text-white-50 small mt-1">Cela peut prendre 15 à 30 secondes</div>
</div>

<div class="container-fluid py-4 px-4" style="max-width: 1100px;">
    <h2 class="h4 fw-bold mb-1">
        <i class="bi bi-robot me-2 text-primary"></i>Agent IA — Génération d'évaluations
    </h2>
    <p class="text-muted mb-4 small">Sélectionne automatiquement les meilleures questions selon vos critères, en évitant les répétitions des 30 derniers jours.</p>

    <!-- ONGLETS TYPE -->
    <ul class="nav nav-pills mb-4" id="typeTabs">
        <li class="nav-item">
            <button class="nav-link active" onclick="switchType('continue')">
                <i class="bi bi-clipboard-check me-1"></i>Évaluation Continue
            </button>
        </li>
        <li class="nav-item ms-2">
            <button class="nav-link" onclick="switchType('efm')">
                <i class="bi bi-award me-1"></i>EFM (multi-modules)
            </button>
        </li>
    </ul>

    <div class="row g-4">
        <!-- COLONNE GAUCHE : Paramètres -->
        <div class="col-lg-4">

            <!-- === FORMULAIRE CONTINUE === -->
            <div id="formContinue" class="step-card active">
                <h6 class="fw-bold mb-3"><i class="bi bi-sliders me-2 text-primary"></i>Paramètres</h6>

                <div class="mb-3">
                    <label class="form-label fw-semibold small">Module <span class="text-danger">*</span></label>
                    <select id="c_module" class="form-select form-select-sm">
                        <option value="">— Choisir —</option>
                        <?php foreach ($qcmModules as $m): ?>
                        <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['nom']) ?> (<?= $m['nb_q'] ?> Q)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold small">Groupe / Niveau</label>
                    <select id="c_groupe" class="form-select form-select-sm">
                        <option value="0">— Tous les groupes —</option>
                        <?php foreach ($groupes as $g): ?>
                        <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold small">Nb questions</label>
                        <input type="number" id="c_nb" class="form-control form-control-sm" value="20" min="5" max="60">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold small">Durée (min)</label>
                        <input type="number" id="c_duree" class="form-control form-control-sm" value="30" min="10" max="180">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold small">Note maximale</label>
                    <select id="c_note" class="form-select form-select-sm">
                        <option value="20">20</option>
                        <option value="40">40</option>
                    </select>
                </div>

                <button class="btn btn-primary w-100" onclick="generer('continue')">
                    <i class="bi bi-robot me-2"></i>Générer avec l'IA
                </button>
            </div>

            <!-- === FORMULAIRE EFM === -->
            <div id="formEfm" class="step-card" style="display:none;">
                <h6 class="fw-bold mb-3"><i class="bi bi-sliders me-2 text-primary"></i>Paramètres EFM</h6>

                <div class="mb-3">
                    <label class="form-label fw-semibold small">Modules sources <span class="text-danger">*</span></label>
                    <div style="max-height: 180px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 6px; padding: 8px;">
                        <?php foreach ($efmModules as $m): ?>
                        <div class="form-check">
                            <input class="form-check-input efm-module-cb" type="checkbox" value="<?= $m['id'] ?>" id="em_<?= $m['id'] ?>">
                            <label class="form-check-label small" for="em_<?= $m['id'] ?>">
                                <?= htmlspecialchars($m['nom']) ?> <span class="text-muted">(<?= $m['nb_q'] ?> Q)</span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold small">Groupe / Niveau</label>
                    <select id="e_groupe" class="form-select form-select-sm">
                        <option value="0">— Tous les groupes —</option>
                        <?php foreach ($groupes as $g): ?>
                        <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold small">Nb questions total</label>
                    <input type="number" id="e_nb" class="form-control form-control-sm" value="40" min="10" max="100">
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label fw-semibold small">Code module</label>
                        <input type="text" id="e_code" class="form-control form-control-sm" placeholder="M205">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold small">Filière</label>
                        <input type="text" id="e_filiere" class="form-control form-control-sm" placeholder="IDOCC">
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold small">Établissement</label>
                    <input type="text" id="e_etab" class="form-control form-control-sm" placeholder="ISTA...">
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold small">Année scolaire</label>
                    <input type="text" id="e_annee" class="form-control form-control-sm" value="<?= getAnneeFormation() ?>">
                </div>

                <button class="btn btn-primary w-100" onclick="generer('efm')">
                    <i class="bi bi-robot me-2"></i>Générer avec l'IA
                </button>
            </div>
        </div>

        <!-- COLONNE DROITE : Prévisualisation -->
        <div class="col-lg-8">
            <div class="step-card h-100" id="previewCard">
                <div id="previewEmpty" class="text-center text-muted py-5">
                    <i class="bi bi-robot" style="font-size: 48px; opacity: .2;"></i>
                    <p class="mt-3">Configurez les paramètres et cliquez sur <strong>Générer</strong>.</p>
                    <p class="small">L'agent IA analysera toutes les questions disponibles et proposera une évaluation optimisée.</p>
                </div>

                <div id="previewContent" style="display:none;">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h5 class="fw-bold mb-1" id="prevTitre"></h5>
                            <div class="text-muted small" id="prevMeta"></div>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-secondary" onclick="regenerer()">
                                <i class="bi bi-arrow-clockwise me-1"></i>Régénérer
                            </button>
                            <button class="btn btn-sm btn-success" onclick="validerEval()">
                                <i class="bi bi-check-lg me-1"></i>Valider &amp; Enregistrer
                            </button>
                        </div>
                    </div>

                    <div class="alert alert-info small py-2" id="prevConsignes"></div>

                    <div id="prevQuestions"></div>

                    <div class="alert alert-warning small mt-3 py-2" id="prevAvertissement" style="display:none;">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <span id="prevAvertissementMsg"></span>
                    </div>
                </div>

                <div id="previewSuccess" style="display:none;" class="text-center py-5">
                    <i class="bi bi-check-circle-fill text-success" style="font-size: 48px;"></i>
                    <h5 class="mt-3 fw-bold" id="successMsg"></h5>
                    <div id="successLinks" class="mt-3 d-flex gap-2 justify-content-center"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentType  = 'continue';
let lastPayload  = null;
let lastResponse = null;

function switchType(type) {
    currentType = type;
    document.getElementById('formContinue').style.display = type === 'continue' ? 'block' : 'none';
    document.getElementById('formEfm').style.display      = type === 'efm'      ? 'block' : 'none';
    document.querySelectorAll('#typeTabs .nav-link').forEach((el, i) => {
        el.classList.toggle('active', (i === 0 && type === 'continue') || (i === 1 && type === 'efm'));
    });
    resetPreview();
}

function resetPreview() {
    document.getElementById('previewEmpty').style.display   = 'block';
    document.getElementById('previewContent').style.display = 'none';
    document.getElementById('previewSuccess').style.display = 'none';
}

function showSpinner(msg) {
    document.getElementById('spinnerMsg').textContent = msg || 'L\'agent IA analyse les questions…';
    document.getElementById('spinnerOverlay').classList.add('show');
}
function hideSpinner() {
    document.getElementById('spinnerOverlay').classList.remove('show');
}

function buildPayload(type) {
    if (type === 'continue') {
        return {
            type:        'continue',
            module_id:   document.getElementById('c_module').value,
            groupe_id:   document.getElementById('c_groupe').value,
            nb_questions: document.getElementById('c_nb').value,
            duree_min:   document.getElementById('c_duree').value,
            note_max:    document.getElementById('c_note').value,
        };
    } else {
        const moduleIds = [...document.querySelectorAll('.efm-module-cb:checked')].map(cb => cb.value);
        return {
            type:          'efm',
            module_ids:    moduleIds,
            groupe_id:     document.getElementById('e_groupe').value,
            nb_questions:  document.getElementById('e_nb').value,
            code_module:   document.getElementById('e_code').value,
            filiere:       document.getElementById('e_filiere').value,
            etablissement: document.getElementById('e_etab').value,
            annee:         document.getElementById('e_annee').value,
        };
    }
}

async function generer(type) {
    lastPayload = buildPayload(type);

    if (type === 'continue' && !lastPayload.module_id) {
        alert('Sélectionnez un module.');
        return;
    }
    if (type === 'efm' && lastPayload.module_ids.length === 0) {
        alert('Sélectionnez au moins un module.');
        return;
    }

    showSpinner('L\'agent IA analyse ' + (type === 'efm' ? 'les modules sélectionnés' : 'le module') + '…');

    try {
        const fd = new FormData();
        Object.entries(lastPayload).forEach(([k, v]) => {
            if (Array.isArray(v)) v.forEach(val => fd.append(k + '[]', val));
            else fd.append(k, v);
        });

        const resp = await fetch('api_agent_eval.php', { method: 'POST', body: fd });
        lastResponse = await resp.json();
        hideSpinner();

        if (lastResponse.error) {
            alert('Erreur : ' + lastResponse.error);
            return;
        }
        renderPreview(lastResponse);
    } catch (e) {
        hideSpinner();
        alert('Erreur réseau : ' + e.message);
    }
}

function regenerer() { generer(currentType); }

function renderPreview(data) {
    document.getElementById('previewEmpty').style.display   = 'none';
    document.getElementById('previewContent').style.display = 'block';
    document.getElementById('previewSuccess').style.display = 'none';

    document.getElementById('prevTitre').textContent = data.titre || '—';

    if (data.type === 'continue') {
        document.getElementById('prevMeta').textContent =
            (data.nb_questions || 0) + ' questions · ' + (data.duree_min || '—') + ' min · /' + (data.note_max || 20);
    } else {
        const total = (data.parties || []).reduce((s, p) => s + (p.questions || []).length, 0);
        document.getElementById('prevMeta').textContent =
            total + ' questions · ' + (data.parties || []).length + ' partie(s) · EFM';
    }

    document.getElementById('prevConsignes').textContent = data.consignes || '';

    const container = document.getElementById('prevQuestions');
    container.innerHTML = '';

    const typeBadge = t => {
        const map = { qcm: 'bg-primary', vrai_faux: 'bg-success', texte_libre: 'bg-warning text-dark', multiple: 'bg-info' };
        return `<span class="badge badge-type ${map[t] || 'bg-secondary'}">${t}</span>`;
    };

    if (data.type === 'continue') {
        (data.questions || []).forEach((q, i) => {
            container.innerHTML += `
                <div class="preview-q">
                    <span class="text-muted me-2">${i + 1}.</span>
                    ${escHtml(q.texte_court || q.texte || '—')}
                    <span class="ms-2">${typeBadge(q.type)}</span>
                    <span class="text-muted ms-1 small">${q.points} pt</span>
                </div>`;
        });
    } else {
        (data.parties || []).forEach(p => {
            container.innerHTML += `<div class="preview-partie"><i class="bi bi-folder me-1"></i>${escHtml(p.titre)}</div>`;
            (p.questions || []).forEach((q, i) => {
                container.innerHTML += `
                    <div class="preview-q">
                        <span class="text-muted me-2">${i + 1}.</span>
                        ${escHtml(q.texte_court || q.texte || '—')}
                        <span class="ms-2">${typeBadge(q.type)}</span>
                        <span class="text-muted ms-1 small">${q.points} pt</span>
                    </div>`;
            });
        });
    }
}

async function validerEval() {
    if (!lastResponse) return;
    showSpinner('Enregistrement en base de données…');

    try {
        const resp = await fetch('api_agent_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(lastResponse),
        });
        const result = await resp.json();
        hideSpinner();

        if (result.error) {
            alert('Erreur : ' + result.error);
            return;
        }

        document.getElementById('previewContent').style.display = 'none';
        document.getElementById('previewSuccess').style.display = 'block';
        document.getElementById('successMsg').textContent = result.message;

        const links = document.getElementById('successLinks');
        links.innerHTML = '';
        if (result.type === 'efm') {
            links.innerHTML = `<a href="modules.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-box me-1"></i>Voir les modules</a>
                               <a href="efm.php" class="btn btn-sm btn-primary"><i class="bi bi-award me-1"></i>Gérer l'EFM</a>`;
        } else {
            links.innerHTML = `<a href="results.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-table me-1"></i>Voir les résultats</a>
                               <a href="agent_eval.php" class="btn btn-sm btn-primary"><i class="bi bi-robot me-1"></i>Nouvelle évaluation</a>`;
        }
    } catch (e) {
        hideSpinner();
        alert('Erreur réseau : ' + e.message);
    }
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
