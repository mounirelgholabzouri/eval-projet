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

    $validees = 0;

    // Valider les corrections IA existantes
    foreach ($reponses as $r) {
        $points    = (float)$r['points_obtenus'];
        $max       = (float)$r['points'];
        $isCorrect = $points > 0 ? 1 : 0;
        $pdo->prepare("UPDATE reponses_stagiaires SET is_correct=? WHERE id=?")
            ->execute([$isCorrect, (int)$r['id']]);
        $validees++;
    }

    // Valider avec 0 pt les questions texte libre sans aucune correction
    $stmtRest = $pdo->prepare("
        SELECT rs.id FROM reponses_stagiaires rs
        JOIN questions q ON q.id = rs.question_id
        WHERE rs.session_id = ? AND q.type = 'texte_libre' AND rs.source_correction IS NULL
    ");
    $stmtRest->execute([$sessionId]);
    foreach ($stmtRest->fetchAll() as $r) {
        $pdo->prepare("UPDATE reponses_stagiaires SET points_obtenus=0, is_correct=0, source_correction='manuel' WHERE id=?")
            ->execute([$r['id']]);
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
