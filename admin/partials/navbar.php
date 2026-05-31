<?php
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
    ['file' => 'etablissements.php', 'icon' => 'bi-building',          'label' => 'Établissements'],
];

$dropdowns = [
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
        'files' => ['generate.php', 'config_ia.php', 'ia_pile.php'],
        'items' => [
            ['file' => 'generate.php',  'icon' => 'bi-robot',  'label' => 'Génération IA'],
            ['file' => 'config_ia.php', 'icon' => 'bi-gear',   'label' => 'Config IA'],
            ['file' => 'ia_pile.php',   'icon' => 'bi-layers', 'label' => 'Pile IA'],
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
            <?php foreach ($dd['items'] as $it): ?>
            <a href="<?= $it['file'] ?>"
               class="sb-link sb-sub <?= $currentPage === $it['file'] ? 'active' : '' ?>">
                <i class="bi <?= $it['icon'] ?>"></i>
                <span><?= $it['label'] ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </nav>

    <!-- Footer -->
    <div class="sb-footer">
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
