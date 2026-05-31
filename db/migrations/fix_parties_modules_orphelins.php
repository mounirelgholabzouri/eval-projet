<?php
/**
 * Migration : créer les parties manquantes et rattacher les questions orphelines
 *
 * Modules concernés :
 *   47 — M206 EFM       (40 q avec partie_id=89 appartenant à module 32)
 *   42 — M105 DOCKER 1  (10 q avec partie_id=97 appartenant à module 43)
 */
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

$fixes = [
    47 => 'Général',
    42 => 'Général',
];

foreach ($fixes as $moduleId => $partieNom) {
    // Vérifier si une partie existe déjà pour ce module
    $existing = $pdo->prepare("SELECT id FROM parties WHERE module_id=? AND nom=? LIMIT 1");
    $existing->execute([$moduleId, $partieNom]);
    $partieId = $existing->fetchColumn();

    if (!$partieId) {
        $pdo->prepare("INSERT INTO parties (module_id, nom, ordre, actif) VALUES (?, ?, 1, 1)")
            ->execute([$moduleId, $partieNom]);
        $partieId = (int)$pdo->lastInsertId();
        echo "✓ Module $moduleId : partie '$partieNom' créée (id=$partieId)\n";
    } else {
        // S'assurer qu'elle est active
        $pdo->prepare("UPDATE parties SET actif=1 WHERE id=?")->execute([$partieId]);
        echo "✓ Module $moduleId : partie '$partieNom' existante (id=$partieId) — actif=1 forcé\n";
    }

    // Rattacher toutes les questions du module à cette nouvelle partie
    $stmt = $pdo->prepare("UPDATE questions SET partie_id=? WHERE module_id=?");
    $stmt->execute([$partieId, $moduleId]);
    echo "  → {$stmt->rowCount()} questions rattachées à la partie $partieId\n";
}

// Vérification finale
echo "\nVérification :\n";
$stmt = $pdo->query("
    SELECT m.id, m.nom,
           COUNT(DISTINCT p.id) AS nb_parties_actives,
           COUNT(q.id) AS nb_questions
    FROM modules m
    LEFT JOIN parties p ON p.module_id=m.id AND p.actif=1
    LEFT JOIN questions q ON q.module_id=m.id
    WHERE m.id IN (47, 42)
    GROUP BY m.id, m.nom
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  Module {$r['id']} — parties_actives={$r['nb_parties_actives']} — questions={$r['nb_questions']} — {$r['nom']}\n";
}
