-- Migration: Supprimer le système de parties
-- Conserve les questions et leurs données associées

SET FOREIGN_KEY_CHECKS=0;

-- Supprimer les contraintes FK
ALTER TABLE `questions` DROP FOREIGN KEY `fk_question_partie`;

-- Supprimer la colonne partie_id de la table questions
ALTER TABLE `questions` DROP COLUMN `partie_id`;

-- Supprimer la table parties
DROP TABLE IF EXISTS `parties`;

-- Réactiver les FK
SET FOREIGN_KEY_CHECKS=1;

-- Vérifier que la migration est complète
SELECT 'Migration completed: parties system removed' as status;
