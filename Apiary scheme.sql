-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)
--
-- Host: scalas.at    Database: Apiary
-- ------------------------------------------------------
-- Server version	8.0.44-0ubuntu0.22.04.2

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


--
-- Table structure for table `Hives`
--

DROP TABLE IF EXISTS `Hives`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `Hives` (
  `ID` int unsigned NOT NULL AUTO_INCREMENT,
  `Hive_nr` varchar(50) DEFAULT NULL,
  `inactive` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB AUTO_INCREMENT=182 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `Queens`
--

DROP TABLE IF EXISTS `Queens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `Queens` (
  `ID` int unsigned NOT NULL AUTO_INCREMENT,
  `gezeichnet` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `Lebensnummer` varchar(50) DEFAULT NULL,
  `Geburtsjahr` int NOT NULL,
  `Rasse` varchar(50) DEFAULT NULL,
  `Züchter` varchar(50) DEFAULT NULL,
  `LN_Mutter` varchar(50) DEFAULT NULL,
  `LN_Vatermutter` varchar(50) DEFAULT NULL,
  `Belegstelle` varchar(50) DEFAULT NULL,
  `gezeichnet2` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB AUTO_INCREMENT=275 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `Users`
--

DROP TABLE IF EXISTS `Users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `Users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `role` varchar(32) NOT NULL DEFAULT 'user',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `Visits`
--

DROP TABLE IF EXISTS `Visits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `Visits` (
  `ID` int unsigned NOT NULL AUTO_INCREMENT,
  `Hive_ID` int unsigned NOT NULL,
  `Queen_ID` int unsigned DEFAULT NULL,
  `Datum` date DEFAULT (curdate()),
  `Standort` varchar(50) DEFAULT NULL,
  `Aufbau` varchar(50) DEFAULT NULL,
  `Volksstärke` varchar(50) DEFAULT NULL,
  `Königin` varchar(50) DEFAULT NULL,
  `Brut_Stifte` varchar(50) DEFAULT NULL,
  `Brut_offen` varchar(50) DEFAULT NULL,
  `Brut_verdeckelt` varchar(50) DEFAULT NULL,
  `Sanftmut` varchar(50) DEFAULT NULL,
  `Wabensitz` varchar(50) DEFAULT NULL,
  `Schwarmneigung` varchar(50) DEFAULT NULL,
  `Honig` varchar(50) DEFAULT NULL,
  `Futter` varchar(50) DEFAULT NULL,
  `Bemerkungen` varchar(200) DEFAULT NULL,
  `ToDo` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `Visits_Queens_FK` (`Queen_ID`),
  KEY `Visits_Hives_FK` (`Hive_ID`),
  CONSTRAINT `Visits_Hives_FK` FOREIGN KEY (`Hive_ID`) REFERENCES `Hives` (`ID`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `Visits_Queens_FK` FOREIGN KEY (`Queen_ID`) REFERENCES `Queens` (`ID`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=3508 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-02-02 20:19:54
