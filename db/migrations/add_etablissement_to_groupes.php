<?php
/**
 * Migration : ajoute etablissement_id (FK) dans la table groupes
 * et rattache les groupes existants au premier établissement actif
 */
require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();

// Vérifier si la colonne existe déjà
$cols = $pdo->query("SHOW COLUMNS FROM groupes LIKE 'etablissement_id'")->fetchAll();
if (!empty($cols)) {
    echo "Migration déjà appliquée — colonne etablissement_id existe.\n";
    exit(0);
}

// Ajouter la colonne
$pdo->exec("ALTER TABLE groupes ADD COLUMN etablissement_id INT NULL AFTER nom");
$pdo->exec("ALTER TABLE groupes ADD CONSTRAINT fk_groupe_etablissement
            FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE SET NULL");

// Rattacher les groupes existants au premier établissement actif
$defaut = $pdo->query("SELECT id FROM etablissements WHERE actif = 1 ORDER BY id LIMIT 1")->fetchColumn();
if ($defaut) {
    $pdo->prepare("UPDATE groupes SET etablissement_id = ? WHERE etablissement_id IS NULL")
        ->execute([$defaut]);
    $nb = $pdo->query("SELECT COUNT(*) FROM groupes WHERE etablissement_id = $defaut")->fetchColumn();
    echo "Migration OK — $nb groupe(s) rattaché(s) à l'établissement id=$defaut.\n";
} else {
    echo "Migration OK — colonne ajoutée (aucun établissement actif trouvé pour le rattachement).\n";
}
