<?php
/**
 * Impression du sujet d'évaluation pratique — A4 portrait
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo    = getDB();
$evalId = (int)($_GET['id'] ?? 0);
if (!$evalId) { echo '<p class="text-danger p-4">ID invalide.</p>'; exit; }

$ev = $pdo->prepare("SELECT * FROM eval_pratique WHERE id = ?");
$ev->execute([$evalId]);
$eval = $ev->fetch();
if (!$eval) { echo '<p class="text-danger p-4">Évaluation introuvable.</p>'; exit; }

$stmtP = $pdo->prepare("SELECT * FROM eval_pratique_parties WHERE eval_id = ? ORDER BY ordre, numero");
$stmtP->execute([$evalId]);
$parties = $stmtP->fetchAll();
foreach ($parties as &$p) {
    $stmtQ = $pdo->prepare("SELECT * FROM eval_pratique_questions WHERE partie_id = ? ORDER BY ordre, numero");
    $stmtQ->execute([$p['id']]);
    $p['questions'] = $stmtQ->fetchAll();
}
unset($p);

$tamponPath = __DIR__ . '/../assets/img/tampon_ofppt.png';
$tamponB64  = file_exists($tamponPath)
    ? 'data:image/png;base64,' . base64_encode(file_get_contents($tamponPath))
    : '';

$logoPath = __DIR__ . '/../assets/img/logo_ofppt.png';
$logob64  = file_exists($logoPath)
    ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))
    : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($eval['titre']) ?> — Sujet</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        @page { size: A4 portrait; margin: 15mm 18mm 20mm 18mm; }

        body { font-family: Arial, sans-serif; font-size: 10.5px; color: #000; background: #fff; line-height: 1.5; }

        /* ── Header ── */
        .page-header {
            display: flex;
            align-items: center;
            border: 1px solid #000;
            margin-bottom: 10px;
        }
        .page-header .logo-cell {
            width: 50px;
            padding: 4px 6px;
            border-right: 1px solid #000;
            text-align: center;
        }
        .page-header .logo-cell img { max-width: 42px; max-height: 42px; }
        .page-header .centre-cell {
            flex: 1;
            text-align: center;
            padding: 4px 8px;
            border-right: 1px solid #000;
        }
        .page-header .centre-cell .etab { font-size: 8px; text-transform: uppercase; }
        .page-header .centre-cell .titre-doc { font-size: 12px; font-weight: bold; }
        .page-header .centre-cell .module { font-size: 9px; }
        .page-header .right-cell { width: 110px; padding: 4px 6px; font-size: 8px; text-align: center; }

        /* ── Meta stagiaire ── */
        .stagiaire-meta {
            display: flex;
            gap: 10px;
            margin-bottom: 8px;
            font-size: 9.5px;
        }
        .stagiaire-meta .field {
            flex: 1;
            border-bottom: 1px solid #000;
            padding-bottom: 2px;
        }
        .stagiaire-meta .field.narrow { flex: 0 0 90px; }

        /* ── Consignes ── */
        .consignes {
            border: 1px dashed #888;
            padding: 5px 8px;
            font-size: 9px;
            margin-bottom: 10px;
            border-radius: 3px;
            background: #fafafa;
        }
        .consignes strong { font-size: 9.5px; }

        /* ── Parties ── */
        .partie {
            margin-bottom: 10px;
            page-break-inside: avoid;
        }
        .partie-header {
            background: #1a3a5c;
            color: #fff;
            padding: 4px 8px;
            font-size: 10px;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
        }
        .partie-header .pts { font-weight: normal; font-size: 9px; }

        .contexte {
            font-style: italic;
            font-size: 9px;
            color: #444;
            padding: 4px 8px;
            background: #f5f5f5;
            border-left: 3px solid #1a3a5c;
            margin-bottom: 4px;
        }

        .question {
            display: flex;
            gap: 6px;
            padding: 4px 8px;
            border-bottom: 1px solid #e0e0e0;
        }
        .question:last-child { border-bottom: none; }
        .question .qnum {
            font-weight: bold;
            min-width: 28px;
            color: #1a3a5c;
            font-size: 10px;
        }
        .question .qtexte { flex: 1; font-size: 10px; }
        .question .qpts {
            white-space: nowrap;
            font-size: 9px;
            color: #888;
            min-width: 50px;
            text-align: right;
        }
        .partie-body { border: 1px solid #c0c8d8; border-top: none; }

        /* ── Pied ── */
        .page-footer { text-align: center; font-size: 8px; color: #888; margin-top: 10px; }

        /* ── Tampon ── */
        .tampon-fixed { position: fixed; bottom: 14mm; right: 14mm; width: 38mm; }

        .no-print { text-align: center; padding: 12px; }
        .no-print .btn-print { background: #0d6efd; color: #fff; border: none; padding: 8px 24px; border-radius: 6px; font-size: 14px; cursor: pointer; }

        @media print { .no-print { display: none; } }
    </style>
</head>
<body>

<div class="no-print">
    <button class="btn-print" onclick="window.print()">&#128438; Imprimer le sujet</button>
    <a href="print_grille_pratique.php?id=<?= $evalId ?>" target="_blank"
       style="margin-left:12px; color:#198754; font-size:14px;">
        &#128203; Imprimer la grille
    </a>
    <a href="eval_pratique.php" style="margin-left:12px; color:#6c757d; font-size:13px;">
        &#8592; Retour
    </a>
</div>

<!-- ── En-tête officiel ── -->
<div class="page-header">
    <div class="logo-cell">
        <?php if ($logob64): ?>
        <img src="<?= $logob64 ?>" alt="Logo OFPPT">
        <?php else: ?>
        <span style="font-size:7px;font-weight:bold;">OFPPT</span>
        <?php endif; ?>
    </div>
    <div class="centre-cell">
        <?php if ($eval['etablissement']): ?>
        <div class="etab"><?= htmlspecialchars($eval['etablissement']) ?></div>
        <?php endif; ?>
        <div class="titre-doc"><?= htmlspecialchars($eval['titre']) ?></div>
        <?php if ($eval['module_code'] || $eval['module_intitule']): ?>
        <div class="module">
            <?= htmlspecialchars(($eval['module_code'] ? $eval['module_code'] . ' — ' : '') . $eval['module_intitule']) ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="right-cell">
        <?php if ($eval['filiere']): ?>
        <div><strong>Filière :</strong> <?= htmlspecialchars($eval['filiere']) ?></div>
        <?php endif; ?>
        <div><strong>Durée :</strong> <?= htmlspecialchars($eval['duree']) ?></div>
        <div><strong>Note :</strong> /<?= (int)$eval['note_max'] ?> pts</div>
        <?php if ($eval['annee']): ?>
        <div><?= htmlspecialchars($eval['annee']) ?></div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Informations stagiaire ── -->
<div class="stagiaire-meta">
    <div class="field">Nom et Prénom : ______________________________________________</div>
    <div class="field narrow">Groupe : ______________</div>
    <div class="field narrow">Date : ______________</div>
</div>

<?php
// Récupérer les consignes depuis structure_json
$struct = json_decode($eval['structure_json'] ?? '{}', true);
$consignes = $struct['consignes'] ?? '';
?>

<?php if ($consignes): ?>
<div class="consignes">
    <strong>Consignes :</strong> <?= nl2br(htmlspecialchars($consignes)) ?>
</div>
<?php endif; ?>

<!-- ── Parties ── -->
<?php foreach ($parties as $p): ?>
<div class="partie">
    <div class="partie-header">
        <span>Partie <?= (int)$p['numero'] ?> — <?= htmlspecialchars($p['titre']) ?></span>
        <span class="pts"><?= rtrim(rtrim(number_format((float)$p['points'], 2, '.', ''), '0'), '.') ?> pts</span>
    </div>
    <div class="partie-body">
        <?php if (!empty($struct['parties'][(int)$p['numero']-1]['contexte'])): ?>
        <div class="contexte"><?= nl2br(htmlspecialchars($struct['parties'][(int)$p['numero']-1]['contexte'])) ?></div>
        <?php endif; ?>
        <?php foreach ($p['questions'] as $q): ?>
        <div class="question">
            <div class="qnum">Q<?= (int)$q['numero'] ?>.</div>
            <div class="qtexte"><?= nl2br(htmlspecialchars($q['texte'])) ?></div>
            <div class="qpts">(<?= rtrim(rtrim(number_format((float)$q['points'], 2, '.', ''), '0'), '.') ?> pts)</div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<div class="page-footer">
    <?= htmlspecialchars($eval['etablissement'] ?: 'OFPPT') ?>
    <?= $eval['annee'] ? ' — ' . htmlspecialchars($eval['annee']) : '' ?>
</div>

<?php if ($tamponB64): ?>
<img class="tampon-fixed" src="<?= $tamponB64 ?>" alt="Tampon OFPPT">
<?php endif; ?>

</body>
</html>
