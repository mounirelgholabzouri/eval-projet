<?php
/**
 * Migration : recatégorisation des évaluations (EFM / CC théorique / CC pratique)
 * + table cc_pratique_notes pour les grilles de notation pratique saisies manuellement.
 */
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

// 1. Colonne categorie sur evaluations
$cols = $pdo->query("SHOW COLUMNS FROM evaluations LIKE 'categorie'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE evaluations
        ADD COLUMN categorie VARCHAR(20) NOT NULL DEFAULT 'cc_theorique'
        COMMENT 'efm | cc_theorique | cc_pratique' AFTER type");
    echo "evaluations.categorie ajouté\n";

    // Migration des valeurs existantes
    $pdo->exec("UPDATE evaluations SET categorie = 'efm'          WHERE type = 'efm'");
    $pdo->exec("UPDATE evaluations SET categorie = 'cc_pratique'  WHERE type = 'pratique'");
    $pdo->exec("UPDATE evaluations SET categorie = 'cc_theorique' WHERE type IN ('qcm','mixte')");
    echo "Catégories migrées (efm→efm, qcm/mixte→cc_theorique, pratique→cc_pratique)\n";
} else {
    echo "evaluations.categorie déjà présent\n";
}

// 2. Table des notes de CC pratique (grille manuelle, structure fixe 4 parties / 20)
$pdo->exec("CREATE TABLE IF NOT EXISTS cc_pratique_notes (
    id INT NOT NULL AUTO_INCREMENT,
    evaluation_id INT NOT NULL,
    stagiaire_id  INT NOT NULL,
    p1q1 DECIMAL(4,2) DEFAULT NULL,
    p1q2 DECIMAL(4,2) DEFAULT NULL,
    p2q1 DECIMAL(4,2) DEFAULT NULL,
    p2q2 DECIMAL(4,2) DEFAULT NULL,
    p3q1 DECIMAL(4,2) DEFAULT NULL,
    p3q2 DECIMAL(4,2) DEFAULT NULL,
    p4   DECIMAL(4,2) DEFAULT NULL,
    total DECIMAL(5,2) DEFAULT NULL,
    absent TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_eval_stag (evaluation_id, stagiaire_id),
    KEY idx_eval (evaluation_id),
    KEY idx_stag (stagiaire_id),
    CONSTRAINT fk_ccpn_eval FOREIGN KEY (evaluation_id) REFERENCES evaluations (id) ON DELETE CASCADE,
    CONSTRAINT fk_ccpn_stag FOREIGN KEY (stagiaire_id)  REFERENCES stagiaires (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "Table cc_pratique_notes prête\n";

echo "Migration terminée.\n";
