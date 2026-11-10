<?php
/**
 * Refonte ponctuelle (PC local uniquement) : évaluations CC/EFM, fusion des modules,
 * suppression des parties. Usage : php db/refonte_cc_efm.php [--dry]
 */
require_once __DIR__ . '/../includes/functions.php';

$dry = in_array('--dry', $argv, true);
$pdo = getDB();

function cols(PDO $pdo, string $table): array {
    return array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field');
}
if (!in_array('partie_id', cols($pdo, 'questions'), true)) exit("Refonte déjà appliquée.\n");

// canon => [code, filiere, intitulé, modules fusionnés]
$groupes = [
    99  => ['M103',    'ID',    'Concevoir un réseau informatique', []],
    42  => ['M105',    'ID',    'Gérer une infrastructure virtualisée', [79, 82, 87, 88, 89, 92, 93, 94]],
    95  => ['M201',    'IDOCC', 'Cloud Computing', [96, 97]],
    103 => ['M203',    'IDOCC', 'Déployer une infrastructure Cloud Computing', [101, 102]],
    35  => ['M204',    'IDOCC', 'Administrer un environnement Cloud propriétaire en ligne public', [70, 83]],
    31  => ['M205',    'IDOCC', 'Sécuriser un environnement Cloud propriétaire en ligne public', [71, 84]],
    32  => ['M206',    'IDOCC', 'Gouverner les données dans le Cloud', [48, 85]],
    49  => ['M207',    'IDOCC', "Établir une stratégie de maintien d'un SI dans un Cloud propriétaire en ligne public", [56, 63, 64, 65, 86]],
    33  => ['EGTS206', null,    'Culture et techniques avancées du numérique', [52, 81]],
];

// Évaluations théoriques : id => [type, numero, contenu]
$evals = [
    86 => ['cc', 1, 'Modèles et protocoles de communication IP'],
    11 => ['cc', 3, null],
    63 => ['cc', 4, 'Virtualisation, hyperviseurs et conteneurisation Docker'],
    72 => ['cc', 1, 'Hyper-V : setup, networking, migration'],
    73 => ['cc', 1, 'Hyper-V pour débutants'],
    71 => ['cc', 2, 'Proxmox VE et VMware ESXi'],
    78 => ['efm', null, null],
    76 => ['efm', null, 'V1'],
    82 => ['cc', 1, 'Introduction au Cloud Computing'],
    83 => ['cc', 2, 'Modèles de déploiement, marché, scalabilité, performance et coûts'],
    84 => ['cc', 2, 'Modèles de déploiement, marché, scalabilité (variante)'],
    88 => ['cc', 1, 'OpenStack Glance — gestion des images'],
    89 => ['cc', 2, 'OpenStack Flavors'],
    91 => ['cc', 3, 'Composants et architecture OpenStack'],
    5  => ['cc', 1, null],
    42 => ['efm', null, null],
    1  => ['cc', 1, null],
    43 => ['efm', null, null],
    2  => ['cc', 1, null],
    16 => ['efm', null, null],
    17 => ['cc', 1, 'Partie 1'],
    24 => ['cc', 2, 'Multi-Cloud et réplication Azure'],
    37 => ['cc', 3, 'Migration SI dans le Cloud et DevOps Azure'],
    31 => ['cc', 4, "Maintien d'un SI dans un Cloud propriétaire (complet)"],
    32 => ['efm', null, null],
    3  => ['cc', 1, null],
    20 => ['cc', 2, null],
];
$aSupprimer = [65, 66, 67, 68, 69, 70, 77]; // évaluations des modules [BANQUE] + EFM V2 vide

// ── Contrôles préalables ─────────────────────────────────────
$all = $pdo->query("SELECT id, categorie FROM evaluations")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($all as $id => $cat) {
    if ($cat !== 'cc_pratique' && !isset($evals[$id]) && !in_array($id, $aSupprimer, true)) {
        exit("Évaluation $id non mappée — arrêt.\n");
    }
}
$in = implode(',', $aSupprimer);
if ((int)$pdo->query("SELECT COUNT(*) FROM sessions_eval WHERE evaluation_id IN ($in)")->fetchColumn() > 0) {
    exit("Une évaluation à supprimer a des sessions — arrêt.\n");
}
if ($dry) exit("Contrôles OK (" . count($all) . " évaluations). Aucune écriture (--dry).\n");

// ── A. DDL préparatoire ──────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS evaluation_questions (
    evaluation_id INT NOT NULL,
    question_id   INT NOT NULL,
    ordre         INT NOT NULL DEFAULT 0,
    PRIMARY KEY (evaluation_id, question_id),
    KEY idx_eq_question (question_id),
    CONSTRAINT fk_eq_eval     FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE,
    CONSTRAINT fk_eq_question FOREIGN KEY (question_id)   REFERENCES questions(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("ALTER TABLE evaluations MODIFY type VARCHAR(20) NOT NULL,
    ADD COLUMN mode ENUM('theorique','pratique') NULL AFTER type,
    ADD COLUMN numero TINYINT UNSIGNED NULL AFTER mode,
    ADD COLUMN contenu VARCHAR(255) NULL AFTER numero");
$pdo->exec("ALTER TABLE modules ADD COLUMN code VARCHAR(20) NULL AFTER id, ADD COLUMN filiere VARCHAR(20) NULL AFTER code");
$pdo->exec("ALTER TABLE questions DROP FOREIGN KEY fk_question_partie");
$pdo->exec("ALTER TABLE questions ADD INDEX idx_question_module (module_id)");

// ── B. Données ───────────────────────────────────────────────
$pdo->beginTransaction();
try {
    // 1. Chaque évaluation théorique garde exactement les questions de son module d'origine
    $pdo->exec("INSERT INTO evaluation_questions (evaluation_id, question_id, ordre)
                SELECT e.id, q.id, q.ordre FROM evaluations e JOIN questions q ON q.module_id = e.module_id
                WHERE e.categorie <> 'cc_pratique'");
    // 2. Quota de tirage : du module vers l'évaluation
    $pdo->exec("UPDATE evaluations e JOIN modules m ON m.id = e.module_id
                SET e.nb_questions = m.nb_questions_controle
                WHERE e.nb_questions IS NULL AND e.categorie <> 'cc_pratique' AND m.nb_questions_controle IS NOT NULL");
    // 3. Visibilité inchangée après fusion
    $pdo->exec("UPDATE evaluations e JOIN modules m ON m.id = e.module_id SET e.actif = (e.actif AND m.actif)");
    // 4. Type / mode / numéro / contenu
    $up = $pdo->prepare("UPDATE evaluations SET type = ?, mode = ?, numero = ?, contenu = ? WHERE id = ?");
    foreach ($evals as $id => [$type, $num, $contenu]) {
        $up->execute([$type, $type === 'cc' ? 'theorique' : null, $num, $contenu, $id]);
    }
    $pdo->exec("UPDATE evaluations SET type = 'cc', mode = 'pratique',
                numero = JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.numero'))
                WHERE categorie = 'cc_pratique'");
    $pdo->exec("UPDATE evaluations SET type = 'efm', duree_minutes = 120, note_max = 40 WHERE type = 'efm' AND id IN (76, 78)");
    // 5. Évaluations sans objet
    $pdo->exec("DELETE FROM evaluations WHERE id IN ($in)");

    // 6. Fusion des modules
    $updMod = $pdo->prepare("UPDATE modules SET code = ?, filiere = ?, nom = ?, actif = ? WHERE id = ?");
    foreach ($groupes as $canon => [$code, $filiere, $intitule, $autres]) {
        $ids = array_merge([$canon], $autres);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $act = $pdo->prepare("SELECT MAX(actif) FROM modules WHERE id IN ($ph)");
        $act->execute($ids);
        $actif = (int)$act->fetchColumn();
        if ($autres) {
            $pa = implode(',', array_fill(0, count($autres), '?'));
            $params = array_merge([$canon], $autres);
            $pdo->prepare("UPDATE evaluations SET module_id = ? WHERE module_id IN ($pa)")->execute($params);
            $pdo->prepare("UPDATE questions   SET module_id = ? WHERE module_id IN ($pa)")->execute($params);
            $pdo->prepare("INSERT IGNORE INTO module_formateurs (module_id, formateur_id)
                           SELECT ?, formateur_id FROM module_formateurs WHERE module_id IN ($pa)")->execute($params);
            $pdo->prepare("DELETE FROM modules WHERE id IN ($pa)")->execute($autres);
        }
        $updMod->execute([$code, $filiere, $intitule, $actif, $canon]);
    }

    // 7. Doublons de questions jamais utilisés (copies des banques)
    $refs = $pdo->query("SELECT DISTINCT question_id FROM reponses_stagiaires
                         UNION SELECT question_id FROM evaluation_questions")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($pdo->query("SELECT questions_ids FROM sessions_eval WHERE questions_ids IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $j) {
        foreach (json_decode($j, true) ?: [] as $qid) $refs[] = (int)$qid;
    }
    $refs = array_flip(array_map('intval', $refs));

    $sig = [];
    $rows = $pdo->query("SELECT q.id, q.module_id, q.type, q.texte,
                         (SELECT GROUP_CONCAT(CONCAT(c.is_correct, ':', c.texte) ORDER BY c.texte SEPARATOR '|')
                          FROM choix_reponses c WHERE c.question_id = q.id) AS choix
                         FROM questions q ORDER BY q.id")->fetchAll();
    foreach ($rows as $r) {
        $key = $r['module_id'] . '#' . $r['type'] . '#' . mb_strtolower(trim(preg_replace('/\s+/u', ' ', $r['texte']))) . '#' . $r['choix'];
        $sig[$key][] = (int)$r['id'];
    }
    $del = $pdo->prepare("DELETE FROM questions WHERE id = ?");
    $nbDoublons = 0;
    foreach ($sig as $ids) {
        if (count($ids) < 2) continue;
        $gardes = array_filter($ids, fn($id) => isset($refs[$id]));
        if (!$gardes) $gardes = [$ids[0]];
        foreach ($ids as $id) {
            if (!in_array($id, $gardes, true)) { $del->execute([$id]); $nbDoublons++; }
        }
    }

    // 8. Nomenclature
    $nom = $pdo->prepare("UPDATE evaluations SET nom = ? WHERE id = ?");
    foreach ($pdo->query("SELECT e.*, m.code AS module_code, m.filiere AS module_filiere
                          FROM evaluations e JOIN modules m ON m.id = e.module_id")->fetchAll() as $e) {
        $nom->execute([nomEvaluation($e['module_filiere'], $e['module_code'], $e['type'], $e['mode'], $e['numero'] !== null ? (int)$e['numero'] : null, $e['contenu']), $e['id']]);
    }

    $pdo->commit();
} catch (Throwable $t) {
    $pdo->rollBack();
    exit("ERREUR, données annulées (DDL préparatoire déjà appliqué) : " . $t->getMessage() . "\n");
}

// ── C. DDL final ─────────────────────────────────────────────
$pdo->exec("ALTER TABLE questions DROP INDEX idx_module_partie, DROP COLUMN partie_id");
$pdo->exec("DROP TABLE parties");
$pdo->exec("ALTER TABLE evaluations DROP COLUMN categorie, DROP COLUMN parties_ids,
            MODIFY type ENUM('cc','efm') NOT NULL DEFAULT 'cc'");
$pdo->exec("ALTER TABLE modules DROP COLUMN nb_questions_controle");

echo "Refonte terminée : " . $pdo->query("SELECT COUNT(*) FROM modules")->fetchColumn() . " modules, "
   . $pdo->query("SELECT COUNT(*) FROM evaluations")->fetchColumn() . " évaluations, "
   . "$nbDoublons doublons supprimés.\n";
