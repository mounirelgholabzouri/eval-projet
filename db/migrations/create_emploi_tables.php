<?php
/**
 * Crée les tables emploi du temps (ref_filieres, formateurs, groupes_emploi)
 * sans dépendance à emploi_ista.
 * Idempotent : CREATE TABLE IF NOT EXISTS.
 */
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

$pdo->exec("
CREATE TABLE IF NOT EXISTS ref_filieres (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    code           VARCHAR(60)  NOT NULL UNIQUE,
    nom            VARCHAR(200) NOT NULL,
    secteur        VARCHAR(150) NULL,
    niveau         VARCHAR(20)  NULL,
    type_formation VARCHAR(50)  NULL,
    actif          TINYINT(1)   NOT NULL DEFAULT 1,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "OK — ref_filieres\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS formateurs (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    nom                 VARCHAR(100) NOT NULL,
    prenom              VARCHAR(100) NOT NULL,
    matricule           VARCHAR(30)  NULL UNIQUE,
    specialite          VARCHAR(150) NULL,
    email               VARCHAR(150) NULL UNIQUE,
    telephone           VARCHAR(40)  NULL,
    volume_horaire_max  INT NOT NULL DEFAULT 30,
    actif               TINYINT(1)   NOT NULL DEFAULT 1,
    created_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "OK — formateurs\n";

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
echo "OK — groupes_emploi\n";

echo "Tables emploi du temps créées.\n";
