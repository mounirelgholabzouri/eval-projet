<?php
require_once __DIR__ . '/../includes/admin_auth.php';
http_response_code(410);
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="UTF-8"><title>Page supprimée</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light d-flex align-items-center justify-content-center min-vh-100">
<div class="text-center">
    <h1 class="display-6 fw-bold text-muted">Éval. pratique déplacée</h1>
    <p class="text-muted">Les évaluations pratiques sont maintenant gérées via <strong>Évaluations → type "Pratique"</strong>.</p>
    <a href="modules.php" class="btn btn-primary mt-3">Retour aux modules</a>
</div>
</body></html>
