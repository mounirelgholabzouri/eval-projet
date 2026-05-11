<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $repId     = (int)($_POST['rep_id']     ?? 0);
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $points    = (float)($_POST['points']   ?? -1);

    if ($repId <= 0 || $sessionId <= 0 || $points < 0) {
        throw new Exception('Paramètres invalides');
    }

    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT q.points FROM reponses_stagiaires rs JOIN questions q ON q.id = rs.question_id WHERE rs.id = ?");
    $stmt->execute([$repId]);
    $pointsMax = (float)($stmt->fetchColumn() ?? 0);

    $points    = min(max($points, 0), $pointsMax);
    $isCorrect = $points > 0 ? 1 : 0;

    $pdo->prepare("UPDATE reponses_stagiaires SET points_obtenus = ?, is_correct = ?, source_correction = 'manuel' WHERE id = ?")
        ->execute([$points, $isCorrect, $repId]);

    // Recalculer score session
    $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(points_obtenus),0) FROM reponses_stagiaires WHERE session_id = ?");
    $stmt2->execute([$sessionId]);
    $score = (float)$stmt2->fetchColumn();

    $session = getSession($sessionId);
    $total   = (float)$session['total_points'];
    $pct     = $total > 0 ? round($score / $total * 100, 2) : 0;

    $pdo->prepare("UPDATE sessions_eval SET score = ?, pourcentage = ? WHERE id = ?")
        ->execute([$score, $pct, $sessionId]);

    echo json_encode([
        'success'   => true,
        'points'    => $points,
        'points_max' => $pointsMax,
        'score'     => $score,
        'total'     => $total,
        'pct'       => $pct,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
