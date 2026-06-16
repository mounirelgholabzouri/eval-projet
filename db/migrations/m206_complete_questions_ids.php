<?php
/**
 * Migration : compléter questions_ids des sessions M206 jusqu'à 20 questions.
 *
 * - Récupère les IDs répondus pour chaque session sans questions_ids
 * - Complète aléatoirement jusqu'à 20 avec des questions du module non encore choisies
 * - Si déjà ≥ 20 réponses : garde les 20 premières (par ordre)
 * - Sauvegarde dans sessions_eval.questions_ids
 */

require_once __DIR__ . '/../../includes/functions.php';

$pdo = getDB();
$moduleId = 32; // M206 – Gouverner les données dans le Cloud
$target   = 20;

// ── Toutes les questions du module, indexées par id ──────────────
$stmtQ = $pdo->prepare("SELECT id FROM questions WHERE module_id = ? ORDER BY ordre, id");
$stmtQ->execute([$moduleId]);
$allQIds = $stmtQ->fetchAll(PDO::FETCH_COLUMN); // tableau d'IDs dans l'ordre

// ── Sessions M206 sans questions_ids ─────────────────────────────
$stmtS = $pdo->prepare("
    SELECT s.id, s.nom, s.prenom
    FROM sessions_eval s
    JOIN evaluations e ON e.id = s.evaluation_id
    WHERE e.module_id = ? AND s.questions_ids IS NULL AND s.statut = 'termine'
");
$stmtS->execute([$moduleId]);
$sessions = $stmtS->fetchAll();

if (empty($sessions)) {
    echo "Aucune session à migrer.\n";
    exit;
}

$updateStmt = $pdo->prepare("UPDATE sessions_eval SET questions_ids = ? WHERE id = ?");
$count = 0;

foreach ($sessions as $session) {
    $sid = (int)$session['id'];

    // IDs des questions répondues (dans l'ordre du module)
    $stmtR = $pdo->prepare("
        SELECT q.id FROM reponses_stagiaires rs
        JOIN questions q ON q.id = rs.question_id
        WHERE rs.session_id = ? ORDER BY q.ordre, q.id
    ");
    $stmtR->execute([$sid]);
    $answeredIds = $stmtR->fetchAll(PDO::FETCH_COLUMN);
    $answeredIds = array_map('intval', $answeredIds);

    if (count($answeredIds) >= $target) {
        // Déjà ≥ 20 réponses : garder les 20 premières (ordre module)
        $chosenIds = array_slice($answeredIds, 0, $target);
    } else {
        // Compléter aléatoirement avec questions non répondues
        $remaining = array_values(array_diff($allQIds, $answeredIds));
        shuffle($remaining);
        $needed    = $target - count($answeredIds);
        $extras    = array_slice($remaining, 0, $needed);

        // Fusionner et retrier dans l'ordre du module
        $merged = array_merge($answeredIds, $extras);
        usort($merged, fn($a, $b) => array_search($a, $allQIds) <=> array_search($b, $allQIds));
        $chosenIds = $merged;
    }

    $updateStmt->execute([json_encode($chosenIds), $sid]);
    $count++;
    $label = strtoupper($session['nom']) . ' ' . $session['prenom'];
    echo "Session #{$sid} {$label} → " . count($answeredIds) . " répondues + " . (count($chosenIds) - count($answeredIds)) . " ajoutées = " . count($chosenIds) . " questions\n";
}

echo "\n✅ {$count} session(s) migrée(s).\n";
