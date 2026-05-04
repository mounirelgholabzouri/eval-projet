<?php
/**
 * Migration : ajout des colonnes de configuration multi-fournisseurs IA
 * Ajoute ai_provider, openai_api_key, google_api_key dans la table config.
 */
require_once __DIR__ . '/../config/database.php';

$pdo = getDB();

$defaults = [
    'ai_provider'    => 'anthropic',
    'openai_api_key' => '',
    'google_api_key' => '',
];

$stmt = $pdo->prepare("INSERT IGNORE INTO config (cle, valeur) VALUES (?, ?)");
foreach ($defaults as $cle => $valeur) {
    $stmt->execute([$cle, $valeur]);
    echo "OK : $cle\n";
}

echo "Migration multi-fournisseurs IA terminée.\n";
