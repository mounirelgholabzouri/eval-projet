<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

// Module M205 = id 31
$MODULE_ID = 31;

// Trouver tous les doublons : stagiaires avec plusieurs sessions pour M205
$stmt = $pdo->prepare("
    SELECT s.stagiaire_id,
           COALESCE(st.nom, s.nom) AS nom,
           COALESCE(st.prenom, s.prenom) AS prenom,
           COUNT(*) AS nb,
           MAX(s.pourcentage) AS meilleur_pct
    FROM sessions_eval s
    LEFT JOIN stagiaires st ON st.id = s.stagiaire_id
    WHERE s.module_id = ?
      AND s.stagiaire_id IS NOT NULL
    GROUP BY s.stagiaire_id
    HAVING COUNT(*) > 1
    ORDER BY nom, prenom
");
$stmt->execute([$MODULE_ID]);
$doublons = $stmt->fetchAll();

// Aussi chercher les doublons par nom/prenom libre (sans stagiaire_id)
$stmtLibre = $pdo->prepare("
    SELECT s.nom, s.prenom,
           COUNT(*) AS nb,
           MAX(s.pourcentage) AS meilleur_pct
    FROM sessions_eval s
    WHERE s.module_id = ?
      AND s.stagiaire_id IS NULL
    GROUP BY s.nom, s.prenom
    HAVING COUNT(*) > 1
    ORDER BY s.nom, s.prenom
");
$stmtLibre->execute([$MODULE_ID]);
$doublonsLibres = $stmtLibre->fetchAll();

$supprime = 0;
$erreurs  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {

    // Supprimer doublons par stagiaire_id
    foreach ($doublons as $d) {
        // Récupérer toutes les sessions de ce stagiaire pour M205
        $stmt2 = $pdo->prepare("
            SELECT id, pourcentage, date_debut
            FROM sessions_eval
            WHERE module_id = ? AND stagiaire_id = ?
            ORDER BY pourcentage DESC, date_debut DESC
        ");
        $stmt2->execute([$MODULE_ID, $d['stagiaire_id']]);
        $sessions = $stmt2->fetchAll();

        // Garder la première (meilleur score), supprimer le reste
        $garder = true;
        foreach ($sessions as $sess) {
            if ($garder) { $garder = false; continue; }
            supprimerSession((int)$sess['id']);
            $supprime++;
        }
    }

    // Supprimer doublons libres (sans stagiaire_id)
    foreach ($doublonsLibres as $d) {
        $stmt2 = $pdo->prepare("
            SELECT id, pourcentage, date_debut
            FROM sessions_eval
            WHERE module_id = ? AND stagiaire_id IS NULL AND nom = ? AND prenom = ?
            ORDER BY pourcentage DESC, date_debut DESC
        ");
        $stmt2->execute([$MODULE_ID, $d['nom'], $d['prenom']]);
        $sessions = $stmt2->fetchAll();

        $garder = true;
        foreach ($sessions as $sess) {
            if ($garder) { $garder = false; continue; }
            supprimerSession((int)$sess['id']);
            $supprime++;
        }
    }

    // Recharger les doublons après suppression
    $stmt->execute([$MODULE_ID]);
    $doublons = $stmt->fetchAll();
    $stmtLibre->execute([$MODULE_ID]);
    $doublonsLibres = $stmtLibre->fetchAll();
}

$totalDoublons = count($doublons) + count($doublonsLibres);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Nettoyage doublons M205</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container py-4" style="max-width:800px">
    <h2 class="h4 fw-bold mb-4">
        <i class="bi bi-scissors me-2 text-danger"></i>Nettoyage doublons — M205
    </h2>

    <?php if ($supprime > 0): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle me-2"></i>
        <strong><?= $supprime ?> session(s) supprimée(s).</strong> Seul le meilleur score de chaque stagiaire a été conservé.
    </div>
    <?php endif; ?>

    <?php if ($totalDoublons === 0): ?>
    <div class="alert alert-info">
        <i class="bi bi-check-circle me-2"></i>Aucun doublon trouvé pour M205. La base est propre.
    </div>
    <a href="results.php?module_id=<?= $MODULE_ID ?>" class="btn btn-primary">
        <i class="bi bi-arrow-left me-1"></i>Retour aux résultats M205
    </a>
    <?php else: ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <strong><?= $totalDoublons ?> stagiaire(s) avec plusieurs sessions.</strong>
        La suppression conservera uniquement le meilleur score.
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Stagiaire</th>
                        <th class="text-center">Sessions</th>
                        <th class="text-center">Meilleur score</th>
                        <th class="text-center">Sera conservé</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($doublons as $d): ?>
                <tr>
                    <td><?= htmlspecialchars($d['prenom'] . ' ' . $d['nom']) ?></td>
                    <td class="text-center text-danger fw-bold"><?= $d['nb'] ?></td>
                    <td class="text-center"><?= number_format($d['meilleur_pct'], 1) ?>%</td>
                    <td class="text-center"><span class="badge bg-success">1 session</span></td>
                </tr>
                <?php endforeach; ?>
                <?php foreach ($doublonsLibres as $d): ?>
                <tr>
                    <td><?= htmlspecialchars($d['prenom'] . ' ' . $d['nom']) ?> <span class="text-muted small">(non inscrit)</span></td>
                    <td class="text-center text-danger fw-bold"><?= $d['nb'] ?></td>
                    <td class="text-center"><?= number_format($d['meilleur_pct'], 1) ?>%</td>
                    <td class="text-center"><span class="badge bg-success">1 session</span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <form method="POST" class="d-flex gap-3">
        <?= csrfField() ?>
        <input type="hidden" name="confirm" value="1">
        <button type="submit" class="btn btn-danger">
            <i class="bi bi-trash me-2"></i>Supprimer les doublons (garder meilleur score)
        </button>
        <a href="results.php?module_id=<?= $MODULE_ID ?>" class="btn btn-outline-secondary">Annuler</a>
    </form>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
