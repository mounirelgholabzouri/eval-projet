# CONTEXT.md — Contexte du projet

## Présentation

**Outil d'Évaluation en Ligne** — plateforme web PHP/MySQL permettant à des formateurs de créer des QCM et à des stagiaires de les passer en ligne, avec correction automatique et tableau de bord admin.

## Utilisateurs

### Stagiaires
- S'inscrivent via `/register.php` (nom, prénom, groupe, année, login, mot de passe)
- Se connectent via `/login_stagiaire.php`
- Choisissent un module actif sur `/index.php` et passent le quiz
- Voient leurs résultats sur `/result.php`

### Formateurs (admin)
- Se connectent via `/admin/login.php` (identifiant : `admin` / mot de passe : `admin123`)
- Gèrent les modules (création, activation/désactivation)
- Gèrent les questions (ajout, modification, suppression)
- Consultent les résultats (liste, détail par session)
- Voient la liste des stagiaires avec leur compte et moyenne
- Gèrent les groupes
- Peuvent générer des QCM via l'IA Claude (API Anthropic)

## Flux d'évaluation

```
login_stagiaire.php
       ↓
  index.php  ← sélection du module actif
       ↓
   quiz.php  ← questions une par une avec timer
       ↓
  result.php ← score, pourcentage, mention
```

## Fonctionnalités implémentées

### Côté stagiaire
- [x] Inscription avec création de compte (login/mdp bcrypt)
- [x] Connexion sécurisée avec session PHP
- [x] Déconnexion
- [x] Sélection d'un module d'évaluation
- [x] Quiz avec timer par module
- [x] Soumission automatique à l'expiration du timer
- [x] Affichage des résultats avec mention (Excellent / Très bien / Bien / Passable / Insuffisant)

### Côté admin
- [x] Dashboard avec statistiques globales
- [x] Gestion des modules (CRUD + toggle actif/inactif via AJAX)
- [x] Gestion des questions (QCM, vrai/faux, texte libre, choix multiples)
- [x] Liste des résultats avec filtres (module, statut)
- [x] Détail d'une session (réponses par question)
- [x] Correction manuelle des questions texte libre
- [x] Liste des stagiaires avec groupe, année, login et moyenne
- [x] Gestion des groupes
- [x] Génération de QCM par IA (Claude API)

## Modules QCM disponibles (en base)

| Module | Questions | Durée |
|---|---|---|
| Réseaux TCP/IP - Fondamentaux | 5 | 45 min |
| Sécurité Informatique | 5 | 30 min |
| Excel - Tableaux et Formules | 5 | 40 min |
| VLAN | 5 | — |
| Hyperviseurs | 10 | — |
| Hyperviseurs Type 2 (VirtualBox/VMware) | 20 | — |

## Mentions de résultats

| Seuil | Mention | Badge |
|---|---|---|
| ≥ 90% | Excellent | vert |
| ≥ 75% | Très bien | vert |
| ≥ 60% | Bien | bleu |
| ≥ 50% | Passable | orange |
| < 50% | Insuffisant | rouge |

## Stack technique

- **Backend** : PHP 8.3 (PDO, sessions, bcrypt)
- **Base de données** : MySQL 8.4 (`eval_online`, utf8mb4)
- **Frontend** : Bootstrap 5.3.2 + Bootstrap Icons 1.11.3
- **Serveur** : Apache (Laragon) sur Windows
- **IA** : API Anthropic Claude (génération de QCM)

## État actuel (avril 2026)

- Système d'authentification stagiaire complet (inscription/connexion/déconnexion)
- Colonnes `login` et `password_hash` présentes dans la table `stagiaires`
- 0 comptes stagiaires créés (les stagiaires existants n'ont pas encore de login)
- Les stagiaires existants peuvent s'inscrire avec leurs vrais nom/prénom/groupe → le système retrouve leur profil et y attache le login

## Améliorations possibles

- [ ] Page profil stagiaire (historique de ses évaluations)
- [ ] Export CSV des résultats
- [ ] Réinitialisation du mot de passe
- [ ] Import de stagiaires en masse (CSV)
- [ ] Statistiques par groupe / par module
- [ ] Randomisation de l'ordre des questions
- [ ] Question avec image
- [ ] Email de confirmation à l'inscription
