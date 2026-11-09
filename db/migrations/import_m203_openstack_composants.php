<?php
/**
 * Migration : Import QCM M203 OpenStack - Composants et architecture
 * Source : exports/qcm_m203_openstack_composants.json
 */

require_once __DIR__ . '/../../includes/functions.php';

$pdo = getDB();

$jsonPath = __DIR__ . '/../../exports/qcm_m203_openstack_composants.json';
if (!file_exists($jsonPath)) {
    die("ERREUR : fichier JSON introuvable : $jsonPath\n");
}

$raw  = file_get_contents($jsonPath);
$raw  = ltrim($raw, "\xEF\xBB\xBF"); // retirer BOM UTF-8 si présent
$data = json_decode($raw, true);

if (!$data || !isset($data['questions'])) {
    die("ERREUR : JSON invalide.\n");
}

$moduleNom = $data['module'] ?? 'M203 Déployer une infrastructure Cloud Computing';
$partieNom = $data['titre']  ?? 'Composants et architecture OpenStack';

$pdo->beginTransaction();
try {
    // Module
    $stmt = $pdo->prepare("SELECT id FROM modules WHERE nom = ?");
    $stmt->execute([$moduleNom]);
    $moduleId = $stmt->fetchColumn();
    if (!$moduleId) {
        $pdo->prepare("INSERT INTO modules (nom, actif, nb_questions_controle) VALUES (?, 1, 20)")
            ->execute([$moduleNom]);
        $moduleId = (int)$pdo->lastInsertId();
        echo "Module créé : #$moduleId — $moduleNom\n";
    } else {
        echo "Module existant : #$moduleId — $moduleNom\n";
    }

    // Évaluation (sans elle, le module n'apparaît pas côté stagiaire)
    $stmtE = $pdo->prepare("SELECT id FROM evaluations WHERE module_id = ?");
    $stmtE->execute([$moduleId]);
    if (!$stmtE->fetchColumn()) {
        $pdo->prepare("INSERT INTO evaluations (module_id, nom, type, duree_minutes, note_max, actif) VALUES (?, ?, 'qcm', 30, 20, 1)")
            ->execute([$moduleId, $moduleNom]);
        echo "Évaluation créée : #" . $pdo->lastInsertId() . "\n";
    }

    // Partie
    $stmtP = $pdo->prepare("SELECT id FROM parties WHERE module_id = ? AND nom = ?");
    $stmtP->execute([$moduleId, $partieNom]);
    $partieId = $stmtP->fetchColumn();
    if (!$partieId) {
        $stmtOrd = $pdo->prepare("SELECT COALESCE(MAX(ordre),0)+1 FROM parties WHERE module_id = ?");
        $stmtOrd->execute([$moduleId]);
        $ordreP = (int)$stmtOrd->fetchColumn();
        $pdo->prepare("INSERT INTO parties (module_id, nom, ordre, actif) VALUES (?, ?, ?, 1)")
            ->execute([$moduleId, $partieNom, $ordreP]);
        $partieId = (int)$pdo->lastInsertId();
        echo "Partie créée : #$partieId — $partieNom\n";
    } else {
        echo "Partie existante : #$partieId\n";
    }

    // Questions
    $nbImporte = 0;
    $nbSkip    = 0;

    foreach ($data['questions'] as $idx => $q) {
        $texte  = trim($q['enonce'] ?? '');
        $points = (float)($q['points'] ?? 1);
        $rawType = strtolower(trim($q['type'] ?? 'qcm'));
        $type = match(true) {
            str_contains($rawType, 'texte') || str_contains($rawType, 'libre') => 'texte_libre',
            str_contains($rawType, 'vrai')  || str_contains($rawType, 'faux')  => 'vrai_faux',
            default => 'qcm',
        };

        if ($texte === '') { $nbSkip++; continue; }

        $stmtChk = $pdo->prepare("SELECT id FROM questions WHERE partie_id = ? AND texte = ?");
        $stmtChk->execute([$partieId, $texte]);
        if ($stmtChk->fetchColumn()) {
            echo "  [SKIP] Q" . ($idx+1) . " déjà présente\n";
            $nbSkip++;
            continue;
        }

        $pdo->prepare("INSERT INTO questions (module_id, partie_id, texte, type, points, ordre) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$moduleId, $partieId, $texte, $type, $points, $idx + 1]);
        $qid = (int)$pdo->lastInsertId();

        if ($type === 'qcm') {
            $rawOptions = $q['options'] ?? [];
            $correcte   = strtoupper(trim($q['reponse_correcte'] ?? ''));
            foreach ($rawOptions as $ordOpt => $optTxt) {
                $lettre = '';
                if (preg_match('/^([A-Da-d])[.\)]\s*/u', $optTxt, $m)) {
                    $lettre = strtoupper($m[1]);
                    $optTxt = preg_replace('/^[A-Da-d][.\)]\s*/u', '', $optTxt);
                }
                $isCorrect = ($lettre !== '' && $lettre === $correcte) ? 1 : 0;
                $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)")
                    ->execute([$qid, $optTxt, $isCorrect, $ordOpt + 1]);
            }
        } elseif ($type === 'texte_libre') {
            foreach (($q['reponse_attendue_cles'] ?? []) as $ordCle => $cle) {
                $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, 1, ?)")
                    ->execute([$qid, $cle, $ordCle + 1]);
            }
        }

        $nbImporte++;
        echo "  [QCM]  Q" . ($idx + 1) . " — " . mb_substr($texte, 0, 60) . "\n";
    }

    $pdo->commit();

    $totalPts = array_sum(array_column($data['questions'], 'points'));
    echo "\n✓ Import terminé : $nbImporte question(s) importée(s), $nbSkip ignorée(s) — Total : {$totalPts} pts\n";
    echo "→ http://eval-projet.test/admin/gestion_questions.php?module_id=$moduleId\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "ERREUR : " . $e->getMessage() . "\n";
    exit(1);
}
