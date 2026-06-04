<?php
/**
 * Migration EFM : ajoute les colonnes type et meta_json à la table modules.
 * À exécuter une seule fois via PHP CLI.
 */
require_once __DIR__ . '/../config/database.php';

$pdo = getDB();

$cols = $pdo->query("SHOW COLUMNS FROM modules")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('type', $cols)) {
    $pdo->exec("ALTER TABLE modules ADD COLUMN type VARCHAR(10) NOT NULL DEFAULT 'qcm' AFTER actif");
    echo "Colonne 'type' ajoutée.\n";
} else {
    echo "Colonne 'type' déjà présente.\n";
}

if (!in_array('meta_json', $cols)) {
    $pdo->exec("ALTER TABLE modules ADD COLUMN meta_json TEXT NULL AFTER type");
    echo "Colonne 'meta_json' ajoutée.\n";
} else {
    echo "Colonne 'meta_json' déjà présente.\n";
}

echo "Migration terminée.\n";
