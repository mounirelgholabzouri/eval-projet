<?php
/**
 * Import d'un fichier JSON d'évaluation externe (format "evaluation.questions")
 * Convertit les types QCM_choix_unique/multiple, Vrai_Faux, Association, Completion, Scenario_pratique
 * vers le format interne (qcm, multiple, vrai_faux, texte_libre)
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo    = getDB();
$msg    = '';
$errors = [];
$stats  = [];

// Mapping des types externes → types internes
function mapType(string $type): string {
    return match ($type) {
        'QCM_choix_unique'        => 'qcm',
        'QCM_choix_multiple'      => 'multiple',
        'Vrai_Faux',
        'Vrai_Faux_Justification' => 'vrai_faux',
        default                   => 'texte_libre', // Association, Completion, Scenario_pratique
    };
}

// Parse "A) texte option" → ['lettre' => 'A', 'texte' => 'texte option']
function parseOption(string $opt): array {
    if (preg_match('/^([A-Z])\)\s*(.+)$/s', trim($opt), $m)) {
        return ['lettre' => $m[1], 'texte' => trim($m[2])];
    }
    return ['lettre' => '', 'texte' => trim($opt)];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['json_file'])) {
    $raw  = file_get_contents($_FILES['json_file']['tmp_name']);
    $data = json_decode($raw, true);

    $eval = $data['evaluation'] ?? null;
    if (!$eval || !isset($eval['questions'])) {
        $errors[] = 'Fichier JSON invalide — structure "evaluation.questions" introuvable.';
    } else {
        $moduleNom  = $eval['module'] ?? 'Module importé';
        $partieNom  = $eval['titre']  ?? 'Général';
        $nbTotal    = 0;
        $nbImporte  = 0;
        $nbSkip     = 0;

        $pdo->beginTransaction();
        try {
            // Trouver ou créer le module
            $stmtM = $pdo->prepare("SELECT id FROM modules WHERE nom = ? AND type = 'qcm'");
            $stmtM->execute([$moduleNom]);
            $moduleId = $stmtM->fetchColumn();
            if (!$moduleId) {
                $pdo->prepare("INSERT INTO modules (nom, type, actif) VALUES (?, 'qcm', 1)")
                    ->execute([$moduleNom]);
                $moduleId = $pdo->lastInsertId();
            }

            // Trouver ou créer la partie
            $stmtP = $pdo->prepare("SELECT id FROM parties WHERE module_id = ? AND nom = ?");
            $stmtP->execute([$moduleId, $partieNom]);
            $partieId = $stmtP->fetchColumn();
            if (!$partieId) {
                $stmtOrdre = $pdo->prepare("SELECT COALESCE(MAX(ordre),0)+1 FROM parties WHERE module_id = ?");
                $stmtOrdre->execute([$moduleId]);
                $ordre = (int)$stmtOrdre->fetchColumn();
                $pdo->prepare("INSERT INTO parties (module_id, nom, ordre, actif) VALUES (?, ?, ?, 1)")
                    ->execute([$moduleId, $partieNom, $ordre]);
                $partieId = $pdo->lastInsertId();
            }

            foreach ($eval['questions'] as $idx => $q) {
                $nbTotal++;
                $texte   = $q['enonce'] ?? '';
                $type    = mapType($q['type'] ?? '');
                $points  = (float)($q['points'] ?? 1);

                // Doublons
                $stmtQ = $pdo->prepare("SELECT id FROM questions WHERE partie_id = ? AND texte = ?");
                $stmtQ->execute([$partieId, $texte]);
                if ($stmtQ->fetchColumn()) { $nbSkip++; continue; }

                $pdo->prepare("INSERT INTO questions (module_id, partie_id, texte, type, points, ordre)
                               VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$moduleId, $partieId, $texte, $type, $points, $idx + 1]);
                $questionId = $pdo->lastInsertId();

                // Construire les choix selon le type
                $options = $q['options'] ?? [];

                if ($type === 'qcm' || $type === 'multiple') {
                    $correctes = isset($q['reponses_correctes'])
                        ? $q['reponses_correctes']
                        : (isset($q['reponse_correcte']) ? [$q['reponse_correcte']] : []);

                    foreach ($options as $ord => $optStr) {
                        $parsed     = parseOption($optStr);
                        $is_correct = in_array($parsed['lettre'], $correctes, true) ? 1 : 0;
                        $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)")
                            ->execute([$questionId, $parsed['texte'], $is_correct, $ord + 1]);
                    }

                } elseif ($type === 'vrai_faux') {
                    $correcte = $q['reponse_correcte'] ?? 'Vrai';
                    foreach (['Vrai', 'Faux'] as $ord => $label) {
                        $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)")
                            ->execute([$questionId, $label, ($label === $correcte ? 1 : 0), $ord + 1]);
                    }

                } elseif ($type === 'texte_libre') {
                    // Association : éléments_droite comme choix, reponses_correctes comme méta
                    if (isset($q['elements_droite'])) {
                        foreach ($q['elements_droite'] as $ord => $el) {
                            $parsed = parseOption($el);
                            $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, 0, ?)")
                                ->execute([$questionId, $parsed['texte'], $ord + 1]);
                        }
                    }
                    // Completion / Scenario : reponses_correctes comme choix marqués corrects
                    if (isset($q['reponses_correctes']) && is_array($q['reponses_correctes'])) {
                        foreach ($q['reponses_correctes'] as $ord => $rep) {
                            if (is_string($rep)) {
                                $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, 1, ?)")
                                    ->execute([$questionId, $rep, $ord + 1]);
                            }
                        }
                    }
                    // Scenario_pratique : reponse_attendue_cles
                    if (isset($q['reponse_attendue_cles']) && is_array($q['reponse_attendue_cles'])) {
                        foreach ($q['reponse_attendue_cles'] as $ord => $cle) {
                            $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, 1, ?)")
                                ->execute([$questionId, $cle, $ord + 1]);
                        }
                    }
                }

                $nbImporte++;
            }

            $pdo->commit();
            $stats = [
                'total'   => $nbTotal,
                'importe' => $nbImporte,
                'skip'    => $nbSkip,
                'module'  => $moduleNom,
                'partie'  => $partieNom,
            ];
            $msg = "Import terminé : {$nbImporte} question(s) importée(s), {$nbSkip} déjà présente(s).";

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Erreur BD : ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Import évaluation JSON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container py-5" style="max-width:660px">
    <h2 class="h4 fw-bold mb-4">
        <i class="bi bi-file-earmark-arrow-up me-2 text-primary"></i>Import évaluation JSON externe
    </h2>

    <?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($msg): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
        <?php if ($stats): ?>
        <ul class="mb-0 mt-2 small">
            <li>Module créé/trouvé : <strong><?= htmlspecialchars($stats['module']) ?></strong></li>
            <li>Partie créée/trouvée : <strong><?= htmlspecialchars($stats['partie']) ?></strong></li>
            <li>Total dans le fichier : <?= $stats['total'] ?></li>
            <li>Importées : <strong class="text-success"><?= $stats['importe'] ?></strong></li>
            <li>Déjà présentes (ignorées) : <?= $stats['skip'] ?></li>
        </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 p-4 mb-3">
        <p class="small text-muted mb-3">
            Accepte les fichiers JSON au format <code>{"evaluation": {"module": "...", "titre": "...", "questions": [...]}}</code>.<br>
            Types supportés : QCM_choix_unique, QCM_choix_multiple, Vrai_Faux, Vrai_Faux_Justification, Association, Completion, Scenario_pratique.
        </p>
        <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            <div class="mb-3">
                <label class="form-label fw-semibold">Fichier JSON d'évaluation</label>
                <input type="file" name="json_file" accept=".json" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-upload me-2"></i>Importer
            </button>
        </form>
    </div>

    <div class="text-center">
        <a href="questions.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-list-ul me-1"></i>Gérer les questions
        </a>
        <a href="index.php" class="btn btn-outline-secondary btn-sm ms-2">
            <i class="bi bi-arrow-left me-1"></i>Retour
        </a>
    </div>
</div>
</body>
</html>
