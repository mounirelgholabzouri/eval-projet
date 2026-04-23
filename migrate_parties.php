<?php
require_once __DIR__ . '/config/database.php';
$pdo = getDB();

// 1. Créer table parties
$pdo->exec("
    CREATE TABLE IF NOT EXISTS parties (
        id INT AUTO_INCREMENT PRIMARY KEY,
        module_id INT NOT NULL,
        nom VARCHAR(200) NOT NULL,
        ordre INT DEFAULT 0,
        FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "Table parties OK\n";

// 2. Ajouter colonne partie_id dans questions (si absente)
$cols = $pdo->query("SHOW COLUMNS FROM questions LIKE 'partie_id'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE questions ADD COLUMN partie_id INT DEFAULT NULL AFTER module_id");
    $pdo->exec("ALTER TABLE questions ADD CONSTRAINT fk_question_partie FOREIGN KEY (partie_id) REFERENCES parties(id) ON DELETE SET NULL");
    echo "Colonne partie_id ajoutée OK\n";
} else {
    echo "Colonne partie_id déjà présente\n";
}

echo "Migration terminée.\n";
