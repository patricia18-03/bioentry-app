-- ============================================================
-- Système de Gestion de Pointage (BioEntry) - Schéma de base
-- ============================================================

CREATE DATABASE IF NOT EXISTS bioentry_pointage
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE bioentry_pointage;

-- Table des employés
CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    matricule VARCHAR(20) NOT NULL UNIQUE,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) DEFAULT '',
    groupe VARCHAR(100) DEFAULT 'All Users',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Table des équipements (badgeuses)
CREATE TABLE IF NOT EXISTS equipements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipement_id VARCHAR(50) NOT NULL UNIQUE,
    nom VARCHAR(100) NOT NULL,
    porte VARCHAR(50) NOT NULL
) ENGINE=InnoDB;

-- Table des pointages (issus de l'import CSV BioEntry)
CREATE TABLE IF NOT EXISTS pointages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date_heure DATETIME NOT NULL,
    porte VARCHAR(50) NOT NULL,
    equipement_id VARCHAR(50),
    equipement_nom VARCHAR(100),
    groupe_utilisateur VARCHAR(100) DEFAULT 'All Users',
    utilisateur_matricule VARCHAR(20),
    utilisateur_nom VARCHAR(150) NOT NULL,
    evenement VARCHAR(100) DEFAULT "Succès de l'Authentification",
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (date_heure),
    INDEX idx_matricule (utilisateur_matricule),
    UNIQUE KEY uniq_pointage (date_heure, utilisateur_matricule, utilisateur_nom, equipement_nom, porte, evenement)
) ENGINE=InnoDB;

-- Table des anomalies détectées
CREATE TABLE IF NOT EXISTS anomalies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pointage_id INT,
    type VARCHAR(50) NOT NULL,
    description TEXT,
    resolved TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pointage_id) REFERENCES pointages(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Utilisateur admin de démonstration (mot de passe : admin123)
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    nom_affiche VARCHAR(100) DEFAULT 'Admin RH'
) ENGINE=InnoDB;

INSERT INTO admins (login, password_hash, nom_affiche) VALUES
('admin', '$2y$10$UjZ5DZ5fA7O2eG9Zc0v0Z.hFV2b8b5V6b7hOe0nD8j8fQvGZP0V0m', 'Admin RH')
ON DUPLICATE KEY UPDATE login = login;
-- NB: régénérez ce hash avec password_hash('admin123', PASSWORD_DEFAULT) avant mise en prod.

-- Quelques employés de démonstration (facultatif)
INSERT INTO employees (matricule, nom, prenom, groupe) VALUES
('509', 'ANDRIANORO', 'Sedera Josué', 'All Users'),
('517', 'RAZANAMAVO', 'Lydie Eldine', 'All Users'),
('2',   'RANDRIAMPARANY', 'Antsa Kerena', 'All Users'),
('518', 'ANDRIANANTOANDRO', 'Henintsoa F.', 'All Users'),
('515', 'MANDIMBISOA', 'N. Jeannie E.', 'All Users')
ON DUPLICATE KEY UPDATE nom = VALUES(nom);
