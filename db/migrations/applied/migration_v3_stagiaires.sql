-- ============================================================
-- Migration v3 — Ajout table stagiaires + colonne stagiaire_id
-- ============================================================
USE eval_online;

-- Table des stagiaires avec authentification
CREATE TABLE IF NOT EXISTS stagiaires (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    groupe_id INT NOT NULL,
    annee_scolaire VARCHAR(9) NOT NULL DEFAULT '2024-2025',
    login VARCHAR(100) DEFAULT NULL UNIQUE,
    password_hash VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (groupe_id) REFERENCES groupes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Colonne stagiaire_id dans sessions_eval (si absente)
ALTER TABLE sessions_eval
    ADD COLUMN IF NOT EXISTS stagiaire_id INT DEFAULT NULL AFTER groupe_libre,
    ADD CONSTRAINT fk_session_stagiaire FOREIGN KEY (stagiaire_id) REFERENCES stagiaires(id) ON DELETE SET NULL;

-- Colonne annee_scolaire dans groupes (si absente)
ALTER TABLE groupes
    ADD COLUMN IF NOT EXISTS annee_scolaire VARCHAR(9) DEFAULT NULL AFTER nom;
