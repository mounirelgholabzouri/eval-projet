<?php
/**
 * Migration : accès restreint aux évaluations (2026-11-25)
 *  Table evaluation_stagiaires (evaluation_id, stagiaire_id).
 *  Règle : une évaluation sans ligne ici est proposée à tous les stagiaires ;
 *          avec au moins une ligne, seuls les stagiaires listés la voient.
 *
 * Idempotente.
 */
require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$existe = $pdo->query("SHOW TABLES LIKE 'evaluation_stagiaires'")->fetchColumn();
if ($existe) {
    echo "= Table evaluation_stagiaires déjà présente\n";
} else {
    $pdo->exec("CREATE TABLE `evaluation_stagiaires` (
        `evaluation_id` int NOT NULL,
        `stagiaire_id`  int NOT NULL,
        PRIMARY KEY (`evaluation_id`, `stagiaire_id`),
        KEY `idx_es_stagiaire` (`stagiaire_id`),
        CONSTRAINT `fk_es_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `evaluations` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_es_stagiaire` FOREIGN KEY (`stagiaire_id`) REFERENCES `stagiaires` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✓ Table evaluation_stagiaires créée\n";
}
echo "Terminé.\n";
