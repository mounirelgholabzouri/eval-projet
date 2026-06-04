<?php
/**
 * Redistribue les points des questions d'un module sur note_max,
 * en valeurs entières ou demi-entières (multiples de 0.5).
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$moduleId = (int)($_POST['module_id'] ?? 0);
if (!$moduleId) {
    echo json_encode(['error' => 'Module requis.']);
    exit;
}

$pdo = getDB();

// Récupère note_max depuis evaluations
$evalStmt = $pdo->prepare("SELECT note_max FROM evaluations WHERE module_id = ? ORDER BY id LIMIT 1");
$evalStmt->execute([$moduleId]);
$noteMax = (float)($evalStmt->fetchColumn() ?: 40);

// Récupère tous les IDs de questions du module
$qStmt = $pdo->prepare("SELECT id FROM questions WHERE module_id = ? ORDER BY partie_id, ordre, id");
$qStmt->execute([$moduleId]);
$questionIds = $qStmt->fetchAll(PDO::FETCH_COLUMN);

$n = count($questionIds);
if ($n === 0) {
    echo json_encode(['error' => 'Aucune question dans ce module.']);
    exit;
}

// Algorithme : base arrondie au demi-entier inférieur,
// les nExtra premières questions reçoivent +0.5 pour atteindre exactement noteMax.
$base      = $noteMax / $n;
$floorHalf = floor($base * 2) / 2;          // arrondi bas au 0.5 le plus proche
$remainder = round($noteMax - $floorHalf * $n, 4);
$nExtra    = (int)round($remainder / 0.5);  // nb de questions à +0.5

$pdo->beginTransaction();
try {
    foreach ($questionIds as $i => $qId) {
        $points = $floorHalf + ($i < $nExtra ? 0.5 : 0);
        $pdo->prepare("UPDATE questions SET points = ? WHERE id = ?")
            ->execute([$points, $qId]);
    }
    $pdo->commit();

    $lo   = $floorHalf;
    $hi   = $floorHalf + 0.5;
    $desc = $nExtra > 0
        ? "{$nExtra} × {$hi} + " . ($n - $nExtra) . " × {$lo}"
        : "{$n} × {$lo}";

    echo json_encode([
        'success'  => true,
        'note_max' => $noteMax,
        'nb'       => $n,
        'desc'     => $desc,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['error' => $e->getMessage()]);
}
