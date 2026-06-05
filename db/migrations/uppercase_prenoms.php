<?php
/**
 * Migration : convertit les PRÉNOMS (colonne `prenom`) des stagiaires et
 * formateurs en MAJUSCULES, en respectant l'encodage UTF-8 (accents).
 * Complète uppercase_noms.php (qui traitait la colonne `nom`).
 *
 * Idempotent : marqueur .done_uppercase_prenoms.
 */
require_once __DIR__ . '/../../config/database.php';

$marker = __DIR__ . '/.done_uppercase_prenoms';
if (file_exists($marker)) {
    echo "Migration déjà exécutée (.done_uppercase_prenoms présent).\n";
    exit;
}

$pdo = getDB();
$total = 0;

foreach (['stagiaires', 'formateurs'] as $table) {
    $rows = $pdo->query("SELECT id, prenom FROM $table")->fetchAll(PDO::FETCH_ASSOC);
    $upd  = $pdo->prepare("UPDATE $table SET prenom = ? WHERE id = ?");
    $count = 0;
    foreach ($rows as $r) {
        $maj = mb_strtoupper($r['prenom'], 'UTF-8');
        if ($maj !== $r['prenom']) {
            $upd->execute([$maj, $r['id']]);
            $count++;
        }
    }
    echo "Table $table : $count prénom(s) converti(s) en majuscules (sur " . count($rows) . ").\n";
    $total += $count;
}

file_put_contents($marker, date('c') . " — $total prénoms convertis\n");
echo "Terminé. Total : $total prénom(s) modifié(s).\n";
