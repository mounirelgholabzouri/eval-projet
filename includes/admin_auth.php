<?php
require_once __DIR__ . '/../config/database.php';
session_name(ADMIN_SESSION_NAME);
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: ' . (strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? '' : 'admin/') . 'login.php');
    exit;
}
