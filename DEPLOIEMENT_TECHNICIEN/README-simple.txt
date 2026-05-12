=====================================================
  MISE EN PLACE DU DEPOT GIT - GUIDE DEBUTANT A-Z
  Repo : https://github.com/mounirelgholabzouri/eval-projet
=====================================================


ETAPE 1 — Installer Git
------------------------
Ouvre PowerShell en administrateur (clic droit -> "Executer en tant qu'administrateur") et colle :

  Set-ExecutionPolicy Bypass -Scope Process -Force
  [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072
  iex ((New-Object System.Net.WebClient).DownloadString('https://chocolatey.org/install.ps1'))

Attendre la fin, puis :

  choco install git -y

Fermer et rouvrir PowerShell, puis verifier :

  git --version

Tu dois voir quelque chose comme : git version 2.x.x


ETAPE 2 — Configurer ton identite Git
---------------------------------------
  git config --global user.name "Ton Prenom Nom"
  git config --global user.email "ton.email@exemple.com"

Verifier que c'est bien enregistre :

  git config --list


ETAPE 3 — Choisir ou mettre le projet
---------------------------------------
Navigue vers le dossier souhaite, par exemple :

  cd C:\Users\TonNom\Desktop

Ou cree un dossier dedie :

  mkdir C:\Projets
  cd C:\Projets


ETAPE 4 — Cloner le depot
---------------------------
  git clone https://github.com/mounirelgholabzouri/eval-projet.git

Puis entre dans le dossier cree :

  cd eval-projet


ETAPE 5 — Verifier que tout est bien recupere
-----------------------------------------------
  git status

Resultat attendu :
  On branch main
  nothing to commit, working tree clean

Lister les fichiers :

  ls


ETAPE 6 — Recuperer les mises a jour (usage quotidien)
--------------------------------------------------------
Chaque jour avant de travailler, ou apres qu'un collegue a pousse des modifications :

  git pull origin main


=====================================================
  RECAPITULATIF
=====================================================

  1. Installer Git via Chocolatey
  2. git config --global user.name / user.email
  3. cd vers ton dossier de travail
  4. git clone https://github.com/mounirelgholabzouri/eval-projet.git
  5. cd eval-projet
  6. git status  <- verifier que tout est OK
  7. git pull origin main  <- chaque jour avant de bosser


=====================================================
  CONSEIL IMPORTANT
=====================================================
Si PowerShell te demande des identifiants GitHub :
- Login    : ton adresse email GitHub
- Password : un TOKEN d'acces personnel (pas ton mot de passe)

Pour generer un token :
  GitHub -> Settings -> Developer settings -> Personal access tokens -> Generate new token

Le mot de passe classique ne fonctionne plus sur GitHub depuis 2021.

=====================================================
