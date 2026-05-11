<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['error' => 'Données invalides.']);
    exit;
}

$type = $input['type'] ?? '';
$pdo  = getDB();

// ============================================================
// SAUVEGARDE — Évaluation Continue
// Crée un module type='qcm' dédié à cette session
// ============================================================
if ($type === 'continue') {
    $titre       = trim($input['titre'] ?? 'Évaluation Continue');
    $groupeId    = (int)($input['groupe_id'] ?? 0);
    $moduleId    = (int)($input['module_id'] ?? 0);
    $dureeMin    = (int)($input['duree_min'] ?? 30);
    $noteMax     = (int)($input['note_max'] ?? 20);
    $questions   = $input['questions'] ?? [];

    if (empty($questions) || $moduleId <= 0) {
        echo json_encode(['error' => 'Données incomplètes.']);
        exit;
    }

    // Vérifier que les IDs de questions existent bien
    $ids = array_map(fn($q) => (int)$q['id'], $questions);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $check = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE id IN ($placeholders) AND module_id = ?");
    $check->execute(array_merge($ids, [$moduleId]));
    if ((int)$check->fetchColumn() !== count($ids)) {
        echo json_encode(['error' => 'Certaines questions n\'appartiennent pas au module.']);
        exit;
    }

    // Récupérer le module source pour nommer la session
    $mod = $pdo->prepare("SELECT nom FROM modules WHERE id=?");
    $mod->execute([$moduleId]);
    $modNom = $mod->fetchColumn();

    // Créer une session_eval pré-remplie (sans stagiaire = session modèle admin)
    $token = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare("
        INSERT INTO sessions_eval (token, nom, prenom, groupe_id, module_id, statut, total_points)
        VALUES (?, ?, ?, ?, ?, 'en_cours', ?)
    ");
    $totalPoints = array_sum(array_column($questions, 'points'));
    $stmt->execute([$token, '[AGENT] ' . $titre, $modNom, $groupeId ?: null, $moduleId, $totalPoints]);
    $sessionId = (int)$pdo->lastInsertId();

    echo json_encode([
        'success'    => true,
        'type'       => 'continue',
        'session_id' => $sessionId,
        'message'    => 'Évaluation continue créée (session #' . $sessionId . ').',
        'nb_questions' => count($questions),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// SAUVEGARDE — EFM
// Crée un module type='efm' + parties + recopie question_ids
// ============================================================
if ($type === 'efm') {
    $titre        = trim($input['titre'] ?? 'EFM');
    $groupeId     = (int)($input['groupe_id'] ?? 0);
    $codeModule   = trim($input['code_module'] ?? '');
    $filiere      = trim($input['filiere'] ?? '');
    $etablissement = trim($input['etablissement'] ?? '');
    $annee        = trim($input['annee'] ?? '');
    $parties      = $input['parties'] ?? [];

    if (empty($parties)) {
        echo json_encode(['error' => 'Aucune partie définie.']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        // 1. Créer une partie "Général" temporaire (sera remplacée)
        $stmt = $pdo->prepare("
            INSERT INTO modules (nom, description, type, actif, note_max)
            VALUES (?, ?, 'efm', 1, 20)
        ");
        $stmt->execute([$titre, 'Généré par agent IA — ' . date('d/m/Y H:i')]);
        $newModuleId = (int)$pdo->lastInsertId();

        // 2. Métadonnées EFM
        $stmt = $pdo->prepare("
            INSERT INTO modules_efm_meta (module_id, code_module, filiere, etablissement, annee)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$newModuleId, $codeModule, $filiere, $etablissement, $annee]);

        $totalQuestions = 0;
        $ordre = 0;

        foreach ($parties as $pi => $partie) {
            $partieQuestions = $partie['questions'] ?? [];
            if (empty($partieQuestions)) continue;

            // 3. Créer la partie dans le nouveau module
            $stmt = $pdo->prepare("INSERT INTO parties (module_id, nom, ordre, actif) VALUES (?, ?, ?, 1)");
            $stmt->execute([$newModuleId, $partie['titre'], $pi]);
            $partieId = (int)$pdo->lastInsertId();

            // 4. Dupliquer les questions dans le nouveau module (copie physique)
            foreach ($partieQuestions as $qi => $qRef) {
                $qId = (int)$qRef['id'];
                $src = $pdo->prepare("SELECT * FROM questions WHERE id=?");
                $src->execute([$qId]);
                $qSrc = $src->fetch();
                if (!$qSrc) continue;

                $stmt = $pdo->prepare("
                    INSERT INTO questions (module_id, partie_id, texte, type, points, ordre)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$newModuleId, $partieId, $qSrc['texte'], $qSrc['type'], $qSrc['points'], $qi + 1]);
                $newQId = (int)$pdo->lastInsertId();

                // Copier les choix de réponses
                $choix = $pdo->prepare("SELECT * FROM choix_reponses WHERE question_id=? ORDER BY ordre");
                $choix->execute([$qId]);
                foreach ($choix->fetchAll() as $c) {
                    $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?,?,?,?)")
                        ->execute([$newQId, $c['texte'], $c['is_correct'], $c['ordre']]);
                }

                $totalQuestions++;
            }
        }

        // 5. Supprimer la partie auto créée par ensurePartieDefault si elle est vide
        $pdo->prepare("DELETE FROM parties WHERE module_id=? AND nom='Général' AND id NOT IN (SELECT DISTINCT partie_id FROM questions WHERE module_id=?)")
            ->execute([$newModuleId, $newModuleId]);

        $pdo->commit();

        echo json_encode([
            'success'       => true,
            'type'          => 'efm',
            'module_id'     => $newModuleId,
            'message'       => 'Module EFM "' . $titre . '" créé avec ' . $totalQuestions . ' questions.',
            'nb_questions'  => $totalQuestions,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error' => 'Erreur DB : ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['error' => 'Type inconnu.']);
