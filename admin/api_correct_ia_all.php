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
        echo json_encode(['success'=>true,'corriges'=>0,'erreurs'=>[],'resultats'=>[]]);
        exit;
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
                'rep_id'     => (int)$r['id'],
                'suggestion' => $res['points'],   // correcterAvecIA() retourne 'points'
                'niveau'     => $res['niveau'],
                'feedback'   => $res['feedback'],
            ];
        } else {
            $erreurs[] = 'Q#' . $r['id'] . ' : ' . $res['error'];
        }
    }

    // Recalcul du score global de la session après toutes les corrections IA
    $scoreData = updateSessionScore($sessionId);

    echo json_encode([
        'success'   => true,
        'corriges'  => count($resultats),
        'erreurs'   => $erreurs,
        'resultats' => $resultats,
        'score'     => $scoreData['score'],
        'total'     => $scoreData['total'],
        'pct'       => $scoreData['pct'],
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
