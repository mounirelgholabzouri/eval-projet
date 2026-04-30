<?php
/**
 * Migration : optimisations schéma eval_online
 * - FK sessions_eval.stagiaire_id → stagiaires.id
 * - Index manquants (performance)
 * - modules.type → ENUM('qcm','efm')
 * - sessions_eval.token → CHAR(64)
 *
 * Safe à relancer : chaque opération vérifie l'état avant d'agir.
 * Exécuter via : php db/migrate_optimizations.php
 */

require_once __DIR__ . '/../config/database.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$ok   = 0;
$skip = 0;
$fail = 0;

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────

function indexExists(PDO $pdo, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE table_schema = DATABASE()
           AND table_name   = ?
           AND index_name   = ?"
    );
    $stmt->execute([$table, $indexName]);
    return (int) $stmt->fetchColumn() > 0;
}

function fkExists(PDO $pdo, string $table, string $fkName): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE table_schema    = DATABASE()
           AND table_name      = ?
           AND constraint_name = ?
           AND constraint_type = 'FOREIGN KEY'"
    );
    $stmt->execute([$table, $fkName]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnType(PDO $pdo, string $table, string $column): string
{
    $stmt = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE table_schema = DATABASE()
           AND table_name   = ?
           AND column_name  = ?"
    );
    $stmt->execute([$table, $column]);
    return (string) $stmt->fetchColumn();
}

function run(PDO $pdo, string $label, string $sql, bool $skip): void
{
    global $ok, $fail;
    if ($skip) {
        echo "  [SKIP]  $label\n";
        $GLOBALS['skip']++;
        return;
    }
    try {
        $pdo->exec($sql);
        echo "  [OK]    $label\n";
        $ok++;
    } catch (PDOException $e) {
        echo "  [FAIL]  $label\n         " . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "\n=== Migration : optimisations schéma eval_online ===\n\n";

// ─────────────────────────────────────────────────────────────
// 1. Clé étrangère sessions_eval.stagiaire_id → stagiaires.id
// ─────────────────────────────────────────────────────────────
echo "── 1. Clé étrangère stagiaire_id ──\n";

// Nettoyer les sessions orphelines avant d'ajouter la FK
$orphans = $pdo->query(
    "SELECT COUNT(*) FROM sessions_eval se
     WHERE se.stagiaire_id IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM stagiaires s WHERE s.id = se.stagiaire_id)"
)->fetchColumn();

if ($orphans > 0) {
    echo "  [WARN]  $orphans session(s) avec stagiaire_id orphelin détectée(s).\n";
    echo "          Mise à NULL avant ajout FK...\n";
    $pdo->exec(
        "UPDATE sessions_eval se
         SET se.stagiaire_id = NULL
         WHERE se.stagiaire_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM stagiaires s WHERE s.id = se.stagiaire_id)"
    );
    echo "  [OK]    Orphelins nettoyés.\n";
    $ok++;
}

run(
    $pdo,
    'FK fk_session_stagiaire (sessions_eval → stagiaires ON DELETE SET NULL)',
    "ALTER TABLE sessions_eval
     ADD CONSTRAINT fk_session_stagiaire
     FOREIGN KEY (stagiaire_id) REFERENCES stagiaires(id) ON DELETE SET NULL",
    fkExists($pdo, 'sessions_eval', 'fk_session_stagiaire')
);

// ─────────────────────────────────────────────────────────────
// 2. Index manquants — sessions_eval
// ─────────────────────────────────────────────────────────────
echo "\n── 2. Index sessions_eval ──\n";

run(
    $pdo,
    'INDEX idx_stagiaire (sessions_eval.stagiaire_id)',
    "ALTER TABLE sessions_eval ADD INDEX idx_stagiaire (stagiaire_id)",
    indexExists($pdo, 'sessions_eval', 'idx_stagiaire')
);

run(
    $pdo,
    'INDEX idx_module_statut (sessions_eval.module_id, statut)',
    "ALTER TABLE sessions_eval ADD INDEX idx_module_statut (module_id, statut)",
    indexExists($pdo, 'sessions_eval', 'idx_module_statut')
);

// ─────────────────────────────────────────────────────────────
// 3. Index manquants — modules
// ─────────────────────────────────────────────────────────────
echo "\n── 3. Index modules ──\n";

run(
    $pdo,
    'INDEX idx_type_actif (modules.type, actif)',
    "ALTER TABLE modules ADD INDEX idx_type_actif (type, actif)",
    indexExists($pdo, 'modules', 'idx_type_actif')
);

// ─────────────────────────────────────────────────────────────
// 4. Index manquants — reponses_stagiaires
// ─────────────────────────────────────────────────────────────
echo "\n── 4. Index reponses_stagiaires ──\n";

run(
    $pdo,
    'INDEX idx_session_question (reponses_stagiaires.session_id, question_id)',
    "ALTER TABLE reponses_stagiaires ADD INDEX idx_session_question (session_id, question_id)",
    indexExists($pdo, 'reponses_stagiaires', 'idx_session_question')
);

run(
    $pdo,
    'INDEX idx_session_question_correct (session_id, question_id, is_correct)',
    "ALTER TABLE reponses_stagiaires ADD INDEX idx_session_question_correct (session_id, question_id, is_correct)",
    indexExists($pdo, 'reponses_stagiaires', 'idx_session_question_correct')
);

// ─────────────────────────────────────────────────────────────
// 5. modules.type → ENUM('qcm','efm')
// ─────────────────────────────────────────────────────────────
echo "\n── 5. modules.type → ENUM ──\n";

$typeCol = columnType($pdo, 'modules', 'type');
$alreadyEnum = stripos($typeCol, 'enum') !== false;

run(
    $pdo,
    "modules.type VARCHAR(10) → ENUM('qcm','efm') [actuel: $typeCol]",
    "ALTER TABLE modules MODIFY type ENUM('qcm','efm') NOT NULL DEFAULT 'qcm'",
    $alreadyEnum
);

// ─────────────────────────────────────────────────────────────
// 6. sessions_eval.token → CHAR(64)
// ─────────────────────────────────────────────────────────────
echo "\n── 6. sessions_eval.token → CHAR(64) ──\n";

$tokenCol    = columnType($pdo, 'sessions_eval', 'token');
$alreadyChar = stripos($tokenCol, 'char(64)') !== false && stripos($tokenCol, 'varchar') === false;

run(
    $pdo,
    "sessions_eval.token VARCHAR(64) → CHAR(64) [actuel: $tokenCol]",
    "ALTER TABLE sessions_eval MODIFY token CHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL",
    $alreadyChar
);

// ─────────────────────────────────────────────────────────────
// Résumé
// ─────────────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 50) . "\n";
echo "  Résultat : {$ok} OK  |  {$skip} déjà en place  |  {$fail} erreur(s)\n";
echo str_repeat('─', 50) . "\n\n";

if ($fail > 0) {
    echo "  Des erreurs sont survenues. Vérifiez les messages ci-dessus.\n\n";
    exit(1);
}

echo "  Migration terminée avec succès.\n\n";
