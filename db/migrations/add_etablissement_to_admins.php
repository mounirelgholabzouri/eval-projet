<?php
/**
 * Migration : ajoute etablissement_id (FK) dans la table admins (formateurs)
 */
require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();

$cols = $pdo->query("SHOW COLUMNS FROM admins LIKE 'etablissement_id'")->fetchAll();
if (!empty($cols)) {
    echo "Migration déjà appliquée.\n";
    exit(0);
}

$pdo->exec("ALTER TABLE admins ADD COLUMN etablissement_id INT NULL AFTER role");
$pdo->exec("ALTER TABLE admins ADD CONSTRAINT fk_admin_etablissement
            FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE SET NULL");

// Rattacher tous les formateurs existants au premier établissement actif
$defaut = $pdo->query("SELECT id FROM etablissements WHERE actif=1 ORDER BY id LIMIT 1")->fetchColumn();
if ($defaut) {
    $nb = $pdo->prepare("UPDATE admins SET etablissement_id=? WHERE etablissement_id IS NULL")
               ->execute([$defaut]);
    echo "Migration OK — formateurs/admins rattachés à l'établissement id=$defaut.\n";
} else {
    echo "Migration OK — colonne ajoutée (aucun établissement actif).\n";
}
