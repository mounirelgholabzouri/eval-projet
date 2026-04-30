<?php
require_once __DIR__ . '/config/database.php';
$pdo = getDB();

$cols = $pdo->query("SHOW COLUMNS FROM parties LIKE 'actif'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE parties ADD COLUMN actif TINYINT(1) NOT NULL DEFAULT 1 AFTER ordre");
    echo "Colonne 'actif' ajoutée à la table parties.\n";
} else {
    echo "Colonne 'actif' existe déjà.\n";
}
