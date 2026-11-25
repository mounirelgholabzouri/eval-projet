# CLAUDE.md — Instructions pour Claude Code

## Environnement

- **PHP** : `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`
- **MySQL** : `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe`
- **Serveur** : Apache Laragon port 80 — **ne jamais utiliser `php -S`** (mono-thread, bloque AJAX)
- **Racine projet** : `C:\PROJETS\Eval-Projet\`
- **Lien Apache** : `C:\laragon\www\eval-projet\` → symlink vers la racine
- **URL** : `http://eval-projet.test/`
- **Worktree** : modifications dans `.claude\worktrees\<nom>\` → **copier manuellement** vers la racine pour activer sur Apache
- **VirtualHost** : `C:\laragon\etc\apache2\sites-enabled\auto.eval-projet.test.conf`

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
- **Import dump** : utiliser **Bash** avec `mysql ... < fichier.sql` — PowerShell (`Get-Content | mysql`) corrompt les accents en `??` (CP850), **même avec utf8mb4**

## Poste principal et PC portable

- **Poste principal** (celui-ci) : `C:\PROJETS\Eval-Projet\` — base de référence.
- **PC portable** : copie autonome de l'application (sa propre base `eval_online`, sa propre config). **Il n'y a pas de second PC fixe** : le « PC distant » `192.168.1.178` des anciennes versions n'existe plus.
- **Mise à jour du portable** (à la main, sur le portable) : `git pull origin master`, puis exécuter avec PHP chaque nouveau script de `db/migrations/` (une seule fois ; les migrations récentes sont idempotentes).
- `sync_remote.sh` / `setup_autosync.ps1` : scripts **obsolètes** (SSH vers l'ancien PC distant) — ne pas les lancer. La tâche `EvalProjet-SyncSession` a été supprimée le 2026-11-09.

> ⚠ **Refonte CC/EFM (2026-11-09)** : si la base du portable date d'avant la refonte (table `parties` encore présente), lancer `php db/refonte_cc_efm.php --dry` puis sans `--dry` **avant** les autres migrations. L'import de questions entre PCs (`export/import_questions*.php`) a été retiré.

### Données propres à chaque installation (jamais copiées)
- `sessions_eval`, `reponses_stagiaires`, `stagiaires`, `cc_pratique_notes` — données d'examen
- `config` — clés API, provider IA

### Ajouter une migration
Créer `db/migrations/nom_migration.php` (script PDO autonome, **idempotent** : vérifier l'état avant chaque ALTER, sauter une étape si les données ne la permettent pas).

### ⚠ Ne jamais faire
- Importer un dump complet d'une installation dans l'autre : **écrase les sessions d'examen** de la cible
- Copier la table `config` d'une installation à l'autre (clés API propres à chaque poste)

## Commandes rapides

```bash
# Lint PHP
"C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" -l fichier.php

# Exécuter un script
"C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" "C:\PROJETS\Eval-Projet\script.php"
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
- Formulaires créés dynamiquement en JS → **toujours ajouter le token CSRF manuellement** (`name="csrf_token"`, valeur `<?= csrfToken() ?>`)
- `groupes_emploi.code` est UNIQUE NOT NULL → attraper `PDOException` code `23000` pour doublon, et valider `filiere_id` non null avant INSERT

### Encodage — RÈGLE D'OR

> **Ne jamais importer un dump SQL via PowerShell.**  
> `Get-Content fichier.sql | mysql.exe` corrompt silencieusement les accents (CP850 → `??` irréversibles).

| Contexte | Commande correcte |
|----------|-------------------|
| Import dump (Bash) | `mysql --default-character-set=utf8mb4 -u root db_name < fichier.sql` |
| Insertion / migration | Script PHP/PDO uniquement (`charset=utf8mb4` dans le DSN) |
| Lecture rapide CLI | `mysql --default-character-set=utf8mb4 -u root -e "SELECT ..."` |

- Fichiers PHP : UTF-8 sans BOM
- Données historiques corrompues (CP850) : corriger via `iconv('UTF-8','CP850//IGNORE', $str)`
- Si corruption détectée en masse : script `db/restore_encoding.php` (import dump → DB temp → UPDATE sélectif)

## Modèle Module → Évaluations CC/EFM (refonte 2026-11-09, `db/refonte_cc_efm.php`)

- **Plus de notion de partie** (table `parties` et `questions.partie_id` supprimées).
- `modules` = banque de questions : `code` (M207, UNIQUE de fait), `filiere` (IDOCC, ID…), `nom` (intitulé), `actif` (publié). Libellé : `moduleLibelle()` → « IDOCC-M207 — Intitulé ».
- `evaluations` : `type` ENUM(`cc`,`efm`) · `mode` ENUM(`theorique`,`pratique`) pour les CC (NULL pour EFM) · `numero` (CCn) · `contenu` (thème) · `nb_questions` (quota de tirage, NULL = toutes) · `meta_json` (EFM : etablissement, annee ; CC pratique : groupe_id, numero).
- `evaluation_questions (evaluation_id, question_id, ordre)` : questions d'une évaluation (peuvent venir d'autres modules, ex. EFM multi-modules).
- **Nomenclature** : `nomEvaluation()` → `FILIERE-MODULE-CCn (contenu)`, `FILIERE-MODULE-CCn (Pratique)`, `FILIERE-MODULE-EFM`. Le nom est recalculé à chaque création/modification (`renommerEvaluationsModule()` après changement de code/filière).
- **Point d'entrée unique** : `creerEvaluation(array)` / `modifierEvaluation()` / `setQuestionsEvaluation()` / `creerQuestion()` / `creerModule()` / `trouverOuCreerModule()` — ne jamais faire d'INSERT direct dans `evaluations` ou `questions`.
- `creerSession()` fige **toujours** `sessions_eval.questions_ids` (tirage aléatoire `shuffle` si quota) → modifier une évaluation n'altère pas les sessions passées.
- `getQuestionsEvaluation($evalId, $questionIds)` pour le quiz ; `getQuestionsModule($moduleId)` = banque admin.
- `getReponsesSession($sessionId)` : si `questions_ids` présent → **LEFT JOIN** ; sinon (anciennes sessions) → réponses seules.
- Stagiaire : seules les évaluations `actif=1`, `mode` théorique ou EFM, d'un module `actif=1` sont proposées.
- **Accès restreint** : table `evaluation_stagiaires (evaluation_id, stagiaire_id)` — aucune ligne = ouverte à tous ; sinon seuls les stagiaires listés la voient (`getEvaluationsActives($stagiaireId)`, `stagiaireAccesEvaluation()`, `setStagiairesEvaluation()`). Réglage dans `admin/evaluations.php` (bloc « Accès »). Migration `db/migrations/evaluation_stagiaires.php`.
- **⚠ Import JSON** : vérifier que `is_correct = 1` est bien positionné sur au moins un choix par question — sinon tous les stagiaires obtiennent 0.

## Vues admin

| Page | Rôle |
|------|------|
| `admin/modules.php` | Modules (code, filière, intitulé, publication) — liens banque / évaluations / résultats |
| `admin/evaluations.php` | **Création et édition des CC/EFM** : `?module_id=X`, `?action=new&type=cc\|efm`, `?action=edit&id=Y` — sélection des questions, impressions, rééquilibrage des points |
| `admin/gestion_questions.php` | Banque de questions — `?tab=gerer\|deplacer\|import_eval` (l'import JSON crée une évaluation inactive) |
| `admin/gestion_impression.php` | `?type=cc1_pratique\|cc2_pratique\|cc3_theorique\|efm` — filtres groupe+module(+évaluation), saisie des grilles CC pratique, génération CC1/CC2 depuis CC3, **impression A4 paysage une page** |
| `admin/sessions_view.php` | Sessions groupées par évaluation + date — `?evaluation_id=X&jour=Y` |
| `admin/results.php` | Liste de toutes les sessions (`?module_id=X`, `?categorie=efm\|cc_theorique\|cc_pratique`) |
| `admin/detail.php` | Détail question par question d'une session |
| Impressions | `print_blank.php?evaluation_id=X` (sujet CC), `print_efm.php?evaluation_id=X` (sujet EFM), `print_exams.php?module_id=X[&evaluation_id=Y]` (copies) |
| CLI | `db/publier_test.php fichier.json [--dry-run]` — clés `module` (commençant par le code), `sujet`, optionnelles `code_module`, `filiere`, `type_evaluation`, `numero_cc` |

## Invariants métier

- Suppression stagiaire : cascade manuelle `reponses_stagiaires → sessions_eval → stagiaire` via `supprimerStagiaire()` — FK `sessions_eval.stagiaire_id` en **RESTRICT** (refuse un stagiaire qui a encore des sessions) ; `cc_pratique_notes` part en CASCADE
- Groupes : FK `stagiaires.groupe_id` en **RESTRICT** (vider un groupe avant de le supprimer) · `groupes.nom` **UNIQUE**, toujours passé par `normaliserNomGroupe()` (majuscules, sans espaces : « id 101 » → « ID101 ») · migration `db/migrations/integrite_stagiaires_groupes.php`
- `stagiaires.annee_scolaire` n'a plus de valeur par défaut : toujours la fournir à l'INSERT (format `2026-2027`)
- Clés API : table `config` — `anthropic_api_key`, `openai_api_key`, `google_api_key`, `ollama_base_url`
- Provider IA actif : `config.ai_provider` ∈ {`anthropic`, `openai`, `google`, `ollama`}
- **Résultats visibles admin uniquement** — stagiaire ne voit pas l'historique de ses sessions après `result.php`
- **Noms + prénoms stagiaires/formateurs en MAJUSCULES** — `mb_strtoupper($x, 'UTF-8')` à la saisie (`creerStagiaireAdmin`, `modifierStagiaire`, stagiaires.php, formateurs_emploi.php). Jamais le CLI MySQL pour convertir (encodage)
- **Année de formation** : `getAnneeFormation(bool $short=true)` (functions.php) — calendrier scolaire sept→juil (mois ≥9 → an/an+1, sinon an-1/an). Ne plus utiliser `date('Y').'/'.(date('Y')+1)`
- **Note finale** : `arrondiNote(float $score, float $total, int $noteMax=20): float` — arrondi au **demi-point** (0.5) le plus proche. Utilisée dans `print_efm_result.php`. Ex : 13.64/20 → 13.5, 8.64/20 → 8.5.
- **Impression** : choix QCM en `<ul class="choix-list">` flex (4 col si ≤20 chars, 2 col défaut, 1 col si >60). Étiquette CC = numéro de l'évaluation (surchargeable via `print_blank.php?evaluation_id=X&cc_num=1|2|3`, cellule colorée). Ne jamais mettre de contenu HTML après un `style="..."` sans quote fermante (bug print_efm_result)

## Docker
- **Ollama optionnel** : sous `profiles: [ollama]` dans docker-compose.yml → `docker compose up -d` lance seulement db+app. IA locale : `docker compose --profile ollama up -d`
- Modifier `docker-compose.yml` ne nécessite **pas** de rebuild image (lu à l'exécution)

## IA multi-providers (`includes/ai_provider.php`)

- **Point d'entrée unique** : `callAIUnified($provider, $apiKey, $model, $systemPrompt, $messages, $maxTokens)`
- **Anthropic** : `/v1/messages` — header `x-api-key` + `anthropic-version: 2023-06-01` — supporte PDF natif via `anthropic-beta: pdfs-2024-09-25`
- **OpenAI** : `/v1/chat/completions` — header `Authorization: Bearer` — `_callOpenAI()` accepte un `$baseUrl` custom (réutilisé pour Ollama)
- **Google Gemini** : `/v1beta/models/{model}:generateContent?key=` — payload en `contents[].parts[]`
- **Ollama** (local Docker) : utilise l'**API native `/api/chat`** (pas OpenAI-compat) pour pouvoir définir `options.num_ctx` (sinon limité à 2048 → réponses tronquées)
  - **Fallback URLs** : essaie en cascade `ollama_base_url` → `ollama:11434` → `host.docker.internal:11434` → `localhost:11434` → `127.0.0.1:11434` (fonctionne sur Docker, Laragon et VM sans config)
  - **Pas de clé API** → check `if (!$apiKey && $provider !== 'ollama')` partout
  - **CPU = ~10 tokens/s** → `max_tokens` limité à 2000 pour Ollama (vs 8192 pour cloud) sinon timeout 5 min
  - Container `eval_ollama` dans `docker-compose.yml`, image `ollama/ollama:latest`, port 11434
  - **Docker Desktop RAM** : `settings-store.json` (`MemoryMiB`) **écrasé au boot** → modifier via GUI Settings > Resources (32 GB host → mettre 8 GB suffisent pour llama3.2:3b)

### Modèles Ollama recommandés (selon RAM Docker dispo)
| Modèle | Taille runtime | RAM requise |
|---|---|---|
| `qwen2.5:0.5b` | ~500 MB | 1 GB |
| `llama3.2:1b` | ~1.3 GB | 2 GB |
| `llama3.2:latest` (3B) | ~2.4 GB | 4 GB |

## EFM — Impressions OFPPT

- Évaluations EFM : `evaluations.type='efm'` ; code module et filière viennent du module, établissement et année de `meta_json` (champs `efm_*` fournis par `getEvaluation()`)
- **Images (logo, tampon)** : toujours en base64 (`base64_encode(file_get_contents($path))`) — chemins relatifs ignorés à l'impression

### Tampon `assets/img/tampon_ofppt.png` — bas droite de chaque page

**Navigateur** (`print_efm.php`, `print_efm_result.php`, `efm_fiche_resultat.php`) :
- Placer l'`<img>` **hors du div `.page`**, juste avant `</body>`
- CSS : `position: fixed; bottom: 14mm; right: 14mm; width: 38mm;` (toujours actif, pas seulement en @media print)
- ⚠ `position: absolute` dans un parent `position: relative` annule fixed → le tampon n'apparaît qu'une fois

**mPDF** (`admin/generate_efm_pdf.php`) :
- mPDF ignore `right` et `bottom` en `position: fixed` → utiliser `$mpdf->SetHTMLFooter()`
- Image dans `<td style="text-align:right">`, config : `margin_bottom: 48`, `margin_footer: 8`
