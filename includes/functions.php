<?php
require_once __DIR__ . '/../config/database.php';

// ============================================================
// Fonctions utilitaires générales
// ============================================================

function sanitize(string $str): string {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

function generateToken(int $length = 32): string {
    return bin2hex(random_bytes($length));
}

function formatDuration(int $minutes): string {
    if ($minutes < 60) return "{$minutes} min";
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $m > 0 ? "{$h}h" . str_pad($m, 2, '0', STR_PAD_LEFT) : "{$h}h";
}

function getMention(float $pourcentage): array {
    if ($pourcentage >= 90) return ['label' => 'Excellent', 'class' => 'success'];
    if ($pourcentage >= 75) return ['label' => 'Très bien', 'class' => 'success'];
    if ($pourcentage >= 60) return ['label' => 'Bien', 'class' => 'primary'];
    if ($pourcentage >= 50) return ['label' => 'Passable', 'class' => 'warning'];
    return ['label' => 'Insuffisant', 'class' => 'danger'];
}

// ============================================================
// Fonctions modules
// ============================================================

function getModulesActifs(): array {
    $pdo = getDB();
    return $pdo->query("SELECT * FROM modules WHERE actif = 1 ORDER BY nom")->fetchAll();
}


function getModule(int $id): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT m.*,
               COALESCE(em.code_module,   '') AS efm_code_module,
               COALESCE(em.filiere,       '') AS efm_filiere,
               COALESCE(em.etablissement, '') AS efm_etablissement,
               COALESCE(em.annee,         '') AS efm_annee
        FROM modules m
        LEFT JOIN modules_efm_meta em ON em.module_id = m.id
        WHERE m.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getAllModules(): array {
    $pdo = getDB();
    return $pdo->query("
        SELECT m.*,
               COALESCE(m.note_max, 20) AS note_max,
               (SELECT COUNT(*) FROM questions q WHERE q.module_id = m.id) AS nb_questions,
               COALESCE(em.code_module,   '') AS efm_code_module,
               COALESCE(em.filiere,       '') AS efm_filiere,
               COALESCE(em.etablissement, '') AS efm_etablissement,
               COALESCE(em.annee,         '') AS efm_annee
        FROM modules m
        LEFT JOIN modules_efm_meta em ON em.module_id = m.id
        ORDER BY m.nom
    ")->fetchAll();
}

// ============================================================
// Fonctions groupes
// ============================================================

function getGroupes(): array {
    $pdo = getDB();
    return $pdo->query("SELECT * FROM groupes ORDER BY nom")->fetchAll();
}

// ============================================================
// Fonctions questions
// ============================================================

function getQuestionsModule(int $moduleId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE module_id = ? ORDER BY ordre, id");
    $stmt->execute([$moduleId]);
    $questions = $stmt->fetchAll();

    foreach ($questions as &$q) {
        $stmt2 = $pdo->prepare("SELECT * FROM choix_reponses WHERE question_id = ? ORDER BY ordre, id");
        $stmt2->execute([$q['id']]);
        $q['choix'] = $stmt2->fetchAll();
    }
    return $questions;
}


function getTotalPoints(int $moduleId): float {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(points), 0) AS total FROM questions WHERE module_id = ?");
    $stmt->execute([$moduleId]);
    return (float)$stmt->fetchColumn();
}


function supprimerSession(int $sessionId): void {
    $pdo = getDB();
    $pdo->prepare("DELETE FROM reponses_stagiaires WHERE session_id = ?")->execute([$sessionId]);
    $pdo->prepare("DELETE FROM sessions_eval WHERE id = ?")->execute([$sessionId]);
}

function supprimerModule(int $moduleId): void {
    $pdo = getDB();
    // 1. Réponses des sessions liées au module
    $sids = $pdo->prepare("SELECT id FROM sessions_eval WHERE module_id = ?");
    $sids->execute([$moduleId]);
    foreach ($sids->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $pdo->prepare("DELETE FROM reponses_stagiaires WHERE session_id = ?")->execute([$sid]);
    }
    // 2. Sessions
    $pdo->prepare("DELETE FROM sessions_eval WHERE module_id = ?")->execute([$moduleId]);
    // 3. Choix des questions
    $qids = $pdo->prepare("SELECT id FROM questions WHERE module_id = ?");
    $qids->execute([$moduleId]);
    foreach ($qids->fetchAll(PDO::FETCH_COLUMN) as $qid) {
        $pdo->prepare("DELETE FROM choix_reponses WHERE question_id = ?")->execute([$qid]);
    }
    // 4. Questions + module
    $pdo->prepare("DELETE FROM questions WHERE module_id = ?")->execute([$moduleId]);
    $pdo->prepare("DELETE FROM modules WHERE id = ?")->execute([$moduleId]);
}

// ============================================================
// Fonctions sessions d'évaluation
// ============================================================

function creerSession(string $nom, string $prenom, ?int $groupeId, string $groupeLibre, int $moduleId, ?int $stagiaireId = null): array {
    $pdo = getDB();
    $token = generateToken();
    $totalPoints = getTotalPoints($moduleId);

    $stmt = $pdo->prepare("INSERT INTO sessions_eval (token, nom, prenom, groupe_id, groupe_libre, module_id, total_points, stagiaire_id)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$token, $nom, $prenom, $groupeId ?: null, $groupeLibre, $moduleId, $totalPoints, $stagiaireId]);

    return ['id' => (int)$pdo->lastInsertId(), 'token' => $token];
}

function getSession(int $id): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT s.*,
               COALESCE(st.nom,    s.nom)    AS nom,
               COALESCE(st.prenom, s.prenom) AS prenom,
               m.nom AS module_nom, m.duree_minutes,
               m.type AS module_type,
               COALESCE(g.nom, s.groupe_libre) AS groupe_nom,
               COALESCE(em.code_module,   '') AS efm_code_module,
               COALESCE(em.filiere,       '') AS efm_filiere,
               COALESCE(em.etablissement, '') AS efm_etablissement,
               COALESCE(em.annee,         '') AS efm_annee
        FROM sessions_eval s
        JOIN modules m ON m.id = s.module_id
        LEFT JOIN stagiaires st ON st.id = s.stagiaire_id
        LEFT JOIN groupes g ON g.id = s.groupe_id
        LEFT JOIN modules_efm_meta em ON em.module_id = m.id
        WHERE s.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getSessionByToken(string $token): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT s.*,
               COALESCE(st.nom,    s.nom)    AS nom,
               COALESCE(st.prenom, s.prenom) AS prenom,
               m.nom AS module_nom, m.duree_minutes,
               m.type AS module_type,
               COALESCE(g.nom, s.groupe_libre) AS groupe_nom,
               COALESCE(em.code_module,   '') AS efm_code_module,
               COALESCE(em.filiere,       '') AS efm_filiere,
               COALESCE(em.etablissement, '') AS efm_etablissement,
               COALESCE(em.annee,         '') AS efm_annee
        FROM sessions_eval s
        JOIN modules m ON m.id = s.module_id
        LEFT JOIN stagiaires st ON st.id = s.stagiaire_id
        LEFT JOIN groupes g ON g.id = s.groupe_id
        LEFT JOIN modules_efm_meta em ON em.module_id = m.id
        WHERE s.token = ?
    ");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function sauvegarderReponse(int $sessionId, int $questionId, ?int $choixId, ?string $reponseTxt, bool $isCorrect, float $points): void {
    $pdo = getDB();
    // Upsert (remplace si déjà répondu)
    $stmt = $pdo->prepare("
        INSERT INTO reponses_stagiaires (session_id, question_id, choix_id, reponse_texte, is_correct, points_obtenus)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE choix_id = VALUES(choix_id), reponse_texte = VALUES(reponse_texte),
                                is_correct = VALUES(is_correct), points_obtenus = VALUES(points_obtenus)
    ");
    $stmt->execute([$sessionId, $questionId, $choixId, $reponseTxt, $isCorrect ? 1 : 0, $points]);
}

function terminerSession(int $sessionId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(points_obtenus), 0) AS score FROM reponses_stagiaires WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $score = (float)$stmt->fetchColumn();

    $session = getSession($sessionId);
    $total = (float)$session['total_points'];
    $pct = $total > 0 ? round($score / $total * 100, 2) : 0;

    $stmt2 = $pdo->prepare("UPDATE sessions_eval SET statut='termine', date_fin=NOW(), score=?, pourcentage=? WHERE id=?");
    $stmt2->execute([$score, $pct, $sessionId]);

    return ['score' => $score, 'total' => $total, 'pourcentage' => $pct];
}

function getReponsesSession(int $sessionId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT rs.*, q.texte AS question_texte, q.type, q.points AS points_max,
               cr.texte AS choix_texte
        FROM reponses_stagiaires rs
        JOIN questions q ON q.id = rs.question_id
        LEFT JOIN choix_reponses cr ON cr.id = rs.choix_id
        WHERE rs.session_id = ?
        ORDER BY q.ordre, q.id
    ");
    $stmt->execute([$sessionId]);
    return $stmt->fetchAll();
}

// ============================================================
// Fonctions admin
// ============================================================

function getAllSessions(int $limit = 100, int $offset = 0): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT s.*, m.nom AS module_nom,
               COALESCE(g.nom, s.groupe_libre) AS groupe_nom
        FROM sessions_eval s
        JOIN modules m ON m.id = s.module_id
        LEFT JOIN groupes g ON g.id = s.groupe_id
        ORDER BY s.date_debut DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$limit, $offset]);
    return $stmt->fetchAll();
}

function countSessions(): int {
    $pdo = getDB();
    return (int)$pdo->query("SELECT COUNT(*) FROM sessions_eval")->fetchColumn();
}

function getStatsGlobales(): array {
    $pdo = getDB();
    return [
        'total_sessions'   => (int)$pdo->query("SELECT COUNT(*) FROM sessions_eval")->fetchColumn(),
        'terminees'        => (int)$pdo->query("SELECT COUNT(*) FROM sessions_eval WHERE statut='termine'")->fetchColumn(),
        'moy_pourcentage'  => (float)$pdo->query("SELECT COALESCE(AVG(pourcentage),0) FROM sessions_eval WHERE statut='termine'")->fetchColumn(),
        'nb_modules'       => (int)$pdo->query("SELECT COUNT(*) FROM modules WHERE actif=1")->fetchColumn(),
        'nb_stagiaires'    => (int)$pdo->query("SELECT COUNT(*) FROM stagiaires")->fetchColumn(),
        'nb_groupes'       => (int)$pdo->query("SELECT COUNT(*) FROM groupes")->fetchColumn(),
    ];
}

// ============================================================
// Fonctions stagiaires
// ============================================================

function trouverOuCreerGroupe(string $nom, ?string $annee = null): int {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM groupes WHERE nom = ? LIMIT 1");
    $stmt->execute([trim($nom)]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];
    $stmt2 = $pdo->prepare("INSERT INTO groupes (nom) VALUES (?)");
    $stmt2->execute([trim($nom)]);
    return (int)$pdo->lastInsertId();
}

function trouverOuCreerStagiaire(string $nom, string $prenom, int $groupeId, string $annee): int {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM stagiaires WHERE nom=? AND prenom=? AND groupe_id=? AND annee_scolaire=? LIMIT 1");
    $stmt->execute([trim($nom), trim($prenom), $groupeId, $annee]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];
    $stmt2 = $pdo->prepare("INSERT INTO stagiaires (nom, prenom, groupe_id, annee_scolaire) VALUES (?,?,?,?)");
    $stmt2->execute([trim($nom), trim($prenom), $groupeId, $annee]);
    return (int)$pdo->lastInsertId();
}

function getStagiaires(?int $groupeId = null, ?string $annee = null): array {
    $pdo = getDB();
    $where = []; $params = [];
    if ($groupeId) { $where[] = 's.groupe_id = ?'; $params[] = $groupeId; }
    if ($annee)    { $where[] = 's.annee_scolaire = ?'; $params[] = $annee; }
    $sql = "SELECT s.*, g.nom AS groupe_nom,
                COUNT(se.id) AS nb_evaluations,
                COALESCE(AVG(CASE WHEN se.statut='termine' THEN se.pourcentage END), NULL) AS moy_pourcentage
            FROM stagiaires s
            JOIN groupes g ON g.id = s.groupe_id
            LEFT JOIN sessions_eval se ON se.stagiaire_id = s.id
            " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
            GROUP BY s.id ORDER BY s.annee_scolaire DESC, g.nom, s.nom, s.prenom";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getStagiaire(int $id): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT s.*, g.nom AS groupe_nom FROM stagiaires s JOIN groupes g ON g.id = s.groupe_id WHERE s.id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getAnneesDisponibles(): array {
    $y = (int)date('Y');
    $m = (int)date('m');
    $start = $m >= 9 ? $y : $y - 1;
    $annees = [];
    for ($i = $start + 1; $i >= $start - 1; $i--) {
        $annees[] = $i . '-' . ($i + 1);
    }
    return $annees;
}

function getAnneeCourante(): string {
    $y = (int)date('Y');
    $m = (int)date('m');
    $start = $m >= 9 ? $y : $y - 1;
    return $start . '-' . ($start + 1);
}

function getStagiaireByLogin(string $login): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT s.*, g.nom AS groupe_nom FROM stagiaires s JOIN groupes g ON g.id = s.groupe_id WHERE s.login = ? LIMIT 1");
    $stmt->execute([trim($login)]);
    return $stmt->fetch() ?: null;
}

function loginExists(string $login, int $excludeId = 0): bool {
    $pdo = getDB();
    $sql = "SELECT COUNT(*) FROM stagiaires WHERE login = ?";
    $params = [trim($login)];
    if ($excludeId > 0) { $sql .= " AND id != ?"; $params[] = $excludeId; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
}

function normaliserPourLogin(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','ä'=>'a',
                    'î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u',
                    'ç'=>'c','ñ'=>'n','æ'=>'ae','œ'=>'oe']);
    return preg_replace('/[^a-z0-9]/', '', $s);
}

function genererLogin(string $prenom, string $nom, int $excludeId = 0): string {
    $p = ucfirst(normaliserPourLogin($prenom));
    $n = strtoupper(normaliserPourLogin($nom));
    $base = $p . '.' . $n;
    $login = $base;
    $i = 2;
    while (loginExists($login, $excludeId)) {
        $login = $base . $i;
        $i++;
    }
    return $login;
}

function creerStagiaireAdmin(string $nom, string $prenom, int $groupeId, string $annee): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM stagiaires WHERE nom=? AND prenom=? AND groupe_id=? AND annee_scolaire=? LIMIT 1");
    $stmt->execute([trim($nom), trim($prenom), $groupeId, $annee]);
    if ($stmt->fetchColumn()) {
        throw new RuntimeException("Ce stagiaire existe déjà dans ce groupe pour cette année.");
    }
    $login = genererLogin($prenom, $nom);
    $hash  = password_hash('123456', PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO stagiaires (nom, prenom, groupe_id, annee_scolaire, login, password_hash, must_change_password) VALUES (?,?,?,?,?,?,1)")
        ->execute([trim($nom), trim($prenom), $groupeId, $annee, $login, $hash]);
    return ['id' => (int)$pdo->lastInsertId(), 'login' => $login];
}

function modifierStagiaire(int $id, string $nom, string $prenom, int $groupeId, string $annee, string $login): void {
    $pdo = getDB();
    $pdo->prepare("UPDATE stagiaires SET nom=?, prenom=?, groupe_id=?, annee_scolaire=?, login=? WHERE id=?")
        ->execute([trim($nom), trim($prenom), $groupeId, $annee, trim($login), $id]);
}

function supprimerStagiaire(int $id): bool {
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        // 1. Supprimer les réponses liées aux sessions du stagiaire
        $pdo->prepare("
            DELETE FROM reponses_stagiaires
            WHERE session_id IN (SELECT id FROM sessions_eval WHERE stagiaire_id = ?)
        ")->execute([$id]);
        // 2. Supprimer les sessions du stagiaire
        $pdo->prepare("DELETE FROM sessions_eval WHERE stagiaire_id = ?")->execute([$id]);
        // 3. Supprimer le stagiaire
        $pdo->prepare("DELETE FROM stagiaires WHERE id = ?")->execute([$id]);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}

function resetPasswordStagiaire(int $id): void {
    $pdo = getDB();
    $hash = password_hash('123456', PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE stagiaires SET password_hash=?, must_change_password=1 WHERE id=?")
        ->execute([$hash, $id]);
}

// ============================================================
// Fonctions formateurs
// ============================================================

function getAllFormateurs(): array {
    $pdo = getDB();
    return $pdo->query("
        SELECT a.*,
               COUNT(DISTINCT mf.module_id) AS nb_modules
        FROM admins a
        LEFT JOIN module_formateurs mf ON a.id = mf.formateur_id
        GROUP BY a.id
        ORDER BY a.nom
    ")->fetchAll();
}

function getFormateur(int $id): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function adminUsernameUnique(string $username, int $excludeId = 0): bool {
    $pdo = getDB();
    $sql = "SELECT COUNT(*) FROM admins WHERE username = ?";
    $params = [trim($username)];
    if ($excludeId > 0) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() === 0;
}

function creerFormateur(string $username, string $nom, string $password): int {
    $pdo = getDB();
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash, nom, role) VALUES (?, ?, ?, 'formateur')");
    $stmt->execute([trim($username), $hash, trim($nom)]);
    return (int)$pdo->lastInsertId();
}

function modifierFormateur(int $id, string $username, string $nom, ?string $password = null): void {
    $pdo = getDB();
    if ($password) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE admins SET username = ?, nom = ?, password_hash = ? WHERE id = ?");
        $stmt->execute([trim($username), trim($nom), $hash, $id]);
    } else {
        $stmt = $pdo->prepare("UPDATE admins SET username = ?, nom = ? WHERE id = ?");
        $stmt->execute([trim($username), trim($nom), $id]);
    }
}

function supprimerFormateur(int $id): void {
    $pdo = getDB();
    $pdo->prepare("DELETE FROM admins WHERE id = ?")->execute([$id]);
}

function getGroupesFormateur(int $formateurId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT groupe_id FROM formateur_groupes WHERE formateur_id = ?");
    $stmt->execute([$formateurId]);
    return array_column($stmt->fetchAll(), 'groupe_id');
}

function getGroupesFormateur_Details(int $formateurId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT g.* FROM groupes g
        INNER JOIN formateur_groupes fg ON g.id = fg.groupe_id
        WHERE fg.formateur_id = ?
        ORDER BY g.nom
    ");
    $stmt->execute([$formateurId]);
    return $stmt->fetchAll();
}

function setGroupesFormateur(int $formateurId, array $groupeIds): void {
    $pdo = getDB();
    $pdo->prepare("DELETE FROM formateur_groupes WHERE formateur_id = ?")->execute([$formateurId]);
    foreach ($groupeIds as $groupeId) {
        $pdo->prepare("INSERT INTO formateur_groupes (formateur_id, groupe_id) VALUES (?, ?)")
            ->execute([$formateurId, (int)$groupeId]);
    }
}

// ============================================================
// Fonctions import questions
// ============================================================

function parseExcelFile(string $filePath): array {
    $questions = [];
    if (!file_exists($filePath)) return [];

    // Lecture simple du CSV (Excel en .csv)
    if (($handle = fopen($filePath, 'r')) !== false) {
        $header = null;
        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            if ($header === null) {
                $header = $data;
                continue;
            }
            if (count($data) > 0 && !empty($data[0])) {
                $questions[] = array_combine($header, $data);
            }
        }
        fclose($handle);
    }
    return $questions;
}

function parsePdfFile(string $filePath): array {
    // Simple extraction - assume PDF has text format
    // For production, use a proper PDF library
    $text = shell_exec("pdftotext \"$filePath\" -") ?? '';
    return extractQuestionsFromText($text);
}

function parseMarkdownFile(string $filePath): array {
    if (!file_exists($filePath)) return [];
    $content = file_get_contents($filePath);
    return extractQuestionsFromMarkdown($content);
}

function parseDocxFile(string $filePath): array {
    // Extract from DOCX (which is a ZIP with XML)
    $questions = [];
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return [];

    if ($xml = $zip->getFromName('word/document.xml')) {
        $dom = new DOMDocument();
        @$dom->loadXML($xml);
        $xpath = new DOMXPath($dom);

        $paragraphs = $xpath->query('//w:p');
        $currentQuestion = null;

        foreach ($paragraphs as $para) {
            $text = trim($xpath->evaluate('string(.)', $para));
            if (empty($text)) continue;

            // Détecte les questions (commençant par un numéro)
            if (preg_match('/^\d+[\.\)]\s+(.+)/', $text, $m)) {
                if ($currentQuestion) {
                    $questions[] = $currentQuestion;
                }
                $currentQuestion = ['texte' => $m[1], 'type' => 'qcm', 'points' => 1];
            } elseif ($currentQuestion && preg_match('/^[a-dA-D\*]\)\s+(.+)/', $text, $m)) {
                // Réponses possibles
                if (!isset($currentQuestion['choix'])) {
                    $currentQuestion['choix'] = [];
                }
                $isCorrect = strpos($text, '*') !== false ? 1 : 0;
                $currentQuestion['choix'][] = [
                    'texte' => preg_replace('/^\*?\s*[a-dA-D]\)\s*/', '', $m[1]),
                    'is_correct' => $isCorrect
                ];
            }
        }
        if ($currentQuestion) {
            $questions[] = $currentQuestion;
        }
    }
    $zip->close();
    return $questions;
}

function extractQuestionsFromMarkdown(string $content): array {
    $questions = [];
    $lines = explode("\n", $content);
    $currentQuestion = null;

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // Questions (## Titre ou ### Titre)
        if (preg_match('/^#+\s+(.+)/', $line, $m)) {
            if ($currentQuestion) {
                $questions[] = $currentQuestion;
            }
            $currentQuestion = ['texte' => $m[1], 'type' => 'qcm', 'points' => 1];
        } elseif ($currentQuestion && preg_match('/^[-*]\s+\*?\*?(.+?)\*?\*?$/', $line, $m)) {
            // Choix de réponse
            if (!isset($currentQuestion['choix'])) {
                $currentQuestion['choix'] = [];
            }
            $isCorrect = (strpos($line, '**') !== false || strpos($line, '*') !== false) ? 1 : 0;
            $currentQuestion['choix'][] = [
                'texte' => $m[1],
                'is_correct' => $isCorrect
            ];
        }
    }
    if ($currentQuestion) {
        $questions[] = $currentQuestion;
    }
    return $questions;
}

function extractQuestionsFromText(string $text): array {
    $questions = [];
    // Pattern simple: numéro. Question
    $pattern = '/(\d+)[\.\)]\s+([^\n]+)/';

    if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $questions[] = [
                'texte' => $match[2],
                'type' => 'qcm',
                'points' => 1,
                'choix' => []
            ];
        }
    }
    return $questions;
}

function importQuestionsToModule(array $questions, int $moduleId): array {
    $pdo = getDB();
    $imported = 0;
    $errors = [];

    foreach ($questions as $q) {
        try {
            $texte = trim($q['texte'] ?? $q['question'] ?? '');
            $type = $q['type'] ?? 'qcm';
            $points = (float)($q['points'] ?? 1);

            if (strlen($texte) < 3) {
                $errors[] = "Question ignorée : texte trop court";
                continue;
            }

            // Insert question
            $stmt = $pdo->prepare("
                INSERT INTO questions (module_id, texte, type, points, ordre)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$moduleId, $texte, $type, $points, $imported + 1]);
            $questionId = (int)$pdo->lastInsertId();

            // Insert choices if exists
            $choix = $q['choix'] ?? [];
            $ordre = 1;
            foreach ($choix as $choice) {
                $choixTexte = $choice['texte'] ?? $choice ?? '';
                $isCorrect = isset($choice['is_correct']) ? (int)$choice['is_correct'] : 0;

                $stmt = $pdo->prepare("
                    INSERT INTO choix_reponses (question_id, texte, is_correct, ordre)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$questionId, $choixTexte, $isCorrect, $ordre]);
                $ordre++;
            }

            $imported++;
        } catch (Exception $e) {
            $errors[] = "Erreur : " . $e->getMessage();
        }
    }

    return ['imported' => $imported, 'errors' => $errors];
}
