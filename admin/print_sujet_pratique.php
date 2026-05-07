<?php
/**
 * Impression sujet évaluation pratique — 3 variantes
 * Format IDENTIQUE à print_exams.php (non-EFM)
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo    = getDB();
$evalId = (int)($_GET['id'] ?? 0);
if (!$evalId) { header('Location: eval_pratique.php'); exit; }

$ev = $pdo->prepare("SELECT * FROM eval_pratique WHERE id = ?");
$ev->execute([$evalId]);
$eval = $ev->fetch();
if (!$eval) { header('Location: eval_pratique.php'); exit; }

$stmtP = $pdo->prepare("SELECT * FROM eval_pratique_parties WHERE eval_id = ? ORDER BY ordre, numero");
$stmtP->execute([$evalId]);
$parties = $stmtP->fetchAll();

foreach ($parties as &$p) {
    $stmtQ = $pdo->prepare("SELECT * FROM eval_pratique_questions WHERE partie_id = ? ORDER BY ordre, numero");
    $stmtQ->execute([$p['id']]);
    $p['questions'] = $stmtQ->fetchAll();
}
unset($p);

// Logo en base64
$logoPath = __DIR__ . '/../assets/img/ofppt_logo.png';
$logoB64  = file_exists($logoPath)
    ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))
    : '';

$tamponPath = __DIR__ . '/../assets/img/tampon_ofppt.png';
$tamponB64  = file_exists($tamponPath)
    ? 'data:image/png;base64,' . base64_encode(file_get_contents($tamponPath))
    : '';

$annee     = $eval['annee'] ?: date('Y') . '/' . (date('Y')+1);
$noteMax   = (int)$eval['note_max'];
$duree     = $eval['duree'] ?: '2h';
$struct    = json_decode($eval['structure_json'] ?? '{}', true);
$consignes = $struct['consignes'] ?? '';

// 3 variantes : parties mélangées avec seeds différents
function makeVariant(array $parties, int $seed): array {
    srand($seed);
    $v = $parties;
    shuffle($v);
    foreach ($v as $i => &$p) { $p['num_affichage'] = $i + 1; }
    unset($p);
    return $v;
}

$variantes = [
    'A' => makeVariant($parties, 42),
    'B' => makeVariant($parties, 137),
    'C' => makeVariant($parties, 251),
];

function s(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
function fpts(float $pts): string {
    return rtrim(rtrim(number_format($pts, 2, '.', ''), '0'), '.');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Sujet Pratique — <?= s($eval['titre']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 11pt; color: #000; background: #fff; }

        /* ---- En-tête (identique print_exams) ---- */
        .header { border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 8px; }
        .header-top { display: flex; align-items: center; gap: 12px; }
        .header-logo { width: 70px; height: auto; flex-shrink: 0; }
        .header-org { font-size: 9pt; font-style: italic; font-weight: bold; flex-grow: 1; text-align: center; }
        .header-year { text-align: center; font-size: 9pt; margin-top: 4px; }

        .module-box { border: 1px solid #000; padding: 4px 8px; margin: 6px 0; text-align: center; }
        .module-box .label { font-size: 8pt; }
        .module-box .titre { font-size: 10pt; font-weight: bold; }
        .module-box .variante-badge {
            display: inline-block; margin-left: 8px;
            background: #000; color: #fff;
            font-size: 9pt; font-weight: bold;
            padding: 1px 7px; border-radius: 3px;
        }

        .info-row { display: flex; justify-content: space-between; font-size: 10pt; margin: 3px 0; border-bottom: 1px solid #000; padding-bottom: 2px; }
        .info-row span { font-weight: normal; }

        /* ---- Consignes ---- */
        .consignes-box { border: 1px dashed #888; padding: 4px 8px; margin: 8px 0; font-size: 9pt; background: #fafafa; }
        .consignes-box strong { font-size: 9.5pt; }

        /* ---- Parties & questions (identique print_exams) ---- */
        .partie-titre {
            font-weight: bold; font-size: 10pt;
            background: #f0f0f0; padding: 3px 6px;
            margin: 10px 0 4px; border-left: 3px solid #000;
            display: flex; justify-content: space-between;
        }
        .partie-contexte { font-style: italic; font-size: 9pt; color: #444; padding: 3px 8px 5px; }

        .question { margin: 6px 0; padding: 5px 8px; border: 1px solid #ccc; page-break-inside: avoid; }
        .q-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
        .q-num  { font-weight: bold; font-size: 10pt; flex-shrink: 0; }
        .q-text { flex-grow: 1; font-size: 10pt; }
        .q-pts  { font-size: 9pt; font-weight: bold; flex-shrink: 0; white-space: nowrap; }

/* ---- Séparateur de pages ---- */
        .page-break { page-break-after: always; break-after: page; }

        /* ---- Tampon ---- */
        .tampon-fixed { position: fixed; bottom: 14mm; right: 14mm; width: 38mm; }

        /* ---- Barre d'outils ---- */
        @page { size: A4; margin: 0; }
        @media print {
            .no-print { display: none !important; }
            body { font-size: 10.5pt; padding: 12mm 14mm; }
            .question { page-break-inside: avoid; }
        }
        .toolbar { background: #2c3e50; color: #fff; padding: 10px 20px; display: flex; gap: 12px; align-items: center; }
        .toolbar h1 { font-size: 13pt; font-weight: bold; flex-grow: 1; }
        .toolbar a, .toolbar button {
            background: #fff; color: #2c3e50; border: none; padding: 6px 14px;
            border-radius: 4px; cursor: pointer; font-size: 10pt; font-weight: bold;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
        }
        .toolbar button:hover, .toolbar a:hover { background: #f0f0f0; }
    </style>
</head>
<body>

<div class="toolbar no-print">
    <h1>Impression — <?= s($eval['titre']) ?> — 3 Variantes</h1>
    <a href="print_grille_pratique.php?id=<?= $evalId ?>">Grille de notes</a>
    <a href="eval_pratique.php">&#8592; Retour</a>
    <button onclick="window.print()">Imprimer / PDF</button>
</div>

<?php foreach ($variantes as $lettre => $varParties):
    $isLast  = ($lettre === 'C');
    $qGlobal = 0;
?>
<div class="exam-page <?= !$isLast ? 'page-break' : '' ?>">

    <!-- En-tête identique à print_exams -->
    <div class="header">
        <div class="header-top">
            <?php if ($logoB64): ?>
            <img src="<?= $logoB64 ?>" class="header-logo" alt="OFPPT">
            <?php endif; ?>
            <div class="header-org">
                <?= $eval['etablissement'] ? s($eval['etablissement']) : 'Direction R&eacute;gionale &mdash; OFPPT' ?>
            </div>
        </div>
        <div class="header-year">Ann&eacute;e de Formation <?= s($annee) ?></div>
    </div>

    <div class="module-box">
        <div class="label">Contr&ocirc;le Pratique</div>
        <div class="titre">
            <?= $eval['module_code'] ? s($eval['module_code']) . ' &mdash; ' : '' ?><?= s($eval['module_intitule'] ?: $eval['titre']) ?>
            <span class="variante-badge">Variante <?= $lettre ?></span>
        </div>
    </div>

    <div class="info-row">
        <span>Fili&egrave;re&nbsp;: <strong><?= s($eval['filiere']) ?></strong></span>
        <span>Dur&eacute;e&nbsp;: <?= s($duree) ?></span>
    </div>
    <div class="info-row">
        <span>Groupe&nbsp;: ___________________________</span>
        <span>Note&nbsp;: _______ / <?= $noteMax ?></span>
    </div>
    <div class="info-row">
        <span>Nom et Pr&eacute;nom&nbsp;: _______________________________________________</span>
        <span>Date&nbsp;: _______________</span>
    </div>

    <?php if ($consignes): ?>
    <div class="consignes-box">
        <strong>Consignes&nbsp;:</strong> <?= nl2br(s($consignes)) ?>
    </div>
    <?php endif; ?>

    <!-- Parties dans l'ordre de la variante -->
    <?php foreach ($varParties as $p):
        $numAff = $p['num_affichage'];
        $pPts   = fpts((float)$p['points']);
        // Contexte depuis le JSON original (indexé par numéro original -1)
        $origIdx  = (int)$p['numero'] - 1;
        $contexte = $struct['parties'][$origIdx]['contexte'] ?? '';
    ?>
    <div class="partie-titre">
        <span>Partie <?= $numAff ?> &mdash; <?= s($p['titre']) ?></span>
        <span><?= $pPts ?> pts</span>
    </div>

    <?php if ($contexte): ?>
    <div class="partie-contexte"><?= nl2br(s($contexte)) ?></div>
    <?php endif; ?>

    <?php foreach ($p['questions'] as $q):
        $qGlobal++;
        $qPts = fpts((float)$q['points']);
    ?>
    <div class="question">
        <div class="q-header">
            <span class="q-num">Q<?= $qGlobal ?>.</span>
            <span class="q-text"><?= nl2br(s($q['texte'])) ?></span>
            <span class="q-pts"><?= $qPts ?> pts</span>
        </div>
    </div>
    <?php endforeach; ?>

    <?php endforeach; ?>

</div>
<?php endforeach; ?>

<?php if ($tamponB64): ?>
<img class="tampon-fixed" src="<?= $tamponB64 ?>" alt="Tampon OFPPT">
<?php endif; ?>

<script>
if (new URLSearchParams(location.search).get('auto') === '1') window.print();
</script>
</body>
</html>
