-- ============================================================
-- Migration v2 — À exécuter sur une base existante
-- (si vous avez déjà importé schema.sql avant cette mise à jour)
-- ============================================================
USE eval_online;

-- Ajout de la notation /20 ou /40 sur les modules
ALTER TABLE modules
    ADD COLUMN IF NOT EXISTS note_max INT DEFAULT 20
        COMMENT 'Notation sur 20 ou 40' AFTER duree_minutes;

-- Table de configuration (clé API, etc.)
CREATE TABLE IF NOT EXISTS config (
    cle VARCHAR(100) PRIMARY KEY,
    valeur TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
