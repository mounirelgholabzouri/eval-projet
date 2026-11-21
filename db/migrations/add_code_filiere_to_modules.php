<?php
/**
 * Ajoute les colonnes `code` et `filiere` à `modules` (absentes sur cette installation
 * mais requises par le code actuel — includes/functions.php::getAllModules()/moduleLibelle()).
 */
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

$existingCols = array_column($pdo->query("SHOW COLUMNS FROM modules")->fetchAll(PDO::FETCH_ASSOC), 'Field');

$toAdd = [
    'code'    => "ALTER TABLE modules ADD COLUMN `code` VARCHAR(20) DEFAULT NULL AFTER id",
    'filiere' => "ALTER TABLE modules ADD COLUMN `filiere` VARCHAR(20) DEFAULT NULL AFTER `code`",
];

foreach ($toAdd as $col => $sql) {
    if (!in_array($col, $existingCols, true)) {
        $pdo->exec($sql);
        echo "modules.$col ajouté\n";
    } else {
        echo "modules.$col existe déjà\n";
    }
}

echo "OK\n";
