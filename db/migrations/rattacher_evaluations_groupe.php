<?php
/**
 * Migration : rattache les évaluations de chaque stagiaire à son groupe actuel
 * (stagiaires déplacés de groupe avant que modifierStagiaire() ne le fasse automatiquement).
 *  - sessions_eval.groupe_id ← stagiaires.groupe_id
 *  - notes de CC pratique déplacées vers la grille du même module/numéro du groupe actuel
 * Idempotent. Usage : php db/migrations/rattacher_evaluations_groupe.php [--dry]
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$dry = in_array('--dry', $argv ?? [], true);
$pdo = getDB();

$sql = "SELECT st.id, st.nom, st.prenom, st.groupe_id, g.nom AS groupe
        FROM stagiaires st JOIN groupes g ON g.id = st.groupe_id
        WHERE EXISTS (SELECT 1 FROM sessions_eval se WHERE se.stagiaire_id = st.id
                        AND (se.groupe_id IS NULL OR se.groupe_id <> st.groupe_id))
           OR EXISTS (SELECT 1 FROM cc_pratique_notes n JOIN evaluations e ON e.id = n.evaluation_id
                      WHERE n.stagiaire_id = st.id AND e.type = 'cc' AND e.mode = 'pratique'
                        AND COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(e.meta_json, '$.groupe_id')) AS UNSIGNED), 0) <> st.groupe_id)
        ORDER BY g.nom, st.nom, st.prenom";
$stagiaires = $pdo->query($sql)->fetchAll();

if (!$stagiaires) { echo "Rien à rattacher : toutes les évaluations sont sur le groupe actuel du stagiaire.\n"; exit; }

$tot = ['sessions' => 0, 'cc_pratique' => 0, 'conflits' => 0];
if (!$dry) $pdo->beginTransaction();
try {
    foreach ($stagiaires as $s) {
        if ($dry) {
            echo "[dry] {$s['nom']} {$s['prenom']} → {$s['groupe']}\n";
            continue;
        }
        $r = transfererEvaluationsStagiaire((int)$s['id'], (int)$s['groupe_id']);
        foreach ($r as $k => $v) $tot[$k] += $v;
        echo "{$s['nom']} {$s['prenom']} → {$s['groupe']} : {$r['sessions']} session(s), {$r['cc_pratique']} note(s) CC pratique"
           . ($r['conflits'] ? ", {$r['conflits']} conflit(s) laissé(s)" : '') . "\n";
    }
    if (!$dry) $pdo->commit();
} catch (Throwable $e) {
    if (!$dry && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Erreur, rien n'a été modifié : " . $e->getMessage() . "\n");
    exit(1);
}

echo $dry
    ? count($stagiaires) . " stagiaire(s) à rattacher (relancer sans --dry pour appliquer).\n"
    : "Terminé : {$tot['sessions']} session(s), {$tot['cc_pratique']} note(s) CC pratique, {$tot['conflits']} conflit(s).\n";
