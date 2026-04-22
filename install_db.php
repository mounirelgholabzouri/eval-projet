<?php
// ============================================================
// install_db.php - Setup complet base de donnees
// Usage : php install_db.php [host] [user] [pass] [dbname] [projdir]
// ============================================================

$host    = $argv[1] ?? '127.0.0.1';
$user    = $argv[2] ?? 'root';
$pass    = $argv[3] ?? '';
$dbname  = $argv[4] ?? 'eval_online';
$projDir = rtrim($argv[5] ?? __DIR__, '/\\');

function run(PDO $pdo, string $sql): void {
    $stmts = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($stmts as $s) {
        if ($s === '' || str_starts_with($s, '--') || str_starts_with($s, '/*')) continue;
        try {
            $pdo->exec($s);
        } catch (PDOException $e) {
            if (!preg_match('/Duplicate|already exists/i', $e->getMessage())) {
                fwrite(STDERR, "  ! " . $e->getMessage() . "\n");
            }
        }
    }
}

try {
    // 1. Connexion root
    $pdo = new PDO("mysql:host=$host", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 2. Creation base
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "[OK] Base '$dbname' creee\n";

    // 3. Reconnexion sur la base
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 4. Import schema.sql
    $schema = $projDir . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'schema.sql';
    if (!file_exists($schema)) {
        fwrite(STDERR, "[ERREUR] schema.sql introuvable : $schema\n");
        exit(1);
    }
    run($pdo, file_get_contents($schema));
    echo "[OK] Schema importe\n";

    // 5. Import migrations
    $migrations = glob($projDir . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migration_*.sql');
    foreach ($migrations as $mig) {
        run($pdo, file_get_contents($mig));
        echo "[OK] Migration : " . basename($mig) . "\n";
    }

    // 6. Compte admin par defaut
    $hash = password_hash('admin123', PASSWORD_BCRYPT);
    $pdo->exec("INSERT IGNORE INTO admins (username, password_hash, nom) VALUES ('admin', '$hash', 'Administrateur')");
    echo "[OK] Compte admin cree (admin / admin123)\n";

    // 7. Groupes de demo
    $pdo->exec("INSERT IGNORE INTO groupes (nom) VALUES ('Groupe A - 2025')");
    $pdo->exec("INSERT IGNORE INTO groupes (nom) VALUES ('Groupe B - 2025')");
    $pdo->exec("INSERT IGNORE INTO groupes (nom) VALUES ('Groupe C - 2025')");
    echo "[OK] Groupes demo inseres\n";

    // 8. Module de demo
    $pdo->exec("INSERT IGNORE INTO modules (id, nom, description, duree_minutes, note_max, actif) VALUES (1, 'Module Demo', 'Evaluation de demonstration', 30, 20, 1)");
    echo "[OK] Module demo insere\n";

    // 9. Questions de demo
    $pdo->exec("INSERT IGNORE INTO questions (id, module_id, texte, type, points, ordre) VALUES (1, 1, 'Quelle est la capitale de la France ?', 'qcm', 5, 1)");
    $pdo->exec("INSERT IGNORE INTO questions (id, module_id, texte, type, points, ordre) VALUES (2, 1, 'La Terre est ronde.', 'vrai_faux', 5, 2)");
    $pdo->exec("INSERT IGNORE INTO questions (id, module_id, texte, type, points, ordre) VALUES (3, 1, 'Decrivez votre parcours en quelques lignes.', 'texte_libre', 10, 3)");

    // 10. Choix reponses
    $pdo->exec("INSERT IGNORE INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (1, 'Paris', 1, 1)");
    $pdo->exec("INSERT IGNORE INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (1, 'Lyon', 0, 2)");
    $pdo->exec("INSERT IGNORE INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (1, 'Marseille', 0, 3)");
    $pdo->exec("INSERT IGNORE INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (2, 'Vrai', 1, 1)");
    $pdo->exec("INSERT IGNORE INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (2, 'Faux', 0, 2)");
    echo "[OK] Questions et reponses demo inserees\n";

    // 11. Config API Claude (vide)
    $pdo->exec("INSERT IGNORE INTO config (cle, valeur) VALUES ('anthropic_api_key', '')");
    echo "[OK] Config initialisee\n";

    echo "\n[SUCCES] Base de donnees prete.\n";
    echo "  Admin : admin / admin123\n";
    exit(0);

} catch (Exception $e) {
    fwrite(STDERR, "[ERREUR] " . $e->getMessage() . "\n");
    exit(1);
}
