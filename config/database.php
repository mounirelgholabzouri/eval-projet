<?php
// ============================================================
// Configuration de la base de données — Laragon
// ============================================================

define('DB_HOST',    'localhost');
define('DB_NAME',    'eval_online');
define('DB_USER',    'root');
define('DB_PASS',    '');           // Laragon : mot de passe root vide par défaut
define('DB_CHARSET', 'utf8mb4');

define('SITE_NAME',          "Outil d'Évaluation en Ligne");
define('SITE_URL',           'http://eval-projet.test');
define('ADMIN_SESSION_NAME', 'eval_admin');
define('SESSION_EVAL_NAME',  'eval_stagiaire');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn     = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die('<div style="font-family:Arial;padding:30px;background:#fee;border:2px solid #c00;margin:20px;border-radius:8px;">
                <h2>Erreur de connexion MySQL</h2>
                <p>' . htmlspecialchars($e->getMessage()) . '</p>
                <p>Vérifiez que <strong>Laragon est démarré</strong> (bouton vert).</p>
            </div>');
        }
    }
    return $pdo;
}
