@echo off
REM ============================================================
REM install.bat - Installation Eval-Projet sur Windows/Laragon
REM Prerequis :
REM   - Laragon installe dans C:\laragon (https://laragon.org)
REM   - Projet copie quelque part (ex: C:\laragon\www\eval-projet)
REM Usage :
REM   Double-clic ou depuis cmd : install.bat
REM ============================================================

chcp 65001 >nul
title Installation Eval-Projet

REM ============================================================
REM 1. Configuration
REM ============================================================
set "LARAGON_DIR=C:\laragon"
set "DB_NAME=eval_online"
set "DB_USER=root"
set "DB_PASS="
set "DB_HOST=127.0.0.1"

REM PROJECT_DIR = dossier ou se trouve ce install.bat
set "PROJECT_DIR=%~dp0"
if "%PROJECT_DIR:~-1%"=="\" set "PROJECT_DIR=%PROJECT_DIR:~0,-1%"

REM Fallback si schema absent ici
if not exist "%PROJECT_DIR%\db\schema.sql" (
    if exist "%LARAGON_DIR%\www\eval-projet\db\schema.sql" (
        set "PROJECT_DIR=%LARAGON_DIR%\www\eval-projet"
    )
)

REM ============================================================
REM 2. Detection binaires Laragon
REM ============================================================
set "PHP_BIN="
set "MYSQL_BIN="
for /d %%D in ("%LARAGON_DIR%\bin\php\php-*") do set "PHP_BIN=%%D\php.exe"
for /d %%D in ("%LARAGON_DIR%\bin\mysql\mysql-*") do set "MYSQL_BIN=%%D\bin\mysql.exe"
set "APACHE_CONF=%LARAGON_DIR%\etc\apache2\sites-enabled\00-default.conf"

echo.
echo ============================================================
echo   Installation Eval-Projet
echo ============================================================
echo   Laragon : %LARAGON_DIR%
echo   Projet  : %PROJECT_DIR%
echo   Base    : %DB_NAME% (user=%DB_USER%)
echo   PHP     : %PHP_BIN%
echo ============================================================
echo.

REM ============================================================
REM 3. Verifications
REM ============================================================
echo [INFO] Verification des prerequis...

if not exist "%LARAGON_DIR%" (
    echo [ERREUR] Laragon introuvable dans %LARAGON_DIR%
    echo          Telechargez-le sur https://laragon.org
    pause
    exit /b 1
)

if not exist "%PHP_BIN%" (
    echo [ERREUR] PHP Laragon introuvable
    pause
    exit /b 1
)

if not exist "%PROJECT_DIR%\db\schema.sql" (
    echo [ERREUR] db\schema.sql manquant dans %PROJECT_DIR%
    pause
    exit /b 1
)

if not exist "%PROJECT_DIR%\install_db.php" (
    echo [ERREUR] install_db.php manquant dans %PROJECT_DIR%
    echo          Assurez-vous que le fichier helper est present
    pause
    exit /b 1
)

echo [OK] Laragon, PHP et projet detectes
echo.

REM ============================================================
REM 4. Test connexion MySQL
REM ============================================================
echo [INFO] Test de connexion MySQL...
"%PHP_BIN%" -r "try { new PDO('mysql:host=%DB_HOST%','%DB_USER%','%DB_PASS%'); echo 'OK'; } catch (Exception $e) { exit(1); }" >nul 2>&1
if errorlevel 1 (
    echo [ERREUR] Impossible de se connecter a MySQL
    echo          Demarrez Laragon (Start All) et relancez
    pause
    exit /b 1
)
echo [OK] MySQL accessible
echo.

REM ============================================================
REM 5. Installation base (delegue a install_db.php)
REM ============================================================
echo [INFO] Installation de la base de donnees...
"%PHP_BIN%" "%PROJECT_DIR%\install_db.php" "%DB_HOST%" "%DB_USER%" "%DB_PASS%" "%DB_NAME%" "%PROJECT_DIR%"
if errorlevel 1 (
    echo [ERREUR] Echec installation base
    pause
    exit /b 1
)
echo.

REM ============================================================
REM 6. Extensions PHP
REM ============================================================
echo [INFO] Verification extensions PHP...
set "MISSING="
for %%E in (pdo_mysql mbstring fileinfo curl openssl) do (
    "%PHP_BIN%" -m | findstr /i /b /c:"%%E" >nul
    if errorlevel 1 set "MISSING=!MISSING! %%E"
)
setlocal enabledelayedexpansion
if defined MISSING (
    echo [WARN] Extensions manquantes :!MISSING!
    echo        Laragon Menu PHP Extensions - cocher les manquantes
) else (
    echo [OK] Toutes les extensions PHP sont activees
)
endlocal
echo.

REM ============================================================
REM 7. VirtualHost Apache
REM ============================================================
echo [INFO] Configuration VirtualHost Apache...
if exist "%APACHE_CONF%" (
    copy /Y "%APACHE_CONF%" "%APACHE_CONF%.bak" >nul

    set "APACHE_PATH=%PROJECT_DIR:\=/%"
    call :writeVHost
    echo [OK] VirtualHost configure
    echo      DocumentRoot = %PROJECT_DIR:\=/%
    echo [WARN] Rechargez Apache : Laragon - clic droit Apache - Reload
) else (
    echo [WARN] Fichier %APACHE_CONF% introuvable
    echo        Configuration manuelle necessaire
)
echo.

REM ============================================================
REM 8. Lint PHP
REM ============================================================
echo [INFO] Verification syntaxe PHP...
set "LINT_OK=1"
for %%F in ("%PROJECT_DIR%\config\database.php" "%PROJECT_DIR%\includes\functions.php" "%PROJECT_DIR%\index.php") do (
    if exist "%%F" (
        "%PHP_BIN%" -l "%%F" >nul 2>&1
        if errorlevel 1 (
            echo [WARN]   Erreur syntaxe : %%F
            set "LINT_OK=0"
        )
    )
)
if "%LINT_OK%"=="1" echo [OK] Syntaxe PHP OK
echo.

REM ============================================================
REM 9. Resume
REM ============================================================
echo ============================================================
echo   Installation terminee
echo ============================================================
echo.
echo   Acces :
echo     Stagiaire : http://localhost/
echo     Admin     : http://localhost/admin/
echo.
echo   Prochaines etapes :
echo     1. Rechargez Apache (Laragon - Reload Apache)
echo     2. Ouvrez http://localhost/admin/
echo     3. Changez le mot de passe admin
echo     4. Renseignez cle API Claude dans table 'config'
echo.
pause
goto :eof

REM ============================================================
REM Sous-routine : ecriture VirtualHost
REM ============================================================
:writeVHost
> "%APACHE_CONF%" echo ^<VirtualHost _default_:80^>
>>"%APACHE_CONF%" echo     DocumentRoot "%APACHE_PATH%"
>>"%APACHE_CONF%" echo     ^<Directory "%APACHE_PATH%"^>
>>"%APACHE_CONF%" echo         AllowOverride All
>>"%APACHE_CONF%" echo         Require all granted
>>"%APACHE_CONF%" echo     ^</Directory^>
>>"%APACHE_CONF%" echo ^</VirtualHost^>
goto :eof
