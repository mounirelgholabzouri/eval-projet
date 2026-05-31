<?php
require_once __DIR__ . '/../includes/admin_auth.php';
http_response_code(410);
header('Location: eval_pratique.php');
exit;
