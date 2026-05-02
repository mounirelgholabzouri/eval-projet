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

    // Récupérer toutes les réponses texte_libre non vides de cette session
    $stmt = $pdo->prepare("
        SELECT rs.id, rs.reponse_texte, q.texte AS question_texte, q.points
        FROM reponses_stagiaires rs
        JOIN questions q ON rs.question_id = q.id
        WHERE rs.session_id = ? AND q.type = 'texte_libre' AND rs.reponse_texte IS NOT NULL AND rs.reponse_texte != ''
        ORDER BY q.ordre, q.id
    ");
    $stmt->execute([$sessionId]);
    $reponses = $stmt->fetchAll();

    if (empty($reponses)) {
        throw new Exception('Aucune réponse texte libre à corriger');
    }

    $resultats = [];
    $erreurs   = [];

    foreach ($reponses as $r) {
        $res = correcterAvecIA(
            (int)$r['id'],
            $r['reponse_texte'],
            $r['question_texte'],
            (float)$r['points']
        );

        if ($res['success']) {
            $resultats[] = [
                'rep_id'   => (int)$r['id'],
                'points'   => $res['points'],
                'niveau'   => $res['niveau'],
                'feedback' => $res['feedback'],
            ];
        } else {
            $erreurs[] = 'Q#' . $r['id'] . ' : ' . $res['error'];
        }
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
        'corriges'  => count($resultats),
        'erreurs'   => $erreurs,
        'resultats' => $resultats,
        'score'     => $score,
        'total'     => $total,
        'pct'       => $pct,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
