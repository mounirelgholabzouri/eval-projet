<?php
/**
 * Migration : création de la table etablissements
 * Insère l'établissement par défaut "ISTA HAY RIAD"
 */
require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();

// Vérifier si la table existe déjà
$tables = $pdo->query("SHOW TABLES LIKE 'etablissements'")->fetchAll();
if (!empty($tables)) {
    echo "Migration déjà appliquée — table etablissements existe.\n";
    exit(0);
}

$pdo->exec("
    CREATE TABLE `etablissements` (
        `id`         INT NOT NULL AUTO_INCREMENT,
        `nom`        VARCHAR(200) NOT NULL,
        `ville`      VARCHAR(100) DEFAULT '',
        `actif`      TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$stmt = $pdo->prepare("INSERT INTO etablissements (nom, ville, actif) VALUES (?, ?, 1)");
$stmt->execute(['ISTA HAY RIAD', 'Rabat']);

echo "Migration OK — table etablissements créée, ISTA HAY RIAD inséré (id=" . $pdo->lastInsertId() . ").\n";
