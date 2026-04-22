# Guide de Déploiement - Eval-Projet

## Pour le technicien chargé de l'installation sur serveur Windows

---

## 📋 Prérequis

- **OS** : Windows 10/11 ou Windows Server 2016+
- **Accès administrateur** : requis pour installer XAMPP et démarrer services
- **Connexion internet** : pour télécharger XAMPP (~160 Mo) et le code source
- **Espace disque** : 2 GB disponibles (XAMPP + projet)
- **Ports** : 80 (Apache), 3306 (MySQL) libres

---

## 🚀 Installation - Procédure rapide (10 min)

### Étape 1 : Préparer le fichier
1. Téléchargez ou récupérez le fichier **`deploy_windows.bat`**
2. Placez-le sur le Bureau ou un dossier facilement accessible
3. **Important** : n'exécutez PAS le fichier normalement

### Étape 2 : Exécuter en tant qu'administrateur
1. **Clic droit** sur `deploy_windows.bat`
2. Sélectionnez **« Exécuter en tant qu'administrateur »**
3. Cliquez **« Oui »** si une fenêtre d'alerte apparaît

### Étape 3 : Suivre les écrans
Le script affiche 8 étapes :
- **[1/8]** Vérification des droits
- **[2/8]** Téléchargement du code (Git ou ZIP)
- **[3/8]** Détection/installation XAMPP
- **[4/8]** Démarrage services Apache + MySQL
- **[5/8]** Déploiement des fichiers
- **[6/8]** **Création base de données** (automatique, ~10 sec)
- **[7/8]** Ouverture navigateur
- **[8/8]** Affichage du guide final

**Durée totale** : 2-5 minutes (sans installer XAMPP) ou 10-15 min (avec installation XAMPP)

---

## 📁 Fichiers à fournir au technicien

```
DEPLOIEMENT - Package technicien
├── deploy_windows.bat          ← Fichier PRINCIPAL (exécutable)
├── DEPLOIEMENT.md              ← Ce guide
├── QUICK_START.txt             ← Aide rapide (1 page)
├── CREDENTIALS.txt             ← Comptes par défaut
├── DEPANNAGE.txt               ← Solutions aux problèmes courants
├── VERIFICATION.txt            ← Checklist post-déploiement
└── README.txt                  ← Info générale sur l'appli
```

---

## 📝 Contenu des fichiers joints

### CREDENTIALS.txt (à conserver et transmettre)
```
BASE DE DONNEES
===============
Nom       : eval_online
Host      : localhost (127.0.0.1)
Port      : 3306
User      : root
Password  : (vide)

COMPTE ADMIN PAR DEFAUT
=======================
Login     : admin
Password  : admin123

⚠️ CHANGEMENT OBLIGATOIRE A LA PREMIERE CONNEXION
```

### QUICK_START.txt (à imprimer / afficher)
```
DEPLOIEMENT EN 3 ETAPES
=======================
1. Clic droit sur deploy_windows.bat
2. "Exécuter en tant qu'administrateur"
3. Suivre les instructions (15 min max)

ACCES APRES
===========
- Stagiaire  : http://localhost/eval-projet/
- Admin      : http://localhost/eval-projet/admin/
- PhpMyAdmin : http://localhost/phpmyadmin/

LOGIN : admin
PASS  : admin123 (changer immédiatement)
```

### DEPANNAGE.txt (problèmes courants)
```
PROBLEME 1 : "Ce script doit être lancé en tant qu'Administrateur"
SOLUTION   : Clic droit - "Exécuter en tant qu'administrateur"

PROBLEME 2 : "Telechargement ZIP echoue" / Pas de git
SOLUTION   : Verifiez connexion internet / proxy
             Relancez le script

PROBLEME 3 : "MySQL ne repond pas"
SOLUTION   : Demarrez XAMPP : C:\xampp\xampp-control.exe
             Cliquez "Start" pour MySQL
             Relancez le script

PROBLEME 4 : Erreur 500 ou page blanche
SOLUTION   : Logs : C:\xampp\logs\php_error.log
             Verifiez extensions PHP activees

PROBLEME 5 : Port 80 ou 3306 deja utilises
SOLUTION   : Tuez le processus ou changez le port dans XAMPP
             Relancez Apache/MySQL

PROBLEME 6 : "La base n'a pas ete creee"
SOLUTION   : MySQL doit etre en cours d'execution
             Lancez XAMPP control, cliquez "Start MySQL"
             Relancez le script

PROBLEME 7 : Reinstaller l'app sans supprimer les donnees
SOLUTION   : Relancez le script (il drop + recreate la base)
             OU sauvegardez la base avant :
             mysqldump -u root eval_online > backup.sql
```

### VERIFICATION.txt (checklist post-deploy)
```
VERIFICATION POST-DEPLOIEMENT
==============================

[ ] 1. Page d'accueil stagiaire charge
    http://localhost/eval-projet/
    - Logo et interface visible

[ ] 2. Connexion admin fonctionne
    http://localhost/eval-projet/admin/
    - Login : admin
    - Password : admin123
    - Tableau de bord affiche

[ ] 3. Base de donnees cree
    http://localhost/phpmyadmin/
    - Login : root (vide)
    - Base eval_online presente
    - Tables : admins, groupes, modules, questions, etc.

[ ] 4. Donnees de demo insertees
    Admin > Gestion modules
    - "Module Demo" est present et actif
    - 4 questions lisibles

[ ] 5. Compte admin fonctionne
    Admin > (menu futur pour changer mot de passe)
    - Acces OK

[ ] 6. Services runs
    XAMPP control : C:\xampp\xampp-control.exe
    - Apache : vert (running)
    - MySQL : vert (running)

[ ] 7. Redemarrage OK (optionnel)
    Arretez Apache/MySQL
    Relancez XAMPP
    Verifiez page charge
    - Tous les services demarrent sans erreur

Si tous [ ] coches : DEPLOIEMENT OK ✓
```

### README.txt (info appli)
```
EVAL-PROJET - Outil d'Evaluation en Ligne
==========================================

DESCRIPTION
-----------
Application PHP/MySQL permettant aux formateurs de creer
des evaluations en ligne pour les stagiaires.

FONCTIONNALITES
---------------
- Gestion groupes et stagiaires
- Creation modules + questions (QCM, V/F, texte libre)
- Generation IA de QCM (via API Anthropic - optionnel)
- Evaluation en ligne avec chrono
- Correction automatique + export Excel
- Dashboard admin avec statistiques

ARCHITECTURE
------------
- Serveur   : Apache + PHP 8.3
- Base      : MySQL 8.4
- Frontend  : HTML/CSS/JavaScript
- Stack     : XAMPP (Apache + MySQL + PHP)

CONFIGURATION APRES DEPLOY
--------------------------
1. CHANGEZ MOT DE PASSE ADMIN (obligatoire)
2. Creez vos groupes (Admin > Gestion Groupes)
3. Creez vos modules (Admin > Gestion Modules)
4. Creez vos questions (Admin > Gestion Questions)
5. (Optionnel) Configurez cle API Anthropic
   pour generation IA de questions

CLES API ANTHROPIC
------------------
Pour activer la generation IA de QCM :
1. Allez sur https://console.anthropic.com/
2. Creez un compte (gratuit, budget de test)
3. Gerez votre cle API
4. Copiez la cle dans Admin > Configuration
   (ou via phpMyAdmin : UPDATE config SET ...)

SUPPORT
-------
Contactez le developpeur :
- Email : mounir.elgholabzouri@gmail.com
- GitHub : https://github.com/mounirelgholabzouri/eval-projet
```

---

## ⚙️ Configuration après déploiement

### 1️⃣ Changement mot de passe admin (OBLIGATOIRE)
```
Admin > Configuration > Changer mot de passe
Login : admin
Ancien MDP : admin123
Nouveau MDP : (choisir un MDP fort)
```

### 2️⃣ Configuration API Claude (optionnel mais recommandé)
Pour activer la génération IA de QCM :

**Via interface (futur)** :
```
Admin > Configuration > Clé API Anthropic
```

**Via phpMyAdmin (maintenant)** :
```
1. Allez sur http://localhost/phpmyadmin/
2. Login : root (pas de mot de passe)
3. Sélectionnez base "eval_online"
4. Allez dans table "config"
5. Modifiez la ligne "anthropic_api_key" :
   Valeur actuelle : (vide)
   Nouvelle valeur : sk-...votre cle...
6. Cliquez "Enregistrer"
```

**Obtenir la clé API** :
1. Allez sur https://console.anthropic.com/
2. Créez un compte (gratuit)
3. Allez dans "API Keys"
4. Créez une nouvelle clé
5. Copiez la clé (ex: sk-ant-v0-xxx...)

### 3️⃣ Création des données métier
```
Admin > Gestion Groupes
→ Créer "Groupe A", "Groupe B", etc.

Admin > Gestion Modules
→ Créer "Module Français", "Module Math", etc.
→ Activer les modules désirés (colonne "Actif")

Admin > Gestion Questions
→ Sélectionner un module
→ Ajouter les questions (QCM, V/F, Texte libre)

Admin > Gestion Stagiaires
→ Créer/importer les stagiaires
→ Assigner à des groupes
```

---

## 🔧 Commandes utiles (PowerShell Admin)

### Redémarrer Apache
```powershell
C:\xampp\apache_stop.bat
Start-Sleep -Seconds 2
C:\xampp\apache_start.bat
```

### Redémarrer MySQL
```powershell
C:\xampp\mysql_stop.bat
Start-Sleep -Seconds 2
C:\xampp\mysql_start.bat
```

### Relancer l'installation
```powershell
# Si vous avez une sauvegarde de la base
mysqldump -u root eval_online > C:\backup_eval.sql

# Relancez le script (il recreera la base vide)
.\deploy_windows.bat

# Pour restaurer la sauvegarde :
mysql -u root eval_online < C:\backup_eval.sql
```

### Consulter les logs
```powershell
# Logs PHP
Get-Content C:\xampp\logs\php_error.log -Tail 50

# Logs Apache
Get-Content C:\xampp\apache\logs\error.log -Tail 50

# Logs MySQL
Get-Content "C:\xampp\mysql\data\MACHINE_NAME.err" -Tail 50
```

### Vérifier les ports
```powershell
netstat -ano | findstr :80
netstat -ano | findstr :3306
```

---

## 📊 Structure fichiers post-deploy

```
C:\xampp\htdocs\eval-projet\
├── admin/                    # Interface admin
├── assets/                   # CSS, images
├── config/
│   └── database.php          # Config DB
├── db/
│   └── schema.sql            # Schema initial
├── includes/
│   ├── functions.php         # Fonctions métier
│   └── admin_auth.php        # Auth admin
├── index.php                 # Accueil stagiaire
├── login_stagiaire.php       # Connexion stagiaire
├── quiz.php                  # Evaluation
├── result.php                # Resultats
└── ...autres fichiers
```

---

## 🆘 Support et dépannage avancé

### Logs détaillés
```
C:\xampp\logs\       # Tous les logs
C:\xampp\logs\php_error.log
C:\xampp\apache\logs\error.log
C:\xampp\mysql\data\*.err
```

### Base de données
```
PhpMyAdmin : http://localhost/phpmyadmin/
User       : root
Pass       : (vide)
```

### Contacter le développeur
```
Email  : mounir.elgholabzouri@gmail.com
GitHub : https://github.com/mounirelgholabzouri/eval-projet
```

---

## 📝 Notes pour le technicien

- ✅ Le script est **idempotent** : relancez-le autant que nécessaire
- ✅ La base de données est **réinitialisée** à chaque run (données de démo restaurées)
- ✅ Si vous avez des données à conserver, **sauvegardez-les avant** de relancer
- ✅ XAMPP doit tourner pour que l'appli fonctionne
- ✅ Les données entrées par les stagiaires sont dans la table `reponses_stagiaires`
- ✅ Les résultats peuvent être exportés en Excel depuis Admin > Résultats

---

## 📞 Assistance

Si le déploiement échoue :
1. Consultez `DEPANNAGE.txt`
2. Vérifiez `VERIFICATION.txt`
3. Contactez le développeur avec :
   - La dernière ligne d'erreur affichée
   - La version de Windows
   - Les logs de C:\xampp\logs\

**Développeur** : mounir.elgholabzouri@gmail.com
