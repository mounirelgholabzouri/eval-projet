CREATE TABLE IF NOT EXISTS stagiaires (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    groupe_id INT DEFAULT NULL,
    annee_scolaire VARCHAR(9) DEFAULT NULL,
    login VARCHAR(100) DEFAULT NULL UNIQUE,
    password_hash VARCHAR(255) DEFAULT NULL,
    must_change_password TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (groupe_id) REFERENCES groupes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE sessions_eval ADD COLUMN stagiaire_id INT DEFAULT NULL AFTER token;
ALTER TABLE sessions_eval ADD CONSTRAINT fk_sessions_stagiaire FOREIGN KEY (stagiaire_id) REFERENCES stagiaires(id) ON DELETE SET NULL;
