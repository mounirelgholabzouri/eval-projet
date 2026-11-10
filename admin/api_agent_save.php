<?php
/**
 * Enregistre la proposition de l'agent IA comme évaluation (inactive) :
 *  - type "continue" → CC théorique du module choisi
 *  - type "efm"      → EFM rattaché au premier module sélectionné
 * Les questions de la banque sont liées, pas recopiées.
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !in_array($input['type'] ?? '', ['continue', 'efm'], true)) {
    echo json_encode(['error' => 'Données invalides.']);
    exit;
}

$isEfm    = $input['type'] === 'efm';
$moduleId = $isEfm ? (int)($input['module_ids'][0] ?? 0) : (int)($input['module_id'] ?? 0);
$ids      = array_values(array_unique(array_filter(array_map(fn($q) => (int)($q['id'] ?? 0), $input['questions'] ?? []))));

if ($moduleId <= 0 || !getModule($moduleId) || !$ids) {
    echo json_encode(['error' => 'Données incomplètes (module ou questions manquants).']);
    exit;
}

// Seules des questions existantes (des modules sélectionnés) sont acceptées
$modulesAutorises = $isEfm ? array_map('intval', $input['module_ids']) : [$moduleId];
$ph1 = implode(',', array_fill(0, count($ids), '?'));
$ph2 = implode(',', array_fill(0, count($modulesAutorises), '?'));
$check = getDB()->prepare("SELECT COUNT(*) FROM questions WHERE id IN ($ph1) AND module_id IN ($ph2)");
$check->execute(array_merge($ids, $modulesAutorises));
if ((int)$check->fetchColumn() !== count($ids)) {
    echo json_encode(['error' => "Certaines questions proposées n'existent pas dans les modules sélectionnés."]);
    exit;
}

$numero = null;
if (!$isEfm) {
    $numero = 1;
    foreach (getEvaluationsModule($moduleId) as $e) {
        if ($e['type'] === 'cc' && $e['mode'] === 'theorique') $numero = max($numero, (int)$e['numero'] + 1);
    }
}

try {
    $evalId = creerEvaluation([
        'module_id'     => $moduleId,
        'type'          => $isEfm ? 'efm' : 'cc',
        'mode'          => 'theorique',
        'numero'        => $numero,
        'contenu'       => trim((string)($input['titre'] ?? '')),
        'duree_minutes' => $isEfm ? 120 : (int)($input['duree_min'] ?? 30),
        'note_max'      => $isEfm ? 40 : (int)($input['note_max'] ?? 20),
        'meta'          => $isEfm ? array_filter([
            'etablissement' => trim((string)($input['etablissement'] ?? '')),
            'annee'         => trim((string)($input['annee'] ?? '')),
        ]) : null,
        'actif'         => false,
        'question_ids'  => $ids,
    ]);
    $eval = getEvaluation($evalId);
    echo json_encode([
        'success'       => true,
        'type'          => $input['type'],
        'evaluation_id' => $evalId,
        'nb_questions'  => count($ids),
        'message'       => 'Évaluation « ' . $eval['nom'] . ' » créée (inactive) avec ' . count($ids) . ' questions.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['error' => 'Erreur DB : ' . $e->getMessage()]);
}
