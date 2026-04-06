<?php
require_once __DIR__ . '/../config/database.php';
session_name(ADMIN_SESSION_NAME);
session_start();
session_destroy();
header('Location: login.php');
exit;
