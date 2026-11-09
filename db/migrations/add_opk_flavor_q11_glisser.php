<?php
/**
 * Migration : type de question 'glisser_deposer' + Q11 OPK-FLAVOR
 * (commande complète `openstack flavor create` à reconstituer par glisser-déposer)
 */

require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

$marker = __DIR__ . '/../.done_add_opk_flavor_q11_glisser';
if (file_exists($marker)) {
    echo "Déjà exécuté.\n";
    exit(0);
}

// ── Type enum (DDL hors transaction : commit implicite en MySQL) ─────────────
$col = $pdo->query("SHOW COLUMNS FROM questions LIKE 'type'")->fetch();
if (strpos($col['Type'], "'glisser_deposer'") === false) {
    $pdo->exec("ALTER TABLE questions MODIFY type
                ENUM('qcm','vrai_faux','texte_libre','multiple','glisser_deposer') NOT NULL DEFAULT 'qcm'");
    echo "Type 'glisser_deposer' ajouté à questions.type\n";
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("SELECT id FROM modules WHERE nom = 'OPK-FLAVOR'");
    $stmt->execute();
    $moduleId = $stmt->fetchColumn();
    if (!$moduleId) {
        throw new RuntimeException("Module OPK-FLAVOR introuvable — exécuter import_opk_flavor.php d'abord.");
    }

    $stmt = $pdo->prepare("SELECT id FROM parties WHERE module_id = ? ORDER BY ordre, id LIMIT 1");
    $stmt->execute([$moduleId]);
    $partieId = $stmt->fetchColumn();

    $texte = "Reconstituez la commande OpenStack CLI complète qui crée le flavor public « m1.web » avec : "
           . "2 vCPU, 2048 Mo de RAM, disque racine de 20 Go, disque éphémère de 10 Go, swap de 512 Mo "
           . "et la propriété hw:cpu_cores=2.\n"
           . "---\n"
           . "openstack {{1}} {{2}} --id auto {{3}} 2 {{4}} 2048 {{5}} 20 {{6}} 10 {{7}} 512 {{8}} {{9}} hw:cpu_cores=2 m1.web";

    // Bonnes étiquettes : ordre = numéro du trou
    $bonnes = [
        1 => 'flavor',
        2 => 'create',
        3 => '--vcpus',
        4 => '--ram',
        5 => '--disk',
        6 => '--ephemeral',
        7 => '--swap',
        8 => '--public',
        9 => '--property',
    ];
    $leurres = ['image', 'set', '--cpu', '--memory', '--size', '--min-disk', '--visibility'];

    $stmt = $pdo->prepare("SELECT id FROM questions WHERE module_id = ? AND type = 'glisser_deposer'");
    $stmt->execute([$moduleId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
        $pdo->prepare("DELETE FROM choix_reponses WHERE question_id = ?")->execute([$oldId]);
        $pdo->prepare("DELETE FROM questions WHERE id = ?")->execute([$oldId]);
    }

    $pdo->prepare("INSERT INTO questions (module_id, partie_id, texte, type, points, ordre)
                   VALUES (?, ?, ?, 'glisser_deposer', 2.00, 11)")
        ->execute([$moduleId, $partieId, $texte]);
    $qId = $pdo->lastInsertId();

    $insC = $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)");
    foreach ($bonnes as $trou => $mot) {
        $insC->execute([$qId, $mot, 1, $trou]);
    }
    foreach ($leurres as $i => $mot) {
        $insC->execute([$qId, $mot, 0, 100 + $i]);
    }

    $pdo->prepare("UPDATE modules SET nb_questions_controle = 11, actif = 1 WHERE id = ?")->execute([$moduleId]);

    // Évaluation : c'est elle (et non le module seul) qui apparaît dans la liste des stagiaires
    $stmt = $pdo->prepare("SELECT id FROM evaluations WHERE module_id = ?");
    $stmt->execute([$moduleId]);
    if (!$stmt->fetchColumn()) {
        $pdo->prepare("INSERT INTO evaluations (module_id, nom, type, categorie, duree_minutes, note_max, nb_questions, actif)
                       VALUES (?, 'OPK-FLAVOR — Contrôle Flavors OpenStack', 'qcm', 'cc_theorique', 20, 20, NULL, 1)")
            ->execute([$moduleId]);
        echo "Évaluation créée (id=" . $pdo->lastInsertId() . ")\n";
    }

    $pdo->commit();
    file_put_contents($marker, date('Y-m-d H:i:s'));
    echo "Question 11 (glisser-déposer) ajoutée (id=$qId) — module OPK-FLAVOR : 11 questions.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ERREUR : " . $e->getMessage() . "\n";
    exit(1);
}
