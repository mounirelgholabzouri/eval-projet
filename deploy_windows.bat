@echo off
setlocal EnableExtensions EnableDelayedExpansion
chcp 65001 >nul
title Deploiement Eval-Projet - Assistant Windows

REM ============================================================
REM  deploy_windows.bat
REM  Installation COMPLETE automatisee d'Eval-Projet sur Windows
REM  - Telecharge le projet depuis GitHub (git clone ou ZIP)
REM  - Installe XAMPP si absent
REM  - Deploie les fichiers
REM  - COMPLETE : creation base, import schema, donnees demo, compte admin
REM  - Demarrage services et ouverture navigateur
REM ============================================================

set "GIT_REPO=https://github.com/mounirelgholabzouri/eval-projet.git"
set "GIT_ZIP=https://github.com/mounirelgholabzouri/eval-projet/archive/refs/heads/master.zip"
set "GIT_BRANCH=master"

set "DB_NAME=eval_online"
set "DB_USER=root"
set "DB_PASS="
set "DB_HOST=127.0.0.1"

set "SCRIPT_DIR=%~dp0"
if "%SCRIPT_DIR:~-1%"=="\" set "SCRIPT_DIR=%SCRIPT_DIR:~0,-1%"
set "SOURCE_DIR="
set "XAMPP_DIR=C:\xampp"
set "WEB_ROOT=C:\xampp\htdocs\eval-projet"
set "PHP_BIN=C:\xampp\php\php.exe"

cls
echo.
echo ============================================================
echo   DEPLOIEMENT COMPLET - Eval-Projet
echo ============================================================
echo.
echo   Ce script va :
echo     1. Verifier droits administrateur
echo     2. Telecharger le code depuis GitHub
echo     3. Installer/detecter XAMPP
echo     4. Deployer les fichiers
echo     5. CREER base + IMPORTER schema + DONNEES
echo     6. Creer compte admin + modules demo
echo     7. Demarrer services
echo     8. Ouvrir navigateur
echo.
echo ============================================================
pause

REM ============================================================
REM  1. Droits admin
REM ============================================================
echo.
echo [1/8] Verification des droits administrateur...
net session >nul 2>&1
if errorlevel 1 (
    echo [ERREUR] Lancez en tant qu'Administrateur.
    pause
    exit /b 1
)
echo [OK] Droits admin confirmes.

REM ============================================================
REM  2. Telechargement source
REM ============================================================
echo.
echo [2/8] Recuperation du code source...

if exist "%SCRIPT_DIR%\db\schema.sql" if exist "%SCRIPT_DIR%\install_db.php" (
    echo [INFO] Utilisation locale.
    set "SOURCE_DIR=%SCRIPT_DIR%"
    goto :source_ready
)

where git >nul 2>&1
if not errorlevel 1 (
    echo [INFO] Git detecte - clonage...
    if exist "%TEMP%\eval-projet-src" rmdir /S /Q "%TEMP%\eval-projet-src"
    git clone --depth 1 --branch "%GIT_BRANCH%" "%GIT_REPO%" "%TEMP%\eval-projet-src"
    if not errorlevel 1 (
        set "SOURCE_DIR=%TEMP%\eval-projet-src"
        goto :source_ready
    )
)

echo [INFO] Telechargement ZIP...
set "ZIP_FILE=%TEMP%\eval-projet.zip"
if exist "%ZIP_FILE%" del /Q "%ZIP_FILE%"
powershell -NoProfile -Command "try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri '%GIT_ZIP%' -OutFile '%ZIP_FILE%' -UseBasicParsing } catch { exit 1 }"
if errorlevel 1 (
    echo [ERREUR] Telechargement echoue.
    pause
    exit /b 1
)
powershell -NoProfile -Command "Expand-Archive -LiteralPath '%ZIP_FILE%' -DestinationPath '%TEMP%\eval-projet-zip' -Force"
for /d %%D in ("%TEMP%\eval-projet-zip\eval-projet-*") do set "SOURCE_DIR=%%D"
if not exist "%SOURCE_DIR%\db\schema.sql" (
    echo [ERREUR] schema.sql introuvable.
    pause
    exit /b 1
)
echo [OK] Extraction complete.

:source_ready
echo     Sources : %SOURCE_DIR%

REM ============================================================
REM  3. Detection/Installation XAMPP
REM ============================================================
echo.
echo [3/8] Verification XAMPP...

if exist "%XAMPP_DIR%\xampp-control.exe" (
    echo [OK] XAMPP detecte.
    goto :xampp_ready
)

echo [INFO] XAMPP absent.
echo   Choisissez :
echo     [1] Installer XAMPP (recommande)
echo     [2] Installer manuellement et relancer
echo     [3] Quitter
set /p "CHOICE=Votre choix : "

if "%CHOICE%"=="1" goto :install_xampp
if "%CHOICE%"=="2" (
    start "" "https://www.apachefriends.org/download.html"
    pause
    exit /b 0
)
exit /b 0

:install_xampp
set "XAMPP_URL=https://sourceforge.net/projects/xampp/files/XAMPP%%20Windows/8.2.12/xampp-windows-x64-8.2.12-0-VS16-installer.exe/download"
set "XAMPP_INSTALLER=%TEMP%\xampp-installer.exe"
if not exist "%XAMPP_INSTALLER%" (
    echo [INFO] Telechargement XAMPP (160 Mo)...
    powershell -NoProfile -Command "try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri '%XAMPP_URL%' -OutFile '%XAMPP_INSTALLER%' -UseBasicParsing } catch { exit 1 }"
    if errorlevel 1 (
        echo [ERREUR] Telechargement XAMPP echoue.
        pause
        exit /b 1
    )
)
echo [INFO] Installation (2-5 min)...
"%XAMPP_INSTALLER%" --mode unattended --unattendedmodeui minimal --prefix "%XAMPP_DIR%" --launchapps 0 --disable-components xampp_mailtodisk,xampp_mercury,xampp_filezilla,xampp_tomcat,xampp_perl
if not exist "%XAMPP_DIR%\xampp-control.exe" (
    echo [ERREUR] Installation XAMPP echouee.
    pause
    exit /b 1
)
echo [OK] XAMPP installe.

:xampp_ready
echo     %XAMPP_DIR%

REM ============================================================
REM  4. Demarrage services
REM ============================================================
echo.
echo [4/8] Demarrage Apache + MySQL...

if exist "%XAMPP_DIR%\apache_start.bat" start "" /MIN "%XAMPP_DIR%\apache_start.bat"
if exist "%XAMPP_DIR%\mysql_start.bat"  start "" /MIN "%XAMPP_DIR%\mysql_start.bat"
timeout /t 5 /nobreak >nul

echo [INFO] Test MySQL (30 sec max)...
set "MYSQL_OK=0"
for /l %%I in (1,1,15) do (
    "%PHP_BIN%" -r "try { new PDO('mysql:host=%DB_HOST%','%DB_USER%','%DB_PASS%'); echo 'OK'; } catch (Exception $e) { exit(1); }" >nul 2>&1
    if !errorlevel! == 0 (
        set "MYSQL_OK=1"
        goto :mysql_ok
    )
    timeout /t 2 /nobreak >nul
)
:mysql_ok
if "%MYSQL_OK%"=="0" (
    echo [ERREUR] MySQL non accessible. Demarrez XAMPP manuellement.
    pause
    exit /b 1
)
echo [OK] MySQL pret.

REM ============================================================
REM  5. Deploiement fichiers
REM ============================================================
echo.
echo [5/8] Deploiement vers %WEB_ROOT% ...

if not exist "%WEB_ROOT%" mkdir "%WEB_ROOT%"
robocopy "%SOURCE_DIR%" "%WEB_ROOT%" /E /NFL /NDL /NJH /NJS /NP ^
    /XD ".git" ".github" ".claude" "vendor" "node_modules" "docker" ^
    /XF "deploy_windows.bat" "*.bat" "*.sh" "Dockerfile" "docker-compose.yml" "]" >nul
echo [OK] Fichiers deployes.

REM ============================================================
REM  6. Creation base + schema + donnees
REM ============================================================
echo.
echo [6/8] Installation base de donnees...

REM Creer script PHP temporaire
set "SETUP_PHP=%TEMP%\eval-setup.php"

(
echo ^<?php
echo $host = '%DB_HOST%';
echo $user = '%DB_USER%';
echo $pass = '%DB_PASS%';
echo $dbname = '%DB_NAME%';
echo $webroot = '%WEB_ROOT%';
echo.
echo try {
echo     // Connexion root
echo     $pdo = new PDO("mysql:host=$host", $user, $pass, [PDO::ATTR_ERRMODE =^> PDO::ERRMODE_EXCEPTION]);
echo.
echo     // 1. Create DB
echo     echo "[DB] Creation base $dbname\n";
echo     $pdo-^>exec("DROP DATABASE IF EXISTS `$dbname`");
echo     $pdo-^>exec("CREATE DATABASE `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo.
echo     // 2. Reconnexion
echo     $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE =^> PDO::ERRMODE_EXCEPTION]);
echo.
echo     // 3. Import schema.sql
echo     echo "[DB] Import schema\n";
echo     $schema = file_get_contents($webroot . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'schema.sql');
echo     foreach (array_filter(array_map('trim', explode(';', $schema))) as $sql) {
echo         if ($sql ^^^ str_starts_with($sql, '--')) {
echo             try { $pdo-^>exec($sql); } catch (Exception $e) {
echo                 if (!preg_match('/Duplicate|already exists/i', $e-^>getMessage())) throw $e;
echo             }
echo         }
echo     }
echo     echo "[OK] Schema importe\n";
echo.
echo     // 4. Donnees de base (admin + groupes de demo)
echo     echo "[DB] Insertion donnees de base\n";
echo.
echo     // Compte admin
echo     $admin_hash = password_hash('admin123', PASSWORD_BCRYPT);
echo     $pdo-^>exec("INSERT IGNORE INTO admins (username, password_hash, nom) VALUES ('admin', '$admin_hash', 'Admin Eval-Projet')");
echo.
echo     // Groupes
echo     $pdo-^>exec("INSERT IGNORE INTO groupes (nom) VALUES ('Groupe A - 2025')");
echo     $pdo-^>exec("INSERT IGNORE INTO groupes (nom) VALUES ('Groupe B - 2025')");
echo     $pdo-^>exec("INSERT IGNORE INTO groupes (nom) VALUES ('Groupe C - 2025')");
echo.
echo     // Module de demo
echo     $pdo-^>exec("INSERT IGNORE INTO modules (nom, description, duree_minutes, note_max, actif) VALUES ('Module Demo', 'Evaluation de demonstration', 30, 20, 1)");
echo.
echo     // Questions demo (choisir module_id = 1)
echo     $pdo-^>exec("INSERT IGNORE INTO questions (module_id, texte, type, points, ordre) VALUES (1, 'Quelle est la capitale de la France ?', 'qcm', 5, 1)");
echo     $pdo-^>exec("INSERT IGNORE INTO questions (module_id, texte, type, points, ordre) VALUES (1, 'Qui a decouverte l''Amerique ?', 'qcm', 5, 2)");
echo     $pdo-^>exec("INSERT IGNORE INTO questions (module_id, texte, type, points, ordre) VALUES (1, '2 + 2 = ?', 'vrai_faux', 5, 3)");
echo     $pdo-^>exec("INSERT IGNORE INTO questions (module_id, texte, type, points, ordre) VALUES (1, 'Decrivez vos competences principales en 200 mots.', 'texte_libre', 5, 4)");
echo.
echo     // Choix reponses pour Q1
echo     $pdo-^>exec("INSERT IGNORE INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (1, 'Paris', 1, 1)");
echo     $pdo-^>exec("INSERT IGNORE INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (1, 'Lyon', 0, 2)");
echo     $pdo-^>exec("INSERT IGNORE INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (1, 'Marseille', 0, 3)");
echo.
echo     // Choix pour Q2
echo     $pdo-^>exec("INSERT IGNORE INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (2, 'Christophe Colomb', 1, 1)");
echo     $pdo-^>exec("INSERT IGNORE INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (2, 'Vasco de Gama', 0, 2)");
echo.
echo     // Choix pour Q3
echo     $pdo-^>exec("INSERT IGNORE INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (3, 'Vrai', 1, 1)");
echo     $pdo-^>exec("INSERT IGNORE INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (3, 'Faux', 0, 2)");
echo.
echo     // Config API Claude (vide initialement)
echo     $pdo-^>exec("INSERT IGNORE INTO config (cle, valeur) VALUES ('anthropic_api_key', '')");
echo.
echo     echo "[OK] Donnees insertees\n";
echo     echo "\n=== SUCCES ===\n";
echo     echo "Base $dbname creee et configuree.\n";
echo     echo "Compte admin : admin / admin123\n";
echo     exit(0);
echo.
echo } catch (Exception $e) {
echo     fwrite(STDERR, "[ERREUR] " . $e-^>getMessage() . "\n");
echo     exit(1);
echo }
echo ?\^>
) > "%SETUP_PHP%"

REM Executer le script
"%PHP_BIN%" "%SETUP_PHP%"
if errorlevel 1 (
    echo [ERREUR] Installation base echouee.
    pause
    exit /b 1
)
del /Q "%SETUP_PHP%"

echo [OK] Base configuree.

REM ============================================================
REM  7. Ouverture navigateur
REM ============================================================
echo.
echo [7/8] Ouverture navigateur...
start "" "http://localhost/eval-projet/"
timeout /t 3 /nobreak >nul

REM ============================================================
REM  8. Resume
REM ============================================================
echo.
echo [8/8] Finalisation.
echo.
echo ============================================================
echo   DEPLOIEMENT TERMINE - SUCCES !
echo ============================================================
echo.
echo   ACCES IMMEDIAT :
echo     Stagiaire : http://localhost/eval-projet/
echo     Admin     : http://localhost/eval-projet/admin/
echo.
echo   COMPTE ADMIN :
echo     Login    : admin
echo     Password : admin123
echo     ^(CHANGER IMMEDIATEMENT^)
echo.
echo   BASE DE DONNEES :
echo     Nom      : %DB_NAME%
echo     Host     : %DB_HOST%
echo     User     : %DB_USER%
echo.
echo   CONTENU DE DEMO :
echo     - Groupe A, B, C (2025)
echo     - Module "Module Demo"
echo     - 4 questions (QCM, V/F, texte libre)
echo.
echo   PROCHAINES ETAPES :
echo     1. Connectez-vous : Admin ^> admin123
echo     2. Changez le mot de passe admin IMMEDIATEMENT
echo     3. Reclamez votre cle API Anthropic sur https://console.anthropic.com/
echo        (budget de test gratuit disponible)
echo     4. Configurez la cle dans Admin ^> Configuration (futur)
echo        OU via phpMyAdmin :
echo           UPDATE config SET valeur='sk-...' WHERE cle='anthropic_api_key'
echo     5. Creez vos groupes, modules et questions
echo.
echo   PHPIADMIN :
echo     http://localhost/phpmyadmin/
echo     Login : root
echo     Password : (vide)
echo.
echo   GESTION XAMPP :
echo     Demarrage     : %XAMPP_DIR%\xampp-control.exe
echo     Restart Apache: Boutton dans XAMPP
echo     Restart MySQL : Boutton dans XAMPP
echo     Logs PHP      : %XAMPP_DIR%\logs\
echo.
echo   DEPANNAGE :
echo     - Erreur 500              : verifiez logs PHP
echo     - Base non creee          : relancez le script
echo     - MySQL refuse connexion  : lancez XAMPP, cliquez Start MySQL
echo.
echo ============================================================
echo.
pause
endlocal
exit /b 0
