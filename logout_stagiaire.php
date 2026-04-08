<?php
require_once __DIR__ . '/includes/functions.php';
session_name(SESSION_EVAL_NAME ?? 'eval_stagiaire');
session_start();
session_destroy();
redirect('login_stagiaire.php');
