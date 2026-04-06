<?php
session_name(SESSION_EVAL_NAME ?? 'eval_stagiaire');
session_start();

require_once __DIR__ . '/includes/functions.php';

if (empty($_SESSION['eval_session_id'])) {
    redirect('index.php');
}

$sessionId = (int)$_SESSION['eval_session_id'];
$session   = getSession($sessionId);

if (!$session) {
    redirect('index.php');
}

// Si pas encore terminé, forcer la fin
if ($session['statut'] !== 'termine') {
    terminerSession($sessionId);
    $session = getSession($sessionId);
}

$reponses  = getReponsesSession($sessionId);
$mention   = getMention((float)$session['pourcentage']);
$groupe    = $session['groupe_nom'] ?: $session['groupe_libre'];

// Nettoyage session
unset($_SESSION['eval_session_id'], $_SESSION['eval_session_token'], $_SESSION['eval_result']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Résultats — <?= sanitize($session['module_nom']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container py-5" style="max-width: 800px;">

    <!-- Carte résultat principal -->
    <div class="card border-0 shadow rounded-4 mb-4 overflow-hidden">
        <div class="card-header bg-<?= $mention['class'] ?> text-white py-4 text-center">
            <div class="display-4 mb-2">
                <?php if ($mention['class'] === 'success'): ?>
                    <i class="bi bi-trophy-fill"></i>
                <?php elseif ($mention['class'] === 'primary'): ?>
                    <i class="bi bi-star-fill"></i>
                <?php elseif ($mention['class'] === 'warning'): ?>
                    <i class="bi bi-emoji-neutral-fill"></i>
                <?php else: ?>
                    <i class="bi bi-emoji-frown-fill"></i>
                <?php endif; ?>
            </div>
            <h2 class="fw-bold mb-1"><?= $mention['label'] ?></h2>
            <?php
            // Convertir le score sur la note max du module (20 ou 40)
            $module   = getModule((int)$session['module_id']);
            $noteMax  = (int)($module['note_max'] ?? 20);
            $total    = (float)$session['total_points'];
            $scoreRaw = (float)$session['score'];
            $scoreSur = $total > 0 ? round($scoreRaw / $total * $noteMax, 2) : 0;
            ?>
            <div class="h1 fw-bold mb-0">
                <?= number_format($scoreSur, 2) ?> / <?= $noteMax ?>
            </div>
            <div class="h5 opacity-75 mb-0">(<?= number_format($scoreRaw, 1) ?> / <?= number_format($total, 1) ?> pts bruts)</div>
            <div class="h4 opacity-75"><?= number_format((float)$session['pourcentage'], 1) ?> %</div>
        </div>

        <div class="card-body p-4">
            <div class="row g-3 text-center">
                <div class="col-6 col-md-3">
                    <div class="stat-box">
                        <div class="text-muted small"><i class="bi bi-person me-1"></i>Stagiaire</div>
                        <div class="fw-semibold"><?= sanitize($session['prenom']) ?> <?= sanitize($session['nom']) ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-box">
                        <div class="text-muted small"><i class="bi bi-people me-1"></i>Groupe</div>
                        <div class="fw-semibold"><?= sanitize($groupe ?: '—') ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-box">
                        <div class="text-muted small"><i class="bi bi-journal me-1"></i>Module</div>
                        <div class="fw-semibold small"><?= sanitize($session['module_nom']) ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-box">
                        <div class="text-muted small"><i class="bi bi-calendar3 me-1"></i>Date</div>
                        <div class="fw-semibold small">
                            <?= date('d/m/Y H:i', strtotime($session['date_debut'])) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Détail des réponses -->
    <?php if (!empty($reponses)): ?>
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white border-0 py-3 px-4">
            <h5 class="mb-0 fw-bold">
                <i class="bi bi-list-check me-2 text-primary"></i>Détail des réponses
            </h5>
        </div>
        <div class="card-body p-0">
            <?php foreach ($reponses as $idx => $r): ?>
            <div class="result-row px-4 py-3 d-flex align-items-start gap-3
                        <?= $r['type'] === 'texte_libre' ? 'border-start border-4 border-warning' : ($r['is_correct'] ? 'border-start border-4 border-success' : 'border-start border-4 border-danger') ?>
                        <?= $idx > 0 ? 'border-top' : '' ?>">

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
                    <div class="fw-semibold mb-1">
                        Q<?= $idx + 1 ?>. <?= sanitize($r['question_texte']) ?>
                    </div>
                    <div class="small text-muted">
                        <?php if ($r['type'] === 'texte_libre'): ?>
                            <span class="text-warning"><i class="bi bi-info-circle me-1"></i>Correction manuelle par le formateur</span>
                            <?php if ($r['reponse_texte']): ?>
                                <br><em>"<?= sanitize(mb_substr($r['reponse_texte'], 0, 200)) ?>"</em>
                            <?php endif; ?>
                        <?php elseif ($r['is_correct']): ?>
                            <span class="text-success"><i class="bi bi-check-circle me-1"></i>Bonne réponse</span>
                            — <?= sanitize($r['choix_texte'] ?? '') ?>
                        <?php else: ?>
                            <span class="text-danger"><i class="bi bi-x-circle me-1"></i>Mauvaise réponse</span>
                            <?php if ($r['choix_texte']): ?>
                                — Votre réponse : <?= sanitize($r['choix_texte']) ?>
                            <?php else: ?>
                                — Sans réponse
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex-shrink-0 text-end">
                    <span class="fw-semibold <?= $r['is_correct'] ? 'text-success' : 'text-muted' ?>">
                        <?= number_format((float)$r['points_obtenus'], 1) ?> / <?= number_format((float)$r['points_max'], 1) ?>
                    </span>
                    <div class="text-muted small">pts</div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Boutons -->
    <div class="d-flex gap-3 justify-content-center flex-wrap">
        <a href="index.php" class="btn btn-primary btn-lg">
            <i class="bi bi-arrow-clockwise me-2"></i>Nouvelle évaluation
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-lg">
            <i class="bi bi-printer me-2"></i>Imprimer
        </button>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
