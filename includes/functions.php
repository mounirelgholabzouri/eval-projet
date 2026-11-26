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
    $token = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($token === '' || !hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        $message = 'Token CSRF invalide. Rechargez la page et réessayez.';
        if (str_starts_with(basename($_SERVER['PHP_SELF'] ?? ''), 'api_')) {
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE));
        }
        exit($message);
    }
}

/**
 * Retourne l'année de formation courante.
 * Année scolaire : septembre → juillet.
 * Ex : en oct. 2025 → "25/26" (short) ou "2025/2026" (long)
 *      en mars 2025 → "24/25" (short) ou "2024/2025" (long)
 */
function getAnneeFormation(bool $short = true): string {
    $m = (int)date('n'); // mois 1-12
    $y = (int)date('Y');
    $debut = ($m >= 9) ? $y : $y - 1;
    $fin   = $debut + 1;
    return $short
        ? substr((string)$debut, -2) . '/' . substr((string)$fin, -2)
        : $debut . '/' . $fin;
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


const MODULE_SELECT = "
    SELECT m.*,
           (SELECT COUNT(*) FROM questions q   WHERE q.module_id = m.id) AS nb_questions,
           (SELECT COUNT(*) FROM evaluations e WHERE e.module_id = m.id) AS nb_evaluations
    FROM modules m";

/** « IDOCC-M207 — Intitulé » */
function moduleLibelle(array $m): string {
    $prefix = implode('-', array_filter([trim((string)($m['filiere'] ?? '')), trim((string)($m['code'] ?? ''))]));
    return $prefix !== '' ? $prefix . ' — ' . $m['nom'] : $m['nom'];
}

function getModule(int $id): ?array {
    $stmt = getDB()->prepare(MODULE_SELECT . " WHERE m.id = ?");
    $stmt->execute([$id]);
    $m = $stmt->fetch();
    if (!$m) return null;
    $m['libelle'] = moduleLibelle($m);
    return $m;
}

function getAllModules(): array {
    $modules = getDB()->query(MODULE_SELECT . " ORDER BY m.code IS NULL, m.code, m.nom")->fetchAll();
    foreach ($modules as &$m) $m['libelle'] = moduleLibelle($m);
    return $modules;
}

/** Crée un module et renvoie son id. */
function creerModule(string $code, ?string $filiere, string $intitule, string $description = '', bool $actif = true): int {
    $pdo = getDB();
    $pdo->prepare("INSERT INTO modules (code, filiere, nom, description, actif) VALUES (?, ?, ?, ?, ?)")
        ->execute([strtoupper(trim($code)) ?: null, strtoupper(trim((string)$filiere)) ?: null, trim($intitule), $description, $actif ? 1 : 0]);
    return (int)$pdo->lastInsertId();
}

/** Retrouve un module par son code (ex. M207), le crée sinon. */
function trouverOuCreerModule(string $code, ?string $filiere, string $intitule): int {
    $stmt = getDB()->prepare("SELECT id FROM modules WHERE code = ? LIMIT 1");
    $stmt->execute([strtoupper(trim($code))]);
    return (int)($stmt->fetchColumn() ?: creerModule($code, $filiere, $intitule));
}

/**
 * Calcule la note finale sur $noteMax arrondie au demi-point (0.5) le plus proche.
 * Ex. : 9.09 → 9.0 | 13.64 → 13.5 | 8.64 → 8.5
 */
function arrondiNote(float $score, float $total, int $noteMax = 20): float {
    if ($total <= 0) return 0.0;
    return round($score / $total * $noteMax * 2) / 2;
}

/** Nomenclature : FILIERE-MODULE-CCn (contenu) | FILIERE-MODULE-EFM (contenu). */
function nomEvaluation(?string $filiere, ?string $code, string $type, ?string $mode, ?int $numero, ?string $contenu): string {
    $prefix = implode('-', array_filter([trim((string)$filiere), trim((string)$code)]));
    $nom    = ($prefix !== '' ? $prefix . '-' : '') . ($type === 'efm' ? 'EFM' : 'CC' . ($numero ?: ''));
    $details = array_filter([$mode === 'pratique' ? 'Pratique' : null, trim((string)$contenu) ?: null]);
    return $details ? $nom . ' (' . implode(' — ', $details) . ')' : $nom;
}

const EVALUATION_SELECT = "
    SELECT e.*, m.nom AS module_nom, m.code AS module_code, m.filiere AS module_filiere,
           m.description AS module_description, m.actif AS module_actif,
           (SELECT COUNT(*) FROM evaluation_questions eq WHERE eq.evaluation_id = e.id) AS nb_questions_total,
           (SELECT COUNT(*) FROM sessions_eval s WHERE s.evaluation_id = e.id) AS nb_sessions,
           (SELECT COUNT(*) FROM evaluation_stagiaires es WHERE es.evaluation_id = e.id) AS nb_stagiaires_autorises
    FROM evaluations e
    JOIN modules m ON m.id = e.module_id";

/** Ajoute les champs efm_* (impression OFPPT) et le nombre de questions posées. */
function _enrichirEvaluation(array $e): array {
    $meta = json_decode($e['meta_json'] ?? '{}', true) ?: [];
    $e['efm_code_module']   = $meta['code_module']   ?? ($e['module_code'] ?? '');
    $e['efm_filiere']       = $meta['filiere']       ?? ($e['module_filiere'] ?? '');
    $e['efm_etablissement'] = $meta['etablissement'] ?? '';
    $e['efm_annee']         = $meta['annee']         ?? '';
    $total = (int)($e['nb_questions_total'] ?? 0);
    $e['nb_questions_posees'] = $e['nb_questions'] ? min((int)$e['nb_questions'], $total) : $total;
    return $e;
}

/**
 * Évaluations en ligne proposées aux stagiaires (CC théoriques et EFM actifs).
 * Avec $stagiaireId : exclut les évaluations réservées à d'autres stagiaires
 * (voir evaluation_stagiaires — aucune ligne = ouverte à tous).
 */
function getEvaluationsActives(?int $stagiaireId = null): array {
    $sql = EVALUATION_SELECT . "
        WHERE e.actif = 1 AND m.actif = 1 AND (e.mode IS NULL OR e.mode = 'theorique')";
    $params = [];
    if ($stagiaireId !== null) {
        $sql .= " AND " . _SQL_EVAL_ACCES;
        $params = [$stagiaireId];
    }
    $stmt = getDB()->prepare($sql . " ORDER BY e.nom");
    $stmt->execute($params);
    return array_map('_enrichirEvaluation', $stmt->fetchAll());
}

/** Condition SQL (alias e, 1 paramètre : stagiaire_id) : évaluation ouverte à tous ou réservée à ce stagiaire. */
const _SQL_EVAL_ACCES = "(NOT EXISTS (SELECT 1 FROM evaluation_stagiaires es WHERE es.evaluation_id = e.id)
         OR EXISTS (SELECT 1 FROM evaluation_stagiaires es WHERE es.evaluation_id = e.id AND es.stagiaire_id = ?))";

/** Le stagiaire peut-il passer cette évaluation (au regard de la restriction d'accès uniquement) ? */
function stagiaireAccesEvaluation(int $evaluationId, int $stagiaireId): bool {
    $stmt = getDB()->prepare("SELECT 1 FROM evaluations e WHERE e.id = ? AND " . _SQL_EVAL_ACCES);
    $stmt->execute([$evaluationId, $stagiaireId]);
    return (bool)$stmt->fetchColumn();
}

/** Stagiaires autorisés sur une évaluation ([] = ouverte à tous). */
function getStagiairesEvaluation(int $evaluationId): array {
    $stmt = getDB()->prepare("SELECT stagiaire_id FROM evaluation_stagiaires WHERE evaluation_id = ?");
    $stmt->execute([$evaluationId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Remplace la liste des stagiaires autorisés ([] = ouverte à tous). */
function setStagiairesEvaluation(int $evaluationId, array $stagiaireIds): void {
    $pdo = getDB();
    $pdo->prepare("DELETE FROM evaluation_stagiaires WHERE evaluation_id = ?")->execute([$evaluationId]);
    $ins = $pdo->prepare("INSERT IGNORE INTO evaluation_stagiaires (evaluation_id, stagiaire_id) VALUES (?, ?)");
    foreach (array_unique(array_map('intval', $stagiaireIds)) as $sid) {
        if ($sid > 0) $ins->execute([$evaluationId, $sid]);
    }
}

function getEvaluation(int $id): ?array {
    $stmt = getDB()->prepare(EVALUATION_SELECT . " WHERE e.id = ?");
    $stmt->execute([$id]);
    $e = $stmt->fetch();
    return $e ? _enrichirEvaluation($e) : null;
}

function getEvaluationsModule(int $moduleId): array {
    $stmt = getDB()->prepare(EVALUATION_SELECT . " WHERE e.module_id = ? ORDER BY e.type = 'efm', e.mode = 'pratique', e.numero, e.id");
    $stmt->execute([$moduleId]);
    return array_map('_enrichirEvaluation', $stmt->fetchAll());
}

/**
 * Point d'entrée unique de création d'une évaluation.
 * $d : module_id, type (cc|efm), mode (theorique|pratique), numero, contenu,
 *      duree_minutes, note_max, nb_questions, meta (array), actif, question_ids (int[])
 */
function creerEvaluation(array $d): int {
    $pdo    = getDB();
    $d      = _normaliserEvaluation($d);
    $module = getModule($d['module_id']);
    if (!$module) throw new InvalidArgumentException("Module {$d['module_id']} introuvable.");

    $pdo->prepare("INSERT INTO evaluations (module_id, nom, type, mode, numero, contenu, duree_minutes, note_max, nb_questions, meta_json, actif)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$d['module_id'], nomEvaluation($module['filiere'], $module['code'], $d['type'], $d['mode'], $d['numero'], $d['contenu']),
                   $d['type'], $d['mode'], $d['numero'], $d['contenu'], $d['duree_minutes'], $d['note_max'],
                   $d['nb_questions'], $d['meta_json'], $d['actif']]);
    $id = (int)$pdo->lastInsertId();
    if (!empty($d['question_ids'])) setQuestionsEvaluation($id, $d['question_ids']);
    return $id;
}

function modifierEvaluation(int $id, array $d): void {
    $d      = _normaliserEvaluation($d);
    $module = getModule($d['module_id']);
    if (!$module) throw new InvalidArgumentException("Module {$d['module_id']} introuvable.");
    getDB()->prepare("UPDATE evaluations SET module_id = ?, nom = ?, type = ?, mode = ?, numero = ?, contenu = ?,
                      duree_minutes = ?, note_max = ?, nb_questions = ?, meta_json = ?, actif = ? WHERE id = ?")
        ->execute([$d['module_id'], nomEvaluation($module['filiere'], $module['code'], $d['type'], $d['mode'], $d['numero'], $d['contenu']),
                   $d['type'], $d['mode'], $d['numero'], $d['contenu'], $d['duree_minutes'], $d['note_max'],
                   $d['nb_questions'], $d['meta_json'], $d['actif'], $id]);
    if (isset($d['question_ids'])) setQuestionsEvaluation($id, $d['question_ids']);
}

/** Recalcule le nom de toutes les évaluations d'un module (après changement de code/filière). */
function renommerEvaluationsModule(int $moduleId): void {
    $m   = getModule($moduleId);
    $upd = getDB()->prepare("UPDATE evaluations SET nom = ? WHERE id = ?");
    foreach (getEvaluationsModule($moduleId) as $e) {
        $upd->execute([nomEvaluation($m['filiere'], $m['code'], $e['type'], $e['mode'], $e['numero'] !== null ? (int)$e['numero'] : null, $e['contenu']), $e['id']]);
    }
}

function _normaliserEvaluation(array $d): array {
    $type = ($d['type'] ?? 'cc') === 'efm' ? 'efm' : 'cc';
    $mode = $type === 'cc' ? (($d['mode'] ?? 'theorique') === 'pratique' ? 'pratique' : 'theorique') : null;
    $meta = $d['meta'] ?? (isset($d['meta_json']) ? json_decode((string)$d['meta_json'], true) : null);
    $out = [
        'module_id'     => (int)($d['module_id'] ?? 0),
        'type'          => $type,
        'mode'          => $mode,
        'numero'        => $type === 'cc' && !empty($d['numero']) ? max(1, (int)$d['numero']) : null,
        'contenu'       => trim((string)($d['contenu'] ?? '')) ?: null,
        'duree_minutes' => max(5, (int)($d['duree_minutes'] ?? ($type === 'efm' ? 120 : 30))),
        'note_max'      => (int)($d['note_max'] ?? ($type === 'efm' ? 40 : 20)) === 40 ? 40 : 20,
        'nb_questions'  => !empty($d['nb_questions']) ? max(1, (int)$d['nb_questions']) : null,
        'meta_json'     => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        'actif'         => !empty($d['actif']) ? 1 : 0,
    ];
    if (array_key_exists('question_ids', $d)) $out['question_ids'] = array_values(array_unique(array_map('intval', $d['question_ids'])));
    return $out;
}

/** Remplace la liste des questions d'une évaluation (l'ordre suit le tableau). */
function setQuestionsEvaluation(int $evaluationId, array $questionIds): void {
    $pdo = getDB();
    $pdo->prepare("DELETE FROM evaluation_questions WHERE evaluation_id = ?")->execute([$evaluationId]);
    ajouterQuestionsEvaluation($evaluationId, $questionIds);
}

function ajouterQuestionsEvaluation(int $evaluationId, array $questionIds): void {
    $pdo  = getDB();
    $next = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) FROM evaluation_questions WHERE evaluation_id = ?");
    $next->execute([$evaluationId]);
    $ordre = (int)$next->fetchColumn();
    $ins = $pdo->prepare("INSERT IGNORE INTO evaluation_questions (evaluation_id, question_id, ordre) VALUES (?, ?, ?)");
    foreach ($questionIds as $qid) $ins->execute([$evaluationId, (int)$qid, ++$ordre]);
}

function getQuestionIdsEvaluation(int $evaluationId): array {
    $stmt = getDB()->prepare("SELECT question_id FROM evaluation_questions WHERE evaluation_id = ? ORDER BY ordre, question_id");
    $stmt->execute([$evaluationId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Supprime une évaluation avec ses sessions et réponses. */
function supprimerEvaluation(int $evaluationId): void {
    $pdo = getDB();
    $pdo->prepare("DELETE FROM sessions_eval WHERE evaluation_id = ?")->execute([$evaluationId]);
    $pdo->prepare("DELETE FROM evaluations WHERE id = ?")->execute([$evaluationId]);
}

/** Crée une question (et ses choix) dans la banque du module ; renvoie son id. */
function creerQuestion(int $moduleId, string $type, string $texte, float $points = 1, array $choix = [], ?string $imagePath = null): int {
    $pdo = getDB();
    $ordre = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 FROM questions WHERE module_id = ?");
    $ordre->execute([$moduleId]);
    $pdo->prepare("INSERT INTO questions (module_id, texte, type, points, ordre, image_path) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$moduleId, $texte, $type, $points, (int)$ordre->fetchColumn(), $imagePath]);
    $qid = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)");
    foreach (array_values($choix) as $i => $c) {
        $ins->execute([$qid, mb_substr((string)$c['texte'], 0, 500), !empty($c['is_correct']) ? 1 : 0, $i + 1]);
    }
    return $qid;
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

/** Charge des questions (avec leurs choix) dans l'ordre du tableau d'ids fourni. */
function getQuestionsByIds(array $questionIds): array {
    $questionIds = array_values(array_map('intval', $questionIds));
    if (!$questionIds) return [];
    $pdo = getDB();
    $ph  = implode(',', array_fill(0, count($questionIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE id IN ($ph)");
    $stmt->execute($questionIds);
    $byId = array_column($stmt->fetchAll(), null, 'id');

    $choix = $pdo->prepare("SELECT * FROM choix_reponses WHERE question_id IN ($ph) ORDER BY ordre, id");
    $choix->execute($questionIds);
    foreach ($choix->fetchAll() as $c) $byId[$c['question_id']]['choix'][] = $c;

    $out = [];
    foreach ($questionIds as $id) {
        if (isset($byId[$id])) $out[] = $byId[$id] + ['choix' => []];
    }
    return $out;
}

/** Questions d'une évaluation ; $questionIds = tirage figé de la session s'il existe. */
function getQuestionsEvaluation(int $evaluationId, ?array $questionIds = null): array {
    return getQuestionsByIds($questionIds ?: getQuestionIdsEvaluation($evaluationId));
}

/** Questions de la banque d'un module (gestion admin). */
function getQuestionsModule(int $moduleId): array {
    $stmt = getDB()->prepare("SELECT id FROM questions WHERE module_id = ? ORDER BY ordre, id");
    $stmt->execute([$moduleId]);
    return getQuestionsByIds($stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Enregistre le taux de réussite des questions pour un groupe (table questions_stats, migration
 * db/migrations/questions_stats.php). $stats : [question_id => ['n' => nb réponses, 'ok' => nb justes]].
 */
function enregistrerStatsQuestions(int $groupeId, array $stats): void {
    $up = getDB()->prepare("INSERT INTO questions_stats (question_id, groupe_id, nb_reponses, nb_justes, taux)
                            VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE nb_reponses = VALUES(nb_reponses), nb_justes = VALUES(nb_justes),
                                                    taux = VALUES(taux), updated_at = CURRENT_TIMESTAMP");
    foreach ($stats as $qid => $s) {
        $n = (int)$s['n']; $ok = (int)$s['ok'];
        $up->execute([(int)$qid, $groupeId, $n, $ok, $n ? round($ok / $n * 100, 2) : 0]);
    }
}

/**
 * Taux de réussite des questions d'un module, calculé en direct sur TOUTES les sessions terminées
 * (tous groupes, toutes évaluations, y compris EFM multi-modules) : une réponse = un couple
 * (session, question), juste si is_correct = 1.
 * Retour : [qid => ['n' => nb réponses, 'ok' => nb justes, 'taux' => %, 'groupes' => [nom => %]]].
 */
function getStatsQuestionsModule(int $moduleId): array {
    $stmt = getDB()->prepare("
        SELECT x.question_id, COALESCE(g.nom, NULLIF(TRIM(x.groupe_libre), ''), 'Sans groupe') AS groupe,
               COUNT(*) AS n, SUM(x.ok) AS ok
        FROM (
            SELECT r.question_id, r.session_id, MAX(se.groupe_id) AS groupe_id,
                   MAX(se.groupe_libre) AS groupe_libre, MAX(COALESCE(r.is_correct, 0)) AS ok
            FROM reponses_stagiaires r
            JOIN questions q     ON q.id  = r.question_id
            JOIN sessions_eval se ON se.id = r.session_id
            WHERE q.module_id = ? AND se.statut = 'termine'
            GROUP BY r.question_id, r.session_id
        ) x
        LEFT JOIN groupes g ON g.id = x.groupe_id
        GROUP BY x.question_id, groupe
        ORDER BY groupe");
    $stmt->execute([$moduleId]);
    $res = [];
    foreach ($stmt->fetchAll() as $r) {
        $qid = (int)$r['question_id']; $n = (int)$r['n']; $ok = (int)$r['ok'];
        $res[$qid]['n']  = ($res[$qid]['n']  ?? 0) + $n;
        $res[$qid]['ok'] = ($res[$qid]['ok'] ?? 0) + $ok;
        $res[$qid]['groupes'][$r['groupe']] = $n ? $ok / $n * 100 : 0;
    }
    foreach ($res as &$s) $s['taux'] = $s['n'] ? round($s['ok'] / $s['n'] * 100) : 0;
    unset($s);
    return $res;
}

/** Questions posées lors d'une session : tirage figé, sinon (anciennes sessions) celles de l'évaluation. */
function questionIdsSession(array $session): array {
    $ids = !empty($session['questions_ids']) ? (json_decode($session['questions_ids'], true) ?: []) : [];
    return $ids ? array_map('intval', $ids) : getQuestionIdsEvaluation((int)$session['evaluation_id']);
}

/** Questions d'une session + réponse du stagiaire (une ligne par question posée, répondue ou non). */
function getQuestionsReponsesSession(array $session): array {
    $ids = questionIdsSession($session);
    if (!$ids) return [];
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = getDB()->prepare("
        SELECT q.id, q.texte AS question_texte, q.type, q.points AS points_max, q.ordre,
               COALESCE(rs.points_obtenus, 0) AS points_obtenus,
               COALESCE(rs.reponse_texte, '') AS reponse_texte,
               rs.choix_id,
               COALESCE(cr.texte, IF(q.type = 'multiple', rs.reponse_texte, NULL)) AS choix_texte
        FROM questions q
        LEFT JOIN reponses_stagiaires rs ON rs.question_id = q.id AND rs.session_id = ?
        LEFT JOIN choix_reponses cr ON cr.id = rs.choix_id
        WHERE q.id IN ($ph)
        ORDER BY FIELD(q.id, $ph)");
    $stmt->execute(array_merge([(int)$session['id']], $ids, $ids));
    return $stmt->fetchAll();
}

function getTotalPointsQuestions(array $questionIds): float {
    if (!$questionIds) return 0.0;
    $ph   = implode(',', array_fill(0, count($questionIds), '?'));
    $stmt = getDB()->prepare("SELECT COALESCE(SUM(points), 0) FROM questions WHERE id IN ($ph)");
    $stmt->execute(array_values($questionIds));
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
    // 2. Évaluations, puis module (questions, choix, liens et formateurs suivent en cascade)
    $pdo->prepare("DELETE FROM evaluations WHERE module_id = ?")->execute([$moduleId]);
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

    $eval = getEvaluation($evaluationId);
    if (!$eval) throw new \InvalidArgumentException("Évaluation $evaluationId introuvable.");

    // Liste figée pour la session : tirage aléatoire si un quota est défini
    $questionsIds = getQuestionIdsEvaluation($evaluationId);
    $nbMax = $eval['nb_questions'] ? (int)$eval['nb_questions'] : 0;
    if ($nbMax > 0 && $nbMax < count($questionsIds)) {
        shuffle($questionsIds);
        $questionsIds = array_slice($questionsIds, 0, $nbMax);
    }

    $totalPoints   = getTotalPointsQuestions($questionsIds);
    $questionsJson = json_encode(array_values($questionsIds));

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
               e.module_id,
               m.nom  AS module_nom,
               m.code AS module_code,
               m.filiere AS module_filiere,
               e.nom  AS evaluation_nom,
               e.duree_minutes,
               e.note_max,
               e.type AS module_type,
               e.mode AS evaluation_mode,
               e.meta_json AS eval_meta_json,
               st.numero_classe,
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
    $row['efm_code_module']   = $meta['code_module']   ?? ($row['module_code'] ?? '');
    $row['efm_filiere']       = $meta['filiere']       ?? ($row['module_filiere'] ?? '');
    $row['efm_etablissement'] = $meta['etablissement'] ?? '';
    $row['efm_annee']         = $meta['annee']         ?? '';
    unset($row['eval_meta_json']);
    return $row;
}

function sauvegarderReponse(int $sessionId, int $questionId, ?int $choixId, ?string $reponseTxt, bool $isCorrect, float $points): void {
    $pdo = getDB();
    // Remplace la réponse existante (pas de clé unique session/question → DELETE + INSERT,
    // indispensable avec la sauvegarde automatique qui réenregistre plusieurs fois)
    $pdo->prepare("DELETE FROM reponses_stagiaires WHERE session_id = ? AND question_id = ?")
        ->execute([$sessionId, $questionId]);
    $stmt = $pdo->prepare("
        INSERT INTO reponses_stagiaires (session_id, question_id, choix_id, reponse_texte, is_correct, points_obtenus)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$sessionId, $questionId, $choixId, $reponseTxt, $isCorrect ? 1 : 0, $points]);
}

// Corrige et enregistre les réponses postées du formulaire quiz ($post = $_POST).
// Utilisée à la soumission finale ET par la sauvegarde automatique (quiz_autosave.php).
function enregistrerReponsesQuiz(int $sessionId, array $questions, array $post): void {
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        foreach ($questions as $q) {
            $qId     = (int)$q['id'];
            $type    = $q['type'];
            $points  = (float)$q['points'];
            $choixId = null;
            $repTxt  = null;
            $correct = false;
            $gained  = 0.0;

            if ($type === 'texte_libre') {
                $repTxt = trim((string)($post["rep_$qId"] ?? ''));
                if ($repTxt === '') $repTxt = null;
            } elseif ($type === 'glisser_deposer') {
                $placement = $post["dd_$qId"] ?? [];
                $res     = corrigerGlisserDeposer($q, is_array($placement) ? $placement : []);
                $repTxt  = $res['json'];
                $correct = $res['correct'];
                $gained  = $res['points'];
            } elseif ($type === 'multiple') {
                // Tout ou rien : exactement l'ensemble des bonnes réponses
                $coches  = array_values(array_unique(array_map('intval', (array)($post["rep_$qId"] ?? []))));
                $bonnes  = array_map(fn($c) => (int)$c['id'], array_filter($q['choix'], fn($c) => $c['is_correct']));
                $textes  = array_column(array_filter($q['choix'], fn($c) => in_array((int)$c['id'], $coches, true)), 'texte');
                $repTxt  = $textes ? implode(' | ', $textes) : null;
                sort($coches); sort($bonnes);
                $correct = $coches && $coches === $bonnes;
                $gained  = $correct ? $points : 0.0;
            } else {
                $choixId = (int)($post["rep_$qId"] ?? 0);
                foreach ($q['choix'] as $c) {
                    if ($choixId > 0 && (int)$c['id'] === $choixId && $c['is_correct']) {
                        $correct = true;
                        $gained  = $points;
                        break;
                    }
                }
            }

            sauvegarderReponse($sessionId, $qId, $choixId ?: null, $repTxt, $correct, $gained);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Clôture une session restée « en cours » avec les réponses déjà enregistrées.
function cloturerSession(int $sessionId): bool {
    $s = getSession($sessionId);
    if (!$s || $s['statut'] !== 'en_cours') return false;
    terminerSession($sessionId);
    return true;
}

// Clôture automatiquement les sessions dont la durée (+ marge) est dépassée :
// stagiaire qui a fermé l'onglet, coupure réseau, PC éteint… Retourne le nombre clôturé.
function cloturerSessionsExpirees(int $margeMinutes = 2): int {
    $stmt = getDB()->prepare("
        SELECT s.id FROM sessions_eval s
        JOIN evaluations e ON e.id = s.evaluation_id
        WHERE s.statut = 'en_cours'
          AND e.duree_minutes > 0
          AND s.date_debut < NOW() - INTERVAL (e.duree_minutes + ?) MINUTE
    ");
    $stmt->execute([$margeMinutes]);
    $n = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (cloturerSession((int)$id)) $n++;
    }
    return $n;
}

// Question glisser_deposer : texte = "énoncé\n---\ngabarit avec {{1}}, {{2}}…" ;
// choix is_correct=1 → bonne étiquette du trou n° ordre ; is_correct=0 → leurres.
function splitGlisserDeposer(string $texte): array {
    $parts = preg_split('/\R-{3,}\R/', $texte, 2);
    return [trim($parts[0]), trim($parts[1] ?? '')];
}

function corrigerGlisserDeposer(array $q, array $placement): array {
    $attendu = [];
    $valides = [];
    foreach ($q['choix'] as $c) {
        $valides[(int)$c['id']] = true;
        if ($c['is_correct']) $attendu[(int)$c['ordre']] = (int)$c['id'];
    }
    $clean = [];
    $ok = 0;
    foreach ($attendu as $trou => $choixId) {
        $donne = (int)($placement[$trou] ?? 0);
        if ($donne && isset($valides[$donne])) $clean[$trou] = $donne;
        if ($donne === $choixId) $ok++;
    }
    $nb = count($attendu);
    return [
        'json'    => $clean ? json_encode($clean) : null,
        'correct' => $nb > 0 && $ok === $nb,
        'points'  => $nb > 0 ? round((float)$q['points'] * $ok / $nb, 2) : 0.0,
    ];
}

// Gabarit HTML avec la réponse du stagiaire (vert = juste, rouge = faux + attendu).
function renderGlisserDeposerReponse(int $questionId, string $texte, ?string $reponseJson): string {
    $stmt = getDB()->prepare("SELECT id, texte, is_correct, ordre FROM choix_reponses WHERE question_id = ?");
    $stmt->execute([$questionId]);
    $byId = [];
    $attendu = [];
    foreach ($stmt->fetchAll() as $c) {
        $byId[(int)$c['id']] = $c['texte'];
        if ($c['is_correct']) $attendu[(int)$c['ordre']] = (int)$c['id'];
    }
    $donne = $reponseJson ? (json_decode($reponseJson, true) ?: []) : [];
    [, $gabarit] = splitGlisserDeposer($texte);
    $html = preg_replace_callback('/\{\{(\d+)\}\}|[^{]+|\{/', function ($m) use ($byId, $attendu, $donne) {
        if (!isset($m[1]) || $m[1] === '') return htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8');
        $n = (int)$m[1];
        $d = (int)($donne[$n] ?? 0);
        $bon = $attendu[$n] ?? 0;
        if ($d && $d === $bon) {
            return '<span class="badge bg-success">' . htmlspecialchars($byId[$d], ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $txt = $d ? '<s>' . htmlspecialchars($byId[$d] ?? '?', ENT_QUOTES, 'UTF-8') . '</s>' : '∅';
        return '<span class="badge bg-danger">' . $txt . '</span><span class="badge bg-success-subtle text-success-emphasis ms-1">'
             . htmlspecialchars($byId[$bon] ?? '?', ENT_QUOTES, 'UTF-8') . '</span>';
    }, $gabarit);
    return '<code class="d-block p-2 bg-dark text-light rounded small" style="white-space:pre-wrap;line-height:2">' . $html . '</code>';
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

    // Récupérer le module et les IDs de questions figés pour cette session
    $se = $pdo->prepare("SELECT se.questions_ids, e.module_id FROM sessions_eval se JOIN evaluations e ON e.id = se.evaluation_id WHERE se.id = ?");
    $se->execute([$sessionId]);
    $meta = $se->fetch();
    if (!$meta) return [];

    $questionIds = $meta['questions_ids'] ? json_decode($meta['questions_ids'], true) : null;

    if ($questionIds) {
        // Tirage figé connu → LEFT JOIN : toutes les questions du tirage, répondues ou non
        $in   = implode(',', array_fill(0, count($questionIds), '?'));
        $stmt = $pdo->prepare("
            SELECT q.id AS question_id, q.texte AS question_texte, q.type, q.points AS points_max, q.ordre,
                   rs.id,
                   rs.reponse_texte,
                   COALESCE(rs.is_correct, 0)     AS is_correct,
                   COALESCE(rs.points_obtenus, 0) AS points_obtenus,
                   rs.choix_id,
                   rs.correction_ia_niveau,
                   rs.correction_ia_feedback,
                   rs.correction_ia_date,
                   COALESCE(cr.texte, IF(q.type = 'multiple', rs.reponse_texte, NULL)) AS choix_texte
            FROM questions q
            LEFT JOIN reponses_stagiaires rs ON rs.question_id = q.id AND rs.session_id = ?
            LEFT JOIN choix_reponses cr ON cr.id = rs.choix_id
            WHERE q.id IN ($in)
            ORDER BY q.ordre, q.id
        ");
        $stmt->execute(array_merge([$sessionId], $questionIds));
    } else {
        // Tirage non stocké (anciennes sessions) → seulement les questions répondues
        $stmt = $pdo->prepare("
            SELECT q.id AS question_id, q.texte AS question_texte, q.type, q.points AS points_max, q.ordre,
                   rs.id,
                   rs.reponse_texte,
                   COALESCE(rs.is_correct, 0)     AS is_correct,
                   COALESCE(rs.points_obtenus, 0) AS points_obtenus,
                   rs.choix_id,
                   rs.correction_ia_niveau,
                   rs.correction_ia_feedback,
                   rs.correction_ia_date,
                   COALESCE(cr.texte, IF(q.type = 'multiple', rs.reponse_texte, NULL)) AS choix_texte
            FROM reponses_stagiaires rs
            JOIN questions q ON q.id = rs.question_id
            LEFT JOIN choix_reponses cr ON cr.id = rs.choix_id
            WHERE rs.session_id = ?
            ORDER BY q.ordre, q.id
        ");
        $stmt->execute([$sessionId]);
    }
    return $stmt->fetchAll();
}

// ============================================================
// Fonctions admin
// ============================================================

function getAllSessions(int $limit = 100, int $offset = 0): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT s.*, m.nom AS module_nom, e.nom AS evaluation_nom, e.type AS module_type,
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

function getStatsGlobales(): array {
    $pdo = getDB();
    return [
        'total_sessions'   => (int)$pdo->query("SELECT COUNT(*) FROM sessions_eval")->fetchColumn(),
        'terminees'        => (int)$pdo->query("SELECT COUNT(*) FROM sessions_eval WHERE statut='termine'")->fetchColumn(),
        'moy_pourcentage'  => (float)$pdo->query("SELECT COALESCE(AVG(pourcentage),0) FROM sessions_eval WHERE statut='termine'")->fetchColumn(),
        'nb_modules'       => (int)$pdo->query("SELECT COUNT(*) FROM modules WHERE actif=1 AND (description IS NULL OR description NOT LIKE '%[BANQUE]%')")->fetchColumn(),
        'nb_stagiaires'    => (int)$pdo->query("SELECT COUNT(*) FROM stagiaires")->fetchColumn(),
        'nb_groupes'       => (int)$pdo->query("SELECT COUNT(*) FROM groupes")->fetchColumn(),
    ];
}

// ============================================================
// Fonctions stagiaires
// ============================================================

/** Nom de groupe canonique : majuscules, sans espaces (« id 101 » → « ID101 »). */
function normaliserNomGroupe(string $nom): string {
    return preg_replace('/\s+/u', '', mb_strtoupper(trim($nom), 'UTF-8'));
}

function trouverOuCreerGroupe(string $nom, ?string $annee = null): int {
    $pdo = getDB();
    $nom = normaliserNomGroupe($nom);
    // Comparaison insensible à la casse et aux espaces pour ne pas recréer un groupe existant
    $stmt = $pdo->prepare("SELECT id FROM groupes WHERE REPLACE(nom, ' ', '') = ? LIMIT 1");
    $stmt->execute([$nom]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];
    $etab = $pdo->query("SELECT id FROM etablissements WHERE actif = 1 ORDER BY id LIMIT 1")->fetchColumn() ?: null;
    $pdo->prepare("INSERT INTO groupes (nom, etablissement_id) VALUES (?, ?)")->execute([$nom, $etab]);
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

function nextNumeroClasse(int $groupeId): int {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(numero_classe), 0) + 1 FROM stagiaires WHERE groupe_id = ?");
    $stmt->execute([$groupeId]);
    return (int)$stmt->fetchColumn();
}

function creerStagiaireAdmin(string $nom, string $prenom, int $groupeId, string $annee): array {
    $pdo = getDB();
    $nom = mb_strtoupper(trim($nom), 'UTF-8');       // NOM en majuscules
    $prenom = mb_strtoupper(trim($prenom), 'UTF-8');  // PRÉNOM en majuscules
    $stmt = $pdo->prepare("SELECT id FROM stagiaires WHERE nom=? AND prenom=? AND groupe_id=? AND annee_scolaire=? LIMIT 1");
    $stmt->execute([$nom, $prenom, $groupeId, $annee]);
    if ($stmt->fetchColumn()) {
        throw new RuntimeException("Ce stagiaire existe déjà dans ce groupe pour cette année.");
    }
    $login = genererLogin($prenom, $nom);
    $hash  = password_hash('123456', PASSWORD_BCRYPT);
    $numeroClasse = nextNumeroClasse($groupeId);
    $pdo->prepare("INSERT INTO stagiaires (nom, prenom, groupe_id, annee_scolaire, login, password_hash, must_change_password, numero_classe) VALUES (?,?,?,?,?,?,1,?)")
        ->execute([$nom, $prenom, $groupeId, $annee, $login, $hash, $numeroClasse]);
    return ['id' => (int)$pdo->lastInsertId(), 'login' => $login];
}

function modifierStagiaire(int $id, string $nom, string $prenom, int $groupeId, string $annee, string $login): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT groupe_id FROM stagiaires WHERE id=?");
    $stmt->execute([$id]);
    $oldGroupeId = (int)$stmt->fetchColumn();

    $nom = mb_strtoupper(trim($nom), 'UTF-8');
    $prenom = mb_strtoupper(trim($prenom), 'UTF-8');
    $login = trim($login);

    if ($oldGroupeId && $oldGroupeId !== $groupeId) {
        // Changement de groupe → nouveau numéro de classe dans le groupe d'arrivée
        $numeroClasse = nextNumeroClasse($groupeId);
        $pdo->prepare("UPDATE stagiaires SET nom=?, prenom=?, groupe_id=?, annee_scolaire=?, login=?, numero_classe=? WHERE id=?")
            ->execute([$nom, $prenom, $groupeId, $annee, $login, $numeroClasse, $id]);
        transfererEvaluationsStagiaire($id, $groupeId); // ses évaluations suivent le stagiaire
    } else {
        $pdo->prepare("UPDATE stagiaires SET nom=?, prenom=?, groupe_id=?, annee_scolaire=?, login=? WHERE id=?")
            ->execute([$nom, $prenom, $groupeId, $annee, $login, $id]);
    }
}

/**
 * Rattache les évaluations d'un stagiaire à son (nouveau) groupe :
 *  - sessions_eval.groupe_id (CC théoriques, EFM) ;
 *  - notes de CC pratique : déplacées vers la grille du même module + numéro du nouveau groupe
 *    (créée si besoin). Si le stagiaire a déjà une note sur la grille d'arrivée, l'ancienne est conservée telle quelle.
 * Idempotent. Retourne ['sessions' => n, 'cc_pratique' => n, 'conflits' => n].
 */
function transfererEvaluationsStagiaire(int $stagiaireId, int $groupeId): array {
    $pdo = getDB();
    $res = ['sessions' => 0, 'cc_pratique' => 0, 'conflits' => 0];
    if ($groupeId <= 0) return $res;

    $u = $pdo->prepare("UPDATE sessions_eval SET groupe_id = ? WHERE stagiaire_id = ? AND (groupe_id IS NULL OR groupe_id <> ?)");
    $u->execute([$groupeId, $stagiaireId, $groupeId]);
    $res['sessions'] = $u->rowCount();

    $q = $pdo->prepare("SELECT n.id, e.module_id, e.numero, e.duree_minutes, e.note_max
                        FROM cc_pratique_notes n JOIN evaluations e ON e.id = n.evaluation_id
                        WHERE n.stagiaire_id = ? AND e.type = 'cc' AND e.mode = 'pratique'
                          AND COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(e.meta_json, '$.groupe_id')) AS UNSIGNED), 0) <> ?");
    $q->execute([$stagiaireId, $groupeId]);
    foreach ($q->fetchAll() as $n) {
        $f = $pdo->prepare("SELECT id FROM evaluations WHERE module_id = ? AND type = 'cc' AND mode = 'pratique' AND numero = ?
                            AND JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.groupe_id')) = ? ORDER BY id LIMIT 1");
        $f->execute([(int)$n['module_id'], (int)$n['numero'], (string)$groupeId]);
        $cible = (int)$f->fetchColumn();
        if (!$cible) {
            $cible = creerEvaluation([
                'module_id' => (int)$n['module_id'], 'type' => 'cc', 'mode' => 'pratique', 'numero' => (int)$n['numero'],
                'duree_minutes' => (int)($n['duree_minutes'] ?: 60), 'note_max' => (float)($n['note_max'] ?: 20), 'actif' => true,
                'meta' => ['numero' => (int)$n['numero'], 'groupe_id' => $groupeId],
            ]);
        }
        $x = $pdo->prepare("SELECT 1 FROM cc_pratique_notes WHERE evaluation_id = ? AND stagiaire_id = ?");
        $x->execute([$cible, $stagiaireId]);
        if ($x->fetchColumn()) { $res['conflits']++; continue; }
        $pdo->prepare("UPDATE cc_pratique_notes SET evaluation_id = ? WHERE id = ?")->execute([$cible, (int)$n['id']]);
        $res['cc_pratique']++;
    }
    return $res;
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

/** Modèle configuré ; repli sur le modèle par défaut du fournisseur s'il n'est plus proposé (modèle retiré). */
function getAIModel(): string {
    $providers = getProvidersConfig();
    $cfg = $providers[getAIProvider()] ?? $providers['anthropic'];
    try {
        $model = trim((string)getDB()->query("SELECT valeur FROM config WHERE cle = 'ai_model' LIMIT 1")->fetchColumn());
    } catch (Exception $e) {
        $model = '';
    }
    return isset($cfg['models'][$model]) ? $model : $cfg['default_model'];
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

function getReponseAttendue(int $questionId): string {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT texte FROM choix_reponses WHERE question_id = ? AND is_correct = 1 ORDER BY ordre, id");
    $stmt->execute([$questionId]);
    return implode(' | ', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function correcterAvecIA(int $repId, string $reponseTexte, string $questionTexte, float $pointsMax, string $reponseAttendue = ''): array {
    $provider = getAIProvider();
    $apiKey   = getAPIKeyForProvider($provider);
    if (empty($apiKey)) {
        $labels = ['anthropic' => 'Anthropic', 'openai' => 'OpenAI', 'google' => 'Google'];
        return ['success' => false, 'error' => 'Clé API ' . ($labels[$provider] ?? $provider) . ' non configurée'];
    }

    try {
        $refBlock = $reponseAttendue !== '' ? "\n\n**Éléments de réponse attendus :** $reponseAttendue" : '';
        $prompt = "Tu es un correcteur d'examens expert. Évalue cette réponse d'étudiant selon 4 niveaux de proximité.

**Question :** $questionTexte$refBlock

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
