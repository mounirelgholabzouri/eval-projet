<?php
/**
 * Migration : convertit les NOMS (colonne `nom`) des stagiaires et formateurs
 * en MAJUSCULES, en respectant l'encodage UTF-8 (accents : é→É, à→À, etc.)
 * Le prénom reste inchangé (convention OFPPT).
 *
 * Idempotent : marqueur .done_uppercase_noms pour ne s'exécuter qu'une fois.
 */
require_once __DIR__ . '/../../config/database.php';

$marker = __DIR__ . '/.done_uppercase_noms';
if (file_exists($marker)) {
    echo "Migration déjà exécutée (.done_uppercase_noms présent).\n";
    exit;
}

$pdo = getDB();
$total = 0;

foreach (['stagiaires', 'formateurs'] as $table) {
    $rows = $pdo->query("SELECT id, nom FROM $table")->fetchAll(PDO::FETCH_ASSOC);
    $upd  = $pdo->prepare("UPDATE $table SET nom = ? WHERE id = ?");
    $count = 0;
    foreach ($rows as $r) {
        $maj = mb_strtoupper($r['nom'], 'UTF-8');
        if ($maj !== $r['nom']) {
            $upd->execute([$maj, $r['id']]);
            $count++;
        }
    }
    echo "Table $table : $count nom(s) converti(s) en majuscules (sur " . count($rows) . ").\n";
    $total += $count;
}

file_put_contents($marker, date('c') . " — $total noms convertis\n");
echo "Terminé. Total : $total nom(s) modifié(s).\n";
