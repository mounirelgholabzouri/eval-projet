<?php
/**
 * gestion_questions.php — Page unifiée de gestion des questions
 * Fusionne : questions.php, move_questions.php, import_questions.php, import_evaluation_json.php
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

// ── Paramètres globaux ───────────────────────────────────────
$tab      = $_GET['tab'] ?? 'gerer';
$moduleId = (int)($_GET['module_id'] ?? $_POST['module_id'] ?? 0);

$msg        = '';
$erreur     = '';
$msgEval    = '';
$statsEval  = [];
$errorsEval = [];

// ── Fonctions d'import évaluation JSON ──────────────────────
function mapType(string $type): string {
    return match (strtoupper($type)) {
        'QCM', 'QCM_CHOIX_UNIQUE'        => 'qcm',
        'QCM_CHOIX_MULTIPLE'              => 'multiple',
        'VF', 'VRAI_FAUX',
        'VRAI_FAUX_JUSTIFICATION'         => 'vrai_faux',
        default                           => 'texte_libre',
    };
}

function parseOption(string $opt): array {
    if (preg_match('/^([A-Z])\)\s*(.+)$/s', trim($opt), $m)) {
        return ['lettre' => $m[1], 'texte' => trim($m[2])];
    }
    return ['lettre' => '', 'texte' => trim($opt)];
}

function normaliserOptions(mixed $options): array {
    if (!$options) return [];
    $result = [];
    if (is_array($options) && array_keys($options) !== range(0, count($options) - 1)) {
        foreach ($options as $lettre => $texte) {
            $result[] = ['lettre' => strtoupper((string)$lettre), 'texte' => trim((string)$texte)];
        }
    } elseif (is_array($options) && !empty($options) && is_array($options[0]) && isset($options[0]['texte'])) {
        foreach ($options as $opt) {
            $result[] = [
                'lettre' => strtoupper(trim((string)($opt['lettre'] ?? ''))),
                'texte'  => trim((string)($opt['texte'] ?? '')),
            ];
        }
    } else {
        foreach ($options as $opt) {
            $result[] = parseOption((string)$opt);
        }
    }
    return $result;
}

function normaliserReponseVF(mixed $rep): string {
    if (is_bool($rep)) return $rep ? 'Vrai' : 'Faux';
    $s = strtolower(trim((string)$rep));
    return in_array($s, ['true', 'vrai', '1', 'yes', 'oui'], true) ? 'Vrai' : 'Faux';
}

/** Supprime des questions jamais répondues (les réponses des stagiaires seraient effacées en cascade). */
function supprimerQuestionsLibres(PDO $pdo, array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [0, 0];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $used = $pdo->prepare("SELECT DISTINCT question_id FROM reponses_stagiaires WHERE question_id IN ($ph)");
    $used->execute($ids);
    $bloquees = array_map('intval', $used->fetchAll(PDO::FETCH_COLUMN));
    $libres = array_values(array_diff($ids, $bloquees));
    if ($libres) {
        $ph2 = implode(',', array_fill(0, count($libres), '?'));
        $pdo->prepare("DELETE FROM questions WHERE id IN ($ph2)")->execute($libres);
    }
    return [count($libres), count($bloquees)];
}

// ── POST : supprimer une question ────────────────────────────
$questionId = (int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_question'])) {
    verifyCsrfToken();
    [$ok, $bloq] = supprimerQuestionsLibres($pdo, [(int)$_POST['delete_question']]);
    header("Location: gestion_questions.php?tab=gerer&module_id=$moduleId&" . ($ok ? 'deleted=1' : 'blocked=1')); exit;
}

// ── POST : enregistrer une question ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_question'])) {
    verifyCsrfToken();
    $texte         = trim($_POST['texte'] ?? '');
    $type          = $_POST['type'] ?? 'qcm';
    $points        = (float)($_POST['points'] ?? 1);
    $ordre         = (int)($_POST['ordre'] ?? 0);
    $choixTextes   = $_POST['choix_texte'] ?? [];
    $choixCorrects = $_POST['choix_correct'] ?? [];

    if (strlen($texte) < 3) {
        $erreur = "Le texte de la question est requis.";
        $tab = 'gerer';
    } elseif ($moduleId <= 0) {
        $erreur = "Module non sélectionné.";
        $tab = 'gerer';
    } elseif (in_array($type, ['qcm', 'vrai_faux', 'multiple'], true) && empty(array_filter((array)$choixCorrects, fn($i) => trim($choixTextes[$i] ?? '') !== ''))) {
        $erreur = "Cochez au moins une bonne réponse (sinon tous les stagiaires obtiendraient 0 à cette question).";
        $tab = 'gerer';
    } else {
        if ($questionId > 0) {
            $pdo->prepare("UPDATE questions SET texte=?, type=?, points=?, ordre=? WHERE id=?")
                ->execute([$texte, $type, $points, $ordre, $questionId]);
            $pdo->prepare("DELETE FROM choix_reponses WHERE question_id=?")->execute([$questionId]);
        } else {
            $questionId = creerQuestion($moduleId, $type, $texte, $points);
            if ($ordre > 0) $pdo->prepare("UPDATE questions SET ordre = ? WHERE id = ?")->execute([$ordre, $questionId]);
        }

        if (in_array($type, ['qcm', 'vrai_faux', 'multiple'])) {
            foreach ($choixTextes as $i => $ct) {
                $ct = trim($ct);
                if ($ct === '') continue;
                $isC = in_array((string)$i, (array)$choixCorrects) ? 1 : 0;
                $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?,?,?,?)")
                    ->execute([$questionId, $ct, $isC, $i + 1]);
            }
        }
        header("Location: gestion_questions.php?tab=gerer&module_id=$moduleId&saved=1"); exit;
    }
}

// ── POST : suppression en masse ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    verifyCsrfToken();
    $bulkAction  = $_POST['bulk_action'] ?? '';
    [$ok, $bloq] = $bulkAction === 'delete' ? supprimerQuestionsLibres($pdo, $_POST['selected_ids'] ?? []) : [0, 0];
    header("Location: gestion_questions.php?tab=gerer&module_id=$moduleId&bulk_deleted=1&count=$ok&blocked=$bloq"); exit;
}

// ── POST : déplacer des questions ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_questions'])) {
    verifyCsrfToken();
    $questionIds    = $_POST['question_ids'] ?? [];
    $targetModuleId = (int)($_POST['target_module_id'] ?? 0);

    if (empty($questionIds)) {
        $erreur = "Sélectionnez au moins une question.";
        $tab = 'deplacer';
    } elseif ($targetModuleId <= 0) {
        $erreur = "Module cible requis.";
        $tab = 'deplacer';
    } else {
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM modules WHERE id = ?");
        $stmtCheck->execute([$targetModuleId]);
        if ($stmtCheck->fetchColumn() == 0) {
            $erreur = "Le module cible n'existe pas.";
            $tab = 'deplacer';
        } else {
            $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
            $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE id IN ($placeholders)");
            $stmtCnt->execute($questionIds);
            if ((int)$stmtCnt->fetchColumn() !== count($questionIds)) {
                $erreur = "Une ou plusieurs questions n'existent pas.";
                $tab = 'deplacer';
            } else {
                $stmt = $pdo->prepare("UPDATE questions SET module_id = ? WHERE id IN ($placeholders)");
                $params = array_merge([$targetModuleId], $questionIds);
                $stmt->execute($params);
                header("Location: gestion_questions.php?tab=deplacer&moved=1"); exit;
            }
        }
    }
}

// ── POST : import évaluation JSON ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['import_type'] ?? '') === 'eval') {
    verifyCsrfToken();
    $tab = 'import_eval';

    $raw = '';
    if (!empty($_FILES['json_file']['tmp_name'])) {
        $raw = file_get_contents($_FILES['json_file']['tmp_name']);
    } elseif (!empty($_POST['json_text'])) {
        $raw = trim($_POST['json_text']);
    }

    $moduleDestId      = (int)($_POST['module_dest_id'] ?? 0);
    $moduleDestNom     = trim($_POST['module_dest_nom'] ?? '');
    $moduleDestCode    = strtoupper(trim($_POST['module_dest_code'] ?? ''));
    $moduleDestFiliere = strtoupper(trim($_POST['module_dest_filiere'] ?? ''));
    $evalType          = ($_POST['eval_type'] ?? 'cc') === 'efm' ? 'efm' : 'cc';
    $evalNumero        = max(1, (int)($_POST['eval_numero'] ?? 1));
    $evalContenu       = trim($_POST['eval_contenu'] ?? '');
    if ($moduleDestId <= 0 && !preg_match('/^[A-Z0-9]{2,20}$/', $moduleDestCode)) {
        $errorsEval[] = 'Nouveau module : indiquez un code valide (ex. M207).';
    }

    if ($raw === '') {
        $errorsEval[] = 'Veuillez fournir un fichier JSON ou coller le contenu JSON.';
    } elseif (!$errorsEval) {
        $data = json_decode($raw, true);

        if (isset($data['evaluation'])) {
            $eval = $data['evaluation'];
        } elseif (isset($data['questions'])) {
            $eval = $data;
        } else {
            $eval = null;
        }

        if (!$eval || !isset($eval['questions'])) {
            $cles = implode(', ', array_keys($data ?? []));
            $errorsEval[] = 'Fichier JSON invalide — structure "evaluation.questions" introuvable. Clés trouvées : ' . ($cles ?: '(aucune)');
        } else {
            $contenu   = $evalContenu !== '' ? $evalContenu : trim((string)($eval['titre'] ?? ''));
            $nbTotal   = 0;
            $nbImporte = 0;
            $nbSkip    = 0;
            $idsEval   = [];

            $pdo->beginTransaction();
            try {
                $midEval = $moduleDestId > 0
                    ? $moduleDestId
                    : trouverOuCreerModule($moduleDestCode, $moduleDestFiliere,
                                           $moduleDestNom !== '' ? $moduleDestNom : ($eval['module'] ?? $moduleDestCode));
                $moduleImport = getModule($midEval);
                if (!$moduleImport) throw new RuntimeException('Module destination introuvable.');
                $moduleNom = $moduleImport['libelle'];

                foreach ($eval['questions'] as $idx => $q) {
                    $nbTotal++;
                    $texte  = trim($q['enonce'] ?? $q['question'] ?? '');
                    $points = (float)($q['points'] ?? 1);

                    $rawOptions = $q['options'] ?? $q['choix'] ?? [];
                    $rawType    = $q['type'] ?? '';
                    if ($rawType === '' && !empty($rawOptions)) {
                        $rawType = 'QCM';
                    }
                    $type = mapType($rawType);

                    if ($texte === '') { $nbSkip++; continue; }

                    $stmtQ = $pdo->prepare("SELECT id FROM questions WHERE module_id = ? AND texte = ?");
                    $stmtQ->execute([$midEval, $texte]);
                    if ($existante = $stmtQ->fetchColumn()) { $idsEval[] = (int)$existante; $nbSkip++; continue; }

                    $qidEval   = creerQuestion($midEval, $type, $texte, $points);
                    $idsEval[] = $qidEval;

                    $options = normaliserOptions($rawOptions);

                    if ($type === 'qcm' || $type === 'multiple') {
                        $correctes = isset($q['reponses_correctes'])
                            ? (array)$q['reponses_correctes']
                            : (isset($q['reponse_correcte']) ? [(string)$q['reponse_correcte']]
                            : (isset($q['bonnes_reponses'])  ? (array)$q['bonnes_reponses']
                            : (isset($q['bonne_reponse'])    ? [(string)$q['bonne_reponse']] : [])));
                        $correctes = array_map('strtoupper', $correctes);

                        foreach ($options as $ord => $opt) {
                            $is_correct = in_array(strtoupper($opt['lettre']), $correctes, true) ? 1 : 0;
                            $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)")
                                ->execute([$qidEval, $opt['texte'], $is_correct, $ord + 1]);
                        }

                    } elseif ($type === 'vrai_faux') {
                        $correcte = normaliserReponseVF($q['reponse_correcte'] ?? 'Vrai');
                        foreach (['Vrai', 'Faux'] as $ord => $label) {
                            $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)")
                                ->execute([$qidEval, $label, ($label === $correcte ? 1 : 0), $ord + 1]);
                        }

                    } elseif ($type === 'texte_libre') {
                        // Association : paires gauche/droite explicites ("paires"/"associations"),
                        // sinon zip elements_gauche + elements_droite (même ordre), stockées comme
                        // réponse attendue (is_correct=1) pour servir de référence à la correction.
                        $paires = $q['paires'] ?? $q['associations'] ?? null;
                        if (is_array($paires) && !empty($paires)) {
                            foreach (array_values($paires) as $ord => $p) {
                                $g = trim((string)(is_array($p) ? ($p['gauche'] ?? $p[0] ?? '') : ''));
                                $d = trim((string)(is_array($p) ? ($p['droite'] ?? $p[1] ?? '') : ''));
                                if ($g === '' && $d === '') continue;
                                $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, 1, ?)")
                                    ->execute([$qidEval, $g . ' → ' . $d, $ord + 1]);
                            }
                        } elseif (isset($q['elements_gauche']) && isset($q['elements_droite'])) {
                            $gaucheOpts = normaliserOptions($q['elements_gauche']);
                            $droiteOpts = normaliserOptions($q['elements_droite']);
                            // Mapping explicite clé_gauche => clé_droite (ex: {"1":"C","2":"A","3":"B"})
                            $mapping = (is_array($q['reponse_correcte'] ?? null)
                                && array_keys($q['reponse_correcte']) !== range(0, count($q['reponse_correcte']) - 1))
                                ? $q['reponse_correcte'] : null;
                            $droiteParLettre = [];
                            foreach ($droiteOpts as $d) {
                                $droiteParLettre[$d['lettre']] = $d['texte'];
                            }
                            foreach ($gaucheOpts as $ord => $g) {
                                if ($mapping !== null && isset($mapping[$g['lettre']]) && isset($droiteParLettre[strtoupper((string)$mapping[$g['lettre']])])) {
                                    $d = $droiteParLettre[strtoupper((string)$mapping[$g['lettre']])];
                                } else {
                                    $d = $droiteOpts[$ord]['texte'] ?? '';
                                }
                                $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, 1, ?)")
                                    ->execute([$qidEval, $g['texte'] . ' → ' . $d, $ord + 1]);
                            }
                        } elseif (isset($q['elements_droite'])) {
                            // Pas d'éléments gauche fournis : on garde la liste à titre informatif
                            foreach (normaliserOptions($q['elements_droite']) as $ord => $opt) {
                                $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, 0, ?)")
                                    ->execute([$qidEval, $opt['texte'], $ord + 1]);
                            }
                        }
                        if (isset($q['reponses_correctes']) && is_array($q['reponses_correctes'])) {
                            foreach ($q['reponses_correctes'] as $ord => $rep) {
                                if (is_string($rep)) {
                                    $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, 1, ?)")
                                        ->execute([$qidEval, $rep, $ord + 1]);
                                }
                            }
                        }
                        if (isset($q['reponse_attendue_cles']) && is_array($q['reponse_attendue_cles'])) {
                            foreach ($q['reponse_attendue_cles'] as $ord => $cle) {
                                $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, 1, ?)")
                                    ->execute([$qidEval, $cle, $ord + 1]);
                            }
                        }
                    }

                    $nbImporte++;
                }

                $newEvalId = creerEvaluation([
                    'module_id'    => $midEval,
                    'type'         => $evalType,
                    'mode'         => 'theorique',
                    'numero'       => $evalNumero,
                    'contenu'      => $contenu,
                    'actif'        => false,
                    'question_ids' => $idsEval,
                ]);
                $pdo->commit();
                $statsEval = [
                    'total'      => $nbTotal,
                    'importe'    => $nbImporte,
                    'skip'       => $nbSkip,
                    'module'     => $moduleNom,
                    'evaluation' => getEvaluation($newEvalId)['nom'],
                    'eval_id'    => $newEvalId,
                ];
                $msgEval = "Import terminé : {$nbImporte} question(s) importée(s), {$nbSkip} déjà présente(s).";

            } catch (Exception $e) {
                $pdo->rollBack();
                $errorsEval[] = 'Erreur BD : ' . $e->getMessage();
            }
        }
    }
}

// ── Chargement des données ───────────────────────────────────
$allModules = getAllModules();
$module     = $moduleId > 0 ? getModule($moduleId) : null;

$questionsCurrent = [];
$statsQuestions   = [];
$editQuestion     = null;
$totalPointsModule = 0;

if ($moduleId > 0 && $module) {
    $questionsCurrent = getQuestionsModule($moduleId);
    $statsQuestions   = getStatsQuestionsModule($moduleId); // taux de réussite en direct, toutes sessions et tous groupes

    $totalPointsModule = array_sum(array_column($questionsCurrent, 'points'));

    if (($tab === 'gerer') && ($questionId > 0)) {
        foreach ($questionsCurrent as $q) {
            if ((int)$q['id'] === $questionId) { $editQuestion = $q; break; }
        }
    }
}

// Pour l'onglet Déplacer
$filterModule = (int)($_GET['filter_module'] ?? 0);
$searchText   = trim($_GET['search'] ?? '');
$questionsDeplacer = [];
if ($tab === 'deplacer') {
    $baseQuery = "SELECT q.*, m.nom AS module_nom FROM questions q JOIN modules m ON m.id = q.module_id WHERE 1=1";
    $paramsD = [];
    if ($filterModule > 0) { $baseQuery .= " AND q.module_id = ?"; $paramsD[] = $filterModule; }
    if ($searchText !== '') { $baseQuery .= " AND q.texte LIKE ?"; $paramsD[] = "%$searchText%"; }
    $baseQuery .= " ORDER BY m.nom, q.ordre, q.id";
    $stmt = $pdo->prepare($baseQuery);
    $stmt->execute($paramsD);
    $questionsDeplacer = $stmt->fetchAll();
}

// Pour l'onglet import_eval

// Flash messages
$flash = $_GET;
if (isset($flash['saved']))        $msg = "Question enregistrée.";
if (isset($flash['deleted']))      $msg = "Question supprimée.";
if (isset($flash['bulk_deleted'])) $msg = (int)$flash['count'] . " question(s) supprimée(s).";
if (!empty($flash['blocked']))     $erreur = (isset($flash['bulk_deleted']) ? (int)$flash['blocked'] . " question(s) conservée(s)" : "Question conservée")
                                          . " : déjà répondue(s) par des stagiaires (la supprimer effacerait leurs réponses et modifierait leurs notes).";
if (isset($flash['moved']))        $msg = "Questions déplacées avec succès.";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestion des Questions — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .question-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin-bottom: 8px; background: #fff; cursor: pointer; transition: all 0.15s; }
        .question-card:hover { background: #f9fafb; border-color: #6366f1; }
        .question-card.selected { background: #eef2ff; border-color: #4f46e5; }
        .question-text { flex: 1; word-break: break-word; color: #374151; font-size: 14px; line-height: 1.4; }
        .question-meta { display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; }
        .badge-meta { font-size: 11px; padding: 3px 8px; }
    </style>
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>

<div class="container-fluid py-4 px-4">
    <h2 class="h4 fw-bold mb-3"><i class="bi bi-question-circle me-2 text-primary"></i>Gestion des Questions</h2>

    <?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show rounded-3">
        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($erreur): ?>
    <div class="alert alert-danger rounded-3"><i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($erreur) ?></div>
    <?php endif; ?>

    <!-- Navigation par onglets -->
    <ul class="nav nav-pills mb-4 gap-1">
        <li class="nav-item">
            <a href="gestion_questions.php?tab=gerer&module_id=<?= $moduleId ?>"
               class="nav-link <?= $tab === 'gerer' ? 'active' : '' ?>">
                <i class="bi bi-question-circle me-1"></i>Gérer
            </a>
        </li>
        <li class="nav-item">
            <a href="gestion_questions.php?tab=deplacer"
               class="nav-link <?= $tab === 'deplacer' ? 'active' : '' ?>">
                <i class="bi bi-arrow-left-right me-1"></i>Déplacer
            </a>
        </li>
        <li class="nav-item">
            <a href="gestion_questions.php?tab=import_eval"
               class="nav-link <?= $tab === 'import_eval' ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-arrow-up me-1"></i>Import Évaluation
            </a>
        </li>
    </ul>

    <!-- ══════════════════════════════════════════════════════════
         ONGLET : GÉRER
    ══════════════════════════════════════════════════════════ -->
    <?php if ($tab === 'gerer'): ?>

    <!-- Sélection module -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form method="GET" action="gestion_questions.php" class="d-flex gap-3 align-items-center flex-wrap">
                <input type="hidden" name="tab" value="gerer">
                <label class="fw-semibold text-nowrap"><i class="bi bi-journal me-1"></i>Module :</label>
                <select name="module_id" class="form-select" style="max-width:350px"
                        onchange="this.form.submit()">
                    <option value="">— Choisir un module —</option>
                    <?php foreach ($allModules as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $m['id'] == $moduleId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($m['libelle']) ?> (<?= $m['nb_questions'] ?> Q)
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <?php if ($moduleId > 0 && $module): ?>
    <div class="row g-4">
        <!-- Formulaire question -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3 px-4">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-<?= $editQuestion ? 'pencil' : 'plus-circle' ?> me-2 text-primary"></i>
                        <?= $editQuestion ? 'Modifier' : 'Ajouter' ?> une question
                        <small class="text-muted fw-normal">· <?= htmlspecialchars($module['nom']) ?></small>
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="gestion_questions.php?tab=gerer&module_id=<?= $moduleId ?><?= $editQuestion ? '&action=edit&id='.$questionId : '' ?>">
                        <?= csrfField() ?>
                        <input type="hidden" name="module_id" value="<?= $moduleId ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Question <span class="text-danger">*</span></label>
                            <textarea name="texte" class="form-control" rows="3" required><?= htmlspecialchars($editQuestion['texte'] ?? '') ?></textarea>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-7">
                                <label class="form-label fw-semibold">Type</label>
                                <select name="type" class="form-select" id="typeSelect" onchange="toggleChoix()">
                                    <option value="qcm" <?= ($editQuestion['type'] ?? '') === 'qcm' ? 'selected' : '' ?>>QCM</option>
                                    <option value="vrai_faux" <?= ($editQuestion['type'] ?? '') === 'vrai_faux' ? 'selected' : '' ?>>Vrai / Faux</option>
                                    <option value="texte_libre" <?= ($editQuestion['type'] ?? '') === 'texte_libre' ? 'selected' : '' ?>>Réponse libre</option>
                                    <option value="multiple" <?= ($editQuestion['type'] ?? '') === 'multiple' ? 'selected' : '' ?>>Choix multiples</option>
                                </select>
                            </div>
                            <div class="col-3">
                                <label class="form-label fw-semibold">Points</label>
                                <input type="number" name="points" class="form-control" step="0.5" min="0.5"
                                       value="<?= $editQuestion['points'] ?? 1 ?>">
                            </div>
                            <div class="col-2">
                                <label class="form-label fw-semibold">Ordre</label>
                                <input type="number" name="ordre" class="form-control" min="0"
                                       value="<?= $editQuestion['ordre'] ?? count($questionsCurrent) + 1 ?>">
                            </div>
                        </div>

                        <!-- Choix de réponses -->
                        <div id="choixSection">
                            <label class="form-label fw-semibold">Choix de réponses</label>
                            <div id="choixList">
                                <?php
                                $existingChoix = $editQuestion['choix'] ?? [];
                                $defChoix = !empty($existingChoix) ? $existingChoix : [
                                    ['texte'=>'', 'is_correct'=>0],
                                    ['texte'=>'', 'is_correct'=>0],
                                    ['texte'=>'', 'is_correct'=>0],
                                    ['texte'=>'', 'is_correct'=>0],
                                ];
                                foreach ($defChoix as $i => $c): ?>
                                <div class="d-flex align-items-center gap-2 mb-2 choix-row">
                                    <input type="text" name="choix_texte[]" class="form-control form-control-sm"
                                           placeholder="Réponse <?= chr(65+$i) ?>"
                                           value="<?= htmlspecialchars($c['texte']) ?>">
                                    <div class="form-check mb-0">
                                        <input type="checkbox" name="choix_correct[]" value="<?= $i ?>"
                                               class="form-check-input" <?= $c['is_correct'] ? 'checked' : '' ?>>
                                        <label class="form-check-label small text-success">OK</label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="addChoix()">
                                <i class="bi bi-plus me-1"></i>Ajouter un choix
                            </button>
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" name="save_question" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i>Enregistrer
                            </button>
                            <?php if ($editQuestion): ?>
                            <a href="gestion_questions.php?tab=gerer&module_id=<?= $moduleId ?>" class="btn btn-outline-secondary">Annuler</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Liste des questions -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3 px-4 d-flex justify-content-between flex-wrap gap-2">
                    <h5 class="mb-0 fw-bold">
                        Banque — <?= htmlspecialchars($module['libelle']) ?>
                    </h5>
                    <span class="badge bg-primary"><?= count($questionsCurrent) ?> question(s)</span>
                    <span class="badge bg-secondary">∑ <?= rtrim(rtrim(number_format($totalPointsModule, 2, ',', ''), '0'), ',') ?> pts</span>
                </div>
                <!-- Barre d'actions en masse -->
                <div id="bulkActionBar" class="card-header bg-light border-bottom py-3 px-4" style="display:none;">
                    <div class="d-flex gap-2 align-items-center">
                        <span class="text-muted"><span id="bulkCount">0</span> sélectionnée(s)</span>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="bulkDeleteQuestions()">
                            <i class="bi bi-trash me-1"></i>Supprimer
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" onclick="clearSelection()">Annuler</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($questionsCurrent)): ?>
                        <p class="text-center text-muted py-4"><i class="bi bi-inbox me-2"></i>Aucune question dans ce module.</p>
                    <?php endif; ?>
                    <div style="display:flex;flex-direction:column;">
                        <div style="padding:12px 16px;border-bottom:1px solid #dee2e6;font-weight:600;font-size:13px;">
                            <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll()" style="margin-right:10px;cursor:pointer;">
                            <span style="cursor:pointer;user-select:none;" onclick="document.getElementById('selectAll').click()">Sélectionner tout</span>
                        </div>
                    </div>
                    <?php foreach ($questionsCurrent as $idx => $q): ?>
                    <div class="p-3 border-bottom d-flex align-items-start gap-3">
                        <input type="checkbox" class="form-check-input question-checkbox mt-2" value="<?= $q['id'] ?>" onchange="updateBulkActionBar()">
                        <div class="question-number small"><?= $idx + 1 ?></div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold small"><?= htmlspecialchars(mb_substr($q['texte'], 0, 100)) ?><?= mb_strlen($q['texte']) > 100 ? '…' : '' ?></div>
                            <div class="mt-1 d-flex gap-2 flex-wrap">
                                <span class="badge bg-light text-muted border small">
                                    <?php $types = ['qcm'=>'QCM','vrai_faux'=>'V/F','texte_libre'=>'Libre','multiple'=>'Multiple'];
                                    echo $types[$q['type']] ?? $q['type']; ?>
                                </span>
                                <span class="badge bg-primary-subtle text-primary small"><?= $q['points'] ?> pt(s)</span>
                                <span class="badge bg-secondary-subtle text-secondary small"><?= count($q['choix']) ?> choix</span>
                                <?php if ($st = $statsQuestions[(int)$q['id']] ?? null):
                                    $cl = $st['taux'] >= 50 ? 'success' : ($st['taux'] >= 30 ? 'warning' : 'danger');
                                    $det = implode(' · ', array_map(fn($g, $t) => "$g : " . round($t) . ' %', array_keys($st['groupes']), $st['groupes'])); ?>
                                <span class="badge bg-<?= $cl ?>-subtle text-<?= $cl ?> small" title="Réussite toutes sessions — par groupe : <?= htmlspecialchars($det) ?>">
                                    <i class="bi bi-graph-up me-1"></i>Réussite <?= $st['taux'] ?> % (<?= $st['ok'] ?>/<?= $st['n'] ?>)
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex gap-1 flex-shrink-0">
                            <a href="gestion_questions.php?tab=gerer&module_id=<?= $moduleId ?>&action=edit&id=<?= $q['id'] ?>"
                               class="btn btn-sm btn-outline-primary rounded-3" title="Modifier">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="gestion_questions.php?tab=gerer&module_id=<?= $moduleId ?>" class="d-inline"
                                  onsubmit="return confirm('Supprimer cette question ?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="module_id" value="<?= $moduleId ?>">
                                <button type="submit" name="delete_question" value="<?= (int)$q['id'] ?>"
                                        class="btn btn-sm btn-outline-danger rounded-3" title="Supprimer">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>
    <div class="alert alert-info rounded-3">
        <i class="bi bi-arrow-up me-2"></i>Sélectionnez un module pour gérer ses questions.
    </div>
    <?php endif; // moduleId > 0 ?>

    <!-- ══════════════════════════════════════════════════════════
         ONGLET : DÉPLACER
    ══════════════════════════════════════════════════════════ -->
    <?php elseif ($tab === 'deplacer'): ?>

    <div class="row g-4">
        <!-- Filtres + destination -->
        <div class="col-lg-3">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 py-3 px-4">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-funnel me-2"></i>Filtres</h6>
                </div>
                <div class="card-body p-4">
                    <form method="GET" action="gestion_questions.php" class="d-flex flex-column gap-3">
                        <input type="hidden" name="tab" value="deplacer">
                        <div>
                            <label class="form-label fw-semibold small">Module</label>
                            <select name="filter_module" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">— Tous les modules —</option>
                                <?php foreach ($allModules as $m): ?>
                                <option value="<?= $m['id'] ?>" <?= $m['id'] == $filterModule ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['libelle']) ?> (<?= $m['nb_questions'] ?> Q)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label fw-semibold small">Recherche</label>
                            <input type="text" name="search" class="form-control form-control-sm"
                                   placeholder="Texte de question..."
                                   value="<?= htmlspecialchars($searchText) ?>"
                                   onkeyup="this.form.submit()">
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise me-1"></i>Rafraîchir
                        </button>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 sticky-top" style="top:20px;">
                <div class="card-header bg-white border-0 py-3 px-4">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-target me-2"></i>Destination</h6>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="gestion_questions.php" id="moveForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="tab" value="deplacer">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Module cible <span class="text-danger">*</span></label>
                            <select name="target_module_id" class="form-select form-select-sm" id="targetModule" onchange="updateMoveButtonCount()">
                                <option value="">— Choisir un module —</option>
                                <?php foreach ($allModules as $m): ?>
                                <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['libelle']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="button" name="move_questions" class="btn btn-primary btn-sm"
                                    id="moveBtn" disabled onclick="submitMove()">
                                <i class="bi bi-arrow-right me-1"></i>Déplacer (0 sélectionnée)
                            </button>
                        </div>
                        <small class="text-muted d-block mt-2">
                            <i class="bi bi-info-circle me-1"></i>Sélectionnez des questions à droite, puis cliquez sur Déplacer.
                        </small>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tableau questions -->
        <div class="col-lg-9">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3 px-4 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold">
                        <i class="bi bi-list-check me-2"></i>Questions
                        <span class="badge bg-primary-subtle text-primary"><?= count($questionsDeplacer) ?></span>
                    </h6>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAllQuestions()">
                        <i class="bi bi-check2-square me-1"></i>Tout sélectionner
                    </button>
                </div>
                <div class="card-body p-0">
                    <form id="questionsForm">
                        <div style="max-height:600px;overflow-y:auto;">
                            <?php if (empty($questionsDeplacer)): ?>
                            <div class="p-4 text-center text-muted">
                                <i class="bi bi-inbox" style="font-size:32px;opacity:.3;"></i>
                                <p class="mt-2">Aucune question trouvée.</p>
                            </div>
                            <?php else: ?>
                                <?php
                                $groupedQ = [];
                                foreach ($questionsDeplacer as $q) {
                                    $groupedQ[$q['module_nom']][] = $q;
                                }
                                foreach ($groupedQ as $mNom => $mQuestions): ?>
                                <div class="px-4 py-2 bg-light border-bottom" style="font-weight:600;font-size:13px;position:sticky;top:0;z-index:10;">
                                    <i class="bi bi-folder me-1"></i><?= htmlspecialchars($mNom) ?>
                                </div>
                                <div class="px-4">
                                    <?php foreach ($mQuestions as $q): ?>
                                    <div class="question-card d-flex align-items-flex-start gap-3">
                                        <input type="checkbox" name="question_ids" value="<?= $q['id'] ?>"
                                               class="form-check-input question-move-checkbox mt-1"
                                               onchange="updateMoveButtonCount()">
                                        <div style="flex:1;">
                                            <div class="question-text">
                                                <?= htmlspecialchars(substr($q['texte'], 0, 120)) ?><?= strlen($q['texte']) > 120 ? '…' : '' ?>
                                            </div>
                                            <div class="question-meta">
                                                <span class="badge bg-secondary-subtle text-secondary badge-meta">
                                                    <i class="bi bi-lightning me-1"></i><?= htmlspecialchars($q['type']) ?>
                                                </span>
                                                <span class="badge bg-primary-subtle text-primary badge-meta">
                                                    <i class="bi bi-star me-1"></i><?= $q['points'] ?>pts
                                                </span>
                                                <span class="badge bg-info-subtle text-info badge-meta">
                                                    Q#<?= $q['id'] ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         ONGLET : IMPORT ÉVALUATION
    ══════════════════════════════════════════════════════════ -->
    <?php elseif ($tab === 'import_eval'): ?>

    <div class="row justify-content-center">
        <div class="col-lg-7">
            <?php if ($errorsEval): ?>
            <div class="alert alert-danger">
                <?php foreach ($errorsEval as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($msgEval): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msgEval) ?>
                <?php if ($statsEval): ?>
                <ul class="mb-0 mt-2 small">
                    <li>Module : <strong><?= htmlspecialchars($statsEval['module']) ?></strong></li>
                    <li>Évaluation créée (inactive) :
                        <a href="evaluations.php?action=edit&id=<?= (int)$statsEval['eval_id'] ?>"><strong><?= htmlspecialchars($statsEval['evaluation']) ?></strong></a></li>
                    <li>Total dans le fichier : <?= $statsEval['total'] ?></li>
                    <li>Importées : <strong class="text-success"><?= $statsEval['importe'] ?></strong></li>
                    <li>Déjà présentes (ignorées) : <?= $statsEval['skip'] ?></li>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm rounded-4 p-4 mb-3">
                <p class="small text-muted mb-3">
                    Format attendu : <code>{"evaluation": {"module": "...", "titre": "...", "questions": [...]}}</code>
                    ou directement <code>{"module": "...", "questions": [...]}</code>.<br>
                    Types supportés : QCM_choix_unique, QCM_choix_multiple, Vrai_Faux, Association, Completion, Scenario_pratique.
                </p>

                <!-- Sous-onglets Fichier / Coller -->
                <ul class="nav nav-tabs mb-3" id="importEvalTabs">
                    <li class="nav-item">
                        <button class="nav-link active" id="eval-tab-file-btn" onclick="switchImportTab('file')" type="button">
                            <i class="bi bi-file-earmark-arrow-up me-1"></i>Fichier JSON
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="eval-tab-paste-btn" onclick="switchImportTab('paste')" type="button">
                            <i class="bi bi-clipboard-fill me-1"></i>Coller JSON
                        </button>
                    </li>
                </ul>

                <form method="POST" action="gestion_questions.php" enctype="multipart/form-data" id="importEvalForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="import_type" value="eval">

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Module destination <span class="text-danger">*</span></label>
                        <select name="module_dest_id" id="evalModuleDestSelect" class="form-select"
                                onchange="toggleNouveauModuleEval(this.value)">
                            <option value="0">— Nouveau module —</option>
                            <?php $posted = ($_POST['import_type'] ?? '') === 'eval'; ?>
                            <?php foreach ($allModules as $m): ?>
                            <option value="<?= $m['id'] ?>"
                                <?= ($posted ? (int)($_POST['module_dest_id'] ?? 0) : $moduleId) === (int)$m['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['libelle']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="evalNouveauModuleBox" class="mt-2 row g-2" style="display:none;">
                            <div class="col-4">
                                <input type="text" name="module_dest_filiere" class="form-control text-uppercase" placeholder="Filière (IDOCC)"
                                       value="<?= htmlspecialchars($posted ? ($_POST['module_dest_filiere'] ?? '') : '') ?>">
                            </div>
                            <div class="col-8">
                                <input type="text" name="module_dest_code" class="form-control text-uppercase" placeholder="Code module * (M207)"
                                       value="<?= htmlspecialchars($posted ? ($_POST['module_dest_code'] ?? '') : '') ?>">
                            </div>
                            <div class="col-12">
                                <input type="text" name="module_dest_nom" class="form-control"
                                       placeholder="Intitulé (vide = champ « module » du JSON)"
                                       value="<?= htmlspecialchars($posted ? ($_POST['module_dest_nom'] ?? '') : '') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="row g-2 mb-4">
                        <div class="col-4">
                            <label class="form-label fw-semibold">Type</label>
                            <select name="eval_type" class="form-select" id="importEvalType"
                                    onchange="document.getElementById('importNumBox').style.display = this.value === 'efm' ? 'none' : ''">
                                <option value="cc">CC</option>
                                <option value="efm" <?= $posted && ($_POST['eval_type'] ?? '') === 'efm' ? 'selected' : '' ?>>EFM</option>
                            </select>
                        </div>
                        <div class="col-3" id="importNumBox">
                            <label class="form-label fw-semibold">N° CC</label>
                            <input type="number" name="eval_numero" class="form-control" min="1" max="9"
                                   value="<?= (int)($posted ? ($_POST['eval_numero'] ?? 1) : 1) ?>">
                        </div>
                        <div class="col">
                            <label class="form-label fw-semibold">Contenu</label>
                            <input type="text" name="eval_contenu" class="form-control" placeholder="vide = « titre » du JSON"
                                   value="<?= htmlspecialchars($posted ? ($_POST['eval_contenu'] ?? '') : '') ?>">
                        </div>
                        <div class="form-text">Une nouvelle évaluation est créée (inactive) avec les questions importées ; elle est à activer dans « Évaluations CC/EFM ».</div>
                    </div>
                    <hr class="my-3">

                    <!-- Mode fichier -->
                    <div id="eval-tab-file">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Fichier JSON</label>
                            <input type="file" name="json_file" accept=".json" class="form-control" id="evalFileInput">
                        </div>
                    </div>

                    <!-- Mode textarea -->
                    <div id="eval-tab-paste" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Contenu JSON</label>
                            <textarea name="json_text" id="evalJsonTextarea" class="form-control font-monospace"
                                      rows="12" placeholder='{"evaluation": {"module": "...", "questions": [...]}}'
                                      style="font-size:.8rem"></textarea>
                            <div class="d-flex gap-2 mt-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="evalPasteFromClipboard()">
                                    <i class="bi bi-clipboard me-1"></i>Coller depuis le presse-papiers
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="document.getElementById('evalJsonTextarea').value=''">
                                    <i class="bi bi-x-circle me-1"></i>Effacer
                                </button>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-upload me-2"></i>Importer
                    </button>
                </form>
            </div>
        </div>
    </div>

    <?php endif; // tab switch ?>

</div><!-- /container-fluid -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ══════════════════════════════════════════════════════════
// JS : Onglet GÉRER — sélection multiple
// ══════════════════════════════════════════════════════════
function updateBulkActionBar() {
    const checkboxes = document.querySelectorAll('.question-checkbox:checked');
    const bar   = document.getElementById('bulkActionBar');
    const count = document.getElementById('bulkCount');
    const selectAll = document.getElementById('selectAll');
    if (!bar || !count) return;
    count.textContent = checkboxes.length;
    bar.style.display = checkboxes.length > 0 ? 'block' : 'none';
    const allCb = document.querySelectorAll('.question-checkbox');
    if (selectAll) {
        selectAll.checked       = allCb.length > 0 && checkboxes.length === allCb.length;
        selectAll.indeterminate = checkboxes.length > 0 && checkboxes.length < allCb.length;
    }
}

function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    if (!selectAll) return;
    document.querySelectorAll('.question-checkbox').forEach(cb => { cb.checked = selectAll.checked; });
    updateBulkActionBar();
}

function clearSelection() {
    document.querySelectorAll('.question-checkbox').forEach(cb => cb.checked = false);
    const selectAll = document.getElementById('selectAll');
    if (selectAll) selectAll.checked = false;
    updateBulkActionBar();
}

function bulkDeleteQuestions() {
    const checkboxes = document.querySelectorAll('.question-checkbox:checked');
    if (checkboxes.length === 0) { if (typeof _toast === 'function') _toast('Sélectionnez au moins une question.', 'warning'); return; }
    const confirmFn = typeof confirmAction === 'function' ? confirmAction : function(msg, cb) { if (confirm(msg)) cb(); };
    confirmFn('Supprimer ' + checkboxes.length + ' question(s) ? Cette action est irréversible.', function () {
        const form = document.createElement('form');
        form.method = 'POST';
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden'; csrfInput.name = 'csrf_token';
        csrfInput.value = document.querySelector('input[name="csrf_token"]')?.value ?? '';
        form.appendChild(csrfInput);
        const tabInput = document.createElement('input');
        tabInput.type = 'hidden'; tabInput.name = 'tab'; tabInput.value = 'gerer';
        form.appendChild(tabInput);
        const modInput = document.createElement('input');
        modInput.type = 'hidden'; modInput.name = 'module_id'; modInput.value = '<?= $moduleId ?>';
        form.appendChild(modInput);
        const baInput = document.createElement('input');
        baInput.type = 'hidden'; baInput.name = 'bulk_action'; baInput.value = 'delete';
        form.appendChild(baInput);
        checkboxes.forEach(function (cb) {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'selected_ids[]'; inp.value = cb.value;
            form.appendChild(inp);
        });
        document.body.appendChild(form);
        form.submit();
    });
}

function toggleChoix() {
    const typeEl = document.getElementById('typeSelect');
    if (!typeEl) return;
    const type    = typeEl.value;
    const section = document.getElementById('choixSection');
    if (!section) return;
    section.style.display = (type === 'texte_libre') ? 'none' : 'block';
    if (type === 'vrai_faux') {
        const list = document.getElementById('choixList');
        if (list) list.innerHTML = `
            <div class="d-flex align-items-center gap-2 mb-2 choix-row">
                <input type="text" name="choix_texte[]" class="form-control form-control-sm" value="Vrai" readonly>
                <div class="form-check mb-0"><input type="checkbox" name="choix_correct[]" value="0" class="form-check-input" checked><label class="form-check-label small text-success">OK</label></div>
            </div>
            <div class="d-flex align-items-center gap-2 mb-2 choix-row">
                <input type="text" name="choix_texte[]" class="form-control form-control-sm" value="Faux" readonly>
                <div class="form-check mb-0"><input type="checkbox" name="choix_correct[]" value="1" class="form-check-input"><label class="form-check-label small text-success">OK</label></div>
            </div>`;
    }
}

let choixCount = document.querySelectorAll('.choix-row').length;
function addChoix() {
    const list = document.getElementById('choixList');
    if (!list) return;
    const idx = choixCount++;
    const div = document.createElement('div');
    div.className = 'd-flex align-items-center gap-2 mb-2 choix-row';
    div.innerHTML = `
        <input type="text" name="choix_texte[]" class="form-control form-control-sm" placeholder="Réponse ${String.fromCharCode(65+idx)}">
        <div class="form-check mb-0">
            <input type="checkbox" name="choix_correct[]" value="${idx}" class="form-check-input">
            <label class="form-check-label small text-success">OK</label>
        </div>
        <button type="button" class="btn btn-sm btn-outline-danger rounded-3" onclick="this.parentElement.remove()">
            <i class="bi bi-x"></i>
        </button>`;
    list.appendChild(div);
}

if (document.getElementById('typeSelect')) toggleChoix();

// ══════════════════════════════════════════════════════════
// JS : Onglet DÉPLACER
// ══════════════════════════════════════════════════════════
function toggleAllQuestions() {
    const checkboxes = document.querySelectorAll('.question-move-checkbox');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    checkboxes.forEach(cb => cb.checked = !allChecked);
    updateMoveButtonCount();
}

function updateMoveButtonCount() {
    const checkboxes = document.querySelectorAll('.question-move-checkbox:checked');
    const count = checkboxes.length;
    const btn = document.getElementById('moveBtn');
    if (!btn) return;
    btn.innerHTML = `<i class="bi bi-arrow-right me-1"></i>Déplacer (${count} sélectionnée${count !== 1 ? 's' : ''})`;
    const targetSel = document.getElementById('targetModule');
    btn.disabled = count === 0 || !targetSel || targetSel.value === '';
    document.querySelectorAll('.question-card').forEach(card => {
        const cb = card.querySelector('input[type="checkbox"]');
        if (cb) card.classList.toggle('selected', cb.checked);
    });
}

document.querySelectorAll('.question-move-checkbox').forEach(cb => {
    cb.addEventListener('change', updateMoveButtonCount);
});
updateMoveButtonCount();

function submitMove() {
    document.querySelectorAll('#moveForm input[name="question_ids[]"]').forEach(el => el.remove());
    document.querySelectorAll('.question-move-checkbox:checked').forEach(cb => {
        const input = document.createElement('input');
        input.type  = 'hidden';
        input.name  = 'question_ids[]';
        input.value = cb.value;
        document.getElementById('moveForm').appendChild(input);
    });
    const hidden = document.createElement('input');
    hidden.type  = 'hidden';
    hidden.name  = 'move_questions';
    hidden.value = '1';
    document.getElementById('moveForm').appendChild(hidden);
    document.getElementById('moveForm').submit();
}

// ══════════════════════════════════════════════════════════
// JS : Onglet IMPORT ÉVALUATION
// ══════════════════════════════════════════════════════════
function switchImportTab(tab) {
    const fileDiv  = document.getElementById('eval-tab-file');
    const pasteDiv = document.getElementById('eval-tab-paste');
    const fileBtn  = document.getElementById('eval-tab-file-btn');
    const pasteBtn = document.getElementById('eval-tab-paste-btn');
    if (!fileDiv || !pasteDiv) return;
    fileDiv.style.display  = tab === 'file'  ? '' : 'none';
    pasteDiv.style.display = tab === 'paste' ? '' : 'none';
    if (fileBtn)  fileBtn.classList.toggle('active',  tab === 'file');
    if (pasteBtn) pasteBtn.classList.toggle('active', tab === 'paste');
    if (tab === 'paste') { const fi = document.getElementById('evalFileInput'); if (fi) fi.value = ''; }
    else { const ta = document.getElementById('evalJsonTextarea'); if (ta) ta.value = ''; }
}

function toggleNouveauModuleEval(val) {
    const box = document.getElementById('evalNouveauModuleBox');
    if (box) box.style.display = val === '0' ? '' : 'none';
}

async function evalPasteFromClipboard() {
    try {
        const text = await navigator.clipboard.readText();
        const ta = document.getElementById('evalJsonTextarea');
        if (ta) ta.value = text;
    } catch(e) {
        alert('Accès presse-papiers refusé — coller manuellement avec Ctrl+V.');
    }
}

// Restaure l'état après erreur
const evalModuleSel = document.getElementById('evalModuleDestSelect');
if (evalModuleSel) toggleNouveauModuleEval(evalModuleSel.value);
</script>
</body>
</html>
