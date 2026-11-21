<?php
/**
 * Phase 2 (DDL final) de refonte_cc_efm_local.php : supprime les colonnes/table
 * devenues inutiles une fois le nouveau schéma en place et vérifié.
 * Usage : php db/migrations/refonte_cc_efm_local_cleanup.php
 */
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

function hasColumn(PDO $pdo, string $table, string $col): bool {
    return in_array($col, array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field'), true);
}
function hasTable(PDO $pdo, string $table): bool {
    return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
}

if (!hasColumn($pdo, 'questions', 'partie_id')) {
    exit("Déjà nettoyé.\n");
}

$pdo->exec("ALTER TABLE questions DROP FOREIGN KEY fk_question_partie");
$pdo->exec("ALTER TABLE questions DROP INDEX idx_module_partie, DROP COLUMN partie_id, ADD INDEX idx_question_module (module_id)");
echo "questions.partie_id supprimé\n";

if (hasTable($pdo, 'parties')) {
    $pdo->exec("DROP TABLE parties");
    echo "Table parties supprimée\n";
}

$pdo->exec("ALTER TABLE evaluations DROP COLUMN categorie, DROP COLUMN parties_ids");
echo "evaluations.categorie / parties_ids supprimés\n";

if (hasColumn($pdo, 'modules', 'nb_questions_controle')) {
    $pdo->exec("ALTER TABLE modules DROP COLUMN nb_questions_controle");
    echo "modules.nb_questions_controle supprimé\n";
}

echo "Nettoyage terminé.\n";
