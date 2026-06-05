<?php
/**
 * Import filtré du dump : skip tables locales, REPLACE INTO pour les autres.
 * Tables protégées : sessions_eval, reponses_stagiaires, stagiaires, config
 */
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

$SKIP_TABLES = ['sessions_eval', 'reponses_stagiaires', 'stagiaires', 'config'];
$dumpFile = __DIR__ . '/dump_eval_online.sql';

if (!file_exists($dumpFile)) {
    die("ERREUR : fichier introuvable : $dumpFile\n");
}

$sql = file_get_contents($dumpFile);

// Séparer en blocs par table
// Chaque bloc commence par "-- Table structure for table `nom`"
$blocks = preg_split('/(?=-- Table structure for table `)/u', $sql);

$imported = [];
$skipped  = [];

foreach ($blocks as $block) {
    if (trim($block) === '') continue;

    // Extraire le nom de table
    if (!preg_match('/-- Table structure for table `([^`]+)`/u', $block, $m)) {
        // Bloc de tête (header du dump) — on l'exécute sauf les SET NAMES / commentaires
        $lines = explode("\n", $block);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '--')) continue;
            if (str_starts_with($line, 'SET ') || str_starts_with($line, '/*!')) {
                try { $pdo->exec($line); } catch (Exception $e) { /* ignore */ }
            }
        }
        continue;
    }

    $table = $m[1];

    if (in_array($table, $SKIP_TABLES)) {
        $skipped[] = $table;
        continue;
    }

    // Remplacer INSERT INTO par REPLACE INTO pour que le nouveau gagne
    $block = str_replace(
        "INSERT INTO `$table`",
        "REPLACE INTO `$table`",
        $block
    );

    // Exécuter chaque statement du bloc
    // On split sur ";\n" mais il faut gérer les valeurs multi-lignes
    $statements = preg_split('/;\s*\n/u', $block);
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || str_starts_with($stmt, '--')) continue;
        // Ignorer les commentaires conditionnels /*!40... */; déjà exécutés via SET
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            // Afficher mais continuer
            echo "  [WARN] $table : " . $e->getMessage() . "\n";
        }
    }

    $imported[] = $table;
    echo "✓ $table importée (REPLACE INTO)\n";
}

echo "\n--- Résumé ---\n";
echo "Importées : " . implode(', ', $imported) . "\n";
echo "Ignorées  : " . implode(', ', $skipped) . " (données locales préservées)\n";
