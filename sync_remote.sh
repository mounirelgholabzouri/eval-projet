#!/bin/bash
# ══════════════════════════════════════════════════════
#  sync_remote.sh — Synchronisation PC local ↔ distant
#  Usage : bash sync_remote.sh
# ══════════════════════════════════════════════════════

REMOTE="Administrateur@192.168.1.178"
REMOTE_DIR="C:/Users/Administrateur/Desktop/Eval-Projet"
REMOTE_PHP="C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"
REMOTE_MYSQL="C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"

LOCAL_PHP="C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"
LOCAL_DIR="C:/Users/Administrateur/Eval-Projet"
MYSQLDUMP="C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqldump.exe"
MYSQL="C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
DB="eval_online"
TMP="/tmp/questions_distant_$(date +%Y%m%d_%H%M%S).json"

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo " SYNC REMOTE — $(date '+%d/%m/%Y %H:%M:%S')"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# ── Étape 1 : Code — git pull sur le distant ──────────
echo ""
echo "[1/4] Mise à jour du code distant..."
ssh -o StrictHostKeyChecking=no $REMOTE \
    "cd '$REMOTE_DIR' && git pull origin master 2>&1 | tail -5"

# ── Étape 2 : Export questions du distant ─────────────
echo ""
echo "[2/4] Export questions du PC distant..."
ssh -o StrictHostKeyChecking=no $REMOTE \
    "'$REMOTE_PHP' '$REMOTE_DIR/admin/export_questions_cli.php'" > "$TMP"

NB=$(php -r "echo json_decode(file_get_contents('$TMP'),true)['nb'] ?? 0;")
echo "    → $NB questions exportées → $TMP"

# ── Étape 3 : Fusion questions nouvelles en local ─────
echo ""
echo "[3/4] Import questions nouvelles en local..."
"$LOCAL_PHP" "$LOCAL_DIR/admin/import_questions_cli.php" "$TMP"

# ── Étape 4 : DB complète local → distant ─────────────
echo ""
echo "[4/4] Synchronisation DB locale → distante..."
"$MYSQLDUMP" -u root "$DB" | \
    ssh -o StrictHostKeyChecking=no $REMOTE \
        "'$REMOTE_MYSQL' -u root $DB"
echo "    → DB synchronisée"

# ── Nettoyage ─────────────────────────────────────────
rm -f "$TMP"

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo " SYNC TERMINÉ ✓"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
