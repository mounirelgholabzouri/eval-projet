<?php
/**
 * Restaure l'encodage correct depuis eval_restore_tmp vers eval_online.
 * Pré-requis : eval_restore_tmp doit exister (import fait via Bash).
 */

require_once __DIR__ . '/../config/database.php';

$tmpDb = 'eval_restore_tmp';
$pdo   = getDB();

// Vérifier que la DB temp existe
try {
    $tmpPdo = new PDO(
        "mysql:host=127.0.0.1;dbname=$tmpDb;charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $count = $tmpPdo->query("SELECT COUNT(*) FROM questions")->fetchColumn();
    echo "DB temporaire OK — $count questions disponibles\n";
} catch (PDOException $e) {
    die("DB temporaire introuvable. Lancer l'import d'abord.\nErreur : " . $e->getMessage() . "\n");
}

// Tables à synchroniser : [table => colonnes à mettre à jour]
$tables = [
    'modules' => ['nom', 'description', 'duree_minutes', 'actif', 'type'],
    'parties' => ['module_id', 'nom', 'ordre', 'actif'],
    'questions' => ['module_id', 'partie_id', 'texte', 'type', 'points', 'ordre'],
    'choix_reponses' => ['question_id', 'texte', 'is_correct', 'ordre'],
    'modules_efm_meta' => ['module_id', 'code_module', 'filiere', 'etablissement', 'annee'],
];

$pdo->exec("SET NAMES utf8mb4");
$tmpPdo->exec("SET NAMES utf8mb4");

foreach ($tables as $table => $cols) {
    echo "\nTable `$table`... ";

    try {
        $rows = $tmpPdo->query("SELECT * FROM `$table`")->fetchAll();
    } catch (PDOException $e) {
        echo "absente du dump, ignorée.\n";
        continue;
    }

    $sets  = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
    $stmt  = $pdo->prepare("UPDATE `eval_online`.`$table` SET $sets WHERE id = ?");
    $updated = 0;

    foreach ($rows as $row) {
        $vals  = array_map(fn($c) => $row[$c] ?? null, $cols);
        $vals[] = $row['id'];
        $stmt->execute($vals);
        if ($stmt->rowCount() > 0) $updated++;
    }

    echo "mis à jour : $updated / " . count($rows) . "\n";
}

// Vérification rapide
echo "\n--- Vérification ---\n";
$sample = $pdo->query("SELECT id, texte FROM questions WHERE id IN (7,8,9)")->fetchAll();
foreach ($sample as $r) {
    echo "ID " . $r['id'] . ": " . $r['texte'] . "\n";
}

// Nettoyage
echo "\nSuppression DB temporaire...\n";
$pdo->exec("DROP DATABASE IF EXISTS `$tmpDb`");

echo "\n✓ Restauration terminée.\n";
