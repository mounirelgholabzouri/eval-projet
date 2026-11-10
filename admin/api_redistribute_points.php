<?php
/**
 * Redistribue les points des questions d'une évaluation sur sa note_max,
 * en multiples de 0.5. Les questions partagées avec d'autres évaluations sont aussi modifiées.
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$eval = getEvaluation((int)($_POST['evaluation_id'] ?? 0));
if (!$eval) {
    echo json_encode(['error' => 'Évaluation requise.']);
    exit;
}

$noteMax     = (float)$eval['note_max'];
$questionIds = getQuestionIdsEvaluation((int)$eval['id']);
$n = count($questionIds);
if ($n === 0) {
    echo json_encode(['error' => 'Aucune question dans cette évaluation.']);
    exit;
}

// Base arrondie au demi-point inférieur ; les nExtra premières questions reçoivent +0.5.
$floorHalf = floor($noteMax / $n * 2) / 2;
$nExtra    = (int)round(($noteMax - $floorHalf * $n) / 0.5);

$pdo = getDB();
$pdo->beginTransaction();
try {
    $upd = $pdo->prepare("UPDATE questions SET points = ? WHERE id = ?");
    foreach ($questionIds as $i => $qId) {
        $upd->execute([$floorHalf + ($i < $nExtra ? 0.5 : 0), $qId]);
    }
    $pdo->commit();
    $hi = $floorHalf + 0.5;
    echo json_encode([
        'success'  => true,
        'note_max' => $noteMax,
        'nb'       => $n,
        'desc'     => $nExtra > 0 ? "{$nExtra} × {$hi} + " . ($n - $nExtra) . " × {$floorHalf}" : "{$n} × {$floorHalf}",
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['error' => $e->getMessage()]);
}
