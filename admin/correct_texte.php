<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $repId     = (int)($_POST['rep_id'] ?? 0);
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $points    = (float)($_POST['points'] ?? 0);

    if ($repId > 0 && $sessionId > 0) {
        $pdo = getDB();
        // Récupérer les points max de la question
        $stmt = $pdo->prepare("SELECT q.points FROM reponses_stagiaires rs JOIN questions q ON q.id = rs.question_id WHERE rs.id = ?");
        $stmt->execute([$repId]);
        $max = (float)($stmt->fetchColumn() ?? 0);
        $points = min($points, $max);

        $isCorrect = $points >= $max ? 1 : ($points > 0 ? 1 : 0);
        $pdo->prepare("UPDATE reponses_stagiaires SET points_obtenus=?, is_correct=? WHERE id=?")
            ->execute([$points, $isCorrect, $repId]);

        // Recalculer le score total de la session
        $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(points_obtenus),0) FROM reponses_stagiaires WHERE session_id=?");
        $stmt2->execute([$sessionId]);
        $score = (float)$stmt2->fetchColumn();

        $session = getSession($sessionId);
        $total = (float)$session['total_points'];
        $pct = $total > 0 ? round($score / $total * 100, 2) : 0;

        $pdo->prepare("UPDATE sessions_eval SET score=?, pourcentage=? WHERE id=?")
            ->execute([$score, $pct, $sessionId]);
    }
}

header("Location: detail.php?id=$sessionId");
exit;
