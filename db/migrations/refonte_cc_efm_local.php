<?php
/**
 * Adaptation locale de db/refonte_cc_efm.php (qui est câblé sur les IDs d'évaluations/modules
 * du "poste principal" et ne s'applique pas à cette base). Même objectif — aligner le schéma
 * sur db/schema.sql (type/mode/numero/contenu, evaluation_questions) — sans fusion de modules
 * ni suppression d'évaluations (aucune des deux n'a de sens sur les données de cette base).
 *
 * Ne fait AUCUN DROP (table/colonne) : uniquement des ajouts et des migrations de données,
 * réversibles. Le nettoyage final (DROP parties, partie_id, categorie, parties_ids,
 * nb_questions_controle) est un script séparé, à lancer après vérification.
 *
 * Usage : php db/migrations/refonte_cc_efm_local.php [--dry]
 */
require_once __DIR__ . '/../../config/database.php';

$dry = in_array('--dry', $argv, true);
$pdo = getDB();

function hasColumn(PDO $pdo, string $table, string $col): bool {
    return in_array($col, array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field'), true);
}

if (hasColumn($pdo, 'evaluations', 'mode')) {
    exit("Déjà appliqué (evaluations.mode existe).\n");
}

if ($dry) {
    $nb = (int)$pdo->query("SELECT COUNT(*) FROM evaluations")->fetchColumn();
    exit("Contrôles OK ($nb évaluations). Aucune écriture (--dry).\n");
}

// ── A. Colonnes additives (evaluations) ─────────────────────────
$pdo->exec("ALTER TABLE evaluations MODIFY type ENUM('qcm','efm','pratique','mixte','cc') NOT NULL DEFAULT 'qcm',
            ADD COLUMN mode ENUM('theorique','pratique') NULL AFTER type,
            ADD COLUMN numero TINYINT UNSIGNED NULL AFTER mode,
            ADD COLUMN contenu VARCHAR(255) NULL AFTER numero");

// ── B. evaluation_questions ──────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS evaluation_questions (
    evaluation_id INT NOT NULL,
    question_id   INT NOT NULL,
    ordre         INT NOT NULL DEFAULT 0,
    PRIMARY KEY (evaluation_id, question_id),
    KEY idx_eq_question (question_id),
    CONSTRAINT fk_eq_eval     FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE,
    CONSTRAINT fk_eq_question FOREIGN KEY (question_id)   REFERENCES questions(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->beginTransaction();
try {
    // 1. type/mode/numero/contenu — pas de données par évaluation disponibles pour les QCM/EFM
    //    (pas d'équivalent au mapping $evals du script d'origine) : mode/numero/contenu restent
    //    NULL pour ces deux types, ce que l'UI gère (nomEvaluation() affiche juste "CC"/"EFM").
    $pdo->exec("UPDATE evaluations SET type = 'cc', mode = 'theorique' WHERE type = 'qcm'");
    $pdo->exec("UPDATE evaluations SET mode = NULL WHERE type = 'efm'");
    // Pratique : numero connu via meta_json->numero (confirmé présent sur toutes les lignes cc_pratique)
    $pdo->exec("UPDATE evaluations SET type = 'cc', mode = 'pratique',
                numero = JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.numero'))
                WHERE categorie = 'cc_pratique'");

    // Enum final (plus de valeurs qcm/pratique/mixte restantes)
    $pdo->exec("ALTER TABLE evaluations MODIFY type ENUM('cc','efm') NOT NULL DEFAULT 'cc'");

    // 2. evaluation_questions : chaque évaluation théorique/EFM garde les questions de son module
    //    (parties_ids était NULL partout = pas de restriction côté module), question d'une partie
    //    inactive exclue (invariant pré-refonte : "partie actif=0 -> exclue partout").
    $pdo->exec("INSERT IGNORE INTO evaluation_questions (evaluation_id, question_id, ordre)
                SELECT e.id, q.id, q.ordre
                FROM evaluations e
                JOIN questions q ON q.module_id = e.module_id
                LEFT JOIN parties p ON p.id = q.partie_id
                WHERE NOT (e.type = 'cc' AND e.mode = 'pratique')
                  AND (p.id IS NULL OR p.actif = 1)");

    // 3. Quota de tirage : nb_questions_controle (module) -> nb_questions (évaluation)
    $pdo->exec("UPDATE evaluations e JOIN modules m ON m.id = e.module_id
                SET e.nb_questions = m.nb_questions_controle
                WHERE e.nb_questions IS NULL AND NOT (e.type = 'cc' AND e.mode = 'pratique')
                  AND m.nb_questions_controle IS NOT NULL");

    // 4. Visibilité inchangée
    $pdo->exec("UPDATE evaluations e JOIN modules m ON m.id = e.module_id SET e.actif = (e.actif AND m.actif)");

    $pdo->commit();
} catch (Throwable $t) {
    $pdo->rollBack();
    exit("ERREUR, données annulées (DDL additif déjà appliqué, à vérifier manuellement) : " . $t->getMessage() . "\n");
}

// ── C. stagiaires.numero_classe (migration existante, idempotente) ──
if (!hasColumn($pdo, 'stagiaires', 'numero_classe')) {
    require __DIR__ . '/add_numero_classe_to_stagiaires.php';
}

$nbEq = (int)$pdo->query("SELECT COUNT(*) FROM evaluation_questions")->fetchColumn();
echo "OK : " . $pdo->query("SELECT COUNT(*) FROM evaluations")->fetchColumn() . " évaluations migrées, "
   . "$nbEq lignes evaluation_questions créées.\n";
echo "Colonnes obsolètes conservées (categorie, parties_ids, modules.nb_questions_controle, questions.partie_id, table parties) — nettoyage séparé après vérification.\n";
