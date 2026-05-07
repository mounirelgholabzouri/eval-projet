<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

// ── Suppression ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_eval_id'])) {
    $delId = (int)$_POST['delete_eval_id'];
    if ($delId > 0) {
        $pdo->prepare("DELETE FROM eval_pratique WHERE id = ?")->execute([$delId]);
    }
    header('Location: eval_pratique.php?deleted=1');
    exit;
}

// ── Liste des évaluations existantes ───────────────────────────
$evals = $pdo->query("
    SELECT ep.*,
           COUNT(DISTINCT epp.id) AS nb_parties,
           COUNT(DISTINCT epq.id) AS nb_questions
    FROM eval_pratique ep
    LEFT JOIN eval_pratique_parties  epp ON epp.eval_id = ep.id
    LEFT JOIN eval_pratique_questions epq ON epq.eval_id = ep.id
    GROUP BY ep.id
    ORDER BY ep.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Évaluation Pratique — Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .sujet-preview { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 1.5rem; max-height: 520px; overflow-y: auto; }
        .sujet-preview .sujet-titre { font-size: 1.3rem; font-weight: 700; text-align: center; margin-bottom: 0.25rem; }
        .sujet-preview .sujet-module { text-align: center; color: #555; font-size: .9rem; }
        .sujet-preview .sujet-meta   { text-align: center; font-size: .85rem; color: #666; margin-bottom: .75rem; }
        .sujet-preview .sujet-consignes { background: #fff3cd; border-left: 4px solid #ffc107; padding: .6rem 1rem; margin-bottom: 1rem; font-size: .9rem; border-radius: 4px; }
        .sujet-preview .sujet-partie { border: 1px solid #c8d6e5; border-radius: 6px; padding: 1rem; margin-bottom: 1rem; background: #fff; }
        .sujet-preview .sujet-partie h3 { font-size: 1rem; font-weight: 600; color: #1a3a5c; margin-bottom: .5rem; }
        .sujet-preview .sujet-partie h3 .pts { font-weight: 400; font-size: .85rem; color: #6c757d; }
        .sujet-preview .contexte { font-style: italic; color: #555; font-size: .88rem; margin-bottom: .5rem; }
        .sujet-preview .sujet-question { padding: .4rem .6rem; border-left: 3px solid #0d6efd; margin-bottom: .4rem; background: #f0f4ff; border-radius: 3px; font-size: .9rem; }
        #spinner-overlay { display:none; position:fixed; inset:0; background:rgba(255,255,255,.85); z-index:9999; flex-direction:column; align-items:center; justify-content:center; gap:1rem; }
        #spinner-overlay.show { display:flex; }
        .progress-dots span { display:inline-block; width:10px; height:10px; border-radius:50%; background:#0d6efd; margin:0 3px; animation:bounce 1.2s infinite; }
        .progress-dots span:nth-child(2) { animation-delay:.2s; }
        .progress-dots span:nth-child(3) { animation-delay:.4s; }
        @keyframes bounce { 0%,60%,100%{transform:translateY(0)} 30%{transform:translateY(-14px)} }
    </style>
</head>
<body class="bg-light">

<?php include __DIR__ . '/partials/navbar.php'; ?>

<!-- Spinner génération -->
<div id="spinner-overlay">
    <div class="progress-dots"><span></span><span></span><span></span></div>
    <p class="fw-semibold text-primary fs-5">Génération du sujet en cours…</p>
    <p class="text-muted small">L'IA analyse votre sujet et structure l'évaluation pratique</p>
</div>

<div class="container-fluid py-4 px-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h4 fw-bold mb-0">
            <i class="bi bi-clipboard2-check me-2 text-primary"></i>Évaluation Pratique
        </h2>
        <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#panelGenerer">
            <i class="bi bi-stars me-1"></i>Nouvelle évaluation IA
        </button>
    </div>

    <?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-1"></i>Évaluation supprimée.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ── Formulaire génération ── -->
    <div class="collapse <?= empty($evals) ? 'show' : '' ?> mb-4" id="panelGenerer">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-primary text-white rounded-top-4 py-3 px-4">
                <i class="bi bi-stars me-2"></i><strong>Générer un sujet pratique avec l'IA</strong>
            </div>
            <div class="card-body p-4">
                <form id="formGenerer" enctype="multipart/form-data">

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Code module</label>
                            <input type="text" name="module_code" class="form-control" placeholder="ex: IDOCC201">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Intitulé du module</label>
                            <input type="text" name="module_intitule" class="form-control" placeholder="ex: Installation et Configuration des Postes de Travail">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Filière</label>
                            <input type="text" name="filiere" class="form-control" placeholder="ex: TSRI / TDI / TSSID…">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Établissement</label>
                            <input type="text" name="etablissement" class="form-control" placeholder="ex: ISTA NTIC Hay Riad">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Année</label>
                            <input type="text" name="annee" class="form-control" value="<?= date('Y') . '/' . (date('Y')+1) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Durée</label>
                            <input type="text" name="duree" class="form-control" value="2h">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Note max</label>
                            <select name="note_max" class="form-select">
                                <option value="20" selected>Sur 20 pts</option>
                                <option value="40">Sur 40 pts</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Nombre de parties</label>
                            <select name="nb_parties" class="form-select">
                                <option value="2">2 parties</option>
                                <option value="3">3 parties</option>
                                <option value="4" selected>4 parties</option>
                                <option value="5">5 parties</option>
                                <option value="6">6 parties</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Questions / partie</label>
                            <select name="questions_par_partie" class="form-select">
                                <option value="1">1 question</option>
                                <option value="2" selected>2 questions</option>
                                <option value="3">3 questions</option>
                                <option value="4">4 questions</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Document (optionnel)</label>
                            <input type="file" name="document" class="form-control" accept=".pdf,.docx,.txt,.md">
                            <div class="form-text">PDF, DOCX, TXT, MD — max 20 Mo</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Sujet / prompt <span class="text-muted">(requis si pas de document)</span></label>
                        <textarea name="prompt_sujet" class="form-control" rows="4"
                            placeholder="Décrivez le sujet de l'évaluation pratique. Ex : « Installation et configuration d'un réseau local avec switch manageable, adressage IP statique et VLAN » ou copiez les objectifs du module..."></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-stars me-1"></i>Générer le sujet pratique
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#panelGenerer">
                            Annuler
                        </button>
                    </div>
                </form>

                <!-- ── Résultat génération ── -->
                <div id="resultatGeneration" class="mt-4 d-none">
                    <hr>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold mb-0"><i class="bi bi-file-earmark-text me-2 text-success"></i>Sujet généré</h5>
                        <div class="d-flex gap-2" id="actionsPost">
                            <a id="btnPrintSujet" href="#" target="_blank" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-printer me-1"></i>Imprimer le sujet
                            </a>
                            <a id="btnPrintGrille" href="#" target="_blank" class="btn btn-success btn-sm">
                                <i class="bi bi-grid-3x3-gap me-1"></i>Imprimer la grille
                            </a>
                        </div>
                    </div>
                    <div id="sujetPreview" class="sujet-preview mb-3"></div>
                    <div class="alert alert-success d-flex align-items-center gap-2 mb-0">
                        <i class="bi bi-check-circle-fill fs-5"></i>
                        <span>Évaluation enregistrée. Retrouvez-la dans la liste ci-dessous.</span>
                    </div>
                </div>

                <div id="erreurGeneration" class="mt-3 alert alert-danger d-none"></div>
            </div>
        </div>
    </div>

    <!-- ── Liste des évaluations ── -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white py-3 px-4 border-bottom">
            <strong><i class="bi bi-list-check me-2"></i>Évaluations pratiques générées (<?= count($evals) ?>)</strong>
        </div>
        <div class="card-body p-0">
            <?php if (empty($evals)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-clipboard2 display-4 d-block mb-2"></i>
                Aucune évaluation pratique générée pour l'instant.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Titre</th>
                            <th>Module</th>
                            <th>Filière</th>
                            <th class="text-center">Parties</th>
                            <th class="text-center">Note max</th>
                            <th>Date</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($evals as $ev): ?>
                        <tr>
                            <td class="fw-semibold"><?= sanitize($ev['titre']) ?></td>
                            <td>
                                <?php if ($ev['module_code']): ?>
                                    <span class="badge bg-primary me-1"><?= sanitize($ev['module_code']) ?></span>
                                <?php endif; ?>
                                <small class="text-muted"><?= sanitize($ev['module_intitule']) ?></small>
                            </td>
                            <td><small><?= sanitize($ev['filiere']) ?></small></td>
                            <td class="text-center">
                                <span class="badge bg-info"><?= (int)$ev['nb_parties'] ?> parties</span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?= (int)$ev['note_max'] ?> pts</span>
                            </td>
                            <td><small class="text-muted"><?= date('d/m/Y H:i', strtotime($ev['created_at'])) ?></small></td>
                            <td class="text-end">
                                <div class="d-flex gap-1 justify-content-end">
                                    <a href="print_sujet_pratique.php?id=<?= $ev['id'] ?>" target="_blank"
                                       class="btn btn-outline-primary btn-sm" title="Imprimer le sujet">
                                        <i class="bi bi-file-text"></i>
                                    </a>
                                    <a href="print_grille_pratique.php?id=<?= $ev['id'] ?>" target="_blank"
                                       class="btn btn-success btn-sm" title="Imprimer la grille">
                                        <i class="bi bi-grid-3x3-gap"></i>
                                    </a>
                                    <button class="btn btn-outline-danger btn-sm"
                                            onclick="confirmerSuppr(<?= $ev['id'] ?>, '<?= addslashes(sanitize($ev['titre'])) ?>')"
                                            title="Supprimer">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Modal suppression -->
<div class="modal fade" id="modalSuppr" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Confirmer la suppression</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Supprimer <strong id="evalTitreSuppr"></strong> ? Cette action est irréversible.
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="delete_eval_id" id="deleteEvalId">
                    <button type="submit" class="btn btn-danger">Supprimer</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function confirmerSuppr(id, titre) {
    document.getElementById('deleteEvalId').value  = id;
    document.getElementById('evalTitreSuppr').textContent = titre;
    new bootstrap.Modal(document.getElementById('modalSuppr')).show();
}

document.getElementById('formGenerer').addEventListener('submit', async function(e) {
    e.preventDefault();

    const overlay = document.getElementById('spinner-overlay');
    overlay.classList.add('show');

    const errDiv = document.getElementById('erreurGeneration');
    const resDiv = document.getElementById('resultatGeneration');
    errDiv.classList.add('d-none');
    resDiv.classList.add('d-none');

    const fd = new FormData(this);

    try {
        const resp = await fetch('api_generate_eval_pratique.php', { method: 'POST', body: fd });
        const data = await resp.json();

        overlay.classList.remove('show');

        if (!data.success) {
            errDiv.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>' + (data.error || 'Erreur inconnue.');
            errDiv.classList.remove('d-none');
            return;
        }

        // Afficher le sujet
        document.getElementById('sujetPreview').innerHTML = data.sujet_texte || '';

        const id = data.eval_id;
        document.getElementById('btnPrintSujet').href  = 'print_sujet_pratique.php?id=' + id;
        document.getElementById('btnPrintGrille').href = 'print_grille_pratique.php?id=' + id;

        resDiv.classList.remove('d-none');
        resDiv.scrollIntoView({ behavior: 'smooth', block: 'start' });

        // Recharger la liste après 2s
        setTimeout(() => location.reload(), 3000);

    } catch (err) {
        overlay.classList.remove('show');
        errDiv.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>Erreur réseau : ' + err.message;
        errDiv.classList.remove('d-none');
    }
});
</script>
</body>
</html>
