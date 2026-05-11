#!/bin/bash
# ══════════════════════════════════════════════════════════════════
#  sync_remote.sh — Synchronisation bidirectionnelle sécurisée
#  Usage : bash sync_remote.sh
#
#  CE QUI EST SYNCHRONISÉ :
#    ✓ Code         : git pull sur les 2 PCs
#    ✓ Questions    : fusion bidirectionnelle (nouvelles questions des 2 côtés)
#    ✓ Migrations   : détecte et exécute les nouveaux scripts PHP de migration
#
#  CE QUI N'EST JAMAIS TOUCHÉ :
#    ✗ sessions_eval / reponses_stagiaires / stagiaires (données d'examen)
#    ✗ config (clé API, paramètres locaux)
# ══════════════════════════════════════════════════════════════════

REMOTE="Administrateur@192.168.1.178"
REMOTE_DIR="C:/Users/Administrateur/Desktop/Eval-Projet"
REMOTE_PHP="C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"

LOCAL_PHP="C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"
LOCAL_DIR="C:/Users/Administrateur/Eval-Projet"

TMP_REMOTE="/tmp/sync_questions_remote_$(date +%Y%m%d_%H%M%S).json"
TMP_LOCAL="/tmp/sync_questions_local_$(date +%Y%m%d_%H%M%S).json"
LOG="$LOCAL_DIR/sync_remote.log"

log() { echo "$1" | tee -a "$LOG"; }

echo "" >> "$LOG"
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
log " SYNC SESSION — $(date '+%d/%m/%Y %H:%M:%S')"
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# ── Étape 1 : Code local ──────────────────────────────────────────
log ""
log "[1/5] git pull local..."
cd "$LOCAL_DIR"
git pull origin master 2>&1 | tail -3 | while read l; do log "    $l"; done

# ── Étape 2 : Code distant ────────────────────────────────────────
log ""
log "[2/5] git pull distant..."
ssh -o StrictHostKeyChecking=no $REMOTE \
    "cd '$REMOTE_DIR' && git pull origin master 2>&1 | tail -3" \
    | while read l; do log "    $l"; done

# ── Étape 3 : Migrations nouvelles (local d'abord, puis distant) ──
log ""
log "[3/5] Vérification migrations..."
run_migrations_local() {
    for f in "$LOCAL_DIR"/db/migrations/*.php; do
        [ -f "$f" ] || continue
        MARKER="$LOCAL_DIR/db/migrations/.done_$(basename $f)"
        if [ ! -f "$MARKER" ]; then
            log "    → Migration locale : $(basename $f)"
            "$LOCAL_PHP" "$f" 2>&1 | while read l; do log "      $l"; done
            touch "$MARKER"
        fi
    done
}
run_migrations_local

ssh -o StrictHostKeyChecking=no $REMOTE "
    for f in '$REMOTE_DIR'/db/migrations/*.php; do
        [ -f \"\$f\" ] || continue
        MARKER='$REMOTE_DIR/db/migrations/.done_'\$(basename \"\$f\")
        if [ ! -f \"\$MARKER\" ]; then
            echo \"    → Migration distante : \$(basename \$f)\"
            '$REMOTE_PHP' \"\$f\" 2>&1
            touch \"\$MARKER\"
        fi
    done
" | while read l; do log "    $l"; done

# ── Étape 4 : Questions distant → local ───────────────────────────
log ""
log "[4/5] Fusion questions : distant → local..."
ssh -o StrictHostKeyChecking=no $REMOTE \
    "'$REMOTE_PHP' '$REMOTE_DIR/admin/export_questions_cli.php'" > "$TMP_REMOTE" 2>/dev/null

if [ -s "$TMP_REMOTE" ]; then
    NB_REMOTE=$(php -r "echo json_decode(file_get_contents('$TMP_REMOTE'),true)['nb'] ?? 0;" 2>/dev/null)
    log "    → $NB_REMOTE questions exportées depuis le distant"
    "$LOCAL_PHP" "$LOCAL_DIR/admin/import_questions_cli.php" "$TMP_REMOTE" 2>&1 \
        | while read l; do log "    $l"; done
else
    log "    ⚠ Export distant vide ou inaccessible"
fi

# ── Étape 5 : Questions local → distant ───────────────────────────
log ""
log "[5/5] Fusion questions : local → distant..."
"$LOCAL_PHP" "$LOCAL_DIR/admin/export_questions_cli.php" > "$TMP_LOCAL" 2>/dev/null

if [ -s "$TMP_LOCAL" ]; then
    NB_LOCAL=$(php -r "echo json_decode(file_get_contents('$TMP_LOCAL'),true)['nb'] ?? 0;" 2>/dev/null)
    log "    → $NB_LOCAL questions exportées depuis le local"
    scp -q -o StrictHostKeyChecking=no "$TMP_LOCAL" "$REMOTE:/tmp/sync_local.json"
    ssh -o StrictHostKeyChecking=no $REMOTE \
        "'$REMOTE_PHP' '$REMOTE_DIR/admin/import_questions_cli.php' /tmp/sync_local.json" 2>&1 \
        | while read l; do log "    $l"; done
else
    log "    ⚠ Export local vide"
fi

# ── Nettoyage ─────────────────────────────────────────────────────
rm -f "$TMP_REMOTE" "$TMP_LOCAL"

log ""
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
log " SYNC TERMINÉ ✓  —  $(date '+%H:%M:%S')"
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
