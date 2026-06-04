<?php
/**
 * Migration : tables eval_pratique, eval_pratique_parties, eval_pratique_questions
 */
require_once __DIR__ . '/../config/database.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "<pre>\n";

// ── eval_pratique ───────────────────────────────────────────────
$cols = [];
try { $cols = array_column($pdo->query("SHOW COLUMNS FROM eval_pratique")->fetchAll(), 'Field'); } catch(Exception $e) {}
if (empty($cols)) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eval_pratique (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            titre         VARCHAR(255) NOT NULL,
            module_code   VARCHAR(100) DEFAULT '',
            module_intitule VARCHAR(255) DEFAULT '',
            filiere       VARCHAR(100) DEFAULT '',
            etablissement VARCHAR(200) DEFAULT '',
            annee         VARCHAR(20)  DEFAULT '',
            duree         VARCHAR(50)  DEFAULT '2h',
            note_max      INT          DEFAULT 20,
            prompt_sujet  TEXT,
            sujet_texte   LONGTEXT,
            structure_json LONGTEXT,
            created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✔ Table eval_pratique créée\n";
} else {
    echo "— Table eval_pratique existe déjà\n";
}

// ── eval_pratique_parties ───────────────────────────────────────
$cols2 = [];
try { $cols2 = array_column($pdo->query("SHOW COLUMNS FROM eval_pratique_parties")->fetchAll(), 'Field'); } catch(Exception $e) {}
if (empty($cols2)) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eval_pratique_parties (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            eval_id         INT NOT NULL,
            numero          INT NOT NULL,
            titre           VARCHAR(255) DEFAULT '',
            points          FLOAT DEFAULT 5,
            ordre           INT DEFAULT 0,
            FOREIGN KEY (eval_id) REFERENCES eval_pratique(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✔ Table eval_pratique_parties créée\n";
} else {
    echo "— Table eval_pratique_parties existe déjà\n";
}

// ── eval_pratique_questions ─────────────────────────────────────
$cols3 = [];
try { $cols3 = array_column($pdo->query("SHOW COLUMNS FROM eval_pratique_questions")->fetchAll(), 'Field'); } catch(Exception $e) {}
if (empty($cols3)) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eval_pratique_questions (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            partie_id   INT NOT NULL,
            eval_id     INT NOT NULL,
            numero      INT NOT NULL,
            texte       TEXT NOT NULL,
            points      FLOAT DEFAULT 2.5,
            criteres    TEXT,
            ordre       INT DEFAULT 0,
            FOREIGN KEY (partie_id) REFERENCES eval_pratique_parties(id) ON DELETE CASCADE,
            FOREIGN KEY (eval_id)   REFERENCES eval_pratique(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✔ Table eval_pratique_questions créée\n";
} else {
    echo "— Table eval_pratique_questions existe déjà\n";
}

echo "\n✅ Migration terminée.\n</pre>";
