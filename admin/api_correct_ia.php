<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $repId     = (int)($_POST['rep_id']     ?? 0);
    $sessionId = (int)($_POST['session_id'] ?? 0);

    if ($repId <= 0) {
        throw new Exception('ID réponse invalide');
    }

    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT rs.id, rs.reponse_texte, rs.session_id, q.texte AS question_texte, q.points
        FROM reponses_stagiaires rs
        JOIN questions q ON rs.question_id = q.id
        WHERE rs.id = ? AND q.type = 'texte_libre'
    ");
    $stmt->execute([$repId]);
    $reponse = $stmt->fetch();

    if (!$reponse) throw new Exception('Réponse texte libre non trouvée');
    if (empty($reponse['reponse_texte'])) throw new Exception('Aucun texte à corriger');

    $sessionId = $sessionId ?: (int)$reponse['session_id'];

    $resultat = correcterAvecIA($repId, $reponse['reponse_texte'], $reponse['question_texte'], (float)$reponse['points']);

    if (!$resultat['success']) throw new Exception($resultat['error']);

    $scoreData = updateSessionScore($sessionId);

    echo json_encode([
        'success'  => true,
        'points'   => $resultat['points'],
        'niveau'   => $resultat['niveau'],
        'feedback' => $resultat['feedback'],
        'score'    => $scoreData['score'],
        'total'    => $scoreData['total'],
        'pct'      => $scoreData['pct'],
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
