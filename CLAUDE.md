# CLAUDE.md — Instructions pour Claude Code

## Environnement

- **OS** : Windows 10/11, utilisateur `Administrateur`
- **Serveur web** : Apache via **Laragon** (multi-thread, port 80) — NE PAS utiliser le serveur PHP built-in
- **PHP** : `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`
- **MySQL** : `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe`
- **Racine projet** : `C:\Users\Administrateur\Eval-Projet\`
- **URL locale** : `http://localhost/` (Apache `_default_:80` pointe sur le projet)

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
├── result.php                 # Résultats après évaluation
├── config/
│   └── database.php           # Config DB + constantes + getDB()
├── includes/
│   ├── functions.php          # Toutes les fonctions métier
│   ├── admin_auth.php         # Middleware auth admin
│   └── claude_generator.php   # Génération QCM via API Claude
├── admin/
│   ├── index.php              # Dashboard admin
│   ├── modules.php            # Gestion modules/QCM (toggle actif AJAX)
│   ├── questions.php          # Gestion questions
│   ├── results.php            # Liste des résultats
│   ├── detail.php             # Détail d'une session
│   ├── stagiaires.php         # CRUD stagiaires (créer/modifier/supprimer/reset mdp)
│   ├── groupes.php            # Gestion des groupes
│   ├── generate.php           # Génération QCM par IA
│   ├── correct_texte.php      # Correction questions texte libre
│   ├── login.php              # Connexion admin
│   ├── logout.php             # Déconnexion admin
│   └── partials/navbar.php    # Barre de navigation admin
├── assets/css/style.css       # Styles personnalisés
├── db/
│   ├── schema.sql             # Schéma complet + données de démo (source de vérité)
│   └── migration_v2.sql       # Migration historique (déjà intégrée dans schema.sql)
└── CLAUDE.md                  # Ce fichier
```

> **Migrations appliquées hors schema.sql** : table `stagiaires` + colonne `stagiaire_id` dans `sessions_eval` (appliquées via script PHP PDO).

## Schéma de base de données

| Table | Colonnes clés |
|---|---|
| `admins` | id, username, password_hash, nom |
| `groupes` | id, nom |
| `stagiaires` | id, nom, prenom, groupe_id, annee_scolaire, login, password_hash, **must_change_password** |
| `modules` | id, nom, description, duree_minutes, note_max, actif |
| `questions` | id, module_id, texte, type (qcm/vrai_faux/texte_libre/multiple), points, ordre |
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
3. `result.php` → affiche le score depuis `$_SESSION['eval_result']`

## Fonctions métier clés (`includes/functions.php`)

| Fonction | Rôle |
|---|---|
| `getModulesActifs()` | Modules avec actif=1 |
| `getQuestionsModule($id)` | Questions + choix d'un module |
| `creerSession(...)` | Démarre une session d'évaluation |
| `sauvegarderReponse(...)` | Upsert réponse stagiaire |
| `terminerSession($id)` | Calcule score, passe statut à 'termine' |
| `getStagiaireByLogin($login)` | Authentification stagiaire |
| `loginExists($login, $excludeId)` | Vérifie unicité login |
| `genererLogin($prenom, $nom)` | Génère login unique (Pierre.DUPONT) |
| `creerStagiaireAdmin(...)` | Crée stagiaire avec mdp par défaut 123456 |
| `trouverOuCreerGroupe($nom, $annee)` | Groupe auto-créé si inexistant |
| `getAnneeCourante()` / `getAnneesDisponibles()` | Années scolaires |

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

## Points d'attention

1. **Serveur** : Ne jamais utiliser `php -S` (mono-thread, bloque AJAX) → Apache Laragon uniquement
2. **Encodage** : Insertions avec accents via scripts PHP/PDO uniquement (MySQL CLI = cp850)
3. **Session order** : `require_once` → `session_name()` → `session_start()` — toujours dans cet ordre
4. **Submit JS** : `form.submit()` n'envoie pas les boutons submit → ajouter `<input type="hidden" name="submit_final" value="1">`
5. **MySQL CLI pipe** : utiliser `Get-Content fichier.sql | mysql.exe` (l'opérateur `<` est bloqué en PowerShell)
6. **ALTER TABLE IF NOT EXISTS** : non supporté par MySQL 8.4 de Laragon → vérifier via `SHOW COLUMNS` en PHP avant d'ajouter
7. **Migrations** : toujours via un script PHP/PDO — jamais le CLI MySQL (encodage cp850)
8. **VirtualHost Apache** : `C:\laragon\etc\apache2\sites-enabled\00-default.conf`
