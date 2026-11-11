<?php
/**
 * preview_eval.php — Prévisualisation d'une évaluation (vue stagiaire, lecture seule).
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$evalId = (int)($_GET['id'] ?? 0);
if (!$evalId) {
    header('Location: evaluations.php');
    exit;
}

$eval = getEvaluation($evalId);
if (!$eval) {
    header('Location: evaluations.php');
    exit;
}

$questions = getQuestionsEvaluation($evalId);
$nbQ       = count($questions);
$module    = getModule((int)$eval['module_id']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prévisualisation — <?= sanitize($eval['nom']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .dd-template {
            font-family: SFMono-Regular, Consolas, monospace; font-size: .9rem;
            background: #1e1e2e; color: #e0e0e0; padding: 14px 16px; border-radius: 10px;
            line-height: 2.6; white-space: pre-wrap; word-break: break-word;
        }
        .dd-slot {
            display: inline-block; min-width: 90px; min-height: 30px; vertical-align: middle;
            border: 2px dashed #8ab4f8; border-radius: 6px; padding: 0 8px; margin: 0 2px;
            line-height: 26px; text-align: center; color: #fff;
        }
        .dd-bank { display: flex; flex-wrap: wrap; gap: 8px; min-height: 44px;
                   padding: 10px; border: 1px dashed #ced4da; border-radius: 10px; background: #fff; }
        .dd-token {
            font-family: SFMono-Regular, Consolas, monospace; font-size: .85rem;
            background: #e7f1ff; color: #0b5ed7; border: 1px solid #9ec5fe;
            padding: 4px 10px; border-radius: 6px;
        }
        .preview-banner {
            background: linear-gradient(135deg, #fff3cd, #ffe69c);
            border-bottom: 2px solid #ffc107;
        }
        .correct-answer {
            background: #d1e7dd !important;
            border-color: #198754 !important;
        }
        .correct-badge {
            font-size: .7rem; vertical-align: middle;
        }
    </style>
</head>
<body class="bg-light">

<!-- Bandeau prévisualisation -->
<div class="preview-banner py-2 px-4 d-flex align-items-center gap-3">
    <i class="bi bi-eye-fill text-warning fs-5"></i>
    <span class="fw-semibold text-dark">Mode prévisualisation — vue stagiaire</span>
    <span class="badge bg-warning text-dark ms-1">Admin uniquement</span>
    <a href="evaluations.php?module_id=<?= (int)$eval['module_id'] ?>" class="btn btn-sm btn-outline-dark ms-auto">
        <i class="bi bi-arrow-left me-1"></i>Retour aux évaluations
    </a>
</div>

<!-- Barre supérieure (simulée) -->
<nav class="navbar navbar-dark bg-primary shadow-sm">
    <div class="container-fluid">
        <span class="navbar-brand fw-bold">
            <i class="bi bi-journal-check me-2"></i><?= sanitize($eval['nom']) ?>
        </span>
        <div class="d-flex align-items-center gap-3">
            <span class="text-white-75 small d-none d-sm-inline">
                <i class="bi bi-person me-1"></i>Stagiaire Exemple — Groupe A
            </span>
            <div class="timer-badge">
                <i class="bi bi-clock me-1"></i>
                <span><?= (int)$eval['duree_minutes'] ?>:00</span>
            </div>
        </div>
    </div>
</nav>

<div class="container py-5" style="max-width: 800px;">

    <!-- Info prévisualisation -->
    <div class="alert alert-warning rounded-4 d-flex gap-3 align-items-start mb-4">
        <i class="bi bi-lightbulb-fill fs-4 mt-1"></i>
        <div>
            <strong>Prévisualisation administrateur</strong><br>
            <span class="small">Les bonnes réponses sont indiquées en vert. Le chronomètre et la soumission sont désactivés. Les mots du glisser-déposer ne sont pas mélangés.</span>
        </div>
    </div>

    <!-- Progression (simulée) -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body py-3 px-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="small text-muted">
                    <i class="bi bi-list-check me-1"></i><?= $nbQ ?> question<?= $nbQ > 1 ? 's' : '' ?>
                    — Total :
                    <?php
                    $totalPts = array_sum(array_column($questions, 'points'));
                    echo number_format($totalPts, 1);
                    ?> pts
                </span>
                <span class="small text-muted">0 / <?= $nbQ ?> répondu(s)</span>
            </div>
            <div class="progress" style="height: 8px;">
                <div class="progress-bar bg-primary" style="width:0%"></div>
            </div>
        </div>
    </div>

    <!-- Questions -->
    <?php
    $typeLabels = [
        'qcm'             => 'QCM',
        'vrai_faux'       => 'Vrai / Faux',
        'texte_libre'     => 'Réponse libre',
        'multiple'        => 'Choix multiples',
        'glisser_deposer' => 'Glisser-déposer'
    ];
    $qNum = 0;
    ?>

    <?php foreach ($questions as $q): ?>
    <?php
        $qNum++;
        $qId   = (int)$q['id'];
        $enonce = $q['texte'];
        $gabarit = '';
        if ($q['type'] === 'glisser_deposer') [$enonce, $gabarit] = splitGlisserDeposer($q['texte']);
    ?>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">

            <div class="d-flex align-items-start gap-3 mb-3">
                <div class="question-number"><?= $qNum ?></div>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-start">
                        <p class="fw-semibold mb-1 question-text"><?= nl2br(sanitize($enonce)) ?></p>
                        <span class="badge bg-primary-subtle text-primary ms-2 flex-shrink-0">
                            <?= $q['points'] ?> pt<?= $q['points'] > 1 ? 's' : '' ?>
                        </span>
                    </div>
                    <span class="badge bg-light text-muted border small">
                        <?= $typeLabels[$q['type']] ?? $q['type'] ?>
                    </span>
                </div>
            </div>

            <?php if ($q['type'] === 'qcm' || $q['type'] === 'vrai_faux'): ?>
                <div class="choices-list ps-5">
                    <?php foreach ($q['choix'] as $c): ?>
                    <label class="choice-label d-flex align-items-center gap-3 p-3 rounded-3 mb-2 <?= $c['is_correct'] ? 'correct-answer' : '' ?>">
                        <input class="form-check-input m-0 flex-shrink-0" type="radio"
                               name="rep_<?= $qId ?>" value="<?= $c['id'] ?>" disabled>
                        <span><?= sanitize($c['texte']) ?></span>
                        <?php if ($c['is_correct']): ?>
                            <span class="badge bg-success correct-badge ms-auto"><i class="bi bi-check-lg"></i> Correcte</span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>

            <?php elseif ($q['type'] === 'multiple'): ?>
                <div class="choices-list ps-5">
                    <div class="text-muted small mb-2"><i class="bi bi-ui-checks me-1"></i>Plusieurs réponses possibles — cochez toutes les bonnes réponses</div>
                    <?php foreach ($q['choix'] as $c): ?>
                    <label class="choice-label d-flex align-items-center gap-3 p-3 rounded-3 mb-2 <?= $c['is_correct'] ? 'correct-answer' : '' ?>">
                        <input class="form-check-input m-0 flex-shrink-0" type="checkbox"
                               name="rep_<?= $qId ?>[]" value="<?= (int)$c['id'] ?>" disabled>
                        <span><?= sanitize($c['texte']) ?></span>
                        <?php if ($c['is_correct']): ?>
                            <span class="badge bg-success correct-badge ms-auto"><i class="bi bi-check-lg"></i> Correcte</span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>

            <?php elseif ($q['type'] === 'texte_libre'): ?>
                <div class="ps-5">
                    <textarea class="form-control" rows="5" placeholder="Rédigez votre réponse ici..." disabled></textarea>
                    <div class="text-muted small mt-1">
                        <i class="bi bi-info-circle me-1"></i>Réponse corrigée par le formateur
                    </div>
                </div>

            <?php elseif ($q['type'] === 'glisser_deposer'): ?>
                <div class="ps-5">
                    <?php
                    // Construire le mapping slot → texte correct (ordre = numéro de slot)
                    $slotAnswers = [];
                    foreach ($q['choix'] as $c) {
                        if ($c['is_correct']) $slotAnswers[(int)$c['ordre']] = $c['texte'];
                    }
                    ?>
                    <div class="dd-template mb-3"><?php
                        foreach (preg_split('/(\{\{\d+\}\})/', $gabarit, -1, PREG_SPLIT_DELIM_CAPTURE) as $seg):
                            if (preg_match('/^\{\{(\d+)\}\}$/', $seg, $mm)):
                                $slotNum = (int)$mm[1];
                                $bonne = $slotAnswers[$slotNum] ?? null;
                                if ($bonne !== null): ?>
                                    <span class="dd-slot filled" style="border-style:solid;border-color:#4caf50;background:#2e7d32"><?= sanitize($bonne) ?></span>
                                <?php else: ?>
                                    <span class="dd-slot" title="Case <?= $slotNum ?>"></span>
                                <?php endif;
                            else:
                                echo sanitize($seg);
                            endif;
                        endforeach; ?>
                    </div>
                    <div class="small text-muted mb-2">
                        <i class="bi bi-hand-index me-1"></i>Glissez les mots-clés dans les cases. Certains mots-clés sont des pièges.
                    </div>
                    <div class="dd-bank">
                        <?php foreach ($q['choix'] as $c): ?>
                        <span class="dd-token <?= $c['is_correct'] ? '' : 'opacity-50' ?>"
                              title="<?= $c['is_correct'] ? 'Mot correct' : 'Leurre' ?>">
                            <?= sanitize($c['texte']) ?>
                            <?php if (!$c['is_correct']): ?>
                                <span class="badge bg-danger ms-1 correct-badge" style="font-size:.6rem">leurre</span>
                            <?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <?php endforeach; ?>

    <!-- Bouton soumission (désactivé) -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4 text-center">
            <p class="text-muted mb-3">
                <i class="bi bi-exclamation-circle me-1"></i>
                Vérifiez vos réponses avant de soumettre. La soumission est définitive.
            </p>
            <button type="button" class="btn btn-success btn-lg px-5 fw-semibold" disabled>
                <i class="bi bi-send-check-fill me-2"></i>Terminer et envoyer
            </button>
            <div class="text-muted small mt-2"><i class="bi bi-lock me-1"></i>Soumission désactivée en mode prévisualisation</div>
        </div>
    </div>

    <div class="text-center mb-5">
        <a href="evaluations.php?module_id=<?= (int)$eval['module_id'] ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Retour aux évaluations
        </a>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
