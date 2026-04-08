# CLAUDE.md — Instructions pour Claude Code

Ce fichier contient les instructions techniques essentielles pour travailler sur ce projet.

## Environnement

- **OS** : Windows 10/11, utilisateur `Administrateur`
- **Serveur web** : Apache via **Laragon** (multi-thread, port 80) — NE PAS utiliser le serveur PHP built-in
- **PHP** : `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`
- **MySQL** : `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe`
- **Racine projet** : `C:\Users\Administrateur\Eval-Projet\`
- **Symlink web** : `C:\laragon\www\eval-projet` → `C:\Users\Administrateur\Eval-Projet`
- **URL locale** : `http://localhost/` (Apache `_default_:80` pointe sur le projet)
- **URL réseau** : `http://<IP_locale>/`

## Base de données

- **Nom de la DB** : `eval_online`
- **Host** : `localhost` (127.0.0.1)
- **User** : `root` / **Mot de passe** : *(vide)*
- **Charset** : `utf8mb4` — TOUJOURS utiliser PDO/PHP pour les insertions (jamais MySQL CLI direct → problème encodage cp850)

### Exécuter du PHP (pour insérer en DB, lancer des scripts)
```powershell
powershell -Command "& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' 'C:\Users\Administrateur\Eval-Projet\monscript.php' 2>&1"
```

## Conventions de code

### Sessions PHP — ORDRE OBLIGATOIRE
```php
require_once __DIR__ . '/includes/functions.php';   // 1. D'abord
session_name(SESSION_EVAL_NAME ?? 'eval_stagiaire'); // 2. Ensuite
session_start();                                     // 3. Enfin
```

### Sessions admin
```php
require_once __DIR__ . '/../includes/admin_auth.php';
// admin_auth.php gère automatiquement session_name(ADMIN_SESSION_NAME) + session_start()
```

### Constantes de config (définies dans `config/database.php`)
- `SESSION_EVAL_NAME` = `'eval_stagiaire'`
- `ADMIN_SESSION_NAME` = `'eval_admin'`
- `SITE_NAME` = `"Outil d'Évaluation en Ligne"`

### Sécurité
- Toujours utiliser `sanitize($str)` pour l'affichage HTML → `htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8')`
- Toujours utiliser des requêtes préparées PDO (jamais de concaténation SQL)
- Mots de passe : `password_hash($pwd, PASSWORD_BCRYPT)` / `password_verify()`

### Encodage
- Tous les fichiers PHP : **UTF-8 sans BOM**
- Insertions DB : via PHP/PDO uniquement (MySQL CLI = cp850 = caractères corrompus)

## Structure des fichiers

```
Eval-Projet/
├── index.php                  # Accueil stagiaire (requiert auth)
├── login_stagiaire.php        # Connexion stagiaire
├── register.php               # Inscription stagiaire
├── logout_stagiaire.php       # Déconnexion stagiaire
├── quiz.php                   # Page d'évaluation
├── result.php                 # Résultats après évaluation
├── config/
│   └── database.php           # Config DB + constantes + getDB()
├── includes/
│   ├── functions.php          # Toutes les fonctions métier
│   ├── admin_auth.php         # Middleware auth admin
│   └── claude_generator.php  # Génération QCM via API Claude
├── admin/
│   ├── index.php              # Dashboard admin
│   ├── modules.php            # Gestion modules/QCM (toggle actif AJAX)
│   ├── questions.php          # Gestion questions
│   ├── results.php            # Liste des résultats
│   ├── detail.php             # Détail d'une session
│   ├── stagiaires.php         # Liste des stagiaires
│   ├── groupes.php            # Gestion des groupes
│   ├── generate.php           # Génération QCM par IA
│   ├── correct_texte.php      # Correction questions texte libre
│   ├── login.php              # Connexion admin
│   ├── logout.php             # Déconnexion admin
│   └── partials/navbar.php    # Barre de navigation admin
├── assets/css/style.css       # Styles personnalisés
├── db/
│   ├── schema.sql             # Schéma complet + données de démo
│   └── migration_v2.sql       # Migrations ajoutées après création
└── CLAUDE.md                  # Ce fichier
```

## Schéma de base de données (tables principales)

| Table | Rôle |
|---|---|
| `admins` | Comptes formateurs (login/password_hash) |
| `groupes` | Groupes de stagiaires (nom, annee) |
| `stagiaires` | Profils stagiaires (nom, prenom, groupe_id, annee_scolaire, login, password_hash) |
| `modules` | Évaluations/QCM (nom, description, duree_minutes, note_max, actif) |
| `questions` | Questions (texte, type, points, ordre, module_id) |
| `choix_reponses` | Réponses possibles (texte, is_correct, question_id) |
| `sessions_eval` | Sessions d'évaluation (token, stagiaire_id, module_id, statut, score) |
| `reponses_stagiaires` | Réponses données (session_id, question_id, choix_id, is_correct, points_obtenus) |
| `config` | Configuration clé/valeur (ex: claude_api_key) |

## Commandes utiles

### Lint PHP
```powershell
powershell -Command "& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' -l 'C:\Users\Administrateur\Eval-Projet\fichier.php' 2>&1"
```

### Vérifier la DB
```powershell
powershell -Command "& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' -r \"`$pdo=new PDO('mysql:host=127.0.0.1;dbname=eval_online;charset=utf8mb4','root',''); echo 'OK';\" 2>&1"
```

### Redémarrer Apache (Laragon)
Laragon UI → Stop → Start (ou Laragon hérite du démarrage Windows)

## Points d'attention

1. **Serveur mono-thread** : Le serveur PHP built-in (`php -S`) bloque les requêtes parallèles → utiliser Apache Laragon
2. **Encodage** : Ne JAMAIS insérer de données avec accents via MySQL CLI → passer par des scripts PHP
3. **Session order** : `require_once` doit toujours précéder `session_name()` et `session_start()`
4. **Submit JS** : `form.submit()` via JavaScript n'envoie pas la valeur des boutons submit → ajouter `<input type="hidden" name="submit_final" value="1">` avant soumission
5. **VirtualHost Apache** : Config dans `C:\laragon\etc\apache2\sites-enabled\00-default.conf`
