<?php
/**
 * Migration : ajout de la config Ollama
 */
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

$inserts = [
    ['ollama_base_url', 'http://ollama:11434'],
];

foreach ($inserts as [$cle, $valeur]) {
    $pdo->prepare("INSERT IGNORE INTO config (cle, valeur) VALUES (?, ?)")
        ->execute([$cle, $valeur]);
    echo "  — $cle\n";
}

echo "✅ Migration Ollama OK\n";
