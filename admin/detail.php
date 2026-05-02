<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: results.php'); exit; }

$session  = getSession($id);
if (!$session) { header('Location: results.php'); exit; }

$reponses = getReponsesSession($id);
$mention  = getMention((float)$session['pourcentage']);
$groupe   = $session['groupe_nom'] ?: $session['groupe_libre'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Détail — <?= htmlspecialchars($session['prenom'] . ' ' . $session['nom']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container py-4" style="max-width:900px">
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="results.php" class="btn btn-sm btn-outline-secondary rounded-3">
            <i class="bi bi-arrow-left"></i>
        </a>
        <h2 class="h4 fw-bold mb-0">
            Résultat de <?= htmlspecialchars($session['prenom'] . ' ' . $session['nom']) ?>
        </h2>
        <div class="ms-auto d-flex gap-2 flex-wrap">
            <?php
            $hasTexteLibre = false;
            foreach ($reponses as $r) {
                if ($r['type'] === 'texte_libre' && !empty($r['reponse_texte'])) { $hasTexteLibre = true; break; }
            }
            ?>
            <?php if ($hasTexteLibre): ?>
            <button type="button" id="btn-correct-all" class="btn btn-sm btn-info rounded-3"
                    onclick="corrigerToutAvecIA(<?= $id ?>)">
                <i class="bi bi-robot me-1"></i>Corriger tout avec IA
            </button>
            <button type="button" id="btn-validate-all" class="btn btn-sm btn-success rounded-3"
                    onclick="validerToutesLesNotes(<?= $id ?>)" style="display:none;">
                <i class="bi bi-check-all me-1"></i>Valider toutes les notes
            </button>
            <?php endif; ?>
            <a href="print_exams.php?session_id=<?= $id ?>" target="_blank"
               class="btn btn-sm btn-dark rounded-3">
                <i class="bi bi-printer me-1"></i>Imprimer le test
            </a>
            <?php if (($session['module_type'] ?? '') === 'efm'): ?>
            <a href="print_efm_result.php?session_id=<?= $id ?>" target="_blank"
               class="btn btn-sm btn-danger rounded-3">
                <i class="bi bi-file-earmark-ruled me-1"></i>Impression EFM
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Carte résumé -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
        <div class="card-header bg-<?= $mention['class'] ?> text-white py-3 px-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-1 fw-bold"><?= htmlspecialchars($session['prenom'] . ' ' . $session['nom']) ?></h5>
                    <div class="opacity-75 small">
                        <?= htmlspecialchars($groupe ?: '—') ?> — <?= htmlspecialchars($session['module_nom']) ?>
                    </div>
                </div>
                <div class="text-end">
                    <div class="h3 fw-bold mb-0">
                        <?= number_format($session['score'], 1) ?> / <?= number_format($session['total_points'], 1) ?>
                    </div>
                    <div class="h5 opacity-75 mb-0"><?= number_format($session['pourcentage'], 1) ?>% — <?= $mention['label'] ?></div>
                </div>
            </div>
        </div>
        <div class="card-body px-4 py-3">
            <div class="row g-2 text-muted small">
                <div class="col-auto"><i class="bi bi-calendar me-1"></i><?= date('d/m/Y H:i', strtotime($session['date_debut'])) ?></div>
                <?php if ($session['date_fin']): ?>
                <div class="col-auto"><i class="bi bi-clock me-1"></i>
                    Durée : <?= round((strtotime($session['date_fin']) - strtotime($session['date_debut'])) / 60) ?> min
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Détail réponses -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-0 py-3 px-4">
            <h5 class="mb-0 fw-bold"><i class="bi bi-list-check me-2 text-primary"></i>Détail des réponses</h5>
        </div>
        <div class="card-body p-0">
            <?php foreach ($reponses as $idx => $r): ?>
            <div class="px-4 py-3 <?= $idx > 0 ? 'border-top' : '' ?> d-flex align-items-start gap-3
                         <?= $r['type'] === 'texte_libre' ? 'border-start border-4 border-warning' : ($r['is_correct'] ? 'border-start border-4 border-success' : 'border-start border-4 border-danger') ?>">

                <div class="flex-shrink-0 mt-1">
                    <?php if ($r['type'] === 'texte_libre'): ?>
                        <span class="badge bg-warning text-dark"><i class="bi bi-pencil"></i></span>
                    <?php elseif ($r['is_correct']): ?>
                        <span class="badge bg-success"><i class="bi bi-check-lg"></i></span>
                    <?php else: ?>
                        <span class="badge bg-danger"><i class="bi bi-x-lg"></i></span>
                    <?php endif; ?>
                </div>
                <div class="flex-grow-1">
                    <div class="fw-semibold mb-1">Q<?= $idx + 1 ?>. <?= htmlspecialchars($r['question_texte']) ?></div>
                    <?php if ($r['type'] === 'texte_libre'): ?>
                        <div class="small text-muted">
                            <?php if ($r['reponse_texte']): ?>
                                <div class="fst-italic mb-2">"<?= htmlspecialchars($r['reponse_texte']) ?>"</div>

                                <!-- Section Correction IA -->
                                <?php
                                $niveauLabels = [0=>'Nul', 1=>'Insuffisant', 2=>'Partiel', 3=>'Correct'];
                                $niveauCls    = [0=>'dark', 1=>'danger', 2=>'warning', 3=>'success'];
                                $n     = isset($r['correction_ia_niveau']) && $r['correction_ia_niveau'] !== null
                                       ? (int)$r['correction_ia_niveau'] : -1;
                                ?>
                                <div class="p-2 rounded border border-info bg-white mb-2" id="ia-section-<?= $r['id'] ?>">
                                    <?php if ($r['correction_ia_feedback']): ?>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <small class="fw-semibold text-info"><i class="bi bi-robot"></i> Correction IA</small>
                                            <?php if ($n >= 0): ?>
                                            <span class="badge bg-<?= $niveauCls[$n] ?> rounded-pill">
                                                Niveau <?= $n ?> — <?= $niveauLabels[$n] ?>
                                            </span>
                                            <?php endif; ?>
                                            <small class="text-muted ms-auto"><?= date('d/m/Y H:i', strtotime($r['correction_ia_date'])) ?></small>
                                        </div>
                                        <small class="text-muted d-block mb-1"><?= htmlspecialchars($r['correction_ia_feedback']) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted fst-italic">Pas encore corrigé par IA.</small>
                                    <?php endif; ?>
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-info"
                                                onclick="corrigerAvecIA(<?= $r['id'] ?>, <?= $id ?>, this)">
                                            <i class="bi bi-arrow-repeat me-1"></i><?= $r['correction_ia_feedback'] ? 'Recorriger' : 'Corriger' ?> avec IA
                                        </button>
                                    </div>
                                </div>

                                <!-- Note manuelle -->
                                <div class="d-flex align-items-center gap-2 flex-wrap" id="note-section-<?= $r['id'] ?>">
                                    <input type="number" step="0.5" min="0" max="<?= $r['points_max'] ?>"
                                           id="points-input-<?= $r['id'] ?>"
                                           class="form-control form-control-sm" style="width:80px"
                                           value="<?= number_format($r['points_obtenus'], 1) ?>">
                                    <span class="text-muted">/ <?= number_format($r['points_max'], 1) ?> pt(s)</span>
                                    <button type="button" class="btn btn-sm btn-warning"
                                            onclick="validerNoteManuelle(<?= $r['id'] ?>, <?= $id ?>, this)">
                                        <i class="bi bi-check me-1"></i>Valider note
                                    </button>
                                    <span id="note-status-<?= $r['id'] ?>" class="small"></span>
                                </div>
                            <?php else: ?>
                                <span class="text-danger fst-italic">Aucune réponse saisie</span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="small">
                            Réponse : <strong><?= htmlspecialchars($r['choix_texte'] ?? 'Aucune réponse') ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="flex-shrink-0 text-end">
                    <span class="fw-bold <?= $r['is_correct'] ? 'text-success' : ($r['type'] === 'texte_libre' ? 'text-warning' : 'text-danger') ?>">
                        <?= number_format($r['points_obtenus'], 1) ?>
                    </span>
                    <span class="text-muted small"> / <?= number_format($r['points_max'], 1) ?> pt(s)</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const NIVEAU_INFO = {
    0: { label: 'Nul',         cls: 'dark' },
    1: { label: 'Insuffisant', cls: 'danger' },
    2: { label: 'Partiel',     cls: 'warning' },
    3: { label: 'Correct',     cls: 'success' },
};

function renderIASection(repId, sessionId, data) {
    const ni  = NIVEAU_INFO[data.niveau] ?? { label: '?', cls: 'secondary' };
    const now = new Date().toLocaleString('fr-FR', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
    document.getElementById('ia-section-' + repId).innerHTML = `
        <div class="d-flex align-items-center gap-2 mb-1">
            <small class="fw-semibold text-info"><i class="bi bi-robot"></i> Correction IA</small>
            <span class="badge bg-${ni.cls} rounded-pill">Niveau ${data.niveau} — ${ni.label}</span>
            <small class="text-muted ms-auto">${now}</small>
        </div>
        <small class="text-muted d-block mb-1">${data.feedback}</small>
        <div class="mt-2">
            <button type="button" class="btn btn-sm btn-outline-info"
                    onclick="corrigerAvecIA(${repId}, ${sessionId}, this)">
                <i class="bi bi-arrow-repeat me-1"></i>Recorriger avec IA
            </button>
        </div>
    `;

    // Sync input note + affichage score
    const input = document.getElementById('points-input-' + repId);
    if (input) {
        input.value = parseFloat(data.points).toFixed(1);
        input.style.outline = '2px solid #0dcaf0';
        setTimeout(() => input.style.outline = '', 2000);
    }
    updateScoreDisplay(data.score, data.total, data.pct);
}

function updateScoreDisplay(score, total, pct) {
    const scoreEl = document.querySelector('.h3.fw-bold.mb-0');
    const pctEl   = document.querySelector('.h5.opacity-75.mb-0');
    if (scoreEl && score !== undefined) {
        scoreEl.textContent = parseFloat(score).toFixed(1) + ' / ' + parseFloat(total).toFixed(1);
    }
    if (pctEl && pct !== undefined) {
        const mentions = [[90,'Excellent'],[75,'Très bien'],[60,'Bien'],[50,'Passable'],[0,'Insuffisant']];
        const label = mentions.find(([s]) => pct >= s)?.[1] ?? 'Insuffisant';
        pctEl.textContent = parseFloat(pct).toFixed(1) + '% — ' + label;
    }
}

async function corrigerAvecIA(repId, sessionId, btnElement) {
    const btn = btnElement;
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Correction...';

    try {
        const res  = await fetch('api_correct_ia.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'rep_id=' + repId + '&session_id=' + sessionId
        });
        const data = await res.json();

        if (!data.success) {
            alert('Erreur : ' + data.error);
            btn.innerHTML = orig;
            btn.disabled = false;
            return;
        }

        renderIASection(repId, sessionId, data);

    } catch (err) {
        alert('Erreur réseau : ' + err.message);
        btn.innerHTML = orig;
        btn.disabled = false;
    }
}

async function validerNoteManuelle(repId, sessionId, btnElement) {
    const btn    = btnElement;
    const input  = document.getElementById('points-input-' + repId);
    const status = document.getElementById('note-status-' + repId);
    const points = parseFloat(input.value);

    if (isNaN(points) || points < 0) { alert('Note invalide'); return; }

    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>';

    try {
        const res  = await fetch('api_correct_manual.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `rep_id=${repId}&session_id=${sessionId}&points=${points}`
        });
        const data = await res.json();

        if (!data.success) {
            alert('Erreur : ' + data.error);
            btn.innerHTML = orig;
            btn.disabled = false;
            return;
        }

        btn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Validé';
        btn.classList.replace('btn-warning', 'btn-success');
        status.innerHTML = `<span class="text-success">${parseFloat(data.points).toFixed(1)} / ${parseFloat(data.points_max).toFixed(1)} pt(s) enregistré</span>`;
        updateScoreDisplay(data.score, data.total, data.pct);

        setTimeout(() => {
            btn.innerHTML = orig;
            btn.classList.replace('btn-success', 'btn-warning');
            btn.disabled = false;
            status.innerHTML = '';
        }, 3000);

    } catch (err) {
        alert('Erreur réseau : ' + err.message);
        btn.innerHTML = orig;
        btn.disabled = false;
    }
}

async function corrigerToutAvecIA(sessionId) {
    const btn  = document.getElementById('btn-correct-all');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Correction en cours...';

    try {
        const res  = await fetch('api_correct_ia_all.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'session_id=' + sessionId
        });
        const data = await res.json();

        if (!data.success) {
            alert('Erreur : ' + data.error);
            btn.innerHTML = orig;
            btn.disabled = false;
            return;
        }

        data.resultats.forEach(r => renderIASection(r.rep_id, sessionId, r));
        updateScoreDisplay(data.score, data.total, data.pct);

        btn.innerHTML = `<i class="bi bi-check-circle me-1"></i>${data.corriges} corrigée(s)`;
        btn.classList.replace('btn-info', 'btn-success');

        // Afficher le bouton "Valider toutes les notes"
        const btnValidateAll = document.getElementById('btn-validate-all');
        if (btnValidateAll) {
            btnValidateAll.style.display = 'inline-block';
        }

        if (data.erreurs.length) alert('⚠ ' + data.erreurs.join('\n'));

    } catch (err) {
        alert('Erreur réseau : ' + err.message);
        btn.innerHTML = orig;
        btn.disabled = false;
    }
}

async function validerToutesLesNotes(sessionId) {
    const btn  = document.getElementById('btn-validate-all');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Validation en cours...';

    try {
        const res  = await fetch('api_validate_all_notes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'session_id=' + sessionId
        });
        const data = await res.json();

        if (!data.success) {
            alert('Erreur : ' + data.error);
            btn.innerHTML = orig;
            btn.disabled = false;
            return;
        }

        updateScoreDisplay(data.score, data.total, data.pct);

        btn.innerHTML = `<i class="bi bi-check-circle-fill me-1"></i>${data.validees} validée(s)`;

        setTimeout(() => {
            btn.innerHTML = orig;
            btn.disabled = false;
        }, 3000);

    } catch (err) {
        alert('Erreur réseau : ' + err.message);
        btn.innerHTML = orig;
        btn.disabled = false;
    }
}
</script>
</body>
</html>
