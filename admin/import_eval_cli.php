<?php
/**
 * Import CLI d'un fichier JSON d'évaluation externe
 * Usage : php admin/import_eval_cli.php "C:\chemin\vers\fichier.json"
 */
if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

$jsonPath = $argv[1] ?? null;
if (!$jsonPath || !file_exists($jsonPath)) {
    echo "Usage : php admin/import_eval_cli.php \"chemin_vers_fichier.json\"\n";
    exit(1);
}

require_once __DIR__ . '/../includes/functions.php';
$pdo = getDB();

$raw  = file_get_contents($jsonPath);
// Retirer le BOM UTF-8 si présent
$raw  = ltrim($raw, "\xEF\xBB\xBF");
$data = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "Erreur JSON : " . json_last_error_msg() . "\n";
    exit(1);
}

$eval = $data['evaluation'] ?? null;
if (!$eval || !isset($eval['questions'])) {
    echo "Structure invalide — clé 'evaluation.questions' introuvable.\n";
    echo "Clés trouvées : " . implode(', ', array_keys($data ?? [])) . "\n";
    exit(1);
}

function mapType(string $type): string {
    return match ($type) {
        'QCM_choix_unique'        => 'qcm',
        'QCM_choix_multiple'      => 'multiple',
        'Vrai_Faux',
        'Vrai_Faux_Justification' => 'vrai_faux',
        default                   => 'texte_libre',
    };
}

function parseOption(string $opt): array {
    if (preg_match('/^([A-Z])\)\s*(.+)$/s', trim($opt), $m)) {
        return ['lettre' => $m[1], 'texte' => trim($m[2])];
    }
    return ['lettre' => '', 'texte' => trim($opt)];
}

$moduleNom = $eval['module'] ?? 'Module importé';
$partieNom = $eval['titre']  ?? 'Général';
$nbTotal = $nbImporte = $nbSkip = 0;

$pdo->beginTransaction();
try {
    $stmtM = $pdo->prepare("SELECT id FROM modules WHERE nom = ? AND type = 'qcm'");
    $stmtM->execute([$moduleNom]);
    $moduleId = $stmtM->fetchColumn();
    if (!$moduleId) {
        $pdo->prepare("INSERT INTO modules (nom, type, actif) VALUES (?, 'qcm', 1)")->execute([$moduleNom]);
        $moduleId = $pdo->lastInsertId();
        echo "Module créé : $moduleNom (id=$moduleId)\n";
    } else {
        echo "Module existant : $moduleNom (id=$moduleId)\n";
    }

    $stmtP = $pdo->prepare("SELECT id FROM parties WHERE module_id = ? AND nom = ?");
    $stmtP->execute([$moduleId, $partieNom]);
    $partieId = $stmtP->fetchColumn();
    if (!$partieId) {
        $stmtOrdre = $pdo->prepare("SELECT COALESCE(MAX(ordre),0)+1 FROM parties WHERE module_id = ?");
        $stmtOrdre->execute([$moduleId]);
        $ordre = (int)$stmtOrdre->fetchColumn();
        $pdo->prepare("INSERT INTO parties (module_id, nom, ordre, actif) VALUES (?, ?, ?, 1)")->execute([$moduleId, $partieNom, $ordre]);
        $partieId = $pdo->lastInsertId();
        echo "Partie créée : $partieNom (id=$partieId)\n";
    } else {
        echo "Partie existante : $partieNom (id=$partieId)\n";
    }

    foreach ($eval['questions'] as $idx => $q) {
        $nbTotal++;
        $texte  = $q['enonce'] ?? '';
        $type   = mapType($q['type'] ?? '');
        $points = (float)($q['points'] ?? 1);

        $stmtQ = $pdo->prepare("SELECT id FROM questions WHERE partie_id = ? AND texte = ?");
        $stmtQ->execute([$partieId, $texte]);
        if ($stmtQ->fetchColumn()) {
            echo "  [SKIP] Q" . ($idx+1) . " déjà présente\n";
            $nbSkip++;
            continue;
        }

        $pdo->prepare("INSERT INTO questions (module_id, partie_id, texte, type, points, ordre) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$moduleId, $partieId, $texte, $type, $points, $idx + 1]);
        $questionId = $pdo->lastInsertId();

        $options   = $q['options'] ?? [];
        $correctes = isset($q['reponses_correctes']) ? $q['reponses_correctes']
                   : (isset($q['reponse_correcte']) ? [$q['reponse_correcte']] : []);

        if ($type === 'qcm' || $type === 'multiple') {
            foreach ($options as $ord => $optStr) {
                $parsed = parseOption($optStr);
                $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)")
                    ->execute([$questionId, $parsed['texte'], in_array($parsed['lettre'], $correctes, true) ? 1 : 0, $ord + 1]);
            }
        } elseif ($type === 'vrai_faux') {
            $correcte = $q['reponse_correcte'] ?? 'Vrai';
            foreach (['Vrai', 'Faux'] as $ord => $label) {
                $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)")
                    ->execute([$questionId, $label, ($label === $correcte ? 1 : 0), $ord + 1]);
            }
        } else {
            foreach (array_merge(
                array_map(fn($el) => parseOption($el)['texte'], $q['elements_droite'] ?? []),
                $q['reponses_correctes'] ?? (is_array($correctes) ? array_filter($correctes, 'is_string') : []),
                $q['reponse_attendue_cles'] ?? []
            ) as $ord => $rep) {
                $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, 1, ?)")
                    ->execute([$questionId, $rep, $ord + 1]);
            }
        }

        echo "  [OK] Q" . ($idx+1) . " : " . mb_substr($texte, 0, 60) . "...\n";
        $nbImporte++;
    }

    $pdo->commit();
    echo "\nTerminé : $nbImporte importée(s), $nbSkip ignorée(s) sur $nbTotal.\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "ERREUR BD : " . $e->getMessage() . "\n";
    exit(1);
}
