<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
session_name(ADMIN_SESSION_NAME);
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: ' . (strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? '' : 'admin/') . 'login.php');
    exit;
}

// Re-sync le rôle depuis la DB si absent de la session (ex: session antérieure à la migration)
if (!isset($_SESSION['admin_role'])) {
    try {
        $__pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $__stmt = $__pdo->prepare("SELECT role FROM admins WHERE id = ? LIMIT 1");
        $__stmt->execute([$_SESSION['admin_id']]);
        $__row = $__stmt->fetch(PDO::FETCH_ASSOC);
        $_SESSION['admin_role'] = $__row ? ($__row['role'] ?? 'admin') : 'admin';
    } catch (\Exception $__e) {
        $_SESSION['admin_role'] = 'admin';
    }
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
