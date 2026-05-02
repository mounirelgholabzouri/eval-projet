<?php
require_once __DIR__ . '/../config/database.php';

$pdo = getDB();

try {
    // Vérifier si les colonnes existent déjà
    $stmt = $pdo->prepare("SHOW COLUMNS FROM reponses_stagiaires LIKE 'correction_ia_feedback'");
    $stmt->execute();

    if ($stmt->fetch()) {
        echo "Colonnes IA déjà présentes.\n";
        exit(0);
    }

    // Ajouter les colonnes pour la correction IA
    $pdo->exec("ALTER TABLE reponses_stagiaires ADD COLUMN correction_ia_feedback LONGTEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL");
    $pdo->exec("ALTER TABLE reponses_stagiaires ADD COLUMN correction_ia_points_suggeres DECIMAL(5,2) DEFAULT NULL");
    $pdo->exec("ALTER TABLE reponses_stagiaires ADD COLUMN correction_ia_date TIMESTAMP NULL DEFAULT NULL");

    echo "✓ Colonnes IA ajoutées avec succès.\n";
} catch (Exception $e) {
    die("Erreur migration: " . $e->getMessage() . "\n");
}
