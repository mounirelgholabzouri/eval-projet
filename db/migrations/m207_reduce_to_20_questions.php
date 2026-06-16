<?php
/**
 * Migration : M207 COMPLET (module 63) — réduire de 30 à 20 questions
 * et passer la notation sur /20.
 *
 * - Supprime 10 questions tirées au hasard une fois pour toutes (IDs figés
 *   ci-dessous pour rester identique sur les 2 PCs synchronisés)
 * - Cascade : supprime aussi choix_reponses et reponses_stagiaires liées
 * - Passe evaluations.note_max de 40 à 20 pour le module → les résultats
 *   déjà passés s'affichent désormais sur /20 à l'impression
 *   (arrondiNote() recalcule score/total_points*note_max dynamiquement)
 */

require_once __DIR__ . '/../../includes/functions.php';

$pdo = getDB();
$moduleId = 63; // M207 - ... COMPLET
$idsToDelete = [1646, 1651, 1652, 1654, 1655, 1659, 1661, 1664, 1666, 1669];

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE module_id = ?");
$stmtCount->execute([$moduleId]);
$current = (int)$stmtCount->fetchColumn();

if ($current <= 20) {
    echo "Module $moduleId a déjà $current question(s) (≤ 20) — rien à faire.\n";
} else {
    $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
    $del = $pdo->prepare("DELETE FROM questions WHERE module_id = ? AND id IN ($placeholders)");
    $del->execute(array_merge([$moduleId], $idsToDelete));
    echo $del->rowCount() . " question(s) supprimée(s) (cascade choix_reponses + reponses_stagiaires).\n";
}

$upd = $pdo->prepare("UPDATE evaluations SET note_max = 20 WHERE module_id = ? AND note_max <> 20");
$upd->execute([$moduleId]);
echo $upd->rowCount() . " évaluation(s) passée(s) sur note_max = 20.\n";

$stmtCount->execute([$moduleId]);
echo "Module $moduleId : " . (int)$stmtCount->fetchColumn() . " question(s) restantes.\n";

echo "\n✅ Migration terminée.\n";
