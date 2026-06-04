<?php
/**
 * Export des questions en JSON — à lancer sur le PC distant
 * Télécharge un fichier questions_export_DATE.json
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

// Récupérer toutes les questions avec module, partie, choix
$stmt = $pdo->query("
    SELECT q.id, q.texte, q.type, q.points, q.ordre, q.image_path,
           p.nom AS partie_nom, p.ordre AS partie_ordre,
           m.nom AS module_nom
    FROM questions q
    JOIN parties p ON p.id = q.partie_id
    JOIN modules m ON m.id = q.module_id
    ORDER BY m.nom, p.ordre, q.ordre, q.id
");
$questions = $stmt->fetchAll();

$export = [];
foreach ($questions as $q) {
    $choix = $pdo->prepare("SELECT texte, is_correct, ordre FROM choix_reponses WHERE question_id = ? ORDER BY ordre, id");
    $choix->execute([$q['id']]);

    $export[] = [
        'module_nom'   => $q['module_nom'],
        'module_type'  => 'qcm',
        'partie_nom'   => $q['partie_nom'],
        'partie_ordre' => (int)$q['partie_ordre'],
        'texte'        => $q['texte'],
        'type'         => $q['type'],
        'points'       => (float)$q['points'],
        'ordre'        => (int)$q['ordre'],
        'image_path'   => $q['image_path'],
        'choix'        => $choix->fetchAll(),
    ];
}

$payload = [
    'exported_at' => date('Y-m-d H:i:s'),
    'machine'     => gethostname(),
    'nb'          => count($export),
    'questions'   => $export,
];

$filename = 'questions_export_' . date('Ymd_His') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
