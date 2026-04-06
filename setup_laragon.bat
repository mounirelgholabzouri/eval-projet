@echo off
chcp 65001 >nul
title Setup Laragon — Outil d'évaluation en ligne

color 0A
echo.
echo  ██╗      █████╗ ██████╗  █████╗  ██████╗  ██████╗ ███╗   ██╗
echo  ██║     ██╔══██╗██╔══██╗██╔══██╗██╔════╝ ██╔═══██╗████╗  ██║
echo  ██║     ███████║██████╔╝███████║██║  ███╗██║   ██║██╔██╗ ██║
echo  ██║     ██╔══██║██╔══██╗██╔══██║██║   ██║██║   ██║██║╚██╗██║
echo  ███████╗██║  ██║██║  ██║██║  ██║╚██████╔╝╚██████╔╝██║ ╚████║
echo  ╚══════╝╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝ ╚═════╝  ╚═════╝ ╚═╝  ╚═══╝
echo.
echo  Configuration automatique pour Laragon
echo  ══════════════════════════════════════════════════════════════
echo.

:: ── Droits administrateur ────────────────────────────────────
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo  [ERREUR] Droits administrateur requis.
    echo  Clic droit sur ce fichier ^> "Executer en tant qu'administrateur"
    echo.
    pause
    exit /b 1
)

:: ── Vérification Laragon installé ───────────────────────────
if not exist "C:\laragon" (
    echo  [!] Laragon n'est pas installe dans C:\laragon
    echo.
    echo  ETAPE 1 - Telechargez Laragon Full :
    echo    ^> Ouvrez votre navigateur
    echo    ^> Allez sur : laragon.org/download
    echo    ^> Cliquez sur "Laragon Full" (version avec MySQL + PHP + Apache)
    echo    ^> Installez dans C:\laragon  (chemin par defaut, ne pas changer)
    echo.
    echo  ETAPE 2 - Lancez Laragon et cliquez "Demarrer tout"
    echo.
    echo  ETAPE 3 - Relancez ce script
    echo.
    pause
    exit /b 1
)
echo  [OK] Laragon installe dans C:\laragon

:: ── Vérification que Laragon est démarré ────────────────────
tasklist /fi "imagename eq laragon.exe" 2>nul | find /i "laragon.exe" >nul
if %errorLevel% neq 0 (
    echo  [!] Laragon ne semble pas etre demarre.
    echo  Demarrez Laragon et cliquez "Demarrer tout", puis appuyez sur une touche.
    pause
)

:: ── Trouver le dossier www de Laragon ───────────────────────
set WWW=C:\laragon\www
if not exist "%WWW%" mkdir "%WWW%"

set PROJ_SRC=C:\Users\Administrateur\Eval-Projet
set PROJ_DST=%WWW%\eval-projet

echo.
echo  ══════════════════════════════════════════════════════════════
echo   Etape 1 : Lien symbolique
echo  ══════════════════════════════════════════════════════════════
echo.

if exist "%PROJ_DST%" (
    echo  [INFO] Un dossier eval-projet existe deja dans C:\laragon\www
    echo  Voulez-vous le remplacer ? (O pour Oui, N pour Non)
    set /p RESP_LINK=  Choix :
    if /i "%RESP_LINK%"=="O" (
        rmdir "%PROJ_DST%" 2>nul
        if exist "%PROJ_DST%" rmdir /s /q "%PROJ_DST%"
    ) else (
        echo  [OK] Dossier existant conserve.
        goto :db_setup
    )
)

echo  [>>] Creation du lien symbolique...
mklink /D "%PROJ_DST%" "%PROJ_SRC%" >nul 2>&1
if %errorLevel% equ 0 (
    echo  [OK] Lien cree : %PROJ_DST% ^-^> %PROJ_SRC%
) else (
    echo  [!] Lien symbolique impossible, copie des fichiers...
    xcopy "%PROJ_SRC%\*" "%PROJ_DST%\" /E /I /H /Y /Q
    echo  [OK] Fichiers copies dans %PROJ_DST%
)

:db_setup
echo.
echo  ══════════════════════════════════════════════════════════════
echo   Etape 2 : Base de donnees MySQL
echo  ══════════════════════════════════════════════════════════════
echo.

:: Trouver mysql.exe dans Laragon (toutes versions)
set MYSQL=
for /d %%D in ("C:\laragon\bin\mysql\mysql-*-winx64") do (
    if exist "%%D\bin\mysql.exe" set MYSQL=%%D\bin\mysql.exe
)
for /d %%D in ("C:\laragon\bin\mysql\mysql-*") do (
    if exist "%%D\bin\mysql.exe" if "%MYSQL%"=="" set MYSQL=%%D\bin\mysql.exe
)

if "%MYSQL%"=="" (
    echo  [!] mysql.exe introuvable automatiquement.
    echo  Entrez le chemin complet vers mysql.exe de Laragon :
    echo  (ex: C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe)
    set /p MYSQL=  Chemin :
)

if not exist "%MYSQL%" (
    echo.
    echo  [!] MySQL introuvable. Import manuel requis :
    echo      1. Ouvrez Laragon ^> clic droit ^> "phpMyAdmin"
    echo      2. Creez la base "eval_online"
    echo      3. Importez le fichier : db\schema.sql
    echo.
    goto :php_config
)

echo  [OK] MySQL trouve : %MYSQL%
echo.

:: Mot de passe root Laragon (vide par défaut)
set DB_USER=root
set DB_PASS=

echo  Mot de passe root MySQL de Laragon (appuyez sur Entree si vide) :
set /p DB_PASS=  Mot de passe :

echo.
echo  [>>] Creation de la base eval_online...

if "%DB_PASS%"=="" (
    "%MYSQL%" -u%DB_USER% -e "CREATE DATABASE IF NOT EXISTS eval_online CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>nul
    set MYSQL_CMD="%MYSQL%" -u%DB_USER%
) else (
    "%MYSQL%" -u%DB_USER% -p%DB_PASS% -e "CREATE DATABASE IF NOT EXISTS eval_online CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>nul
    set MYSQL_CMD="%MYSQL%" -u%DB_USER% -p%DB_PASS%
)

if %errorLevel% neq 0 (
    echo  [ERREUR] Connexion MySQL impossible. Verifiez que Laragon est demarre.
    echo  Import manuel via phpMyAdmin requis.
    goto :php_config
)

echo  [>>] Import du schema SQL...
if "%DB_PASS%"=="" (
    "%MYSQL%" -u%DB_USER% eval_online < "%PROJ_SRC%\db\schema.sql" 2>&1
) else (
    "%MYSQL%" -u%DB_USER% -p%DB_PASS% eval_online < "%PROJ_SRC%\db\schema.sql" 2>&1
)

if %errorLevel% equ 0 (
    echo  [OK] Base de donnees creee et importee avec succes !
) else (
    echo  [!] Erreur lors de l'import. Essayez via phpMyAdmin.
)

:php_config
echo.
echo  ══════════════════════════════════════════════════════════════
echo   Etape 3 : Configuration PHP
echo  ══════════════════════════════════════════════════════════════
echo.

:: Trouver php.exe dans Laragon
set PHP=
for /d %%D in ("C:\laragon\bin\php\php-*") do (
    if exist "%%D\php.exe" set PHP=%%D\php.exe
)

if not "%PHP%"=="" (
    echo  [OK] PHP trouve : %PHP%
    echo  [>>] Verification des extensions requises...
    "%PHP%" -m 2>nul | findstr /i "pdo_mysql zip curl" >nul
    if %errorLevel% equ 0 (
        echo  [OK] Extensions PDO_MySQL, ZIP et cURL actives.
    ) else (
        echo  [!] Verifiez que pdo_mysql, zip et curl sont actives dans php.ini
        echo      Laragon ^> Clic droit ^> PHP ^> php.ini
    )
) else (
    echo  [!] PHP non trouve automatiquement.
)

:: Réécrire config/database.php avec les bonnes valeurs
echo.
echo  [>>] Ecriture de config/database.php...
(
echo ^<?php
echo define^('DB_HOST', 'localhost'^);
echo define^('DB_NAME', 'eval_online'^);
echo define^('DB_USER', 'root'^);
echo define^('DB_PASS', '%DB_PASS%'^);
echo define^('DB_CHARSET', 'utf8mb4'^);
echo.
echo define^('SITE_NAME', 'Outil d'\''Evaluation en Ligne'^);
echo define^('SITE_URL', 'http://eval-projet.test'^);
echo define^('ADMIN_SESSION_NAME', 'eval_admin'^);
echo define^('SESSION_EVAL_NAME', 'eval_stagiaire'^);
echo.
echo function getDB^(^): PDO ^{
echo     static $pdo = null;
echo     if ^($pdo === null^) ^{
echo         try ^{
echo             $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
echo             $options = [
echo                 PDO::ATTR_ERRMODE            =^> PDO::ERRMODE_EXCEPTION,
echo                 PDO::ATTR_DEFAULT_FETCH_MODE =^> PDO::FETCH_ASSOC,
echo                 PDO::ATTR_EMULATE_PREPARES   =^> false,
echo             ];
echo             $pdo = new PDO^($dsn, DB_USER, DB_PASS, $options^);
echo         ^} catch ^(PDOException $e^) ^{
echo             die^('^<div style="font-family:Arial;padding:30px;background:#fee;border:2px solid #c00;margin:20px;border-radius:8px;"^>^<h2^>Erreur DB^</h2^>^<p^>' . htmlspecialchars^($e-^>getMessage^(^)^) . '^</p^>^</div^>'^);
echo         ^}
echo     ^}
echo     return $pdo;
echo ^}
) > "%PROJ_SRC%\config\database.php"
echo  [OK] config/database.php configure.

:: ── Virtual host Laragon (auto si .test activé) ──────────────
echo.
echo  ══════════════════════════════════════════════════════════════
echo   Etape 4 : Virtual host Laragon
echo  ══════════════════════════════════════════════════════════════
echo.
echo  Laragon cree automatiquement un virtual host pour chaque
echo  dossier dans C:\laragon\www\
echo.
echo  Votre application sera accessible sur :
echo    http://eval-projet.test/
echo    http://eval-projet.test/admin/
echo.
echo  Si .test ne fonctionne pas :
echo    Laragon ^> Preferences ^> General ^> Nom de domaine : .test
echo    Puis : Laragon ^> Clic droit ^> Apache ^> Recharger
echo.

:: ── Résumé ───────────────────────────────────────────────────
echo  ══════════════════════════════════════════════════════════════
echo.
echo   [INSTALLATION TERMINEE]
echo.
echo   Acces stagiaires : http://eval-projet.test/
echo   Acces admin      : http://eval-projet.test/admin/
echo.
echo   Ou via localhost  : http://localhost/eval-projet/
echo                       http://localhost/eval-projet/admin/
echo.
echo   Identifiants admin par defaut :
echo   Login       : admin
echo   Mot de passe: admin123
echo.
echo   !! Pensez a changer le mot de passe admin apres connexion !!
echo      http://eval-projet.test/changer_mot_de_passe_admin.php
echo.
echo  ══════════════════════════════════════════════════════════════

:: Ouvrir le navigateur
set /p OPEN_BROWSER=  Ouvrir le navigateur maintenant ? (O/N) :
if /i "%OPEN_BROWSER%"=="O" (
    start "" "http://eval-projet.test/"
)

echo.
pause
