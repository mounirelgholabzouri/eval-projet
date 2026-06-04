<?php
/**
 * Import de questions depuis un JSON exporté par le PC distant
 * Matching par (texte + partie_nom + module_nom) — pas par ID, 0 conflit
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo    = getDB();
$msg    = '';
$errors = [];
$stats  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['json_file'])) {
    $content = file_get_contents($_FILES['json_file']['tmp_name']);
    $data    = json_decode($content, true);

    if (!$data || !isset($data['questions'])) {
        $errors[] = 'Fichier JSON invalide.';
    } else {
        $nbTotal   = 0;
        $nbImporte = 0;
        $nbSkip    = 0;

        $pdo->beginTransaction();
        try {
            foreach ($data['questions'] as $q) {
                $nbTotal++;

                // 1. Trouver ou créer le module
                $stmtM = $pdo->prepare("SELECT id FROM modules WHERE nom = ?");
                $stmtM->execute([$q['module_nom']]);
                $moduleId = $stmtM->fetchColumn();
                if (!$moduleId) {
                    $pdo->prepare("INSERT INTO modules (nom, actif) VALUES (?, 1)")
                        ->execute([$q['module_nom']]);
                    $moduleId = $pdo->lastInsertId();
                }

                // 2. Trouver ou créer la partie
                $stmtP = $pdo->prepare("SELECT id FROM parties WHERE module_id = ? AND nom = ?");
                $stmtP->execute([$moduleId, $q['partie_nom']]);
                $partieId = $stmtP->fetchColumn();
                if (!$partieId) {
                    $pdo->prepare("INSERT INTO parties (module_id, nom, ordre, actif) VALUES (?, ?, ?, 1)")
                        ->execute([$moduleId, $q['partie_nom'], $q['partie_ordre']]);
                    $partieId = $pdo->lastInsertId();
                }

                // 3. Vérifier si la question existe déjà (même texte dans même partie)
                $stmtQ = $pdo->prepare("SELECT id FROM questions WHERE partie_id = ? AND texte = ?");
                $stmtQ->execute([$partieId, $q['texte']]);
                if ($stmtQ->fetchColumn()) {
                    $nbSkip++;
                    continue;
                }

                // 4. Insérer la question avec nouveau ID (auto-increment local)
                $pdo->prepare("INSERT INTO questions (module_id, partie_id, texte, type, points, ordre, image_path)
                               VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $moduleId,
                        $partieId,
                        $q['texte'],
                        $q['type'],
                        $q['points'],
                        $q['ordre'],
                        $q['image_path'] ?? null,
                    ]);
                $questionId = $pdo->lastInsertId();

                // 5. Insérer les choix de réponse
                foreach ($q['choix'] ?? [] as $c) {
                    $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)")
                        ->execute([$questionId, $c['texte'], (int)$c['is_correct'], (int)$c['ordre']]);
                }

                $nbImporte++;
            }

            $pdo->commit();
            $stats = [
                'total'    => $nbTotal,
                'importe'  => $nbImporte,
                'skip'     => $nbSkip,
                'machine'  => $data['machine'] ?? '?',
                'exported' => $data['exported_at'] ?? '?',
            ];
            $msg = "Import terminé : {$nbImporte} question(s) importée(s), {$nbSkip} déjà présente(s).";

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Erreur BD : ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Import questions — PC distant</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container py-5" style="max-width:660px">
    <h2 class="h4 fw-bold mb-4">
        <i class="bi bi-cloud-download me-2 text-primary"></i>Import questions depuis PC distant
    </h2>

    <?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($msg): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
        <?php if ($stats): ?>
        <ul class="mb-0 mt-2 small">
            <li>Exporté depuis : <strong><?= htmlspecialchars($stats['machine']) ?></strong> le <?= htmlspecialchars($stats['exported']) ?></li>
            <li>Total dans le fichier : <?= $stats['total'] ?></li>
            <li>Importées : <strong class="text-success"><?= $stats['importe'] ?></strong></li>
            <li>Déjà présentes (ignorées) : <?= $stats['skip'] ?></li>
        </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 p-4 mb-3">
        <h5 class="fw-semibold mb-3">Workflow de synchronisation</h5>
        <ol class="small text-muted mb-4">
            <li class="mb-1">Sur le <strong>PC distant</strong> : ouvrir <code>admin/export_questions.php</code> → télécharge un fichier JSON</li>
            <li class="mb-1">Copier le fichier JSON sur ce PC (réseau, clé USB…)</li>
            <li>Uploader le fichier ci-dessous → nouvelles questions ajoutées, doublons ignorés</li>
        </ol>
        <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            <div class="mb-3">
                <label class="form-label fw-semibold">Fichier JSON (<code>questions_export_*.json</code>)</label>
                <input type="file" name="json_file" accept=".json" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-upload me-2"></i>Importer les questions
            </button>
        </form>
    </div>

    <div class="text-center">
        <a href="export_questions.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>Exporter les questions de CE PC
        </a>
        <a href="index.php" class="btn btn-outline-secondary btn-sm ms-2">
            <i class="bi bi-arrow-left me-1"></i>Retour
        </a>
    </div>
</div>
</body>
</html>
