-- ------------------------------------------------------------
--  Table utilisateurs
-- ------------------------------------------------------------
CREATE TABLE utilisateurs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    nom           VARCHAR(100)  NOT NULL,
    matricule     VARCHAR(50)   NOT NULL UNIQUE,
    email         VARCHAR(150)  UNIQUE,
    date_ajout    DATE          NOT NULL,
    date_creation TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
);
 
-- ------------------------------------------------------------
--  Table pdf_fichiers
-- ------------------------------------------------------------
CREATE TABLE pdf_fichiers (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id INT          NOT NULL,
    nom_fichier    VARCHAR(255) NOT NULL,
    pdf_data       LONGBLOB     NOT NULL,
    date_upload    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);
 
-- ------------------------------------------------------------
--  Table historique_actions
-- ------------------------------------------------------------
CREATE TABLE historique_actions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id INT          NOT NULL,
    action         ENUM(
                       'CREATION_UTILISATEUR',
                       'MODIFICATION_UTILISATEUR',
                       'SUPPRESSION_UTILISATEUR',
                       'AJOUT_PDF',
                       'REMPLACEMENT_PDF',
                       'SUPPRESSION_PDF'
                   )            NOT NULL,
    nom_fichier    VARCHAR(255) DEFAULT NULL,
    details        TEXT         DEFAULT NULL,
    date_action    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);
 