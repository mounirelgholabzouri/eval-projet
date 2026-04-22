<?php
// ============================================================
// install_db.php - Setup complet base de donnees
// ============================================================

$host    = $argv[1] ?? '127.0.0.1';
$user    = $argv[2] ?? 'root';
$pass    = $argv[3] ?? '';
$dbname  = $argv[4] ?? 'eval_online';

try {
    // 1. Connexion root
    $pdo = new PDO("mysql:host=$host", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 2. Creer/Reinitialiser base
    $pdo->exec("DROP DATABASE IF EXISTS `$dbname`");
    $pdo->exec("CREATE DATABASE `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "[OK] Base '$dbname' creee\n";

    // 3. Reconnexion
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 4. Tables dans le bon ordre (sans FK d'abord, puis avec FK)

    // Tables sans dependances
    $pdo->exec("CREATE TABLE groupes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE modules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(150) NOT NULL,
        description TEXT,
        duree_minutes INT DEFAULT 30,
        note_max INT DEFAULT 20,
        actif TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        nom VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE config (
        cle VARCHAR(100) PRIMARY KEY,
        valeur TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Questions (depend de modules)
    $pdo->exec("CREATE TABLE questions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        module_id INT NOT NULL,
        texte TEXT NOT NULL,
        type ENUM('qcm', 'vrai_faux', 'texte_libre', 'multiple') NOT NULL DEFAULT 'qcm',
        points DECIMAL(5,2) DEFAULT 1.00,
        ordre INT DEFAULT 0,
        image_path VARCHAR(255) DEFAULT NULL,
        FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Choix reponses (depend de questions)
    $pdo->exec("CREATE TABLE choix_reponses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        question_id INT NOT NULL,
        texte VARCHAR(500) NOT NULL,
        is_correct TINYINT(1) DEFAULT 0,
        ordre INT DEFAULT 0,
        FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Stagiaires (depend de groupes)
    $pdo->exec("CREATE TABLE stagiaires (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Sessions evaluation (depend de stagiaires, groupes, modules)
    $pdo->exec("CREATE TABLE sessions_eval (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(64) NOT NULL UNIQUE,
        stagiaire_id INT DEFAULT NULL,
        nom VARCHAR(100) NOT NULL,
        prenom VARCHAR(100) NOT NULL,
        groupe_id INT DEFAULT NULL,
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Reponses stagiaires (depend de sessions_eval, questions, choix_reponses)
    $pdo->exec("CREATE TABLE reponses_stagiaires (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo "[OK] Schema importe\n";

    // 5. Donnees de demo
    $hash = password_hash('admin123', PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO admins (username, password_hash, nom) VALUES ('admin', '$hash', 'Administrateur')");
    echo "[OK] Compte admin cree\n";

    $pdo->exec("INSERT INTO groupes (nom) VALUES ('Groupe A - 2025')");
    $pdo->exec("INSERT INTO groupes (nom) VALUES ('Groupe B - 2025')");
    $pdo->exec("INSERT INTO groupes (nom) VALUES ('Groupe C - 2025')");
    echo "[OK] Groupes demo inseres\n";

    $pdo->exec("INSERT INTO modules (id, nom, description, duree_minutes, note_max, actif) VALUES (1, 'Module Demo', 'Evaluation de demonstration', 30, 20, 1)");
    echo "[OK] Module demo insere\n";

    $pdo->exec("INSERT INTO questions (id, module_id, texte, type, points, ordre) VALUES (1, 1, 'Quelle est la capitale de la France ?', 'qcm', 5, 1)");
    $pdo->exec("INSERT INTO questions (id, module_id, texte, type, points, ordre) VALUES (2, 1, 'La Terre est ronde.', 'vrai_faux', 5, 2)");
    $pdo->exec("INSERT INTO questions (id, module_id, texte, type, points, ordre) VALUES (3, 1, 'Decrivez votre parcours en quelques lignes.', 'texte_libre', 10, 3)");
    echo "[OK] Questions demo inserees\n";

    $pdo->exec("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (1, 'Paris', 1, 1)");
    $pdo->exec("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (1, 'Lyon', 0, 2)");
    $pdo->exec("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (1, 'Marseille', 0, 3)");
    $pdo->exec("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (2, 'Vrai', 1, 1)");
    $pdo->exec("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (2, 'Faux', 0, 2)");
    echo "[OK] Choix reponses inseres\n";

    $pdo->exec("INSERT INTO config (cle, valeur) VALUES ('anthropic_api_key', '')");
    echo "[OK] Config initialisee\n";

    echo "\n[SUCCES] Deploiement complete.\n";
    echo "  Admin : admin / admin123\n";
    echo "  URL   : http://localhost/eval-projet/\n";
    exit(0);

} catch (Exception $e) {
    fwrite(STDERR, "[ERREUR] " . $e->getMessage() . "\n");
    exit(1);
}
