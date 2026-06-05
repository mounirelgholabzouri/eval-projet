<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo    = getDB();
$msg    = '';
$erreur = '';

const CC_CELLS_GEN = ['p1q1','p1q2','p2q1','p2q2','p3q1','p3q2','p4'];
const CC_MAX_GEN   = ['p1q1'=>2.5,'p1q2'=>2.5,'p2q1'=>2.5,'p2q2'=>2.5,
                      'p3q1'=>2.5,'p3q2'=>2.5,'p4'=>5.0];
const STEP = 0.5;

function distribuer(float $total): array {
    $unit     = (int)round($total / STEP);
    $maxUnits = array_map(fn($m) => (int)round($m / STEP), CC_MAX_GEN);
    $order    = CC_CELLS_GEN;
    shuffle($order);
    $result    = array_fill_keys(CC_CELLS_GEN, 0.0);
    $remaining = $unit;
    $n         = count($order);
    for ($i = 0; $i < $n; $i++) {
        $cell   = $order[$i];
        $isLast = ($i === $n - 1);
        if ($isLast) {
            $val = max(0, min($remaining, $maxUnits[$cell]));
        } else {
            $futurMax = 0;
            foreach (array_slice($order, $i + 1) as $c) $futurMax += $maxUnits[$c];
            $minVal = max(0, $remaining - $futurMax);
            $maxVal = min($maxUnits[$cell], $remaining);
            $val    = ($minVal <= $maxVal) ? mt_rand($minVal, $maxVal) : $maxVal;
        }
        $result[$cell] = round($val * STEP, 2);
        $remaining    -= $val;
    }
    if ($remaining !== 0)
        $result['p4'] = round(min(5.0, max(0.0, $result['p4'] + $remaining * STEP)), 2);
    return $result;
}

function snapNote(float $note): float {
    return max(0.0, min(20.0, round($note * 2) / 2));
}

function getOrCreateEvalPratique(PDO $pdo, int $moduleId, int $groupeId, int $numero): int {
    $stmt = $pdo->prepare("
        SELECT id FROM evaluations
        WHERE module_id = ? AND categorie = 'cc_pratique'
          AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.groupe_id')) = ?
          AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.numero'))    = ?
        LIMIT 1
    ");
    $stmt->execute([$moduleId, (string)$groupeId, (string)$numero]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];
    $meta = json_encode(['numero' => $numero, 'groupe_id' => $groupeId], JSON_UNESCAPED_UNICODE);
    $pdo->prepare("
        INSERT INTO evaluations (module_id, nom, type, categorie, duree_minutes, note_max, meta_json, actif)
        VALUES (?, ?, 'pratique', 'cc_pratique', 60, 20, ?, 1)
    ")->execute([$moduleId, "Contrôle pratique N°$numero", $meta]);
    return (int)$pdo->lastInsertId();
}

function fmtN($v): string {
    if ($v === null || $v === '') return '';
    return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.') ?: '0';
}

// ── Paramètres ────────────────────────────────────────────────
$groupes   = getGroupes();
$groupeId  = (int)($_POST['groupe_id'] ?? $_GET['groupe_id'] ?? 0);
$genCC1    = !empty($_POST['gen_cc1']) || !empty($_GET['gen_cc1']);
$genCC2    = !empty($_POST['gen_cc2']) || !empty($_GET['gen_cc2']);
$printMode = isset($_GET['print']);
$printCC   = (int)($_GET['cc'] ?? 0);

$groupeNom = '';
foreach ($groupes as $g) {
    if ((int)$g['id'] === $groupeId) { $groupeNom = $g['nom']; break; }
}

// ── Roster ────────────────────────────────────────────────────
$roster = [];
if ($groupeId > 0) {
    $r = $pdo->prepare("SELECT id, nom, prenom FROM stagiaires WHERE groupe_id = ? ORDER BY nom, prenom");
    $r->execute([$groupeId]);
    foreach ($r->fetchAll() as $row)
        $roster[(int)$row['id']] = ['nom' => $row['nom'], 'prenom' => $row['prenom']];
}

// ── CC3 théorique depuis DB ───────────────────────────────────
$cc3DB   = [];   // mid => [sid => score]  — données brutes DB
$modInfo = [];   // mid => nom module
if ($groupeId > 0) {
    // Récupère tous les modules cc_theorique qui ont des évals
    $mods = $pdo->query("
        SELECT DISTINCT e.module_id, m.nom AS module_nom
        FROM evaluations e JOIN modules m ON m.id = e.module_id
        WHERE e.categorie = 'cc_theorique'
        ORDER BY m.nom
    ")->fetchAll();
    foreach ($mods as $m) {
        $modInfo[(int)$m['module_id']] = $m['module_nom'];
    }

    // Dernière session terminée par stagiaire par module
    $q = $pdo->prepare("
        SELECT s.stagiaire_id, s.score, e.module_id
        FROM sessions_eval s
        JOIN evaluations e ON e.id = s.evaluation_id
        WHERE s.groupe_id = ? AND s.statut = 'termine' AND e.categorie = 'cc_theorique'
        ORDER BY e.module_id, s.date_fin
    ");
    $q->execute([$groupeId]);
    foreach ($q->fetchAll() as $r) {
        $sid = (int)$r['stagiaire_id'];
        $mid = (int)$r['module_id'];
        if ($sid > 0) $cc3DB[$mid][$sid] = (float)$r['score'];
    }
}

// ── Reconstruction cc3Data depuis POST (si édition manuelle) ─
// cc3Data = valeurs effectives utilisées pour générer CC1/CC2
// absent_cc3[mid][sid] = true → stagiaire absent, pas de CC généré
$cc3Data   = $cc3DB;   // par défaut = données DB
$cc3Absent = [];       // mid => [sid => true]

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cc3'])) {
    $cc3Data = [];
    foreach ($_POST['cc3'] as $mid => $students) {
        $mid = (int)$mid;
        foreach ($students as $sid => $raw) {
            $sid = (int)$sid;
            if (!empty($_POST['cc3_absent'][$mid][$sid])) {
                $cc3Absent[$mid][$sid] = true;
            } else {
                $rawVal = trim(str_replace(',', '.', (string)$raw));
                if ($rawVal !== '') {
                    $cc3Data[$mid][$sid] = max(0.0, min(20.0, (float)$rawVal));
                }
            }
        }
    }
}

// ── Génération du preview ─────────────────────────────────────
$preview = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview'])) {
    verifyCsrfToken();
    if ($groupeId <= 0)           { $erreur = "Sélectionnez un groupe."; }
    elseif (!$genCC1 && !$genCC2) { $erreur = "Cochez au moins CC1 ou CC2."; }
    elseif (empty($cc3Data) && empty($cc3Absent)) { $erreur = "Aucune note CC3 pour ce groupe."; }
    else {
        foreach ($cc3Data as $mid => $students) {
            foreach ($students as $sid => $score3) {
                if ($genCC1) {
                    $n = snapNote($score3 - 1);
                    $preview[$mid]['cc1'][$sid] = array_merge(distribuer($n), ['total' => $n]);
                }
                if ($genCC2) {
                    $n = snapNote($score3 + 1);
                    $preview[$mid]['cc2'][$sid] = array_merge(distribuer($n), ['total' => $n]);
                }
            }
        }
        // Absents CC3 → absents dans CC1/CC2 aussi
        foreach ($cc3Absent as $mid => $sids) {
            foreach ($sids as $sid => $_) {
                if ($genCC1) $preview[$mid]['cc1_absent'][$sid] = true;
                if ($genCC2) $preview[$mid]['cc2_absent'][$sid] = true;
            }
        }
    }
}

// ── Sauvegarde notes modifiées ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_cc'])) {
    verifyCsrfToken();
    $saveCC  = (int)$_POST['save_cc'];
    $notesIn = $_POST['notes']   ?? [];
    $absIn   = $_POST['absents'] ?? [];
    $doPrint = isset($_POST['do_print']);

    if ($groupeId <= 0) {
        $erreur = "Données manquantes.";
    } else {
        $up = $pdo->prepare("
            INSERT INTO cc_pratique_notes
                (evaluation_id, stagiaire_id, p1q1,p1q2,p2q1,p2q2,p3q1,p3q2,p4,total,absent)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                p1q1=VALUES(p1q1),p1q2=VALUES(p1q2),p2q1=VALUES(p2q1),p2q2=VALUES(p2q2),
                p3q1=VALUES(p3q1),p3q2=VALUES(p3q2),p4=VALUES(p4),
                total=VALUES(total),absent=VALUES(absent)
        ");
        $count = 0;
        // Tous les mids = union des notes + absents
        $allMids = array_unique(array_merge(array_keys($notesIn), array_keys($absIn)));
        foreach ($allMids as $mid) {
            $mid    = (int)$mid;
            $evalId = getOrCreateEvalPratique($pdo, $mid, $groupeId, $saveCC);
            // Stagiaires avec notes
            foreach ($notesIn[$mid] ?? [] as $sid => $cells) {
                $sid  = (int)$sid;
                $vals = []; $tot = 0;
                foreach (CC_CELLS_GEN as $c) {
                    $v = max(0.0, min(CC_MAX_GEN[$c],
                        (float)str_replace(',', '.', trim((string)($cells[$c] ?? '0')))));
                    $v = round(round($v / STEP) * STEP, 2);
                    $vals[] = $v; $tot += $v;
                }
                $up->execute(array_merge([$evalId, $sid], $vals, [round($tot, 2), 0]));
                $count++;
            }
            // Stagiaires absents
            foreach ($absIn[$mid] ?? [] as $sid => $_) {
                $sid = (int)$sid;
                $up->execute([$evalId, $sid, null,null,null,null,null,null,null, null, 1]);
                $count++;
            }
        }
        $msg = "CC$saveCC : $count ligne(s) sauvegardée(s).";
        if ($doPrint) {
            header("Location: generer_cc_pratique.php?groupe_id=$groupeId&print=1&cc=$saveCC");
            exit;
        }
    }
}

// ── Données pour impression ───────────────────────────────────
$printData = [];
if ($printMode && $printCC > 0 && $groupeId > 0) {
    $evRows = $pdo->prepare("
        SELECT e.id, e.module_id, m.nom AS module_nom
        FROM evaluations e JOIN modules m ON m.id = e.module_id
        WHERE e.categorie = 'cc_pratique'
          AND JSON_UNQUOTE(JSON_EXTRACT(e.meta_json,'$.groupe_id')) = ?
          AND JSON_UNQUOTE(JSON_EXTRACT(e.meta_json,'$.numero'))    = ?
    ");
    $evRows->execute([(string)$groupeId, (string)$printCC]);
    foreach ($evRows->fetchAll() as $ev) {
        $mid = (int)$ev['module_id'];
        $modInfo[$mid] = $ev['module_nom'];
        $nt = $pdo->prepare("SELECT * FROM cc_pratique_notes WHERE evaluation_id = ?");
        $nt->execute([(int)$ev['id']]);
        foreach ($nt->fetchAll() as $row) {
            $sid = (int)$row['stagiaire_id'];
            if (!isset($roster[$sid])) continue;
            $printData[$mid][$sid] = $row;
        }
    }
    ksort($printData);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $printMode ? 'État CC'.$printCC.' — '.$groupeNom : 'Générer CC1/CC2 Pratique' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <?php if (!$printMode): ?><link href="../assets/css/style.css" rel="stylesheet"><?php endif; ?>
    <style>
        .grille th, .grille td { text-align:center; vertical-align:middle; font-size:.82rem; padding:3px 5px; }
        .grille td.name { text-align:left; white-space:nowrap; }
        .note-input {
            width:52px; text-align:center; border:1px solid #ced4da;
            border-radius:4px; padding:2px 4px; font-size:.82rem;
        }
        .note-input:focus { outline:none; border-color:#0d6efd; box-shadow:0 0 0 2px rgba(13,110,253,.15); }
        .note-input.over { background:#ffdede; border-color:#dc3545; }
        .note-input-cc3 { width:60px; background:#fffbe6; border-color:#ffc107; }
        .note-input-cc3:focus { border-color:#e6a000; box-shadow:0 0 0 2px rgba(255,193,7,.2); }
        td.total-cell { font-weight:bold; background:#f8f9fa; min-width:46px; }
        tr.absent-row td { opacity:.5; }
        @media print {
            .no-print { display:none !important; }
            body { background:#fff !important; }
            .container-fluid { padding:10mm !important; }
            .page-block { page-break-inside:avoid; margin-bottom:1.5rem; }
            .grille { font-size:11px; }
            .grille th, .grille td { border:1px solid #000 !important; padding:3px 5px; }
            .note-input { border:none !important; box-shadow:none !important; background:transparent !important; width:auto; }
            .print-title { font-size:13pt; font-weight:bold; margin-bottom:4mm; }
        }
    </style>
</head>
<body class="<?= $printMode ? '' : 'bg-light' ?>">

<?php if ($printMode): ?>
<!-- ════════════════════════════════════════════════════════════
     MODE IMPRESSION
     ════════════════════════════════════════════════════════════ -->
<div class="container-fluid py-3 px-4">
    <div class="d-flex gap-2 align-items-center mb-3 no-print">
        <a href="generer_cc_pratique.php?groupe_id=<?= $groupeId ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Retour
        </a>
        <button onclick="window.print()" class="btn btn-dark btn-sm">
            <i class="bi bi-printer me-1"></i>Imprimer
        </button>
        <button form="saveFormPrint" type="submit" name="do_save" value="1" class="btn btn-success btn-sm">
            <i class="bi bi-floppy me-1"></i>Sauvegarder les modifications
        </button>
        <span class="text-muted small">Modifiez les cellules puis sauvegardez avant d'imprimer.</span>
    </div>

    <?php if (empty($printData)): ?>
        <div class="alert alert-warning">Aucune donnée CC<?= $printCC ?> pour ce groupe. Générez et insérez d'abord les notes.</div>
    <?php else: ?>
    <div class="print-title">
        <?= htmlspecialchars(getEtablissementDefaut()) ?><br>
        <span style="font-size:.9em;font-weight:normal;">
            Contrôle Continu N°<?= $printCC ?> Pratique — Groupe : <?= htmlspecialchars($groupeNom) ?>
        </span>
    </div>

    <form method="POST" id="saveFormPrint">
        <?= csrfField() ?>
        <input type="hidden" name="groupe_id" value="<?= $groupeId ?>">
        <input type="hidden" name="save_cc"   value="<?= $printCC ?>">

    <?php foreach ($printData as $mid => $students): ?>
    <div class="page-block">
        <h6 class="fw-bold border-bottom pb-1 mb-2"><?= htmlspecialchars($modInfo[$mid]) ?></h6>
        <div class="table-responsive">
            <table class="table table-bordered grille mb-0 bg-white">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2" class="name">NOM</th>
                        <th rowspan="2" class="name">PRÉNOM</th>
                        <th colspan="2">PARTIE 1 (5 pts)</th>
                        <th colspan="2">PARTIE 2 (5 pts)</th>
                        <th colspan="2">PARTIE 3 (5 pts)</th>
                        <th rowspan="2">PARTIE 4<br>(5 pts)</th>
                        <th rowspan="2">TOTAL /20</th>
                        <th rowspan="2" class="no-print">Absent</th>
                    </tr>
                    <tr><th>Q1</th><th>Q2</th><th>Q1</th><th>Q2</th><th>Q1</th><th>Q2</th></tr>
                </thead>
                <tbody>
                <?php foreach ($roster as $sid => $stg):
                    $row = $students[$sid] ?? null;
                    $abs = $row && (int)$row['absent'] === 1;
                    $uid = "pr{$printCC}_{$mid}_{$sid}";
                ?>
                <tr class="<?= $abs ? 'absent-row' : '' ?>" id="tr_<?= $uid ?>">
                    <td class="name"><?= htmlspecialchars($stg['nom']) ?></td>
                    <td class="name"><?= htmlspecialchars($stg['prenom']) ?></td>
                    <?php if ($abs): ?>
                        <td colspan="7" class="text-center text-muted fst-italic">Absent</td>
                        <td class="total-cell">Abs</td>
                        <td class="no-print">
                            <input type="checkbox" name="absents[<?= $mid ?>][<?= $sid ?>]"
                                   class="form-check-input absent-cb" data-uid="<?= $uid ?>"
                                   checked onchange="toggleAbsent(this,'<?= $uid ?>')">
                        </td>
                    <?php elseif ($row): ?>
                        <?php foreach (CC_CELLS_GEN as $c):
                            $max = CC_MAX_GEN[$c]; ?>
                        <td>
                            <input type="number" class="note-input"
                                   name="notes[<?= $mid ?>][<?= $sid ?>][<?= $c ?>]"
                                   value="<?= fmtN($row[$c]) ?>"
                                   min="0" max="<?= $max ?>" step="0.5"
                                   data-max="<?= $max ?>" data-uid="<?= $uid ?>"
                                   onchange="recalcTotal('<?= $uid ?>')">
                        </td>
                        <?php endforeach; ?>
                        <td class="total-cell" id="tot_<?= $uid ?>"><?= fmtN($row['total']) ?></td>
                        <td class="no-print">
                            <input type="checkbox" name="absents[<?= $mid ?>][<?= $sid ?>]"
                                   class="form-check-input absent-cb" data-uid="<?= $uid ?>"
                                   onchange="toggleAbsent(this,'<?= $uid ?>')">
                        </td>
                    <?php else: ?>
                        <?php foreach (CC_CELLS_GEN as $c):
                            $max = CC_MAX_GEN[$c]; ?>
                        <td>
                            <input type="number" class="note-input"
                                   name="notes[<?= $mid ?>][<?= $sid ?>][<?= $c ?>]"
                                   value="0" min="0" max="<?= $max ?>" step="0.5"
                                   data-max="<?= $max ?>" data-uid="<?= $uid ?>"
                                   onchange="recalcTotal('<?= $uid ?>')">
                        </td>
                        <?php endforeach; ?>
                        <td class="total-cell" id="tot_<?= $uid ?>">0</td>
                        <td class="no-print">
                            <input type="checkbox" name="absents[<?= $mid ?>][<?= $sid ?>]"
                                   class="form-check-input absent-cb" data-uid="<?= $uid ?>"
                                   onchange="toggleAbsent(this,'<?= $uid ?>')">
                        </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
    </form>
    <?php endif; ?>
</div>
<script>
function recalcTotal(uid) {
    var inputs = document.querySelectorAll('[data-uid="' + uid + '"]');
    var tot = 0, hasOver = false;
    inputs.forEach(function(inp) {
        if (inp.type !== 'number') return;
        var v = parseFloat(inp.value) || 0;
        var max = parseFloat(inp.dataset.max);
        inp.classList.toggle('over', v > max);
        if (v > max) hasOver = true;
        tot += v;
    });
    var cell = document.getElementById('tot_' + uid);
    if (cell) { cell.textContent = Math.round(tot * 100) / 100; cell.style.color = (tot > 20 || hasOver) ? '#dc3545' : ''; }
}
function toggleAbsent(cb, uid) {
    var tr = document.getElementById('tr_' + uid);
    if (tr) tr.classList.toggle('absent-row', cb.checked);
    document.querySelectorAll('[data-uid="' + uid + '"]').forEach(function(inp) {
        if (inp.type === 'number') inp.disabled = cb.checked;
    });
}
</script>
<?php if (!empty($printData)): ?>
<script>window.addEventListener('load', () => window.print());</script>
<?php endif; ?>

<?php else: ?>
<!-- ════════════════════════════════════════════════════════════
     MODE NORMAL
     ════════════════════════════════════════════════════════════ -->
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container-fluid py-4 px-4">
    <h2 class="h4 fw-bold mb-4">
        <i class="bi bi-magic me-2 text-primary"></i>Générer CC1 &amp; CC2 Pratique depuis CC3 Théorique
    </h2>

    <?php if ($msg): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($erreur): ?>
        <div class="alert alert-danger rounded-3"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($erreur) ?></div>
    <?php endif; ?>

    <!-- ── Formulaire principal ──────────────────────────────── -->
    <form method="POST" id="mainForm">
        <?= csrfField() ?>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Groupe</label>
                        <select name="groupe_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">— Choisir un groupe —</option>
                            <?php foreach ($groupes as $g): ?>
                            <option value="<?= (int)$g['id'] ?>" <?= $groupeId === (int)$g['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($g['nom']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">CC à générer</label>
                        <div class="d-flex gap-4 mt-1">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="gen_cc1" id="gen_cc1" value="1" <?= $genCC1 ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold text-primary" for="gen_cc1">CC1 (CC3 − 1)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="gen_cc2" id="gen_cc2" value="1" <?= $genCC2 ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold text-success" for="gen_cc2">CC2 (CC3 + 1)</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" name="preview" value="1" class="btn btn-primary">
                            <i class="bi bi-magic me-1"></i>Générer CC1/CC2
                        </button>
                        <?php if ($groupeId > 0): ?>
                        <a href="?groupe_id=<?= $groupeId ?>&print=1&cc=1" target="_blank" class="btn btn-outline-primary btn-sm ms-1">
                            <i class="bi bi-printer me-1"></i>CC1
                        </a>
                        <a href="?groupe_id=<?= $groupeId ?>&print=1&cc=2" target="_blank" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-printer me-1"></i>CC2
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($groupeId > 0 && !empty($modInfo)): ?>
        <!-- ── Tableau CC3 éditable ──────────────────────────── -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 border-warning border-2" style="border:2px solid #ffc107 !important;">
            <div class="card-header bg-warning bg-opacity-10 py-2 px-4 d-flex justify-content-between align-items-center">
                <span class="fw-bold"><i class="bi bi-pencil-square me-2 text-warning"></i>Notes CC3 Théorique — modifiables</span>
                <span class="text-muted small">Modifiez les notes et/ou cochez les absents avant de générer</span>
            </div>
            <div class="card-body p-0">
            <?php foreach ($modInfo as $mid => $nomMod): ?>
            <div class="px-3 pt-3 pb-2">
                <div class="fw-semibold text-secondary small mb-1"><?= htmlspecialchars($nomMod) ?></div>
                <div class="table-responsive">
                    <table class="table table-bordered grille mb-0 bg-white">
                        <thead class="table-warning">
                            <tr>
                                <th class="name">NOM</th>
                                <th class="name">PRÉNOM</th>
                                <th>Note CC3 /20</th>
                                <th>Absent</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($roster as $sid => $stg):
                            $dbScore  = $cc3DB[$mid][$sid]   ?? null;
                            $curScore = $cc3Data[$mid][$sid] ?? null;
                            $isAbsent = !empty($cc3Absent[$mid][$sid]) || ($dbScore === null && $curScore === null);
                            $uid      = "cc3_{$mid}_{$sid}";
                        ?>
                        <tr class="<?= $isAbsent ? 'absent-row' : '' ?>" id="tr_<?= $uid ?>">
                            <td class="name"><?= htmlspecialchars($stg['nom']) ?></td>
                            <td class="name"><?= htmlspecialchars($stg['prenom']) ?></td>
                            <td>
                                <input type="number" class="note-input note-input-cc3"
                                       name="cc3[<?= $mid ?>][<?= $sid ?>]"
                                       value="<?= $curScore !== null ? fmtN($curScore) : ($dbScore !== null ? fmtN($dbScore) : '') ?>"
                                       min="0" max="20" step="0.5"
                                       placeholder="—"
                                       <?= $isAbsent ? 'disabled' : '' ?>>
                            </td>
                            <td>
                                <input type="checkbox"
                                       name="cc3_absent[<?= $mid ?>][<?= $sid ?>]"
                                       class="form-check-input absent-cb-cc3"
                                       data-uid="<?= $uid ?>"
                                       <?= $isAbsent ? 'checked' : '' ?>
                                       onchange="toggleAbsentCC3(this,'<?= $uid ?>')">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </form><!-- /mainForm -->

<?php
function renderEditableTable(array $preview, array $roster, array $modInfo, int $ccNum, int $groupeId): void {
    $key   = 'cc'.$ccNum;
    $abKey = 'cc'.$ccNum.'_absent';
    $color = $ccNum === 1 ? 'primary' : 'success';
    foreach ($preview as $mid => $ccData) {
        if (empty($ccData[$key]) && empty($ccData[$abKey])) continue;
        echo '<div class="card border-0 shadow-sm rounded-4 mb-3">';
        echo '<div class="card-header bg-white border-bottom py-2 px-4 fw-semibold">'.htmlspecialchars($modInfo[$mid] ?? '').'</div>';
        echo '<div class="card-body p-0"><div class="table-responsive">';
        echo '<table class="table table-bordered grille mb-0 bg-white">';
        echo '<thead class="table-'.$color.'"><tr>';
        echo '<th rowspan="2" class="name">NOM</th><th rowspan="2" class="name">PRÉNOM</th>';
        echo '<th colspan="2">PARTIE 1 (5 pts)</th><th colspan="2">PARTIE 2 (5 pts)</th>';
        echo '<th colspan="2">PARTIE 3 (5 pts)</th><th rowspan="2">PARTIE 4<br>(5 pts)</th>';
        echo '<th rowspan="2">TOTAL /20</th><th rowspan="2">Absent</th></tr>';
        echo '<tr><th>Q1</th><th>Q2</th><th>Q1</th><th>Q2</th><th>Q1</th><th>Q2</th></tr></thead><tbody>';
        foreach ($roster as $sid => $stg) {
            $d   = $ccData[$key][$sid]   ?? null;
            $abs = !empty($ccData[$abKey][$sid]);
            $uid = "prev{$ccNum}_{$mid}_{$sid}";
            echo '<tr class="'.($abs ? 'absent-row' : '').'" id="tr_'.$uid.'">';
            echo '<td class="name">'.htmlspecialchars($stg['nom']).'</td>';
            echo '<td class="name">'.htmlspecialchars($stg['prenom']).'</td>';
            if ($abs) {
                echo '<td colspan="7" class="text-center text-muted fst-italic">Absent</td>';
                echo '<td class="total-cell">Abs</td>';
                echo '<td><input type="checkbox" name="absents['.$mid.']['.$sid.']"'
                    .' class="form-check-input absent-cb" data-uid="'.$uid.'" checked'
                    .' onchange="toggleAbsent(this,\''.$uid.'\')"></td>';
            } elseif ($d) {
                foreach (CC_CELLS_GEN as $c) {
                    $max = CC_MAX_GEN[$c];
                    echo '<td><input type="number" class="note-input"'
                        .' name="notes['.$mid.']['.$sid.']['.$c.']"'
                        .' value="'.fmtN($d[$c]).'"'
                        .' min="0" max="'.$max.'" step="0.5"'
                        .' data-max="'.$max.'" data-uid="'.$uid.'"'
                        .' onchange="recalcTotal(\''.$uid.'\')"></td>';
                }
                echo '<td class="total-cell" id="tot_'.$uid.'">'.fmtN($d['total']).'</td>';
                echo '<td><input type="checkbox" name="absents['.$mid.']['.$sid.']"'
                    .' class="form-check-input absent-cb" data-uid="'.$uid.'"'
                    .' onchange="toggleAbsent(this,\''.$uid.'\')"></td>';
            } else {
                echo '<td colspan="7" class="text-muted">—</td><td class="total-cell">—</td><td></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></div></div>';
    }
}
?>

    <?php if (!empty($preview)): ?>

    <!-- CC1 -->
    <?php if ($genCC1): ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold text-primary mb-0"><i class="bi bi-1-circle me-2"></i>CC1 Pratique (CC3 − 1)</h5>
        <div class="d-flex gap-2">
            <button form="formCC1" type="submit" name="save_cc" value="1" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-floppy me-1"></i>Sauvegarder CC1
            </button>
            <button form="formCC1" type="submit" name="do_print" value="1" class="btn btn-primary btn-sm">
                <i class="bi bi-printer me-1"></i>Sauvegarder &amp; Imprimer CC1
            </button>
        </div>
    </div>
    <form method="POST" id="formCC1">
        <?= csrfField() ?>
        <input type="hidden" name="groupe_id" value="<?= $groupeId ?>">
        <input type="hidden" name="save_cc"   value="1">
        <?php renderEditableTable($preview, $roster, $modInfo, 1, $groupeId); ?>
    </form>
    <?php endif; ?>

    <!-- CC2 -->
    <?php if ($genCC2): ?>
    <div class="d-flex justify-content-between align-items-center mb-3 mt-4">
        <h5 class="fw-bold text-success mb-0"><i class="bi bi-2-circle me-2"></i>CC2 Pratique (CC3 + 1)</h5>
        <div class="d-flex gap-2">
            <button form="formCC2" type="submit" name="save_cc" value="2" class="btn btn-outline-success btn-sm">
                <i class="bi bi-floppy me-1"></i>Sauvegarder CC2
            </button>
            <button form="formCC2" type="submit" name="do_print" value="1" class="btn btn-success btn-sm">
                <i class="bi bi-printer me-1"></i>Sauvegarder &amp; Imprimer CC2
            </button>
        </div>
    </div>
    <form method="POST" id="formCC2">
        <?= csrfField() ?>
        <input type="hidden" name="groupe_id" value="<?= $groupeId ?>">
        <input type="hidden" name="save_cc"   value="2">
        <?php renderEditableTable($preview, $roster, $modInfo, 2, $groupeId); ?>
    </form>
    <?php endif; ?>

    <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function recalcTotal(uid) {
    var inputs = document.querySelectorAll('[data-uid="' + uid + '"]');
    var tot = 0, hasOver = false;
    inputs.forEach(function(inp) {
        if (inp.type !== 'number') return;
        var v = parseFloat(inp.value) || 0;
        var max = parseFloat(inp.dataset.max);
        inp.classList.toggle('over', v > max);
        if (v > max) hasOver = true;
        tot += v;
    });
    var cell = document.getElementById('tot_' + uid);
    if (cell) { cell.textContent = Math.round(tot * 100) / 100; cell.style.color = (tot > 20 || hasOver) ? '#dc3545' : ''; }
}
function toggleAbsent(cb, uid) {
    var tr = document.getElementById('tr_' + uid);
    if (tr) tr.classList.toggle('absent-row', cb.checked);
    document.querySelectorAll('[data-uid="' + uid + '"]').forEach(function(inp) {
        if (inp.type === 'number') inp.disabled = cb.checked;
    });
}
function toggleAbsentCC3(cb, uid) {
    var tr = document.getElementById('tr_' + uid);
    if (tr) tr.classList.toggle('absent-row', cb.checked);
    var row = tr ? tr.querySelectorAll('input[type="number"]') : [];
    row.forEach(function(inp) { inp.disabled = cb.checked; });
}
</script>
<?php endif; ?>
</body>
</html>
