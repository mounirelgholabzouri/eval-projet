<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/spreadsheet.php';

$pdo = getDB();
$msg = ''; $erreur = '';

// Colonnes du modèle Excel (ordre figé) : ID, NOM, PRENOM, 7 notes, ABSENT
const CC_MODEL_HEADERS = ['ID', 'NOM', 'PRENOM', 'P1Q1', 'P1Q2', 'P2Q1', 'P2Q2', 'P3Q1', 'P3Q2', 'P4', 'ABSENT'];

/** Charge l'éval cc_pratique + son groupe + roster. Retourne [eval, groupeId, roster] ou null. */
function loadCCEval(PDO $pdo, int $evalId): ?array {
    $stmt = $pdo->prepare("SELECT e.*, m.nom AS module_nom FROM evaluations e
                           JOIN modules m ON m.id = e.module_id
                           WHERE e.id = ? AND e.categorie = 'cc_pratique'");
    $stmt->execute([$evalId]);
    $ev = $stmt->fetch();
    if (!$ev) return null;
    $meta = json_decode($ev['meta_json'] ?? '{}', true) ?: [];
    $gid = (int)($meta['groupe_id'] ?? 0);
    $r = $pdo->prepare("SELECT id, nom, prenom FROM stagiaires WHERE groupe_id = ? ORDER BY nom, prenom");
    $r->execute([$gid]);
    return [$ev, $gid, $r->fetchAll()];
}

// Cellules de la grille fixe (comme l'image) : 4 parties / total 20
const CC_CELLS  = ['p1q1','p1q2','p2q1','p2q2','p3q1','p3q2','p4'];
const CC_MAXQ   = 2.5;  // max par sous-question (parties 1-3)
const CC_MAXP4  = 5.0;  // max partie 4

function ccCellMax(string $cell): float { return $cell === 'p4' ? CC_MAXP4 : CC_MAXQ; }

// ── Création d'une grille CC pratique ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_cc'])) {
    verifyCsrfToken();
    $moduleId = (int)($_POST['module_id'] ?? 0);
    $groupeId = (int)($_POST['groupe_id'] ?? 0);
    $numero   = max(1, (int)($_POST['numero'] ?? 1));
    $titre    = trim($_POST['titre'] ?? '');
    if ($titre === '') $titre = "Contrôle pratique N°$numero";

    if ($moduleId > 0 && $groupeId > 0) {
        $meta = json_encode(['numero' => $numero, 'groupe_id' => $groupeId], JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare("INSERT INTO evaluations (module_id, nom, type, categorie, duree_minutes, note_max, meta_json, actif)
                               VALUES (?,?,'pratique','cc_pratique',60,20,?,1)");
        $stmt->execute([$moduleId, $titre, $meta]);
        $newId = (int)$pdo->lastInsertId();
        header("Location: cc_pratique.php?eval_id=$newId&created=1");
        exit;
    }
    $erreur = "Module et groupe obligatoires.";
}

// ── Suppression d'une grille ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_cc'])) {
    verifyCsrfToken();
    $delId = (int)($_POST['eval_id'] ?? 0);
    if ($delId > 0) {
        $stmt = $pdo->prepare("DELETE FROM evaluations WHERE id = ? AND categorie = 'cc_pratique'");
        $stmt->execute([$delId]);
        header("Location: cc_pratique.php?deleted=1");
        exit;
    }
}

// ── Enregistrement des notes ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_notes'])) {
    verifyCsrfToken();
    $evalId = (int)($_POST['eval_id'] ?? 0);
    $notes  = $_POST['notes'] ?? [];
    $absents = $_POST['absent'] ?? [];

    $up = $pdo->prepare("INSERT INTO cc_pratique_notes
        (evaluation_id, stagiaire_id, p1q1,p1q2,p2q1,p2q2,p3q1,p3q2,p4, total, absent)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
        p1q1=VALUES(p1q1),p1q2=VALUES(p1q2),p2q1=VALUES(p2q1),p2q2=VALUES(p2q2),
        p3q1=VALUES(p3q1),p3q2=VALUES(p3q2),p4=VALUES(p4),total=VALUES(total),absent=VALUES(absent)");

    $allSids = array_unique(array_merge(array_keys($notes), array_keys($absents)));
    foreach ($allSids as $sid) {
        $sid = (int)$sid;
        if ($sid <= 0) continue;
        $cells = $notes[$sid] ?? [];
        $isAbsent = isset($absents[$sid]) ? 1 : 0;
        $vals = []; $total = 0; $hasVal = false;
        foreach (CC_CELLS as $c) {
            if ($isAbsent) { $vals[] = null; continue; }
            $raw = trim((string)($cells[$c] ?? ''));
            if ($raw === '') { $vals[] = null; continue; }
            $v = (float)str_replace(',', '.', $raw);
            if ($v < 0) $v = 0;
            $max = ccCellMax($c);
            if ($v > $max) $v = $max;
            $vals[] = $v; $total += $v; $hasVal = true;
        }
        $totalVal = $isAbsent ? null : ($hasVal ? round($total, 2) : null);
        $up->execute(array_merge([$evalId, $sid], $vals, [$totalVal, $isAbsent]));
    }
    header("Location: cc_pratique.php?eval_id=$evalId&saved=1");
    exit;
}

// ── Import d'un fichier Excel (modèle de l'application) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_excel'])) {
    verifyCsrfToken();
    $evalId = (int)($_POST['eval_id'] ?? 0);
    $loaded = $evalId > 0 ? loadCCEval($pdo, $evalId) : null;
    if (!$loaded) { header("Location: cc_pratique.php?erreur_import=1"); exit; }
    [$ev, $gid, $roster] = $loaded;

    if (empty($_FILES['fichier']['tmp_name']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
        header("Location: cc_pratique.php?eval_id=$evalId&import_err=" . urlencode('Aucun fichier reçu.')); exit;
    }

    $rows = readSpreadsheet($_FILES['fichier']['tmp_name']);
    if (count($rows) < 2) {
        header("Location: cc_pratique.php?eval_id=$evalId&import_err=" . urlencode('Fichier vide ou illisible.')); exit;
    }

    // Index de correspondance : par ID (col 0) + par NOM|PRENOM normalisés
    $byId = []; $byName = [];
    foreach ($roster as $st) {
        $byId[(int)$st['id']] = (int)$st['id'];
        $key = mb_strtolower(trim($st['nom']) . '|' . trim($st['prenom']), 'UTF-8');
        $byName[$key] = (int)$st['id'];
    }

    $up = $pdo->prepare("INSERT INTO cc_pratique_notes
        (evaluation_id, stagiaire_id, p1q1,p1q2,p2q1,p2q2,p3q1,p3q2,p4, total, absent)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
        p1q1=VALUES(p1q1),p1q2=VALUES(p1q2),p2q1=VALUES(p2q1),p2q2=VALUES(p2q2),
        p3q1=VALUES(p3q1),p3q2=VALUES(p3q2),p4=VALUES(p4),total=VALUES(total),absent=VALUES(absent)");

    $nbOk = 0; $nbSkip = 0;
    foreach ($rows as $i => $row) {
        if ($i === 0) continue; // en-tête
        $row = array_map(fn($v) => trim((string)$v), $row);
        if (count(array_filter($row, fn($v) => $v !== '')) === 0) continue; // ligne vide

        $sid = 0;
        $rid = (int)($row[0] ?? 0);
        if ($rid > 0 && isset($byId[$rid])) {
            $sid = $rid;
        } else {
            $key = mb_strtolower(($row[1] ?? '') . '|' . ($row[2] ?? ''), 'UTF-8');
            $sid = $byName[$key] ?? 0;
        }
        if ($sid <= 0) { $nbSkip++; continue; }

        $absRaw = mb_strtolower($row[10] ?? '', 'UTF-8');
        $isAbsent = ($absRaw !== '' && $absRaw !== '0') ? 1 : 0;

        $vals = []; $total = 0; $hasVal = false;
        foreach (CC_CELLS as $k => $c) {
            if ($isAbsent) { $vals[] = null; continue; }
            $raw = $row[3 + $k] ?? '';
            if ($raw === '') { $vals[] = null; continue; }
            $v = (float)str_replace(',', '.', $raw);
            if ($v < 0) $v = 0;
            $max = ccCellMax($c);
            if ($v > $max) $v = $max;
            $vals[] = $v; $total += $v; $hasVal = true;
        }
        $totalVal = $isAbsent ? null : ($hasVal ? round($total, 2) : null);
        $up->execute(array_merge([$evalId, $sid], $vals, [$totalVal, $isAbsent]));
        $nbOk++;
    }
    header("Location: cc_pratique.php?eval_id=$evalId&imported=" . $nbOk . "&skipped=" . $nbSkip);
    exit;
}

if (isset($_GET['created'])) $msg = "Grille créée.";
if (isset($_GET['saved']))   $msg = "Notes enregistrées.";
if (isset($_GET['deleted'])) $msg = "Grille supprimée.";
if (isset($_GET['imported'])) {
    $msg = (int)$_GET['imported'] . " ligne(s) importée(s)."
         . (($_GET['skipped'] ?? 0) > 0 ? " " . (int)$_GET['skipped'] . " ignorée(s) (stagiaire non reconnu)." : "");
}
if (isset($_GET['import_err'])) $erreur = "Import : " . htmlspecialchars($_GET['import_err']);

$evalId    = (int)($_GET['eval_id'] ?? 0);
$printMode = isset($_GET['print']);

// ── Données pour le mode grille / état ───────────────────────
$eval = null; $roster = []; $notesMap = []; $groupeNom = ''; $moduleNom = '';
if ($evalId > 0) {
    $stmt = $pdo->prepare("SELECT e.*, m.nom AS module_nom FROM evaluations e
                           JOIN modules m ON m.id = e.module_id
                           WHERE e.id = ? AND e.categorie = 'cc_pratique'");
    $stmt->execute([$evalId]);
    $eval = $stmt->fetch() ?: null;
    if ($eval) {
        $moduleNom = $eval['module_nom'];
        $meta = json_decode($eval['meta_json'] ?? '{}', true) ?: [];
        $gid = (int)($meta['groupe_id'] ?? 0);
        $eval['numero'] = (int)($meta['numero'] ?? 1);

        $g = $pdo->prepare("SELECT nom FROM groupes WHERE id = ?");
        $g->execute([$gid]);
        $groupeNom = (string)($g->fetchColumn() ?: '');

        $r = $pdo->prepare("SELECT id, nom, prenom FROM stagiaires WHERE groupe_id = ? ORDER BY nom, prenom");
        $r->execute([$gid]);
        $roster = $r->fetchAll();

        $n = $pdo->prepare("SELECT * FROM cc_pratique_notes WHERE evaluation_id = ?");
        $n->execute([$evalId]);
        foreach ($n->fetchAll() as $row) $notesMap[(int)$row['stagiaire_id']] = $row;

        // ── Téléchargement du modèle Excel (roster pré-rempli) ──
        if (isset($_GET['model'])) {
            $rows = [CC_MODEL_HEADERS];
            foreach ($roster as $st) {
                $row = $notesMap[(int)$st['id']] ?? null;
                $isAbs = $row ? (int)$row['absent'] === 1 : false;
                $rows[] = [
                    (int)$st['id'], $st['nom'], $st['prenom'],
                    $row && !$isAbs ? $row['p1q1'] : '', $row && !$isAbs ? $row['p1q2'] : '',
                    $row && !$isAbs ? $row['p2q1'] : '', $row && !$isAbs ? $row['p2q2'] : '',
                    $row && !$isAbs ? $row['p3q1'] : '', $row && !$isAbs ? $row['p3q2'] : '',
                    $row && !$isAbs ? $row['p4']   : '', $isAbs ? 'x' : '',
                ];
            }
            $bin = buildXlsx($rows, 'CC Pratique');
            $fname = 'modele_cc_pratique_' . preg_replace('/[^A-Za-z0-9]+/', '_', $groupeNom) . '_N' . $eval['numero'] . '.xlsx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            header('Content-Length: ' . strlen($bin));
            echo $bin;
            exit;
        }
    } else {
        $erreur = "Grille introuvable.";
        $evalId = 0;
    }
}

// ── Liste des grilles existantes (mode liste) ────────────────
$ccList = [];
if ($evalId === 0) {
    $ccList = $pdo->query("
        SELECT e.id, e.nom, e.meta_json, m.nom AS module_nom,
               (SELECT COUNT(*) FROM cc_pratique_notes n WHERE n.evaluation_id = e.id AND n.total IS NOT NULL) AS nb_notes
        FROM evaluations e
        JOIN modules m ON m.id = e.module_id
        WHERE e.categorie = 'cc_pratique'
        ORDER BY m.nom, e.id DESC
    ")->fetchAll();
    $modules = $pdo->query("SELECT id, nom FROM modules ORDER BY nom")->fetchAll();
    $groupes = getGroupes();
}

function fmtN($v): string {
    if ($v === null || $v === '') return '';
    $v = (float)$v;
    $s = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CC Pratique — Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .grille th, .grille td { text-align: center; vertical-align: middle; }
        .grille td.name { text-align: left; white-space: nowrap; }
        .grille input[type=number] { width: 64px; text-align: center; }
        .grille .total-cell { font-weight: 700; background: #f8f9fa; }
        @page { margin: 0; }
        @media print {
            .no-print { display: none !important; }
            html, body { margin: 0 !important; background: #fff !important; }
            .container-fluid { padding: 12mm 10mm !important; }
            .grille { font-size: 12px; }
            .grille th, .grille td { border: 1px solid #000 !important; padding: 4px 6px; }
        }
        .grille-title { text-align: center; font-weight: 700; }
    </style>
</head>
<body class="<?= $printMode ? '' : 'bg-light' ?>">
<?php if (!$printMode): ?>
<?php include __DIR__ . '/partials/navbar.php'; ?>
<?php endif; ?>

<div class="container-fluid py-4 px-4">

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible fade show rounded-3 mb-4 no-print">
    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($erreur): ?>
<div class="alert alert-danger alert-dismissible fade show rounded-3 mb-4 no-print">
    <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($erreur) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($evalId === 0): ?>
<!-- ════════ LISTE + CRÉATION ════════ -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
        <h2 class="h4 fw-bold mb-0"><i class="bi bi-grid-3x3-gap me-2 text-primary"></i>CC Pratique — Grilles de notation</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#mCreate">
            <i class="bi bi-plus-lg me-2"></i>Nouvelle grille
        </button>
    </div>

    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-body">
            <?php if (empty($ccList)): ?>
                <p class="text-muted mb-0 text-center py-4">Aucune grille CC pratique. Créez-en une pour commencer.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="table-light">
                        <tr><th>Module</th><th>Intitulé</th><th>Groupe</th><th class="text-center">Notes saisies</th><th class="text-end">Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ccList as $cc):
                        $m = json_decode($cc['meta_json'] ?? '{}', true) ?: [];
                        $gid = (int)($m['groupe_id'] ?? 0);
                        $gn = '';
                        foreach (($groupes ?? []) as $gg) { if ((int)$gg['id'] === $gid) { $gn = $gg['nom']; break; } }
                    ?>
                        <tr>
                            <td class="small"><?= htmlspecialchars($cc['module_nom']) ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($cc['nom']) ?></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($gn ?: '—') ?></span></td>
                            <td class="text-center"><?= (int)$cc['nb_notes'] ?></td>
                            <td class="text-end">
                                <a href="cc_pratique.php?eval_id=<?= (int)$cc['id'] ?>" class="btn btn-sm btn-outline-primary" title="Saisir les notes"><i class="bi bi-pencil-square"></i></a>
                                <a href="cc_pratique.php?eval_id=<?= (int)$cc['id'] ?>&print=1" target="_blank" class="btn btn-sm btn-outline-dark" title="Imprimer l'état"><i class="bi bi-printer"></i></a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer cette grille et toutes ses notes ?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="eval_id" value="<?= (int)$cc['id'] ?>">
                                    <button name="delete_cc" class="btn btn-sm btn-outline-danger" title="Supprimer"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal création -->
    <div class="modal fade" id="mCreate" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="POST">
            <?= csrfField() ?>
            <div class="modal-header"><h5 class="modal-title">Nouvelle grille CC pratique</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Module</label>
                    <select name="module_id" class="form-select" required>
                        <option value="">— Choisir —</option>
                        <?php foreach (($modules ?? []) as $m): ?>
                        <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Groupe</label>
                    <select name="groupe_id" class="form-select" required>
                        <option value="">— Choisir —</option>
                        <?php foreach (($groupes ?? []) as $g): ?>
                        <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2">
                    <div class="col-4">
                        <label class="form-label fw-semibold">N°</label>
                        <input type="number" name="numero" class="form-control" value="1" min="1" max="9">
                    </div>
                    <div class="col-8">
                        <label class="form-label fw-semibold">Intitulé <span class="text-muted small">(auto si vide)</span></label>
                        <input type="text" name="titre" class="form-control" placeholder="Contrôle pratique N°1">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                <button name="create_cc" class="btn btn-primary">Créer</button>
            </div>
          </form>
        </div>
      </div>
    </div>

<?php else: ?>
<!-- ════════ GRILLE / ÉTAT ════════ -->
    <?php if (!$printMode): ?>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print">
        <div>
            <a href="cc_pratique.php" class="btn btn-sm btn-light mb-2"><i class="bi bi-arrow-left"></i> Retour</a>
            <h2 class="h5 fw-bold mb-0"><?= htmlspecialchars($eval['nom']) ?></h2>
            <div class="text-muted small"><?= htmlspecialchars($moduleNom) ?> — Groupe <?= htmlspecialchars($groupeNom) ?></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="cc_pratique.php?eval_id=<?= $evalId ?>&model=1" class="btn btn-outline-secondary" download><i class="bi bi-download me-2"></i>Télécharger modèle</a>
            <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#mImport"><i class="bi bi-upload me-2"></i>Importer Excel</button>
            <a href="cc_pratique.php?eval_id=<?= $evalId ?>&print=1" target="_blank" class="btn btn-dark"><i class="bi bi-printer me-2"></i>Imprimer l'état</a>
        </div>
    </div>
    <?php else: ?>
    <div class="text-center mb-3">
        <div class="grille-title h4"><?= htmlspecialchars("Grille de note de " . strtolower($eval['nom'])) ?></div>
        <div class="fw-semibold"><?= htmlspecialchars($groupeNom) ?> — MODULE <?= htmlspecialchars($moduleNom) ?></div>
    </div>
    <?php endif; ?>

    <?php if (empty($roster)): ?>
        <div class="alert alert-warning">Aucun stagiaire dans ce groupe.</div>
    <?php else: ?>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="eval_id" value="<?= $evalId ?>">
        <div class="table-responsive">
            <table class="table table-bordered grille bg-white mb-3">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2">NOM</th>
                        <th rowspan="2">PRENOM</th>
                        <th colspan="2">PARTIE 1 (5 pts)</th>
                        <th colspan="2">PARTIE 2 (5 pts)</th>
                        <th colspan="2">PARTIE 3 (5 pts)</th>
                        <th rowspan="2">PARTIE 4<br>(5 pts)</th>
                        <th rowspan="2">TOTAL<br>/20</th>
                        <?php if (!$printMode): ?><th rowspan="2" class="no-print">Abs.</th><?php endif; ?>
                    </tr>
                    <tr>
                        <th>Q1 (2.5)</th><th>Q2 (2.5)</th>
                        <th>Q1 (2.5)</th><th>Q2 (2.5)</th>
                        <th>Q1 (2.5)</th><th>Q2 (2.5)</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($roster as $st):
                    $sid = (int)$st['id'];
                    $row = $notesMap[$sid] ?? null;
                    $isAbsent = $row ? (int)$row['absent'] === 1 : false;
                ?>
                    <tr data-sid="<?= $sid ?>" class="<?= $isAbsent ? 'table-secondary' : '' ?>">
                        <td class="name"><?= htmlspecialchars($st['nom']) ?></td>
                        <td class="name"><?= htmlspecialchars($st['prenom']) ?></td>
                        <?php foreach (CC_CELLS as $c):
                            $val = $row ? $row[$c] : null; ?>
                        <td>
                            <?php if ($printMode): ?>
                                <?= $isAbsent ? 'Abs' : fmtN($val) ?>
                            <?php else: ?>
                                <input type="number" class="form-control form-control-sm cc-in"
                                       name="notes[<?= $sid ?>][<?= $c ?>]"
                                       value="<?= htmlspecialchars(fmtN($val)) ?>"
                                       min="0" max="<?= ccCellMax($c) ?>" step="0.25"
                                       <?= $isAbsent ? 'disabled' : '' ?>>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        <td class="total-cell"><span class="cc-total"><?= $isAbsent ? 'Abs' : fmtN($row['total'] ?? null) ?></span></td>
                        <?php if (!$printMode): ?>
                        <td class="no-print">
                            <input type="checkbox" class="form-check-input cc-abs" name="absent[<?= $sid ?>]" <?= $isAbsent ? 'checked' : '' ?>>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$printMode): ?>
        <div class="d-flex justify-content-end no-print">
            <button name="save_notes" class="btn btn-success"><i class="bi bi-save me-2"></i>Enregistrer les notes</button>
        </div>
        <?php endif; ?>
    </form>
    <?php endif; ?>

    <!-- Modal import Excel -->
    <div class="modal fade" id="mImport" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="eval_id" value="<?= $evalId ?>">
            <div class="modal-header"><h5 class="modal-title">Importer un fichier Excel</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Fichier Excel</label>
                    <input type="file" name="fichier" class="form-control" accept=".xlsx,.xls,.csv" required>
                    <div class="form-text">Format : .xlsx, .xls ou .csv selon le modèle téléchargé</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                <button name="import_excel" class="btn btn-primary">Importer</button>
            </div>
          </form>
        </div>
      </div>
    </div>
<?php endif; ?>

</div>

<?php if (!$printMode): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Total live + gestion absent
function recalc(tr) {
    let t = 0, any = false;
    tr.querySelectorAll('.cc-in').forEach(i => {
        const v = parseFloat(i.value);
        if (!isNaN(v)) { t += v; any = true; }
    });
    const abs = tr.querySelector('.cc-abs');
    const out = tr.querySelector('.cc-total');
    if (abs && abs.checked) { out.textContent = 'Abs'; }
    else { out.textContent = any ? (Math.round(t * 4) / 4) : ''; }  // step=0.25
}
document.querySelectorAll('tr[data-sid]').forEach(tr => {
    tr.querySelectorAll('.cc-in').forEach(i => i.addEventListener('input', () => recalc(tr)));
    const abs = tr.querySelector('.cc-abs');
    if (abs) abs.addEventListener('change', () => {
        const dis = abs.checked;
        tr.classList.toggle('table-secondary', dis);
        tr.querySelectorAll('.cc-in').forEach(i => { i.disabled = dis; if (dis) i.value=''; });
        recalc(tr);
    });
});
</script>
<?php else: ?>
<script>window.addEventListener('load', () => window.print());</script>
<?php endif; ?>
</body>
</html>
