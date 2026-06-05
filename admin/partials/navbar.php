<?php
/* ── Script inline précoce : applique la classe de largeur AVANT le rendu ── */
echo '<script>(function(){var w=localStorage.getItem("admin-width")||"";if(w&&["w-wide","w-normal","w-compact"].indexOf(w)>=0)document.body.classList.add(w);})();</script>';

$currentPage = basename($_SERVER['PHP_SELF']);
$etabNavbar  = !empty($_SESSION['admin_etablissement_nom'])
    ? $_SESSION['admin_etablissement_nom']
    : (function_exists('getEtablissementDefaut') ? getEtablissementDefaut() : '');

$navItems = [
    ['file' => 'index.php',          'icon' => 'bi-speedometer2',     'label' => 'Dashboard'],
    ['file' => 'modules.php',        'icon' => 'bi-journal-text',      'label' => 'Modules'],
    ['file' => 'questions.php',      'icon' => 'bi-question-circle',   'label' => 'Questions'],
    ['file' => 'move_questions.php', 'icon' => 'bi-arrow-left-right',  'label' => 'Déplacer'],
    ['file' => 'groupes_emploi.php',  'icon' => 'bi-people',            'label' => 'Groupes'],
    ['file' => 'stagiaires.php',     'icon' => 'bi-person-lines-fill', 'label' => 'Stagiaires'],
    ['file' => 'formateurs_emploi.php', 'icon' => 'bi-person-badge',   'label' => 'Formateurs'],
    ['file' => 'sessions_view.php',  'icon' => 'bi-calendar-check',    'label' => 'Sessions'],
    ['file' => 'results.php',        'icon' => 'bi-bar-chart',         'label' => 'Résultats'],
    ['file' => 'cc_pratique.php',        'icon' => 'bi-grid-3x3-gap', 'label' => 'CC Pratique'],
    ['file' => 'generer_cc_pratique.php','icon' => 'bi-magic',        'label' => 'Générer CC1/CC2'],
    ['file' => 'etablissements.php', 'icon' => 'bi-building',          'label' => 'Établissements'],
];

$dropdowns = [
    'audit' => [
        'icon' => 'bi-clipboard-data', 'label' => 'Résultat Audit',
        'files' => ['resultat_audit.php'],
        'items' => [
            ['file' => 'resultat_audit.php', 'href' => 'resultat_audit.php?type=cc1_pratique',  'type' => 'cc1_pratique',  'icon' => 'bi-grid-3x3-gap', 'label' => 'CC1 Pratique'],
            ['file' => 'resultat_audit.php', 'href' => 'resultat_audit.php?type=cc2_pratique',  'type' => 'cc2_pratique',  'icon' => 'bi-grid-3x3-gap', 'label' => 'CC2 Pratique'],
            ['file' => 'resultat_audit.php', 'href' => 'resultat_audit.php?type=cc3_theorique', 'type' => 'cc3_theorique', 'icon' => 'bi-ui-checks',     'label' => 'CC3 Théorique'],
            ['file' => 'resultat_audit.php', 'href' => 'resultat_audit.php?type=efm',           'type' => 'efm',           'icon' => 'bi-award',        'label' => 'EFM'],
        ],
    ],
    'importer' => [
        'icon' => 'bi-cloud-upload', 'label' => 'Importer',
        'files' => ['import_questions.php', 'import_evaluation_json.php'],
        'items' => [
            ['file' => 'import_questions.php',       'icon' => 'bi-pc-display',            'label' => 'PC distant (sync)'],
            ['file' => 'import_evaluation_json.php', 'icon' => 'bi-file-earmark-arrow-up', 'label' => 'Évaluation JSON'],
        ],
    ],
    'efm' => [
        'icon' => 'bi-intersect', 'label' => 'Fusion / EFM',
        'files' => ['fusion.php', 'print_efm_result.php'],
        'items' => [
            ['file' => 'fusion.php',           'icon' => 'bi-intersect', 'label' => 'Fusion / EFM'],
            ['file' => 'print_efm_result.php', 'icon' => 'bi-printer',   'label' => 'Résultats EFM'],
        ],
    ],
    'ia' => [
        'icon' => 'bi-robot', 'label' => 'IA',
        'files' => ['generate.php', 'config_ia.php', 'ia_pile.php', 'agent_eval.php'],
        'items' => [
            ['file' => 'generate.php',   'icon' => 'bi-robot',        'label' => 'Génération IA'],
            ['file' => 'agent_eval.php', 'icon' => 'bi-magic',        'label' => 'Agent IA'],
            ['file' => 'config_ia.php',  'icon' => 'bi-gear',         'label' => 'Config IA'],
            ['file' => 'ia_pile.php',    'icon' => 'bi-layers',       'label' => 'Pile IA'],
        ],
    ],
];

// Construit le contenu de la sidebar (réutilisé desktop + mobile)
// $prefix : 'desk' ou 'mob' pour éviter les doublons d'ID dans le DOM
function renderSidebarContent(array $navItems, array $dropdowns, string $currentPage, string $etab, string $prefix = 'desk'): void { ?>

    <!-- Brand -->
    <div class="sb-brand">
        <div class="sb-brand-title">
            <i class="bi bi-shield-check text-primary me-2"></i>Admin Évaluations
        </div>
        <?php if ($etab): ?>
        <div class="sb-brand-sub">
            <i class="bi bi-building me-1"></i><?= htmlspecialchars(trim($etab), ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Nav -->
    <nav class="sb-nav">
        <?php foreach ($navItems as $item): ?>
        <a href="<?= $item['file'] ?>"
           class="sb-link <?= $currentPage === $item['file'] ? 'active' : '' ?>">
            <i class="bi <?= $item['icon'] ?>"></i>
            <span><?= $item['label'] ?></span>
        </a>
        <?php endforeach; ?>

        <?php foreach ($dropdowns as $key => $dd):
            $isOpen = in_array($currentPage, $dd['files']); ?>
        <button class="sb-link sb-toggle <?= $isOpen ? 'active' : '' ?>"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#sb-dd-<?= $prefix ?>-<?= $key ?>"
                aria-expanded="<?= $isOpen ? 'true' : 'false' ?>">
            <i class="bi <?= $dd['icon'] ?>"></i>
            <span><?= $dd['label'] ?></span>
            <i class="bi bi-chevron-down sb-chevron ms-auto"></i>
        </button>
        <div class="collapse <?= $isOpen ? 'show' : '' ?>" id="sb-dd-<?= $prefix ?>-<?= $key ?>">
            <?php foreach ($dd['items'] as $it):
                $href = $it['href'] ?? $it['file'];
                $isActive = ($currentPage === $it['file']);
                if ($isActive && isset($it['type'])) {
                    $isActive = (($_GET['type'] ?? 'efm') === $it['type']);
                }
            ?>
            <a href="<?= $href ?>"
               class="sb-link sb-sub <?= $isActive ? 'active' : '' ?>">
                <i class="bi <?= $it['icon'] ?>"></i>
                <span><?= $it['label'] ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </nav>

    <!-- Footer -->
    <div class="sb-footer">
        <button type="button" class="sb-link sb-link-muted sb-width-btn" title="Changer la largeur du contenu">
            <i class="bi bi-layout-three-columns sb-width-icon"></i>
            <span class="sb-width-label">Largeur</span>
        </button>
        <a href="../index.php" target="_blank" class="sb-link sb-link-muted">
            <i class="bi bi-box-arrow-up-right"></i><span>Voir le site</span>
        </a>
        <a href="logout.php" class="sb-link sb-link-danger">
            <i class="bi bi-box-arrow-right"></i><span>Déconnexion</span>
        </a>
    </div>
<?php }
?>

<!-- ══════════════════════════════════════════════════════════
     MOBILE : barre haute + offcanvas
     ══════════════════════════════════════════════════════════ -->
<div class="d-flex d-lg-none align-items-center bg-dark px-3 py-2 sb-topbar">
    <button class="btn p-0 text-white me-3 fs-5 lh-1"
            data-bs-toggle="offcanvas" data-bs-target="#sbMobile">
        <i class="bi bi-list"></i>
    </button>
    <span class="text-white fw-semibold small">
        <i class="bi bi-shield-check me-1 text-primary"></i>Admin Évaluations
    </span>
</div>

<div class="offcanvas offcanvas-start sb-offcanvas" tabindex="-1" id="sbMobile">
    <div class="offcanvas-header border-bottom border-secondary px-3 py-2">
        <span class="text-white fw-semibold"><i class="bi bi-shield-check me-1 text-primary"></i>Admin</span>
        <button type="button" class="btn-close btn-close-white btn-sm"
                data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-0 d-flex flex-column">
        <?php renderSidebarContent($navItems, $dropdowns, $currentPage, $etabNavbar, 'mob'); ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     DESKTOP : sidebar fixe (visible lg+)
     ══════════════════════════════════════════════════════════ -->
<aside class="sb-desktop">
    <?php renderSidebarContent($navItems, $dropdowns, $currentPage, $etabNavbar); ?>
</aside>

<script>
/* ═══════════════════════════════════════════════════════════════
   Bloc synchrone — s'exécute avant le rendu de la page.
   Définit les utilitaires globaux disponibles dès le chargement.
   ═══════════════════════════════════════════════════════════════ */
(function () {

    /* ── Toggle largeur contenu ──────────────────────────────── */
    var modes = [
        { key: '',          label: 'Original',     icon: 'bi-layout-three-columns' },
        { key: 'w-wide',    label: 'Large (1400)',  icon: 'bi-arrows-fullscreen' },
        { key: 'w-normal',  label: 'Normal (1100)', icon: 'bi-layout-sidebar' },
        { key: 'w-compact', label: 'Compact (800)', icon: 'bi-layout-text-sidebar-reverse' },
    ];
    var stored = localStorage.getItem('admin-width') || '';
    var idx    = modes.findIndex(function (m) { return m.key === stored; });
    if (idx < 0) idx = 0;

    function applyWidth(mode) {
        document.body.classList.remove('w-wide', 'w-normal', 'w-compact');
        if (mode.key) document.body.classList.add(mode.key);
        document.querySelectorAll('.sb-width-label').forEach(function (el) { el.textContent  = mode.label; });
        document.querySelectorAll('.sb-width-icon') .forEach(function (el) { el.className    = 'bi ' + mode.icon; });
        localStorage.setItem('admin-width', mode.key);
    }
    applyWidth(modes[idx]);
    document.querySelectorAll('.sb-width-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            idx = (idx + 1) % modes.length;
            applyWidth(modes[idx]);
        });
    });

    /* ── Toast global (disponible avant DOMContentLoaded) ────── */
    window._toast = function (msg, type) {
        type = type || 'success';
        var wrap = document.getElementById('_toastWrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.id = '_toastWrap';
            document.body.appendChild(wrap);
        }
        var el = document.createElement('div');
        el.className = 'alert alert-' + type + ' shadow-sm py-2 px-3 mb-0 small';
        el.innerHTML = msg;
        wrap.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity .3s';
            el.style.opacity    = '0';
            setTimeout(function () { el.remove(); }, 320);
        }, 3800);
    };

    /* ── confirmAction — fallback natif (remplacé après DOMReady) */
    window.confirmAction = function (msg, cb) {
        if (window.confirm(msg) && cb) cb();
    };

})();

/* ═══════════════════════════════════════════════════════════════
   Bloc DOMContentLoaded — Bootstrap est disponible ici.
   ═══════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {

    /* 1. Tooltips Bootstrap sur tous les boutons/liens avec title */
    document.querySelectorAll('a.btn[title], button[title]').forEach(function (el) {
        if (!el.getAttribute('data-bs-toggle') && !el._bsTooltip) {
            el._bsTooltip = new bootstrap.Tooltip(el, { trigger: 'hover', placement: 'top' });
        }
    });

    /* 2. Auto-dismiss des alertes succès / warning après 4,5 s */
    document.querySelectorAll('.alert-success, .alert-warning').forEach(function (el) {
        if (el.closest('.modal')) return;
        setTimeout(function () {
            el.style.transition  = 'opacity .4s ease, max-height .4s ease, margin .4s ease, padding .4s ease';
            el.style.overflow    = 'hidden';
            el.style.opacity     = '0';
            el.style.maxHeight   = '0';
            el.style.marginTop   = '0';
            el.style.marginBottom = '0';
            el.style.paddingTop  = '0';
            el.style.paddingBottom = '0';
            setTimeout(function () { el.remove(); }, 440);
        }, 4500);
    });

    /* 3. Ctrl+S / Cmd+S → submit le formulaire principal (hors modal) */
    document.addEventListener('keydown', function (e) {
        if (!(e.ctrlKey || e.metaKey) || e.key !== 's') return;
        e.preventDefault();
        if (document.querySelector('.modal.show')) return;
        var form = document.querySelector('.card-body form[method="POST"]')
                || document.querySelector('form[method="POST"]');
        if (!form) return;
        var btn = form.querySelector('[type="submit"]:not([disabled])');
        if (btn) btn.click();
    });

    /* 4. Interception des liens de suppression directe (?action=delete) */
    document.querySelectorAll('a[href*="action=delete"]').forEach(function (link) {
        link.removeAttribute('onclick');
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var href = this.href;
            confirmAction('Supprimer définitivement cet élément ?', function () {
                window.location.href = href;
            });
        });
    });

    /* 5. Surbrillance des lignes via checkbox tbody */
    document.querySelectorAll('tbody .form-check-input[type="checkbox"]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var tr = this.closest('tr');
            if (tr) tr.classList.toggle('row-selected', this.checked);
        });
        if (cb.checked) { var tr = cb.closest('tr'); if (tr) tr.classList.add('row-selected'); }
    });

    /* 6. Injection live-search dans les tableaux avec 5+ lignes de données */
    document.querySelectorAll('.table-responsive').forEach(function (wrap) {
        if (wrap.previousElementSibling && wrap.previousElementSibling.classList.contains('tbl-search-bar')) return;
        var table = wrap.querySelector('table');
        if (!table) return;
        var tbody = table.querySelector('tbody');
        if (!tbody) return;
        var dataRows = Array.from(tbody.querySelectorAll('tr')).filter(function (r) {
            return !r.querySelector('td[colspan]');
        });
        if (dataRows.length < 5) return;

        var bar  = document.createElement('div');
        bar.className = 'tbl-search-bar';
        bar.innerHTML  = '<div class="input-group input-group-sm">'
            + '<span class="input-group-text"><i class="bi bi-search"></i></span>'
            + '<input type="search" class="form-control" placeholder="Rechercher dans le tableau…" autocomplete="off">'
            + '</div>';
        wrap.parentNode.insertBefore(bar, wrap);

        var inp = bar.querySelector('input');
        inp.addEventListener('input', function () {
            var v = this.value.toLowerCase().trim();
            tbody.querySelectorAll('tr').forEach(function (r) {
                if (r.querySelector('td[colspan]')) return;
                r.style.display = (!v || r.textContent.toLowerCase().includes(v)) ? '' : 'none';
            });
        });
    });

    /* 7. Bouton submit → état loading pendant navigation (POST) */
    document.querySelectorAll('form[method="POST"]').forEach(function (form) {
        form.addEventListener('submit', function () {
            if (!form.checkValidity()) return;
            var btn = form.querySelector('[type="submit"]:not(.no-loading)');
            if (btn) setTimeout(function () { btn.classList.add('btn-loading'); }, 60);
        });
    });

    /* 8. Modal de confirmation Bootstrap (remplace le fallback natif) */
    var gcm = document.createElement('div');
    gcm.className   = 'modal fade';
    gcm.id          = '_gcm';
    gcm.tabIndex    = -1;
    gcm.innerHTML   =
        '<div class="modal-dialog modal-sm modal-dialog-centered">'
      + '<div class="modal-content rounded-4 border-0 shadow">'
      + '<div class="modal-body p-4 text-center">'
      + '<i class="bi bi-exclamation-triangle-fill text-danger d-block mb-3" style="font-size:2.2rem"></i>'
      + '<p id="_gcm-msg" class="fw-semibold mb-0 lh-sm"></p>'
      + '</div>'
      + '<div class="modal-footer border-0 justify-content-center gap-2 pb-4 pt-0">'
      + '<button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Annuler</button>'
      + '<button type="button" class="btn btn-danger px-4" id="_gcm-ok">Confirmer</button>'
      + '</div></div></div>';
    document.body.appendChild(gcm);

    var bsGcm = new bootstrap.Modal(gcm);

    window.confirmAction = function (msg, onOk) {
        document.getElementById('_gcm-msg').textContent = msg;
        var okBtn = document.getElementById('_gcm-ok');
        var fresh = okBtn.cloneNode(true);
        okBtn.parentNode.replaceChild(fresh, okBtn);
        fresh.addEventListener('click', function () { bsGcm.hide(); if (onOk) onOk(); });
        gcm.addEventListener('hidden.bs.modal', function handler() {
            gcm.removeEventListener('hidden.bs.modal', handler);
        });
        bsGcm.show();
        setTimeout(function () { fresh.focus(); }, 300);
    };

});
</script>
