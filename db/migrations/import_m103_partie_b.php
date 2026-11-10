<?php
/**
 * Migration : Création du module M103 Partie B + import des 20 questions QCM
 * Source : exports/qcm_m103_partie_b.json
 */

require_once __DIR__ . '/../../includes/functions.php';

$pdo = getDB();

$jsonPath = __DIR__ . '/../../exports/qcm_m103_partie_b.json';
if (!file_exists($jsonPath)) {
    die("ERREUR : fichier JSON introuvable : $jsonPath\n");
}

$data = json_decode(file_get_contents($jsonPath), true);
if (!$data || !isset($data['questions'])) {
    die("ERREUR : JSON invalide ou structure inattendue.\n");
}

$moduleNom  = $data['module']  ?? 'M103 Concevoir un réseau informatique';
$partieNom  = $data['titre']   ?? 'Partie B — Modèles et protocoles de communication IP';
$nbQuotaCC  = 20; // toutes les questions pour le contrôle
$noteMax    = 20;

$pdo->beginTransaction();
try {
    // ── 1. Module ─────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT id FROM modules WHERE nom = ?");
    $stmt->execute([$moduleNom]);
    $moduleId = $stmt->fetchColumn();

    if (!$moduleId) {
        $pdo->prepare("INSERT INTO modules (nom, actif, nb_questions_controle) VALUES (?, 1, ?)")
            ->execute([$moduleNom, $nbQuotaCC]);
        $moduleId = (int)$pdo->lastInsertId();
        echo "Module créé : #$moduleId — $moduleNom\n";
    } else {
        echo "Module existant : #$moduleId — $moduleNom\n";
    }

    // ── 2. Évaluation (si absente) ────────────────────────────────────────
    $stmtE = $pdo->prepare("SELECT id FROM evaluations WHERE module_id = ?");
    $stmtE->execute([$moduleId]);
    $evalId = $stmtE->fetchColumn();
    if (!$evalId) {
        $pdo->prepare("INSERT INTO evaluations (module_id, nom, type, duree_minutes, note_max) VALUES (?, ?, 'qcm', 30, ?)")
            ->execute([$moduleId, $moduleNom, $noteMax]);
        $evalId = (int)$pdo->lastInsertId();
        echo "Évaluation créée : #$evalId\n";
    } else {
        echo "Évaluation existante : #$evalId\n";
    }

    // ── 3. Partie ──────────────────────────────────────────────────────────
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
        echo "Partie existante : #$partieId — $partieNom\n";
    }

    // ── 4. Questions ───────────────────────────────────────────────────────
    $nbImporte = 0;
    $nbSkip    = 0;

    foreach ($data['questions'] as $idx => $q) {
        $texte  = trim($q['enonce'] ?? '');
        $points = (float)($q['points'] ?? 1);
        $type   = 'qcm';

        if ($texte === '') { $nbSkip++; continue; }

        // Anti-doublon
        $stmtChk = $pdo->prepare("SELECT id FROM questions WHERE partie_id = ? AND texte = ?");
        $stmtChk->execute([$partieId, $texte]);
        if ($stmtChk->fetchColumn()) {
            echo "  SKIP (doublon) : Q" . ($idx + 1) . "\n";
            $nbSkip++;
            continue;
        }

        $pdo->prepare("INSERT INTO questions (module_id, partie_id, texte, type, points, ordre) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$moduleId, $partieId, $texte, $type, $points, $idx + 1]);
        $qid = (int)$pdo->lastInsertId();

        // Options
        $rawOptions = $q['options'] ?? [];
        $correcte   = strtoupper(trim($q['reponse_correcte'] ?? ''));

        foreach ($rawOptions as $ordOpt => $optTxt) {
            // Extraire la lettre : "A. texte" → lettre = "A"
            $lettre = '';
            if (preg_match('/^([A-Da-d])[.\)]\s*/u', $optTxt, $m)) {
                $lettre   = strtoupper($m[1]);
                $optTxt   = preg_replace('/^[A-Da-d][.\)]\s*/u', '', $optTxt);
            }
            $isCorrect = ($lettre !== '' && $lettre === $correcte) ? 1 : 0;
            $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)")
                ->execute([$qid, $optTxt, $isCorrect, $ordOpt + 1]);
        }

        $nbImporte++;
    }

    $pdo->commit();
    echo "\n✓ Import terminé : $nbImporte question(s) importée(s), $nbSkip ignorée(s).\n";
    echo "→ Module #$moduleId visible sur : http://eval-projet.test/admin/modules.php\n";
    echo "→ Questions : http://eval-projet.test/admin/gestion_questions.php?module_id=$moduleId\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "ERREUR : " . $e->getMessage() . "\n";
    exit(1);
}
