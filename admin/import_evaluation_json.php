<?php
/**
 * Import d'un fichier JSON d'évaluation externe (format "evaluation.questions")
 * Convertit les types QCM_choix_unique/multiple, Vrai_Faux, Association, Completion, Scenario_pratique
 * vers le format interne (qcm, multiple, vrai_faux, texte_libre)
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo    = getDB();
$msg    = '';
$errors = [];
$stats  = [];

// Mapping des types externes → types internes
// Supporte les deux formats : "QCM_choix_unique" (ancien) et "QCM"/"VF" (nouveau)
function mapType(string $type): string {
    return match (strtoupper($type)) {
        'QCM', 'QCM_CHOIX_UNIQUE'        => 'qcm',
        'QCM_CHOIX_MULTIPLE'              => 'multiple',
        'VF', 'VRAI_FAUX',
        'VRAI_FAUX_JUSTIFICATION'         => 'vrai_faux',
        default                           => 'texte_libre',
    };
}

// Parse "A) texte option" → ['lettre' => 'A', 'texte' => 'texte option']
function parseOption(string $opt): array {
    if (preg_match('/^([A-Z])\)\s*(.+)$/s', trim($opt), $m)) {
        return ['lettre' => $m[1], 'texte' => trim($m[2])];
    }
    return ['lettre' => '', 'texte' => trim($opt)];
}

// Normalise les options en tableau uniforme [['lettre'=>'A','texte'=>'...'], ...]
// Accepte : objet {"A":"texte"} OU tableau ["A) texte", ...]
function normaliserOptions(mixed $options): array {
    if (!$options) return [];
    $result = [];
    if (is_array($options) && array_keys($options) !== range(0, count($options) - 1)) {
        // Objet associatif {"A": "texte", "B": "texte"}
        foreach ($options as $lettre => $texte) {
            $result[] = ['lettre' => strtoupper((string)$lettre), 'texte' => trim((string)$texte)];
        }
    } else {
        // Tableau indexé ["A) texte", ...]
        foreach ($options as $opt) {
            $result[] = parseOption((string)$opt);
        }
    }
    return $result;
}

// Normalise reponse_correcte VF : booléen true/false → "Vrai"/"Faux"
function normaliserReponseVF(mixed $rep): string {
    if (is_bool($rep)) return $rep ? 'Vrai' : 'Faux';
    $s = strtolower(trim((string)$rep));
    return in_array($s, ['true', 'vrai', '1', 'yes', 'oui'], true) ? 'Vrai' : 'Faux';
}

// Charge la liste des modules pour le sélecteur
$tousModules = $pdo->query("SELECT id, nom FROM modules ORDER BY nom")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupère le JSON depuis le fichier uploadé OU le textarea
    $raw = '';
    if (!empty($_FILES['json_file']['tmp_name'])) {
        $raw = file_get_contents($_FILES['json_file']['tmp_name']);
    } elseif (!empty($_POST['json_text'])) {
        $raw = trim($_POST['json_text']);
    }

    $moduleDestId  = (int)($_POST['module_dest_id'] ?? 0);   // 0 = nouveau module
    $moduleDestNom = trim($_POST['module_dest_nom'] ?? '');   // nom si nouveau

    if ($raw === '') {
        $errors[] = 'Veuillez fournir un fichier JSON ou coller le contenu JSON.';
    } else {
    $data = json_decode($raw, true);

    // Accepte {"evaluation":{...}} ou directement {"module":...,"questions":[...]}
    if (isset($data['evaluation'])) {
        $eval = $data['evaluation'];
    } elseif (isset($data['questions'])) {
        $eval = $data;
    } else {
        $eval = null;
    }
    if (!$eval || !isset($eval['questions'])) {
        $cles = implode(', ', array_keys($data ?? []));
        $errors[] = 'Fichier JSON invalide — structure "evaluation.questions" introuvable. Clés trouvées : ' . ($cles ?: '(aucune)');
    } else {
        $partieNom  = $eval['titre']  ?? 'Général';
        $nbTotal    = 0;
        $nbImporte  = 0;
        $nbSkip     = 0;

        $pdo->beginTransaction();
        try {
            // ── Résolution du module destination ──────────────────────────
            if ($moduleDestId > 0) {
                // Module existant choisi dans la liste
                $moduleId  = $moduleDestId;
                $moduleNom = $pdo->prepare("SELECT nom FROM modules WHERE id=?")->execute([$moduleId])
                    ? ($pdo->query("SELECT nom FROM modules WHERE id=$moduleId")->fetchColumn() ?: 'Module #'.$moduleId)
                    : 'Module #'.$moduleId;
                // Récupère le nom proprement
                $stmtNom = $pdo->prepare("SELECT nom FROM modules WHERE id=?");
                $stmtNom->execute([$moduleId]);
                $moduleNom = $stmtNom->fetchColumn() ?: 'Module #'.$moduleId;
            } else {
                // Nouveau module : utilise le nom saisi ou celui du JSON
                $moduleNom = $moduleDestNom !== '' ? $moduleDestNom : ($eval['module'] ?? 'Module importé');
                $stmtM = $pdo->prepare("SELECT id FROM modules WHERE nom = ?");
                $stmtM->execute([$moduleNom]);
                $moduleId = $stmtM->fetchColumn();
                if (!$moduleId) {
                    $pdo->prepare("INSERT INTO modules (nom, actif) VALUES (?, 1)")
                        ->execute([$moduleNom]);
                    $moduleId = (int)$pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO evaluations (module_id, nom, type, duree_minutes, note_max) VALUES (?, ?, 'qcm', 30, 20)")
                        ->execute([$moduleId, $moduleNom]);
                }
            }

            // Trouver ou créer la partie
            $stmtP = $pdo->prepare("SELECT id FROM parties WHERE module_id = ? AND nom = ?");
            $stmtP->execute([$moduleId, $partieNom]);
            $partieId = $stmtP->fetchColumn();
            if (!$partieId) {
                $stmtOrdre = $pdo->prepare("SELECT COALESCE(MAX(ordre),0)+1 FROM parties WHERE module_id = ?");
                $stmtOrdre->execute([$moduleId]);
                $ordre = (int)$stmtOrdre->fetchColumn();
                $pdo->prepare("INSERT INTO parties (module_id, nom, ordre, actif) VALUES (?, ?, ?, 1)")
                    ->execute([$moduleId, $partieNom, $ordre]);
                $partieId = $pdo->lastInsertId();
            }

            foreach ($eval['questions'] as $idx => $q) {
                $nbTotal++;
                // Supporte "enonce" (ancien format) et "question" (nouveau format)
                $texte  = trim($q['enonce'] ?? $q['question'] ?? '');
                $type   = mapType($q['type'] ?? '');
                $points = (float)($q['points'] ?? 1);

                if ($texte === '') { $nbSkip++; continue; }

                // Doublons
                $stmtQ = $pdo->prepare("SELECT id FROM questions WHERE partie_id = ? AND texte = ?");
                $stmtQ->execute([$partieId, $texte]);
                if ($stmtQ->fetchColumn()) { $nbSkip++; continue; }

                $pdo->prepare("INSERT INTO questions (module_id, partie_id, texte, type, points, ordre)
                               VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$moduleId, $partieId, $texte, $type, $points, $idx + 1]);
                $questionId = $pdo->lastInsertId();

                $options = normaliserOptions($q['options'] ?? []);

                if ($type === 'qcm' || $type === 'multiple') {
                    // reponses_correctes (tableau) ou reponse_correcte (string)
                    $correctes = isset($q['reponses_correctes'])
                        ? (array)$q['reponses_correctes']
                        : (isset($q['reponse_correcte']) ? [(string)$q['reponse_correcte']] : []);
                    // Normalise en majuscules
                    $correctes = array_map('strtoupper', $correctes);

                    foreach ($options as $ord => $opt) {
                        $is_correct = in_array(strtoupper($opt['lettre']), $correctes, true) ? 1 : 0;
                        $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)")
                            ->execute([$questionId, $opt['texte'], $is_correct, $ord + 1]);
                    }

                } elseif ($type === 'vrai_faux') {
                    $correcte = normaliserReponseVF($q['reponse_correcte'] ?? 'Vrai');
                    foreach (['Vrai', 'Faux'] as $ord => $label) {
                        $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)")
                            ->execute([$questionId, $label, ($label === $correcte ? 1 : 0), $ord + 1]);
                    }

                } elseif ($type === 'texte_libre') {
                    if (isset($q['elements_droite'])) {
                        foreach (normaliserOptions($q['elements_droite']) as $ord => $opt) {
                            $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, 0, ?)")
                                ->execute([$questionId, $opt['texte'], $ord + 1]);
                        }
                    }
                    if (isset($q['reponses_correctes']) && is_array($q['reponses_correctes'])) {
                        foreach ($q['reponses_correctes'] as $ord => $rep) {
                            if (is_string($rep)) {
                                $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, 1, ?)")
                                    ->execute([$questionId, $rep, $ord + 1]);
                            }
                        }
                    }
                    if (isset($q['reponse_attendue_cles']) && is_array($q['reponse_attendue_cles'])) {
                        foreach ($q['reponse_attendue_cles'] as $ord => $cle) {
                            $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, 1, ?)")
                                ->execute([$questionId, $cle, $ord + 1]);
                        }
                    }
                }

                $nbImporte++;
            }

            $pdo->commit();
            $stats = [
                'total'   => $nbTotal,
                'importe' => $nbImporte,
                'skip'    => $nbSkip,
                'module'  => $moduleNom,
                'partie'  => $partieNom,
            ];
            $msg = "Import terminé : {$nbImporte} question(s) importée(s), {$nbSkip} déjà présente(s).";

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Erreur BD : ' . $e->getMessage();
        }
    }
    } // fin else $raw !== ''
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Import évaluation JSON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>
<div class="container py-5" style="max-width:660px">
    <h2 class="h4 fw-bold mb-4">
        <i class="bi bi-file-earmark-arrow-up me-2 text-primary"></i>Import évaluation JSON externe
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
            <li>Module créé/trouvé : <strong><?= htmlspecialchars($stats['module']) ?></strong></li>
            <li>Partie créée/trouvée : <strong><?= htmlspecialchars($stats['partie']) ?></strong></li>
            <li>Total dans le fichier : <?= $stats['total'] ?></li>
            <li>Importées : <strong class="text-success"><?= $stats['importe'] ?></strong></li>
            <li>Déjà présentes (ignorées) : <?= $stats['skip'] ?></li>
        </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 p-4 mb-3">
        <p class="small text-muted mb-3">
            Format attendu : <code>{"evaluation": {"module": "...", "titre": "...", "questions": [...]}}</code>
            ou directement <code>{"module": "...", "questions": [...]}</code>.<br>
            Types supportés : QCM_choix_unique, QCM_choix_multiple, Vrai_Faux, Vrai_Faux_Justification, Association, Completion, Scenario_pratique.
        </p>

        <!-- Onglets Fichier / Coller -->
        <ul class="nav nav-tabs mb-3" id="importTabs">
            <li class="nav-item">
                <button class="nav-link active" id="tab-file-btn" onclick="switchTab('file')" type="button">
                    <i class="bi bi-file-earmark-arrow-up me-1"></i>Fichier JSON
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="tab-paste-btn" onclick="switchTab('paste')" type="button">
                    <i class="bi bi-clipboard-fill me-1"></i>Coller JSON
                </button>
            </li>
        </ul>

        <form method="POST" enctype="multipart/form-data" id="importForm">
            <?= csrfField() ?>

            <!-- Sélecteur module destination -->
            <div class="mb-4">
                <label class="form-label fw-semibold">Module destination <span class="text-danger">*</span></label>
                <select name="module_dest_id" id="moduleDestSelect" class="form-select" onchange="toggleNouveauModule(this.value)">
                    <option value="0">— Nouveau module —</option>
                    <?php foreach ($tousModules as $m): ?>
                    <option value="<?= $m['id'] ?>"
                        <?= (isset($_POST['module_dest_id']) && (int)$_POST['module_dest_id'] === (int)$m['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($m['nom']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div id="nouveauModuleBox" class="mt-2" style="display:none">
                    <input type="text" name="module_dest_nom" class="form-control"
                           placeholder="Nom du nouveau module (laisser vide = utiliser le nom dans le JSON)"
                           value="<?= htmlspecialchars($_POST['module_dest_nom'] ?? '') ?>">
                    <div class="form-text">Laisser vide pour utiliser le champ <code>module</code> du JSON.</div>
                </div>
            </div>
            <hr class="my-3">

            <!-- Mode fichier -->
            <div id="tab-file">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Fichier JSON</label>
                    <input type="file" name="json_file" accept=".json" class="form-control" id="fileInput">
                </div>
            </div>

            <!-- Mode textarea -->
            <div id="tab-paste" style="display:none">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Contenu JSON</label>
                    <textarea name="json_text" id="jsonTextarea" class="form-control font-monospace"
                              rows="12" placeholder='{"evaluation": {"module": "...", "questions": [...]}}'
                              style="font-size:.8rem"></textarea>
                    <div class="d-flex gap-2 mt-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="pasteFromClipboard()">
                            <i class="bi bi-clipboard me-1"></i>Coller depuis le presse-papiers
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="document.getElementById('jsonTextarea').value=''">
                            <i class="bi bi-x-circle me-1"></i>Effacer
                        </button>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-upload me-2"></i>Importer
            </button>
        </form>
    </div>

    <script>
    function toggleNouveauModule(val) {
        document.getElementById('nouveauModuleBox').style.display = val === '0' ? '' : 'none';
    }
    // Restaure l'état après erreur de validation
    toggleNouveauModule(document.getElementById('moduleDestSelect').value);

    function switchTab(tab) {
        document.getElementById('tab-file').style.display  = tab === 'file'  ? '' : 'none';
        document.getElementById('tab-paste').style.display = tab === 'paste' ? '' : 'none';
        document.getElementById('tab-file-btn').classList.toggle('active',  tab === 'file');
        document.getElementById('tab-paste-btn').classList.toggle('active', tab === 'paste');
        // Vide l'autre champ pour éviter un double envoi
        if (tab === 'paste') document.getElementById('fileInput').value = '';
        else document.getElementById('jsonTextarea').value = '';
    }
    async function pasteFromClipboard() {
        try {
            const text = await navigator.clipboard.readText();
            document.getElementById('jsonTextarea').value = text;
        } catch(e) {
            alert('Accès presse-papiers refusé — coller manuellement avec Ctrl+V.');
        }
    }
    </script>

    <div class="text-center">
        <a href="questions.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-list-ul me-1"></i>Gérer les questions
        </a>
        <a href="index.php" class="btn btn-outline-secondary btn-sm ms-2">
            <i class="bi bi-arrow-left me-1"></i>Retour
        </a>
    </div>
</div>
</body>
</html>
