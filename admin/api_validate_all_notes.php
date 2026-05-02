<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    if ($sessionId <= 0) {
        throw new Exception('ID session invalide');
    }

    $pdo = getDB();

    // Récupérer toutes les réponses texte_libre qui ont une correction IA
    $stmt = $pdo->prepare("
        SELECT rs.id, rs.points_obtenus, q.points
        FROM reponses_stagiaires rs
        JOIN questions q ON rs.question_id = q.id
        WHERE rs.session_id = ? AND q.type = 'texte_libre'
              AND rs.correction_ia_feedback IS NOT NULL AND rs.correction_ia_feedback != ''
        ORDER BY q.ordre, q.id
    ");
    $stmt->execute([$sessionId]);
    $reponses = $stmt->fetchAll();

    if (empty($reponses)) {
        throw new Exception('Aucune réponse corrigée par IA à valider');
    }

    $validees = 0;

    foreach ($reponses as $r) {
        $points = (float)$r['points_obtenus'];
        $max    = (float)$r['points'];
        $isCorrect = $points >= $max ? 1 : ($points > 0 ? 1 : 0);

        // Marquer comme validée (points_obtenus déjà définis par l'IA ou manuel)
        $pdo->prepare("UPDATE reponses_stagiaires SET is_correct=? WHERE id=?")
            ->execute([$isCorrect, (int)$r['id']]);
        $validees++;
    }

    // Recalculer le score total de la session
    $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(points_obtenus),0) FROM reponses_stagiaires WHERE session_id = ?");
    $stmt2->execute([$sessionId]);
    $score = (float)$stmt2->fetchColumn();

    $session = getSession($sessionId);
    $total   = (float)$session['total_points'];
    $pct     = $total > 0 ? round($score / $total * 100, 2) : 0;

    $pdo->prepare("UPDATE sessions_eval SET score=?, pourcentage=? WHERE id=?")
        ->execute([$score, $pct, $sessionId]);

    echo json_encode([
        'success'   => true,
        'validees'  => $validees,
        'score'     => $score,
        'total'     => $total,
        'pct'       => $pct,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
