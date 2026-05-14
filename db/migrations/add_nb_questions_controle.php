<?php
/**
 * Migration : ajout nb_questions_controle sur modules + questions_ids sur sessions_eval
 */
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

// modules.nb_questions_controle
$cols = $pdo->query("SHOW COLUMNS FROM modules LIKE 'nb_questions_controle'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE modules ADD COLUMN nb_questions_controle INT NULL DEFAULT NULL COMMENT 'Nb questions tirées aléatoirement (NULL = toutes)'");
    echo "modules.nb_questions_controle ajouté\n";
} else {
    echo "modules.nb_questions_controle déjà présent\n";
}

// sessions_eval.questions_ids
$cols = $pdo->query("SHOW COLUMNS FROM sessions_eval LIKE 'questions_ids'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE sessions_eval ADD COLUMN questions_ids TEXT NULL DEFAULT NULL COMMENT 'JSON array des IDs questions tirés pour cette session'");
    echo "sessions_eval.questions_ids ajouté\n";
} else {
    echo "sessions_eval.questions_ids déjà présent\n";
}

// Valeur par défaut M205 = 20 questions
$pdo->exec("UPDATE modules SET nb_questions_controle = 20 WHERE nom = 'M205'");
echo "M205 : nb_questions_controle = 20\n";

echo "Migration terminée.\n";
