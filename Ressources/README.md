# Projet Jumão BDE — ISEN Brest

Application de gestion de trésorerie, de clubs et de remboursements pour le Bureau Des Élèves (BDE) de l'ISEN Brest.

## Fonctionnalités

- Suivi des transactions (dépenses/recettes) par club et par exercice budgétaire
- Gestion des budgets et des versements par tranche, avec alertes de dépassement
- Workflow de demande et de validation des remboursements
- Tableau de bord avec statistiques et graphiques
- Gestion des clubs, des utilisateurs et des rôles (trésorier / responsable / admin)

## Prérequis

- PHP 8.1+
- MySQL 8.0+
- (fournis directement par le serveur mutualisé de l'ISEN — rien à installer côté hébergement)

## Installation

> Projet en cours de mise en place : seule l'arborescence est prête, aucune logique PHP n'est encore écrite.

```bash
cp .env.example .env
# renseigner DB_HOST, DB_NAME, DB_USER, DB_PASS dans .env
mysql -u <user> -p < sql/schema.sql
```

## Utilisation

```bash
php -S localhost:8000 -t public
```

> À venir une fois les contrôleurs et vues écrits.

## Configuration

| Variable | Rôle | Défaut |
|---|---|---|
| `DB_HOST` | Adresse du serveur MySQL | `localhost` |
| `DB_NAME` | Nom de la base de données | `jumao` |
| `DB_USER` | Utilisateur MySQL | — |
| `DB_PASS` | Mot de passe MySQL | — |

## Structure du projet

```
BDE/
├── public/           Seul dossier exposé au web (index.php, css/, js/, uploads/)
├── app/
│   ├── config/        Connexion PDO à MySQL
│   ├── models/         Modèles (données)
│   ├── controllers/     Contrôleurs (logique)
│   └── views/            Vues PHP (affichage)
├── sql/               Schéma de la base MySQL
├── Maquettes/          Pages HTML de référence visuelle
└── Ressources/          Plan et cahier des charges (plan.txt, Plan_Projet_Jumao.xlsx)
```

## Technologies

PHP 8 (MVC léger, sans framework) + MySQL + PDO, hébergé sur le serveur de l'ISEN. Maquettes en HTML/CSS/JS/Chart.js.

## Rôles utilisateurs

| Rôle | Description | Accès |
|---|---|---|
| `tresorier` | Trésorier BDE | Accès complet : tous les clubs, budgets, transactions, validation des remboursements, dashboard global |
| `responsable` | Responsable de club | Accès limité à son propre club (transactions, remboursements, budget de son club) |
| `admin` | Administration | Gestion des comptes utilisateurs et attribution des rôles |

## Documents de référence

- `Plan_Projet_Jumao.xlsx` : analyse du besoin, solution retenue, exigences, fonctions, stack technique, architecture, schéma de base de données, WBS, roadmap, Gantt, plan financier, risques
