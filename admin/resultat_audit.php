<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

// ── Types d'audit ────────────────────────────────────────────
$AUDIT_TYPES = [
    'cc1_pratique'  => ['label' => 'CC1 Pratique',  'source' => 'pratique',  'numero' => 1, 'note_max' => 20, 'icon' => 'bi-grid-3x3-gap'],
    'cc2_pratique'  => ['label' => 'CC2 Pratique',  'source' => 'pratique',  'numero' => 2, 'note_max' => 20, 'icon' => 'bi-grid-3x3-gap'],
    'cc3_theorique' => ['label' => 'CC3 Théorique', 'source' => 'theorique', 'note_max' => 20, 'icon' => 'bi-ui-checks'],
    'efm'           => ['label' => 'EFM',           'source' => 'efm',       'note_max' => 40, 'icon' => 'bi-award'],
];

$type = $_GET['type'] ?? 'efm';
if (!isset($AUDIT_TYPES[$type])) $type = 'efm';
$cfg = $AUDIT_TYPES[$type];

$groupeId  = (int)($_GET['groupe_id'] ?? 0);
$printMode = isset($_GET['print']);
$groupes   = getGroupes();

$CC_CELLS = ['p1q1','p1q2','p2q1','p2q2','p3q1','p3q2','p4'];

function fmtNote($v): string {
    if ($v === null || $v === '') return '—';
    $v = (float)$v;
    $s = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

// ── Chargement des données ───────────────────────────────────
$groupeNom = '';
$roster    = [];   // sid => ['nom','prenom']
$modules   = [];   // module_id => ['nom','note_max','students'=>[sid=>data],'present'=>[sid=>true],'absent'=>[sid=>true]]

if ($groupeId > 0) {
    foreach ($groupes as $g) {
        if ((int)$g['id'] === $groupeId) { $groupeNom = $g['nom']; break; }
    }

    $st = $pdo->prepare("SELECT id, nom, prenom FROM stagiaires WHERE groupe_id = ? ORDER BY nom, prenom");
    $st->execute([$groupeId]);
    foreach ($st->fetchAll() as $r) {
        $roster[(int)$r['id']] = ['nom' => $r['nom'], 'prenom' => $r['prenom']];
    }

    if ($cfg['source'] === 'pratique') {
        // Grilles CC pratique du groupe pour le N° demandé
        $evRows = $pdo->query("
            SELECT e.id, e.module_id, e.note_max, e.meta_json, m.nom AS module_nom
            FROM evaluations e JOIN modules m ON m.id = e.module_id
            WHERE e.categorie = 'cc_pratique'
            ORDER BY m.nom
        ")->fetchAll();

        foreach ($evRows as $ev) {
            $meta = json_decode($ev['meta_json'] ?? '{}', true) ?: [];
            if ((int)($meta['groupe_id'] ?? 0) !== $groupeId) continue;
            if ((int)($meta['numero'] ?? 1) !== $cfg['numero'])  continue;

            $mid = (int)$ev['module_id'];
            if (!isset($modules[$mid])) {
                $modules[$mid] = ['nom' => $ev['module_nom'], 'note_max' => (int)$ev['note_max'],
                                  'students' => [], 'present' => [], 'absent' => []];
            }
            $nt = $pdo->prepare("SELECT * FROM cc_pratique_notes WHERE evaluation_id = ?");
            $nt->execute([(int)$ev['id']]);
            foreach ($nt->fetchAll() as $row) {
                $sid = (int)$row['stagiaire_id'];
                if (!isset($roster[$sid])) continue;
                $modules[$mid]['students'][$sid] = $row;
                if ((int)$row['absent'] === 1)        $modules[$mid]['absent'][$sid]  = true;
                elseif ($row['total'] !== null)        $modules[$mid]['present'][$sid] = true;
            }
        }
    } else {
        // CC théorique ou EFM : sessions terminées
        $categorie = $cfg['source'] === 'efm' ? 'efm' : 'cc_theorique';
        $q = $pdo->prepare("
            SELECT s.stagiaire_id, s.score, s.pourcentage, s.date_fin,
                   e.module_id, e.note_max, m.nom AS module_nom
            FROM sessions_eval s
            JOIN evaluations e ON e.id = s.evaluation_id
            JOIN modules m     ON m.id = e.module_id
            WHERE s.groupe_id = ? AND s.statut = 'termine' AND e.categorie = ?
            ORDER BY e.module_id, s.date_fin
        ");
        $q->execute([$groupeId, $categorie]);
        foreach ($q->fetchAll() as $row) {
            $sid = $row['stagiaire_id'] !== null ? (int)$row['stagiaire_id'] : 0;
            if ($sid <= 0 || !isset($roster[$sid])) continue;
            $mid = (int)$row['module_id'];
            if (!isset($modules[$mid])) {
                $modules[$mid] = ['nom' => $row['module_nom'], 'note_max' => (int)$row['note_max'],
                                  'students' => [], 'present' => [], 'absent' => []];
            }
            // dernière session gagne (ordre chronologique)
            $modules[$mid]['students'][$sid] = $row;
            $modules[$mid]['present'][$sid]  = true;
        }
        // absents = roster sans session
        foreach ($modules as $mid => &$mod) {
            foreach ($roster as $sid => $stg) {
                if (!isset($mod['present'][$sid])) $mod['absent'][$sid] = true;
            }
        }
        unset($mod);
    }
    ksort($modules);
}

$pageTitle = 'AUDIT ' . $cfg['label'] . ($groupeNom ? ' — ' . $groupeNom : '');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .audit-mod { margin-bottom: 2rem; page-break-inside: avoid; }
        .grille th, .grille td { text-align: center; vertical-align: middle; }
        .grille td.name { text-align: left; white-space: nowrap; }
        @page { margin: 0; }
        @media print {
            .no-print { display: none !important; }
            html, body { margin: 0 !important; background: #fff !important; }
            .container-fluid { padding: 12mm 10mm !important; }
            .grille { font-size: 11px; }
            .grille th, .grille td { border: 1px solid #000 !important; padding: 3px 5px; }
        }
    </style>
</head>
<body class="<?= $printMode ? '' : 'bg-light' ?>">
<?php if (!$printMode): ?>
<?php include __DIR__ . '/partials/navbar.php'; ?>
<?php endif; ?>

<div class="container-fluid py-4 px-4">

<?php if (!$printMode): ?>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print">
        <h2 class="h4 fw-bold mb-0"><i class="bi <?= $cfg['icon'] ?> me-2 text-primary"></i>AUDIT <?= htmlspecialchars($cfg['label']) ?></h2>
        <?php if ($groupeId > 0 && !empty($modules)): ?>
        <a href="?type=<?= $type ?>&groupe_id=<?= $groupeId ?>&print=1" target="_blank" class="btn btn-dark">
            <i class="bi bi-printer me-2"></i>Imprimer l'état
        </a>
        <?php endif; ?>
    </div>

    <!-- Onglets de type -->
    <ul class="nav nav-pills mb-3 no-print">
        <?php foreach ($AUDIT_TYPES as $tk => $tc): ?>
        <li class="nav-item">
            <a class="nav-link <?= $tk === $type ? 'active' : '' ?>"
               href="?type=<?= $tk ?><?= $groupeId ? '&groupe_id='.$groupeId : '' ?>">
                <i class="bi <?= $tc['icon'] ?> me-1"></i><?= htmlspecialchars($tc['label']) ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <div class="card border-0 shadow-sm rounded-3 mb-4 no-print">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="type" value="<?= $type ?>">
                <div class="col-md-6 col-lg-4">
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
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="mb-4">
        <h3 class="h5 fw-bold mb-1"><?= htmlspecialchars(getEtablissementDefaut()) ?></h3>
        <div class="text-muted"><?= htmlspecialchars($cfg['label']) ?> — Groupe <strong><?= htmlspecialchars($groupeNom) ?></strong></div>
    </div>
<?php endif; ?>

<?php if ($groupeId === 0): ?>
    <div class="alert alert-info rounded-3 no-print"><i class="bi bi-info-circle me-2"></i>Sélectionnez un groupe pour afficher l'audit.</div>
<?php elseif (empty($modules)): ?>
    <div class="alert alert-light border"><i class="bi bi-inbox me-2"></i>Aucune donnée <?= htmlspecialchars($cfg['label']) ?> pour ce groupe.</div>
<?php else: ?>

    <?php foreach ($modules as $mid => $mod):
        $nbPres = count($mod['present']); $nbAbs = count($mod['absent']);
    ?>
    <div class="audit-mod">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2 flex-wrap gap-2">
            <h5 class="h6 fw-bold mb-0"><?= htmlspecialchars($mod['nom']) ?></h5>
            <div class="small">
                <span class="badge bg-success">Présents : <?= $nbPres ?></span>
                <span class="badge bg-danger">Absents : <?= $nbAbs ?></span>
                <span class="badge bg-secondary">Effectif : <?= count($roster) ?></span>
            </div>
        </div>

        <?php if ($cfg['source'] === 'pratique'): ?>
        <!-- Grille pratique (format image) -->
        <div class="table-responsive">
            <table class="table table-bordered grille bg-white mb-0">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2">NOM</th><th rowspan="2">PRENOM</th>
                        <th colspan="2">PARTIE 1 (5 pts)</th>
                        <th colspan="2">PARTIE 2 (5 pts)</th>
                        <th colspan="2">PARTIE 3 (5 pts)</th>
                        <th rowspan="2">PARTIE 4<br>(5 pts)</th>
                        <th rowspan="2">TOTAL<br>/20</th>
                    </tr>
                    <tr>
                        <th>Q1</th><th>Q2</th><th>Q1</th><th>Q2</th><th>Q1</th><th>Q2</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($roster as $sid => $stg):
                    $row = $mod['students'][$sid] ?? null;
                    $isAbs = $row ? (int)$row['absent'] === 1 : false;
                ?>
                    <tr class="<?= $isAbs ? 'table-secondary' : '' ?>">
                        <td class="name"><?= htmlspecialchars($stg['nom']) ?></td>
                        <td class="name"><?= htmlspecialchars($stg['prenom']) ?></td>
                        <?php foreach ($CC_CELLS as $c): ?>
                        <td><?= $isAbs ? 'Abs' : ($row ? fmtNote($row[$c]) : '—') ?></td>
                        <?php endforeach; ?>
                        <td class="fw-bold bg-light"><?= $isAbs ? 'Abs' : ($row ? fmtNote($row['total']) : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php else: ?>
        <!-- Notes théorique / EFM -->
        <div class="table-responsive">
            <table class="table table-bordered grille bg-white mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="name">Nom &amp; Prénom</th>
                        <th>Note /<?= (int)$mod['note_max'] ?></th>
                        <th>%</th>
                        <th>Mention</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($roster as $sid => $stg):
                    $row = $mod['students'][$sid] ?? null;
                    if ($row) { $ment = getMention((float)$row['pourcentage']); }
                ?>
                    <tr class="<?= $row ? '' : 'table-secondary' ?>">
                        <td class="name"><?= htmlspecialchars($stg['nom'] . ' ' . $stg['prenom']) ?></td>
                        <td class="fw-bold"><?= $row ? fmtNote($row['score']) : '—' ?></td>
                        <td><?= $row ? fmtNote($row['pourcentage']) . '%' : '—' ?></td>
                        <td><?php if ($row): ?><span class="badge bg-<?= $ment['class'] ?>"><?= $ment['label'] ?></span><?php else: ?>—<?php endif; ?></td>
                        <td><?php if ($row): ?><span class="badge bg-success">Présent</span><?php else: ?><span class="badge bg-danger">Absent</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

<?php endif; ?>

</div>

<?php if (!$printMode): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php else: ?>
<script>window.addEventListener('load', () => window.print());</script>
<?php endif; ?>
</body>
</html>
