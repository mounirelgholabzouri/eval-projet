<?php
/**
 * Migration : table questions_stats — taux de réussite de chaque question par groupe.
 * Alimentée par « Préparer CC3 » (admin/gestion_impression.php), affichée dans la banque de questions.
 * Idempotente.
 */
require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();
$existe = $pdo->query("SHOW TABLES LIKE 'questions_stats'")->fetchColumn();
if ($existe) { echo "questions_stats existe déjà — rien à faire.\n"; exit; }

$pdo->exec("CREATE TABLE questions_stats (
    question_id  INT NOT NULL,
    groupe_id    INT NOT NULL,
    nb_reponses  INT NOT NULL DEFAULT 0,
    nb_justes    INT NOT NULL DEFAULT 0,
    taux         DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'pourcentage de réponses justes',
    updated_at   TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (question_id, groupe_id),
    KEY idx_qs_groupe (groupe_id),
    CONSTRAINT fk_qs_question FOREIGN KEY (question_id) REFERENCES questions (id) ON DELETE CASCADE,
    CONSTRAINT fk_qs_groupe   FOREIGN KEY (groupe_id)   REFERENCES groupes (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "Table questions_stats créée.\n";
