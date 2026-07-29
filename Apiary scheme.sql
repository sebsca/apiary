-- Apiary Logbook schema for a fresh MySQL 8 database.
-- This script drops existing Apiary tables and therefore must not be used as a migration.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS Visits;
DROP TABLE IF EXISTS Queens;
DROP TABLE IF EXISTS Hives;
DROP TABLE IF EXISTS Users;

CREATE TABLE Hives (
  ID int unsigned NOT NULL AUTO_INCREMENT,
  Hive_nr varchar(50) DEFAULT NULL,
  inactive tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE Queens (
  ID int unsigned NOT NULL AUTO_INCREMENT,
  gezeichnet varchar(50) DEFAULT NULL,
  Lebensnummer varchar(50) DEFAULT NULL,
  Geburtsjahr int NOT NULL,
  Rasse varchar(50) DEFAULT NULL,
  `Züchter` varchar(50) DEFAULT NULL,
  LN_Mutter varchar(50) DEFAULT NULL,
  LN_Vatermutter varchar(50) DEFAULT NULL,
  Belegstelle varchar(50) DEFAULT NULL,
  PRIMARY KEY (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE Users (
  id int NOT NULL AUTO_INCREMENT,
  username varchar(64) NOT NULL,
  password_hash varchar(255) DEFAULT NULL,
  role varchar(32) NOT NULL DEFAULT 'contributor',
  created_at timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  last_login timestamp NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY users_username_unique (username),
  CONSTRAINT users_role_check CHECK (role IN ('admin', 'contributor', 'readonly'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE Visits (
  ID int unsigned NOT NULL AUTO_INCREMENT,
  Hive_ID int unsigned NOT NULL,
  Queen_ID int unsigned DEFAULT NULL,
  Datum date NOT NULL DEFAULT (curdate()),
  Standort varchar(50) DEFAULT NULL,
  Aufbau varchar(50) DEFAULT NULL,
  `Volksstärke` varchar(50) DEFAULT NULL,
  `Königin` varchar(50) DEFAULT NULL,
  Brut_Stifte varchar(50) DEFAULT NULL,
  Brut_offen varchar(50) DEFAULT NULL,
  Brut_verdeckelt varchar(50) DEFAULT NULL,
  Sanftmut varchar(50) DEFAULT NULL,
  Wabensitz varchar(50) DEFAULT NULL,
  Schwarmneigung varchar(50) DEFAULT NULL,
  Honig varchar(50) DEFAULT NULL,
  Futter varchar(50) DEFAULT NULL,
  Bemerkungen varchar(200) DEFAULT NULL,
  ToDo varchar(200) DEFAULT NULL,
  PRIMARY KEY (ID),
  KEY visits_hive_timeline (Hive_ID, Datum, ID),
  KEY visits_queen (Queen_ID),
  CONSTRAINT visits_hive_fk
    FOREIGN KEY (Hive_ID) REFERENCES Hives (ID)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT visits_queen_fk
    FOREIGN KEY (Queen_ID) REFERENCES Queens (ID)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET FOREIGN_KEY_CHECKS = 1;
