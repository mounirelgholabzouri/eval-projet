@echo off
setlocal EnableExtensions DisableDelayedExpansion
chcp 65001 >nul
title Deploiement Eval-Projet - Assistant Windows

REM ============================================================
REM  deploy_windows.bat - COMPLET
REM  Installation automatisee : telechargement + XAMPP + base de donnees
REM ============================================================

set "GIT_REPO=https://github.com/mounirelgholabzouri/eval-projet.git"
set "GIT_ZIP=https://github.com/mounirelgholabzouri/eval-projet/archive/refs/heads/master.zip"
set "DB_NAME=eval_online"
set "DB_USER=root"
set "DB_PASS="
set "DB_HOST=127.0.0.1"
set "SCRIPT_DIR=%~dp0"
if "%SCRIPT_DIR:~-1%"=="\" set "SCRIPT_DIR=%SCRIPT_DIR:~0,-1%"
set "XAMPP_DIR=C:\xampp"
set "WEB_ROOT=C:\xampp\htdocs\eval-projet"
set "PHP_BIN=C:\xampp\php\php.exe"

cls
echo.
echo ============================================================
echo   DEPLOIEMENT EVAL-PROJET
echo ============================================================
echo.
echo Etapes :
echo   1. Verification droits administrateur
echo   2. Telechargement code depuis GitHub
echo   3. Installation XAMPP (si absent)
echo   4. Deploiement fichiers
echo   5. Creation base de donnees
echo   6. Demarrage services
echo   7. Ouverture navigateur
echo.
pause

REM ============================================================
REM  1. Droits admin
REM ============================================================
echo.
echo [1/7] Verification droits admin...
net session >nul 2>&1
if errorlevel 1 (
    echo ERREUR : Lancez en tant qu'Administrateur.
    pause
    exit /b 1
)
echo OK.

REM ============================================================
REM  2. Telechargement source
REM ============================================================
echo.
echo [2/7] Recuperation code source...
set "SOURCE_DIR="

if exist "%SCRIPT_DIR%\db\schema.sql" (
    echo Utilisation locale.
    set "SOURCE_DIR=%SCRIPT_DIR%"
    goto :source_ok
)

where git >nul 2>&1
if not errorlevel 1 (
    echo Git detecte - clonage...
    if exist "%TEMP%\eval-src" rmdir /S /Q "%TEMP%\eval-src" >nul 2>&1
    git clone --depth 1 "%GIT_REPO%" "%TEMP%\eval-src" >nul 2>&1
    if not errorlevel 1 (
        set "SOURCE_DIR=%TEMP%\eval-src"
        goto :source_ok
    )
)

echo Telechargement ZIP...
set "ZIP=%TEMP%\eval.zip"
if exist "%ZIP%" del "%ZIP%" >nul
certutil -urlcache -split -f "%GIT_ZIP%" "%ZIP%" >nul 2>&1
if errorlevel 1 (
    echo ERREUR : Telechargement impossible. Verifiez connexion internet.
    pause
    exit /b 1
)
powershell -NoProfile "Expand-Archive -LiteralPath '%ZIP%' -DestinationPath '%TEMP%\eval-zip' -Force" 2>nul
for /d %%D in ("%TEMP%\eval-zip\eval-projet-*") do set "SOURCE_DIR=%%D"
if not exist "%SOURCE_DIR%\db\schema.sql" (
    echo ERREUR : Archive invalide.
    pause
    exit /b 1
)
echo OK.

:source_ok
echo Source : %SOURCE_DIR%

REM ============================================================
REM  3. Installation XAMPP
REM ============================================================
echo.
echo [3/7] Verification XAMPP...

if exist "%XAMPP_DIR%\xampp-control.exe" (
    echo XAMPP present.
    goto :xampp_ok
)

echo XAMPP absent - telechargement automatique...
set "INSTALLER=%TEMP%\xampp.exe"
if not exist "%INSTALLER%" (
    echo Telechargement XAMPP (160 Mo, patientez)...
    certutil -urlcache -split -f "https://sourceforge.net/projects/xampp/files/XAMPP%%20Windows/8.2.12/xampp-windows-x64-8.2.12-0-VS16-installer.exe/download" "%INSTALLER%" >nul 2>&1
    if errorlevel 1 (
        echo Telechargement certutil echoue, essai PowerShell...
        powershell -NoProfile -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri 'https://sourceforge.net/projects/xampp/files/XAMPP Windows/8.2.12/xampp-windows-x64-8.2.12-0-VS16-installer.exe/download' -OutFile '%INSTALLER%' -UseBasicParsing" >nul 2>&1
    )
    if not exist "%INSTALLER%" (
        echo ERREUR : Telechargement XAMPP impossible.
        echo Telechargez manuellement : https://www.apachefriends.org/download.html
        pause
        exit /b 1
    )
)
echo Installation XAMPP en cours (2-5 min, patientez)...
"%INSTALLER%" --mode unattended --unattendedmodeui minimal --prefix "%XAMPP_DIR%" --launchapps 0 --disable-components xampp_mailtodisk,xampp_mercury,xampp_filezilla,xampp_tomcat,xampp_perl
if not exist "%XAMPP_DIR%\xampp-control.exe" (
    echo ERREUR : Installation XAMPP echouee.
    pause
    exit /b 1
)
echo OK - XAMPP installe.

:xampp_ok

REM ============================================================
REM  4. Deploiement fichiers
REM ============================================================
echo.
echo [4/7] Deploiement...

if not exist "%WEB_ROOT%" mkdir "%WEB_ROOT%"
robocopy "%SOURCE_DIR%" "%WEB_ROOT%" /E /NFL /NDL /NJH /NJS /NP /XD ".git" ".github" ".claude" "vendor" "node_modules" "docker" /XF "*.bat" "*.sh" "]" >nul
echo OK.

REM ============================================================
REM  5. Demarrage services
REM ============================================================
echo.
echo [5/7] Demarrage services...

start "" /MIN "%XAMPP_DIR%\apache_start.bat"
start "" /MIN "%XAMPP_DIR%\mysql_start.bat"
timeout /t 5 /nobreak >nul

echo Test MySQL...
set "MYSQL_OK=0"
for /l %%I in (1,1,15) do (
    "%PHP_BIN%" -r "try { new PDO('mysql:host=%DB_HOST%','%DB_USER%','%DB_PASS%'); exit(0); } catch(Exception $e) { exit(1); }" >nul 2>&1
    if not errorlevel 1 (
        set "MYSQL_OK=1"
        goto :mysql_ok
    )
    timeout /t 2 /nobreak >nul
)

:mysql_ok
if "%MYSQL_OK%"=="0" (
    echo ERREUR : MySQL ne repond pas. Demarrez XAMPP manuellement.
    pause
    exit /b 1
)
echo OK - MySQL pret.

REM ============================================================
REM  6. Creation base de donnees
REM ============================================================
echo.
echo [6/7] Creation base de donnees...

set "TMPPHP=%TEMP%\setup-eval.php"

REM Creer le script PHP
(
    echo ^<?php
    echo $db_host = '%DB_HOST%';
    echo $db_user = '%DB_USER%';
    echo $db_pass = '%DB_PASS%';
    echo $db_name = '%DB_NAME%';
    echo $web_root = '%WEB_ROOT%';
    echo.
    echo try {
    echo     // 1. Connexion root
    echo     $pdo = new PDO("mysql:host=$db_host", $db_user, $db_pass);
    echo.
    echo     // 2. Creer base
    echo     $pdo-^>exec("DROP DATABASE IF EXISTS `$db_name`");
    echo     $pdo-^>exec("CREATE DATABASE `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo.
    echo     // 3. Reconnexion
    echo     $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    echo.
    echo     // 4. Import schema
    echo     $schema = file_get_contents($web_root . '/db/schema.sql');
    echo     foreach (array_filter(array_map('trim', explode(';', $schema))) as $sql) {
    echo         if (!empty($sql^) ^&^& !str_starts_with($sql, '--')) {
    echo             try { $pdo-^>exec($sql); } catch(Exception $e) {}
    echo         }
    echo     }
    echo.
    echo     // 5. Donnees de base
    echo     $admin_hash = password_hash('admin123', PASSWORD_BCRYPT);
    echo     $pdo-^>exec("INSERT IGNORE INTO admins (username, password_hash, nom) VALUES ('admin', '$admin_hash', 'Admin')");
    echo     $pdo-^>exec("INSERT IGNORE INTO groupes (nom) VALUES ('Groupe A - 2025')");
    echo     $pdo-^>exec("INSERT IGNORE INTO groupes (nom) VALUES ('Groupe B - 2025')");
    echo     $pdo-^>exec("INSERT IGNORE INTO modules (nom, description, duree_minutes, note_max, actif) VALUES ('Module Demo', 'Demo', 30, 20, 1)");
    echo     $pdo-^>exec("INSERT IGNORE INTO questions (module_id, texte, type, points, ordre) VALUES (1, 'Quelle est la capitale de la France ?', 'qcm', 5, 1)");
    echo     $pdo-^>exec("INSERT IGNORE INTO choix_reponses (question_id, texte, is_correct) VALUES (1, 'Paris', 1), (1, 'Lyon', 0)");
    echo     $pdo-^>exec("INSERT IGNORE INTO config (cle, valeur) VALUES ('anthropic_api_key', '')");
    echo     echo "OK\n";
    echo.
    echo } catch(Exception $e) {
    echo     echo "ERREUR: " . $e-^>getMessage() . "\n";
    echo     exit(1);
    echo }
) > "%TMPPHP%"

REM Executer le script PHP
"%PHP_BIN%" "%TMPPHP%"
if errorlevel 1 (
    echo ERREUR : Installation base echouee.
    pause
    exit /b 1
)
del "%TMPPHP%" >nul 2>&1
echo OK.

REM ============================================================
REM  7. Ouverture navigateur
REM ============================================================
echo.
echo [7/7] Ouverture...
start http://localhost/eval-projet/

echo.
echo ============================================================
echo DEPLOIEMENT COMPLET
echo ============================================================
echo.
echo Acces :
echo   Stagiaire : http://localhost/eval-projet/
echo   Admin     : http://localhost/eval-projet/admin/
echo.
echo Identifiants admin :
echo   Login : admin
echo   Mdp   : admin123 (a changer)
echo.
echo Base : eval_online sur localhost
echo User : root (vide)
echo.
echo Prochaines etapes :
echo   1. Connectez-vous en admin
echo   2. Changez le mot de passe
echo   3. Creez vos groupes et modules
echo   4. (Optionnel) Ajoutez votre cle API Anthropic
echo.
echo ============================================================
pause
endlocal
exit /b 0
