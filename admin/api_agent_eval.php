<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/agent_functions.php';

header('Content-Type: application/json; charset=utf-8');

$type = $_POST['type'] ?? '';

if ($type === 'continue') {
    $moduleId    = (int)($_POST['module_id'] ?? 0);
    $groupeId    = (int)($_POST['groupe_id'] ?? 0);
    $nbQuestions = (int)($_POST['nb_questions'] ?? 20);
    $dureeMin    = (int)($_POST['duree_min'] ?? 30);
    $noteMax     = (int)($_POST['note_max'] ?? 20);

    if ($moduleId <= 0) {
        echo json_encode(['error' => 'Module requis.']);
        exit;
    }

    $result = agentGenererEvalContinue($moduleId, $groupeId, $nbQuestions, $dureeMin, $noteMax);

} elseif ($type === 'efm') {
    $moduleIds    = array_filter(array_map('intval', (array)($_POST['module_ids'] ?? [])));
    $groupeId     = (int)($_POST['groupe_id'] ?? 0);
    $nbQuestions  = (int)($_POST['nb_questions'] ?? 40);
    $codeModule   = trim($_POST['code_module'] ?? '');
    $filiere      = trim($_POST['filiere'] ?? '');
    $etablissement = trim($_POST['etablissement'] ?? '');
    $annee        = trim($_POST['annee'] ?? getAnneeFormation());

    if (empty($moduleIds)) {
        echo json_encode(['error' => 'Sélectionnez au moins un module.']);
        exit;
    }

    $result = agentGenererEFM($moduleIds, $groupeId, $nbQuestions, $codeModule, $filiere, $etablissement, $annee);

} else {
    echo json_encode(['error' => 'Type invalide.']);
    exit;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
