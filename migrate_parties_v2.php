<?php
/**
 * Migration v2 — Normalisation du modèle parties
 * Objectifs :
 *  1. Chaque module a au moins une partie (crée "Général" si aucune)
 *  2. Chaque question a une partie (assigne à "Général" si NULL)
 *  3. questions.partie_id devient NOT NULL
 *  4. Index composite (module_id, partie_id) pour perf
 */
require_once __DIR__ . '/config/database.php';
$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Migration parties v2 ===\n\n";

// 1. Chaque module doit avoir au moins une partie
$modules = $pdo->query("SELECT id, nom FROM modules")->fetchAll();
$created = 0;
foreach ($modules as $m) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM parties WHERE module_id = ?");
    $stmt->execute([$m['id']]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->prepare("INSERT INTO parties (module_id, nom, ordre) VALUES (?, 'Général', 1)")
            ->execute([$m['id']]);
        $created++;
    }
}
echo "Parties « Général » créées : $created\n";

// 2. Assigner les questions orphelines à la partie "Général" de leur module
$orphans = $pdo->query("SELECT id, module_id FROM questions WHERE partie_id IS NULL")->fetchAll();
$assigned = 0;
foreach ($orphans as $q) {
    // Récupérer la première partie du module (idéalement "Général")
    $stmt = $pdo->prepare("SELECT id FROM parties WHERE module_id = ? ORDER BY ordre, id LIMIT 1");
    $stmt->execute([$q['module_id']]);
    $partieId = $stmt->fetchColumn();
    if ($partieId) {
        $pdo->prepare("UPDATE questions SET partie_id = ? WHERE id = ?")
            ->execute([$partieId, $q['id']]);
        $assigned++;
    }
}
echo "Questions orphelines assignées : $assigned\n";

// 3. Recréer la FK en ON DELETE RESTRICT + passer partie_id NOT NULL
try {
    $fkExists = $pdo->query("
        SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'questions'
          AND CONSTRAINT_NAME = 'fk_question_partie'
    ")->fetchColumn();
    if ($fkExists) {
        $pdo->exec("ALTER TABLE questions DROP FOREIGN KEY fk_question_partie");
        echo "FK fk_question_partie (SET NULL) supprimée\n";
    }
    $pdo->exec("ALTER TABLE questions MODIFY partie_id INT NOT NULL");
    echo "partie_id passé NOT NULL : OK\n";
    $pdo->exec("ALTER TABLE questions ADD CONSTRAINT fk_question_partie FOREIGN KEY (partie_id) REFERENCES parties(id) ON DELETE RESTRICT");
    echo "FK fk_question_partie (RESTRICT) recréée\n";
} catch (PDOException $e) {
    echo "ERREUR FK/NOT NULL : " . $e->getMessage() . "\n";
}

// 4. Index composite (supprime si existe, recrée)
try {
    $existing = $pdo->query("SHOW INDEX FROM questions WHERE Key_name = 'idx_module_partie'")->fetchAll();
    if (empty($existing)) {
        $pdo->exec("CREATE INDEX idx_module_partie ON questions (module_id, partie_id)");
        echo "Index idx_module_partie créé\n";
    } else {
        echo "Index idx_module_partie déjà présent\n";
    }
} catch (PDOException $e) {
    echo "Index : " . $e->getMessage() . "\n";
}

// Vérification finale
$nullCount = $pdo->query("SELECT COUNT(*) FROM questions WHERE partie_id IS NULL")->fetchColumn();
$modSansPartie = $pdo->query("
    SELECT COUNT(*) FROM modules m
    WHERE NOT EXISTS (SELECT 1 FROM parties p WHERE p.module_id = m.id)
")->fetchColumn();

echo "\n=== Vérifications ===\n";
echo "Questions sans partie_id : $nullCount (doit être 0)\n";
echo "Modules sans partie : $modSansPartie (doit être 0)\n";
echo "\nMigration terminée.\n";
