-- ============================================================
-- Outil d'évaluation en ligne - Schéma de base de données
-- ============================================================

CREATE DATABASE IF NOT EXISTS eval_online CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE eval_online;

-- Table des groupes de stagiaires
CREATE TABLE IF NOT EXISTS groupes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Table des modules / matières
CREATE TABLE IF NOT EXISTS modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(150) NOT NULL,
    description TEXT,
    duree_minutes INT DEFAULT 30,
    note_max INT DEFAULT 20 COMMENT 'Notation sur 20 ou 40',
    actif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Table des questions
CREATE TABLE IF NOT EXISTS questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module_id INT NOT NULL,
    texte TEXT NOT NULL,
    type ENUM('qcm', 'vrai_faux', 'texte_libre', 'multiple') NOT NULL DEFAULT 'qcm',
    points DECIMAL(5,2) DEFAULT 1.00,
    ordre INT DEFAULT 0,
    image_path VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Table des choix de réponses (pour QCM et vrai/faux)
CREATE TABLE IF NOT EXISTS choix_reponses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    texte VARCHAR(500) NOT NULL,
    is_correct TINYINT(1) DEFAULT 0,
    ordre INT DEFAULT 0,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Table des stagiaires (comptes authentifiés)
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

-- Table des sessions d'évaluation (une par stagiaire)
CREATE TABLE IF NOT EXISTS sessions_eval (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    stagiaire_id INT DEFAULT NULL,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    groupe_id INT,
    groupe_libre VARCHAR(100),
    module_id INT NOT NULL,
    date_debut TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_fin TIMESTAMP NULL,
    score DECIMAL(6,2) DEFAULT 0,
    total_points DECIMAL(6,2) DEFAULT 0,
    pourcentage DECIMAL(5,2) DEFAULT 0,
    statut ENUM('en_cours', 'termine') DEFAULT 'en_cours',
    FOREIGN KEY (stagiaire_id) REFERENCES stagiaires(id) ON DELETE SET NULL,
    FOREIGN KEY (groupe_id) REFERENCES groupes(id) ON DELETE SET NULL,
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Table des réponses données par les stagiaires
CREATE TABLE IF NOT EXISTS reponses_stagiaires (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    question_id INT NOT NULL,
    choix_id INT DEFAULT NULL,
    reponse_texte TEXT DEFAULT NULL,
    is_correct TINYINT(1) DEFAULT 0,
    points_obtenus DECIMAL(5,2) DEFAULT 0,
    FOREIGN KEY (session_id) REFERENCES sessions_eval(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
    FOREIGN KEY (choix_id) REFERENCES choix_reponses(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Table des administrateurs
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    nom VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Table de configuration générale (clé API, etc.)
CREATE TABLE IF NOT EXISTS config (
    cle VARCHAR(100) PRIMARY KEY,
    valeur TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- Données de démonstration
-- ============================================================

-- Admin par défaut (mot de passe : admin123)
INSERT INTO admins (username, password_hash, nom) VALUES
('admin', '$2y$10$NxdWcjfkgvVxCFdIdTmdneMpukmP85OYB26IN8lQGvMV2tONeQnHW', 'Administrateur');

-- Groupes
INSERT INTO groupes (nom) VALUES
('Groupe A - BTS SIO'),
('Groupe B - BTS SIO'),
('Groupe A - TSSR'),
('Groupe B - TSSR'),
('Groupe CDA');

-- Module exemple : Réseaux
INSERT INTO modules (nom, description, duree_minutes, note_max) VALUES
('Réseaux TCP/IP - Fondamentaux', 'Évaluation sur les bases des réseaux TCP/IP, modèle OSI, adressage IP.', 45, 20),
('Sécurité Informatique', 'Évaluation sur les fondamentaux de la cybersécurité.', 30, 20),
('Excel - Tableaux et Formules', 'Maîtrise des fonctions Excel et tableaux croisés dynamiques.', 40, 20);

-- Questions pour le module Réseaux
INSERT INTO questions (module_id, texte, type, points, ordre) VALUES
(1, 'Combien de couches comporte le modèle OSI ?', 'qcm', 1, 1),
(1, 'Quelle est l\'adresse IP de loopback standard ?', 'qcm', 1, 2),
(1, 'Le protocole TCP garantit la livraison des paquets.', 'vrai_faux', 1, 3),
(1, 'Quel protocole est utilisé pour la résolution de noms de domaine ?', 'qcm', 1, 4),
(1, 'Expliquez en 3-5 lignes la différence entre TCP et UDP.', 'texte_libre', 2, 5);

-- Choix pour Q1 (Couches OSI)
INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES
(1, 'A. 4 couches', 0, 1),
(1, 'B. 5 couches', 0, 2),
(1, 'C. 7 couches', 1, 3),
(1, 'D. 9 couches', 0, 4);

-- Choix pour Q2 (Loopback)
INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES
(2, 'A. 192.168.0.1', 0, 1),
(2, 'B. 127.0.0.1', 1, 2),
(2, 'C. 10.0.0.1', 0, 3),
(2, 'D. 255.255.255.0', 0, 4);

-- Choix pour Q3 (TCP vrai/faux)
INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES
(3, 'Vrai', 1, 1),
(3, 'Faux', 0, 2);

-- Choix pour Q4 (DNS)
INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES
(4, 'A. DHCP', 0, 1),
(4, 'B. FTP', 0, 2),
(4, 'C. DNS', 1, 3),
(4, 'D. SNMP', 0, 4);
