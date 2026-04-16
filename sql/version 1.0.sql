-- Création de la base de données
CREATE DATABASE IF NOT EXISTS gestion_business CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gestion_business;

-- 1. Table Utilisateurs
CREATE TABLE utilisateurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    rank VARCHAR(50)
) ENGINE=InnoDB;

-- 2. Table Fournisseurs
CREATE TABLE fournisseurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    adresse TEXT,
    telephone VARCHAR(20)
) ENGINE=InnoDB;

-- 3. Table Clients
CREATE TABLE clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    adresse TEXT,
    telephone VARCHAR(20)
) ENGINE=InnoDB;

-- 4. Table Produits
CREATE TABLE produits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    qte DECIMAL(10, 3) DEFAULT 0.000,
    prix_achat DECIMAL(10, 2),
    prix_vente DECIMAL(10, 2)
) ENGINE=InnoDB;

-- 5. Table Achats
CREATE TABLE achats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    num VARCHAR(50),
    date DATETIME DEFAULT CURRENT_TIMESTAMP,
    id_fournisseur INT,
    versement DECIMAL(10, 2),
    FOREIGN KEY (id_fournisseur) REFERENCES fournisseurs(id)
) ENGINE=InnoDB;

-- 6. Table Achat_details
CREATE TABLE achat_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_achat INT,
    id_produit INT,
    prix_achat DECIMAL(10, 2),
    qte DECIMAL(10, 3),
    FOREIGN KEY (id_achat) REFERENCES achats(id) ON DELETE CASCADE,
    FOREIGN KEY (id_produit) REFERENCES produits(id)
) ENGINE=InnoDB;

-- 7. Table Ventes
CREATE TABLE ventes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    num VARCHAR(50),
    date DATETIME DEFAULT CURRENT_TIMESTAMP,
    id_clients INT,
    versement DECIMAL(10, 2),
    FOREIGN KEY (id_clients) REFERENCES clients(id)
) ENGINE=InnoDB;

-- 8. Table Vente_details
CREATE TABLE vente_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_vente INT,
    id_produit INT,
    prix_vente DECIMAL(10, 2),
    qte DECIMAL(10, 3),
    FOREIGN KEY (id_vente) REFERENCES ventes(id) ON DELETE CASCADE,
    FOREIGN KEY (id_produit) REFERENCES produits(id)
) ENGINE=InnoDB;