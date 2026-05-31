<?php
/**
 * Migration : M206 EFM — passage type 'qcm' → 'efm' + métadonnées EFM + note_max=40
 */
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

$moduleId = 47;

// 1. Passer le type à 'efm' et confirmer note_max=40
$pdo->prepare("UPDATE modules SET type='efm', note_max=40 WHERE id=?")
    ->execute([$moduleId]);
echo "✓ Module $moduleId : type=efm, note_max=40\n";

// 2. Insérer/mettre à jour les métadonnées EFM
$exists = $pdo->prepare("SELECT COUNT(*) FROM modules_efm_meta WHERE module_id=?");
$exists->execute([$moduleId]);

if ($exists->fetchColumn()) {
    $pdo->prepare("
        UPDATE modules_efm_meta
        SET code_module='M206', filiere='', etablissement='ISTA HAY RIAD RABAT', annee='25/26'
        WHERE module_id=?
    ")->execute([$moduleId]);
    echo "✓ modules_efm_meta : mise à jour\n";
} else {
    $pdo->prepare("
        INSERT INTO modules_efm_meta (module_id, code_module, filiere, etablissement, annee)
        VALUES (?, 'M206', '', 'ISTA HAY RIAD RABAT', '25/26')
    ")->execute([$moduleId]);
    echo "✓ modules_efm_meta : insertion\n";
}

// 3. Vérification
$m = $pdo->prepare("
    SELECT m.id, m.nom, m.type, m.note_max,
           em.code_module, em.filiere, em.etablissement, em.annee
    FROM modules m
    LEFT JOIN modules_efm_meta em ON em.module_id = m.id
    WHERE m.id = ?
");
$m->execute([$moduleId]);
$row = $m->fetch(PDO::FETCH_ASSOC);
echo "\nRésultat :\n";
foreach ($row as $k => $v) echo "  $k = $v\n";

// Total points (pour vérification formule /40)
$total = $pdo->prepare("SELECT COUNT(*) AS nb, SUM(points) AS total FROM questions WHERE module_id=?");
$total->execute([$moduleId]);
$t = $total->fetch(PDO::FETCH_ASSOC);
echo "\nQuestions : {$t['nb']} — Total points bruts : {$t['total']}\n";
echo "Formule notation : (score / {$t['total']}) × 40 = note sur /40\n";
