<?php
/**
 * Migration : ajout numero_classe sur stagiaires (numéro de liste de classe,
 * propre à chaque groupe, démarrant à 1). Attribué automatiquement, jamais
 * modifié manuellement. Affiché en pied de page discret des EFM/contrôles.
 */
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

$cols = $pdo->query("SHOW COLUMNS FROM stagiaires LIKE 'numero_classe'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE stagiaires ADD COLUMN numero_classe INT NULL DEFAULT NULL COMMENT 'Numéro de liste de classe, par groupe, à partir de 1' AFTER groupe_id");
    echo "stagiaires.numero_classe ajouté\n";
} else {
    echo "stagiaires.numero_classe déjà présent\n";
}

// Backfill : numérotation par groupe, ordre alphabétique nom/prénom, pour les stagiaires sans numéro
$groupes = $pdo->query("SELECT DISTINCT groupe_id FROM stagiaires WHERE numero_classe IS NULL")->fetchAll(PDO::FETCH_COLUMN);
foreach ($groupes as $groupeId) {
    $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(numero_classe), 0) FROM stagiaires WHERE groupe_id = ?");
    $maxStmt->execute([$groupeId]);
    $num = (int)$maxStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT id FROM stagiaires WHERE groupe_id = ? AND numero_classe IS NULL ORDER BY nom, prenom, id");
    $stmt->execute([$groupeId]);
    $upd = $pdo->prepare("UPDATE stagiaires SET numero_classe = ? WHERE id = ?");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $stagiaireId) {
        $num++;
        $upd->execute([$num, $stagiaireId]);
    }
    echo "Groupe $groupeId : numéros de classe attribués jusqu'à $num\n";
}

$idx = $pdo->query("SHOW INDEX FROM stagiaires WHERE Key_name = 'uq_groupe_numero_classe'")->fetchAll();
if (empty($idx)) {
    $pdo->exec("ALTER TABLE stagiaires ADD UNIQUE KEY uq_groupe_numero_classe (groupe_id, numero_classe)");
    echo "Index unique uq_groupe_numero_classe ajouté\n";
} else {
    echo "Index unique uq_groupe_numero_classe déjà présent\n";
}

echo "Migration terminée.\n";
