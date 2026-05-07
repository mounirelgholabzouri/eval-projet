<?php
/**
 * Grille de note de contrôle pratique — impression navigateur
 * Reproduit fidèlement le modèle PDF fourni.
 * URL : print_grille_pratique.php?id=<eval_id>&nb_lignes=25
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo    = getDB();
$evalId = (int)($_GET['id'] ?? 0);

if (!$evalId) { echo '<p class="text-danger p-4">ID invalide.</p>'; exit; }

$eval = $pdo->prepare("SELECT * FROM eval_pratique WHERE id = ?");
$eval->execute([$evalId]);
$ev = $eval->fetch();
if (!$ev) { echo '<p class="text-danger p-4">Évaluation introuvable.</p>'; exit; }

// Charger les parties et questions
$stmtP = $pdo->prepare("SELECT * FROM eval_pratique_parties WHERE eval_id = ? ORDER BY ordre, numero");
$stmtP->execute([$evalId]);
$parties = $stmtP->fetchAll();

foreach ($parties as &$p) {
    $stmtQ = $pdo->prepare("SELECT * FROM eval_pratique_questions WHERE partie_id = ? ORDER BY ordre, numero");
    $stmtQ->execute([$p['id']]);
    $p['questions'] = $stmtQ->fetchAll();
}
unset($p);

$nbLignes = max(10, min(40, (int)($_GET['nb_lignes'] ?? 20)));

// Titre et infos
$titre        = $ev['titre'] ?: 'Grille de note de contrôle pratique N°1';
$moduleCode   = $ev['module_code'];
$moduleIntit  = $ev['module_intitule'];
$filiere      = $ev['filiere'];
$etablissement = $ev['etablissement'];
$annee        = $ev['annee'];
$noteMax      = (int)$ev['note_max'];

// Tampon OFPPT
$tamponPath = __DIR__ . '/../assets/img/tampon_ofppt.png';
$tamponB64  = file_exists($tamponPath)
    ? 'data:image/png;base64,' . base64_encode(file_get_contents($tamponPath))
    : '';

// Calculer colonnes : chaque partie peut avoir 1 ou 2 questions
// Si >2 questions/partie : on regroupe par paires
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($titre) ?></title>
    <style>
        /* ── Reset ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        @page {
            size: A4 landscape;
            margin: 12mm 10mm 18mm 10mm;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 9px;
            color: #000;
            background: #fff;
        }

        /* ── En-tête ── */
        .grille-header {
            text-align: center;
            margin-bottom: 6px;
        }
        .grille-header h1 {
            font-size: 14px;
            font-weight: bold;
            border: 1px solid #000;
            padding: 6px 12px;
            display: inline-block;
            min-width: 60%;
        }
        .grille-header .sub {
            font-size: 9px;
            margin-top: 2px;
            color: #333;
        }
        .grille-header .meta {
            font-size: 8.5px;
            color: #555;
            margin-top: 2px;
        }

        /* ── Tableau principal ── */
        .grille-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .grille-table th,
        .grille-table td {
            border: 1px solid #aaa;
            padding: 2px 3px;
            vertical-align: middle;
            text-align: center;
        }

        /* NOM PRENOM col */
        .col-nom { width: 110px; font-size: 8px; font-weight: bold; text-align: left; padding-left: 4px; }

        /* En-têtes parties */
        .th-partie {
            background: #f0f0f0;
            font-size: 7.5px;
            font-weight: bold;
        }
        .th-question {
            background: #fafafa;
            font-size: 7px;
            font-weight: normal;
        }
        .th-total {
            background: #e8e8e8;
            font-size: 8px;
            font-weight: bold;
        }

        /* Cellules données */
        .td-data { height: 7mm; background: #fff; }
        .td-nom   { height: 7mm; background: #fff; text-align: left; }

        /* Pied de page / bouton impression */
        .no-print { text-align: center; padding: 12px; }
        .no-print .btn-print {
            background: #0d6efd; color: #fff; border: none;
            padding: 8px 24px; border-radius: 6px; font-size: 14px; cursor: pointer;
        }
        .no-print .btn-print:hover { background: #0b5ed7; }

        /* Tampon */
        .tampon-fixed {
            position: fixed;
            bottom: 14mm;
            right: 14mm;
            width: 38mm;
        }

        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<!-- Bouton impression -->
<div class="no-print" style="margin-bottom:10px;">
    <button class="btn-print" onclick="window.print()">
        &#128438; Imprimer la grille
    </button>
    <a href="print_sujet_pratique.php?id=<?= $evalId ?>" target="_blank"
       style="margin-left:12px; color:#0d6efd; font-size:14px;">
        &#128196; Voir le sujet
    </a>
    <a href="eval_pratique.php" style="margin-left:12px; color:#6c757d; font-size:13px;">
        &#8592; Retour
    </a>
    <label style="margin-left:20px; font-size:12px;">
        Lignes :
        <select onchange="location.href='?id=<?= $evalId ?>&nb_lignes='+this.value"
                style="font-size:12px; padding:2px 6px;">
            <?php foreach ([15,20,25,30,35] as $n): ?>
            <option value="<?= $n ?>" <?= $n == $nbLignes ? 'selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
        </select>
    </label>
</div>

<!-- ── Grille ── -->
<div class="grille-header">
    <h1><?= htmlspecialchars($titre) ?></h1>
    <div class="sub">
        <?php
        $subParts = [];
        if ($moduleCode)  $subParts[] = $moduleCode;
        if ($moduleIntit) $subParts[] = $moduleIntit;
        echo htmlspecialchars(implode(' — ', $subParts));
        ?>
    </div>
    <?php if ($filiere || $etablissement || $annee): ?>
    <div class="meta">
        <?= implode('&nbsp;&nbsp;|&nbsp;&nbsp;', array_filter([
            $filiere       ? htmlspecialchars($filiere) : '',
            $etablissement ? htmlspecialchars($etablissement) : '',
            $annee         ? htmlspecialchars($annee) : '',
        ])) ?>
    </div>
    <?php endif; ?>
</div>

<table class="grille-table">
    <thead>
        <!-- Ligne 1 : groupes parties + TOTAL -->
        <tr>
            <th class="col-nom th-total" rowspan="2">NOM PRENOM</th>
            <?php foreach ($parties as $p):
                $nbQ = count($p['questions']);
                $colspan = max(1, $nbQ);
            ?>
            <th class="th-partie" colspan="<?= $colspan ?>">
                PARTIE <?= (int)$p['numero'] ?>
                (<?= rtrim(rtrim(number_format((float)$p['points'], 2, '.', ''), '0'), '.') ?> pts)
            </th>
            <?php endforeach; ?>
            <th class="th-total" rowspan="2">TOTAL</th>
        </tr>
        <!-- Ligne 2 : questions -->
        <tr>
            <?php foreach ($parties as $p): ?>
                <?php foreach ($p['questions'] as $q): ?>
                <th class="th-question">
                    QUESTION<?= (int)$q['numero'] ?><br>
                    (<?= rtrim(rtrim(number_format((float)$q['points'], 2, '.', ''), '0'), '.') ?> pts)
                </th>
                <?php endforeach; ?>
                <?php if (empty($p['questions'])): ?>
                <th class="th-question">&nbsp;</th>
                <?php endif; ?>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php for ($i = 0; $i < $nbLignes; $i++): ?>
        <tr>
            <td class="td-nom td-data">&nbsp;</td>
            <?php foreach ($parties as $p): ?>
                <?php $nbQ = max(1, count($p['questions'])); ?>
                <?php for ($j = 0; $j < $nbQ; $j++): ?>
                <td class="td-data">&nbsp;</td>
                <?php endfor; ?>
            <?php endforeach; ?>
            <td class="td-data">&nbsp;</td>
        </tr>
        <?php endfor; ?>
    </tbody>
</table>

<?php if ($tamponB64): ?>
<img class="tampon-fixed" src="<?= $tamponB64 ?>" alt="Tampon OFPPT">
<?php endif; ?>

</body>
</html>
