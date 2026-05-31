<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ai_provider.php';

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

// ── CSRF ─────────────────────────────────────────────────────

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function verifyCsrfToken(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        exit('Token CSRF invalide. Rechargez la page et réessayez.');
    }
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
// Fonctions établissement
// ============================================================

function getEtablissementDefaut(): string {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $pdo  = getDB();
        $row  = $pdo->query("SELECT nom FROM etablissements WHERE actif = 1 ORDER BY id ASC LIMIT 1")->fetch();
        $cache = $row ? $row['nom'] : 'ISTA HAY RIAD';
    } catch (\Exception $e) {
        $cache = 'ISTA HAY RIAD';
    }
    return $cache;
}

function getAllEtablissements(): array {
    $pdo = getDB();
    return $pdo->query("SELECT * FROM etablissements ORDER BY nom")->fetchAll();
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
    $stmt = $pdo->prepare("SELECT m.* FROM modules m WHERE m.id = ?");
    $stmt->execute([$id]);
    $module = $stmt->fetch() ?: null;
    if (!$module) return null;
    _attachEvalToModule($pdo, $module);
    return $module;
}

function getAllModules(): array {
    $pdo = getDB();
    $modules = $pdo->query("
        SELECT m.*,
               (SELECT COUNT(*) FROM questions q WHERE q.module_id = m.id) AS nb_questions
        FROM modules m
        ORDER BY m.nom
    ")->fetchAll();
    foreach ($modules as &$m) {
        _attachEvalToModule($pdo, $m);
    }
    return $modules;
}

/** Récupère toutes les évaluations actives (avec le nom du module parent). */
function getEvaluationsActives(): array {
    $pdo = getDB();
    return $pdo->query("
        SELECT e.*, m.nom AS module_nom,
               (SELECT COUNT(*) FROM questions q WHERE q.module_id = e.module_id) AS nb_questions
        FROM evaluations e
        JOIN modules m ON m.id = e.module_id
        WHERE e.actif = 1 AND m.actif = 1
        ORDER BY e.nom
    ")->fetchAll();
}

/** Retourne une évaluation + données du module parent. */
function getEvaluation(int $id): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT e.*, m.nom AS module_nom, m.description AS module_description
        FROM evaluations e
        JOIN modules m ON m.id = e.module_id
        WHERE e.id = ?
    ");
    $stmt->execute([$id]);
    $eval = $stmt->fetch() ?: null;
    if (!$eval) return null;
    $meta = json_decode($eval['meta_json'] ?? '{}', true) ?: [];
    $eval['efm_code_module']   = $meta['code_module']   ?? '';
    $eval['efm_filiere']       = $meta['filiere']       ?? '';
    $eval['efm_etablissement'] = $meta['etablissement'] ?? '';
    $eval['efm_annee']         = $meta['annee']         ?? '';
    return $eval;
}

/** Injecte les champs d'évaluation (type, duree, note_max, efm_*) dans un tableau module. */
function _attachEvalToModule(PDO $pdo, array &$module): void {
    $stmt = $pdo->prepare("SELECT * FROM evaluations WHERE module_id = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([$module['id']]);
    $eval = $stmt->fetch();
    if ($eval) {
        $module['type']           = $eval['type'];
        $module['duree_minutes']  = $eval['duree_minutes'];
        $module['note_max']       = $eval['note_max'];
        $module['evaluation_id']  = $eval['id'];
        $meta = json_decode($eval['meta_json'] ?? '{}', true) ?: [];
        $module['efm_code_module']   = $meta['code_module']   ?? '';
        $module['efm_filiere']       = $meta['filiere']       ?? '';
        $module['efm_etablissement'] = $meta['etablissement'] ?? '';
        $module['efm_annee']         = $meta['annee']         ?? '';
    } else {
        $module['type'] = 'qcm'; $module['duree_minutes'] = 30; $module['note_max'] = 20;
        $module['evaluation_id'] = null;
        $module['efm_code_module'] = $module['efm_filiere'] = $module['efm_etablissement'] = $module['efm_annee'] = '';
    }
}

// ============================================================
// Fonctions groupes
// ============================================================

function getGroupes(): array {
    $pdo = getDB();
    return $pdo->query("
        SELECT g.*, COALESCE(e.nom, '') AS etablissement_nom
        FROM groupes g
        LEFT JOIN etablissements e ON e.id = g.etablissement_id
        ORDER BY g.nom
    ")->fetchAll();
}

// ============================================================
// Fonctions questions
// ============================================================

/**
 * @param int        $moduleId
 * @param int[]|null $questionIds  Si fourni, ne charge que ces IDs (tirage aléatoire déjà fait)
 */
function getQuestionsModule(int $moduleId, ?array $questionIds = null): array {
    $pdo = getDB();
    if ($questionIds !== null && count($questionIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $stmt = $pdo->prepare("SELECT q.* FROM questions q WHERE q.id IN ($placeholders) ORDER BY q.ordre, q.id");
        $stmt->execute($questionIds);
    } else {
        $stmt = $pdo->prepare("SELECT q.* FROM questions q WHERE q.module_id = ? ORDER BY q.ordre, q.id");
        $stmt->execute([$moduleId]);
    }
    $questions = $stmt->fetchAll();

    foreach ($questions as &$q) {
        $stmt2 = $pdo->prepare("SELECT * FROM choix_reponses WHERE question_id = ? ORDER BY ordre, id");
        $stmt2->execute([$q['id']]);
        $q['choix'] = $stmt2->fetchAll();
    }
    return $questions;
}


function getTotalPoints(int $moduleId, ?array $questionIds = null): float {
    $pdo = getDB();
    if ($questionIds !== null && count($questionIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(points), 0) FROM questions WHERE id IN ($placeholders)");
        $stmt->execute($questionIds);
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(q.points), 0) AS total FROM questions q WHERE q.module_id = ?");
        $stmt->execute([$moduleId]);
    }
    return (float)$stmt->fetchColumn();
}


function supprimerSession(int $sessionId): void {
    $pdo = getDB();
    $pdo->prepare("DELETE FROM reponses_stagiaires WHERE session_id = ?")->execute([$sessionId]);
    $pdo->prepare("DELETE FROM sessions_eval WHERE id = ?")->execute([$sessionId]);
}

function supprimerModule(int $moduleId): void {
    $pdo = getDB();
    // 1. Sessions via évaluations du module → réponses
    $eids = $pdo->prepare("SELECT id FROM evaluations WHERE module_id = ?");
    $eids->execute([$moduleId]);
    foreach ($eids->fetchAll(PDO::FETCH_COLUMN) as $evalId) {
        $sids = $pdo->prepare("SELECT id FROM sessions_eval WHERE evaluation_id = ?");
        $sids->execute([$evalId]);
        foreach ($sids->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            $pdo->prepare("DELETE FROM reponses_stagiaires WHERE session_id = ?")->execute([$sid]);
        }
        $pdo->prepare("DELETE FROM sessions_eval WHERE evaluation_id = ?")->execute([$evalId]);
    }
    // 2. Évaluations
    $pdo->prepare("DELETE FROM evaluations WHERE module_id = ?")->execute([$moduleId]);
    // 3. Choix des questions
    $qids = $pdo->prepare("SELECT id FROM questions WHERE module_id = ?");
    $qids->execute([$moduleId]);
    foreach ($qids->fetchAll(PDO::FETCH_COLUMN) as $qid) {
        $pdo->prepare("DELETE FROM choix_reponses WHERE question_id = ?")->execute([$qid]);
    }
    // 4. Questions, parties, module
    $pdo->prepare("DELETE FROM questions WHERE module_id = ?")->execute([$moduleId]);
    $pdo->prepare("DELETE FROM parties WHERE module_id = ?")->execute([$moduleId]);
    $pdo->prepare("DELETE FROM modules WHERE id = ?")->execute([$moduleId]);
}

// ============================================================
// Fonctions sessions d'évaluation
// ============================================================

/**
 * Crée une session pour une évaluation donnée.
 * $evaluationId : ID de la table evaluations.
 */
function creerSession(string $nom, string $prenom, ?int $groupeId, string $groupeLibre, int $evaluationId, ?int $stagiaireId = null): array {
    $pdo = getDB();
    $token = generateToken();

    // Récupère l'évaluation (nb_questions, module_id, parties_ids)
    $evalStmt = $pdo->prepare("SELECT * FROM evaluations WHERE id = ?");
    $evalStmt->execute([$evaluationId]);
    $eval = $evalStmt->fetch();
    if (!$eval) throw new \InvalidArgumentException("Évaluation $evaluationId introuvable.");

    $moduleId = (int)$eval['module_id'];
    $nbMax    = $eval['nb_questions'] ? (int)$eval['nb_questions'] : null;

    // Tirage aléatoire si quota configuré
    $questionsIds = null;
    if ($nbMax !== null && $nbMax > 0) {
        $allIds = $pdo->prepare("SELECT id FROM questions WHERE module_id = ? ORDER BY RAND()");
        $allIds->execute([$moduleId]);
        $ids = $allIds->fetchAll(PDO::FETCH_COLUMN);
        $questionsIds = array_slice($ids, 0, $nbMax);
    }

    $totalPoints  = getTotalPoints($moduleId, $questionsIds);
    $questionsJson = $questionsIds !== null ? json_encode($questionsIds) : null;

    $stmt = $pdo->prepare("
        INSERT INTO sessions_eval (token, nom, prenom, groupe_id, groupe_libre, evaluation_id, total_points, stagiaire_id, questions_ids)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$token, $nom, $prenom, $groupeId ?: null, $groupeLibre, $evaluationId, $totalPoints, $stagiaireId, $questionsJson]);

    return ['id' => (int)$pdo->lastInsertId(), 'token' => $token];
}

function getSession(int $id): ?array {
    return _fetchSession("s.id = ?", [$id]);
}

function getSessionByToken(string $token): ?array {
    return _fetchSession("s.token = ?", [$token]);
}

function _fetchSession(string $where, array $params): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT s.*,
               COALESCE(st.nom,    s.nom)    AS nom,
               COALESCE(st.prenom, s.prenom) AS prenom,
               m.nom  AS module_nom,
               e.duree_minutes,
               e.note_max,
               e.type AS module_type,
               e.meta_json AS eval_meta_json,
               COALESCE(g.nom, s.groupe_libre) AS groupe_nom
        FROM sessions_eval s
        JOIN evaluations e  ON e.id  = s.evaluation_id
        JOIN modules m      ON m.id  = e.module_id
        LEFT JOIN stagiaires st ON st.id = s.stagiaire_id
        LEFT JOIN groupes    g  ON g.id  = s.groupe_id
        WHERE $where
    ");
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row) return null;
    $meta = json_decode($row['eval_meta_json'] ?? '{}', true) ?: [];
    $row['efm_code_module']   = $meta['code_module']   ?? '';
    $row['efm_filiere']       = $meta['filiere']       ?? '';
    $row['efm_etablissement'] = $meta['etablissement'] ?? '';
    $row['efm_annee']         = $meta['annee']         ?? '';
    unset($row['eval_meta_json']);
    return $row;
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
        SELECT s.*, m.nom AS module_nom, e.type AS module_type,
               COALESCE(g.nom, s.groupe_libre) AS groupe_nom
        FROM sessions_eval s
        JOIN evaluations e ON e.id = s.evaluation_id
        JOIN modules     m ON m.id = e.module_id
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

function getStagiaires(?int $groupeId = null, ?string $annee = null, ?int $etablissementId = null): array {
    $pdo = getDB();
    $where = []; $params = [];
    if ($groupeId)        { $where[] = 's.groupe_id = ?';        $params[] = $groupeId; }
    if ($etablissementId) { $where[] = 'g.etablissement_id = ?'; $params[] = $etablissementId; }
    if ($annee)           { $where[] = 's.annee_scolaire = ?';   $params[] = $annee; }
    $sql = "SELECT s.*, g.nom AS groupe_nom,
                COALESCE(e.nom, '') AS etablissement_nom,
                COUNT(se.id) AS nb_evaluations,
                COALESCE(AVG(CASE WHEN se.statut='termine' THEN se.pourcentage END), NULL) AS moy_pourcentage
            FROM stagiaires s
            JOIN groupes g ON g.id = s.groupe_id
            LEFT JOIN etablissements e ON e.id = g.etablissement_id
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
               COALESCE(e.nom, '') AS etablissement_nom,
               COUNT(DISTINCT mf.module_id) AS nb_modules
        FROM admins a
        LEFT JOIN etablissements e  ON e.id = a.etablissement_id
        LEFT JOIN module_formateurs mf ON a.id = mf.formateur_id
        GROUP BY a.id
        ORDER BY a.nom
    ")->fetchAll();
}

function getFormateur(int $id): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT a.*, COALESCE(e.nom, '') AS etablissement_nom
        FROM admins a
        LEFT JOIN etablissements e ON e.id = a.etablissement_id
        WHERE a.id = ?
    ");
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

function creerFormateur(string $username, string $nom, string $password, ?int $etablissementId = null): int {
    $pdo = getDB();
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash, nom, role, etablissement_id) VALUES (?, ?, ?, 'formateur', ?)");
    $stmt->execute([trim($username), $hash, trim($nom), $etablissementId]);
    return (int)$pdo->lastInsertId();
}

function modifierFormateur(int $id, string $username, string $nom, ?string $password = null, ?int $etablissementId = null): void {
    $pdo = getDB();
    if ($password) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE admins SET username=?, nom=?, password_hash=?, etablissement_id=? WHERE id=?");
        $stmt->execute([trim($username), trim($nom), $hash, $etablissementId, $id]);
    } else {
        $stmt = $pdo->prepare("UPDATE admins SET username=?, nom=?, etablissement_id=? WHERE id=?");
        $stmt->execute([trim($username), trim($nom), $etablissementId, $id]);
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

    // Lecture CSV/Excel
    if (($handle = fopen($filePath, 'r')) !== false) {
        $header = null;
        $headerLower = null;

        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            if ($header === null) {
                $header = $data;
                // Normalise les entêtes
                $headerLower = array_map('strtolower', $data);
                continue;
            }

            if (count($data) > 0 && !empty($data[0])) {
                $row = array_combine($header, $data);

                // Cherche le texte de la question
                $texteKey = null;
                foreach ($headerLower as $idx => $key) {
                    if (strpos($key, 'question') !== false || strpos($key, 'texte') !== false) {
                        $texteKey = $header[$idx];
                        break;
                    }
                }
                if (!$texteKey) {
                    $texteKey = $header[0]; // Première colonne par défaut
                }

                $question = [
                    'texte' => trim($row[$texteKey] ?? ''),
                    'type' => trim($row[array_search('type', $headerLower, true) ? $header[array_search('type', $headerLower, true)] : 'type'] ?? 'qcm'),
                    'points' => (float)($row[array_search('points', $headerLower, true) ? $header[array_search('points', $headerLower, true)] : 'points'] ?? 1),
                    'choix' => []
                ];

                // Cherche les colonnes de choix
                foreach ($headerLower as $idx => $key) {
                    if (preg_match('/^(choice|choix|reponse|answer)\s*(\d+)$/', $key, $m)) {
                        $choixNum = $m[2];
                        $choixTexte = trim($row[$header[$idx]] ?? '');
                        if (!empty($choixTexte)) {
                            $isCorrect = 0;
                            // Détecte la réponse correcte par astérisque ou colonne dédiée
                            if (strpos($choixTexte, '*') !== false) {
                                $isCorrect = 1;
                                $choixTexte = str_replace('*', '', $choixTexte);
                            }

                            $correctKey = array_search("correct_$choixNum", $headerLower, true);
                            if ($correctKey !== false) {
                                $isCorrect = (int)($row[$header[$correctKey]] ?? 0);
                            }

                            $question['choix'][] = [
                                'texte' => trim($choixTexte),
                                'is_correct' => $isCorrect
                            ];
                        }
                    }
                }

                if (!empty($question['texte'])) {
                    $questions[] = $question;
                }
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
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $paragraphs = $xpath->query('//w:p');
        $currentQuestion = null;

        foreach ($paragraphs as $para) {
            // Récupère le texte, y compris les runs (w:r)
            $text = '';
            $isBold = false;

            foreach ($para->childNodes as $node) {
                if ($node->nodeName === 'w:r') {
                    $rPr = $xpath->query('w:rPr[w:b]', $node);
                    if ($rPr->length > 0) {
                        $isBold = true;
                    }
                    $text .= $xpath->evaluate('string(w:t)', $node);
                } else {
                    $text .= $xpath->evaluate('string(.)', $node);
                }
            }

            $text = trim($text);
            if (empty($text)) continue;

            // Détecte les questions (commençant par un numéro)
            if (preg_match('/^\d+[\.\)]\s+(.+)/', $text, $m)) {
                if ($currentQuestion) {
                    $questions[] = $currentQuestion;
                }
                $currentQuestion = ['texte' => trim($m[1]), 'type' => 'qcm', 'points' => 1];
            } elseif ($currentQuestion && preg_match('/^[a-dA-D\*]\)\s+(.+)/', $text, $m)) {
                // Réponses possibles : a) b) c) d) ou *a) *b) *c) *d)
                if (!isset($currentQuestion['choix'])) {
                    $currentQuestion['choix'] = [];
                }

                $isCorrect = (strpos($text, '*') !== false || $isBold) ? 1 : 0;
                $choixTexte = preg_replace('/^\*?\s*[a-dA-D]\)\s*/', '', $text);
                $choixTexte = trim(str_replace('*', '', $choixTexte));

                $currentQuestion['choix'][] = [
                    'texte' => $choixTexte,
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

        // Questions (## Titre ou ### Titre ou - Titre avec liste)
        if (preg_match('/^#+\s+(.+)/', $line, $m)) {
            // Titre Markdown
            if ($currentQuestion) {
                $questions[] = $currentQuestion;
            }
            $currentQuestion = ['texte' => trim($m[1]), 'type' => 'qcm', 'points' => 1];
        } elseif (preg_match('/^\d+[\.\)]\s+(.+)/', $line, $m)) {
            // Question numérotée (1. Question ou 1) Question)
            if ($currentQuestion) {
                $questions[] = $currentQuestion;
            }
            $currentQuestion = ['texte' => trim($m[1]), 'type' => 'qcm', 'points' => 1];
        } elseif ($currentQuestion && preg_match('/^[-\*]\s+(.+)$/', $line, $m)) {
            // Choix de réponse (- ou *)
            if (!isset($currentQuestion['choix'])) {
                $currentQuestion['choix'] = [];
            }

            $fullLine = $m[1];
            $isCorrect = 0;
            $choixTexte = $fullLine;

            // Détecte la réponse correcte marquée par ** ou * ou ✓ ou [x]
            if (preg_match('/^\*\*(.+?)\*\*/', $fullLine, $bold)) {
                $isCorrect = 1;
                $choixTexte = $bold[1];
            } elseif (preg_match('/^✓\s*(.+)/', $fullLine, $check)) {
                $isCorrect = 1;
                $choixTexte = $check[1];
            } elseif (preg_match('/^\[x\]\s*(.+)/', $fullLine, $checkbox)) {
                $isCorrect = 1;
                $choixTexte = $checkbox[1];
            } else {
                // Sinon c'est juste du texte
                $choixTexte = $fullLine;
            }

            $currentQuestion['choix'][] = [
                'texte' => trim($choixTexte),
                'is_correct' => $isCorrect
            ];
        } elseif ($currentQuestion && preg_match('/^[a-dA-D\*]\)\s+(.+)/', $line, $m)) {
            // Format a) b) c) d)
            if (!isset($currentQuestion['choix'])) {
                $currentQuestion['choix'] = [];
            }

            $isCorrect = (strpos($line, '*') !== false) ? 1 : 0;
            $choixTexte = preg_replace('/^\*?\s*[a-dA-D]\)\s*/', '', $line);
            $choixTexte = trim(str_replace('*', '', $choixTexte));

            $currentQuestion['choix'][] = [
                'texte' => $choixTexte,
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

// ============================================================
// Fonctions Correction IA (Claude)
// ============================================================

function getAnthropicApiKey(): string {
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("SELECT valeur FROM config WHERE cle = 'anthropic_api_key' LIMIT 1");
        $stmt->execute();
        return trim($stmt->fetchColumn() ?: '');
    } catch (Exception $e) {
        return '';
    }
}

function getAIModel(): string {
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("SELECT valeur FROM config WHERE cle = 'ai_model' LIMIT 1");
        $stmt->execute();
        $model = trim($stmt->fetchColumn() ?: 'claude-sonnet-4-20250514');
        return !empty($model) ? $model : 'claude-sonnet-4-20250514';
    } catch (Exception $e) {
        return 'claude-sonnet-4-20250514';
    }
}

function niveauToPoints(int $niveau, float $pointsMax): float {
    // Niveau 0 → 0 | Niveau 1 → note/3 | Niveau 2 → 2*(note/3) | Niveau 3 → note complète
    // Arrondi au demi-point supérieur : 0.1-0.4 → 0.5 | 0.6-0.9 → 1.0
    $raw = match($niveau) {
        0 => 0.0,
        1 => $pointsMax / 3,
        2 => 2 * $pointsMax / 3,
        default => $pointsMax,
    };
    return $niveau === 0 ? 0.0 : ceil($raw * 2) / 2;
}

function correcterAvecIA(int $repId, string $reponseTexte, string $questionTexte, float $pointsMax): array {
    $provider = getAIProvider();
    $apiKey   = getAPIKeyForProvider($provider);
    if (empty($apiKey)) {
        $labels = ['anthropic' => 'Anthropic', 'openai' => 'OpenAI', 'google' => 'Google'];
        return ['success' => false, 'error' => 'Clé API ' . ($labels[$provider] ?? $provider) . ' non configurée'];
    }

    try {
        $prompt = "Tu es un correcteur d'examens expert. Évalue cette réponse d'étudiant selon 4 niveaux de proximité.

**Question :** $questionTexte

**Réponse de l'étudiant :** $reponseTexte

**Niveaux (choisis exactement l'un d'eux) :**
- Niveau 0 — Nul : réponse vide, illisible ou sans aucun lien avec le sujet de la question
- Niveau 1 — Insuffisant : réponse en lien avec le sujet mais incorrecte ou très incomplète
- Niveau 2 — Partiel : réponse partiellement correcte, éléments justes mais incomplets
- Niveau 3 — Correct : réponse correcte et suffisamment complète

**Instructions :**
1. Choisis le niveau le plus adapté (0, 1, 2 ou 3)
2. Fournis un feedback constructif en 2-3 phrases (explique pourquoi ce niveau, ce qui manque ou ce qui est bien)

**Format JSON strict (rien d'autre) :**
{\"niveau\": <0|1|2|3>, \"feedback\": \"<feedback en français>\"}";

        $result = callAIUnified(
            $provider,
            $apiKey,
            getAIModel(),
            '',
            [['role' => 'user', 'content' => $prompt]],
            400
        );

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error']];
        }

        $contenu = trim($result['text']);

        // Extraire niveau et feedback
        preg_match('/"niveau"\s*:\s*([0123])/', $contenu, $nm);
        preg_match('/"feedback"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $contenu, $fm);

        if (!$nm || !$fm) {
            return ['success' => false, 'error' => 'Format de réponse IA incorrect'];
        }

        $niveau   = (int)$nm[1];
        $feedback = $fm[1];
        $points   = niveauToPoints($niveau, $pointsMax);
        $isCorrect = $niveau >= 2 ? 1 : 0; // niveaux 0 et 1 = incorrect

        $pdo = getDB();
        $pdo->prepare("
            UPDATE reponses_stagiaires
            SET points_obtenus                = ?,
                is_correct                    = ?,
                correction_ia_feedback        = ?,
                correction_ia_points_suggeres = ?,
                correction_ia_niveau          = ?,
                correction_ia_date            = NOW(),
                source_correction             = 'ia'
            WHERE id = ?
        ")->execute([$points, $isCorrect, $feedback, $points, $niveau, $repId]);

        return [
            'success'  => true,
            'points'   => $points,
            'niveau'   => $niveau,
            'feedback' => $feedback,
        ];

    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Erreur : ' . $e->getMessage()];
    }
}

function updateSessionScore(int $sessionId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(points_obtenus),0) FROM reponses_stagiaires WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $score = (float)$stmt->fetchColumn();
    $session = getSession($sessionId);
    $total   = (float)$session['total_points'];
    $pct     = $total > 0 ? round($score / $total * 100, 2) : 0;
    $pdo->prepare("UPDATE sessions_eval SET score=?, pourcentage=? WHERE id=?")->execute([$score, $pct, $sessionId]);
    return ['score' => $score, 'total' => $total, 'pct' => $pct];
}
