<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/spreadsheet.php';

$pdo = getDB();
$msg = ''; $erreur = '';

// ── Configuration des types ───────────────────────────────────
$TYPES = [
    'cc1_pratique'  => ['label' => 'CC1 Pratique',  'source' => 'pratique',  'numero' => 1, 'note_max' => 20, 'color' => 'primary', 'icon' => 'bi-1-circle-fill'],
    'cc2_pratique'  => ['label' => 'CC2 Pratique',  'source' => 'pratique',  'numero' => 2, 'note_max' => 20, 'color' => 'success', 'icon' => 'bi-2-circle-fill'],
    'cc3_pratique'  => ['label' => 'CC3 Pratique',  'source' => 'pratique',  'numero' => 3, 'note_max' => 20, 'color' => 'warning', 'icon' => 'bi-3-circle-fill'],
    'efm'           => ['label' => 'EFM',            'source' => 'efm',       'note_max' => 40, 'color' => 'danger',  'icon' => 'bi-award-fill'],
];
const CC_CELLS = ['p1q1','p1q2','p2q1','p2q2','p3q1','p3q2','p4'];
const CC_MAX   = ['p1q1'=>2.5,'p1q2'=>2.5,'p2q1'=>2.5,'p2q2'=>2.5,'p3q1'=>2.5,'p3q2'=>2.5,'p4'=>5.0];
const STEP     = 0.5;
// Modèle d'import des totaux CC1/CC2 pratique (ordre figé)
const CC_IMPORT_HEADERS = ['ID', 'NOM', 'PRENOM', 'TOTAL', 'ABSENT'];

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
const CATEG_SQL = [
    'cc_pratique'  => "e.type = 'cc' AND e.mode = 'pratique'",
    'cc_theorique' => "e.type = 'cc' AND e.mode = 'theorique'",
    'efm'          => "e.type = 'efm'",
];
function findEvalCC(PDO $pdo, int $mid, int $gid, int $num): ?int {
    $s = $pdo->prepare("SELECT e.id FROM evaluations e WHERE e.module_id=? AND " . CATEG_SQL['cc_pratique'] . " AND e.numero=? AND JSON_UNQUOTE(JSON_EXTRACT(e.meta_json,'$.groupe_id'))=? LIMIT 1");
    $s->execute([$mid, $num, (string)$gid]);
    $id = $s->fetchColumn();
    return $id ? (int)$id : null;
}
function getOrCreateEvalCC(PDO $pdo, int $mid, int $gid, int $num): int {
    return findEvalCC($pdo, $mid, $gid, $num) ?? creerEvaluation([
        'module_id' => $mid, 'type' => 'cc', 'mode' => 'pratique', 'numero' => $num,
        'duree_minutes' => 60, 'note_max' => 20, 'actif' => true,
        'meta' => ['numero' => $num, 'groupe_id' => $gid],
    ]);
}

// ── Regroupement des contrôles → CC1 / CC2 / CC3 ──────────────
// Les contrôles théoriques d'un module passés par un groupe (dans l'ordre numéro, puis création)
// sont découpés en 3 parties consécutives (9 → 3+3+3, 7 → 3+2+2) ; la moyenne de chaque partie
// devient le total des grilles CC1, CC2 et CC3 Pratique du groupe.
const SEUIL_CONTROLE_PASSE = 0.30; // part du groupe ayant passé un contrôle pour qu'il compte (absents = 0)

function estRegroupe(?string $metaJson): bool {
    return !empty((json_decode($metaJson ?? '{}', true) ?: [])['regroupe']);
}
function decouper3(array $ids): array {
    $n = count($ids); $res = []; $pos = 0;
    for ($i = 0; $i < 3 && $pos < $n; $i++) {
        $t = intdiv($n, 3) + ($i < $n % 3 ? 1 : 0);
        $res[$i] = array_slice($ids, $pos, $t); $pos += $t;
    }
    return $res;
}
/** ['controles' => [id => nom], 'parties' => [[ids]…], 'notes' => [sid => [p => note]], 'detail' => [sid => [id => note]]] */
function calculerRegroupement(PDO $pdo, int $moduleId, int $groupeId, array $roster): array {
    $res = ['controles' => [], 'parties' => [], 'notes' => [], 'detail' => []];
    if (!$roster) return $res;
    $ev = $pdo->prepare("SELECT id, nom, meta_json FROM evaluations
                         WHERE module_id = ? AND type = 'cc' AND mode = 'theorique' ORDER BY numero, id");
    $ev->execute([$moduleId]);
    $q = $pdo->prepare("SELECT stagiaire_id, pourcentage FROM sessions_eval
                        WHERE evaluation_id = ? AND groupe_id = ? AND statut = 'termine' ORDER BY date_fin");
    foreach ($ev->fetchAll() as $e) {
        if (estRegroupe($e['meta_json'])) continue;
        $q->execute([(int)$e['id'], $groupeId]);
        $notes = [];
        foreach ($q->fetchAll() as $r) {          // dernière session terminée du stagiaire
            $sid = (int)$r['stagiaire_id'];
            if (isset($roster[$sid])) $notes[$sid] = round((float)$r['pourcentage'] / 5, 2);
        }
        if (count($notes) / count($roster) < SEUIL_CONTROLE_PASSE) continue;
        $res['controles'][(int)$e['id']] = $e['nom'];
        foreach ($roster as $sid => $_) $res['detail'][$sid][(int)$e['id']] = $notes[$sid] ?? 0.0; // absent = 0
    }
    $res['parties'] = decouper3(array_keys($res['controles']));
    foreach ($roster as $sid => $_) {
        foreach ($res['parties'] as $p => $ids) {
            $v = array_map(fn($id) => $res['detail'][$sid][$id], $ids);
            $res['notes'][$sid][$p] = snapNote(array_sum($v) / count($v)); // arrondi au demi-point
        }
    }
    return $res;
}

// ── Helpers import "récap" multi-modules (1 feuille Excel = 1 module) ──
/** Normalise un nom (majuscules, sans accents, espaces compactés). */
function normalizeNameStr(string $s): string {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = strtr($s, [
        'À'=>'A','Â'=>'A','Ä'=>'A','Á'=>'A',
        'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
        'Î'=>'I','Ï'=>'I','Ì'=>'I',
        'Ô'=>'O','Ö'=>'O','Ò'=>'O',
        'Ù'=>'U','Û'=>'U','Ü'=>'U',
        'Ç'=>'C','Ñ'=>'N','Œ'=>'OE','-'=>' ',
    ]);
    $s = preg_replace('/[^A-Z0-9 ]/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}
/** Compare deux noms (insensible accents/casse/ordre des mots, tolère petites fautes de frappe). */
function namesMatch(string $a, string $b): bool {
    $na = normalizeNameStr($a); $nb = normalizeNameStr($b);
    if ($na === '' || $nb === '') return false;
    if ($na === $nb) return true;
    $wa = explode(' ', $na); $wb = explode(' ', $nb);
    sort($wa); sort($wb);
    if ($wa === $wb) return true;
    if (count($wa) !== count($wb)) return false;
    $remaining = $wb;
    foreach ($wa as $word) {
        $bestKey = null; $bestD = 3;
        foreach ($remaining as $k => $w2) {
            $d = levenshtein($word, $w2);
            if ($d < $bestD) { $bestD = $d; $bestKey = $k; }
        }
        if ($bestKey === null) return false;
        unset($remaining[$bestKey]);
    }
    return true;
}
/** Devine le module DB correspondant à une feuille (par code ou par titre descriptif). */
function guessModuleId(PDO $pdo, string $sheetName, string $sheetTitle, array $modulesAvail): int {
    $code = trim($sheetName);
    if (preg_match('/^[A-Za-z]+\d+/', $code, $m)) $code = $m[0];

    $cands = [];
    if ($code !== '') {
        $stmt = $pdo->prepare("SELECT id, nom FROM modules WHERE nom LIKE ? ORDER BY id");
        $stmt->execute([$code . '%']);
        $cands = $stmt->fetchAll();
    }
    if (!$cands) {
        $allMods = $pdo->query("SELECT id, nom FROM modules ORDER BY id")->fetchAll();
        $normTitle = mb_strtolower($sheetTitle, 'UTF-8');
        foreach ($allMods as $m) {
            $normNom = mb_strtolower($m['nom'], 'UTF-8');
            if (mb_strlen($normNom) >= 10 && strpos($normTitle, $normNom) !== false) $cands[] = $m;
        }
    }
    if (!$cands) return 0;
    foreach ($cands as $c) if (isset($modulesAvail[(int)$c['id']])) return (int)$c['id'];
    return (int)$cands[0]['id'];
}
/** Repère la ligne d'en-tête (contenant "CC1"/"CC2") d'une feuille récap et ses colonnes. */
function findRecapColumns(array $rows): ?array {
    foreach ($rows as $i => $row) {
        $cc1Col = null; $cc2Col = null; $nameCol = 0;
        foreach ($row as $c => $v) {
            if ($cc1Col === null && preg_match('/CC1/i', (string)$v)) $cc1Col = $c;
            if ($cc2Col === null && preg_match('/CC2/i', (string)$v)) $cc2Col = $c;
            if (preg_match('/nom/i', (string)$v)) $nameCol = $c;
        }
        if ($cc1Col !== null && $cc2Col !== null) {
            $title = '';
            for ($j = 0; $j < $i; $j++) $title .= ' ' . trim((string)($rows[$j][0] ?? ''));
            return ['headerRow' => $i, 'nameCol' => $nameCol, 'cc1Col' => $cc1Col, 'cc2Col' => $cc2Col, 'title' => trim($title)];
        }
    }
    return null;
}
/** Interprète une cellule de note récap : null = vide, ou ['absent'=>false,'total'=>float] (un "Absent" devient la note 0). */
function parseRecapNote(string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '') return null;
    if (preg_match('/absen/i', $raw)) return ['absent' => false, 'total' => 0.0];
    if (!is_numeric(str_replace(',', '.', $raw))) return null;
    return ['absent' => false, 'total' => snapNote((float)str_replace(',', '.', $raw))];
}

// ── Paramètres ────────────────────────────────────────────────
$type      = $_GET['type'] ?? $_POST['type'] ?? 'cc1_pratique';
if ($type === 'cc3_theorique') $type = 'cc3_pratique'; // ancien onglet CC3 (anciens liens)
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
$catCond = $isPrat ? "e.type = 'cc'" : CATEG_SQL[$categ];
$modsQ   = $pdo->query("SELECT DISTINCT m.* FROM evaluations e JOIN modules m ON m.id=e.module_id WHERE $catCond ORDER BY m.code, m.nom");
$modulesAvail = []; foreach ($modsQ->fetchAll() as $m) $modulesAvail[(int)$m['id']] = moduleLibelle($m);
$moduleNom   = $modulesAvail[$moduleId] ?? '';
$moduleLabel = $moduleNom;

// ── Évaluations candidates (CC théorique / EFM) ──────────────
$evalsDispo = [];
if (!$isPrat && $moduleId > 0) {
    $s = $pdo->prepare("SELECT e.id, e.nom, e.note_max FROM evaluations e WHERE e.module_id=? AND " . CATEG_SQL[$categ] . " ORDER BY e.numero DESC, e.id DESC");
    $s->execute([$moduleId]);
    $evalsDispo = $s->fetchAll();
}
$evalChoisie = (int)($_GET['evaluation_id'] ?? $_POST['evaluation_id'] ?? 0);
if ($evalsDispo && !in_array($evalChoisie, array_map('intval', array_column($evalsDispo, 'id')), true)) {
    $evalChoisie = (int)$evalsDispo[0]['id'];
}

// ── Roster ────────────────────────────────────────────────────
$roster = [];
if ($groupeId > 0) {
    $r = $pdo->prepare("SELECT id, nom, prenom, numero_classe FROM stagiaires WHERE groupe_id=? ORDER BY nom, prenom");
    $r->execute([$groupeId]);
    foreach ($r->fetchAll() as $row) $roster[(int)$row['id']] = $row;
}

// ── Chargement des données depuis DB ─────────────────────────
$data       = [];
$evalId     = null;
$noteMaxMod = $cfg['note_max'];

function loadData(PDO $pdo, bool $isPrat, array $evalsDispo, int $evalChoisie, int $moduleId, int $groupeId, int $numero, ?int &$evalId, int &$noteMaxMod): array {
    $data = [];
    if ($isPrat) {
        $evalId = findEvalCC($pdo, $moduleId, $groupeId, $numero);
        if ($evalId) {
            $nt = $pdo->prepare("SELECT * FROM cc_pratique_notes WHERE evaluation_id=?");
            $nt->execute([$evalId]);
            foreach ($nt->fetchAll() as $row) $data[(int)$row['stagiaire_id']] = $row;
        }
    } else {
        foreach ($evalsDispo as $ev) {
            if ((int)$ev['id'] === $evalChoisie) { $evalId = $evalChoisie; $noteMaxMod = (int)$ev['note_max']; }
        }
        if ($evalId) {
            $q = $pdo->prepare("SELECT id, stagiaire_id, score, pourcentage FROM sessions_eval WHERE evaluation_id=? AND groupe_id=? AND statut='termine' ORDER BY date_fin DESC");
            $q->execute([$evalId, $groupeId]);
            foreach ($q->fetchAll() as $row) { $sid=(int)$row['stagiaire_id']; if(!isset($data[$sid])) $data[$sid]=$row; }
        }
    }
    return $data;
}

if ($groupeId > 0 && $moduleId > 0)
    $data = loadData($pdo, $isPrat, $evalsDispo, $evalChoisie, $moduleId, $groupeId, $cfg['numero'] ?? 0, $evalId, $noteMaxMod);

// ── Téléchargement modèle Excel des totaux (CC1/CC2 pratique) ─
if (isset($_GET['model']) && $isPrat && $groupeId > 0 && $moduleId > 0) {
    $rows = [CC_IMPORT_HEADERS];
    foreach ($roster as $sid => $stg) {
        $row   = $data[$sid] ?? null;
        $isAbs = $row && (int)($row['absent'] ?? 0) === 1;
        $rows[] = [
            $sid, $stg['nom'], $stg['prenom'],
            $row && !$isAbs ? fmtN($row['total'] ?? null) : '',
            $isAbs ? 'x' : '',
        ];
    }
    $bin   = buildXlsx($rows, $cfg['label']);
    $fname = 'modele_totaux_' . $type . '_'
           . preg_replace('/[^A-Za-z0-9]+/', '_', $groupeNom) . '_'
           . preg_replace('/[^A-Za-z0-9]+/', '_', $moduleNom) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}

// ── POST : Importer les totaux Excel et les répartir sur les questions (CC1/CC2 pratique) ─
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_excel'])) {
    verifyCsrfToken();
    if (!$isPrat || !$groupeId || !$moduleId) {
        $erreur = "Sélectionnez un groupe et un module (CC1/CC2 Pratique).";
    } elseif (empty($_FILES['fichier']['tmp_name']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
        $erreur = "Aucun fichier reçu.";
    } else {
        $rows = readSpreadsheet($_FILES['fichier']['tmp_name']);
        if (count($rows) < 2) {
            $erreur = "Fichier vide ou illisible.";
        } else {
            // Index roster par ID + par NOM|PRENOM normalisés
            $byId = []; $byName = [];
            foreach ($roster as $sid => $stg) {
                $byId[$sid] = $sid;
                $key = mb_strtolower(trim($stg['nom']) . '|' . trim($stg['prenom']), 'UTF-8');
                $byName[$key] = $sid;
            }

            $eid = getOrCreateEvalCC($pdo, $moduleId, $groupeId, $cfg['numero'] ?? 1);
            $up  = $pdo->prepare("INSERT INTO cc_pratique_notes
                (evaluation_id, stagiaire_id, p1q1,p1q2,p2q1,p2q2,p3q1,p3q2,p4, total, absent)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                p1q1=VALUES(p1q1),p1q2=VALUES(p1q2),p2q1=VALUES(p2q1),p2q2=VALUES(p2q2),
                p3q1=VALUES(p3q1),p3q2=VALUES(p3q2),p4=VALUES(p4),total=VALUES(total),absent=VALUES(absent)");

            $nbOk = 0; $nbSkip = 0;
            foreach ($rows as $i => $row) {
                if ($i === 0) continue; // en-tête
                $row = array_map(fn($v) => trim((string)$v), $row);
                if (count(array_filter($row, fn($v) => $v !== '')) === 0) continue;

                $sid = 0;
                $rid = (int)($row[0] ?? 0);
                if ($rid > 0 && isset($byId[$rid])) {
                    $sid = $rid;
                } else {
                    $key = mb_strtolower(($row[1] ?? '') . '|' . ($row[2] ?? ''), 'UTF-8');
                    $sid = $byName[$key] ?? 0;
                }
                if ($sid <= 0) { $nbSkip++; continue; }

                $absRaw   = mb_strtolower($row[4] ?? '', 'UTF-8');
                $isAbsent = ($absRaw !== '' && $absRaw !== '0') ? 1 : 0;

                // Un absent obtient la note 0/20 (comptée dans la somme/moyenne), au lieu d'être exclu.
                $totRaw = $isAbsent ? '0' : ($row[3] ?? '');
                if ($totRaw === '') { $nbSkip++; continue; }
                $total = snapNote((float)str_replace(',', '.', $totRaw));
                $cells = distribuer($total);
                $vals  = array_map(fn($c) => $cells[$c], CC_CELLS);
                $up->execute(array_merge([$eid, $sid], $vals, [$total, 0]));
                $nbOk++;
            }
            header("Location: gestion_impression.php?type=$type&groupe_id=$groupeId&module_id=$moduleId&imported=$nbOk&skipped=$nbSkip");
            exit;
        }
    }
}

// ── POST : Importer un récap multi-modules (1 feuille = 1 module, colonnes CC1/CC2) — étape 1 : aperçu ─
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_recap'])) {
    verifyCsrfToken();
    if (!$isPrat || !$groupeId) {
        $erreur = "Sélectionnez un groupe (onglet CC1 ou CC2 Pratique).";
    } elseif (empty($_FILES['fichier']['tmp_name']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
        $erreur = "Aucun fichier reçu.";
    } else {
        $sheets = readXlsxSheets($_FILES['fichier']['tmp_name']);
        if (!$sheets) {
            $erreur = "Fichier illisible (un classeur .xlsx multi-feuilles est attendu).";
        } else {
            $preview = [];
            foreach ($sheets as $sheetName => $rows) {
                $cols = findRecapColumns($rows);
                if (!$cols) continue;
                $dataRows = [];
                for ($i = $cols['headerRow'] + 1; $i < count($rows); $i++) {
                    $row  = $rows[$i];
                    $name = trim((string)($row[$cols['nameCol']] ?? ''));
                    if ($name === '') continue;
                    if (preg_match('/^(total|moyenne|nb|effectif)/i', $name)) continue;
                    $dataRows[] = [
                        'name' => $name,
                        'cc1'  => trim((string)($row[$cols['cc1Col']] ?? '')),
                        'cc2'  => trim((string)($row[$cols['cc2Col']] ?? '')),
                    ];
                }
                if (!$dataRows) continue;
                usort($dataRows, fn($a, $b) => strcasecmp(normalizeNameStr($a['name']), normalizeNameStr($b['name'])));
                $preview[$sheetName] = [
                    'title'           => $cols['title'],
                    'guess_module_id' => guessModuleId($pdo, $sheetName, $cols['title'], $modulesAvail),
                    'rows'            => $dataRows,
                ];
            }
            if (!$preview) {
                $erreur = "Aucune feuille avec colonnes \"CC1\"/\"CC2\" reconnue dans ce fichier.";
            } else {
                $_SESSION['recap_preview'] = ['groupe_id' => $groupeId, 'type' => $type, 'sheets' => $preview];
                header("Location: gestion_impression.php?type=$type&groupe_id=$groupeId&module_id=$moduleId&evaluation_id=$evalChoisie");
                exit;
            }
        }
    }
}

// ── POST : Annuler l'aperçu du récap ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recap_cancel'])) {
    verifyCsrfToken();
    unset($_SESSION['recap_preview']);
    header("Location: gestion_impression.php?type=$type&groupe_id=$groupeId&module_id=$moduleId&evaluation_id=$evalChoisie");
    exit;
}

// ── POST : Confirmer l'import du récap — étape 2 : application ─
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recap_confirm'])) {
    verifyCsrfToken();
    $preview = $_SESSION['recap_preview'] ?? null;
    if (!$preview || (int)$preview['groupe_id'] !== $groupeId) {
        $erreur = "Aperçu expiré ou groupe différent — veuillez réimporter le fichier.";
    } else {
        $moduleMap = $_POST['module_map'] ?? [];
        $nbOk = 0; $nbSkip = 0; $nbMods = 0; $firstMid = 0;
        $up = $pdo->prepare("INSERT INTO cc_pratique_notes
            (evaluation_id, stagiaire_id, p1q1,p1q2,p2q1,p2q2,p3q1,p3q2,p4, total, absent)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
            p1q1=VALUES(p1q1),p1q2=VALUES(p1q2),p2q1=VALUES(p2q1),p2q2=VALUES(p2q2),
            p3q1=VALUES(p3q1),p3q2=VALUES(p3q2),p4=VALUES(p4),total=VALUES(total),absent=VALUES(absent)");

        foreach ($preview['sheets'] as $sheetName => $sheet) {
            $mid = (int)($moduleMap[$sheetName] ?? 0);
            if ($mid <= 0) continue;
            $nbMods++;
            if ($firstMid === 0) $firstMid = $mid;
            $eid1 = getOrCreateEvalCC($pdo, $mid, $groupeId, 1);
            $eid2 = getOrCreateEvalCC($pdo, $mid, $groupeId, 2);

            foreach ($sheet['rows'] as $row) {
                $sid = 0;
                foreach ($roster as $rid => $stg) {
                    if (namesMatch($row['name'], trim($stg['nom'] . ' ' . $stg['prenom']))) { $sid = $rid; break; }
                }
                if ($sid <= 0) { $nbSkip++; continue; }

                foreach ([1 => ['eid'=>$eid1,'raw'=>$row['cc1']], 2 => ['eid'=>$eid2,'raw'=>$row['cc2']]] as $info) {
                    $parsed = parseRecapNote($info['raw']);
                    if ($parsed === null) continue;
                    // Un absent obtient la note 0/20 (comptée dans la somme/moyenne).
                    $cells = distribuer($parsed['total']);
                    $vals  = array_map(fn($c) => $cells[$c], CC_CELLS);
                    $up->execute(array_merge([$info['eid'], $sid], $vals, [$parsed['total'], 0]));
                    $nbOk++;
                }
            }
        }
        unset($_SESSION['recap_preview']);
        $redirMid = $moduleId > 0 ? $moduleId : $firstMid;
        header("Location: gestion_impression.php?type=$type&groupe_id=$groupeId&module_id=$redirMid&recap_done=$nbOk&recap_skip=$nbSkip&recap_mods=$nbMods");
        exit;
    }
}

// ── POST : Regrouper les contrôles → CC1/CC2/CC3 (aperçu puis validation) ─
$regroupement = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['regrouper_preview']) || isset($_POST['regrouper_confirm']))) {
    verifyCsrfToken();
    if (!$groupeId || !$moduleId) { $erreur = "Sélectionnez un groupe et un module."; }
    else {
        $regroupement = calculerRegroupement($pdo, $moduleId, $groupeId, $roster);
        if (!$regroupement['controles']) {
            $erreur = "Aucun contrôle théorique passé par ce groupe pour ce module.";
            $regroupement = null;
        } elseif (isset($_POST['regrouper_confirm'])) {
            $pdo->beginTransaction();
            try {
                $up = $pdo->prepare("INSERT INTO cc_pratique_notes (evaluation_id,stagiaire_id,p1q1,p1q2,p2q1,p2q2,p3q1,p3q2,p4,total,absent)
                                     VALUES (?,?,?,?,?,?,?,?,?,?,0) ON DUPLICATE KEY UPDATE p1q1=VALUES(p1q1),p1q2=VALUES(p1q2),
                                     p2q1=VALUES(p2q1),p2q2=VALUES(p2q2),p3q1=VALUES(p3q1),p3q2=VALUES(p3q2),p4=VALUES(p4),total=VALUES(total),absent=0");
                $mark = $pdo->prepare("UPDATE evaluations SET meta_json = JSON_SET(COALESCE(meta_json, '{}'), '$.regroupe', true) WHERE id = ?");
                $nb = 0;
                foreach ([0 => 1, 1 => 2, 2 => 3] as $p => $num) {   // CC1, CC2, CC3 → grilles du groupe (total réparti)
                    if (!isset($regroupement['parties'][$p])) continue;
                    $eid = getOrCreateEvalCC($pdo, $moduleId, $groupeId, $num);
                    $mark->execute([$eid]);
                    foreach ($regroupement['notes'] as $sid => $parts) {
                        $cells = distribuer($parts[$p]);
                        $up->execute(array_merge([$eid, $sid], array_map(fn($c) => $cells[$c], CC_CELLS), [$parts[$p]]));
                        $nb++;
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            header("Location: gestion_impression.php?type=$type&groupe_id=$groupeId&module_id=$moduleId&regroupe=$nb");
            exit;
        }
    }
}
if (isset($_GET['regroupe'])) $msg = (int)$_GET['regroupe'] . " note(s) injectée(s) dans CC1, CC2 et CC3 à partir du regroupement des contrôles.";

// ── POST : Générer CCn depuis la moyenne de la partie n du regroupement des contrôles ─
// CC1 ← partie 1, CC2 ← partie 2, CC3 ← partie 3 (grilles pratiques du groupe).
// Les notes sont pré-remplies dans la grille ; elles ne sont enregistrées qu'au clic sur « Sauvegarder ».
$partieGen = $isPrat ? (int)($cfg['numero'] ?? 1) - 1 : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    verifyCsrfToken();
    if ($partieGen === null) { $erreur = "Génération uniquement pour CC1, CC2 et CC3."; }
    elseif (!$groupeId || !$moduleId) { $erreur = "Sélectionnez groupe et module."; }
    else {
        $rg = calculerRegroupement($pdo, $moduleId, $groupeId, $roster);
        $label = 'CC' . ($partieGen + 1);
        if (!isset($rg['parties'][$partieGen])) {
            $erreur = count($rg['controles']) . " contrôle(s) théorique(s) passé(s) par ce groupe : pas assez pour former la partie $label.";
        } else {
            $data = [];
            foreach ($rg['notes'] as $sid => $parts) {
                $note = $parts[$partieGen];
                $data[$sid] = array_merge(distribuer($note), ['total' => $note, 'absent' => 0, 'stagiaire_id' => $sid]);
            }
            $genereRegroupement = true;
            $ctrl = implode(' + ', array_map(fn($id) => $rg['controles'][$id], $rg['parties'][$partieGen]));
            $msg = count($data) . " note(s) $label générée(s) = moyenne de : $ctrl. Vérifiez puis cliquez sur « Sauvegarder ».";
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
        if (!empty($_POST['from_regroupement'])) // notes issues du regroupement : exclues de l'export Moyennes CC
            $pdo->prepare("UPDATE evaluations SET meta_json = JSON_SET(COALESCE(meta_json, '{}'), '$.regroupe', true) WHERE id = ?")->execute([$eid]);
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
            if ($evalChoisie) $evalId = $evalChoisie; else { $erreur="Évaluation introuvable."; $ok=false; }
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
        $dest = "gestion_impression.php?type=$type&groupe_id=$groupeId&module_id=$moduleId&evaluation_id=$evalChoisie" . ($doPrint ? '&print=1' : '&saved=1');
        header("Location: $dest"); exit;
    }
}

if (isset($_GET['saved'])) $msg = "Résultats sauvegardés avec succès.";
if (isset($_GET['imported'])) {
    $msg = (int)$_GET['imported'] . " note(s) importée(s) et réparties sur les questions."
         . (($_GET['skipped'] ?? 0) > 0 ? " " . (int)$_GET['skipped'] . " ligne(s) ignorée(s) (stagiaire non reconnu ou note vide)." : "");
}
if (isset($_GET['recap_done'])) {
    $msg = (int)$_GET['recap_done'] . " note(s) CC1/CC2 importée(s) et réparties sur "
         . (int)($_GET['recap_mods'] ?? 0) . " module(s)."
         . (($_GET['recap_skip'] ?? 0) > 0 ? " " . (int)$_GET['recap_skip'] . " ligne(s) ignorée(s) (stagiaire non reconnu)." : "");
}

// ── Aperçu récap multi-modules en attente de confirmation ────
$recapPreview = null;
if (!empty($_SESSION['recap_preview']) && (int)$_SESSION['recap_preview']['groupe_id'] === $groupeId) {
    $recapPreview = $_SESSION['recap_preview'];
    $allModulesList = $pdo->query("SELECT id, nom FROM modules ORDER BY nom")->fetchAll();
}

// Reload after save/generate (data already loaded above or set by generate)
$nbPres = count(array_filter($data, fn($r) => !(isset($r['absent']) && (int)$r['absent'] === 1) && !empty($r)));
$nbAbs  = count(array_filter($roster, fn($s, $sid) => !isset($data[$sid]) || (isset($data[$sid]['absent']) && (int)$data[$sid]['absent']===1), ARRAY_FILTER_USE_BOTH));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $printMode ? htmlspecialchars($cfg['label'].' — '.$groupeNom.' — '.$moduleNom) : 'Notes par groupe' ?></title>
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
        @page{margin:3cm;size:A4 landscape}
        /* Mise en page "page A4" appliquée en permanence en mode impression (aperçu fidèle + mesures JS correctes)
           @page margin:3cm => zone imprimable = (297-60) x (210-60) mm.
           Pas de hauteur/overflow forcés : si le contenu dépasse, il se poursuit naturellement
           sur une 2e page au lieu d'être tronqué. */
        body.print-mode{margin:0!important;padding:0!important;background:#fff!important;
                  width:237mm;min-height:150mm}
        body.print-mode .container-fluid{padding:0!important;width:237mm!important;box-sizing:border-box}
        body.print-mode .page-block{margin-bottom:0}
        body.print-mode .grille{font-size:8.5px;width:100%}
        body.print-mode .grille th,body.print-mode .grille td{border:1px solid #000!important;padding:2px 3px;white-space:nowrap;font-size:inherit!important;line-height:1.15}
        body.print-mode .grille td.name{max-width:85px;overflow:hidden;text-overflow:ellipsis}
        body.print-mode .note-inp{border:none!important;box-shadow:none!important;
                  background:transparent!important;width:auto!important;padding:0;
                  font-size:inherit!important;line-height:inherit!important}
        body.print-mode .print-header{margin-bottom:2mm;line-height:1.4;text-align:center;width:100%}
        body.print-mode thead{display:table-header-group}
        .badge-cc{display:inline-block;padding:1mm 5mm;border-radius:3mm;font-size:14pt;font-weight:900;letter-spacing:1px;vertical-align:middle;margin-left:3mm}
        .badge-cc1{background:#0d6efd;color:#fff}
        .badge-cc2{background:#198754;color:#fff}
        .badge-cc3{background:#e67e00;color:#fff}
        .badge-efm{background:#dc3545;color:#fff}
        @media print{
            .no-print{display:none !important}
        }
    </style>
</head>
<body class="<?= $printMode ? 'print-mode' : 'bg-light' ?>">

<?php if ($printMode): ?>
<!-- ═══════════════════════  IMPRESSION  ════════════════════════ -->
<div class="container-fluid py-3 px-4">
    <div class="d-flex gap-2 align-items-center mb-2 no-print flex-wrap">
        <a href="gestion_impression.php?type=<?= $type ?>&groupe_id=<?= $groupeId ?>&module_id=<?= $moduleId ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Retour
        </a>
        <button onclick="window.print()" class="btn btn-dark btn-sm">
            <i class="bi bi-printer me-1"></i>Imprimer
        </button>
        <button form="printSaveForm" type="submit" name="save" value="1" class="btn btn-success btn-sm">
            <i class="bi bi-floppy me-1"></i>Sauvegarder les modifications
        </button>
    </div>
    <div class="alert alert-danger py-2 px-3 mb-3 no-print fw-bold">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        Avant d'imprimer : dans la boîte de dialogue, ouvrir <u>"Plus de paramètres"</u> et
        DÉCOCHER la case <u>"En-têtes et pieds de page"</u> (sinon date/URL/n° de page s'impriment) —
        marges : <u>Par défaut</u>.
    </div>

    <div class="print-header">
        <div style="font-size:13pt;font-weight:bold;letter-spacing:.3px">
            <?= htmlspecialchars(getEtablissementDefaut()) ?>
        </div>
        <div style="font-size:11pt;font-weight:600;margin-top:1mm">
            <?= htmlspecialchars($cfg['label']) ?>
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
        <input type="hidden" name="module_id"  value="<?= $moduleId ?>"><input type="hidden" name="evaluation_id" value="<?= $evalChoisie ?>">

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
                    <th rowspan="2" class="text-center">N°</th>
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
                <td class="text-center text-muted"><?= $stg['numero_classe'] !== null ? (int)$stg['numero_classe'] : '—' ?></td>
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
                    <th class="text-center">N°</th>
                    <th class="name">NOM</th><th class="name">PRÉNOM</th>
                    <th>Note /<?= $noteMaxMod ?></th><th>%</th><th>Mention</th>
                    <th class="no-print">Absent</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($roster as $sid => $stg):
                $row=$data[$sid]??null; $abs=!$row; $uid="t_{$sid}"; ?>
            <tr class="<?= $abs?'abs-row':'' ?>" id="tr_<?= $uid ?>">
                <td class="text-center text-muted"><?= $stg['numero_classe'] !== null ? (int)$stg['numero_classe'] : '—' ?></td>
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
    // Ajuste la mise en page pour occuper la totalité d'une page A4 paysage.
    var MM = 96 / 25.4; // px par mm (96dpi)
    var header    = document.querySelector('.print-header');
    var pageBlock = document.querySelector('.page-block');
    var table     = pageBlock ? pageBlock.querySelector('table.grille') : null;

    if (pageBlock && table) {
        var headerH = 0;
        if (header) {
            var hcs = getComputedStyle(header);
            headerH = header.offsetHeight + parseFloat(hcs.marginBottom || 0) + parseFloat(hcs.marginTop || 0);
        }
        var availH = (150 * MM) - headerH - 2; // hauteur dispo pour le tableau (page - marges 3cm), -2px de marge de sécurité
        var baseFs = 8.5, basePadV = 2, basePadH = 3;

        var fitStyle = document.createElement('style');
        document.head.appendChild(fitStyle);

        // Tableau trop grand : on réduit progressivement police + padding jusqu'à tenir sur 1 page.
        var scale = 1;
        for (var i = 0; i < 40 && pageBlock.scrollHeight > availH && scale > 0.35; i++) {
            scale -= 0.025;
            table.style.fontSize = (baseFs * scale) + 'px';
            fitStyle.textContent = 'body.print-mode .grille th,body.print-mode .grille td{padding:' + (basePadV * scale).toFixed(2) + 'px ' + (basePadH * scale).toFixed(2) + 'px}';
        }

        // Tableau trop petit : on agrandit les lignes pour occuper toute la hauteur de page.
        var curH = pageBlock.scrollHeight;
        if (curH > 0 && availH > curH) {
            var rows = table.querySelectorAll('tbody tr').length;
            if (rows > 0) {
                var extraPerRow = (availH - curH) / rows;
                if (extraPerRow > 1) {
                    fitStyle.textContent += 'body.print-mode .grille tbody td{padding-top:' + (extraPerRow / 2) + 'px;padding-bottom:' + (extraPerRow / 2) + 'px}';
                }
            }
        }
    }

    setTimeout(function() { window.print(); }, 350);
});

// Vide le titre du document pendant l'impression pour limiter l'en-tête/pied de page
// injecté par le navigateur (titre + URL en haut, date + numéro de page en bas).
// Remarque : la case "En-têtes et pieds de page" de la boîte d'impression doit être
// décochée par l'utilisateur pour les supprimer complètement (réglage navigateur, non scriptable).
(function() {
    var savedTitle = document.title;
    window.addEventListener('beforeprint', function() { document.title = ' '; });
    window.addEventListener('afterprint', function() { document.title = savedTitle; });
})();
</script>

<?php else: ?>
<!-- ═══════════════════════  MODE NORMAL  ════════════════════════ -->
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container-fluid py-4 px-4">
    <h2 class="h4 fw-bold mb-3">
        <i class="bi bi-table me-2 text-primary"></i>Notes par groupe
        <small class="text-muted fw-normal fs-6 ms-2">CC pratiques, CC théorique, EFM — saisie, correction et relevé imprimable</small>
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
                <?php if (count($evalsDispo) > 1): ?>
                <div class="col-md-5 col-lg-4">
                    <label class="form-label fw-semibold small mb-1">Évaluation</label>
                    <select name="evaluation_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($evalsDispo as $ev): ?>
                        <option value="<?= (int)$ev['id'] ?>" <?= $evalChoisie === (int)$ev['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ev['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if ($groupeId > 0 && $moduleId > 0): ?>
                <div class="col-auto ms-auto d-flex gap-2">
                    <button form="genForm" type="submit" name="regrouper_preview" value="1" class="btn btn-sm btn-outline-primary"
                            title="Regroupe les contrôles théoriques du groupe en 3 parties : la moyenne de chaque partie devient CC1, CC2, CC3">
                        <i class="bi bi-diagram-3 me-1"></i>Regrouper → CC1/CC2/CC3
                    </button>
                    <?php if ($partieGen !== null): ?>
                    <button form="genForm" type="submit" name="generate" value="1" class="btn btn-sm btn-outline-warning"
                            title="Moyenne de la partie <?= $partieGen + 1 ?> des contrôles théoriques du groupe (regroupés en 3 parties)">
                        <i class="bi bi-magic me-1"></i>Générer de moyenne CC<?= $partieGen + 1 ?>
                    </button>
                    <?php endif; ?>
                    <?php if ($isPrat): ?>
                    <a href="?type=<?= $type ?>&groupe_id=<?= $groupeId ?>&module_id=<?= $moduleId ?>&model=1" class="btn btn-sm btn-outline-secondary" download>
                        <i class="bi bi-download me-1"></i>Modèle totaux
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#mImportTotaux">
                        <i class="bi bi-upload me-1"></i>Importer totaux
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
                <input type="hidden" name="module_id" value="<?= $moduleId ?>"><input type="hidden" name="evaluation_id" value="<?= $evalChoisie ?>">
            </form>
        </div>
    </div>

    <?php if ($regroupement): $rp = $regroupement; ?>
    <!-- ── Aperçu du regroupement CC1/CC2/CC3 ─────────────────── -->
    <div class="card border-primary shadow-sm rounded-4 mb-4">
        <div class="card-header bg-primary text-white fw-semibold">
            <i class="bi bi-diagram-3 me-2"></i>Regroupement des contrôles — <?= htmlspecialchars($groupeNom) ?> — <?= htmlspecialchars($moduleNom) ?>
        </div>
        <div class="card-body">
            <p class="small mb-2">
                <?= count($rp['controles']) ?> contrôle(s) théorique(s) passé(s) par le groupe (au moins <?= (int)(SEUIL_CONTROLE_PASSE * 100) ?> % des stagiaires ; un stagiaire sans note a 0).
                Chaque partie = moyenne de ses contrôles, arrondie au demi-point.
            </p>
            <ul class="small mb-3">
                <?php $dest = ['CC1 Pratique (total de la grille)', 'CC2 Pratique (total de la grille)', 'CC3 Pratique (total de la grille)'];
                foreach ($rp['parties'] as $p => $ids): ?>
                <li><strong>CC<?= $p + 1 ?></strong> → <?= $dest[$p] ?> : <?= htmlspecialchars(implode(' + ', array_map(fn($id) => $rp['controles'][$id], $ids))) ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="table-responsive" style="max-height:420px">
            <table class="table table-sm table-bordered grille bg-white mb-3">
                <thead class="table-light"><tr>
                    <th class="name">NOM</th><th class="name">PRÉNOM</th>
                    <?php $c = 1; foreach ($rp['parties'] as $p => $ids): foreach ($ids as $id): ?>
                    <th title="<?= htmlspecialchars($rp['controles'][$id]) ?>">C<?= $c++ ?></th>
                    <?php endforeach; ?><th class="table-primary">CC<?= $p + 1 ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($roster as $sid => $stg): ?>
                <tr>
                    <td class="name"><?= htmlspecialchars($stg['nom']) ?></td><td class="name"><?= htmlspecialchars($stg['prenom']) ?></td>
                    <?php foreach ($rp['parties'] as $p => $ids): foreach ($ids as $id): ?>
                    <td><?= fmtN($rp['detail'][$sid][$id]) ?></td>
                    <?php endforeach; $v = $rp['notes'][$sid][$p]; ?>
                    <td class="fw-bold <?= $v >= 10 ? 'text-success' : 'text-danger' ?>"><?= fmtN($v) ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <form method="POST" class="d-flex gap-2 align-items-center flex-wrap"
                  onsubmit="return confirm('Remplacer les notes CC1, CC2 et CC3 Pratique de ce groupe par ces moyennes ?')">
                <?= csrfField() ?>
                <input type="hidden" name="type" value="<?= $type ?>">
                <input type="hidden" name="groupe_id" value="<?= $groupeId ?>">
                <input type="hidden" name="module_id" value="<?= $moduleId ?>">
                <button name="regrouper_confirm" value="1" class="btn btn-primary btn-sm"><i class="bi bi-check2-circle me-1"></i>Injecter dans CC1, CC2, CC3</button>
                <a href="?type=<?= $type ?>&groupe_id=<?= $groupeId ?>&module_id=<?= $moduleId ?>" class="btn btn-outline-secondary btn-sm">Annuler</a>
                <span class="small text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Les notes déjà saisies dans CC1, CC2 et CC3 Pratique de ce groupe seront remplacées.</span>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal import Excel des totaux CC1/CC2 -->
    <?php if ($isPrat && $groupeId > 0 && $moduleId > 0): ?>
    <div class="modal fade" id="mImportTotaux" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="type"      value="<?= $type ?>">
            <input type="hidden" name="groupe_id" value="<?= $groupeId ?>">
            <input type="hidden" name="module_id" value="<?= $moduleId ?>"><input type="hidden" name="evaluation_id" value="<?= $evalChoisie ?>">
            <div class="modal-header">
                <h5 class="modal-title">Importer les totaux <?= htmlspecialchars($cfg['label']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">
                    Téléchargez d'abord le modèle (colonnes ID, NOM, PRÉNOM, TOTAL, ABSENT), remplissez la colonne
                    <strong>TOTAL</strong> avec la note sur 20 de chaque stagiaire, puis importez le fichier.
                    La note sera automatiquement répartie sur les questions de la grille (PARTIE 1 à 4).
                </p>
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

    <!-- Bouton + Modal import récap multi-modules (1 feuille = 1 module) -->
    <?php if ($isPrat && $groupeId > 0): ?>
    <div class="d-flex justify-content-end mb-3">
        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mImportRecap">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Importer récap multi-modules (CC1+CC2)
        </button>
    </div>
    <div class="modal fade" id="mImportRecap" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="type"      value="<?= $type ?>">
            <input type="hidden" name="groupe_id" value="<?= $groupeId ?>">
            <input type="hidden" name="module_id" value="<?= $moduleId ?>"><input type="hidden" name="evaluation_id" value="<?= $evalChoisie ?>">
            <div class="modal-header">
                <h5 class="modal-title">Importer un récapitulatif multi-modules</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">
                    Importez un classeur Excel <strong>(.xlsx)</strong> contenant <strong>une feuille par module</strong>,
                    chaque feuille listant les stagiaires (NOM PRÉNOM) avec leurs notes <strong>CC1/20</strong> et <strong>CC2/20</strong>
                    (ou la mention « Absent »). Les notes seront automatiquement réparties sur les questions de chaque grille CC1 et CC2.
                    Un aperçu vous permettra de vérifier/corriger la correspondance feuille → module avant validation.
                </p>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Fichier Excel (.xlsx)</label>
                    <input type="file" name="fichier" class="form-control" accept=".xlsx" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                <button name="import_recap" class="btn btn-primary">Analyser le fichier</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Aperçu récap multi-modules (avant confirmation) ─────── -->
    <?php if ($recapPreview): ?>
    <div class="card border-0 shadow-sm rounded-4 mb-4 border-primary border-2">
        <div class="card-header bg-primary bg-opacity-10 fw-semibold">
            <i class="bi bi-eye me-2"></i>Aperçu de l'import — vérifiez la correspondance feuille → module avant de valider
        </div>
        <div class="card-body p-3">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="type"      value="<?= $type ?>">
                <input type="hidden" name="groupe_id" value="<?= $groupeId ?>">
                <input type="hidden" name="module_id" value="<?= $moduleId ?>"><input type="hidden" name="evaluation_id" value="<?= $evalChoisie ?>">
                <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Feuille</th>
                            <th>Titre détecté</th>
                            <th>Module</th>
                            <th>Stagiaires reconnus</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recapPreview['sheets'] as $sheetName => $sheet):
                        $nbMatch = 0; $unmatched = [];
                        foreach ($sheet['rows'] as $row) {
                            $found = false;
                            foreach ($roster as $stg) {
                                if (namesMatch($row['name'], trim($stg['nom'] . ' ' . $stg['prenom']))) { $found = true; break; }
                            }
                            if ($found) $nbMatch++; else $unmatched[] = $row['name'];
                        }
                        sort($unmatched, SORT_STRING | SORT_FLAG_CASE);
                    ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($sheetName) ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($sheet['title']) ?></td>
                            <td>
                                <select name="module_map[<?= htmlspecialchars($sheetName) ?>]" class="form-select form-select-sm">
                                    <option value="0">— Ignorer cette feuille —</option>
                                    <?php foreach ($allModulesList as $m): ?>
                                    <option value="<?= $m['id'] ?>" <?= ((int)$m['id']===(int)$sheet['guess_module_id'])?'selected':'' ?>><?= htmlspecialchars("#{$m['id']} — {$m['nom']}") ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <span class="badge bg-<?= $nbMatch>0?'success':'secondary' ?>"><?= $nbMatch ?> / <?= count($sheet['rows']) ?></span>
                                <?php if ($unmatched): ?>
                                <div class="small text-danger mt-1">Non reconnus : <?= htmlspecialchars(implode(', ', $unmatched)) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div class="d-flex gap-2 justify-content-end">
                    <button name="recap_cancel" value="1" class="btn btn-light btn-sm">Annuler</button>
                    <button name="recap_confirm" value="1" class="btn btn-primary btn-sm"><i class="bi bi-check2-circle me-1"></i>Valider et répartir CC1/CC2</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

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
                <?= htmlspecialchars($moduleLabel) ?>
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
            <?php if (!empty($genereRegroupement)): ?><input type="hidden" name="from_regroupement" value="1"><?php endif; ?>
            <input type="hidden" name="type"      value="<?= $type ?>">
            <input type="hidden" name="groupe_id" value="<?= $groupeId ?>">
            <input type="hidden" name="module_id" value="<?= $moduleId ?>"><input type="hidden" name="evaluation_id" value="<?= $evalChoisie ?>">

            <div class="table-responsive">
            <?php if ($isPrat): ?>
            <table class="table table-hover table-bordered grille mb-0 bg-white">
                <thead class="table-<?= $cfg['color'] ?> bg-opacity-50">
                    <tr>
                        <th rowspan="2" class="text-center">N°</th>
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
                    <td class="text-center text-muted"><?= $stg['numero_classe'] !== null ? (int)$stg['numero_classe'] : '—' ?></td>
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
                        <td class="tot" id="tot_<?= $uid ?>"><?= ($row['total']??null)!==null ? fmtN($row['total']) : '—' ?></td>
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
                        <th class="text-center">N°</th>
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
                    <td class="text-center text-muted"><?= $stg['numero_classe'] !== null ? (int)$stg['numero_classe'] : '—' ?></td>
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
