<?php
/**
 * Step 1 — Crée la table evaluations
 * Sépare la config d'évaluation du contenu pédagogique (modules).
 */
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

// Idempotent : ne fait rien si la table existe déjà
$exists = $pdo->query("SHOW TABLES LIKE 'evaluations'")->fetchColumn();
if ($exists) {
    echo "Table evaluations déjà présente — skip.\n";
    exit(0);
}

$pdo->exec("
CREATE TABLE evaluations (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  module_id       INT NOT NULL,
  nom             VARCHAR(200) NOT NULL,
  type            ENUM('qcm','efm','pratique','mixte') NOT NULL DEFAULT 'qcm',
  duree_minutes   INT NOT NULL DEFAULT 30,
  note_max        INT NOT NULL DEFAULT 20,
  nb_questions    INT DEFAULT NULL COMMENT 'NULL = toutes les questions du module',
  parties_ids     TEXT DEFAULT NULL COMMENT 'JSON array d IDs de parties; NULL = toutes',
  meta_json       TEXT DEFAULT NULL COMMENT 'Métadonnées EFM : code_module, filiere, etablissement, annee',
  actif           TINYINT(1) NOT NULL DEFAULT 1,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_eval_module FOREIGN KEY (module_id) REFERENCES modules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "OK — table evaluations créée.\n";
