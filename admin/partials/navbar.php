<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="index.php">
            <i class="bi bi-shield-check me-2"></i>Admin Évaluations
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="adminNav">
            <ul class="navbar-nav me-auto gap-1">
                <li class="nav-item">
                    <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : '' ?>"
                       href="index.php">
                        <i class="bi bi-speedometer2 me-1"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'modules.php' ? 'active' : '' ?>"
                       href="modules.php">
                        <i class="bi bi-journal-text me-1"></i>Modules
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'questions.php' ? 'active' : '' ?>"
                       href="questions.php">
                        <i class="bi bi-question-circle me-1"></i>Questions
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'groupes.php' ? 'active' : '' ?>"
                       href="groupes.php">
                        <i class="bi bi-people me-1"></i>Groupes
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'results.php' ? 'active' : '' ?>"
                       href="results.php">
                        <i class="bi bi-bar-chart me-1"></i>Résultats
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'stagiaires.php' ? 'active' : '' ?>"
                       href="stagiaires.php">
                        <i class="bi bi-people me-1"></i>Stagiaires
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'fusion.php' ? 'active' : '' ?>"
                       href="fusion.php">
                        <i class="bi bi-intersect me-1"></i>Fusion QCM
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'generate.php' ? 'active' : '' ?>"
                       href="generate.php">
                        <i class="bi bi-stars me-1"></i>Génération IA
                    </a>
                </li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="../index.php" target="_blank">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Voir le site
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-danger" href="logout.php">
                        <i class="bi bi-box-arrow-right me-1"></i>Déconnexion
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>
