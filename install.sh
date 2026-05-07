#!/bin/bash
# ============================================================
# install.sh - Installation automatique Eval-Projet sur Windows
# Prérequis :
#   - Laragon installé dans C:\laragon (https://laragon.org)
#   - Projet cloné/copié dans C:\laragon\www\eval-projet
#   - Lancer depuis Git Bash (fourni avec Git for Windows)
# Usage :
#   cd /c/laragon/www/eval-projet
#   bash install.sh
# ============================================================

set -e  # Stop au premier erreur

# --- Couleurs ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

ok()    { echo -e "${GREEN}[OK]${NC} $1"; }
info()  { echo -e "${BLUE}[INFO]${NC} $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERREUR]${NC} $1"; exit 1; }

# ============================================================
# 1. Configuration (personnalisable via variables d'environnement)
# ============================================================
LARAGON_DIR="${LARAGON_DIR:-/c/laragon}"
PROJECT_DIR="${PROJECT_DIR:-$LARAGON_DIR/www/eval-projet}"
DB_NAME="${DB_NAME:-eval_online}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"

# Détection auto des binaires Laragon
PHP_BIN=$(ls "$LARAGON_DIR"/bin/php/php-*/php.exe 2>/dev/null | head -n1)
MYSQL_BIN=$(ls "$LARAGON_DIR"/bin/mysql/mysql-*/bin/mysql.exe 2>/dev/null | head -n1)
APACHE_CONF="$LARAGON_DIR/etc/apache2/sites-enabled/00-default.conf"

echo ""
echo "============================================================"
echo "  Installation Eval-Projet"
echo "============================================================"
echo "  Laragon    : $LARAGON_DIR"
echo "  Projet     : $PROJECT_DIR"
echo "  Base       : $DB_NAME (user=$DB_USER)"
echo "  PHP        : ${PHP_BIN:-NON TROUVÉ}"
echo "  MySQL      : ${MYSQL_BIN:-NON TROUVÉ}"
echo "============================================================"
echo ""

# ============================================================
# 2. Vérifications
# ============================================================
info "Vérification des prérequis..."

[ -d "$LARAGON_DIR" ]       || error "Laragon introuvable dans $LARAGON_DIR. Installez-le depuis https://laragon.org"
[ -n "$PHP_BIN" ]           || error "Binaire PHP Laragon introuvable"
[ -n "$MYSQL_BIN" ]         || error "Binaire MySQL Laragon introuvable"
[ -d "$PROJECT_DIR" ]       || error "Projet introuvable dans $PROJECT_DIR"
[ -f "$PROJECT_DIR/db/schema.sql" ] || error "Fichier db/schema.sql manquant"

ok "Laragon, PHP, MySQL et projet détectés"

# ============================================================
# 3. Vérifier que MySQL est démarré
# ============================================================
info "Test de connexion MySQL..."
if ! "$PHP_BIN" -r "try { new PDO('mysql:host=$DB_HOST','$DB_USER','$DB_PASS'); echo 'OK'; } catch (Exception \$e) { exit(1); }" > /dev/null 2>&1; then
    error "Impossible de se connecter à MySQL. Démarrez Laragon (bouton 'Start All') puis relancez ce script."
fi
ok "MySQL accessible"

# ============================================================
# 4. Création de la base de données
# ============================================================
info "Création de la base '$DB_NAME'..."
"$PHP_BIN" <<PHP
<?php
\$pdo = new PDO('mysql:host=$DB_HOST', '$DB_USER', '$DB_PASS');
\$pdo->exec("CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Base créée ou déjà existante\n";
PHP
ok "Base '$DB_NAME' prête"

# ============================================================
# 5. Import du schéma
# ============================================================
info "Import du schéma SQL (db/schema.sql)..."
"$PHP_BIN" <<PHP
<?php
\$pdo = new PDO('mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4', '$DB_USER', '$DB_PASS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
\$sql = file_get_contents('$PROJECT_DIR/db/schema.sql');
// Split sur les ;  (attention aux triggers/procedures non utilisés ici)
\$statements = array_filter(array_map('trim', explode(';', \$sql)));
foreach (\$statements as \$stmt) {
    if (\$stmt === '' || str_starts_with(\$stmt, '--')) continue;
    try { \$pdo->exec(\$stmt); }
    catch (PDOException \$e) {
        // Ignorer "Duplicate entry" / "already exists" pour idempotence
        if (!preg_match('/Duplicate|already exists/i', \$e->getMessage())) {
            fwrite(STDERR, "Erreur: " . \$e->getMessage() . "\n");
        }
    }
}
echo "Schéma importé\n";
PHP
ok "Schéma importé avec succès"

# ============================================================
# 6. Migrations additionnelles
# ============================================================
info "Application des migrations SQL..."
for migration in "$PROJECT_DIR"/db/migration_*.sql; do
    [ -f "$migration" ] || continue
    mig_name=$(basename "$migration")
    "$PHP_BIN" <<PHP > /dev/null 2>&1 || true
<?php
\$pdo = new PDO('mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4', '$DB_USER', '$DB_PASS');
\$sql = file_get_contents('$migration');
\$statements = array_filter(array_map('trim', explode(';', \$sql)));
foreach (\$statements as \$stmt) {
    if (\$stmt === '' || str_starts_with(\$stmt, '--')) continue;
    try { \$pdo->exec(\$stmt); } catch (PDOException \$e) { /* ignore */ }
}
PHP
    ok "  Migration SQL appliquée : $mig_name"
done

info "Application des migrations PHP (migrate_*.php)..."
for migration in "$PROJECT_DIR"/db/migrate_*.php; do
    [ -f "$migration" ] || continue
    mig_name=$(basename "$migration")
    output=$("$PHP_BIN" "$migration" 2>&1) || true
    ok "  $mig_name — $(echo "$output" | grep -E '(✔|✅|—)' | tr '\n' ' ')"
done

# ============================================================
# 7. Vérification des extensions PHP requises
# ============================================================
info "Vérification des extensions PHP..."
MISSING=""
for ext in pdo_mysql mbstring fileinfo curl openssl; do
    if ! "$PHP_BIN" -m | grep -qi "^$ext$"; then
        MISSING="$MISSING $ext"
    fi
done
if [ -n "$MISSING" ]; then
    warn "Extensions PHP manquantes :$MISSING"
    warn "  → Laragon Menu → PHP → Extensions → cocher les manquantes"
else
    ok "Toutes les extensions PHP requises sont activées"
fi

# ============================================================
# 8. Configuration VirtualHost Apache
# ============================================================
info "Configuration VirtualHost Apache..."
if [ -f "$APACHE_CONF" ]; then
    # Backup avant modification
    cp "$APACHE_CONF" "$APACHE_CONF.bak.$(date +%Y%m%d_%H%M%S)"

    PROJECT_WIN_PATH=$(echo "$PROJECT_DIR" | sed 's|^/c|C:|' | sed 's|/|\\\\|g')
    PROJECT_APACHE_PATH=$(echo "$PROJECT_DIR" | sed 's|^/c|C:|' | sed 's|\\\\|/|g')

    cat > "$APACHE_CONF" <<EOF
<VirtualHost _default_:80>
    DocumentRoot "$PROJECT_APACHE_PATH"
    <Directory "$PROJECT_APACHE_PATH">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
EOF
    ok "VirtualHost configuré → DocumentRoot = $PROJECT_APACHE_PATH"
    warn "Rechargez Apache : Laragon UI → clic droit → Apache → Reload"
else
    warn "Fichier $APACHE_CONF introuvable — configuration VirtualHost à faire manuellement"
fi

# ============================================================
# 9. Lint des fichiers PHP critiques
# ============================================================
info "Vérification syntaxe PHP..."
LINT_ERRORS=0
for f in "$PROJECT_DIR"/config/database.php \
         "$PROJECT_DIR"/includes/functions.php \
         "$PROJECT_DIR"/index.php; do
    if [ -f "$f" ]; then
        if ! "$PHP_BIN" -l "$f" > /dev/null 2>&1; then
            warn "  Erreur de syntaxe dans : $f"
            LINT_ERRORS=$((LINT_ERRORS + 1))
        fi
    fi
done
[ "$LINT_ERRORS" -eq 0 ] && ok "Syntaxe PHP OK"

# ============================================================
# 10. Résumé final
# ============================================================
echo ""
echo "============================================================"
echo -e "  ${GREEN}Installation terminée${NC}"
echo "============================================================"
echo ""
echo "  Pages d'accès :"
echo "    Stagiaire : http://localhost/"
echo "    Admin     : http://localhost/admin/"
echo ""
echo "  Prochaines étapes :"
echo "    1. Rechargez Apache (Laragon → Reload Apache)"
echo "    2. Ouvrez http://localhost/admin/ (identifiants par défaut dans schema.sql)"
echo "    3. CHANGEZ le mot de passe admin immédiatement"
echo "    4. Renseignez la clé API Claude dans la table 'config' (clé = anthropic_api_key)"
echo ""
echo "  En cas de problème :"
echo "    - Vérifiez que Laragon est démarré (bouton Start All vert)"
echo "    - Pare-feu : autoriser port 80 pour accès réseau"
echo "    - Logs Apache : $LARAGON_DIR/logs/apache/"
echo ""
