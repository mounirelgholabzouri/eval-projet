<?php
/**
 * Alignement du schéma local sur le dump remote :
 * - Ajoute les colonnes manquantes dans modules
 * - Crée modules_efm_meta si absente
 */
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

// 1. Colonnes manquantes dans modules
$existingCols = [];
foreach ($pdo->query("SHOW COLUMNS FROM modules")->fetchAll(PDO::FETCH_ASSOC) as $col) {
    $existingCols[] = $col['Field'];
}

$toAdd = [
    'nb_questions_controle' => "ALTER TABLE modules ADD COLUMN nb_questions_controle INT DEFAULT NULL COMMENT 'Nb questions tirées aléatoirement (NULL = toutes)' AFTER created_at",
    'type'                  => "ALTER TABLE modules ADD COLUMN `type` ENUM('qcm','efm','pratique','mixte') NOT NULL DEFAULT 'qcm' AFTER nb_questions_controle",
    'note_max'              => "ALTER TABLE modules ADD COLUMN note_max INT NOT NULL DEFAULT 20 AFTER `type`",
];

foreach ($toAdd as $col => $sql) {
    if (!in_array($col, $existingCols)) {
        $pdo->exec($sql);
        echo "✓ modules.$col ajouté\n";
    } else {
        echo "  modules.$col existe déjà\n";
    }
}

// 2. Table modules_efm_meta
$tables = $pdo->query("SHOW TABLES LIKE 'modules_efm_meta'")->fetchAll();
if (empty($tables)) {
    $pdo->exec("
        CREATE TABLE modules_efm_meta (
            id          INT NOT NULL AUTO_INCREMENT,
            module_id   INT NOT NULL,
            code_module VARCHAR(20)  DEFAULT NULL,
            filiere     VARCHAR(100) DEFAULT NULL,
            etablissement VARCHAR(100) DEFAULT NULL,
            annee       VARCHAR(10)  DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_module (module_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✓ Table modules_efm_meta créée\n";
} else {
    echo "  Table modules_efm_meta existe déjà\n";
}

echo "\nSchéma aligné.\n";
