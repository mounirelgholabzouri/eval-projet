<?php
ob_start();
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// Capturer toute sortie parasite (warnings PHP) avant d'écrire le JSON
$leaked = ob_get_clean();
ob_start();

function jsonError(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

if ($leaked !== '') {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'PHP output: ' . strip_tags($leaked)]);
    exit;
}

try {
    // ── CSRF ─────────────────────────────────────────────────────
    $csrfPost = $_POST['csrf_token'] ?? '';
    if (!$csrfPost || !hash_equals(csrfToken(), $csrfPost)) {
        jsonError('Token CSRF invalide. Rechargez la page et réessayez.', 403);
    }

    // ── Paramètres ────────────────────────────────────────────────
    $moduleIds   = array_map('intval', (array)($_POST['module_ids'] ?? []));
    $demande     = trim($_POST['demande']      ?? '');
    $nbQuestions = max(1, min(100, (int)($_POST['nb_questions'] ?? 20)));
    $mode        = $_POST['mode'] ?? 'selection';

    if (!in_array($mode, ['selection', 'generation', 'mixte'], true)) {
        jsonError('Mode invalide. Valeurs acceptées : selection, generation, mixte.');
    }

    $moduleIds = array_filter($moduleIds, fn($id) => $id > 0);

    // ── Config IA ─────────────────────────────────────────────────
    $provider = getAIProvider();
    $apiKey   = getAPIKeyForProvider($provider);
    $model    = getAIModel();

    if (!$apiKey && $provider !== 'ollama') {
        jsonError('Aucune clé API configurée pour le fournisseur « ' . $provider . ' ».');
    }

    $pdo = getDB();

    // ── Charger les questions des modules sélectionnés ────────────
    $questionsBase = [];
    if (!empty($moduleIds)) {
        $placeholders = implode(',', array_fill(0, count($moduleIds), '?'));

        $stmtQ = $pdo->prepare("
            SELECT q.id, q.texte, q.type, q.points, q.partie_id
            FROM questions q
            JOIN parties p ON p.id = q.partie_id AND p.actif = 1
            WHERE q.module_id IN ($placeholders)
            ORDER BY RAND()
            LIMIT 300
        ");
        $stmtQ->execute($moduleIds);
        $questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($questions)) {
            $qIds = array_column($questions, 'id');
            $qPlaceholders = implode(',', array_fill(0, count($qIds), '?'));
            $stmtC = $pdo->prepare("
                SELECT cr.question_id, cr.texte, cr.is_correct
                FROM choix_reponses cr
                WHERE cr.question_id IN ($qPlaceholders)
                ORDER BY cr.question_id, cr.ordre
            ");
            $stmtC->execute($qIds);
            $choixByQ = [];
            foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $choixByQ[(int)$c['question_id']][] = [
                    't' => $c['texte'],
                    'c' => (bool)$c['is_correct'],
                ];
            }

            foreach ($questions as $q) {
                $questionsBase[] = [
                    'id'     => (int)$q['id'],
                    'texte'  => $q['texte'],
                    'type'   => $q['type'],
                    'points' => (float)$q['points'],
                    'choix'  => $choixByQ[(int)$q['id']] ?? [],
                ];
            }
        }
    }

    // ── System prompt ─────────────────────────────────────────────
    $modeDesc = match($mode) {
        'selection'  => 'Tu dois UNIQUEMENT sélectionner des questions parmi celles fournies (source="existante"). Ne génère pas de nouvelles questions.',
        'generation' => 'Tu dois UNIQUEMENT générer de nouvelles questions (source="nouvelle"). N\'utilise pas les id de la base.',
        'mixte'      => 'Tu peux combiner : sélectionner des questions existantes (source="existante") ET générer de nouvelles questions (source="nouvelle").',
        default      => '',
    };

    $systemPrompt = <<<PROMPT
Tu es un expert en formation professionnelle et en ingénierie pédagogique.
Voici une base de questions disponibles (JSON compact). Chaque question a : id, texte, type (qcm/vrai_faux/texte_libre), points, choix (t=texte, c=is_correct).

MODE : {$modeDesc}

Tu dois retourner UNIQUEMENT un objet JSON valide (sans markdown, sans bloc ```json, sans commentaire) avec cette structure exacte :
{
  "explication": "string — justification de tes choix",
  "questions": [
    {
      "source": "existante",
      "id": 123,
      "texte": "...",
      "type": "qcm",
      "points": 1,
      "choix": [{"texte":"...", "is_correct": true}, {"texte":"...", "is_correct": false}]
    },
    {
      "source": "nouvelle",
      "id": null,
      "texte": "...",
      "type": "qcm",
      "points": 1,
      "choix": [{"texte":"...", "is_correct": true}, {"texte":"...", "is_correct": false}]
    }
  ]
}
Pour les questions "existante" : utilise exactement l'id et le texte fournis dans la base.
Pour les questions "nouvelle" : génère dans le style et le niveau des questions existantes.
Pour les questions vrai_faux : 2 choix exactement (Vrai is_correct=true, Faux is_correct=false ou inversement).
Pour les questions texte_libre : choix = tableau vide [].
Ne retourne que du JSON brut. Aucun texte avant ou après.
PROMPT;

    // ── Message utilisateur ───────────────────────────────────────
    $baseJson    = json_encode($questionsBase, JSON_UNESCAPED_UNICODE);
    $demandeText = $demande !== '' ? $demande : 'Compose une pile de ' . $nbQuestions . ' questions représentatives.';

    $userMessage = "Demande : {$demandeText}\n\nNombre de questions souhaité : {$nbQuestions}\n\nBase disponible : {$baseJson}";

    // ── Appel IA ──────────────────────────────────────────────────
    $maxTokens = ($provider === 'ollama') ? 2000 : 4000;

    $aiResult = callAIUnified(
        $provider,
        $apiKey,
        $model,
        $systemPrompt,
        [['role' => 'user', 'content' => $userMessage]],
        $maxTokens
    );

    if (!($aiResult['success'] ?? false)) {
        jsonError('Erreur IA : ' . ($aiResult['error'] ?? 'Réponse vide. Réessayez ou vérifiez la configuration.'));
    }

    // ── Parser la réponse ─────────────────────────────────────────
    $jsonStr = trim($aiResult['text'] ?? '');

    // Extraire le JSON si entouré de balises markdown
    if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/i', $jsonStr, $m)) {
        $jsonStr = trim($m[1]);
    }

    // Extraire l'objet JSON principal si du texte précède
    if (!str_starts_with($jsonStr, '{')) {
        $pos = strpos($jsonStr, '{');
        if ($pos !== false) $jsonStr = substr($jsonStr, $pos);
    }

    $parsed = json_decode($jsonStr, true);

    if (!is_array($parsed) || !isset($parsed['questions'])) {
        jsonError('Réponse de l\'IA non parseable. Réessayez. Réponse brute : ' . substr($aiResult['text'] ?? '', 0, 200));
    }

    // ── Valider les IDs "existante" ───────────────────────────────
    $validIds = array_flip(array_column($questionsBase, 'id'));
    $questionsBase_indexed = [];
    foreach ($questionsBase as $qb) {
        $questionsBase_indexed[$qb['id']] = $qb;
    }

    $questionsValidees = [];
    foreach ($parsed['questions'] as $q) {
        if (!is_array($q)) continue;

        $source = ($q['source'] ?? '') === 'nouvelle' ? 'nouvelle' : 'existante';

        if ($source === 'existante') {
            $id = (int)($q['id'] ?? 0);
            if (!isset($validIds[$id])) continue;
            // Utiliser les données exactes de la base
            $qb = $questionsBase_indexed[$id];
            $choix = array_map(fn($c) => [
                'texte'      => $c['t'],
                'is_correct' => (bool)$c['c'],
            ], $qb['choix']);
            $questionsValidees[] = [
                'source'  => 'existante',
                'id'      => $id,
                'texte'   => $qb['texte'],
                'type'    => $qb['type'],
                'points'  => $qb['points'],
                'choix'   => $choix,
            ];
        } else {
            $texte  = trim($q['texte'] ?? '');
            $type   = in_array($q['type'] ?? '', ['qcm','vrai_faux','texte_libre']) ? $q['type'] : 'qcm';
            $points = max(0.5, (float)($q['points'] ?? 1));

            $choixRaw = $q['choix'] ?? [];
            $choix = [];
            foreach ((array)$choixRaw as $c) {
                if (!empty($c['texte'])) {
                    $choix[] = [
                        'texte'      => trim($c['texte']),
                        'is_correct' => (bool)($c['is_correct'] ?? false),
                    ];
                }
            }

            if ($texte === '') continue;

            $questionsValidees[] = [
                'source'  => 'nouvelle',
                'id'      => null,
                'texte'   => $texte,
                'type'    => $type,
                'points'  => $points,
                'choix'   => $choix,
            ];
        }
    }

    $out = ob_get_clean();
    if ($out !== '') {
        echo json_encode(['success' => false, 'error' => 'PHP warning: ' . strip_tags($out)]);
        exit;
    }

    echo json_encode([
        'success'    => true,
        'questions'  => $questionsValidees,
        'explication'=> $parsed['explication'] ?? '',
        'nb_total'   => count($questionsValidees),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_get_clean();
    jsonError('Erreur serveur : ' . $e->getMessage());
}
