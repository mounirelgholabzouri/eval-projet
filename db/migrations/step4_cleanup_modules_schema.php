<?php
/**
 * Step 4 — Nettoyage du schéma.
 * - Supprime de modules les colonnes déplacées vers evaluations
 * - Supprime module_id de sessions_eval (remplacé par evaluation_id)
 * - Supprime modules_efm_meta (absorbé dans evaluations.meta_json)
 * - Supprime eval_pratique / eval_pratique_parties / eval_pratique_questions
 *
 * ATTENTION : irréversible. Vérifier que steps 1-3 sont OK avant d'exécuter.
 */
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

// ── Garde-fous ──────────────────────────────────────────────────────────────

// Toutes les sessions doivent avoir un evaluation_id
$orphans = $pdo->query("SELECT COUNT(*) FROM sessions_eval WHERE evaluation_id IS NULL")->fetchColumn();
if ($orphans > 0) {
    echo "ARRÊT — $orphans sessions sans evaluation_id. Lancer step3 d'abord.\n";
    exit(1);
}

// L'évaluation couvre bien tous les modules référencés par des sessions
$uncovered = $pdo->query("
    SELECT COUNT(*) FROM sessions_eval s
    LEFT JOIN evaluations e ON e.id = s.evaluation_id
    WHERE e.id IS NULL
")->fetchColumn();
if ($uncovered > 0) {
    echo "ARRÊT — $uncovered sessions référencent une évaluation inexistante.\n";
    exit(1);
}

echo "Garde-fous OK — démarrage du nettoyage.\n\n";

// ── 1. Colonnes à supprimer de modules ──────────────────────────────────────
$moduleCols = $pdo->query("SHOW COLUMNS FROM modules")->fetchAll(PDO::FETCH_COLUMN);
$toDrop = ['duree_minutes', 'note_max', 'type', 'meta_json'];
foreach ($toDrop as $col) {
    if (in_array($col, $moduleCols)) {
        $pdo->exec("ALTER TABLE modules DROP COLUMN `$col`");
        echo "modules.{$col} supprimé.\n";
    } else {
        echo "modules.{$col} déjà absent.\n";
    }
}

// ── 2. Supprimer module_id de sessions_eval ──────────────────────────────────
// D'abord supprimer la FK existante
$fkName = $pdo->query("
    SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sessions_eval'
      AND COLUMN_NAME = 'module_id'
      AND REFERENCED_TABLE_NAME = 'modules'
    LIMIT 1
")->fetchColumn();

if ($fkName) {
    $pdo->exec("ALTER TABLE sessions_eval DROP FOREIGN KEY `$fkName`");
    echo "FK $fkName supprimée.\n";
}

$sesCols = $pdo->query("SHOW COLUMNS FROM sessions_eval")->fetchAll(PDO::FETCH_COLUMN);
if (in_array('module_id', $sesCols)) {
    $pdo->exec("ALTER TABLE sessions_eval DROP COLUMN module_id");
    echo "sessions_eval.module_id supprimé.\n";
} else {
    echo "sessions_eval.module_id déjà absent.\n";
}

// ── 3. Supprimer modules_efm_meta (absorbé dans evaluations.meta_json) ───────
$t = $pdo->query("SHOW TABLES LIKE 'modules_efm_meta'")->fetchColumn();
if ($t) {
    $pdo->exec("DROP TABLE modules_efm_meta");
    echo "Table modules_efm_meta supprimée.\n";
} else {
    echo "modules_efm_meta déjà absente.\n";
}

// ── 4. Supprimer eval_pratique (absorbé dans evaluations type='pratique') ────
foreach (['eval_pratique_questions', 'eval_pratique_parties', 'eval_pratique'] as $table) {
    $t = $pdo->query("SHOW TABLES LIKE '$table'")->fetchColumn();
    if ($t) {
        $pdo->exec("DROP TABLE `$table`");
        echo "Table $table supprimée.\n";
    } else {
        echo "$table déjà absente.\n";
    }
}

echo "\nOK — nettoyage terminé.\n";
