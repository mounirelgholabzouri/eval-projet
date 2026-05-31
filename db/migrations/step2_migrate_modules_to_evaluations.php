<?php
/**
 * Step 2 — Crée une évaluation par module existant.
 * Fusionne modules.meta_json + modules_efm_meta dans evaluations.meta_json.
 * Idempotent : ignore les modules déjà migrés (évaluation existante pour ce module).
 */
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

// Vérifie que la table cible existe
$exists = $pdo->query("SHOW TABLES LIKE 'evaluations'")->fetchColumn();
if (!$exists) {
    echo "ERREUR — Lancer step1 d'abord.\n";
    exit(1);
}

// Charge le méta EFM existant (modules_efm_meta) indexé par module_id
$efmMeta = [];
$metaExists = $pdo->query("SHOW TABLES LIKE 'modules_efm_meta'")->fetchColumn();
if ($metaExists) {
    foreach ($pdo->query("SELECT * FROM modules_efm_meta")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $efmMeta[$row['module_id']] = $row;
    }
}

$modules = $pdo->query("SELECT * FROM modules ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$insert = $pdo->prepare("
    INSERT INTO evaluations (module_id, nom, type, duree_minutes, note_max, meta_json, actif, created_at)
    VALUES (:module_id, :nom, :type, :duree, :note_max, :meta_json, :actif, :created_at)
");

$check = $pdo->prepare("SELECT COUNT(*) FROM evaluations WHERE module_id = ?");

$ok = 0;
$skip = 0;

foreach ($modules as $m) {
    $check->execute([$m['id']]);
    if ($check->fetchColumn() > 0) {
        $skip++;
        continue;
    }

    // Type : normalise 'efm' → 'efm', tout autre → 'qcm'
    $type = in_array($m['type'], ['qcm','efm','pratique','mixte']) ? $m['type'] : 'qcm';

    // Fusionne les deux sources de méta JSON
    $meta = [];
    if (!empty($m['meta_json'])) {
        $decoded = json_decode($m['meta_json'], true);
        if (is_array($decoded)) $meta = $decoded;
    }
    if (isset($efmMeta[$m['id']])) {
        $e = $efmMeta[$m['id']];
        $meta['code_module']   = $e['code_module']   ?? null;
        $meta['filiere']       = $e['filiere']        ?? null;
        $meta['etablissement'] = $e['etablissement']  ?? null;
        $meta['annee']         = $e['annee']          ?? null;
    }

    $insert->execute([
        ':module_id'  => $m['id'],
        ':nom'        => $m['nom'],
        ':type'       => $type,
        ':duree'      => $m['duree_minutes'] ?? 30,
        ':note_max'   => $m['note_max'] ?? 20,
        ':meta_json'  => empty($meta) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
        ':actif'      => $m['actif'] ?? 1,
        ':created_at' => $m['created_at'] ?? date('Y-m-d H:i:s'),
    ]);

    $ok++;
    echo "Module #{$m['id']} → évaluation créée ({$m['nom']})\n";
}

echo "\nOK — $ok évaluations créées, $skip déjà présentes.\n";
