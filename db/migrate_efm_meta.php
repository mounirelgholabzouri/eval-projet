<?php
/**
 * Migration : table modules_efm_meta
 * - Crée la table modules_efm_meta
 * - Migre les données existantes depuis modules.meta_json
 * Safe à relancer.
 */

require_once __DIR__ . '/../config/database.php';

$pdo = getDB();
$ok = $skip = $fail = 0;

function run(PDO $pdo, string $label, string $sql, bool $skip): void {
    global $ok, $fail;
    if ($skip) { echo "  [SKIP]  $label\n"; $GLOBALS['skip']++; return; }
    try {
        $pdo->exec($sql);
        echo "  [OK]    $label\n";
        $ok++;
    } catch (PDOException $e) {
        echo "  [FAIL]  $label — " . $e->getMessage() . "\n";
        $fail++;
    }
}

function tableExists(PDO $pdo, string $table): bool {
    return (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE table_schema = DATABASE() AND table_name = '$table'"
    )->fetchColumn();
}

echo "\n=== Migration : modules_efm_meta ===\n\n";

// ── 1. Créer la table ──────────────────────────────────────────
echo "── 1. Table modules_efm_meta ──\n";
run($pdo, 'CREATE TABLE modules_efm_meta',
    "CREATE TABLE modules_efm_meta (
        module_id      INT NOT NULL,
        code_module    VARCHAR(50)  NOT NULL DEFAULT '',
        filiere        VARCHAR(100) NOT NULL DEFAULT '',
        etablissement  VARCHAR(150) NOT NULL DEFAULT '',
        annee          VARCHAR(9)   NOT NULL DEFAULT '',
        PRIMARY KEY (module_id),
        CONSTRAINT fk_efm_meta_module
            FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    tableExists($pdo, 'modules_efm_meta')
);

// ── 2. Migrer meta_json → modules_efm_meta ─────────────────────
echo "\n── 2. Migration meta_json → modules_efm_meta ──\n";

$rows = $pdo->query(
    "SELECT id, meta_json FROM modules WHERE type = 'efm' AND meta_json IS NOT NULL AND meta_json <> ''"
)->fetchAll();

$migrated = 0;
$already  = 0;

$checkStmt  = $pdo->prepare("SELECT COUNT(*) FROM modules_efm_meta WHERE module_id = ?");
$insertStmt = $pdo->prepare(
    "INSERT INTO modules_efm_meta (module_id, code_module, filiere, etablissement, annee)
     VALUES (?, ?, ?, ?, ?)"
);
$updateStmt = $pdo->prepare(
    "UPDATE modules_efm_meta SET code_module=?, filiere=?, etablissement=?, annee=? WHERE module_id=?"
);

foreach ($rows as $row) {
    $meta = json_decode($row['meta_json'], true) ?? [];
    $code  = $meta['code_module']   ?? '';
    $fil   = $meta['filiere']       ?? '';
    $etab  = $meta['etablissement'] ?? '';
    $annee = $meta['annee']         ?? '';

    $checkStmt->execute([$row['id']]);
    $exists = (int) $checkStmt->fetchColumn();

    if ($exists) {
        $updateStmt->execute([$code, $fil, $etab, $annee, $row['id']]);
        $already++;
    } else {
        $insertStmt->execute([$row['id'], $code, $fil, $etab, $annee]);
        $migrated++;
    }
}

echo "  [OK]    $migrated modules migrés, $already mis à jour\n";
$ok++;

echo "\n" . str_repeat('─', 50) . "\n";
echo "  Résultat : {$ok} OK  |  {$skip} skip  |  {$fail} erreur(s)\n";
echo str_repeat('─', 50) . "\n\n";

if ($fail > 0) { exit(1); }
echo "  Migration terminée.\n\n";
