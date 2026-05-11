<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
session_name(ADMIN_SESSION_NAME);
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: ' . (strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? '' : 'admin/') . 'login.php');
    exit;
}

// Vérification CSRF automatique sur tous les POST sauf les endpoints API
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phpSelf = basename($_SERVER['PHP_SELF']);
    if (strpos($phpSelf, 'api_') !== 0) {
        verifyCsrfToken();
    }
}

// ── Helpers de rôle ─────────────────────────────────────────────

function isAdmin(): bool {
    // Défaut 'admin' : compatibilité sessions ouvertes avant la migration
    return ($_SESSION['admin_role'] ?? 'admin') === 'admin';
}

function isFormateur(): bool {
    return ($_SESSION['admin_role'] ?? 'admin') === 'formateur';
}

/** Redirige vers index.php si l'utilisateur n'est pas admin. */
function requireAdminRole(): void {
    if (!isAdmin()) {
        header('Location: index.php');
        exit;
    }
}

/** Retourne l'id de l'admin connecté. */
function currentAdminId(): int {
    return (int)($_SESSION['admin_id'] ?? 0);
}
