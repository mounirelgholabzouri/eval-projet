<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$msg = ''; $erreur = '';

// ── Configuration des types ───────────────────────────────────
$TYPES = [
    'cc1_pratique'  => ['label' => 'CC1 Pratique',  'source' => 'pratique',  'numero' => 1, 'note_max' => 20, 'color' => 'primary', 'icon' => 'bi-1-circle-fill'],
    'cc2_pratique'  => ['label' => 'CC2 Pratique',  'source' => 'pratique',  'numero' => 2, 'note_max' => 20, 'color' => 'success', 'icon' => 'bi-2-circle-fill'],
    'cc3_theorique' => ['label' => 'CC3 Théorique', 'source' => 'theorique', 'note_max' => 20, 'color' => 'warning', 'icon' => 'bi-3-circle-fill'],
    'efm'           => ['label' => 'EFM',            'source' => 'efm',       'note_max' => 40, 'color' => 'danger',  'icon' => 'bi-award-fill'],
];
const CC_CELLS = ['p1q1','p1q2','p2q1','p2q2','p3q1','p3q2','p4'];
const CC_MAX   = ['p1q1'=>2.5,'p1q2'=>2.5,'p2q1'=>2.5,'p2q2'=>2.5,'p3q1'=>2.5,'p3q2'=>2.5,'p4'=>5.0];
const STEP     = 0.5;

// ── Helpers ───────────────────────────────────────────────────
function distribuer(float $total): array {
    $unit = (int)round($total / STEP);
    $maxU = array_map(fn($m) => (int)round($m / STEP), CC_MAX);
    $order = CC_CELLS; shuffle($order);
    $res = array_fill_keys(CC_CELLS, 0.0); $rem = $unit; $n = count($order);
    for ($i = 0; $i < $n; $i++) {
        $c = $order[$i];
        if ($i === $n - 1) { $val = max(0, min($rem, $maxU[$c])); }
        else {
            $fut = 0; foreach (array_slice($order, $i+1) as $x) $fut += $maxU[$x];
            $val = mt_rand(max(0, $rem-$fut), min($maxU[$c], $rem));
        }
        $res[$c] = round($val * STEP, 2); $rem -= $val;
    }
    if ($rem) $res['p4'] = round(min(5.0, max(0.0, $res['p4'] + $rem * STEP)), 2);
    return $res;
}
function snapNote(float $n): float { return max(0.0, min(20.0, round($n * 2) / 2)); }
function fmtN($v): string { if ($v===null||$v==='') return ''; $s=rtrim(rtrim(number_format((float)$v,2,'.',''  ),'0'),'.'); return $s===''?'0':$s; }
function getOrCreateEvalCC(PDO $pdo, int $mid, int $gid, int $num): int {
    $s = $pdo->prepare("SELECT id FROM evaluations WHERE module_id=? AND categorie='cc_pratique' AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.groupe_id'))=? AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.numero'))=? LIMIT 1");
    $s->execute([$mid,(string)$gid,(string)$num]);
    $r=$s->fetch(); if($r) return (int)$r['id'];
    $meta=json_encode(['numero'=>$num,'groupe_id'=>$gid],JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO evaluations (module_id,nom,type,categorie,duree_minutes,note_max,meta_json,actif) VALUES (?,'Contrôle pratique N°$num','pratique','cc_pratique',60,20,?,1)")->execute([$mid,$meta]);
    return (int)$pdo->lastInsertId();
}

// ── Paramètres ────────────────────────────────────────────────
$type      = $_GET['type'] ?? $_POST['type'] ?? 'cc1_pratique';
if (!array_key_exists($type, $TYPES)) $type = 'cc1_pratique';
$cfg       = $TYPES[$type];
$isPrat    = $cfg['source'] === 'pratique';
$categ     = $isPrat ? 'cc_pratique' : ($cfg['source'] === 'theorique' ? 'cc_theorique' : 'efm');
$groupeId  = (int)($_GET['groupe_id'] ?? $_POST['groupe_id'] ?? 0);
$moduleId  = (int)($_GET['module_id'] ?? $_POST['module_id'] ?? 0);
$printMode = isset($_GET['print']);
$groupes   = getGroupes();
$groupeNom = ''; foreach ($groupes as $g) if ((int)$g['id']===$groupeId) { $groupeNom=$g['nom']; break; }

// ── Modules disponibles pour ce type ─────────────────────────
$catList = $isPrat ? "'cc_pratique','cc_theorique'" : "'$categ'";
$modsQ   = $pdo->query("SELECT DISTINCT e.module_id AS id, m.nom FROM evaluations e JOIN modules m ON m.id=e.module_id WHERE e.categorie IN ($catList) ORDER BY m.nom");
$modulesAvail = []; foreach ($modsQ->fetchAll() as $m) $modulesAvail[(int)$m['id']] = $m['nom'];
$moduleNom = $modulesAvail[$moduleId] ?? '';

// ── Roster ────────────────────────────────────────────────────
$roster = [];
if ($groupeId > 0) {
    $r = $pdo->prepare("SELECT id, nom, prenom FROM stagiaires WHERE groupe_id=? ORDER BY nom, prenom");
    $r->execute([$groupeId]);
    foreach ($r->fetchAll() as $row) $roster[(int)$row['id']] = $row;
}

// ── Chargement des données depuis DB ─────────────────────────
$data       = [];
$evalId     = null;
$noteMaxMod = $cfg['note_max'];

function loadData(PDO $pdo, bool $isPrat, string $categ, int $moduleId, int $groupeId, int $numero, ?int &$evalId, int &$noteMaxMod): array {
    $data = [];
    if ($isPrat) {
        $s = $pdo->prepare("SELECT id FROM evaluations WHERE module_id=? AND categorie='cc_pratique' AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.groupe_id'))=? AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.numero'))=? LIMIT 1");
        $s->execute([$moduleId,(string)$groupeId,(string)$numero]);
        $ev = $s->fetch();
        if ($ev) {
            $evalId = (int)$ev['id'];
            $nt = $pdo->prepare("SELECT * FROM cc_pratique_notes WHERE evaluation_id=?");
            $nt->execute([$evalId]);
            foreach ($nt->fetchAll() as $row) $data[(int)$row['stagiaire_id']] = $row;
        }
    } else {
        $s = $pdo->prepare("SELECT id, note_max FROM evaluations WHERE module_id=? AND categorie=? LIMIT 1");
        $s->execute([$moduleId, $categ]);
        $ev = $s->fetch();
        if ($ev) { $evalId = (int)$ev['id']; $noteMaxMod = (int)$ev['note_max']; }
        if ($evalId) {
            $q = $pdo->prepare("SELECT id, stagiaire_id, score, pourcentage FROM sessions_eval WHERE evaluation_id=? AND groupe_id=? AND statut='termine' ORDER BY date_fin DESC");
            $q->execute([$evalId, $groupeId]);
            foreach ($q->fetchAll() as $row) { $sid=(int)$row['stagiaire_id']; if(!isset($data[$sid])) $data[$sid]=$row; }
        }
    }
    return $data;
}

if ($groupeId > 0 && $moduleId > 0)
    $data = loadData($pdo, $isPrat, $categ, $moduleId, $groupeId, $cfg['numero'] ?? 0, $evalId, $noteMaxMod);

// ── POST : Générer CC1/CC2 depuis CC3 ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    verifyCsrfToken();
    if (!$isPrat) { $erreur = "Génération uniquement pour CC1/CC2."; }
    elseif (!$groupeId || !$moduleId) { $erreur = "Sélectionnez groupe et module."; }
    else {
        $q = $pdo->prepare("SELECT s.stagiaire_id, s.score FROM sessions_eval s JOIN evaluations e ON e.id=s.evaluation_id WHERE s.groupe_id=? AND e.module_id=? AND e.categorie='cc_theorique' AND s.statut='termine' ORDER BY s.date_fin");
        $q->execute([$groupeId, $moduleId]);
        $cc3 = []; foreach ($q->fetchAll() as $r) $cc3[(int)$r['stagiaire_id']] = (float)$r['score'];
        if (empty($cc3)) { $erreur = "Aucune note CC3 disponible pour ce groupe/module."; }
        else {
            $delta = ($cfg['numero'] === 1) ? -1 : 1; $data = [];
            foreach ($cc3 as $sid => $s3) {
                $note = snapNote($s3 + $delta);
                $data[$sid] = array_merge(distribuer($note), ['total'=>$note,'absent'=>0,'stagiaire_id'=>$sid]);
            }
            $msg = count($data)." note(s) générée(s) depuis CC3 (non encore sauvegardées).";
        }
    }
}

// ── POST : Sauvegarder ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    verifyCsrfToken();
    $notesIn  = $_POST['notes']   ?? [];
    $absIn    = $_POST['absents'] ?? [];
    $doPrint  = isset($_POST['do_print']);
    $count    = 0; $ok = true;

    if (!$groupeId || !$moduleId) { $erreur = "Groupe et module requis."; $ok = false; }

    if ($ok && $isPrat) {
        $eid = getOrCreateEvalCC($pdo, $moduleId, $groupeId, $cfg['numero'] ?? 1);
        $up  = $pdo->prepare("INSERT INTO cc_pratique_notes (evaluation_id,stagiaire_id,p1q1,p1q2,p2q1,p2q2,p3q1,p3q2,p4,total,absent) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE p1q1=VALUES(p1q1),p1q2=VALUES(p1q2),p2q1=VALUES(p2q1),p2q2=VALUES(p2q2),p3q1=VALUES(p3q1),p3q2=VALUES(p3q2),p4=VALUES(p4),total=VALUES(total),absent=VALUES(absent)");
        foreach ($notesIn as $sid => $cells) {
            $sid=$sid=(int)$sid; $vals=[]; $tot=0;
            foreach (CC_CELLS as $c) {
                $v=max(0.0,min(CC_MAX[$c],round(round((float)str_replace(',','.',trim($cells[$c]??'0'))/STEP)*STEP,2)));
                $vals[]=$v; $tot+=$v;
            }
            $up->execute(array_merge([$eid,$sid],$vals,[round($tot,2),0])); $count++;
        }
        foreach ($absIn as $sid => $_)
            { $up->execute([$eid,(int)$sid,null,null,null,null,null,null,null,null,1]); $count++; }
    }

    if ($ok && !$isPrat) {
        if (!$evalId) {
            $ev=$pdo->prepare("SELECT id FROM evaluations WHERE module_id=? AND categorie=? LIMIT 1");
            $ev->execute([$moduleId,$categ]); $evR=$ev->fetch();
            if ($evR) $evalId=(int)$evR['id']; else { $erreur="Évaluation introuvable."; $ok=false; }
        }
        if ($ok) {
            foreach ($absIn as $sid => $_) {
                $pdo->prepare("DELETE FROM sessions_eval WHERE evaluation_id=? AND stagiaire_id=? AND groupe_id=?")->execute([$evalId,(int)$sid,$groupeId]); $count++;
            }
            foreach ($notesIn as $sid => $raw) {
                $sid=(int)$sid; $score=max(0.0,min((float)$noteMaxMod,(float)str_replace(',','.',trim($raw))));
                $pct=$noteMaxMod>0?round($score/$noteMaxMod*100,2):0;
                $ex=$pdo->prepare("SELECT id FROM sessions_eval WHERE evaluation_id=? AND stagiaire_id=? AND groupe_id=? ORDER BY date_fin DESC LIMIT 1");
                $ex->execute([$evalId,$sid,$groupeId]); $exR=$ex->fetch();
                if ($exR) $pdo->prepare("UPDATE sessions_eval SET score=?,pourcentage=?,statut='termine',date_fin=NOW() WHERE id=?")->execute([$score,$pct,(int)$exR['id']]);
                else       $pdo->prepare("INSERT INTO sessions_eval (evaluation_id,stagiaire_id,groupe_id,score,pourcentage,statut,date_debut,date_fin) VALUES (?,?,?,?,?,'termine',NOW(),NOW())")->execute([$evalId,$sid,$groupeId,$score,$pct]);
                $count++;
            }
        }
    }

    if ($ok) {
        $dest = "gestion_impression.php?type=$type&groupe_id=$groupeId&module_id=$moduleId" . ($doPrint ? '&print=1' : '&saved=1');
        header("Location: $dest"); exit;
    }
}

if (isset($_GET['saved'])) $msg = "Résultats sauvegardés avec succès.";

// Reload after save/generate (data already loaded above or set by generate)
$nbPres = count(array_filter($data, fn($r) => !(isset($r['absent']) && (int)$r['absent'] === 1) && !empty($r)));
$nbAbs  = count(array_filter($roster, fn($s, $sid) => !isset($data[$sid]) || (isset($data[$sid]['absent']) && (int)$data[$sid]['absent']===1), ARRAY_FILTER_USE_BOTH));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $printMode ? htmlspecialchars($cfg['label'].' — '.$groupeNom.' — '.$moduleNom) : 'Gestion et Impression' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <?php if (!$printMode): ?><link href="../assets/css/style.css" rel="stylesheet"><?php endif; ?>
    <style>
        .grille th,.grille td{text-align:center;vertical-align:middle;font-size:.82rem;padding:3px 6px}
        .grille td.name{text-align:left;white-space:nowrap}
        .note-inp{width:54px;text-align:center;border:1px solid #ced4da;border-radius:4px;padding:2px 4px;font-size:.82rem}
        .note-inp:focus{outline:none;border-color:#0d6efd;box-shadow:0 0 0 2px rgba(13,110,253,.15)}
        .note-inp.over{background:#ffdede;border-color:#dc3545}
        td.tot{font-weight:bold;background:#f8f9fa;min-width:50px}
        tr.abs-row td{opacity:.55}
        .type-tab{cursor:pointer}
        @page{margin:0;size:A4 landscape}
        @media print{
            .no-print{display:none !important}
            html,body{margin:0!important;padding:0!important;background:#fff!important;
                      width:297mm;height:210mm;overflow:hidden}
            .container-fluid{padding:5mm 6mm!important;width:297mm!important;box-sizing:border-box}
            .page-block{page-break-inside:avoid;margin-bottom:0}
            .grille{font-size:8.5px;width:100%}
            .grille th,.grille td{border:1px solid #000!important;padding:2px 3px;white-space:nowrap}
            .grille td.name{max-width:85px;overflow:hidden;text-overflow:ellipsis}
            .note-inp{border:none!important;box-shadow:none!important;
                      background:transparent!important;width:auto!important;padding:0}
            .print-header{margin-bottom:2mm;line-height:1.4;text-align:center;width:100%}
        .badge-cc{display:inline-block;padding:1mm 5mm;border-radius:3mm;font-size:14pt;font-weight:900;letter-spacing:1px;vertical-align:middle;margin-left:3mm}
        .badge-cc1{background:#0d6efd;color:#fff}
        .badge-cc2{background:#198754;color:#fff}
        .badge-cc3{background:#e67e00;color:#fff}
        .badge-efm{background:#dc3545;color:#fff}
            thead{display:table-header-group}
        }
    </style>
</head>
<body class="<?= $printMode ? '' : 'bg-light' ?>">

<?php if ($printMode): ?>
<!-- ═══════════════════════  IMPRESSION  ════════════════════════ -->
<div class="container-fluid py-3 px-4">
    <div class="d-flex gap-2 align-items-center mb-3 no-print flex-wrap">
        <a href="gestion_impression.php?type=<?= $type ?>&groupe_id=<?= $groupeId ?>&module_id=<?= $moduleId ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Retour
        </a>
        <button onclick="window.print()" class="btn btn-dark btn-sm">
            <i class="bi bi-printer me-1"></i>Imprimer
        </button>
        <button form="printSaveForm" type="submit" name="save" value="1" class="btn btn-success btn-sm">
            <i class="bi bi-floppy me-1"></i>Sauvegarder les modifications
        </button>
        <span class="text-muted small"><i class="bi bi-info-circle me-1"></i>Boîte d'impression : marges = <strong>Aucune</strong>, décocher <strong>En-têtes et pieds de page</strong></span>
    </div>

    <div class="print-header">
        <div style="font-size:13pt;font-weight:bold;letter-spacing:.3px">
            <?= htmlspecialchars(getEtablissementDefaut()) ?>
        </div>
        <div style="font-size:11pt;font-weight:600;margin-top:1mm">
            <?php
            $badgeMap = ['cc1_pratique'=>'cc1','cc2_pratique'=>'cc2','cc3_theorique'=>'cc3','efm'=>'efm'];
            $badgeCls = $badgeMap[$type] ?? '';
            ?>
            <?= htmlspecialchars($cfg['label']) ?>
            <?php if ($badgeCls): ?>
            <span class="badge-cc badge-<?= $badgeCls ?>"><?= strtoupper(str_replace('_','',$badgeCls)) ?></span>
            <?php endif; ?>
        </div>
        <div style="font-size:10pt;font-weight:normal;margin-top:1mm">
            <?= htmlspecialchars($moduleNom) ?> &nbsp;|&nbsp; Groupe : <strong><?= htmlspecialchars($groupeNom) ?></strong>
        </div>
        <hr style="border:1.5px solid #000;margin:2mm 0 2mm">
    </div>

    <form method="POST" id="printSaveForm">
        <?= csrfField() ?>
        <input type="hidden" name="type"       value="<?= $type ?>">
        <input type="hidden" name="groupe_id"  value="<?= $groupeId ?>">
        <input type="hidden" name="module_id"  value="<?= $moduleId ?>">

    <div class="page-block">
    <?php if ($isPrat): ?>
        <!-- Grille pratique -->
        <?php
        $nbPres2 = 0; $nbAbs2 = 0;
        foreach ($roster as $sid => $s) {
            $r = $data[$sid] ?? null;
            if ($r && (int)($r['absent']??0)===1) $nbAbs2++; elseif($r) $nbPres2++;
            else $nbAbs2++;
        }
        ?>
        <div class="d-flex gap-2 mb-2 no-print">
            <span class="badge bg-success">Présents : <?= $nbPres2 ?></span>
            <span class="badge bg-danger">Absents : <?= $nbAbs2 ?></span>
        </div>
        <div class="table-responsive">
        <table class="table table-bordered grille mb-0 bg-white">
            <thead class="table-light">
                <tr>
                    <th rowspan="2" class="name">NOM</th><th rowspan="2" class="name">PRÉNOM</th>
                    <th colspan="2">PARTIE 1 (5 pts)</th><th colspan="2">PARTIE 2 (5 pts)</th>
                    <th colspan="2">PARTIE 3 (5 pts)</th><th rowspan="2">P4<br>(5 pts)</th>
                    <th rowspan="2">TOTAL<br>/20</th>
                    <th rowspan="2" class="no-print">Absent</th>
                </tr>
                <tr><th>Q1</th><th>Q2</th><th>Q1</th><th>Q2</th><th>Q1</th><th>Q2</th></tr>
            </thead>
            <tbody>
            <?php foreach ($roster as $sid => $stg):
                $row=$data[$sid]??null; $abs=$row&&(int)($row['absent']??0)===1; $uid="p_{$sid}"; ?>
            <tr class="<?= $abs?'abs-row':'' ?>" id="tr_<?= $uid ?>">
                <td class="name"><?= htmlspecialchars($stg['nom']) ?></td>
                <td class="name"><?= htmlspecialchars($stg['prenom']) ?></td>
                <?php if ($abs): ?>
                    <td colspan="7" class="text-muted fst-italic text-center">Absent</td>
                    <td class="tot">Abs</td>
                    <td class="no-print"><input type="checkbox" class="form-check-input" name="absents[<?= $sid ?>]" data-uid="<?= $uid ?>" checked onchange="toggleAbs(this,'<?= $uid ?>')"></td>
                <?php else: ?>
                    <?php foreach (CC_CELLS as $c): $max=CC_MAX[$c]; ?>
                    <td><input type="number" class="note-inp" name="notes[<?= $sid ?>][<?= $c ?>]" value="<?= fmtN($row[$c]??null) ?>" min="0" max="<?= $max ?>" step="0.5" data-max="<?= $max ?>" data-uid="<?= $uid ?>" onchange="recalc('<?= $uid ?>')"></td>
                    <?php endforeach; ?>
                    <td class="tot" id="tot_<?= $uid ?>"><?= fmtN($row['total']??null) ?></td>
                    <td class="no-print"><input type="checkbox" class="form-check-input" name="absents[<?= $sid ?>]" data-uid="<?= $uid ?>" onchange="toggleAbs(this,'<?= $uid ?>')"></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <!-- Tableau théorique / EFM -->
        <table class="table table-bordered grille mb-0 bg-white">
            <thead class="table-light">
                <tr>
                    <th class="name">NOM</th><th class="name">PRÉNOM</th>
                    <th>Note /<?= $noteMaxMod ?></th><th>%</th><th>Mention</th>
                    <th class="no-print">Absent</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($roster as $sid => $stg):
                $row=$data[$sid]??null; $abs=!$row; $uid="t_{$sid}"; ?>
            <tr class="<?= $abs?'abs-row':'' ?>" id="tr_<?= $uid ?>">
                <td class="name"><?= htmlspecialchars($stg['nom']) ?></td>
                <td class="name"><?= htmlspecialchars($stg['prenom']) ?></td>
                <?php if ($abs): ?>
                    <td colspan="3" class="text-muted fst-italic text-center">Absent</td>
                    <td class="no-print"><input type="checkbox" class="form-check-input" name="absents[<?= $sid ?>]" data-uid="<?= $uid ?>" checked onchange="toggleAbs(this,'<?= $uid ?>')"></td>
                <?php else:
                    $ment=getMention((float)($row['pourcentage']??0)); ?>
                    <td><input type="number" class="note-inp" name="notes[<?= $sid ?>]" value="<?= fmtN($row['score']??null) ?>" min="0" max="<?= $noteMaxMod ?>" step="0.5" data-max="<?= $noteMaxMod ?>" data-uid="<?= $uid ?>" onchange="recalcPct('<?= $uid ?>',<?= $noteMaxMod ?>)"></td>
                    <td id="pct_<?= $uid ?>"><?= fmtN($row['pourcentage']??null) ?>%</td>
                    <td><span class="badge bg-<?= $ment['class'] ?>" id="ment_<?= $uid ?>"><?= $ment['label'] ?></span></td>
                    <td class="no-print"><input type="checkbox" class="form-check-input" name="absents[<?= $sid ?>]" data-uid="<?= $uid ?>" onchange="toggleAbs(this,'<?= $uid ?>')"></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div><!-- /page-block -->
    </form>
</div>
<script>
window.addEventListener('load', function() {
    // Ajuste le zoom pour tenir sur une seule page A4 paysage
    var body   = document.body;
    var pw     = 297 * 3.7795; // 297mm en px (96dpi)
    var ph     = 210 * 3.7795; // 210mm en px
    var bw     = body.scrollWidth;
    var bh     = body.scrollHeight;
    var scaleW = pw / bw;
    var scaleH = ph / bh;
    var scale  = Math.min(scaleW, scaleH, 1);
    if (scale < 0.99) {
        body.style.transformOrigin = 'top left';
        body.style.transform = 'scale(' + scale + ')';
        body.style.width = (100 / scale) + '%';
    }
    setTimeout(function() { window.print(); }, 350);
});
</script>

<?php else: ?>
<!-- ═══════════════════════  MODE NORMAL  ════════════════════════ -->
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container-fluid py-4 px-4">
    <h2 class="h4 fw-bold mb-3">
        <i class="bi bi-clipboard2-data me-2 text-primary"></i>Gestion et Impression — CC &amp; EFM
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

    <!-- ── Onglets type ────────────────────────────────────────── -->
    <ul class="nav nav-pills mb-3 flex-wrap gap-1">
        <?php foreach ($TYPES as $tk => $tc): ?>
        <li class="nav-item">
            <a class="nav-link <?= $tk===$type?'active bg-'.$tc['color']:'' ?>"
               href="?type=<?= $tk ?>&groupe_id=<?= $groupeId ?>&module_id=<?= $moduleId ?>">
                <i class="bi <?= $tc['icon'] ?> me-1"></i><?= htmlspecialchars($tc['label']) ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- ── Filtres ────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-end" id="filterForm">
                <input type="hidden" name="type" value="<?= $type ?>">
                <div class="col-md-4 col-lg-3">
                    <label class="form-label fw-semibold small mb-1">Groupe</label>
                    <select name="groupe_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0">— Choisir un groupe —</option>
                        <?php foreach ($groupes as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= $groupeId===(int)$g['id']?'selected':'' ?>><?= htmlspecialchars($g['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($groupeId > 0): ?>
                <div class="col-md-5 col-lg-4">
                    <label class="form-label fw-semibold small mb-1">Module</label>
                    <select name="module_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0">— Tous / Choisir —</option>
                        <?php foreach ($modulesAvail as $mid => $mnom): ?>
                        <option value="<?= $mid ?>" <?= $moduleId===$mid?'selected':'' ?>><?= htmlspecialchars($mnom) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if ($groupeId > 0 && $moduleId > 0): ?>
                <div class="col-auto ms-auto d-flex gap-2">
                    <?php if ($isPrat): ?>
                    <button form="genForm" type="submit" name="generate" value="1" class="btn btn-sm btn-outline-warning">
                        <i class="bi bi-magic me-1"></i>Générer depuis CC3
                        <span class="badge bg-warning text-dark ms-1"><?= $cfg['label']==='CC1 Pratique'?'CC3−1':'CC3+1' ?></span>
                    </button>
                    <?php endif; ?>
                    <a href="?type=<?= $type ?>&groupe_id=<?= $groupeId ?>&module_id=<?= $moduleId ?>&print=1" target="_blank" class="btn btn-sm btn-dark">
                        <i class="bi bi-printer me-1"></i>Imprimer
                    </a>
                </div>
                <?php endif; ?>
            </form>
            <!-- Formulaire generate séparé -->
            <form method="POST" id="genForm" style="display:none">
                <?= csrfField() ?>
                <input type="hidden" name="type"      value="<?= $type ?>">
                <input type="hidden" name="groupe_id" value="<?= $groupeId ?>">
                <input type="hidden" name="module_id" value="<?= $moduleId ?>">
            </form>
        </div>
    </div>

    <?php if ($groupeId === 0): ?>
    <div class="alert alert-info rounded-3"><i class="bi bi-arrow-up-circle me-2"></i>Sélectionnez un groupe pour commencer.</div>
    <?php elseif ($moduleId === 0): ?>
    <div class="alert alert-info rounded-3"><i class="bi bi-arrow-up-circle me-2"></i>Sélectionnez un module.</div>
    <?php else: ?>

    <!-- ── Tableau résultats ────────────────────────────────────── -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-bottom py-2 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="fw-semibold">
                <span class="badge bg-<?= $cfg['color'] ?> me-2"><?= htmlspecialchars($cfg['label']) ?></span>
                <?= htmlspecialchars($moduleNom) ?>
                <span class="text-muted small ms-2">— <?= htmlspecialchars($groupeNom) ?></span>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <?php if (!empty($roster)): ?>
                <span class="badge bg-success">Présents : <?= $nbPres ?></span>
                <span class="badge bg-danger">Absents : <?= $nbAbs ?></span>
                <span class="badge bg-secondary">Effectif : <?= count($roster) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="card-body p-0">
        <?php if (empty($roster)): ?>
            <div class="p-4 text-muted text-center"><i class="bi bi-inbox me-2"></i>Aucun stagiaire dans ce groupe.</div>
        <?php else: ?>
        <form method="POST" id="saveForm">
            <?= csrfField() ?>
            <input type="hidden" name="type"      value="<?= $type ?>">
            <input type="hidden" name="groupe_id" value="<?= $groupeId ?>">
            <input type="hidden" name="module_id" value="<?= $moduleId ?>">

            <div class="table-responsive">
            <?php if ($isPrat): ?>
            <table class="table table-hover table-bordered grille mb-0 bg-white">
                <thead class="table-<?= $cfg['color'] ?> bg-opacity-50">
                    <tr>
                        <th rowspan="2" class="name" style="min-width:110px">NOM</th>
                        <th rowspan="2" class="name" style="min-width:100px">PRÉNOM</th>
                        <th colspan="2">PARTIE 1<small class="d-block">(5 pts)</small></th>
                        <th colspan="2">PARTIE 2<small class="d-block">(5 pts)</small></th>
                        <th colspan="2">PARTIE 3<small class="d-block">(5 pts)</small></th>
                        <th rowspan="2">P4<small class="d-block">(5 pts)</small></th>
                        <th rowspan="2">TOTAL<small class="d-block">/20</small></th>
                        <th rowspan="2">Absent</th>
                    </tr>
                    <tr><th>Q1</th><th>Q2</th><th>Q1</th><th>Q2</th><th>Q1</th><th>Q2</th></tr>
                </thead>
                <tbody>
                <?php foreach ($roster as $sid => $stg):
                    $row=$data[$sid]??null; $abs=$row&&(int)($row['absent']??0)===1; $uid="n_{$sid}"; ?>
                <tr class="<?= $abs?'abs-row':'' ?>" id="tr_<?= $uid ?>">
                    <td class="name"><?= htmlspecialchars($stg['nom']) ?></td>
                    <td class="name"><?= htmlspecialchars($stg['prenom']) ?></td>
                    <?php if ($abs): ?>
                        <td colspan="7" class="text-center text-muted fst-italic">Absent</td>
                        <td class="tot">Abs</td>
                        <td><input type="checkbox" class="form-check-input" name="absents[<?= $sid ?>]" data-uid="<?= $uid ?>" checked onchange="toggleAbs(this,'<?= $uid ?>')"></td>
                    <?php else: ?>
                        <?php foreach (CC_CELLS as $c): $max=CC_MAX[$c]; ?>
                        <td><input type="number" class="note-inp" name="notes[<?= $sid ?>][<?= $c ?>]" value="<?= fmtN($row[$c]??null) ?>" min="0" max="<?= $max ?>" step="0.5" data-max="<?= $max ?>" data-uid="<?= $uid ?>" onchange="recalc('<?= $uid ?>')"></td>
                        <?php endforeach; ?>
                        <td class="tot" id="tot_<?= $uid ?>"><?= fmtN($row['total']??null)?:'—' ?></td>
                        <td><input type="checkbox" class="form-check-input" name="absents[<?= $sid ?>]" data-uid="<?= $uid ?>" onchange="toggleAbs(this,'<?= $uid ?>')"></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <table class="table table-hover table-bordered grille mb-0 bg-white">
                <thead class="table-<?= $cfg['color'] ?> bg-opacity-50">
                    <tr>
                        <th class="name" style="min-width:130px">NOM &amp; PRÉNOM</th>
                        <th>Note /<?= $noteMaxMod ?></th>
                        <th>%</th>
                        <th>Mention</th>
                        <th>Absent</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($roster as $sid => $stg):
                    $row=$data[$sid]??null; $abs=!$row; $uid="t_{$sid}";
                    $ment=$row?getMention((float)($row['pourcentage']??0)):null; ?>
                <tr class="<?= $abs?'abs-row':'' ?>" id="tr_<?= $uid ?>">
                    <td class="name text-start"><?= htmlspecialchars($stg['nom'].' '.$stg['prenom']) ?></td>
                    <?php if ($abs): ?>
                        <td colspan="3" class="text-muted fst-italic text-center">Absent</td>
                        <td><input type="checkbox" class="form-check-input" name="absents[<?= $sid ?>]" data-uid="<?= $uid ?>" checked onchange="toggleAbs(this,'<?= $uid ?>')"></td>
                    <?php else: ?>
                        <td><input type="number" class="note-inp" name="notes[<?= $sid ?>]" value="<?= fmtN($row['score']??null) ?>" min="0" max="<?= $noteMaxMod ?>" step="0.5" data-max="<?= $noteMaxMod ?>" data-uid="<?= $uid ?>" onchange="recalcPct('<?= $uid ?>',<?= $noteMaxMod ?>)"></td>
                        <td id="pct_<?= $uid ?>"><?= fmtN($row['pourcentage']??null) ?>%</td>
                        <td><span class="badge bg-<?= $ment['class'] ?>" id="ment_<?= $uid ?>"><?= $ment['label'] ?></span></td>
                        <td><input type="checkbox" class="form-check-input" name="absents[<?= $sid ?>]" data-uid="<?= $uid ?>" onchange="toggleAbs(this,'<?= $uid ?>')"></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            </div><!-- /table-responsive -->

            <div class="p-3 border-top d-flex gap-2 flex-wrap">
                <button type="submit" name="save" value="1" class="btn btn-success">
                    <i class="bi bi-floppy me-1"></i>Sauvegarder
                </button>
                <button type="submit" name="save" value="1" form="saveForm" onclick="document.getElementById('doPrint').value='1'" class="btn btn-primary">
                    <i class="bi bi-printer me-1"></i>Sauvegarder &amp; Imprimer
                </button>
                <input type="hidden" name="do_print" id="doPrint" value="0">
            </div>
        </form>
        <?php endif; ?>
        </div><!-- /card-body -->
    </div>

    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>

<script>
function recalc(uid) {
    var inp = document.querySelectorAll('[data-uid="'+uid+'"][type="number"]');
    var tot=0, over=false;
    inp.forEach(function(i){
        var v=parseFloat(i.value)||0, m=parseFloat(i.dataset.max);
        i.classList.toggle('over',v>m); if(v>m) over=true; tot+=v;
    });
    var c=document.getElementById('tot_'+uid);
    if(c){c.textContent=Math.round(tot*100)/100; c.style.color=(tot>20||over)?'#dc3545':'';}
}
function recalcPct(uid, max) {
    var inp=document.querySelector('[data-uid="'+uid+'"][type="number"]');
    if(!inp) return;
    var v=parseFloat(inp.value)||0, pct=max>0?Math.round(v/max*10000)/100:0;
    var pc=document.getElementById('pct_'+uid); if(pc) pc.textContent=pct+'%';
    var mentions=[
        [90,'Excellent','success'],[75,'Très bien','success'],
        [60,'Bien','primary'],[50,'Passable','warning'],[0,'Insuffisant','danger']
    ];
    var m=mentions.find(function(x){return pct>=x[0];})||mentions[mentions.length-1];
    var mb=document.getElementById('ment_'+uid);
    if(mb){mb.textContent=m[1]; mb.className='badge bg-'+m[2];}
}
function toggleAbs(cb, uid) {
    var tr=document.getElementById('tr_'+uid); if(tr) tr.classList.toggle('abs-row',cb.checked);
    document.querySelectorAll('[data-uid="'+uid+'"][type="number"]').forEach(function(i){i.disabled=cb.checked;});
}
</script>
</body>
</html>
