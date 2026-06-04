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

    // Colonnes de base (correction_ia_feedback comme marqueur de présence)
    $pdo->exec("ALTER TABLE reponses_stagiaires ADD COLUMN correction_ia_feedback LONGTEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL");
    $pdo->exec("ALTER TABLE reponses_stagiaires ADD COLUMN correction_ia_points_suggeres DECIMAL(5,2) DEFAULT NULL");
    $pdo->exec("ALTER TABLE reponses_stagiaires ADD COLUMN correction_ia_date TIMESTAMP NULL DEFAULT NULL");

    // Colonnes manquantes de la migration initiale
    $cols = array_column($pdo->query("SHOW COLUMNS FROM reponses_stagiaires")->fetchAll(), 'Field');
    if (!in_array('source_correction', $cols)) {
        $pdo->exec("ALTER TABLE reponses_stagiaires ADD COLUMN source_correction ENUM('ia','manuel') DEFAULT NULL AFTER is_correct");
        echo "✓ source_correction ajouté.\n";
    }
    if (!in_array('correction_ia_niveau', $cols)) {
        $pdo->exec("ALTER TABLE reponses_stagiaires ADD COLUMN correction_ia_niveau TINYINT DEFAULT NULL");
        echo "✓ correction_ia_niveau ajouté.\n";
    }

    echo "✓ Colonnes IA ajoutées avec succès.\n";
} catch (Exception $e) {
    die("Erreur migration: " . $e->getMessage() . "\n");
}
