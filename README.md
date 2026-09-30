# Système de Gestion de Pointage (BioEntry)

Application PHP + MySQL reproduisant le tableau de bord de suivi des pointages
(import CSV, détection d'anomalies, historique, employés, rapports & export).

## Installation

1. **Base de données**
   - Créez la base en important `database/schema.sql` :
     ```
     mysql -u root -p < database/schema.sql
     ```
   - Le script crée la base `bioentry_pointage`, les tables, un compte admin
     de démonstration et quelques employés.

2. **Connexion à la base**
   - Modifiez `config/db.php` si besoin (hôte, utilisateur, mot de passe).

3. **Compte administrateur**
   - Identifiant : `admin`
   - Le hash fourni dans `schema.sql` est un exemple : générez le vôtre avant
     la mise en production avec :
     ```php
     echo password_hash('votre_mot_de_passe', PASSWORD_DEFAULT);
     ```
     puis mettez à jour la colonne `password_hash` de la table `admins`.

4. **Serveur local (test rapide)**
   ```
   php -S localhost:8000
   ```
   puis ouvrez http://localhost:8000

## Structure du projet

```
bioentry-app/
├── config/db.php          # Connexion PDO à MySQL
├── database/schema.sql    # Schéma + données de démo
├── includes/              # header, sidebar, footer, auth, fonctions
├── assets/css/style.css   # Habillage (bleu marine + bandeau vert/rouge)
├── uploads/                # Fichiers CSV importés (généré à l'usage)
├── login.php / logout.php
├── dashboard.php           # Tableau de bord + historique des pointages
├── import.php               # Import de fichiers CSV BioEntry
├── employees.php            # Gestion des employés
├── reports.php               # Filtres + export CSV
└── index.php                  # Redirection vers dashboard.php
```

## Format du CSV attendu

En-tête (virgule ou point-virgule) :
```
Date;Porte;ID Equipement;Equipement;Groupe Utilisateur;Utilisateur;Evenement
```
Le champ `Utilisateur` accepte le format `509(ANDRIANORO Sedera Josué)`.
Un exemple est fourni dans `sample_import.csv`.

## Détection d'anomalies

Recalculée automatiquement après chaque import (`includes/functions.php`) :
- **Doublons** : même utilisateur / même porte pointés à moins de 60 secondes d'intervalle.
- **Sortie sans entrée** : un passage "PORTE SORTIE" sans "PORTE ENTREE" préalable le même jour.

Tant qu'aucune anomalie n'est ouverte, le bandeau du tableau de bord reste vert
et l'export est marqué "Prêt pour export".
