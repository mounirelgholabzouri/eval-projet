<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$msg    = '';
$erreur = '';
$activeTab = 'fusion'; // 'fusion' ou 'efm'

$modules = getAllModules();

// Charger toutes les parties actives avec nb_questions, indexées par module_id
$allParties = [];
$stmtP = $pdo->query("
    SELECT p.id, p.module_id, p.nom, p.ordre,
           COUNT(q.id) AS nb_questions
    FROM parties p
    LEFT JOIN questions q ON q.partie_id = p.id
    WHERE p.actif = 1
    GROUP BY p.id
    ORDER BY p.module_id, p.ordre, p.id
");
foreach ($stmtP->fetchAll() as $p) {
    $allParties[(int)$p['module_id']][] = $p;
}

// Questions actives, indexées par partie_id
$allQuestionsParPartie = [];
$stmtQ = $pdo->query("
    SELECT q.id, q.partie_id, q.texte, q.type, q.points, q.ordre
    FROM questions q
    INNER JOIN parties p ON p.id = q.partie_id
    WHERE p.actif = 1
    ORDER BY q.partie_id, q.ordre, q.id
");
foreach ($stmtQ->fetchAll() as $q) {
    $allQuestionsParPartie[(int)$q['partie_id']][] = $q;
}

// ═══════════════════════════════════════════════════
// POST : Fusion QCM classique
// ═══════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_fusion'])) {
    $activeTab = 'fusion';
    $nom      = trim($_POST['nom'] ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $duree    = max(5, min(300, (int)($_POST['duree_minutes'] ?? 60)));
    $noteMax  = in_array((int)($_POST['note_max'] ?? 20), [20, 40]) ? (int)$_POST['note_max'] : 20;
    $actif    = isset($_POST['actif']) ? 1 : 0;
    $ids      = array_map('intval', (array)($_POST['module_ids'] ?? []));
    $ids      = array_filter($ids, fn($i) => $i > 0);

    if (strlen($nom) < 2) {
        $erreur = "Le nom du module synthèse est requis (minimum 2 caractères).";
    } elseif (count($ids) < 2) {
        $erreur = "Sélectionnez au moins 2 modules à fusionner.";
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO modules (nom, description, actif) VALUES (?,?,?)");
            $stmt->execute([$nom, $desc, $actif]);
            $newModuleId = (int)$pdo->lastInsertId();
            // Évaluation associée
            $pdo->prepare("INSERT INTO evaluations (module_id, nom, type, duree_minutes, note_max, actif) VALUES (?,?,'qcm',?,?,?)")
                ->execute([$newModuleId, $nom, $duree, $noteMax, $actif]);

            $pdo->prepare("INSERT INTO parties (module_id, nom, ordre, actif) VALUES (?, 'Général', 1, 1)")
                ->execute([$newModuleId]);
            $partieId = (int)$pdo->lastInsertId();

            $qOrdre = 1;
            foreach ($ids as $srcModuleId) {
                $qStmt = $pdo->prepare("SELECT * FROM questions WHERE module_id = ? ORDER BY ordre, id");
                $qStmt->execute([$srcModuleId]);
                foreach ($qStmt->fetchAll() as $q) {
                    $insQ = $pdo->prepare(
                        "INSERT INTO questions (module_id, partie_id, texte, type, points, ordre, image_path)
                         VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $insQ->execute([$newModuleId, $partieId, $q['texte'], $q['type'], $q['points'], $qOrdre++, $q['image_path'] ?? null]);
                    $newQId = (int)$pdo->lastInsertId();

                    $cStmt = $pdo->prepare("SELECT * FROM choix_reponses WHERE question_id = ? ORDER BY ordre, id");
                    $cStmt->execute([$q['id']]);
                    foreach ($cStmt->fetchAll() as $c) {
                        $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?,?,?,?)")
                            ->execute([$newQId, $c['texte'], $c['is_correct'], $c['ordre']]);
                    }
                }
            }
            $pdo->commit();
            header("Location: questions.php?module_id={$newModuleId}&msg=fusion_ok"); exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $erreur = "Erreur lors de la fusion : " . $e->getMessage();
        }
    }
}

// ═══════════════════════════════════════════════════
// POST : Création EFM
// ═══════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_efm'])) {
    $activeTab    = 'efm';
    $nom          = trim($_POST['efm_nom'] ?? '');
    $codeModule   = trim($_POST['efm_code'] ?? '');
    $filiere      = trim($_POST['efm_filiere'] ?? '');
    $etablissement= trim($_POST['efm_etablissement'] ?? '');
    $annee        = trim($_POST['efm_annee'] ?? '');
    $duree        = max(5, min(300, (int)($_POST['efm_duree'] ?? 120)));
    $noteMax      = in_array((int)($_POST['efm_note_max'] ?? 20), [20, 40]) ? (int)$_POST['efm_note_max'] : 20;
    $actif        = isset($_POST['efm_actif']) ? 1 : 0;
    $shuffle      = isset($_POST['efm_shuffle']) ? 1 : 0;
    $shuffleChoix = isset($_POST['efm_shuffle_choix']) ? 1 : 0;
    $doImprimer   = isset($_POST['efm_imprimer']); // bouton "Créer et imprimer"

    // Questions sélectionnées individuellement : efm_q_{qId} (checkbox) + efm_pts_{qId} (points)
    $selectedQuestions = []; // [ questionId => points ]
    foreach ($_POST as $k => $v) {
        if (str_starts_with($k, 'efm_q_')) {
            $qid = (int)substr($k, 6);
            if ($qid > 0) {
                $selectedQuestions[$qid] = max(0, (float)str_replace(',', '.', $_POST['efm_pts_' . $qid] ?? 1));
            }
        }
    }

    if (strlen($nom) < 2) {
        $erreur = "Le nom de l'EFM est requis (minimum 2 caractères).";
    } elseif (empty($selectedQuestions)) {
        $erreur = "Sélectionnez au moins une question.";
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO modules (nom, description, actif) VALUES (?, ?, ?)");
            $stmt->execute([$nom, "EFM — $codeModule", $actif]);
            $newModuleId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO parties (module_id, nom, ordre, actif) VALUES (?, 'Général', 1, 1)")
                ->execute([$newModuleId]);
            $partieIdEfm = (int)$pdo->lastInsertId();

            // Évaluation EFM avec métadonnées dans meta_json
            $metaJson = json_encode([
                'code_module'   => $codeModule,
                'filiere'       => $filiere,
                'etablissement' => $etablissement,
                'annee'         => $annee,
            ], JSON_UNESCAPED_UNICODE);
            $pdo->prepare(
                "INSERT INTO evaluations (module_id, nom, type, duree_minutes, note_max, meta_json, actif)
                 VALUES (?, ?, 'efm', ?, ?, ?, ?)"
            )->execute([$newModuleId, $nom, $duree, $noteMax, $metaJson, $actif]);

            // Charger les questions sélectionnées dans leur ordre d'origine
            $qids = array_keys($selectedQuestions);
            $placeholders = implode(',', array_fill(0, count($qids), '?'));
            $qStmt = $pdo->prepare("SELECT * FROM questions WHERE id IN ($placeholders) ORDER BY partie_id, ordre, id");
            $qStmt->execute($qids);
            $srcQuestions = $qStmt->fetchAll();

            if ($shuffle) shuffle($srcQuestions);

            $qOrdre = 1;
            foreach ($srcQuestions as $q) {
                $pts = $selectedQuestions[$q['id']];
                $insQ = $pdo->prepare(
                    "INSERT INTO questions (module_id, partie_id, texte, type, points, ordre, image_path)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $insQ->execute([$newModuleId, $partieIdEfm, $q['texte'], $q['type'], $pts, $qOrdre++, $q['image_path'] ?? null]);
                $newQId = (int)$pdo->lastInsertId();

                $cStmt = $pdo->prepare("SELECT * FROM choix_reponses WHERE question_id = ? ORDER BY ordre, id");
                $cStmt->execute([$q['id']]);
                foreach ($cStmt->fetchAll() as $c) {
                    $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?,?,?,?)")
                        ->execute([$newQId, $c['texte'], $c['is_correct'], $c['ordre']]);
                }
            }

            $pdo->commit();

            if ($doImprimer) {
                // Redirection directe vers l'impression EFM
                $params = http_build_query([
                    'module_id'     => $newModuleId,
                    'etablissement' => $etablissement,
                    'filiere'       => $filiere,
                    'duree'         => $duree . ' min',
                    'annee'         => $annee,
                    'note_max'      => $noteMax,
                    'code_module'   => $codeModule,
                    'intitule'      => $nom,
                    'shuffle'       => 0,
                    'shuffle_choix' => $shuffleChoix ? 1 : 0,
                    'corrige'       => 0,
                ]);
                header("Location: print_efm.php?$params"); exit;
            }

            header("Location: questions.php?module_id={$newModuleId}&msg=efm_ok"); exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $erreur = "Erreur lors de la création EFM : " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fusion / EFM — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container-fluid py-4 px-4">

    <div class="d-flex align-items-center mb-4 gap-3">
        <h2 class="h4 fw-bold mb-0">
            <i class="bi bi-intersect me-2 text-primary"></i>Fusion &amp; EFM
        </h2>
    </div>

    <?php if ($erreur): ?>
        <div class="alert alert-danger rounded-3"><i class="bi bi-exclamation-triangle me-2"></i><?= sanitize($erreur) ?></div>
    <?php endif; ?>
    <?php if ($msg): ?>
        <div class="alert alert-success rounded-3"><i class="bi bi-check-circle me-2"></i><?= sanitize($msg) ?></div>
    <?php endif; ?>

    <!-- ── Onglets ── -->
    <ul class="nav nav-tabs mb-4" id="fusionTabs">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'fusion' ? 'active' : '' ?>" href="#tab-fusion" data-bs-toggle="tab">
                <i class="bi bi-intersect me-1"></i>Fusion QCM
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'efm' ? 'active' : '' ?>" href="#tab-efm" data-bs-toggle="tab">
                <i class="bi bi-file-earmark-ruled me-1"></i>EFM — Examen de Fin de Module
            </a>
        </li>
    </ul>

    <div class="tab-content">

    <!-- ══════════════════════════════════════════════════════════ -->
    <!-- Onglet 1 : Fusion QCM classique                           -->
    <!-- ══════════════════════════════════════════════════════════ -->
    <div class="tab-pane fade <?= $activeTab === 'fusion' ? 'show active' : '' ?>" id="tab-fusion">
        <form method="POST" id="fusionForm">
            <?= csrfField() ?>
            <input type="hidden" name="action_fusion" value="1">
            <div class="row g-4">

                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-header bg-white border-0 py-3 px-4">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-collection me-2 text-primary"></i>Modules à fusionner</h5>
                            <div class="text-muted small mt-1">Sélectionnez au moins 2 modules</div>
                        </div>
                        <div class="card-body p-3">
                            <?php if (empty($modules)): ?>
                                <div class="text-center text-muted py-4"><i class="bi bi-journal-x fs-2 d-block mb-2"></i>Aucun module</div>
                            <?php else: ?>
                            <div class="d-flex gap-2 mb-3">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAll"><i class="bi bi-check2-all me-1"></i>Tout</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnNone"><i class="bi bi-x-lg me-1"></i>Aucun</button>
                            </div>
                            <div class="list-group list-group-flush" id="moduleList">
                                <?php foreach ($modules as $m): ?>
                                <label class="list-group-item list-group-item-action rounded-3 mb-1 border module-item" style="cursor:pointer">
                                    <div class="d-flex align-items-center gap-3">
                                        <input class="form-check-input flex-shrink-0 module-checkbox" type="checkbox"
                                               name="module_ids[]" value="<?= $m['id'] ?>"
                                               data-questions="<?= (int)$m['nb_questions'] ?>"
                                               data-name="<?= sanitize($m['nom']) ?>"
                                               data-duree="<?= (int)$m['duree_minutes'] ?>">
                                        <div class="flex-grow-1">
                                            <div class="fw-semibold"><?= sanitize($m['nom']) ?></div>
                                            <?php if ($m['description']): ?>
                                            <div class="text-muted small"><?= sanitize(mb_substr($m['description'], 0, 60)) ?><?= mb_strlen($m['description']) > 60 ? '…' : '' ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-end flex-shrink-0">
                                            <span class="badge bg-primary-subtle text-primary"><?= (int)$m['nb_questions'] ?> Q</span>
                                            <div class="text-muted small"><?= $m['duree_minutes'] ?> min</div>
                                        </div>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-0 py-3 px-4">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-gear me-2 text-primary"></i>Configuration du module synthèse</h5>
                        </div>
                        <div class="card-body p-4">
                            <div id="selectionSummary" class="alert alert-info rounded-3 d-flex align-items-center gap-3 mb-4">
                                <i class="bi bi-info-circle-fill fs-5 flex-shrink-0"></i>
                                <span id="summaryText">Aucun module sélectionné.</span>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Nom du module synthèse <span class="text-danger">*</span></label>
                                <input type="text" name="nom" id="nomModule" class="form-control"
                                       placeholder="Ex : Révision complète — Modules 1 à 3"
                                       value="<?= sanitize($_POST['nom'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Description</label>
                                <textarea name="description" class="form-control" rows="2"><?= sanitize($_POST['description'] ?? '') ?></textarea>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-sm-6">
                                    <label class="form-label fw-semibold">Durée (minutes)</label>
                                    <input type="number" name="duree_minutes" id="dureeInput" class="form-control" min="5" max="300" value="<?= (int)($_POST['duree_minutes'] ?? 60) ?>">
                                    <div class="form-text" id="dureeHint">—</div>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label fw-semibold">Notation</label>
                                    <div class="d-flex gap-3 mt-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="note_max" value="20" id="nm20" <?= (($_POST['note_max'] ?? '20') == '20') ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="nm20">Sur 20</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="note_max" value="40" id="nm40" <?= (($_POST['note_max'] ?? '') == '40') ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="nm40">Sur 40</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-4 form-check">
                                <input type="checkbox" name="actif" class="form-check-input" id="actif" <?= isset($_POST['actif']) || !isset($_POST['nom']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="actif">Module actif (visible aux stagiaires)</label>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary" id="btnSubmit" disabled>
                                    <i class="bi bi-intersect me-2"></i>Créer le module synthèse
                                </button>
                                <a href="modules.php" class="btn btn-outline-secondary">Annuler</a>
                                <span class="text-muted small ms-2" id="btnHint">Sélectionnez au moins 2 modules</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div><!-- /tab-fusion -->

    <!-- ══════════════════════════════════════════════════════════ -->
    <!-- Onglet 2 : EFM                                            -->
    <!-- ══════════════════════════════════════════════════════════ -->
    <div class="tab-pane fade <?= $activeTab === 'efm' ? 'show active' : '' ?>" id="tab-efm">
        <form method="POST" id="efmForm">
            <?= csrfField() ?>
            <input type="hidden" name="action_efm" value="1">
            <div class="row g-4">

                <!-- Colonne gauche : sélection questions par module/partie -->
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-0 py-3 px-4">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-list-check me-2 text-warning"></i>Sélection des questions</h5>
                            <div class="text-muted small mt-1">Cochez les questions à inclure et ajustez leur pondération</div>
                        </div>
                        <div class="card-body p-3">
                            <div class="mb-3">
                                <label class="form-label fw-semibold small mb-1">
                                    <i class="bi bi-funnel me-1 text-warning"></i>Filtrer par code module
                                </label>
                                <input type="text" id="efmCodeFilter" class="form-control form-control-sm"
                                       placeholder="Ex : M205 — affiche tous les modules commençant par ce code"
                                       autocomplete="off">
                                <div class="form-text" id="efmFilterHint"></div>
                            </div>
                            <div class="d-flex gap-2 mb-3 flex-wrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="efmExpandAll">
                                    <i class="bi bi-arrows-expand me-1"></i>Tout développer
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="efmCollapseAll">
                                    <i class="bi bi-arrows-collapse me-1"></i>Tout réduire
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-success" id="efmSelectFiltered" style="display:none">
                                    <i class="bi bi-check2-all me-1"></i>Tout sélectionner
                                </button>
                            </div>

                            <?php foreach ($modules as $m): ?>
                            <?php $parties = $allParties[(int)$m['id']] ?? []; if (empty($parties)) continue; ?>
                            <?php
                                // Vérifier qu'au moins une partie a des questions
                                $hasQuestions = false;
                                foreach ($parties as $p) {
                                    if (!empty($allQuestionsParPartie[(int)$p['id']])) { $hasQuestions = true; break; }
                                }
                                if (!$hasQuestions) continue;
                            ?>
                            <div class="efm-module-block mb-2 border rounded-3 overflow-hidden"
                                 data-module-name="<?= strtolower(sanitize($m['nom'])) ?>">
                                <!-- En-tête module -->
                                <div class="d-flex align-items-center gap-2 px-3 py-2 bg-light border-bottom efm-module-header"
                                     style="cursor:pointer" data-module="<?= $m['id'] ?>">
                                    <input class="form-check-input efm-module-check flex-shrink-0"
                                           type="checkbox" data-module="<?= $m['id'] ?>"
                                           id="efmm<?= $m['id'] ?>" onclick="event.stopPropagation()">
                                    <label class="fw-semibold mb-0 flex-grow-1" for="efmm<?= $m['id'] ?>"
                                           onclick="event.stopPropagation()" style="cursor:pointer">
                                        <?= sanitize($m['nom']) ?>
                                        <span class="badge bg-secondary-subtle text-secondary ms-1">
                                            <?= (int)$m['nb_questions'] ?> Q
                                        </span>
                                        <span class="badge bg-success-subtle text-success ms-1 efm-selected-count"
                                              data-module="<?= $m['id'] ?>" style="display:none"></span>
                                    </label>
                                    <i class="bi bi-chevron-down efm-chevron text-muted flex-shrink-0"></i>
                                </div>

                                <!-- Parties + questions (masquées par défaut) -->
                                <div class="efm-parties-list" data-module="<?= $m['id'] ?>" style="display:none">
                                    <?php foreach ($parties as $p): ?>
                                    <?php $questions = $allQuestionsParPartie[(int)$p['id']] ?? []; if (empty($questions)) continue; ?>
                                    <div class="efm-partie-block">
                                        <!-- En-tête partie -->
                                        <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom efm-partie-header"
                                             style="cursor:pointer;background:#f8f9fa" data-partie="<?= $p['id'] ?>">
                                            <input class="form-check-input efm-partie-check flex-shrink-0"
                                                   type="checkbox" data-partie="<?= $p['id'] ?>"
                                                   data-module="<?= $m['id'] ?>"
                                                   id="efmp<?= $p['id'] ?>" onclick="event.stopPropagation()">
                                            <label class="mb-0 flex-grow-1 fw-semibold" for="efmp<?= $p['id'] ?>"
                                                   onclick="event.stopPropagation()" style="cursor:pointer">
                                                <?= sanitize($p['nom']) ?>
                                                <span class="badge bg-secondary-subtle text-secondary ms-1">
                                                    <?= count($questions) ?> q
                                                </span>
                                                <span class="badge bg-success-subtle text-success ms-1 efm-partie-badge"
                                                      style="display:none"></span>
                                            </label>
                                            <i class="bi bi-chevron-right efm-partie-chevron text-muted flex-shrink-0"></i>
                                        </div>
                                        <!-- Liste des questions (masquée par défaut) -->
                                        <div class="efm-questions-list" data-partie="<?= $p['id'] ?>" style="display:none">
                                            <div class="d-flex align-items-center gap-2 px-3 py-1 border-bottom bg-white">
                                                <span class="text-muted small flex-grow-1 fst-italic">Énoncé</span>
                                                <span class="text-muted small flex-shrink-0" style="width:80px;text-align:center">Pts</span>
                                            </div>
                                            <?php foreach ($questions as $q): ?>
                                            <?php $texte = mb_substr(strip_tags($q['texte']), 0, 120); ?>
                                            <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom efm-question-row">
                                                <input class="form-check-input efm-q-check flex-shrink-0"
                                                       type="checkbox"
                                                       name="efm_q_<?= $q['id'] ?>"
                                                       value="1"
                                                       data-partie="<?= $p['id'] ?>"
                                                       data-module="<?= $m['id'] ?>">
                                                <span class="flex-grow-1 small"
                                                      title="<?= sanitize($q['texte']) ?>"><?= sanitize($texte) ?><?= mb_strlen(strip_tags($q['texte'])) > 120 ? '…' : '' ?></span>
                                                <div class="flex-shrink-0" style="width:80px">
                                                    <input type="number"
                                                           name="efm_pts_<?= $q['id'] ?>"
                                                           class="form-control form-control-sm efm-pts-input text-center"
                                                           value="<?= number_format((float)$q['points'], 2, '.', '') ?>"
                                                           min="0" step="0.25"
                                                           title="Pondération de cette question">
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>

                            <!-- Résumé dynamique -->
                            <div class="alert alert-info rounded-3 py-2 mt-2 mb-0" id="efmSummary">
                                <i class="bi bi-info-circle me-1"></i>
                                <span id="efmSummaryText">Sélectionnez des modules et des questions.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Colonne droite : métadonnées EFM -->
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-0 py-3 px-4">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-file-earmark-ruled me-2 text-danger"></i>Informations de l'EFM</h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Nom de l'EFM <span class="text-danger">*</span></label>
                                <input type="text" name="efm_nom" class="form-control" required
                                       placeholder="Ex : EFM M205 — Sécurité Cloud"
                                       value="<?= sanitize($_POST['efm_nom'] ?? '') ?>">
                            </div>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label class="form-label fw-semibold">Code module</label>
                                    <input type="text" name="efm_code" class="form-control"
                                           placeholder="Ex : M205"
                                           value="<?= sanitize($_POST['efm_code'] ?? '') ?>">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label fw-semibold">Filière</label>
                                    <input type="text" name="efm_filiere" class="form-control"
                                           placeholder="Ex : IDOCC"
                                           value="<?= sanitize($_POST['efm_filiere'] ?? '') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Établissement</label>
                                    <input type="text" name="efm_etablissement" class="form-control"
                                           placeholder="Ex : ISTA NTIC Rabat"
                                           value="<?= sanitize($_POST['efm_etablissement'] ?? '') ?>">
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label fw-semibold">Durée (min)</label>
                                    <input type="number" name="efm_duree" class="form-control"
                                           min="5" max="300"
                                           value="<?= (int)($_POST['efm_duree'] ?? 120) ?>">
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label fw-semibold">Année scolaire</label>
                                    <input type="text" name="efm_annee" class="form-control"
                                           placeholder="25/26"
                                           value="<?= sanitize($_POST['efm_annee'] ?? (date('y') . '/' . (date('y')+1))) ?>">
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label fw-semibold">Note max</label>
                                    <div class="d-flex gap-3 mt-1">
                                        <?php foreach ([20, 40] as $n): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="efm_note_max"
                                                   value="<?= $n ?>" id="efmnm<?= $n ?>"
                                                   <?= (($_POST['efm_note_max'] ?? 40) == $n) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="efmnm<?= $n ?>">/ <?= $n ?></label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <hr class="my-3">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="efm_shuffle" id="efmShuffle" value="1" checked>
                                <label class="form-check-label" for="efmShuffle">Mélanger les questions (ordre aléatoire)</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="efm_shuffle_choix" id="efmShuffleChoix" value="1" checked>
                                <label class="form-check-label" for="efmShuffleChoix">Mélanger les choix de réponse</label>
                            </div>
                            <div class="form-check form-switch mb-4">
                                <input class="form-check-input" type="checkbox" name="efm_actif" id="efmActif" value="1" checked>
                                <label class="form-check-label" for="efmActif">Module actif (visible aux stagiaires)</label>
                            </div>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <button type="submit" class="btn btn-danger" id="btnEfm" disabled>
                                    <i class="bi bi-file-earmark-ruled me-2"></i>Créer l'EFM
                                </button>
                                <button type="submit" name="efm_imprimer" value="1" class="btn btn-warning" id="btnEfmPrint" disabled>
                                    <i class="bi bi-printer me-2"></i>Créer et imprimer
                                </button>
                                <span class="text-muted small" id="btnEfmHint">Sélectionnez au moins une partie</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </form>
    </div><!-- /tab-efm -->

    </div><!-- /tab-content -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── TAB : activer le bon onglet au chargement ──────────────────
(function(){
    const active = '<?= $activeTab ?>';
    if (active === 'efm') {
        const tab = document.querySelector('[href="#tab-efm"]');
        if (tab) new bootstrap.Tab(tab).show();
    }
})();

// ══════════════════════════════════════════════
// Onglet Fusion QCM
// ══════════════════════════════════════════════
const checkboxes  = document.querySelectorAll('.module-checkbox');
const btnSubmit   = document.getElementById('btnSubmit');
const btnHint     = document.getElementById('btnHint');
const summaryText = document.getElementById('summaryText');
const summaryBox  = document.getElementById('selectionSummary');
const dureeInput  = document.getElementById('dureeInput');
const dureeHint   = document.getElementById('dureeHint');
const nomInput    = document.getElementById('nomModule');

function updateFusionUI() {
    const sel    = [...checkboxes].filter(c => c.checked);
    const totalQ = sel.reduce((s, c) => s + parseInt(c.dataset.questions), 0);
    const totalD = sel.reduce((s, c) => s + parseInt(c.dataset.duree || 0), 0);

    if (sel.length >= 2) {
        btnSubmit.disabled = false;
        btnHint.textContent = '';
    } else {
        btnSubmit.disabled = true;
        btnHint.textContent = sel.length === 1 ? 'Sélectionnez encore 1 module' : 'Sélectionnez au moins 2 modules';
    }

    if (sel.length === 0) {
        summaryText.innerHTML = 'Aucun module sélectionné.';
        summaryBox.className = 'alert alert-info rounded-3 d-flex align-items-center gap-3 mb-4';
    } else {
        const names = sel.map(c => `<strong>${escHtml(c.dataset.name)}</strong>`).join(', ');
        summaryText.innerHTML = `${sel.length} module(s) : ${names} — <strong>${totalQ} questions</strong>`;
        summaryBox.className = 'alert alert-success rounded-3 d-flex align-items-center gap-3 mb-4';
    }

    if (sel.length > 0) {
        dureeHint.textContent = `Durée combinée estimée : ${totalD} min`;
        if (!dureeInput._userEdited) dureeInput.value = totalD;
    } else {
        dureeHint.textContent = '—';
    }
}

dureeInput?.addEventListener('input', () => { dureeInput._userEdited = true; });
checkboxes.forEach(cb => {
    cb.addEventListener('change', () => {
        cb.closest('.module-item').classList.toggle('border-primary', cb.checked);
        cb.closest('.module-item').classList.toggle('bg-primary-subtle', cb.checked);
        updateFusionUI();
    });
});
document.getElementById('btnAll')?.addEventListener('click', () => {
    checkboxes.forEach(c => { c.checked = true; c.closest('.module-item').classList.add('border-primary','bg-primary-subtle'); });
    updateFusionUI();
});
document.getElementById('btnNone')?.addEventListener('click', () => {
    checkboxes.forEach(c => { c.checked = false; c.closest('.module-item').classList.remove('border-primary','bg-primary-subtle'); });
    updateFusionUI();
});
updateFusionUI();

// ══════════════════════════════════════════════
// Onglet EFM
// ══════════════════════════════════════════════
const btnEfm         = document.getElementById('btnEfm');
const btnEfmPrint    = document.getElementById('btnEfmPrint');
const btnEfmHint     = document.getElementById('btnEfmHint');
const efmSummaryText = document.getElementById('efmSummaryText');

function updateEfmUI() {
    let totalQ = 0, totalPts = 0;

    document.querySelectorAll('.efm-q-check:checked').forEach(cb => {
        totalQ++;
        const qName  = cb.name.replace('efm_q_', 'efm_pts_');
        const ptsInp = document.querySelector(`input[name="${qName}"]`);
        totalPts += ptsInp ? (parseFloat(ptsInp.value) || 0) : 0;
    });

    // Badges et états indéterminés des parties
    document.querySelectorAll('.efm-partie-block').forEach(block => {
        const allQ  = block.querySelectorAll('.efm-q-check');
        const selQ  = block.querySelectorAll('.efm-q-check:checked');
        const badge = block.querySelector('.efm-partie-badge');
        const pc    = block.querySelector('.efm-partie-check');
        if (badge) {
            badge.style.display = selQ.length > 0 ? 'inline-flex' : 'none';
            badge.textContent = selQ.length + '/' + allQ.length;
        }
        if (pc && allQ.length > 0) {
            pc.indeterminate = selQ.length > 0 && selQ.length < allQ.length;
            if (!pc.indeterminate) pc.checked = selQ.length === allQ.length;
        }
    });

    // Badges et états indéterminés des modules
    document.querySelectorAll('.efm-module-block').forEach(block => {
        const allQ    = block.querySelectorAll('.efm-q-check');
        const selQ    = block.querySelectorAll('.efm-q-check:checked');
        const badge   = block.querySelector('.efm-selected-count');
        const modCheck = block.querySelector('.efm-module-check');
        if (badge) {
            badge.style.display = selQ.length > 0 ? 'inline-flex' : 'none';
            badge.textContent = selQ.length + ' Q';
        }
        if (modCheck && allQ.length > 0) {
            modCheck.indeterminate = selQ.length > 0 && selQ.length < allQ.length;
            if (!modCheck.indeterminate) modCheck.checked = selQ.length === allQ.length;
        }
    });

    if (totalQ > 0) {
        btnEfm.disabled = false;
        if (btnEfmPrint) btnEfmPrint.disabled = false;
        btnEfmHint.textContent = '';
        efmSummaryText.innerHTML = `<strong>${totalQ}</strong> question(s) sélectionnée(s) — Total : <strong>${totalPts.toFixed(2)} pts</strong>`;
        document.getElementById('efmSummary').className = 'alert alert-success rounded-3 py-2 mt-2 mb-0';
    } else {
        btnEfm.disabled = true;
        if (btnEfmPrint) btnEfmPrint.disabled = true;
        btnEfmHint.textContent = 'Sélectionnez au moins une question';
        efmSummaryText.textContent = 'Sélectionnez des modules et des questions.';
        document.getElementById('efmSummary').className = 'alert alert-info rounded-3 py-2 mt-2 mb-0';
    }
}

// Clic en-tête module → expand/collapse parties
document.querySelectorAll('.efm-module-header').forEach(header => {
    header.addEventListener('click', () => {
        const mid     = header.dataset.module;
        const list    = document.querySelector(`.efm-parties-list[data-module="${mid}"]`);
        const chevron = header.querySelector('.efm-chevron');
        if (!list) return;
        const visible = list.style.display !== 'none';
        list.style.display = visible ? 'none' : 'block';
        if (chevron) {
            chevron.classList.toggle('bi-chevron-down', visible);
            chevron.classList.toggle('bi-chevron-up', !visible);
        }
    });
});

// Case module → tout sélectionner/désélectionner les questions + auto-expand
document.querySelectorAll('.efm-module-check').forEach(cb => {
    cb.addEventListener('change', () => {
        const mid     = cb.dataset.module;
        const list    = document.querySelector(`.efm-parties-list[data-module="${mid}"]`);
        const chevron = document.querySelector(`.efm-module-header[data-module="${mid}"] .efm-chevron`);
        if (!list) return;
        list.querySelectorAll('.efm-q-check').forEach(qc => { qc.checked = cb.checked; });
        if (cb.checked && list.style.display === 'none') {
            list.style.display = 'block';
            // Auto-expand toutes les parties
            list.querySelectorAll('.efm-questions-list').forEach(ql => { ql.style.display = 'block'; });
            list.querySelectorAll('.efm-partie-chevron').forEach(c => {
                c.classList.replace('bi-chevron-right', 'bi-chevron-down');
            });
            if (chevron) chevron.classList.replace('bi-chevron-down', 'bi-chevron-up');
        }
        updateEfmUI();
    });
});

// Clic en-tête partie → expand/collapse questions
document.querySelectorAll('.efm-partie-header').forEach(header => {
    header.addEventListener('click', () => {
        const pid     = header.dataset.partie;
        const list    = document.querySelector(`.efm-questions-list[data-partie="${pid}"]`);
        const chevron = header.querySelector('.efm-partie-chevron');
        if (!list) return;
        const visible = list.style.display !== 'none';
        list.style.display = visible ? 'none' : 'block';
        if (chevron) {
            chevron.classList.toggle('bi-chevron-right', visible);
            chevron.classList.toggle('bi-chevron-down', !visible);
        }
    });
});

// Case partie → tout sélectionner/désélectionner les questions de la partie + auto-expand
document.querySelectorAll('.efm-partie-check').forEach(cb => {
    cb.addEventListener('change', () => {
        const pid     = cb.dataset.partie;
        const list    = document.querySelector(`.efm-questions-list[data-partie="${pid}"]`);
        const chevron = document.querySelector(`.efm-partie-header[data-partie="${pid}"] .efm-partie-chevron`);
        if (!list) return;
        list.querySelectorAll('.efm-q-check').forEach(qc => { qc.checked = cb.checked; });
        if (cb.checked && list.style.display === 'none') {
            list.style.display = 'block';
            if (chevron) chevron.classList.replace('bi-chevron-right', 'bi-chevron-down');
        }
        updateEfmUI();
    });
});

// Tout développer
document.getElementById('efmExpandAll')?.addEventListener('click', () => {
    document.querySelectorAll('.efm-parties-list, .efm-questions-list').forEach(l => { l.style.display = 'block'; });
    document.querySelectorAll('.efm-chevron').forEach(c => { c.classList.replace('bi-chevron-down', 'bi-chevron-up'); });
    document.querySelectorAll('.efm-partie-chevron').forEach(c => { c.classList.replace('bi-chevron-right', 'bi-chevron-down'); });
});

// Tout réduire
document.getElementById('efmCollapseAll')?.addEventListener('click', () => {
    document.querySelectorAll('.efm-parties-list, .efm-questions-list').forEach(l => { l.style.display = 'none'; });
    document.querySelectorAll('.efm-chevron').forEach(c => { c.classList.replace('bi-chevron-up', 'bi-chevron-down'); });
    document.querySelectorAll('.efm-partie-chevron').forEach(c => { c.classList.replace('bi-chevron-down', 'bi-chevron-right'); });
});

// Recalcul si on coche/décoche une question ou change ses points
document.querySelectorAll('.efm-q-check, .efm-pts-input').forEach(el => {
    el.addEventListener('change', updateEfmUI);
    el.addEventListener('input', updateEfmUI);
});

// ── Filtrage par code module ──────────────────────────────────
function applyEfmCodeFilter(code) {
    const codeNorm = code.trim().toLowerCase();
    const blocks   = document.querySelectorAll('.efm-module-block');
    const btnSel   = document.getElementById('efmSelectFiltered');
    const hint     = document.getElementById('efmFilterHint');
    let visible = 0;

    blocks.forEach(block => {
        const match = !codeNorm || (block.dataset.moduleName || '').startsWith(codeNorm);
        block.style.display = match ? '' : 'none';
        if (match) visible++;
    });

    if (codeNorm) {
        hint.textContent = visible + ' module(s) correspondant à « ' + code.trim() + ' »';
        btnSel.style.display = visible > 0 ? 'inline-block' : 'none';
    } else {
        hint.textContent = '';
        btnSel.style.display = 'none';
    }
    updateEfmUI();
}

// Synchronisation bidirectionnelle : filtre ↔ champ efm_code
const efmCodeInput  = document.querySelector('input[name="efm_code"]');
const efmCodeFilter = document.getElementById('efmCodeFilter');

if (efmCodeFilter) {
    efmCodeFilter.addEventListener('input', () => {
        if (efmCodeInput) efmCodeInput.value = efmCodeFilter.value;
        applyEfmCodeFilter(efmCodeFilter.value);
    });
}
if (efmCodeInput) {
    efmCodeInput.addEventListener('input', () => {
        if (efmCodeFilter) efmCodeFilter.value = efmCodeInput.value;
        applyEfmCodeFilter(efmCodeInput.value);
    });
    if (efmCodeInput.value) {
        if (efmCodeFilter) efmCodeFilter.value = efmCodeInput.value;
        applyEfmCodeFilter(efmCodeInput.value);
    }
}

// Sélectionner toutes les questions des modules visibles
document.getElementById('efmSelectFiltered')?.addEventListener('click', () => {
    document.querySelectorAll('.efm-module-block').forEach(block => {
        if (block.style.display === 'none') return;
        const cb  = block.querySelector('.efm-module-check');
        const mid = cb?.dataset.module;
        if (!cb || !mid) return;
        cb.checked = true;
        const list = document.querySelector(`.efm-parties-list[data-module="${mid}"]`);
        const chev = document.querySelector(`.efm-module-header[data-module="${mid}"] .efm-chevron`);
        if (list) {
            list.querySelectorAll('.efm-q-check').forEach(qc => { qc.checked = true; });
            list.querySelectorAll('.efm-questions-list').forEach(ql => { ql.style.display = 'block'; });
            list.querySelectorAll('.efm-partie-chevron').forEach(c => { c.classList.replace('bi-chevron-right','bi-chevron-down'); });
            list.style.display = 'block';
            if (chev) chev.classList.replace('bi-chevron-down','bi-chevron-up');
        }
    });
    updateEfmUI();
});

updateEfmUI();

function escHtml(str) {
    return str.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
</script>
</body>
</html>
