<?php
/**
 * Migration : questions glisser-déposer OPK-FLAVOR issues du TP-IDOCC-M203-03
 * (commandes du TP Gabarits/Flavors — 5 nouvelles questions, sans doublon avec Q11)
 */

require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

$marker = __DIR__ . '/../.done_add_opk_flavor_glisser_tp_m203_03';
if (file_exists($marker)) {
    echo "Déjà exécuté.\n";
    exit(0);
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

    $stmt = $pdo->prepare("SELECT MAX(ordre) FROM questions WHERE module_id = ?");
    $stmt->execute([$moduleId]);
    $maxOrdre = (int) $stmt->fetchColumn();

    $insQ = $pdo->prepare(
        "INSERT INTO questions (module_id, partie_id, texte, type, points, ordre)
         VALUES (?, ?, ?, 'glisser_deposer', 2.00, ?)"
    );
    $insC = $pdo->prepare(
        "INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)"
    );

    $questions = [
        // ── Q12 : openstack flavor create --vcpus 1 --ram 128 --disk 1 m1.tp.nano ──
        [
            'texte' => "Reconstituez la commande CLI qui crée le gabarit « m1.tp.nano » :"
                     . " 1 vCPU, 128 Mo de RAM, disque de 1 Go.\n"
                     . "---\n"
                     . "openstack {{1}} {{2}} {{3}} 1 {{4}} 128 {{5}} 1 m1.tp.nano",
            'bonnes'  => [1 => 'flavor', 2 => 'create', 3 => '--vcpus', 4 => '--ram', 5 => '--disk'],
            'leurres' => ['image', '--memory', '--size', '--ephemeral', 'server', '--swap'],
        ],
        // ── Q13 : openstack limits show --absolute ────────────────────────────────
        [
            'texte' => "Reconstituez la commande CLI qui affiche les limites absolues"
                     . " du quota du projet courant (nb max vCPU, RAM, instances…).\n"
                     . "---\n"
                     . "openstack {{1}} {{2}} {{3}}",
            'bonnes'  => [1 => 'limits', 2 => 'show', 3 => '--absolute'],
            'leurres' => ['quota', 'flavor', '--all', '--project', 'server', '--verbose'],
        ],
        // ── Q14 : openstack image show cirros -c min_disk -c min_ram ─────────────
        [
            'texte' => "Reconstituez la commande CLI qui affiche uniquement les colonnes"
                     . " min_disk et min_ram de l'image cirros.\n"
                     . "---\n"
                     . "openstack {{1}} {{2}} cirros {{3}} min_disk {{4}} min_ram",
            'bonnes'  => [1 => 'image', 2 => 'show', 3 => '-c', 4 => '-c'],
            'leurres' => ['flavor', 'list', '--column', '--filter', '-f', 'min_vcpus'],
        ],
        // ── Q15 : openstack flavor create --private --vcpus 1 --ram 512 --disk 2 ─
        [
            'texte' => "Reconstituez la commande CLI qui crée le gabarit privé « m1.reserve »"
                     . " : 1 vCPU, 512 Mo de RAM, disque de 2 Go, non visible par les autres projets.\n"
                     . "---\n"
                     . "openstack {{1}} {{2}} {{3}} {{4}} 1 {{5}} 512 {{6}} 2 m1.reserve",
            'bonnes'  => [1 => 'flavor', 2 => 'create', 3 => '--private', 4 => '--vcpus', 5 => '--ram', 6 => '--disk'],
            'leurres' => ['image', '--public', '--vcpu', '--memory', '--size', '--swap'],
        ],
        // ── Q16 : openstack flavor set --project M203-<id> m1.reserve ────────────
        [
            'texte' => "Reconstituez la commande CLI qui restreint l'accès au gabarit"
                     . " « m1.reserve » au seul projet M203-<id>.\n"
                     . "---\n"
                     . "openstack {{1}} {{2}} {{3}} M203-<id> m1.reserve",
            'bonnes'  => [1 => 'flavor', 2 => 'set', 3 => '--project'],
            'leurres' => ['create', 'image', '--tenant', '--private', 'delete', '--property'],
        ],
    ];

    $added = [];
    foreach ($questions as $i => $q) {
        // Anti-doublon : ne pas insérer si un glisser_deposer identique existe déjà
        $check = $pdo->prepare(
            "SELECT id FROM questions WHERE module_id = ? AND type = 'glisser_deposer' AND texte = ?"
        );
        $check->execute([$moduleId, $q['texte']]);
        if ($check->fetchColumn()) {
            echo "Question " . ($maxOrdre + $i + 1) . " déjà présente — ignorée.\n";
            continue;
        }

        $insQ->execute([$moduleId, $partieId, $q['texte'], $maxOrdre + $i + 1]);
        $qId = (int) $pdo->lastInsertId();
        $added[] = $qId;

        foreach ($q['bonnes'] as $trou => $mot) {
            $insC->execute([$qId, $mot, 1, $trou]);
        }
        foreach ($q['leurres'] as $j => $mot) {
            $insC->execute([$qId, $mot, 0, 100 + $j]);
        }
        echo "Question glisser-déposer ajoutée (id=$qId, ordre=" . ($maxOrdre + $i + 1) . ").\n";
    }

    // Mettre à jour nb_questions_controle = total questions du module
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE module_id = ?");
    $stmt->execute([$moduleId]);
    $totalQ = (int) $stmt->fetchColumn();
    $pdo->prepare("UPDATE modules SET nb_questions_controle = ? WHERE id = ?")
        ->execute([$totalQ, $moduleId]);
    echo "nb_questions_controle mis à jour : $totalQ.\n";

    $pdo->commit();
    file_put_contents($marker, date('Y-m-d H:i:s'));
    echo "Migration terminée — " . count($added) . " question(s) ajoutée(s).\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ERREUR : " . $e->getMessage() . "\n";
    exit(1);
}
