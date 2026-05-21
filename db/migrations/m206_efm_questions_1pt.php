<?php
/**
 * Migration : M206 EFM (module 47) — toutes les questions à 1 pt
 * Total : 40 questions × 1 pt = 40 pts = note_max → score brut = note /40
 */
$pdo = new PDO('mysql:host=127.0.0.1;dbname=eval_online;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$stmt = $pdo->prepare('UPDATE questions SET points=1 WHERE module_id=47');
$stmt->execute();
echo "✓ {$stmt->rowCount()} questions mises à 1 pt\n";

$r = $pdo->query('SELECT COUNT(*) AS nb, SUM(points) AS total FROM questions WHERE module_id=47')->fetch(PDO::FETCH_ASSOC);
echo "✓ Total : {$r['nb']} questions × 1 pt = {$r['total']} pts (= note_max 40)\n";
