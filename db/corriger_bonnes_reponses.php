<?php
/**
 * Correction ponctuelle (audit 2026-11-09) : questions QCM / multiples importées sans bonne réponse.
 * Positionne is_correct, puis recalcule les réponses et les notes des sessions concernées.
 * Usage : php db/corriger_bonnes_reponses.php [--dry]
 */
require_once __DIR__ . '/../includes/functions.php';

$dry = in_array('--dry', $argv, true);
$pdo = getDB();

// question_id => ordre(s) du ou des bons choix
$bonnes = [
    // M201 (CC1 / CC2)
    2432 => [2], 2433 => [2], 2434 => [1], 2435 => [1, 2, 3, 4], 2436 => [1], 2437 => [1, 2, 3, 4],
    2438 => [2], 2439 => [3], 2441 => [2], 2442 => [1], 2443 => [3], 2445 => [1, 2, 3],
    2447 => [2], 2448 => [1], 2479 => [3], 2480 => [2], 2481 => [2], 2482 => [3], 2483 => [2], 2496 => [2],
    // M105 (EFM)
    2332 => [1], 2334 => [1], 2336 => [1], 2338 => [1], 2340 => [1], 2342 => [1], 2344 => [1], 2346 => [1],
    2348 => [1], 2350 => [1], 2372 => [1], 2375 => [1], 2376 => [1], 2388 => [1], 2398 => [1], 2400 => [1],
    2406 => [1], 2408 => [1], 2409 => [1], 2411 => [1],
];

$sansBonne = $pdo->prepare("SELECT COUNT(*) FROM choix_reponses WHERE question_id = ? AND is_correct = 1");
$setOk     = $pdo->prepare("UPDATE choix_reponses SET is_correct = 1 WHERE question_id = ? AND ordre = ?");
$reps      = $pdo->prepare("
    SELECT rs.id, rs.session_id, rs.choix_id, q.points, COALESCE(c.is_correct, 0) AS ok
    FROM reponses_stagiaires rs
    JOIN questions q ON q.id = rs.question_id
    LEFT JOIN choix_reponses c ON c.id = rs.choix_id
    WHERE rs.question_id = ? AND q.type IN ('qcm', 'vrai_faux')");
$updRep    = $pdo->prepare("UPDATE reponses_stagiaires SET is_correct = ?, points_obtenus = ? WHERE id = ?");

$pdo->beginTransaction();
$sessions = [];
$nbQ = $nbRep = 0;
foreach ($bonnes as $qid => $ordres) {
    $sansBonne->execute([$qid]);
    if ((int)$sansBonne->fetchColumn() > 0) continue; // déjà corrigée : ne pas toucher
    foreach ($ordres as $o) $setOk->execute([$qid, $o]);
    $nbQ++;
    $reps->execute([$qid]);
    foreach ($reps->fetchAll() as $r) {
        if (!$r['choix_id']) continue;
        $updRep->execute([(int)$r['ok'], $r['ok'] ? (float)$r['points'] : 0, $r['id']]);
        $sessions[(int)$r['session_id']] = true;
        $nbRep++;
    }
}

$avant = $apres = 0.0;
foreach (array_keys($sessions) as $sid) {
    $avant += (float)getSession($sid)['pourcentage'];
    $apres += updateSessionScore($sid)['pct'];
}
$n = count($sessions);
printf("%d question(s) corrigée(s), %d réponse(s) recalculée(s), %d session(s) mises à jour.\n", $nbQ, $nbRep, $n);
if ($n) printf("Moyenne des sessions touchées : %.1f %% → %.1f %%\n", $avant / $n, $apres / $n);

if ($dry) { $pdo->rollBack(); echo "(--dry : rien n'a été enregistré)\n"; } else { $pdo->commit(); }
