<?php
// Sauvegarde automatique des réponses du quiz (fetch / sendBeacon depuis quiz.php).
// Les réponses sont enregistrées sans clore la session ; si le temps est dépassé,
// la session est clôturée avec les réponses reçues.
require_once __DIR__ . '/includes/functions.php';
session_name(SESSION_EVAL_NAME ?? 'eval_stagiaire');
session_start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['eval_session_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erreur' => 'session']);
    exit;
}

$sessionId = (int)$_SESSION['eval_session_id'];
$session   = getSession($sessionId);
if (!$session || $session['statut'] !== 'en_cours') {
    echo json_encode(['ok' => false, 'termine' => true]);
    exit;
}

$qIds      = !empty($session['questions_ids']) ? json_decode($session['questions_ids'], true) : null;
$questions = getQuestionsEvaluation((int)$session['evaluation_id'], $qIds);

try {
    enregistrerReponsesQuiz($sessionId, $questions, $_POST);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erreur' => 'enregistrement']);
    exit;
}

// Temps écoulé (marge 1 min) → clôture côté serveur
$duree   = (int)$session['duree_minutes'] * 60;
$expire  = $duree > 0 && time() > strtotime($session['date_debut']) + $duree + 60;
if ($expire) {
    $_SESSION['eval_result'] = terminerSession($sessionId);
}

echo json_encode(['ok' => true, 'termine' => $expire]);
