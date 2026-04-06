<?php
session_name(SESSION_EVAL_NAME ?? 'eval_stagiaire');
session_start();

require_once __DIR__ . '/includes/functions.php';

// Vérification session
if (empty($_SESSION['eval_session_id'])) {
    redirect('index.php');
}

$sessionId = (int)$_SESSION['eval_session_id'];
$session   = getSession($sessionId);

if (!$session || $session['statut'] === 'termine') {
    redirect('result.php');
}

$questions = getQuestionsModule((int)$session['module_id']);
$nbQ       = count($questions);

// ── Traitement de la soumission finale ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_final'])) {
    foreach ($questions as $q) {
        $qId     = (int)$q['id'];
        $type    = $q['type'];
        $points  = (float)$q['points'];
        $choixId = null;
        $repTxt  = null;
        $correct = false;
        $gained  = 0.0;

        if ($type === 'texte_libre') {
            $repTxt  = trim($_POST["rep_$qId"] ?? '');
            $correct = false; // Correction manuelle par le formateur
            $gained  = 0;
        } else {
            $choixId = (int)($_POST["rep_$qId"] ?? 0);
            if ($choixId > 0) {
                // Vérifier si c'est correct
                foreach ($q['choix'] as $c) {
                    if ((int)$c['id'] === $choixId && $c['is_correct']) {
                        $correct = true;
                        $gained  = $points;
                        break;
                    }
                }
            }
        }

        sauvegarderReponse($sessionId, $qId, $choixId ?: null, $repTxt, $correct, $gained);
    }

    $result = terminerSession($sessionId);
    $_SESSION['eval_result'] = $result;
    redirect('result.php');
}

// ── Décompte du temps ────────────────────────────────────────
$dureeSecondes = (int)$session['duree_minutes'] * 60;
$debutTimestamp = strtotime($session['date_debut']);
$elapsed  = time() - $debutTimestamp;
$restant  = max(0, $dureeSecondes - $elapsed);

$groupe = $session['groupe_nom'] ?: $session['groupe_libre'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Évaluation — <?= sanitize($session['module_nom']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">

<!-- Barre supérieure fixe -->
<nav class="navbar navbar-dark bg-primary fixed-top shadow-sm">
    <div class="container-fluid">
        <span class="navbar-brand fw-bold">
            <i class="bi bi-journal-check me-2"></i><?= sanitize($session['module_nom']) ?>
        </span>
        <div class="d-flex align-items-center gap-3">
            <span class="text-white-75 small d-none d-sm-inline">
                <i class="bi bi-person me-1"></i>
                <?= sanitize($session['prenom']) ?> <?= sanitize($session['nom']) ?>
                <?php if ($groupe): ?> — <?= sanitize($groupe) ?><?php endif; ?>
            </span>
            <div id="timer" class="timer-badge <?= $restant < 300 ? 'timer-warning' : '' ?>">
                <i class="bi bi-clock me-1"></i>
                <span id="timer-display">--:--</span>
            </div>
        </div>
    </div>
</nav>

<div class="container py-5 mt-4" style="max-width: 800px;">

    <!-- Progression -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body py-3 px-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="small text-muted">
                    <i class="bi bi-list-check me-1"></i><?= $nbQ ?> question<?= $nbQ > 1 ? 's' : '' ?>
                    — Total : <?= getTotalPoints((int)$session['module_id']) ?> pts
                </span>
                <span class="small text-muted" id="progress-text">0 / <?= $nbQ ?> répondu(s)</span>
            </div>
            <div class="progress" style="height: 8px;">
                <div class="progress-bar bg-primary" id="progress-bar" role="progressbar" style="width:0%"></div>
            </div>
        </div>
    </div>

    <!-- Formulaire questions -->
    <form method="POST" id="quiz-form" action="" onsubmit="return confirmerSoumission()">

        <?php foreach ($questions as $idx => $q): ?>
        <?php $qNum = $idx + 1; $qId = (int)$q['id']; ?>

        <div class="card border-0 shadow-sm rounded-4 mb-4 question-card" id="card-<?= $qId ?>">
            <div class="card-body p-4">

                <!-- En-tête question -->
                <div class="d-flex align-items-start gap-3 mb-3">
                    <div class="question-number"><?= $qNum ?></div>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-start">
                            <p class="fw-semibold mb-1 question-text"><?= nl2br(sanitize($q['texte'])) ?></p>
                            <span class="badge bg-primary-subtle text-primary ms-2 flex-shrink-0">
                                <?= $q['points'] ?> pt<?= $q['points'] > 1 ? 's' : '' ?>
                            </span>
                        </div>
                        <span class="badge bg-light text-muted border small">
                            <?php
                            $typeLabels = [
                                'qcm'         => 'QCM',
                                'vrai_faux'   => 'Vrai / Faux',
                                'texte_libre' => 'Réponse libre',
                                'multiple'    => 'Choix multiples'
                            ];
                            echo $typeLabels[$q['type']] ?? $q['type'];
                            ?>
                        </span>
                    </div>
                </div>

                <!-- Choix selon type -->
                <?php if ($q['type'] === 'qcm' || $q['type'] === 'vrai_faux'): ?>
                    <div class="choices-list ps-5">
                        <?php foreach ($q['choix'] as $c): ?>
                        <label class="choice-label d-flex align-items-center gap-3 p-3 rounded-3 mb-2 cursor-pointer"
                               for="rep_<?= $qId ?>_<?= $c['id'] ?>">
                            <input class="form-check-input m-0 flex-shrink-0 answer-input"
                                   type="radio"
                                   name="rep_<?= $qId ?>"
                                   id="rep_<?= $qId ?>_<?= $c['id'] ?>"
                                   value="<?= $c['id'] ?>"
                                   data-qid="<?= $qId ?>">
                            <span><?= sanitize($c['texte']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($q['type'] === 'texte_libre'): ?>
                    <div class="ps-5">
                        <textarea class="form-control answer-input"
                                  name="rep_<?= $qId ?>"
                                  rows="5"
                                  placeholder="Rédigez votre réponse ici..."
                                  data-qid="<?= $qId ?>"></textarea>
                        <div class="text-muted small mt-1">
                            <i class="bi bi-info-circle me-1"></i>Réponse corrigée par le formateur
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <?php endforeach; ?>

        <!-- Bouton de soumission -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4 text-center">
                <p class="text-muted mb-3">
                    <i class="bi bi-exclamation-circle me-1"></i>
                    Vérifiez vos réponses avant de soumettre. La soumission est définitive.
                </p>
                <button type="submit" name="submit_final" value="1"
                        class="btn btn-success btn-lg px-5 fw-semibold">
                    <i class="bi bi-send-check-fill me-2"></i>Terminer et envoyer
                </button>
            </div>
        </div>

    </form>
</div>

<!-- Modal confirmation -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4">
            <div class="modal-header border-0">
                <h5 class="modal-title"><i class="bi bi-send-check me-2 text-success"></i>Soumettre l'évaluation ?</h5>
            </div>
            <div class="modal-body" id="confirmBody">
                Êtes-vous sûr de vouloir soumettre ?
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-success" id="confirmBtn">
                    <i class="bi bi-check-lg me-1"></i>Oui, soumettre
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal temps écoulé -->
<div class="modal fade" id="timeoutModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-danger">
            <div class="modal-body text-center p-5">
                <div class="text-danger display-4 mb-3"><i class="bi bi-alarm-fill"></i></div>
                <h4 class="fw-bold">Temps écoulé !</h4>
                <p class="text-muted">Le formulaire va être soumis automatiquement...</p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Minuteur ─────────────────────────────────────────────────
let restant = <?= $restant ?>;
const timerEl = document.getElementById('timer-display');
const timerBadge = document.getElementById('timer');

function updateTimer() {
    if (restant <= 0) {
        timerEl.textContent = '00:00';
        const modal = new bootstrap.Modal(document.getElementById('timeoutModal'));
        modal.show();
        setTimeout(() => document.getElementById('quiz-form').submit(), 3000);
        return;
    }
    const m = Math.floor(restant / 60).toString().padStart(2, '0');
    const s = (restant % 60).toString().padStart(2, '0');
    timerEl.textContent = `${m}:${s}`;

    if (restant <= 300) timerBadge.classList.add('timer-warning');
    if (restant <= 60)  timerBadge.classList.add('timer-danger');
    restant--;
}
updateTimer();
setInterval(updateTimer, 1000);

// ── Progression ──────────────────────────────────────────────
const total = <?= $nbQ ?>;
const answered = new Set();

function updateProgress() {
    const pct = Math.round(answered.size / total * 100);
    document.getElementById('progress-bar').style.width = pct + '%';
    document.getElementById('progress-text').textContent = `${answered.size} / ${total} répondu(s)`;
}

document.querySelectorAll('.answer-input').forEach(input => {
    input.addEventListener('change', function() {
        const qid = this.dataset.qid;
        if (this.type === 'radio') {
            answered.add(qid);
            // Highlight la carte
            document.getElementById('card-' + qid).classList.add('answered');
        } else if (this.value.trim().length > 0) {
            answered.add(qid);
            document.getElementById('card-' + qid).classList.add('answered');
        }
        updateProgress();
    });
    // Textarea
    if (input.tagName === 'TEXTAREA') {
        input.addEventListener('input', function() {
            const qid = this.dataset.qid;
            if (this.value.trim().length > 0) {
                answered.add(qid);
                document.getElementById('card-' + qid).classList.add('answered');
            } else {
                answered.delete(qid);
                document.getElementById('card-' + qid).classList.remove('answered');
            }
            updateProgress();
        });
    }
});

// ── Confirmation soumission ───────────────────────────────────
let submitConfirmed = false;

function confirmerSoumission() {
    if (submitConfirmed) return true;
    const nonRepondues = total - answered.size;
    let msg = `Vous avez répondu à <strong>${answered.size} / ${total}</strong> question(s).`;
    if (nonRepondues > 0) {
        msg += `<br><span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>${nonRepondues} question(s) sans réponse.</span>`;
    }
    msg += '<br><br>Confirmer la soumission définitive ?';
    document.getElementById('confirmBody').innerHTML = msg;

    const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    modal.show();

    document.getElementById('confirmBtn').onclick = function() {
        submitConfirmed = true;
        modal.hide();
        document.getElementById('quiz-form').submit();
    };
    return false;
}
</script>
</body>
</html>
