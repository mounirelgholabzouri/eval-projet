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
        <div class="ms-auto d-flex gap-2">
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
                        <div class="small text-muted">Réponse libre :
                            <?php if ($r['reponse_texte']): ?>
                                <em>"<?= htmlspecialchars($r['reponse_texte']) ?>"</em>

                                <!-- Section Correction IA -->
                                <div class="mt-3 p-2 bg-light rounded border border-info" id="ia-section-<?= $r['id'] ?>">
                                    <?php if ($r['correction_ia_feedback']): ?>
                                        <div class="mb-2">
                                            <small class="d-block fw-semibold text-info">
                                                <i class="bi bi-robot"></i> Suggestion IA (<?= date('d/m/Y H:i', strtotime($r['correction_ia_date'] ?? 'now')) ?>)
                                            </small>
                                            <small class="text-muted"><?= htmlspecialchars($r['correction_ia_feedback']) ?></small>
                                        </div>
                                        <div class="small">
                                            <strong class="text-info">Points suggérés : <?= number_format($r['correction_ia_points_suggeres'], 1) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-info mt-2"
                                            onclick="corrigerAvecIA(<?= $r['id'] ?>, this)">
                                        <i class="bi bi-lightbulb me-1"></i>Corriger avec IA
                                    </button>
                                </div>

                                <div class="mt-2">
                                    <form method="POST" action="correct_texte.php" class="d-inline-flex align-items-center gap-2">
                                        <input type="hidden" name="rep_id" value="<?= $r['id'] ?>">
                                        <input type="hidden" name="session_id" value="<?= $id ?>">
                                        <input type="number" name="points" step="0.5" min="0" max="<?= $r['points_max'] ?>"
                                               class="form-control form-control-sm points-input-<?= $r['id'] ?>" style="width:80px"
                                               value="<?= $r['points_obtenus'] ?>" placeholder="pts">
                                        <button type="submit" class="btn btn-sm btn-warning">
                                            <i class="bi bi-check me-1"></i>Valider note
                                        </button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <span class="text-danger">Aucune réponse saisie</span>
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
async function corrigerAvecIA(repId, btnElement) {
    const btn = btnElement;
    const originalText = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Correction en cours...';

    try {
        const response = await fetch('api_correct_ia.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'rep_id=' + repId
        });

        const data = await response.json();

        if (!data.success) {
            alert('Erreur : ' + data.error);
            btn.innerHTML = originalText;
            btn.disabled = false;
            return;
        }

        // Mettre à jour l'affichage
        const section = document.getElementById('ia-section-' + repId);
        const pointsInput = document.querySelector('.points-input-' + repId);

        section.innerHTML = `
            <div class="mb-2">
                <small class="d-block fw-semibold text-success">
                    <i class="bi bi-robot"></i> Suggestion IA (maintenant)
                </small>
                <small class="text-muted">${data.feedback}</small>
            </div>
            <div class="small">
                <strong class="text-success">Points suggérés : ${data.points}</strong>
            </div>
            <button type="button" class="btn btn-sm btn-outline-info mt-2" onclick="corrigerAvecIA(${repId}, this)">
                <i class="bi bi-lightbulb me-1"></i>Corriger avec IA
            </button>
        `;

        pointsInput.value = data.points;
        pointsInput.style.borderColor = '#0d6efd';

        btn = section.querySelector('button');
        btn.innerHTML = '<i class="bi bi-lightbulb me-1"></i>Corriger avec IA';
        btn.disabled = false;

    } catch (error) {
        alert('Erreur réseau : ' + error.message);
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}
</script>
</body>
</html>
