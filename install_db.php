<?php
// ============================================================
// install_db.php - Setup complet base de donnees
// ============================================================

$host    = $argv[1] ?? '127.0.0.1';
$user    = $argv[2] ?? 'root';
$pass    = $argv[3] ?? '';
$dbname  = $argv[4] ?? 'eval_online';
$projDir = rtrim($argv[5] ?? __DIR__, '/\\');

function run(PDO $pdo, string $sql): void {
    $stmts = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($stmts as $s) {
        if (empty($s) || str_starts_with($s, '--') || str_starts_with($s, '/*')) continue;
        try {
            $pdo->exec($s);
        } catch (PDOException $e) {
            if (!preg_match('/Duplicate|already exists|Foreign key/i', $e->getMessage())) {
                fwrite(STDERR, "  ! " . $e->getMessage() . "\n");
            }
        }
    }
}

try {
    // 1. Connexion root
    $pdo = new PDO("mysql:host=$host", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 2. Creer base
    $pdo->exec("DROP DATABASE IF EXISTS `$dbname`");
    $pdo->exec("CREATE DATABASE `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "[OK] Base '$dbname' creee\n";

    // 3. Reconnexion
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 4. Import schema_clean.sql (standalone, inclut toutes migrations)
    $schemaFile = $projDir . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'schema_clean.sql';
    if (!file_exists($schemaFile)) {
        $schemaFile = $projDir . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'schema.sql';
    }
    if (!file_exists($schemaFile)) {
        fwrite(STDERR, "[ERREUR] Fichier schema introuvable\n");
        exit(1);
    }
    run($pdo, file_get_contents($schemaFile));
    echo "[OK] Schema importe\n";

    // 5. Compte admin
    $hash = password_hash('admin123', PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO admins (username, password_hash, nom) VALUES ('admin', '$hash', 'Administrateur')");
    echo "[OK] Compte admin cree (admin / admin123)\n";

    // 6. Groupes demo
    $pdo->exec("INSERT INTO groupes (nom) VALUES ('Groupe A - 2025')");
    $pdo->exec("INSERT INTO groupes (nom) VALUES ('Groupe B - 2025')");
    $pdo->exec("INSERT INTO groupes (nom) VALUES ('Groupe C - 2025')");
    echo "[OK] Groupes demo inseres\n";

    // 7. Module demo
    $pdo->exec("INSERT INTO modules (id, nom, description, duree_minutes, note_max, actif) VALUES (1, 'Module Demo', 'Evaluation de demonstration', 30, 20, 1)");
    echo "[OK] Module demo insere\n";

    // 8. Questions demo
    $pdo->exec("INSERT INTO questions (id, module_id, texte, type, points, ordre) VALUES (1, 1, 'Quelle est la capitale de la France ?', 'qcm', 5, 1)");
    $pdo->exec("INSERT INTO questions (id, module_id, texte, type, points, ordre) VALUES (2, 1, 'La Terre est ronde.', 'vrai_faux', 5, 2)");
    $pdo->exec("INSERT INTO questions (id, module_id, texte, type, points, ordre) VALUES (3, 1, 'Decrivez votre parcours en quelques lignes.', 'texte_libre', 10, 3)");
    echo "[OK] Questions demo inserees\n";

    // 9. Choix reponses
    $pdo->exec("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (1, 'Paris', 1, 1)");
    $pdo->exec("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (1, 'Lyon', 0, 2)");
    $pdo->exec("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (1, 'Marseille', 0, 3)");
    $pdo->exec("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (2, 'Vrai', 1, 1)");
    $pdo->exec("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (2, 'Faux', 0, 2)");
    echo "[OK] Choix reponses inseres\n";

    // 10. Config
    $pdo->exec("INSERT INTO config (cle, valeur) VALUES ('anthropic_api_key', '')");
    echo "[OK] Config initialisee\n";

    echo "\n[SUCCES] Deploiement base de donnees complete.\n";
    echo "  Admin : admin / admin123\n";
    exit(0);

} catch (Exception $e) {
    fwrite(STDERR, "[ERREUR] " . $e->getMessage() . "\n");
    exit(1);
}
