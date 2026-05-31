<?php
/**
 * Migration : import depuis emploi_ista → eval_online
 *
 * Crée dans eval_online :
 *   - formateurs  : table dédiée (4 personnes uniques depuis emploi_ista)
 *   - groupes_emploi : groupes emploi du temps (séparé de groupes eval)
 *
 * Ne touche PAS : groupes, modules, ref_filieres, ref_modules, sessions_eval
 */

require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();

// ── Source : connexion à emploi_ista ────────────────────────────────────────
try {
    $src = new PDO(
        'mysql:host=127.0.0.1;dbname=emploi_ista;charset=utf8mb4',
        'root', '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("❌ Connexion emploi_ista impossible : " . $e->getMessage() . "\n");
}

echo "=== Migration emploi_ista → eval_online ===\n\n";

// ════════════════════════════════════════════════════════════════════════════
// 1. TABLE formateurs
// ════════════════════════════════════════════════════════════════════════════
echo "── 1. Table formateurs ──\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS formateurs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nom             VARCHAR(100) NOT NULL,
    prenom          VARCHAR(100) NOT NULL,
    matricule       VARCHAR(30)  NULL UNIQUE,
    specialite      VARCHAR(150) NULL,
    email           VARCHAR(150) NULL UNIQUE,
    telephone       VARCHAR(40)  NULL,
    volume_horaire_max INT NOT NULL DEFAULT 30,
    actif           TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  ✓ Table formateurs créée (ou déjà existante)\n";

// Dédoublonnage : on garde le premier id par (nom, prenom)
$srcFormateurs = $src->query("
    SELECT f.id, f.nom, f.prenom, f.matricule, f.specialite, f.email, f.telephone, f.volume_horaire_max
    FROM formateurs f
    INNER JOIN (SELECT nom, prenom, MIN(id) AS min_id FROM formateurs GROUP BY nom, prenom) AS dedup
        ON f.id = dedup.min_id
    ORDER BY f.id
")->fetchAll();

$stmtCheck = $pdo->prepare("SELECT id FROM formateurs WHERE email = ? OR (nom = ? AND prenom = ?) LIMIT 1");
$stmtIns   = $pdo->prepare("
    INSERT INTO formateurs (nom, prenom, matricule, specialite, email, telephone, volume_horaire_max)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

$imported = 0;
$skipped  = 0;
foreach ($srcFormateurs as $f) {
    $stmtCheck->execute([$f['email'], $f['nom'], $f['prenom']]);
    if ($stmtCheck->fetch()) {
        echo "  ⏩ Ignoré (déjà présent) : {$f['prenom']} {$f['nom']}\n";
        $skipped++;
        continue;
    }
    $stmtIns->execute([
        $f['nom'], $f['prenom'], $f['matricule'],
        $f['specialite'], $f['email'] ?: null,
        $f['telephone'], $f['volume_horaire_max']
    ]);
    echo "  ✓ Importé : {$f['prenom']} {$f['nom']} ({$f['specialite']})\n";
    $imported++;
}
echo "  → {$imported} importé(s), {$skipped} ignoré(s)\n\n";

// ════════════════════════════════════════════════════════════════════════════
// 2. TABLE groupes_emploi
// ════════════════════════════════════════════════════════════════════════════
echo "── 2. Table groupes_emploi ──\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS groupes_emploi (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    filiere_id      INT          NOT NULL,
    code            VARCHAR(50)  NOT NULL UNIQUE,
    annee           VARCHAR(30)  NOT NULL,
    creneau         VARCHAR(10)  NULL,
    annee_formation TINYINT(1)   NULL,
    fusion_groupes  VARCHAR(200) NULL,
    code_fusion     VARCHAR(50)  NULL,
    effectif        INT          NOT NULL DEFAULT 0,
    mode_formation  ENUM('presentiel','distanciel','hybride') NOT NULL DEFAULT 'presentiel',
    actif           TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ge_filiere FOREIGN KEY (filiere_id) REFERENCES ref_filieres(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  ✓ Table groupes_emploi créée (ou déjà existante)\n";

// Mapping filiere_id emploi_ista → ref_filieres eval_online via code
$filiereMap = [];
$srcFilieres = $src->query("SELECT id, code FROM filieres")->fetchAll();
$stmtRefFil  = $pdo->prepare("SELECT id FROM ref_filieres WHERE code = ?");
foreach ($srcFilieres as $fil) {
    $stmtRefFil->execute([$fil['code']]);
    $row = $stmtRefFil->fetch();
    if ($row) {
        $filiereMap[$fil['id']] = $row['id'];
    }
}
echo "  ✓ Mapping filières : " . count($filiereMap) . " correspondance(s)\n";

$srcGroupes = $src->query("SELECT * FROM groupes ORDER BY id")->fetchAll();

$stmtGCheck = $pdo->prepare("SELECT id FROM groupes_emploi WHERE code = ?");
$stmtGIns   = $pdo->prepare("
    INSERT INTO groupes_emploi
        (filiere_id, code, annee, creneau, annee_formation, fusion_groupes, code_fusion, effectif, mode_formation, actif)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$imported = 0;
$skipped  = 0;
foreach ($srcGroupes as $g) {
    $stmtGCheck->execute([$g['code']]);
    if ($stmtGCheck->fetch()) {
        echo "  ⏩ Ignoré (déjà présent) : {$g['code']}\n";
        $skipped++;
        continue;
    }
    $filId = $filiereMap[$g['filiere_id']] ?? null;
    if (!$filId) {
        echo "  ⚠️  filiere_id {$g['filiere_id']} non mappé pour {$g['code']} — ignoré\n";
        $skipped++;
        continue;
    }
    $stmtGIns->execute([
        $filId, $g['code'], $g['annee'],
        $g['creneau'] ?? null, $g['annee_formation'] ?? null,
        $g['fusion_groupes'] ?? null, $g['code_fusion'] ?? null,
        $g['effectif'], $g['mode_formation'], $g['actif']
    ]);
    echo "  ✓ Importé : {$g['code']} ({$g['annee']}, effectif {$g['effectif']})\n";
    $imported++;
}
echo "  → {$imported} importé(s), {$skipped} ignoré(s)\n\n";

// ════════════════════════════════════════════════════════════════════════════
// Résumé
// ════════════════════════════════════════════════════════════════════════════
echo "=== Migration terminée ===\n";
$nbF = $pdo->query("SELECT COUNT(*) FROM formateurs")->fetchColumn();
$nbG = $pdo->query("SELECT COUNT(*) FROM groupes_emploi")->fetchColumn();
echo "  formateurs      : {$nbF} ligne(s)\n";
echo "  groupes_emploi  : {$nbG} ligne(s)\n";
