================================================================================
  EVAL-PROJET - Outil d'Evaluation en Ligne
================================================================================

DESCRIPTION
===========
Eval-Projet est une application web permettant aux formateurs et responsables
de formation de creer, lancer et corriger des evaluations en ligne pour leurs
stagiaires. Les evaluations peuvent contenir des questions a choix multiple,
vrai/faux, ou reponses texte libre.


CARACTERISTIQUES PRINCIPALES
=============================

POUR LES FORMATEURS (Admin) :
  ✓ Gerer les groupes de stagiaires
  ✓ Creer les modules d'evaluation (avec chrono)
  ✓ Ajouter des questions (QCM, V/F, texte libre)
  ✓ Generer des questions par IA (optionnel, avec clé Anthropic)
  ✓ Corriger les reponses texte libres
  ✓ Consulter les resultats et statistiques
  ✓ Exporter les resultats en Excel

POUR LES STAGIAIRES :
  ✓ S'inscrire / se connecter
  ✓ Consulter les modules disponibles
  ✓ Faire une evaluation avec chrono
  ✓ Voir ses resultats immediatement
  ✓ Telecharger son evaluation en PDF


TECHNOLOGIES UTILISEES
======================
- Backend      : PHP 8.3 (Procedural)
- Serveur web  : Apache 2.4
- Base donnees : MySQL 8.4
- Frontend     : HTML5, CSS3, JavaScript vanilla
- Stack        : XAMPP (Apache + MySQL + PHP)


ARCHITECTURE
============
C:\xampp\htdocs\eval-projet\
├── admin/
│   ├── index.php               # Tableau de bord admin
│   ├── modules.php             # Gestion des modules
│   ├── questions.php           # Gestion des questions
│   ├── results.php             # Resultats et export
│   ├── stagiaires.php          # CRUD stagiaires
│   ├── groupes.php             # Gestion groupes
│   ├── generate.php            # Generation IA
│   ├── correct_texte.php       # Correction texte libre
│   ├── export_excel.php        # Export Excel
│   ├── login.php               # Authentification
│   └── partials/navbar.php     # Navigation
├── config/
│   └── database.php            # Configuration DB
├── db/
│   └── schema.sql              # Schema initial
├── includes/
│   ├── functions.php           # Fonctions métier
│   ├── admin_auth.php          # Middleware admin
│   └── claude_generator.php    # API Anthropic
├── assets/
│   └── css/style.css           # Styles
├── index.php                   # Accueil stagiaire
├── login_stagiaire.php         # Authentification stagiaire
├── register.php                # Inscription
├── quiz.php                    # Page evaluation
└── result.php                  # Resultats stagiaire


BASE DE DONNEES
===============
Tables principales :
  - admins             : comptes administrateurs
  - groupes            : groupes de stagiaires
  - stagiaires         : details des stagiaires
  - modules            : modules d'evaluation
  - questions          : questions du quiz
  - choix_reponses     : reponses possibles (QCM)
  - sessions_eval      : sessions d'evaluation en cours/terminées
  - reponses_stagiaires: reponses saisies par les stagiaires
  - config             : parametres (cle API, etc.)


SECURITE
========
✓ Authentification session + HTTPS recommandé
✓ Requêtes préparées PDO (protection SQL injection)
✓ Hash bcrypt pour les mots de passe
✓ Encodage UTF-8 pour les caractères spéciaux
✓ Validation des données côté serveur
✓ Protection contre XSS et CSRF (dans les formulaires)


PERFORMANCE
===========
Optimisations incluent :
  - Requêtes SQL optimisées avec index
  - Cache des sessions
  - Compression CSS/JS (future)
  - Images optimisees


DEPLOIEMENT
===========
Facile et automatisé :
  1. Clic droit sur deploy_windows.bat
  2. "Executer en tant qu'administrateur"
  3. Suivre les etapes (15 min max)

Pour details : voir DEPLOIEMENT.md


CONFIGURATION APRES DEPLOIEMENT
================================

1. CHANGER MOT DE PASSE ADMIN (OBLIGATOIRE)
   URL : http://localhost/eval-projet/admin/
   Login par defaut : admin / admin123
   Admin > Configuration > Changer mot de passe

2. AJOUTER CLÉ API ANTHROPIC (OPTIONNEL)
   Pour activer generation IA de questions :
   a. Creez un compte sur https://console.anthropic.com/
   b. Generez une cle API
   c. Entrez-la dans Admin > Configuration > Clé API Anthropic

3. CREER VOS DONNEES
   - Admin > Gestion Groupes : creer vos groupes
   - Admin > Gestion Modules : creer vos modules
   - Admin > Gestion Questions : ajouter les questions
   - Admin > Gestion Stagiaires : ajouter les stagiaires


ACCES
=====
Interface Stagiaire : http://localhost/eval-projet/
Interface Admin     : http://localhost/eval-projet/admin/
PhpMyAdmin          : http://localhost/phpmyadmin/
                     (Login: root, Password: vide)


DONNEES DE DEMO
===============
Le deploiement cree automatiquement :
  - 3 groupes : Groupe A, B, C (2025)
  - 1 module : "Module Demo"
  - 4 questions de test (QCM, V/F, texte libre)
  - 1 compte admin : admin / admin123

Ces donnees de demo peuvent etre supprimees via l'interface admin.


MAINTENANCE
===========

Restart Services :
  $ C:\xampp\xampp-control.exe
  Cliquez "Stop All" puis "Start All"

Sauvegarde Base de Donnees :
  $ mysqldump -u root eval_online > backup.sql

Restauration :
  $ mysql -u root eval_online < backup.sql

Consultation Logs :
  PHP     : C:\xampp\logs\php_error.log
  Apache  : C:\xampp\apache\logs\error.log
  MySQL   : C:\xampp\mysql\data\*.err


DEMARRAGE / ARRET
=================

Demarrage XAMPP:
  Double-clic : C:\xampp\xampp-control.exe
  Cliquez "Start All"

Arret XAMPP :
  XAMPP Control : Cliquez "Stop All"
  Ou par command : powershell : taskkill /F /IM httpd.exe
                                taskkill /F /IM mysqld.exe


LIMITATIONS ET NOTES
====================
- Single-server deployment (pas de load balancing)
- Pas de backup automatique (a configurer separement)
- Limite d'upload fichiers : 10 MB (configurable)
- Pas de mode offline (connexion internet requise pour IA)
- Pas de multi-langue (francais uniquement pour maintenant)


TROUBLESHOOTING RAPIDE
======================

Erreur "MySQL ne repond pas"
  → Lancez C:\xampp\xampp-control.exe > "Start MySQL"

Erreur 500 / page blanche
  → Verifiez C:\xampp\logs\php_error.log

Base vide apres deploiement
  → Relancez deploy_windows.bat
  → Verifiez MySQL est running

Mot de passe admin oublie
  → Via PhpMyAdmin, modifiez le hash dans table "admins"

Trop lent / temps de reponse long
  → Verifiez RAM/CPU disponible
  → Redemarrez XAMPP
  → Verifiez pas de queries longues en cours


SUPPORT ET CONTACT
==================

Developpeur : Mounir ELGHOLABZOURI
Email       : mounir.elgholabzouri@gmail.com
GitHub      : https://github.com/mounirelgholabzouri/eval-projet

Pour contacter avec probleme :
  - Decrivez le probleme precisement
  - Fournirez les logs (C:\xampp\logs\*)
  - Donnez votre version Windows (ver en cmd)
  - Donnez les etapes pour reproduire


EVOLUTION FUTURE
================
Fonctionnalites prevues :
  - Interface responsive mobile
  - Export PDF des evaluations
  - Statistiques avancees par groupe
  - Notifications email
  - Multi-langue
  - Import/export questions (CSV, Excel)
  - Mode offline
  - Load balancing / architecture distribuee


LICENCES TIERS
==============
- XAMPP      : Apache License 2.0
- PHP        : PHP License 3.01
- MySQL      : GPL 2.0
- Anthropic  : API Terms (https://www.anthropic.com/terms)


VERSION
=======
Version actuelle : 1.0.0
Date de publication : 2025-04-22
Derniere mise a jour : 2025-04-22


CHANGELOG
=========
v1.0.0 (2025-04-22)
  [+] Release initiale
  [+] Deploiement automatise XAMPP/Windows
  [+] Gestion modules et questions
  [+] Evaluation en ligne avec chrono
  [+] Export Excel
  [+] Integration API Anthropic pour generation IA


================================================================================
Merci d'utiliser Eval-Projet !
Pour plus d'infos : voir DEPLOIEMENT.md et DEPANNAGE.txt
================================================================================
