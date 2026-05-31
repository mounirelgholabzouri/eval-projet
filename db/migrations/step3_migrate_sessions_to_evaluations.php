<?php
/**
 * Step 3 — Ajoute evaluation_id + questions_ids à sessions_eval.
 * Relie chaque session existante à l'évaluation créée pour son module_id.
 * Idempotent : n'écrase pas une valeur evaluation_id déjà renseignée.
 */
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

// ── 1. Ajouter la colonne evaluation_id si absente ──────────────────────────
$cols = $pdo->query("SHOW COLUMNS FROM sessions_eval")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('evaluation_id', $cols)) {
    // Ajout APRÈS module_id pour cohérence de lecture
    $pdo->exec("ALTER TABLE sessions_eval ADD COLUMN evaluation_id INT DEFAULT NULL AFTER module_id");
    echo "Colonne evaluation_id ajoutée.\n";
} else {
    echo "evaluation_id déjà présente.\n";
}

// ── 2. Ajouter la colonne questions_ids si absente ──────────────────────────
$cols = $pdo->query("SHOW COLUMNS FROM sessions_eval")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('questions_ids', $cols)) {
    $pdo->exec("ALTER TABLE sessions_eval ADD COLUMN questions_ids TEXT DEFAULT NULL AFTER evaluation_id");
    echo "Colonne questions_ids ajoutée.\n";
} else {
    echo "questions_ids déjà présente.\n";
}

// ── 3. Construire le mapping module_id → evaluation_id ──────────────────────
$map = [];
foreach ($pdo->query("SELECT id, module_id FROM evaluations")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    // Un module peut avoir plusieurs évaluations après migration → on prend la première créée
    if (!isset($map[$row['module_id']])) {
        $map[$row['module_id']] = $row['id'];
    }
}

// ── 4. Relier les sessions orphelines ───────────────────────────────────────
$sessions = $pdo->query("
    SELECT id, module_id FROM sessions_eval
    WHERE evaluation_id IS NULL AND module_id IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);

$update = $pdo->prepare("UPDATE sessions_eval SET evaluation_id = ? WHERE id = ?");

$ok = 0;
$miss = 0;

foreach ($sessions as $s) {
    if (!isset($map[$s['module_id']])) {
        echo "WARN — session #{$s['id']} : aucune évaluation pour module #{$s['module_id']}\n";
        $miss++;
        continue;
    }
    $update->execute([$map[$s['module_id']], $s['id']]);
    $ok++;
}

// ── 5. Ajouter la FK evaluation_id → evaluations.id (si pas encore là) ─────
$fkExists = $pdo->query("
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sessions_eval'
      AND CONSTRAINT_NAME = 'fk_session_evaluation'
")->fetchColumn();

if (!$fkExists) {
    $pdo->exec("
        ALTER TABLE sessions_eval
        ADD CONSTRAINT fk_session_evaluation
        FOREIGN KEY (evaluation_id) REFERENCES evaluations(id)
    ");
    echo "FK fk_session_evaluation ajoutée.\n";
}

echo "\nOK — $ok sessions reliées à leur évaluation. $miss non trouvées (voir WARN).\n";
