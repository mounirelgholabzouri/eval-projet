
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('admin','formateur') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'admin',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `choix_reponses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `choix_reponses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `question_id` int NOT NULL,
  `texte` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_correct` tinyint(1) DEFAULT '0',
  `ordre` int DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `question_id` (`question_id`),
  CONSTRAINT `choix_reponses_ibfk_1` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4235 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `config` (
  `cle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valeur` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `eval_pratique`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `eval_pratique` (
  `id` int NOT NULL AUTO_INCREMENT,
  `titre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `module_code` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `module_intitule` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `filiere` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `etablissement` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `annee` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `duree` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '2h',
  `note_max` int DEFAULT '20',
  `prompt_sujet` text COLLATE utf8mb4_unicode_ci,
  `sujet_texte` longtext COLLATE utf8mb4_unicode_ci,
  `structure_json` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `eval_pratique_parties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `eval_pratique_parties` (
  `id` int NOT NULL AUTO_INCREMENT,
  `eval_id` int NOT NULL,
  `numero` int NOT NULL,
  `titre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `points` float DEFAULT '5',
  `ordre` int DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `fk_epp_eval` (`eval_id`),
  CONSTRAINT `fk_epp_eval` FOREIGN KEY (`eval_id`) REFERENCES `eval_pratique` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `eval_pratique_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `eval_pratique_questions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `partie_id` int NOT NULL,
  `eval_id` int NOT NULL,
  `numero` int NOT NULL,
  `texte` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `points` float DEFAULT '2.5',
  `criteres` text COLLATE utf8mb4_unicode_ci,
  `ordre` int DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `fk_epq_partie` (`partie_id`),
  KEY `fk_epq_eval` (`eval_id`),
  CONSTRAINT `fk_epq_eval` FOREIGN KEY (`eval_id`) REFERENCES `eval_pratique` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_epq_partie` FOREIGN KEY (`partie_id`) REFERENCES `eval_pratique_parties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `formateur_groupes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `formateur_groupes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `formateur_id` int NOT NULL,
  `groupe_id` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fg` (`formateur_id`,`groupe_id`),
  KEY `fk_fg_groupe` (`groupe_id`),
  CONSTRAINT `fk_fg_formateur` FOREIGN KEY (`formateur_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fg_groupe` FOREIGN KEY (`groupe_id`) REFERENCES `groupes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `groupes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groupes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `module_formateurs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `module_formateurs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `formateur_id` int NOT NULL,
  `module_id` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mf` (`formateur_id`,`module_id`),
  KEY `fk_mf_module` (`module_id`),
  CONSTRAINT `fk_mf_formateur` FOREIGN KEY (`formateur_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mf_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `modules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `duree_minutes` int DEFAULT '30',
  `note_max` int DEFAULT '20' COMMENT 'Notation sur 20 ou 40',
  `actif` tinyint(1) DEFAULT '1',
  `type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'qcm',
  `meta_json` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `nb_questions_controle` int DEFAULT NULL COMMENT 'Nb questions tirées aléatoirement (NULL = toutes)',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `modules_efm_meta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `modules_efm_meta` (
  `id` int NOT NULL AUTO_INCREMENT,
  `module_id` int NOT NULL,
  `code_module` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `filiere` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `etablissement` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `annee` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `module_id` (`module_id`),
  CONSTRAINT `fk_mem_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `parties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `parties` (
  `id` int NOT NULL AUTO_INCREMENT,
  `module_id` int NOT NULL,
  `nom` varchar(200) NOT NULL,
  `ordre` int DEFAULT '0',
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `module_id` (`module_id`),
  CONSTRAINT `parties_ibfk_1` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=91 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `questions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `module_id` int NOT NULL,
  `partie_id` int NOT NULL,
  `texte` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('qcm','vrai_faux','texte_libre','multiple') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'qcm',
  `points` decimal(5,2) DEFAULT '1.00',
  `ordre` int DEFAULT '0',
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_module_partie` (`module_id`,`partie_id`),
  KEY `fk_question_partie` (`partie_id`),
  CONSTRAINT `fk_question_partie` FOREIGN KEY (`partie_id`) REFERENCES `parties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `questions_ibfk_1` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1189 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reponses_stagiaires`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reponses_stagiaires` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_id` int NOT NULL,
  `question_id` int NOT NULL,
  `choix_id` int DEFAULT NULL,
  `reponse_texte` text COLLATE utf8mb4_unicode_ci,
  `is_correct` tinyint(1) DEFAULT '0',
  `points_obtenus` decimal(5,2) DEFAULT '0.00',
  `source_correction` enum('ia','manuel') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correction_ia_feedback` text COLLATE utf8mb4_unicode_ci,
  `correction_ia_points_suggeres` decimal(5,2) DEFAULT NULL,
  `correction_ia_niveau` tinyint DEFAULT NULL,
  `correction_ia_date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `session_id` (`session_id`),
  KEY `question_id` (`question_id`),
  KEY `choix_id` (`choix_id`),
  CONSTRAINT `reponses_stagiaires_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `sessions_eval` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reponses_stagiaires_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reponses_stagiaires_ibfk_3` FOREIGN KEY (`choix_id`) REFERENCES `choix_reponses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6172 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions_eval`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions_eval` (
  `id` int NOT NULL AUTO_INCREMENT,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `groupe_id` int DEFAULT NULL,
  `groupe_libre` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stagiaire_id` int DEFAULT NULL,
  `module_id` int NOT NULL,
  `date_debut` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `date_fin` timestamp NULL DEFAULT NULL,
  `score` decimal(6,2) DEFAULT '0.00',
  `total_points` decimal(6,2) DEFAULT '0.00',
  `pourcentage` decimal(5,2) DEFAULT '0.00',
  `statut` enum('en_cours','termine') COLLATE utf8mb4_unicode_ci DEFAULT 'en_cours',
  `questions_ids` text COLLATE utf8mb4_unicode_ci COMMENT 'JSON array des IDs questions tirés pour cette session',
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `groupe_id` (`groupe_id`),
  KEY `module_id` (`module_id`),
  CONSTRAINT `sessions_eval_ibfk_1` FOREIGN KEY (`groupe_id`) REFERENCES `groupes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sessions_eval_ibfk_2` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=261 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stagiaires`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stagiaires` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `groupe_id` int NOT NULL,
  `annee_scolaire` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '2025-2026',
  `login` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`),
  KEY `groupe_id` (`groupe_id`),
  CONSTRAINT `stagiaires_ibfk_1` FOREIGN KEY (`groupe_id`) REFERENCES `groupes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

