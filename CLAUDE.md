# CLAUDE.md — Instructions pour Claude Code

## Environnement

### Développement local (Windows / Laragon)
- **OS** : Windows 10/11, utilisateur `Administrateur`
- **Serveur web** : Apache via **Laragon** (multi-thread, port 80) — NE PAS utiliser le serveur PHP built-in
- **PHP** : `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`
- **MySQL** : `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe`
- **Racine projet** : `C:\Users\Administrateur\Eval-Projet\`
- **URL locale** : `http://localhost/` (Apache `_default_:80` pointe sur le projet)

### Docker (optionnel)
- **Image** : `php:8.3-apache` + extensions `pdo_mysql`, `mbstring`, `opcache`
- **Lancer** : `docker compose up --build`
- **URL** : `http://localhost:8080`
- **DB dans Docker** : `mysql:8.4`, mot de passe root `root`, init automatique via `db/schema.sql`
- **Variables d'env** : `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` (défauts : `db` / `eval_online` / `root` / `root`)
- **Entrypoint** : attend MySQL, installe Composer si besoin, démarre Apache
- **php.ini Docker** : upload 10M, post 12M, memory 256M, exec 120s, UTF-8

## Base de données

- **Nom** : `eval_online` — **Host** : `localhost` — **User** : `root` / **Password** : *(vide)*
- **Charset** : `utf8mb4` — TOUJOURS utiliser PDO/PHP pour les insertions (MySQL CLI = cp850 = accents corrompus)

### Exécuter un script PHP
```bash
powershell -Command "& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' 'C:\Users\Administrateur\Eval-Projet\script.php' 2>&1"
```

## Conventions de code

### Sessions stagiaire — ORDRE OBLIGATOIRE
```php
require_once __DIR__ . '/includes/functions.php';    // 1. D'abord
session_name(SESSION_EVAL_NAME ?? 'eval_stagiaire'); // 2. Ensuite
session_start();                                      // 3. Enfin
```

### Sessions admin
```php
require_once __DIR__ . '/../includes/admin_auth.php';
// admin_auth.php gère session_name(ADMIN_SESSION_NAME) + session_start() automatiquement
```

### Constantes (`config/database.php`)
- `SESSION_EVAL_NAME` = `'eval_stagiaire'`
- `ADMIN_SESSION_NAME` = `'eval_admin'`
- `SITE_NAME` = `"Outil d'Évaluation en Ligne"`

### Sécurité
- Affichage HTML : `sanitize($str)` → `htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8')`
- SQL : requêtes préparées PDO uniquement (jamais de concaténation)
- Mots de passe : `password_hash($pwd, PASSWORD_BCRYPT)` / `password_verify()`

### Encodage
- Tous les fichiers PHP : **UTF-8 sans BOM**
- Insertions DB : via scripts PHP/PDO uniquement

## Structure des fichiers

```
Eval-Projet/
├── index.php                  # Accueil stagiaire (requiert auth, alerte must_change_password)
├── login_stagiaire.php        # Connexion stagiaire (alimente SESSION must_change_password)
├── register.php               # Inscription stagiaire (auto-login après création)
├── logout_stagiaire.php       # Déconnexion stagiaire
├── changer_password.php       # Changement mot de passe stagiaire (forcé si must_change_password)
├── quiz.php                   # Page d'évaluation (requiert eval_session_id en SESSION)
├── result.php                 # Résultats après évaluation ; bouton "Fiche EFM" si module type=efm, sinon Excel
├── efm_fiche_resultat.php     # Fiche résultat EFM imprimable (accès stagiaire ou admin via ?sid=X)
├── config/
│   └── database.php           # Config DB + constantes + getDB()
├── includes/
│   ├── functions.php          # Toutes les fonctions métier
│   ├── admin_auth.php         # Middleware auth admin
│   └── claude_generator.php   # Génération QCM via API Claude
├── admin/
│   ├── index.php              # Dashboard admin
│   ├── modules.php            # Gestion modules/QCM (toggle actif AJAX)
│   ├── questions.php          # Gestion questions + CRUD parties (sections)
│   ├── results.php            # Liste des résultats + export Excel/CSV
│   ├── export_excel.php       # Export SpreadsheetML .xls (2 feuilles : évals + moyennes/stagiaire)
│   ├── detail.php             # Détail d'une session
│   ├── stagiaires.php         # CRUD stagiaires (créer/modifier/supprimer cascade/reset mdp)
│   ├── groupes.php            # Gestion des groupes (modals, nb stagiaires, protection FK)
│   ├── generate.php           # Génération QCM par IA
│   ├── correct_texte.php      # Correction questions texte libre
│   ├── fusion.php             # Fusion QCM + création EFM (onglets) — stocke type='efm' + meta_json
│   ├── print_efm_result.php   # Impression fiche résultat EFM format OFPPT (?session_id=X, admin only)
│   ├── print_efm.php          # Impression sujet EFM vierge (?module_id=X)
│   ├── print_exams.php        # Impression sujets QCM classiques
│   ├── login.php              # Connexion admin
│   ├── logout.php             # Déconnexion admin
│   └── partials/navbar.php    # Barre de navigation admin
├── assets/
│   ├── css/style.css          # Styles personnalisés
│   └── img/logo_efm.png       # Logo OFPPT (extrait du modèle DOCX, intégré en base64 dans print_efm_result.php)
├── db/
│   ├── schema.sql                 # Schéma complet + données de démo (source de vérité)
│   ├── migration_v2.sql           # Migration historique (déjà intégrée dans schema.sql)
│   └── migration_stagiaires.sql   # Migration stagiaires (déjà appliquée)
├── docker/
│   ├── apache-vhost.conf      # VirtualHost Apache pour Docker
│   ├── entrypoint.sh          # Attend MySQL, installe Composer, lance Apache
│   └── php.ini                # Config PHP (upload, memory, charset UTF-8)
├── Dockerfile                 # Image php:8.3-apache + pdo_mysql + mbstring
├── docker-compose.yml         # Services app (port 8080) + db (mysql:8.4)
├── .dockerignore
├── .gitignore
└── CLAUDE.md                  # Ce fichier
```

> **Migrations appliquées hors schema.sql** : table `stagiaires` + colonne `stagiaire_id` dans `sessions_eval` + table `parties` + colonne `partie_id NOT NULL` dans `questions` (via `migrate_parties.php` puis `migrate_parties_v2.php`).
>
> **Invariants** : chaque module possède au moins une partie (« Général » par défaut) ; chaque question appartient à une partie ; on ne peut pas supprimer la dernière partie d'un module.

## Schéma de base de données

| Table | Colonnes clés |
|---|---|
| `admins` | id, username, password_hash, nom |
| `groupes` | id, nom |
| `stagiaires` | id, nom, prenom, groupe_id, annee_scolaire, login, password_hash, **must_change_password** |
| `modules` | id, nom, description, duree_minutes, note_max, actif, **type** ('qcm'/'efm'), **meta_json** (JSON: code_module, filiere, etablissement, annee) |
| `parties` | id, module_id, nom, ordre — sections d'un module (ex. M205 → "Renforcer VM", "Azure Firewall") |
| `questions` | id, module_id, **partie_id NOT NULL** (FK parties, ON DELETE RESTRICT), texte, type, points, ordre |
| `choix_reponses` | id, question_id, texte, is_correct, ordre |
| `sessions_eval` | id, token, **stagiaire_id**, nom, prenom, groupe_id, module_id, statut, score, pourcentage |
| `reponses_stagiaires` | id, session_id, question_id, choix_id, reponse_texte, is_correct, points_obtenus |
| `config` | cle, valeur (ex: claude_api_key) |

## Flux d'authentification stagiaire

1. `register.php` → crée le stagiaire en DB, connecte automatiquement, redirige vers `index.php`
2. `login_stagiaire.php` → vérifie login/password via `getStagiaireByLogin()`, alimente la SESSION
3. SESSION stagiaire : `stagiaire_id`, `stagiaire_nom`, `stagiaire_prenom`, `stagiaire_groupe_id`, `stagiaire_groupe_nom`, `stagiaire_annee`, `must_change_password`
4. Si `must_change_password = 1` (compte créé par admin) → alerte sur `index.php` → `changer_password.php`

## Flux d'évaluation

1. `index.php` (POST `module_id`) → `creerSession()` → `$_SESSION['eval_session_id']` → redirect `quiz.php`
2. `quiz.php` → lit `eval_session_id` → affiche les questions → POST `submit_final` → `terminerSession()`
3. `result.php` → affiche le score
   - Module type=**efm** → bouton **Fiche résultat EFM** → `efm_fiche_resultat.php?sid=X`
   - Module type=**qcm** → bouton **Télécharger Excel** + Imprimer

## Fonctions métier clés (`includes/functions.php`)

| Fonction | Rôle |
|---|---|
| `getModulesActifs()` | Modules avec actif=1 |
| `getQuestionsModule($id)` | Questions + choix d'un module (ordonnées par partie_id puis ordre) |
| `getPartiesModule($id)` | Parties d'un module + nb_questions |
| `creerPartie($moduleId, $nom, $ordre)` | Crée une partie (ordre auto si 0) |
| `ensurePartieDefault($moduleId)` | Retourne la 1re partie, crée « Général » si aucune — garantit l'invariant |
| `supprimerPartie($id)` | Réassigne les questions à une autre partie ; refuse si dernière partie du module |
| `renommerPartie($id, $nom)` | Renomme une partie |
| `getPartie($id)` | Fetch une partie par id |
| `getQuestionsGroupeesParPartie($id)` | Questions groupées par partie (toutes rattachées) |
| `creerSession(...)` | Démarre une session d'évaluation |
| `sauvegarderReponse(...)` | Upsert réponse stagiaire |
| `terminerSession($id)` | Calcule score, passe statut à 'termine' |
| `getStagiaireByLogin($login)` | Authentification stagiaire |
| `loginExists($login, $excludeId)` | Vérifie unicité login |
| `genererLogin($prenom, $nom)` | Génère login unique (Pierre.DUPONT) |
| `creerStagiaireAdmin(...)` | Crée stagiaire avec mdp par défaut 123456 |
| `trouverOuCreerGroupe($nom, $annee)` | Groupe auto-créé si inexistant |
| `getAnneeCourante()` / `getAnneesDisponibles()` | Années scolaires |
| `supprimerStagiaire($id)` | Cascade : reponses_stagiaires → sessions_eval → stagiaire |
| `getStatsGlobales()` | KPIs dashboard (nb_stagiaires, nb_groupes, nb_modules, sessions…) |

## Commandes utiles

### Lint PHP
```bash
powershell -Command "& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' -l 'C:\Users\Administrateur\Eval-Projet\fichier.php' 2>&1"
```

### Vérifier la connexion DB
```bash
powershell -Command "& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' -r \"`$pdo=new PDO('mysql:host=127.0.0.1;dbname=eval_online;charset=utf8mb4','root',''); echo 'OK';\" 2>&1"
```

### Tester une page HTTP
```bash
curl -s -o /dev/null -w "%{http_code}" "http://localhost/page.php"
```

### Redémarrer Apache
Laragon UI → clic droit → **Reload** ou Stop Apache puis Start Apache

## Export Excel (`admin/export_excel.php`)

Génère un `.xls` SpreadsheetML **sans bibliothèque externe** (aucun Composer requis).

- **Feuille 1 "Évaluations"** : toutes les évals terminées — nom, prénom, groupe, module, date, score brut, note /20, %, mention. Cellules colorées vert (≥50%) / rouge (<50%).
- **Feuille 2 "Moyennes par stagiaire"** : moyenne %, meilleure note, note la plus basse, mention + ligne MOYENNE GÉNÉRALE.
- **Paramètres GET** : `?module_id=X` et/ou `?groupe_id=Y`
- **Auth** : session admin (`eval_admin`) **ou** session stagiaire (`eval_stagiaire`)
- **Accès** : bouton sur `result.php` (filtré module) + bouton sur `admin/results.php`

## Points d'attention

1. **Serveur** : Ne jamais utiliser `php -S` (mono-thread, bloque AJAX) → Apache Laragon uniquement
2. **Encodage** : Insertions avec accents via scripts PHP/PDO uniquement (MySQL CLI = cp850)
3. **Session order** : `require_once` → `session_name()` → `session_start()` — toujours dans cet ordre
4. **Submit JS** : `form.submit()` n'envoie pas les boutons submit → ajouter `<input type="hidden" name="submit_final" value="1">`
5. **MySQL CLI pipe** : utiliser `Get-Content fichier.sql | mysql.exe` (l'opérateur `<` est bloqué en PowerShell)
6. **ALTER TABLE IF NOT EXISTS** : non supporté par MySQL 8.4 de Laragon → vérifier via `SHOW COLUMNS` en PHP avant d'ajouter
7. **Migrations** : toujours via un script PHP/PDO — jamais le CLI MySQL (encodage cp850)
8. **GROUP BY MySQL** : mode `only_full_group_by` actif → toutes les colonnes SELECT non-agrégées doivent être dans GROUP BY ou wrappées dans MAX()/MIN()
9. **Suppression stagiaire** : cascade manuelle (reponses_stagiaires → sessions_eval → stagiaire) — pas de FK ON DELETE CASCADE en DB
10. **VirtualHost Apache** : `C:\laragon\etc\apache2\sites-enabled\00-default.conf`
11. **Clé API Anthropic** : stockée en table `config` (clé `anthropic_api_key`). Crédits à gérer sur **console.anthropic.com** (≠ claude.ai qui est l'interface web). En cas d'erreur "credit balance too low", recharger sur console.anthropic.com → Billing → Add credits.
12. **Génération IA** : `admin/generate.php` accepte un document (PDF/DOCX/TXT) **et/ou** un prompt texte libre — les deux sont optionnels séparément mais au moins un est requis.
13. **EFM (Examen de Fin de Module)** : modules avec `type='efm'` et métadonnées dans `meta_json` (code_module, filiere, etablissement, annee). Impression officielle via `admin/print_efm_result.php?session_id=X` — logo OFPPT intégré en base64 pour garantir la visibilité à l'impression.
14. **Logo impression** : toujours intégrer les images critiques en base64 dans le PHP (`base64_encode(file_get_contents($path))`) — les chemins relatifs sont ignorés par les navigateurs en mode impression.
