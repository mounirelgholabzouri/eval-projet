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
    return $m > 0 ? "{$h}h{$m:02d}" : "{$h}h";
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

function creerSession(string $nom, string $prenom, ?int $groupeId, string $groupeLibre, int $moduleId): array {
    $pdo = getDB();
    $token = generateToken();
    $totalPoints = getTotalPoints($moduleId);

    $stmt = $pdo->prepare("INSERT INTO sessions_eval (token, nom, prenom, groupe_id, groupe_libre, module_id, total_points)
                           VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$token, $nom, $prenom, $groupeId ?: null, $groupeLibre, $moduleId, $totalPoints]);

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
    ];
}
