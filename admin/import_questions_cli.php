<?php
// CLI uniquement — importe un JSON de questions sans conflit d'IDs
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

$jsonFile = $argv[1] ?? null;
if (!$jsonFile || !file_exists($jsonFile)) {
    echo "Usage: php import_questions_cli.php <questions.json>\n";
    exit(1);
}

require_once __DIR__ . '/../includes/functions.php';
$pdo  = getDB();
$data = json_decode(file_get_contents($jsonFile), true);

if (!$data || !isset($data['questions'])) {
    echo "Erreur : JSON invalide.\n";
    exit(1);
}

echo "Source : {$data['machine']} ({$data['exported_at']})\n";
echo "Questions dans le fichier : {$data['nb']}\n";

$nbImporte = 0;
$nbSkip    = 0;

$pdo->beginTransaction();
try {
    foreach ($data['questions'] as $q) {
        // Trouver ou créer le module
        $stmtM = $pdo->prepare("SELECT id FROM modules WHERE nom = ?");
        $stmtM->execute([$q['module_nom']]);
        $moduleId = $stmtM->fetchColumn();
        if (!$moduleId) {
            $pdo->prepare("INSERT INTO modules (nom, actif) VALUES (?, 1)")
                ->execute([$q['module_nom']]);
            $moduleId = $pdo->lastInsertId();
        }

        // Trouver ou créer la partie
        $stmtP = $pdo->prepare("SELECT id FROM parties WHERE module_id = ? AND nom = ?");
        $stmtP->execute([$moduleId, $q['partie_nom']]);
        $partieId = $stmtP->fetchColumn();
        if (!$partieId) {
            $pdo->prepare("INSERT INTO parties (module_id, nom, ordre, actif) VALUES (?, ?, ?, 1)")
                ->execute([$moduleId, $q['partie_nom'], $q['partie_ordre']]);
            $partieId = $pdo->lastInsertId();
        }

        // Vérifier doublon (même texte dans même partie)
        $stmtQ = $pdo->prepare("SELECT id FROM questions WHERE partie_id = ? AND texte = ?");
        $stmtQ->execute([$partieId, $q['texte']]);
        if ($stmtQ->fetchColumn()) {
            $nbSkip++;
            continue;
        }

        // Insérer la question avec nouveau ID local
        $pdo->prepare("INSERT INTO questions (module_id, partie_id, texte, type, points, ordre, image_path)
                       VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$moduleId, $partieId, $q['texte'], $q['type'], $q['points'], $q['ordre'], $q['image_path'] ?? null]);
        $questionId = $pdo->lastInsertId();

        foreach ($q['choix'] ?? [] as $c) {
            $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)")
                ->execute([$questionId, $c['texte'], (int)$c['is_correct'], (int)$c['ordre']]);
        }
        $nbImporte++;
    }
    $pdo->commit();
    echo "Importées  : $nbImporte\n";
    echo "Ignorées   : $nbSkip (déjà présentes)\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Erreur : " . $e->getMessage() . "\n";
    exit(1);
}
