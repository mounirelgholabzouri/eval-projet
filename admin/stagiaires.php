<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$message = null;
$messageType = 'success';

// ── Traitement POST ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'creer') {
        $nom      = trim($_POST['nom'] ?? '');
        $prenom   = trim($_POST['prenom'] ?? '');
        $groupeId = (int)($_POST['groupe_id'] ?? 0);
        $annee    = trim($_POST['annee_scolaire'] ?? '');

        if (strlen($nom) < 2 || strlen($prenom) < 2 || $groupeId <= 0 || !$annee) {
            $message = "Tous les champs sont obligatoires.";
            $messageType = 'danger';
        } else {
            try {
                $result = creerStagiaireAdmin($nom, $prenom, $groupeId, $annee);
                $message = "Stagiaire créé. Login : <strong>" . sanitize($result['login']) . "</strong> — Mot de passe par défaut : <strong>123456</strong>";
            } catch (RuntimeException $e) {
                $message = sanitize($e->getMessage());
                $messageType = 'danger';
            }
        }

    } elseif ($action === 'modifier') {
        $id       = (int)($_POST['id'] ?? 0);
        $nom      = trim($_POST['nom'] ?? '');
        $prenom   = trim($_POST['prenom'] ?? '');
        $groupeId = (int)($_POST['groupe_id'] ?? 0);
        $annee    = trim($_POST['annee_scolaire'] ?? '');
        $login    = trim($_POST['login'] ?? '');

        if (!$id || strlen($nom) < 2 || strlen($prenom) < 2 || $groupeId <= 0 || !$annee || strlen($login) < 3) {
            $message = "Données invalides.";
            $messageType = 'danger';
        } elseif (loginExists($login, $id)) {
            $message = "Ce login est déjà utilisé par un autre stagiaire.";
            $messageType = 'danger';
        } else {
            modifierStagiaire($id, $nom, $prenom, $groupeId, $annee, $login);
            $message = "Stagiaire mis à jour.";
        }

    } elseif ($action === 'supprimer') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id && supprimerStagiaire($id)) {
            $message = "Stagiaire supprimé.";
        } else {
            $message = "Impossible de supprimer : ce stagiaire a des évaluations enregistrées.";
            $messageType = 'danger';
        }

    } elseif ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            resetPasswordStagiaire($id);
            $s = getStagiaire($id);
            $message = "Mot de passe réinitialisé à <strong>123456</strong> pour <strong>" . sanitize($s['prenom'] . ' ' . strtoupper($s['nom'])) . "</strong>.";
        }
    }
}

// ── Données ────────────────────────────────────────────────────
$groupes     = getGroupes();
$annees      = getAnneesDisponibles();
$anneeActive = $_GET['annee'] ?? getAnneeCourante();
$groupeFiltre= isset($_GET['groupe_id']) ? (int)$_GET['groupe_id'] : null;
$stagiaires  = getStagiaires($groupeFiltre ?: null, $anneeActive ?: null);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stagiaires — Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/navbar.php'; ?>

<div class="container py-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0"><i class="bi bi-people-fill me-2 text-primary"></i>Stagiaires</h1>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-primary fs-6"><?= count($stagiaires) ?> stagiaire(s)</span>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCreer">
                <i class="bi bi-person-plus me-1"></i>Ajouter
            </button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show rounded-3">
            <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filtres -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Année scolaire</label>
                    <select name="annee" class="form-select">
                        <option value="">Toutes les années</option>
                        <?php foreach ($annees as $a): ?>
                            <option value="<?= $a ?>" <?= $a === $anneeActive ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Groupe</label>
                    <select name="groupe_id" class="form-select">
                        <option value="">Tous les groupes</option>
                        <?php foreach ($groupes as $g): ?>
                            <option value="<?= $g['id'] ?>" <?= $g['id'] == $groupeFiltre ? 'selected' : '' ?>><?= sanitize($g['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filtrer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tableau -->
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Stagiaire</th>
                        <th>Groupe</th>
                        <th>Année</th>
                        <th class="text-center">Login</th>
                        <th class="text-center">Éval.</th>
                        <th class="text-center">Moyenne</th>
                        <th>Inscrit le</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($stagiaires)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Aucun stagiaire trouvé.</td></tr>
                <?php else: ?>
                    <?php foreach ($stagiaires as $s): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= sanitize($s['prenom']) ?> <?= sanitize(strtoupper($s['nom'])) ?></div>
                            <?php if (!empty($s['must_change_password'])): ?>
                                <span class="badge bg-warning text-dark small"><i class="bi bi-key me-1"></i>Mdp par défaut</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-secondary"><?= sanitize($s['groupe_nom']) ?></span></td>
                        <td><?= sanitize($s['annee_scolaire']) ?></td>
                        <td class="text-center">
                            <?php if (!empty($s['login'])): ?>
                                <span class="badge bg-success"><i class="bi bi-at me-1"></i><?= sanitize($s['login']) ?></span>
                            <?php else: ?>
                                <span class="badge bg-light text-muted border">Sans compte</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($s['nb_evaluations'] > 0): ?>
                                <span class="badge bg-info"><?= $s['nb_evaluations'] ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($s['moy_pourcentage'] !== null): ?>
                                <?php $mention = getMention((float)$s['moy_pourcentage']); ?>
                                <span class="badge bg-<?= $mention['class'] ?>"><?= round($s['moy_pourcentage'], 1) ?>%</span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= date('d/m/Y', strtotime($s['created_at'])) ?></td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary btn-modifier"
                                    data-id="<?= $s['id'] ?>"
                                    data-nom="<?= sanitize($s['nom']) ?>"
                                    data-prenom="<?= sanitize($s['prenom']) ?>"
                                    data-groupe="<?= $s['groupe_id'] ?>"
                                    data-annee="<?= sanitize($s['annee_scolaire']) ?>"
                                    data-login="<?= sanitize($s['login'] ?? '') ?>"
                                    title="Modifier"><i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-outline-warning btn-reset"
                                    data-id="<?= $s['id'] ?>"
                                    data-nom="<?= sanitize($s['prenom'] . ' ' . strtoupper($s['nom'])) ?>"
                                    title="Réinitialiser mot de passe"><i class="bi bi-key"></i>
                                </button>
                                <button class="btn btn-outline-danger btn-supprimer"
                                    data-id="<?= $s['id'] ?>"
                                    data-nom="<?= sanitize($s['prenom'] . ' ' . strtoupper($s['nom'])) ?>"
                                    data-nb="<?= (int)$s['nb_evaluations'] ?>"
                                    title="Supprimer"><i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Créer -->
<div class="modal fade" id="modalCreer" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="creer">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Ajouter un stagiaire</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Login généré automatiquement (<code>Prenom.NOM</code>), mot de passe par défaut : <strong>123456</strong>
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Prénom <span class="text-danger">*</span></label>
                        <input type="text" name="prenom" class="form-control" placeholder="Jean" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Nom <span class="text-danger">*</span></label>
                        <input type="text" name="nom" class="form-control" placeholder="DUPONT" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Groupe <span class="text-danger">*</span></label>
                        <select name="groupe_id" class="form-select" required>
                            <option value="">— Sélectionner —</option>
                            <?php foreach ($groupes as $g): ?>
                                <option value="<?= $g['id'] ?>"><?= sanitize($g['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Année scolaire <span class="text-danger">*</span></label>
                        <select name="annee_scolaire" class="form-select" required>
                            <?php foreach ($annees as $a): ?>
                                <option value="<?= $a ?>" <?= $a === getAnneeCourante() ? 'selected' : '' ?>><?= $a ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Créer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Modifier -->
<div class="modal fade" id="modalModifier" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="modifier">
            <input type="hidden" name="id" id="modif_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Modifier le stagiaire</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Prénom <span class="text-danger">*</span></label>
                        <input type="text" name="prenom" id="modif_prenom" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Nom <span class="text-danger">*</span></label>
                        <input type="text" name="nom" id="modif_nom" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Login <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-at"></i></span>
                            <input type="text" name="login" id="modif_login" class="form-control" required>
                            <button type="button" class="btn btn-outline-secondary" id="btn-regen-login" title="Regénérer">
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Groupe <span class="text-danger">*</span></label>
                        <select name="groupe_id" id="modif_groupe" class="form-select" required>
                            <option value="">— Sélectionner —</option>
                            <?php foreach ($groupes as $g): ?>
                                <option value="<?= $g['id'] ?>"><?= sanitize($g['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Année scolaire</label>
                        <select name="annee_scolaire" id="modif_annee" class="form-select" required>
                            <?php foreach ($annees as $a): ?>
                                <option value="<?= $a ?>"><?= $a ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Reset MDP -->
<div class="modal fade" id="modalReset" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id" id="reset_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key me-2"></i>Réinitialiser</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Réinitialiser le mot de passe de <strong id="reset_nom"></strong> à <code>123456</code> ?</p>
                <p class="text-muted small mb-0">Le stagiaire devra le modifier à sa prochaine connexion.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-warning btn-sm">Réinitialiser</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Supprimer -->
<div class="modal fade" id="modalSupprimer" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="supprimer">
            <input type="hidden" name="id" id="suppr_id">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Supprimer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Supprimer définitivement <strong id="suppr_nom"></strong> ?</p>
                <div id="suppr_warning_eval" class="alert alert-warning py-2 small mb-2" style="display:none">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    Ce stagiaire a <strong id="suppr_nb_eval"></strong> évaluation(s) enregistrée(s).<br>
                    Elles seront <strong>définitivement supprimées</strong> avec le compte.
                </div>
                <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>Cette action est irréversible.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-danger btn-sm">Supprimer</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function normaliser(s) {
    return s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-zA-Z0-9]/g, '').toLowerCase();
}
function loginFromPrenomNom(prenom, nom) {
    const p = normaliser(prenom);
    const n = normaliser(nom).toUpperCase();
    if (!p || !n) return '';
    return p.charAt(0).toUpperCase() + p.slice(1) + '.' + n;
}

document.querySelectorAll('.btn-modifier').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('modif_id').value     = btn.dataset.id;
        document.getElementById('modif_prenom').value = btn.dataset.prenom;
        document.getElementById('modif_nom').value    = btn.dataset.nom;
        document.getElementById('modif_login').value  = btn.dataset.login;
        document.getElementById('modif_annee').value  = btn.dataset.annee;
        document.getElementById('modif_groupe').value = btn.dataset.groupe;
        new bootstrap.Modal(document.getElementById('modalModifier')).show();
    });
});

document.getElementById('btn-regen-login').addEventListener('click', () => {
    const p = document.getElementById('modif_prenom').value;
    const n = document.getElementById('modif_nom').value;
    const l = loginFromPrenomNom(p, n);
    if (l) document.getElementById('modif_login').value = l;
});

document.querySelectorAll('.btn-reset').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('reset_id').value = btn.dataset.id;
        document.getElementById('reset_nom').textContent = btn.dataset.nom;
        new bootstrap.Modal(document.getElementById('modalReset')).show();
    });
});

document.querySelectorAll('.btn-supprimer').forEach(btn => {
    btn.addEventListener('click', () => {
        const nb = parseInt(btn.dataset.nb) || 0;
        document.getElementById('suppr_id').value        = btn.dataset.id;
        document.getElementById('suppr_nom').textContent = btn.dataset.nom;
        const warn = document.getElementById('suppr_warning_eval');
        if (nb > 0) {
            document.getElementById('suppr_nb_eval').textContent = nb;
            warn.style.display = 'block';
        } else {
            warn.style.display = 'none';
        }
        new bootstrap.Modal(document.getElementById('modalSupprimer')).show();
    });
});
</script>
</body>
</html>
