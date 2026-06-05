<?php
/**
 * Import spécifique de la table modules depuis le dump (6 colonnes → 8 locales).
 * Colonnes dump : id, nom, description, actif, created_at, nb_questions_controle
 * Colonnes locales en plus : type (défaut 'qcm'), note_max (défaut 20)
 */
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

$modules = [
    [31, 'M205 Sécuriser un environnement Cloud propriétaire en ligne public', '', 0, '2026-05-14 11:47:48', 20],
    [32, 'M206 – Gouverner les données dans le Cloud', '', 0, '2026-05-14 11:47:49', null],
    [33, 'Culture et techniques avancées du numérique', null, 0, '2026-05-14 11:47:50', null],
    [34, 'M108 : Développer une veille technologique', '', 0, '2026-05-14 12:50:25', 10],
    [35, 'M204- Administrer un environnement Cloud propriétaire en ligne public', '', 0, '2026-05-18 08:24:34', null],
    [39, 'Sécuriser un environnement Cloud propriétaire en ligne public', 'EFM — M205', 0, '2026-05-18 11:24:06', null],
    [40, 'Sécuriser un environnement Cloud propriétaire en ligne public', 'EFM — M205', 0, '2026-05-18 11:24:35', null],
    [41, 'M105 GÉRER UNE INFRASTRUCTURE VIRTUALISÉE', '', 0, '2026-05-19 10:02:27', null],
    [42, 'M105 DOCKER 1', '', 0, '2026-05-19 10:03:17', 10],
    [47, 'M206 Gouverner les données dans le Cloud EFM', '', 0, '2026-05-21 08:11:06', null],
    [48, 'Gouverner les données dans le Cloud', 'EFM — M206', 0, '2026-05-21 09:46:48', null],
    [49, "M207 - Établir une stratégie de maintien d'un SI dans un Cloud propriétaire en ligne public", '', 0, '2026-06-01 09:12:13', null],
];

$stmt = $pdo->prepare("
    REPLACE INTO modules (id, nom, description, actif, created_at, nb_questions_controle)
    VALUES (?, ?, ?, ?, ?, ?)
");

$count = 0;
foreach ($modules as $m) {
    $stmt->execute($m);
    $count++;
    echo "✓ Module {$m[0]} : {$m[1]}\n";
}

echo "\n$count modules importés.\n";

// Vérification
$total = $pdo->query("SELECT COUNT(*) FROM modules")->fetchColumn();
echo "Total modules en DB : $total\n";
