<?php
require_once __DIR__ . '/../config/database.php';

$pdo = getDB();

try {
    // Afficher les questions orphelines
    $stmt = $pdo->query('SELECT COUNT(*) as total FROM questions WHERE partie_id IS NULL');
    $orphelines = (int)$stmt->fetchColumn();

    if ($orphelines === 0) {
        echo "✓ Aucune question orpheline trouvée.\n";
        exit(0);
    }

    echo "Correction de $orphelines questions orphelines...\n\n";

    // Pour chaque module avec questions orphelines
    $stmt = $pdo->query('
        SELECT DISTINCT m.id, m.nom
        FROM modules m
        JOIN questions q ON q.module_id = m.id
        WHERE q.partie_id IS NULL
        ORDER BY m.nom
    ');
    $modules = $stmt->fetchAll();

    foreach ($modules as $module) {
        // Récupérer ou créer la partie "Général" pour ce module
        $stmt = $pdo->prepare('
            SELECT id FROM parties
            WHERE module_id = ? AND nom = "Général"
            LIMIT 1
        ');
        $stmt->execute([$module['id']]);
        $partie = $stmt->fetch();

        if (!$partie) {
            // Créer la partie "Général" avec ordre 1
            $pdo->prepare('
                INSERT INTO parties (module_id, nom, ordre, actif)
                VALUES (?, "Général", 1, 1)
            ')->execute([$module['id']]);
            $partieId = (int)$pdo->lastInsertId();
            echo "✓ Partie créée pour {$module['nom']}\n";
        } else {
            $partieId = (int)$partie['id'];
        }

        // Affecter les questions orphelines à cette partie
        $stmt = $pdo->prepare('
            UPDATE questions
            SET partie_id = ?
            WHERE module_id = ? AND partie_id IS NULL
        ');
        $stmt->execute([$partieId, $module['id']]);
        $count = $stmt->rowCount();
        echo "  → $count questions affectées à 'Général'\n";
    }

    // Vérifier s'il reste des orphelines
    $stmt = $pdo->query('SELECT COUNT(*) FROM questions WHERE partie_id IS NULL');
    $remaining = (int)$stmt->fetchColumn();

    echo "\n✓ Migration complétée!\n";
    echo "  Questions restantes sans partie: $remaining\n";

} catch (Exception $e) {
    die("Erreur: " . $e->getMessage() . "\n");
}
