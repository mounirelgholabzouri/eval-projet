<?php
require_once __DIR__ . '/../config/database.php';

$pdo = getDB();

try {
    // Vérifier si la configuration existe déjà
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM config WHERE cle = 'ai_model'");
    $stmt->execute();

    if ($stmt->fetchColumn() === 0) {
        // Ajouter la configuration du modèle IA (Sonnet par défaut)
        $pdo->prepare("INSERT INTO config (cle, valeur) VALUES (?, ?)")
            ->execute(['ai_model', 'claude-sonnet-4-20250514']);
        echo "✓ Configuration du modèle IA ajoutée (Sonnet par défaut)\n";
    } else {
        echo "Configuration du modèle IA déjà présente.\n";
    }
} catch (Exception $e) {
    die("Erreur migration: " . $e->getMessage() . "\n");
}
