<?php
/**
 * Migration : module formateur multi-tenant
 *
 * - admins.role (admin | formateur)
 * - modules.formateur_id (FK vers admins)
 * - table formateur_groupes (many-to-many)
 *
 * Exécuter via :
 *   php db/migration_formateur.php
 * ou accéder via http://localhost/db/migration_formateur.php (une seule fois)
 */
require_once __DIR__ . '/../config/database.php';

$pdo  = getDB();
$done = [];
$errs = [];

// ── 1. admins.role ─────────────────────────────────────────────
$cols = $pdo->query("SHOW COLUMNS FROM admins")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('role', $cols)) {
    $pdo->exec("ALTER TABLE admins ADD COLUMN role ENUM('admin','formateur') NOT NULL DEFAULT 'admin' AFTER nom");
    // Tous les comptes existants deviennent admin (comportement historique)
    $pdo->exec("UPDATE admins SET role = 'admin'");
    $done[] = "admins.role ajouté — tous les comptes existants promus admin.";
} else {
    $done[] = "admins.role déjà présent.";
}

// ── 2. modules.formateur_id ─────────────────────────────────────
$cols = $pdo->query("SHOW COLUMNS FROM modules")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('formateur_id', $cols)) {
    $pdo->exec("ALTER TABLE modules ADD COLUMN formateur_id INT NULL DEFAULT NULL AFTER actif");
    $done[] = "modules.formateur_id ajouté (NULL = visible par tous les admins).";
} else {
    $done[] = "modules.formateur_id déjà présent.";
}

// FK optionnelle (ignore si déjà là ou si le moteur ne la supporte pas)
try {
    $fks = $pdo->query("
        SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'modules'
          AND CONSTRAINT_NAME = 'fk_modules_formateur'
    ")->fetchAll();
    if (empty($fks)) {
        $pdo->exec("ALTER TABLE modules ADD CONSTRAINT fk_modules_formateur
                    FOREIGN KEY (formateur_id) REFERENCES admins(id) ON DELETE SET NULL");
        $done[] = "FK fk_modules_formateur ajoutée.";
    }
} catch (Exception $e) {
    $errs[] = "FK modules->admins ignorée : " . $e->getMessage();
}

// ── 3. formateur_groupes ───────────────────────────────────────
$tables = $pdo->query("SHOW TABLES LIKE 'formateur_groupes'")->fetchAll();
if (empty($tables)) {
    $pdo->exec("CREATE TABLE formateur_groupes (
        formateur_id INT NOT NULL,
        groupe_id    INT NOT NULL,
        PRIMARY KEY (formateur_id, groupe_id),
        CONSTRAINT fk_fg_formateur FOREIGN KEY (formateur_id) REFERENCES admins(id)   ON DELETE CASCADE,
        CONSTRAINT fk_fg_groupe    FOREIGN KEY (groupe_id)    REFERENCES groupes(id)  ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done[] = "Table formateur_groupes créée.";
} else {
    $done[] = "formateur_groupes déjà présente.";
}

// ── Résumé ──────────────────────────────────────────────────────
if (php_sapi_name() === 'cli') {
    foreach ($done as $msg) echo "✓ $msg\n";
    foreach ($errs as $msg) echo "⚠ $msg\n";
    echo "\nMigration terminée.\n";
} else {
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
    <title>Migration formateur</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    </head><body class="container py-4">';
    echo '<h1 class="h4 mb-3">Migration — module formateur</h1>';
    echo '<ul class="list-group mb-3">';
    foreach ($done as $msg) echo '<li class="list-group-item list-group-item-success">✓ ' . htmlspecialchars($msg) . '</li>';
    foreach ($errs as $msg) echo '<li class="list-group-item list-group-item-warning">⚠ ' . htmlspecialchars($msg) . '</li>';
    echo '</ul>';
    echo '<a href="../admin/" class="btn btn-primary">Aller à l\'admin</a>';
    echo '</body></html>';
}
