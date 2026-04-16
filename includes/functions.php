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
    $stmt = $pdo->prepare("SELECT * FROM modules WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getAllModules(): array {
    $pdo = getDB();
    return $pdo->query("SELECT m.*, COALESCE(m.note_max, 20) AS note_max, COUNT(q.id) AS nb_questions FROM modules m LEFT JOIN questions q ON q.module_id = m.id GROUP BY m.id ORDER BY m.nom")->fetchAll();
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
        SELECT s.*, m.nom AS module_nom, m.duree_minutes,
               g.nom AS groupe_nom
        FROM sessions_eval s
        JOIN modules m ON m.id = s.module_id
        LEFT JOIN groupes g ON g.id = s.groupe_id
        WHERE s.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getSessionByToken(string $token): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT s.*, m.nom AS module_nom, m.duree_minutes,
               g.nom AS groupe_nom
        FROM sessions_eval s
        JOIN modules m ON m.id = s.module_id
        LEFT JOIN groupes g ON g.id = s.groupe_id
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
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sessions_eval WHERE stagiaire_id = ?");
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() > 0) return false;
    $pdo->prepare("DELETE FROM stagiaires WHERE id = ?")->execute([$id]);
    return true;
}

function resetPasswordStagiaire(int $id): void {
    $pdo = getDB();
    $hash = password_hash('123456', PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE stagiaires SET password_hash=?, must_change_password=1 WHERE id=?")
        ->execute([$hash, $id]);
}
