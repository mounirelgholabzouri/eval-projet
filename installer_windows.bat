@echo off
chcp 65001 >nul
title Installation — Outil d'évaluation en ligne

echo.
echo ============================================================
echo   Outil d'évaluation en ligne — Installation Windows
echo ============================================================
echo.

:: ── Vérification des droits administrateur ──────────────────
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo [ERREUR] Ce script doit etre execute en tant qu'Administrateur.
    echo Clic droit sur le fichier -> "Executer en tant qu'administrateur"
    pause
    exit /b 1
)

:: ── Détection du serveur installé ───────────────────────────
set SERVER=
set WWW=

if exist "C:\laragon\www" (
    set SERVER=Laragon
    set WWW=C:\laragon\www
    goto :server_found
)
if exist "C:\xampp\htdocs" (
    set SERVER=XAMPP
    set WWW=C:\xampp\htdocs
    goto :server_found
)
if exist "C:\wamp64\www" (
    set SERVER=WAMP64
    set WWW=C:\wamp64\www
    goto :server_found
)
if exist "C:\wamp\www" (
    set SERVER=WAMP
    set WWW=C:\wamp\www
    goto :server_found
)

:: ── Aucun serveur trouvé ─────────────────────────────────────
echo [!] Aucun serveur web detecte (Laragon, XAMPP, WAMP).
echo.
echo Veuillez installer Laragon (recommande) :
echo   1. Allez sur : https://laragon.org/download/
echo   2. Telechargez "Laragon Full"
echo   3. Installez dans C:\laragon
echo   4. Relancez ce script
echo.
pause
exit /b 1

:server_found
echo [OK] Serveur detecte : %SERVER%
echo [OK] Dossier web     : %WWW%
echo.

:: ── Création du lien symbolique ──────────────────────────────
set PROJ_SRC=C:\Users\Administrateur\Eval-Projet
set PROJ_DST=%WWW%\eval-projet

if exist "%PROJ_DST%" (
    echo [INFO] Le dossier eval-projet existe deja dans le serveur web.
    echo Voulez-vous le reconfigurer ? (O/N)
    set /p CONFIRM=
    if /i not "%CONFIRM%"=="O" goto :skip_link
    rmdir "%PROJ_DST%" 2>nul
    rmdir /s /q "%PROJ_DST%" 2>nul
)

echo [>>] Creation du lien symbolique...
mklink /D "%PROJ_DST%" "%PROJ_SRC%"
if %errorLevel% neq 0 (
    echo [ERREUR] Impossible de creer le lien symbolique.
    echo Copie des fichiers a la place...
    xcopy "%PROJ_SRC%" "%PROJ_DST%" /E /I /H /Y >nul
)
echo [OK] Projet accessible dans %PROJ_DST%

:skip_link

:: ── Configuration base de données ────────────────────────────
echo.
echo ============================================================
echo   Configuration de la base de données MySQL
echo ============================================================
echo.

:: Trouver mysql.exe
set MYSQL=
if exist "C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe" set MYSQL=C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe
if exist "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe"  set MYSQL=C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe
if exist "C:\xampp\mysql\bin\mysql.exe"                             set MYSQL=C:\xampp\mysql\bin\mysql.exe
if exist "C:\wamp64\bin\mysql\mysql8.0.31\bin\mysql.exe"           set MYSQL=C:\wamp64\bin\mysql\mysql8.0.31\bin\mysql.exe

:: Cherche n'importe quelle version MySQL dans laragon
if "%MYSQL%"=="" (
    for /d %%d in ("C:\laragon\bin\mysql\mysql-*") do (
        if exist "%%d\bin\mysql.exe" set MYSQL=%%d\bin\mysql.exe
    )
)

if "%MYSQL%"=="" (
    echo [!] MySQL non trouve automatiquement.
    echo Entrez le chemin complet vers mysql.exe :
    set /p MYSQL="> "
)

if "%MYSQL%"=="" (
    echo [ERREUR] MySQL introuvable. Importez manuellement db\schema.sql via phpMyAdmin.
    goto :config_file
)

echo MySQL trouve : %MYSQL%
echo.
set /p DB_USER=Utilisateur MySQL (defaut: root) :
if "%DB_USER%"=="" set DB_USER=root

set /p DB_PASS=Mot de passe MySQL (Entree = vide) :

echo.
echo [>>] Creation de la base de donnees...
if "%DB_PASS%"=="" (
    "%MYSQL%" -u %DB_USER% -e "CREATE DATABASE IF NOT EXISTS eval_online CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>&1
    "%MYSQL%" -u %DB_USER% eval_online < "%PROJ_SRC%\db\schema.sql" 2>&1
) else (
    "%MYSQL%" -u %DB_USER% -p%DB_PASS% -e "CREATE DATABASE IF NOT EXISTS eval_online CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>&1
    "%MYSQL%" -u %DB_USER% -p%DB_PASS% eval_online < "%PROJ_SRC%\db\schema.sql" 2>&1
)

if %errorLevel% neq 0 (
    echo [ERREUR] Importation SQL echouee.
    echo Importez manuellement db\schema.sql via phpMyAdmin.
) else (
    echo [OK] Base de donnees creee et importee avec succes.
)

:config_file
:: ── Mise à jour de config/database.php ──────────────────────
echo.
echo [>>] Mise a jour de la configuration PHP...

set CONFIG_FILE=%PROJ_SRC%\config\database.php
set /p DB_USER_CONF=Utilisateur DB pour config.php (defaut: root) :
if "%DB_USER_CONF%"=="" set DB_USER_CONF=root

set /p DB_PASS_CONF=Mot de passe DB pour config.php (Entree = vide) :

:: Réécrire le fichier de configuration
(
echo ^<?php
echo define^('DB_HOST', 'localhost'^);
echo define^('DB_NAME', 'eval_online'^);
echo define^('DB_USER', '%DB_USER_CONF%'^);
echo define^('DB_PASS', '%DB_PASS_CONF%'^);
echo define^('DB_CHARSET', 'utf8mb4'^);
echo.
echo define^('SITE_NAME', 'Outil d\'Evaluation en Ligne'^);
echo define^('SITE_URL', 'http://localhost/eval-projet'^);
echo define^('ADMIN_SESSION_NAME', 'eval_admin'^);
echo define^('SESSION_EVAL_NAME', 'eval_stagiaire'^);
echo.
echo function getDB^(^): PDO {
echo     static $pdo = null;
echo     if ^($pdo === null^) {
echo         try {
echo             $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
echo             $options = [
echo                 PDO::ATTR_ERRMODE            =^> PDO::ERRMODE_EXCEPTION,
echo                 PDO::ATTR_DEFAULT_FETCH_MODE =^> PDO::FETCH_ASSOC,
echo                 PDO::ATTR_EMULATE_PREPARES   =^> false,
echo             ];
echo             $pdo = new PDO^($dsn, DB_USER, DB_PASS, $options^);
echo         } catch ^(PDOException $e^) {
echo             die^('Erreur DB : ' . htmlspecialchars^($e-^>getMessage^(^)^)^);
echo         }
echo     }
echo     return $pdo;
echo }
) > "%CONFIG_FILE%"

echo [OK] config/database.php mis a jour.

:: ── Résumé final ─────────────────────────────────────────────
echo.
echo ============================================================
echo   Installation terminee !
echo ============================================================
echo.

if "%SERVER%"=="Laragon" (
    echo   Demarrez Laragon, puis accedez a :
    echo.
    echo   Stagiaires : http://localhost/eval-projet/
    echo   Admin      : http://localhost/eval-projet/admin/
    echo.
    echo   Avec Laragon (virtual hosts actifs) :
    echo   Stagiaires : http://eval-projet.test/
    echo   Admin      : http://eval-projet.test/admin/
) else (
    echo   Demarrez %SERVER%, puis accedez a :
    echo.
    echo   Stagiaires : http://localhost/eval-projet/
    echo   Admin      : http://localhost/eval-projet/admin/
)

echo.
echo   Compte admin par defaut :
echo   Login    : admin
echo   Mot de passe : admin123
echo.
echo ============================================================
pause
