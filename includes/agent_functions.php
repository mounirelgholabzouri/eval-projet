<?php
/**
 * Fonctions de l'agent IA d'auto-génération d'évaluations.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

// ============================================================
// CONTEXT BUILDER — construit le contexte DB pour Claude
// ============================================================

function agentBuildContext(array $moduleIds): array {
    $pdo = getDB();

    $context = [];
    foreach ($moduleIds as $moduleId) {
        $moduleId = (int)$moduleId;

        $mod = $pdo->prepare("SELECT m.*, COALESCE(em.code_module,'') AS code_module FROM modules m LEFT JOIN modules_efm_meta em ON em.module_id=m.id WHERE m.id=?");
        $mod->execute([$moduleId]);
        $module = $mod->fetch();
        if (!$module) continue;

        $parties = $pdo->prepare("SELECT id, nom FROM parties WHERE module_id=? AND actif=1 ORDER BY ordre");
        $parties->execute([$moduleId]);
        $partiesList = $parties->fetchAll();

        $partiesMap = [];
        foreach ($partiesList as $p) {
            $partiesMap[$p['id']] = ['id' => $p['id'], 'nom' => $p['nom'], 'questions' => []];
        }

        $questions = $pdo->prepare("
            SELECT q.id, q.partie_id, q.texte, q.type, q.points
            FROM questions q
            WHERE q.module_id = ?
            ORDER BY q.partie_id, q.ordre, q.id
        ");
        $questions->execute([$moduleId]);

        foreach ($questions->fetchAll() as $q) {
            $entry = [
                'id'     => (int)$q['id'],
                'texte'  => mb_substr($q['texte'], 0, 150),
                'type'   => $q['type'],
                'points' => (float)$q['points'],
            ];
            if (isset($partiesMap[$q['partie_id']])) {
                $partiesMap[$q['partie_id']]['questions'][] = $entry;
            }
        }

        $context[] = [
            'module_id'   => $moduleId,
            'nom'         => $module['nom'],
            'code_module' => $module['code_module'],
            'parties'     => array_values($partiesMap),
        ];
    }

    return $context;
}

// ============================================================
// ANTI-RÉPÉTITION — questions utilisées dans les 30 derniers jours
// pour les modules donnés et optionnellement pour un groupe
// ============================================================

function agentGetRecentQuestionIds(array $moduleIds, int $groupeId = 0, int $days = 30): array {
    $pdo = getDB();

    $placeholders = implode(',', array_fill(0, count($moduleIds), '?'));
    $params = $moduleIds;
    $groupeClause = '';
    if ($groupeId > 0) {
        $groupeClause = " AND se.groupe_id = ?";
        $params[] = $groupeId;
    }
    $params[] = $days;

    $stmt = $pdo->prepare("
        SELECT DISTINCT rs.question_id
        FROM reponses_stagiaires rs
        JOIN sessions_eval se ON se.id = rs.session_id
        WHERE se.module_id IN ($placeholders)
        $groupeClause
        AND se.date_debut >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ============================================================
// APPEL CLAUDE API
// ============================================================

function agentCallClaude(string $systemPrompt, string $userPrompt): array {
    $apiKey = getAnthropicApiKey();
    if (!$apiKey || str_starts_with($apiKey, 'VOTRE_')) {
        return ['error' => 'Clé API Anthropic non configurée.'];
    }

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 4096,
        'system'     => $systemPrompt,
        'messages'   => [['role' => 'user', 'content' => $userPrompt]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response) return ['error' => 'Erreur réseau curl.'];
    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        return ['error' => $data['error']['message'] ?? 'Erreur API HTTP ' . $httpCode];
    }

    $text = $data['content'][0]['text'] ?? '';

    // Extraire le JSON de la réponse
    if (preg_match('/```json\s*([\s\S]+?)\s*```/', $text, $m)) {
        $text = $m[1];
    } elseif (preg_match('/\{[\s\S]+\}/', $text, $m)) {
        $text = $m[0];
    }

    $parsed = json_decode($text, true);
    if (!$parsed) return ['error' => 'Réponse Claude non parseable : ' . substr($text, 0, 200)];

    return $parsed;
}

// ============================================================
// GÉNÉRATION — Évaluation Continue
// ============================================================

function agentGenererEvalContinue(int $moduleId, int $groupeId, int $nbQuestions, int $dureeMin, int $noteMax): array {
    $context = agentBuildContext([$moduleId]);
    $groupe  = agentGetGroupeNom($groupeId);

    if (empty($context)) return ['error' => 'Module introuvable.'];

    $contextJson = json_encode($context[0], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $system = "Tu es un expert en création d'évaluations pédagogiques OFPPT. Tu réponds UNIQUEMENT avec du JSON valide, sans texte avant ni après.";

    $user = <<<PROMPT
Crée une évaluation continue pour :
- Module : {$context[0]['nom']}
- Groupe / niveau : {$groupe}
- Durée : {$dureeMin} minutes
- Nombre de questions demandé : {$nbQuestions}
- Note maximale : {$noteMax}

Voici toutes les questions disponibles par partie :
{$contextJson}

Contraintes :
1. Sélectionne exactement {$nbQuestions} questions (ou moins si stock insuffisant).
2. Couvre toutes les parties du module.
3. Adapte la difficulté au niveau du groupe : {$groupe}.

Retourne ce JSON (et rien d'autre) :
{
  "titre": "...",
  "consignes": "...",
  "nb_questions": ...,
  "duree_min": ...,
  "note_max": ...,
  "questions": [
    {"id": 123, "ordre": 1, "partie_nom": "...", "texte_court": "...", "type": "...", "points": 1.0},
    ...
  ]
}
PROMPT;

    $result = agentCallClaude($system, $user);
    if (isset($result['error'])) return $result;

    $result['module_id'] = $moduleId;
    $result['groupe_id'] = $groupeId;
    $result['type']      = 'continue';
    return $result;
}

// ============================================================
// GÉNÉRATION — EFM multi-modules
// ============================================================

function agentGenererEFM(array $moduleIds, int $groupeId, int $nbQuestions, string $codeModule, string $filiere, string $etablissement, string $annee): array {
    $context = agentBuildContext($moduleIds);
    $groupe  = agentGetGroupeNom($groupeId);

    if (empty($context)) return ['error' => 'Aucun module valide.'];

    $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $nbMods      = count($context);
    $nbParMod    = max(1, (int)round($nbQuestions / $nbMods));

    $system = "Tu es un expert en création d'EFM OFPPT. Tu réponds UNIQUEMENT avec du JSON valide, sans texte avant ni après.";

    $user = <<<PROMPT
Crée un EFM (Examen de Fin de Module) pour :
- Groupe / niveau : {$groupe}
- Code module : {$codeModule}
- Filière : {$filiere}
- Établissement : {$etablissement}
- Année : {$annee}
- Nombre total de questions : {$nbQuestions} (environ {$nbParMod} par module)

Voici toutes les questions disponibles par module :
{$contextJson}

Contraintes :
1. Couvre tous les modules de façon équilibrée.
2. Évite les redondances inter-modules.
3. Adapte la difficulté au groupe : {$groupe}.

Retourne ce JSON (et rien d'autre) :
{
  "titre": "EFM — ...",
  "code_module": "{$codeModule}",
  "filiere": "{$filiere}",
  "etablissement": "{$etablissement}",
  "annee": "{$annee}",
  "parties": [
    {
      "module_id": 15,
      "titre": "Partie 1 — ...",
      "points": 10,
      "questions": [
        {"id": 123, "ordre": 1, "texte_court": "...", "type": "...", "points": 1.0},
        ...
      ]
    }
  ]
}
PROMPT;

    $result = agentCallClaude($system, $user);
    if (isset($result['error'])) return $result;

    $result['module_ids'] = $moduleIds;
    $result['groupe_id']  = $groupeId;
    $result['type']       = 'efm';
    return $result;
}

// ============================================================
// HELPERS
// ============================================================

function agentGetGroupeNom(int $groupeId): string {
    if ($groupeId <= 0) return 'Non spécifié';
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT nom FROM groupes WHERE id=?");
    $stmt->execute([$groupeId]);
    return $stmt->fetchColumn() ?: 'Groupe #' . $groupeId;
}

function agentGetAllQuestionsMap(array $questionIds): array {
    if (empty($questionIds)) return [];
    $pdo = getDB();
    $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
    $stmt = $pdo->prepare("SELECT q.*, p.nom AS partie_nom, m.nom AS module_nom FROM questions q JOIN parties p ON p.id=q.partie_id JOIN modules m ON m.id=q.module_id WHERE q.id IN ($placeholders)");
    $stmt->execute($questionIds);
    $map = [];
    foreach ($stmt->fetchAll() as $q) $map[$q['id']] = $q;
    return $map;
}
