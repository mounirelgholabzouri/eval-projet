# CLAUDE.md — Instructions pour Claude Code

## Environnement

- **PHP** : `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`
- **MySQL** : `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe`
- **Serveur** : Apache Laragon port 80 — **ne jamais utiliser `php -S`** (mono-thread, bloque AJAX)
- **Racine projet** : `C:\Users\Administrateur\Desktop\Eval-Projet\`
- **Lien Apache** : `C:\laragon\www\eval-projet\` → symlink vers la racine
- **URL** : `http://localhost/`
- **Worktree** : modifications dans `.claude\worktrees\<nom>\` → **copier manuellement** vers la racine pour activer sur Apache
- **VirtualHost** : `C:\laragon\etc\apache2\sites-enabled\00-default.conf`

## Docker (optionnel)

- **Lancer** : `docker compose up --build` — URL : `http://localhost:8080`
- **DB** : `mysql:8.4`, password root `root`, init auto via `db/schema.sql`
- **Env** : `DB_HOST=db` / `DB_NAME=eval_online` / `DB_USER=root` / `DB_PASS=root`
- **php.ini Docker** : upload 10M, post 12M, memory 256M, exec 120s, UTF-8

## Base de données

- `eval_online` — host `127.0.0.1` — user `root` — password *(vide)* — charset `utf8mb4`
- **Toujours PDO/PHP** pour les insertions — MySQL CLI = encodage cp850 = accents corrompus
- **Migrations** : via script PHP/PDO uniquement, jamais le CLI MySQL
- **ALTER TABLE IF NOT EXISTS** non supporté par MySQL 8.4 → vérifier via `SHOW COLUMNS` en PHP avant d'ajouter
- **GROUP BY** : mode `only_full_group_by` actif → colonnes non-agrégées dans GROUP BY ou MAX()/MIN()
- **MySQL CLI pipe PowerShell** : `Get-Content fichier.sql | mysql.exe` (opérateur `<` bloqué)

## Synchronisation 2 PCs

- **PC local** : `C:\Users\Administrateur\Eval-Projet\`
- **PC distant** : `Administrateur@192.168.1.178` → `C:/Users/Administrateur/Desktop/Eval-Projet`
- **Script** : `sync_remote.sh` — sync bidirectionnelle sécurisée
- **Automatique** : tâche Windows Scheduler `EvalProjet-SyncSession` (logon) — créée par `setup_autosync.ps1`
- **Journal** : `sync_remote.log` à la racine du projet

### Ce qui est synchronisé
| Quoi | Comment |
|------|---------|
| Code | `git pull origin master` sur les 2 PCs |
| Questions/modules | Export→Import bidirectionnel (fusion sans doublon) |
| Migrations PHP | Détectées par marqueur `.done_*`, exécutées une seule fois |

### Ce qui n'est JAMAIS synchronisé (données par PC)
- `sessions_eval`, `reponses_stagiaires`, `stagiaires` — données d'examen locales
- `config` — clé API Anthropic, paramètres locaux

### Ajouter une migration
Créer `db/migrations/nom_migration.php` (script PDO autonome).  
La sync la détectera et l'exécutera automatiquement sur les 2 PCs au prochain logon.

### ⚠ Ne jamais faire
- Dump DB complet local → distant (`mysqldump | ssh mysql`) : **écrase les sessions d'examen distantes**
- Modifier `config` via sync : chaque PC a sa propre clé API

## Commandes rapides

```bash
# Lint PHP
"C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" -l fichier.php

# Exécuter un script
"C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" "C:\Users\Administrateur\Desktop\Eval-Projet\script.php"
```

## Conventions critiques

### Sessions — ORDRE OBLIGATOIRE
```php
require_once __DIR__ . '/includes/functions.php';    // 1
session_name(SESSION_EVAL_NAME ?? 'eval_stagiaire'); // 2
session_start();                                      // 3
```
Admin : `require_once admin_auth.php` gère session_name + session_start automatiquement.

### Sécurité
- HTML : `sanitize($str)` = `htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8')`
- SQL : PDO préparé uniquement — jamais de concaténation
- `form.submit()` JS n'envoie pas les boutons submit → ajouter `<input type="hidden" name="submit_final" value="1">`

### Encodage
- Fichiers PHP : UTF-8 sans BOM
- Données historiques corrompues (CP850) : corriger via `iconv('UTF-8','CP850//IGNORE', $str)`

## Invariants métier

- Chaque module a ≥1 partie (« Général » par défaut via `ensurePartieDefault()`)
- Chaque question appartient à une partie (`partie_id NOT NULL`, ON DELETE RESTRICT)
- Partie `actif=0` → **totalement exclue** partout (quiz, index, toutes les impressions) → utiliser `getPartiesActives()` sauf interface admin
- Suppression stagiaire : cascade manuelle `reponses_stagiaires → sessions_eval → stagiaire` (pas de FK CASCADE en DB)
- Clé API Anthropic : table `config`, clé `anthropic_api_key` — crédits sur **console.anthropic.com** (≠ claude.ai)

## EFM — Impressions OFPPT

- Modules EFM : `type='efm'` + métadonnées dans `meta_json` (code_module, filiere, etablissement, annee)
- **Images (logo, tampon)** : toujours en base64 (`base64_encode(file_get_contents($path))`) — chemins relatifs ignorés à l'impression

### Tampon `assets/img/tampon_ofppt.png` — bas droite de chaque page

**Navigateur** (`print_efm.php`, `print_efm_result.php`, `efm_fiche_resultat.php`) :
- Placer l'`<img>` **hors du div `.page`**, juste avant `</body>`
- CSS : `position: fixed; bottom: 14mm; right: 14mm; width: 38mm;` (toujours actif, pas seulement en @media print)
- ⚠ `position: absolute` dans un parent `position: relative` annule fixed → le tampon n'apparaît qu'une fois

**mPDF** (`admin/generate_efm_pdf.php`) :
- mPDF ignore `right` et `bottom` en `position: fixed` → utiliser `$mpdf->SetHTMLFooter()`
- Image dans `<td style="text-align:right">`, config : `margin_bottom: 48`, `margin_footer: 8`
