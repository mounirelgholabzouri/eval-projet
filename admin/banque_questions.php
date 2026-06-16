<?php
/**
 * banque_questions.php — Banque de questions par module
 * Consolide les questions de plusieurs modules en un module-banque sans doublons.
 * Permet ensuite d'importer depuis la banque vers un nouveau module.
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$msg    = '';
$erreur = '';
$stats  = [];
$action = $_POST['action'] ?? '';

// ── Action 1 : Consolider → créer/MAJ la banque ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'consolider') {
    verifyCsrfToken();

    $sourceIds  = array_map('intval', $_POST['source_ids'] ?? []);
    $banqueNom  = trim($_POST['banque_nom'] ?? '');

    if (empty($sourceIds)) {
        $erreur = "Sélectionnez au moins un module source.";
    } elseif (strlen($banqueNom) < 2) {
        $erreur = "Le nom de la banque est requis.";
    } else {
        $pdo->beginTransaction();
        try {
            // Trouver ou créer le module-banque
            $stmtFind = $pdo->prepare("SELECT id FROM modules WHERE nom = ?");
            $stmtFind->execute([$banqueNom]);
            $banqueId = $stmtFind->fetchColumn();
            if (!$banqueId) {
                $pdo->prepare("INSERT INTO modules (nom, description, actif) VALUES (?, '[BANQUE]', 1)")
                    ->execute([$banqueNom]);
                $banqueId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO evaluations (module_id, nom, type, duree_minutes, note_max) VALUES (?, ?, 'qcm', 60, 20)")
                    ->execute([$banqueId, $banqueNom]);
            }

            // Index des textes déjà dans la banque (dédup par MD5)
            $existants = $pdo->prepare("SELECT MD5(LOWER(TRIM(texte))) AS h FROM questions WHERE module_id = ?");
            $existants->execute([$banqueId]);
            $hashsBanque = array_flip($existants->fetchAll(PDO::FETCH_COLUMN));

            $nbImporte = 0;
            $nbSkip    = 0;

            $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
            $stmtQ = $pdo->prepare("
                SELECT q.*, p.nom AS partie_nom, p.ordre AS partie_ordre
                FROM questions q
                JOIN parties p ON p.id = q.partie_id
                WHERE q.module_id IN ($placeholders)
                ORDER BY p.ordre, q.ordre, q.id
            ");
            $stmtQ->execute($sourceIds);
            $questions = $stmtQ->fetchAll();

            foreach ($questions as $q) {
                $hash = md5(strtolower(trim($q['texte'])));
                if (isset($hashsBanque[$hash])) {
                    $nbSkip++;
                    continue;
                }
                $hashsBanque[$hash] = true;

                // Trouver ou créer la partie dans la banque
                $stmtP = $pdo->prepare("SELECT id FROM parties WHERE module_id = ? AND nom = ?");
                $stmtP->execute([$banqueId, $q['partie_nom']]);
                $partieId = $stmtP->fetchColumn();
                if (!$partieId) {
                    $pdo->prepare("INSERT INTO parties (module_id, nom, ordre, actif) VALUES (?, ?, ?, 1)")
                        ->execute([$banqueId, $q['partie_nom'], (int)$q['partie_ordre']]);
                    $partieId = (int)$pdo->lastInsertId();
                }

                // Copier la question
                $pdo->prepare("INSERT INTO questions (module_id, partie_id, texte, type, points, ordre, image_path)
                               VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$banqueId, $partieId, $q['texte'], $q['type'], $q['points'], $q['ordre'], $q['image_path']]);
                $newQid = (int)$pdo->lastInsertId();

                // Copier les choix
                $stmtC = $pdo->prepare("SELECT texte, is_correct, ordre FROM choix_reponses WHERE question_id = ? ORDER BY ordre, id");
                $stmtC->execute([$q['id']]);
                foreach ($stmtC->fetchAll() as $c) {
                    $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)")
                        ->execute([$newQid, $c['texte'], (int)$c['is_correct'], (int)$c['ordre']]);
                }

                $nbImporte++;
            }

            $pdo->commit();
            $stats = [
                'type'     => 'consolider',
                'banque'   => $banqueNom,
                'importe'  => $nbImporte,
                'skip'     => $nbSkip,
                'total'    => count($questions),
                'banqueId' => $banqueId,
            ];
            $msg = "Banque « {$banqueNom} » mise à jour : {$nbImporte} question(s) ajoutée(s), {$nbSkip} doublon(s) ignoré(s).";

        } catch (Exception $e) {
            $pdo->rollBack();
            $erreur = 'Erreur BD : ' . $e->getMessage();
        }
    }
}

// ── Action 2 : Importer banque → module cible ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'importer') {
    verifyCsrfToken();

    $banqueId  = (int)($_POST['banque_id']  ?? 0);
    $cibleId   = (int)($_POST['cible_id']   ?? 0);
    $partiesIds = array_map('intval', $_POST['parties_ids'] ?? []);

    if ($banqueId <= 0 || $cibleId <= 0) {
        $erreur = "Sélectionnez une banque et un module cible.";
    } elseif ($banqueId === $cibleId) {
        $erreur = "La banque et le module cible doivent être différents.";
    } else {
        $pdo->beginTransaction();
        try {
            // Index des textes déjà dans le module cible
            $stmtEx = $pdo->prepare("SELECT MD5(LOWER(TRIM(texte))) FROM questions WHERE module_id = ?");
            $stmtEx->execute([$cibleId]);
            $hashsCible = array_flip($stmtEx->fetchAll(PDO::FETCH_COLUMN));

            $wherePartie = '';
            $paramsQ = [$banqueId];
            if (!empty($partiesIds)) {
                $ph = implode(',', array_fill(0, count($partiesIds), '?'));
                $wherePartie = " AND q.partie_id IN ($ph)";
                $paramsQ = array_merge($paramsQ, $partiesIds);
            }

            $stmtQ = $pdo->prepare("
                SELECT q.*, p.nom AS partie_nom, p.ordre AS partie_ordre
                FROM questions q
                JOIN parties p ON p.id = q.partie_id
                WHERE q.module_id = ? $wherePartie
                ORDER BY p.ordre, q.ordre, q.id
            ");
            $stmtQ->execute($paramsQ);
            $questions = $stmtQ->fetchAll();

            $nbImporte = 0;
            $nbSkip    = 0;

            foreach ($questions as $q) {
                $hash = md5(strtolower(trim($q['texte'])));
                if (isset($hashsCible[$hash])) {
                    $nbSkip++;
                    continue;
                }
                $hashsCible[$hash] = true;

                // Trouver ou créer la partie dans le module cible
                $stmtP = $pdo->prepare("SELECT id FROM parties WHERE module_id = ? AND nom = ?");
                $stmtP->execute([$cibleId, $q['partie_nom']]);
                $partieId = $stmtP->fetchColumn();
                if (!$partieId) {
                    $pdo->prepare("INSERT INTO parties (module_id, nom, ordre, actif) VALUES (?, ?, ?, 1)")
                        ->execute([$cibleId, $q['partie_nom'], (int)$q['partie_ordre']]);
                    $partieId = (int)$pdo->lastInsertId();
                }

                $pdo->prepare("INSERT INTO questions (module_id, partie_id, texte, type, points, ordre, image_path)
                               VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$cibleId, $partieId, $q['texte'], $q['type'], $q['points'], $q['ordre'], $q['image_path']]);
                $newQid = (int)$pdo->lastInsertId();

                $stmtC = $pdo->prepare("SELECT texte, is_correct, ordre FROM choix_reponses WHERE question_id = ? ORDER BY ordre, id");
                $stmtC->execute([$q['id']]);
                foreach ($stmtC->fetchAll() as $c) {
                    $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)")
                        ->execute([$newQid, $c['texte'], (int)$c['is_correct'], (int)$c['ordre']]);
                }

                $nbImporte++;
            }

            // Récupérer nom cible
            $nomCible = $pdo->prepare("SELECT nom FROM modules WHERE id=?");
            $nomCible->execute([$cibleId]);
            $nomCibleStr = $nomCible->fetchColumn();

            $pdo->commit();
            $stats = [
                'type'    => 'importer',
                'cible'   => $nomCibleStr,
                'importe' => $nbImporte,
                'skip'    => $nbSkip,
                'total'   => count($questions),
                'cibleId' => $cibleId,
            ];
            $msg = "Import vers « {$nomCibleStr} » : {$nbImporte} question(s) copiée(s), {$nbSkip} doublon(s) ignoré(s).";

        } catch (Exception $e) {
            $pdo->rollBack();
            $erreur = 'Erreur BD : ' . $e->getMessage();
        }
    }
}

// ── Données pour l'affichage ─────────────────────────────────────────────────
$allModules = $pdo->query("
    SELECT m.id, m.nom, m.description,
           COUNT(q.id) AS nb_questions
    FROM modules m
    LEFT JOIN questions q ON q.module_id = m.id
    GROUP BY m.id, m.nom, m.description
    ORDER BY m.nom
")->fetchAll();

// Banques = modules dont la description contient [BANQUE]
$banques = array_filter($allModules, fn($m) => str_contains((string)$m['description'], '[BANQUE]'));

// Parties d'une banque pour l'import partiel (AJAX ou paramètre GET)
$partiesBanque = [];
if (isset($_GET['banque_id']) && (int)$_GET['banque_id'] > 0) {
    $stmtPB = $pdo->prepare("SELECT id, nom, ordre,
        (SELECT COUNT(*) FROM questions WHERE partie_id=p.id) AS nb
        FROM parties p WHERE module_id=? ORDER BY ordre, nom");
    $stmtPB->execute([(int)$_GET['banque_id']]);
    $partiesBanque = $stmtPB->fetchAll();
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode($partiesBanque);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Banque de questions</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .module-check-item { border: 1px solid #dee2e6; border-radius: 8px; padding: 10px 14px; margin-bottom: 6px; cursor: pointer; transition: background .15s; }
        .module-check-item:hover { background: #f0f4ff; }
        .module-check-item input[type=checkbox]:checked ~ * { color: #0d6efd; }
        .module-check-item.selected { background: #e8f0fe; border-color: #86aef7; }
        .badge-nb { font-size: .7rem; }
        #filterInput { max-width: 340px; }
    </style>
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container py-4" style="max-width:900px">

    <h2 class="h4 fw-bold mb-1">
        <i class="bi bi-archive me-2 text-primary"></i>Banque de questions
    </h2>
    <p class="text-muted small mb-4">Consolidez les questions de plusieurs modules en une banque sans doublons, puis importez-les dans de nouveaux modules.</p>

    <?php if ($erreur): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($erreur) ?></div>
    <?php endif; ?>

    <?php if ($msg): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
        <?php if ($stats): ?>
        <ul class="mb-0 mt-2 small">
            <li>Questions dans la source : <strong><?= $stats['total'] ?></strong></li>
            <li>Ajoutées : <strong class="text-success"><?= $stats['importe'] ?></strong></li>
            <li>Doublons ignorés : <?= $stats['skip'] ?></li>
            <?php if ($stats['type'] === 'consolider'): ?>
            <li><a href="gestion_questions.php?tab=gerer&module_id=<?= $stats['banqueId'] ?>" class="text-primary">→ Ouvrir la banque</a></li>
            <?php else: ?>
            <li><a href="gestion_questions.php?tab=gerer&module_id=<?= $stats['cibleId'] ?>" class="text-primary">→ Ouvrir le module cible</a></li>
            <?php endif; ?>
        </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- ─── SECTION 1 : Consolider ──────────────────────────── -->
        <div class="col-12">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-primary text-white rounded-top-4 py-3">
                <i class="bi bi-funnel me-2"></i><strong>1 — Consolider des modules → créer une banque</strong>
            </div>
            <div class="card-body p-4">
                <form method="POST" id="formConsolider">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="consolider">

                    <div class="mb-3 d-flex align-items-center gap-2 flex-wrap">
                        <label class="fw-semibold mb-0">Filtrer les modules :</label>
                        <input type="text" id="filterInput" class="form-control form-control-sm"
                               placeholder="ex: M204, M205, M207…" oninput="filtrerModules(this.value)">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toutSelectionner()">Tout sélectionner</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toutDeselectionner()">Désélectionner</button>
                    </div>

                    <div id="listeModules" style="max-height:320px;overflow-y:auto;padding-right:4px;">
                        <?php foreach ($allModules as $m): ?>
                        <label class="module-check-item d-flex align-items-center gap-3 w-100"
                               data-nom="<?= htmlspecialchars(strtolower($m['nom'])) ?>">
                            <input type="checkbox" name="source_ids[]" value="<?= $m['id'] ?>"
                                   class="form-check-input flex-shrink-0 mt-0"
                                   onchange="this.closest('label').classList.toggle('selected',this.checked)">
                            <span class="flex-grow-1 text-truncate" title="<?= htmlspecialchars($m['nom']) ?>">
                                <span class="badge bg-light text-secondary border fw-normal me-1" style="font-size:.68rem">#<?= $m['id'] ?></span>
                                <?= htmlspecialchars($m['nom']) ?>
                                <?php if (str_contains((string)$m['description'], '[BANQUE]')): ?>
                                <span class="badge bg-warning text-dark ms-1 badge-nb">BANQUE</span>
                                <?php endif; ?>
                            </span>
                            <span class="badge bg-secondary badge-nb flex-shrink-0"><?= $m['nb_questions'] ?> Q</span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-3">
                        <label class="form-label fw-semibold">Nom de la banque cible</label>
                        <input type="text" name="banque_nom" id="banqueNom" class="form-control"
                               placeholder="ex: M204 — Banque Questions" required>
                        <div class="form-text">Si la banque existe déjà (même nom), les nouvelles questions s'y ajoutent sans doublons.</div>
                    </div>

                    <button type="submit" class="btn btn-primary mt-3">
                        <i class="bi bi-archive me-2"></i>Consolider → Banque
                    </button>
                </form>
            </div>
        </div>
        </div>

        <!-- ─── SECTION 2 : Importer banque → module cible ─────── -->
        <div class="col-12">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-success text-white rounded-top-4 py-3">
                <i class="bi bi-box-arrow-in-right me-2"></i><strong>2 — Importer une banque → nouveau module</strong>
            </div>
            <div class="card-body p-4">

                <?php if (empty($banques)): ?>
                <div class="alert alert-info mb-0">
                    <i class="bi bi-info-circle me-2"></i>Aucune banque trouvée. Consolidez d'abord des modules (section 1).
                </div>
                <?php else: ?>
                <form method="POST" id="formImporter">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="importer">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Banque source</label>
                            <select name="banque_id" id="selectBanque" class="form-select" required onchange="chargerParties(this.value)">
                                <option value="">— Choisir une banque —</option>
                                <?php foreach ($banques as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['nom']) ?> (<?= $b['nb_questions'] ?> Q)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Module cible</label>
                            <select name="cible_id" class="form-select" required>
                                <option value="">— Choisir un module —</option>
                                <?php foreach ($allModules as $m): ?>
                                <?php if (!str_contains((string)$m['description'], '[BANQUE]')): ?>
                                <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['nom']) ?> (<?= $m['nb_questions'] ?> Q)</option>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="partiesContainer" class="mt-3" style="display:none">
                        <label class="form-label fw-semibold">
                            Filtrer par parties (optionnel — vide = toutes)
                        </label>
                        <div id="partiesList" class="d-flex flex-wrap gap-2"></div>
                    </div>

                    <button type="submit" class="btn btn-success mt-3">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Importer → Module cible
                    </button>
                </form>
                <?php endif; ?>

            </div>
        </div>
        </div>

        <!-- ─── Tableau de bord des banques ─────────────────────── -->
        <?php if (!empty($banques)): ?>
        <div class="col-12">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-warning text-dark rounded-top-4 py-3">
                <i class="bi bi-database me-2"></i><strong>Banques existantes</strong>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Nom</th>
                            <th class="text-center">Questions</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($banques as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars($b['nom']) ?></td>
                            <td class="text-center"><span class="badge bg-secondary"><?= $b['nb_questions'] ?></span></td>
                            <td class="text-end">
                                <a href="gestion_questions.php?tab=gerer&module_id=<?= $b['id'] ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye me-1"></i>Voir questions
                                </a>
                                <a href="export_questions.php?module_id=<?= $b['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary ms-1">
                                    <i class="bi bi-download me-1"></i>Export JSON
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        </div>
        <?php endif; ?>

    </div><!-- /row -->

    <div class="mt-3 text-center">
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Retour Dashboard
        </a>
    </div>
</div>

<script>
function filtrerModules(val) {
    val = val.toLowerCase();
    document.querySelectorAll('#listeModules label').forEach(el => {
        el.style.display = el.dataset.nom.includes(val) ? '' : 'none';
    });
    // Auto-remplir le nom de la banque
    if (val.length >= 2) {
        const kw = val.toUpperCase().replace(/\s+/g, ' ').trim();
        document.getElementById('banqueNom').placeholder = kw + ' — Banque Questions';
    }
}

function toutSelectionner() {
    document.querySelectorAll('#listeModules label:not([style*="none"]) input[type=checkbox]').forEach(cb => {
        cb.checked = true;
        cb.closest('label').classList.add('selected');
    });
}

function toutDeselectionner() {
    document.querySelectorAll('#listeModules input[type=checkbox]').forEach(cb => {
        cb.checked = false;
        cb.closest('label').classList.remove('selected');
    });
}

function chargerParties(banqueId) {
    const container = document.getElementById('partiesContainer');
    const list = document.getElementById('partiesList');
    if (!banqueId) { container.style.display = 'none'; return; }

    fetch('banque_questions.php?banque_id=' + banqueId + '&ajax=1')
        .then(r => r.json())
        .then(parties => {
            list.innerHTML = '';
            if (parties.length === 0) { container.style.display = 'none'; return; }
            parties.forEach(p => {
                const label = document.createElement('label');
                label.className = 'badge bg-light text-dark border d-flex align-items-center gap-1 p-2';
                label.style.cursor = 'pointer';
                label.innerHTML = `<input type="checkbox" name="parties_ids[]" value="${p.id}" class="form-check-input mt-0">
                    ${p.nom} <span class="badge bg-secondary ms-1">${p.nb}</span>`;
                list.appendChild(label);
            });
            container.style.display = '';
        });
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
