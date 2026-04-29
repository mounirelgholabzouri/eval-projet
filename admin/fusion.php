<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$msg    = '';
$erreur = '';
$activeTab = 'fusion'; // 'fusion' ou 'efm'

$modules = getAllModules();

// ── Précharger toutes les parties de tous les modules (pour JS) ──
$allParties = [];
$stmtP = $pdo->query("
    SELECT p.*, COUNT(q.id) AS nb_questions
    FROM parties p
    LEFT JOIN questions q ON q.partie_id = p.id
    GROUP BY p.id
    ORDER BY p.module_id, p.ordre, p.id
");
foreach ($stmtP->fetchAll() as $p) {
    $allParties[(int)$p['module_id']][] = $p;
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
            $stmt = $pdo->prepare("INSERT INTO modules (nom, description, duree_minutes, note_max, actif, type) VALUES (?,?,?,?,?,'qcm')");
            $stmt->execute([$nom, $desc, $duree, $noteMax, $actif]);
            $newModuleId = (int)$pdo->lastInsertId();

            $partieOrdre = 1;
            $qOrdre = 1;
            foreach ($ids as $srcModuleId) {
                $mCheck = $pdo->prepare("SELECT id, nom FROM modules WHERE id = ?");
                $mCheck->execute([$srcModuleId]);
                $srcModule = $mCheck->fetch();
                if (!$srcModule) continue;

                $newPartieId = creerPartie($newModuleId, $srcModule['nom'], $partieOrdre++);

                $qStmt = $pdo->prepare("SELECT * FROM questions WHERE module_id = ? ORDER BY partie_id, ordre, id");
                $qStmt->execute([$srcModuleId]);
                foreach ($qStmt->fetchAll() as $q) {
                    $insQ = $pdo->prepare(
                        "INSERT INTO questions (module_id, partie_id, texte, type, points, ordre, image_path)
                         VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $insQ->execute([$newModuleId, $newPartieId, $q['texte'], $q['type'], $q['points'], $qOrdre++, $q['image_path'] ?? null]);
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

    // Partie IDs sélectionnées : POST keys efm_p_{partieId}
    $selectedParties = [];
    foreach ($_POST as $k => $v) {
        if (str_starts_with($k, 'efm_p_')) {
            $pid   = (int)substr($k, 6);
            $nbQ   = max(0, (int)($_POST["efm_nb_{$pid}"] ?? 0));
            $selectedParties[$pid] = $nbQ;
        }
    }

    if (strlen($nom) < 2) {
        $erreur = "Le nom de l'EFM est requis (minimum 2 caractères).";
    } elseif (empty($selectedParties)) {
        $erreur = "Sélectionnez au moins une partie.";
    } else {
        try {
            $pdo->beginTransaction();

            $meta = json_encode([
                'code_module'   => $codeModule,
                'filiere'       => $filiere,
                'etablissement' => $etablissement,
                'annee'         => $annee,
            ], JSON_UNESCAPED_UNICODE);

            $stmt = $pdo->prepare(
                "INSERT INTO modules (nom, description, duree_minutes, note_max, actif, type, meta_json)
                 VALUES (?, ?, ?, ?, ?, 'efm', ?)"
            );
            $stmt->execute([$nom, "EFM — $codeModule", $duree, $noteMax, $actif, $meta]);
            $newModuleId = (int)$pdo->lastInsertId();

            $qOrdre = 1;
            foreach ($selectedParties as $srcPartieId => $nbDemande) {
                // Récupérer la partie source
                $pStmt = $pdo->prepare("SELECT p.*, m.nom AS module_nom FROM parties p JOIN modules m ON m.id = p.module_id WHERE p.id = ?");
                $pStmt->execute([$srcPartieId]);
                $srcPartie = $pStmt->fetch();
                if (!$srcPartie) continue;

                // Créer une partie correspondante dans le nouveau module EFM
                $newPartieId = creerPartie($newModuleId, $srcPartie['nom'], 0);

                // Charger les questions de la partie source
                $qStmt = $pdo->prepare("SELECT * FROM questions WHERE partie_id = ? ORDER BY ordre, id");
                $qStmt->execute([$srcPartieId]);
                $questions = $qStmt->fetchAll();

                if ($shuffle) shuffle($questions);
                if ($nbDemande > 0) $questions = array_slice($questions, 0, $nbDemande);

                foreach ($questions as $q) {
                    $insQ = $pdo->prepare(
                        "INSERT INTO questions (module_id, partie_id, texte, type, points, ordre, image_path)
                         VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $insQ->execute([$newModuleId, $newPartieId, $q['texte'], $q['type'], $q['points'], $qOrdre++, $q['image_path'] ?? null]);
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
            <input type="hidden" name="action_efm" value="1">
            <div class="row g-4">

                <!-- Colonne gauche : sélection modules + parties -->
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-0 py-3 px-4">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-layers me-2 text-warning"></i>Sélection des parties</h5>
                            <div class="text-muted small mt-1">Cochez les modules puis sélectionnez les parties à inclure</div>
                        </div>
                        <div class="card-body p-3">
                            <?php foreach ($modules as $m): ?>
                            <?php $parties = $allParties[(int)$m['id']] ?? []; if (empty($parties)) continue; ?>
                            <div class="efm-module-block mb-3 border rounded-3 overflow-hidden">
                                <!-- En-tête module -->
                                <div class="d-flex align-items-center gap-2 px-3 py-2 bg-light border-bottom">
                                    <input class="form-check-input efm-module-check flex-shrink-0"
                                           type="checkbox"
                                           data-module="<?= $m['id'] ?>"
                                           id="efmm<?= $m['id'] ?>">
                                    <label class="fw-semibold mb-0 flex-grow-1" for="efmm<?= $m['id'] ?>">
                                        <?= sanitize($m['nom']) ?>
                                        <span class="badge bg-secondary-subtle text-secondary ms-1"><?= count($parties) ?> partie<?= count($parties) > 1 ? 's' : '' ?></span>
                                    </label>
                                </div>
                                <!-- Parties du module (masquées par défaut) -->
                                <div class="efm-parties-list" data-module="<?= $m['id'] ?>" style="display:none">
                                    <?php foreach ($parties as $p): ?>
                                    <div class="px-3 py-2 border-bottom d-flex align-items-center gap-3">
                                        <input class="form-check-input efm-partie-check flex-shrink-0"
                                               type="checkbox"
                                               name="efm_p_<?= $p['id'] ?>"
                                               value="1"
                                               id="efmp<?= $p['id'] ?>"
                                               data-total="<?= (int)$p['nb_questions'] ?>"
                                               checked>
                                        <label class="mb-0 flex-grow-1" for="efmp<?= $p['id'] ?>">
                                            <?= sanitize($p['nom']) ?>
                                            <span class="text-muted small">(<?= $p['nb_questions'] ?> q)</span>
                                        </label>
                                        <div style="width:110px">
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text" title="0 = toutes">Q</span>
                                                <input type="number" class="form-control efm-nb-input"
                                                       name="efm_nb_<?= $p['id'] ?>"
                                                       value="0" min="0" max="<?= $p['nb_questions'] ?>"
                                                       title="0 = toutes les questions">
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>

                            <!-- Résumé dynamique -->
                            <div class="alert alert-info rounded-3 py-2 mt-2 mb-0" id="efmSummary">
                                <i class="bi bi-info-circle me-1"></i>
                                <span id="efmSummaryText">Sélectionnez des modules et des parties.</span>
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
                            <div class="form-check form-switch mb-4">
                                <input class="form-check-input" type="checkbox" name="efm_actif" id="efmActif" value="1" checked>
                                <label class="form-check-label" for="efmActif">Module actif (visible aux stagiaires)</label>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-danger" id="btnEfm" disabled>
                                    <i class="bi bi-file-earmark-ruled me-2"></i>Créer l'EFM
                                </button>
                                <span class="text-muted small align-self-center" id="btnEfmHint">Sélectionnez au moins une partie</span>
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
const efmModuleChecks = document.querySelectorAll('.efm-module-check');
const btnEfm          = document.getElementById('btnEfm');
const btnEfmHint      = document.getElementById('btnEfmHint');
const efmSummaryText  = document.getElementById('efmSummaryText');

function updateEfmUI() {
    let totalParties = 0, totalQ = 0;
    document.querySelectorAll('.efm-partie-check:checked').forEach(cb => {
        const total   = parseInt(cb.dataset.total);
        const nbInput = cb.closest('[data-total]')?.parentElement?.querySelector('.efm-nb-input');
        const nb      = nbInput ? (parseInt(nbInput.value) || 0) : 0;
        totalParties++;
        totalQ += (nb === 0) ? total : Math.min(nb, total);
    });

    if (totalParties > 0) {
        btnEfm.disabled = false;
        btnEfmHint.textContent = '';
        efmSummaryText.textContent = `${totalParties} partie(s) sélectionnée(s) — ${totalQ} question(s) dans l'EFM.`;
        document.getElementById('efmSummary').className = 'alert alert-success rounded-3 py-2 mt-2 mb-0';
    } else {
        btnEfm.disabled = true;
        btnEfmHint.textContent = 'Sélectionnez au moins une partie';
        efmSummaryText.textContent = 'Sélectionnez des modules et des parties.';
        document.getElementById('efmSummary').className = 'alert alert-info rounded-3 py-2 mt-2 mb-0';
    }
}

// Afficher/masquer les parties quand on coche un module
efmModuleChecks.forEach(cb => {
    cb.addEventListener('change', () => {
        const mid   = cb.dataset.module;
        const list  = document.querySelector(`.efm-parties-list[data-module="${mid}"]`);
        if (list) {
            list.style.display = cb.checked ? 'block' : 'none';
            // (dé)cocher toutes les parties du module
            list.querySelectorAll('.efm-partie-check').forEach(pc => {
                pc.checked = cb.checked;
                pc.disabled = !cb.checked;
            });
        }
        updateEfmUI();
    });
});

// Recalcul si on change nb_questions ou partie_check
document.querySelectorAll('.efm-nb-input, .efm-partie-check').forEach(el => {
    el.addEventListener('change', updateEfmUI);
    el.addEventListener('input', updateEfmUI);
});

updateEfmUI();

function escHtml(str) {
    return str.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
</script>
</body>
</html>
