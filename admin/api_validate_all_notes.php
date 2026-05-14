<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $notes     = $_POST['notes'] ?? [];   // notes[rep_id] = points

    if ($sessionId <= 0) throw new Exception('ID session invalide');
    if (empty($notes))   throw new Exception('Aucune note à valider');

    $pdo = getDB();

    $validees = 0;
    foreach ($notes as $repId => $pts) {
        $repId = (int)$repId;
        $pts   = (float)$pts;
        if ($repId <= 0 || $pts < 0) continue;

        // Récupérer points_max de la question
        $stmtMax = $pdo->prepare("SELECT q.points FROM reponses_stagiaires rs JOIN questions q ON q.id = rs.question_id WHERE rs.id = ?");
        $stmtMax->execute([$repId]);
        $pointsMax = (float)($stmtMax->fetchColumn() ?? 0);

        $pts       = min($pts, $pointsMax);
        $isCorrect = $pts > 0 ? 1 : 0;

        $pdo->prepare("UPDATE reponses_stagiaires SET points_obtenus=?, is_correct=?, source_correction='manuel' WHERE id=?")
            ->execute([$pts, $isCorrect, $repId]);
        $validees++;
    }

    $scoreData = updateSessionScore($sessionId);

    echo json_encode([
        'success'  => true,
        'validees' => $validees,
        'score'    => $scoreData['score'],
        'total'    => $scoreData['total'],
        'pct'      => $scoreData['pct'],
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
