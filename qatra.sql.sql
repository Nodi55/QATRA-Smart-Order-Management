-- MySQL dump 10.13  Distrib 8.4.2-2, for Linux (x86_64)
--
-- Host: localhost    Database: bsfbmb13afxzn35nolyb
-- ------------------------------------------------------
-- Server version	8.4.2-2

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!50717 SELECT COUNT(*) INTO @rocksdb_has_p_s_session_variables FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'performance_schema' AND TABLE_NAME = 'session_variables' */;
/*!50717 SET @rocksdb_get_is_supported = IF (@rocksdb_has_p_s_session_variables, 'SELECT COUNT(*) INTO @rocksdb_is_supported FROM performance_schema.session_variables WHERE VARIABLE_NAME=\'rocksdb_bulk_load\'', 'SELECT 0') */;
/*!50717 PREPARE s FROM @rocksdb_get_is_supported */;
/*!50717 EXECUTE s */;
/*!50717 DEALLOCATE PREPARE s */;
/*!50717 SET @rocksdb_enable_bulk_load = IF (@rocksdb_is_supported, 'SET SESSION rocksdb_bulk_load = 1', 'SET @rocksdb_dummy_bulk_load = 0') */;
/*!50717 PREPARE s FROM @rocksdb_enable_bulk_load */;
/*!50717 EXECUTE s */;
/*!50717 DEALLOCATE PREPARE s */;

--
-- Table structure for table `activated_service`
--

DROP TABLE IF EXISTS `activated_service`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activated_service` (
  `act_id` int NOT NULL AUTO_INCREMENT,
  `acc_id` int DEFAULT NULL,
  `srv_id` int DEFAULT NULL,
  PRIMARY KEY (`act_id`),
  KEY `acc_id` (`acc_id`),
  KEY `srv_id` (`srv_id`),
  CONSTRAINT `activated_service_ibfk_1` FOREIGN KEY (`acc_id`) REFERENCES `unified_account` (`acc_id`),
  CONSTRAINT `activated_service_ibfk_2` FOREIGN KEY (`srv_id`) REFERENCES `service_type` (`srv_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activated_service`
--

LOCK TABLES `activated_service` WRITE;
/*!40000 ALTER TABLE `activated_service` DISABLE KEYS */;
/*!40000 ALTER TABLE `activated_service` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `application`
--

DROP TABLE IF EXISTS `application`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `application` (
  `app_id` int NOT NULL AUTO_INCREMENT,
  `cty_id` int NOT NULL,
  `latitude` float DEFAULT NULL,
  `longitude` float DEFAULT NULL,
  `deed_no` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `deed_file_url` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `app_status` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `cust_id` int NOT NULL,
  `srv_id` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`app_id`),
  KEY `cust_id` (`cust_id`),
  KEY `deed_no` (`deed_no`),
  KEY `cty_id` (`cty_id`),
  KEY `srv_id` (`srv_id`),
  CONSTRAINT `application_ibfk_1` FOREIGN KEY (`cust_id`) REFERENCES `customer` (`cust_id`),
  CONSTRAINT `application_ibfk_2` FOREIGN KEY (`deed_no`) REFERENCES `moj_record` (`deed_no`),
  CONSTRAINT `application_ibfk_3` FOREIGN KEY (`cty_id`) REFERENCES `city` (`cty_id`),
  CONSTRAINT `application_ibfk_4` FOREIGN KEY (`srv_id`) REFERENCES `service_type` (`srv_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `application`
--

LOCK TABLES `application` WRITE;
/*!40000 ALTER TABLE `application` DISABLE KEYS */;
INSERT INTO `application` VALUES (4,1,26.3751,43.9464,'711029485736','uploads/1b075a5fdba1bdf08ee058447efee09e.pdf','Pending_Review',3,1,'2026-08-02 06:57:16'),(5,3,24.7078,46.6844,'711099887766','uploads/4d4c79deef4c91e4b636cee381e957a6.png','Pending_Review',10,2,'2026-08-02 07:08:40'),(8,4,26.375,43.9465,'711029485736','uploads/1d5213ea7aa78ee9ca8cfadafbeb8da2.pdf','Pending_Review',3,1,'2026-08-02 08:56:24'),(9,1,26.375,43.9465,'711029485736','uploads/e735860ef25e63b2ab549fc61068a602.pdf','Pending_Review',3,1,'2026-08-02 08:57:24');
/*!40000 ALTER TABLE `application` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `application_history`
--

DROP TABLE IF EXISTS `application_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `application_history` (
  `hist_id` int NOT NULL AUTO_INCREMENT,
  `app_id` int DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `rejection_reason` text COLLATE utf8mb4_general_ci,
  `changed_by` int DEFAULT NULL,
  `change_date` datetime NOT NULL,
  PRIMARY KEY (`hist_id`),
  KEY `app_id` (`app_id`),
  KEY `changed_by` (`changed_by`),
  CONSTRAINT `application_history_ibfk_1` FOREIGN KEY (`app_id`) REFERENCES `application` (`app_id`),
  CONSTRAINT `application_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `company_employee` (`emp_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `application_history`
--

LOCK TABLES `application_history` WRITE;
/*!40000 ALTER TABLE `application_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `application_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `city`
--

DROP TABLE IF EXISTS `city`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `city` (
  `cty_id` int NOT NULL AUTO_INCREMENT,
  `cty_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `reg_id` int DEFAULT NULL,
  PRIMARY KEY (`cty_id`),
  UNIQUE KEY `cty_name` (`cty_name`,`reg_id`),
  KEY `reg_id` (`reg_id`),
  CONSTRAINT `city_ibfk_1` FOREIGN KEY (`reg_id`) REFERENCES `region` (`reg_id`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `city`
--

LOCK TABLES `city` WRITE;
/*!40000 ALTER TABLE `city` DISABLE KEYS */;
INSERT INTO `city` VALUES (5,'البكيرية',1),(18,'الحائط',2),(3,'الرس',1),(12,'الشماسية',1),(15,'الشنان',2),(23,'القريات',4),(4,'المذنب',1),(1,'بريدة',1),(14,'بقعاء',2),(13,'حائل',2),(24,'دومة الجندل',4),(20,'رفحاء',3),(22,'سكاكا',4),(10,'ضرية',1),(25,'طبرجل',4),(21,'طريف',3),(19,'عرعر',3),(2,'عنيزة',1),(8,'عيون الجواء',1),(26,'مدينة الرياض',1);
/*!40000 ALTER TABLE `city` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `company_employee`
--

DROP TABLE IF EXISTS `company_employee`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_employee` (
  `emp_id` int NOT NULL AUTO_INCREMENT,
  `emp_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `emp_email` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `cty_id` int DEFAULT NULL,
  `active_tasks_count` int DEFAULT '0',
  PRIMARY KEY (`emp_id`),
  UNIQUE KEY `emp_email` (`emp_email`),
  KEY `cty_id` (`cty_id`),
  CONSTRAINT `company_employee_ibfk_1` FOREIGN KEY (`cty_id`) REFERENCES `city` (`cty_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company_employee`
--

LOCK TABLES `company_employee` WRITE;
/*!40000 ALTER TABLE `company_employee` DISABLE KEYS */;
INSERT INTO `company_employee` VALUES (1,'عبدالرحمن النفيسي','admin@qatra.com','$2y$10$si0KQT0U1wfaZIYbdk3Pyuh2UM40ShPtw8HP.1bjfGjBUrjB0m1im',1,0),(2,'محمد ال علي','moh12@qatra.com','$2y$10$Z7HcUy0JU9trMsC467EOf.hNO7Lv1zv/Vj9TvIqPntIyjEa/ZtYjK',1,0);
/*!40000 ALTER TABLE `company_employee` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer`
--

DROP TABLE IF EXISTS `customer`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer` (
  `cust_id` int NOT NULL AUTO_INCREMENT,
  `national_id` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `full_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `phone_number` varchar(15) COLLATE utf8mb4_general_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `cty_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`cust_id`),
  UNIQUE KEY `national_id` (`national_id`),
  KEY `cty_id` (`cty_id`),
  CONSTRAINT `customer_ibfk_1` FOREIGN KEY (`cty_id`) REFERENCES `city` (`cty_id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer`
--

LOCK TABLES `customer` WRITE;
/*!40000 ALTER TABLE `customer` DISABLE KEYS */;
INSERT INTO `customer` VALUES (3,'1124230879','عروب التويجري','0533239918','$2y$10$xO5qRwrif5/bTW7aSe7zKuNS8/61GdmK5SmKnaR94tQIu874.5aOe',1,'2026-07-30 09:32:37'),(5,'1127174801','العنود البطي','0533239919','$2y$10$pIvsc3pT.Cr/ooX00N41QuxVgp6IDDMgCOKYQp/OkirJ.q/yYia8W',1,'2026-07-30 07:36:04'),(7,'1127174888','العنود عبدالله','0532338809','$2y$10$tRUtAer7ZKJnnklkOnxTQOPUACOZvpyR8EzgTJHLrUqxQL4DReJzS',1,'2026-07-30 07:38:15'),(8,'1125278901','ذكرى اليحياء','0502146582','$2y$10$ZpjPgjvEiZyWtJW0/8m5Tuwx/XIpN9g7z0fDJQSdsGj8j/yXgfowS',12,'2026-07-30 07:38:41'),(10,'1073849502','سارة بنت عبدالرحمن القحطاني','0553715039','$2y$10$B4Rz4MsrZqyoDQcdvkkKUOJ/fHKuDXgIy6.As99iCLmlxliTTGSP2',13,'2026-08-02 06:30:10'),(12,'1125278911','ذكرى اليحياء','0502146582','$2y$10$zHkgx6oddNWzQFFFI7jML.fNwQobRCHCzdf633vlRe/d12y9e1Cb2',12,'2026-08-02 07:26:53'),(13,'1125278912','نلصر اليحياء','0502146582','$2y$10$6A566syca5mlmPi.2BSMc.nkjC0oe/aPLpv3TCfDHgrEvciCbwY36',12,'2026-08-02 07:28:21'),(14,'1125278917','محمد القحطاني','0502146580','$2y$10$BDKdhu1/bbKRN9gWiXNXO.W7zIeL6BJAo1QR.6vtDH9kLKcvc55ci',5,'2026-08-02 07:51:00'),(16,'1125278900','ياسر القحطاني','0502146500','$2y$10$ObYqlz9Ty2qodhPv/vthru9ZNAISQxFc1nc3KgiCu6X.cINGunLwa',18,'2026-08-02 08:00:31'),(17,'1124230888','عبدالعزيز محمد','0533224455','$2y$10$v3lzGMTpcofMX/o/d1RimOKPJAifsEVV/9anESEU96aziprSyBRXO',2,'2026-08-02 08:30:20');
/*!40000 ALTER TABLE `customer` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_roles`
--

DROP TABLE IF EXISTS `employee_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_roles` (
  `emp_id` int NOT NULL,
  `role_id` int NOT NULL,
  `assigned_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`emp_id`,`role_id`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `employee_roles_ibfk_1` FOREIGN KEY (`emp_id`) REFERENCES `company_employee` (`emp_id`) ON DELETE CASCADE,
  CONSTRAINT `employee_roles_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `system_role` (`role_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_roles`
--

LOCK TABLES `employee_roles` WRITE;
/*!40000 ALTER TABLE `employee_roles` DISABLE KEYS */;
INSERT INTO `employee_roles` VALUES (1,1,'2026-08-02 08:36:10'),(2,2,'2026-08-02 09:07:20');
/*!40000 ALTER TABLE `employee_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `field_inspection`
--

DROP TABLE IF EXISTS `field_inspection`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_inspection` (
  `insp_id` int NOT NULL AUTO_INCREMENT,
  `building_readiness` tinyint(1) DEFAULT NULL,
  `doors_windows_installed` tinyint(1) DEFAULT NULL,
  `meter_spot_painted` tinyint(1) DEFAULT NULL,
  `site_photos_url` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `inspection_result` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `app_id` int DEFAULT NULL,
  `emp_id` int DEFAULT NULL,
  PRIMARY KEY (`insp_id`),
  KEY `app_id` (`app_id`),
  KEY `emp_id` (`emp_id`),
  CONSTRAINT `field_inspection_ibfk_1` FOREIGN KEY (`app_id`) REFERENCES `application` (`app_id`),
  CONSTRAINT `field_inspection_ibfk_2` FOREIGN KEY (`emp_id`) REFERENCES `company_employee` (`emp_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `field_inspection`
--

LOCK TABLES `field_inspection` WRITE;
/*!40000 ALTER TABLE `field_inspection` DISABLE KEYS */;
/*!40000 ALTER TABLE `field_inspection` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `installation_task`
--

DROP TABLE IF EXISTS `installation_task`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `installation_task` (
  `task_id` int NOT NULL AUTO_INCREMENT,
  `pipe_length` float DEFAULT NULL,
  `pipe_diameter` float DEFAULT NULL,
  `initial_reading` float DEFAULT NULL,
  `app_id` int DEFAULT NULL,
  `emp_id` int DEFAULT NULL,
  PRIMARY KEY (`task_id`),
  UNIQUE KEY `app_id` (`app_id`),
  KEY `emp_id` (`emp_id`),
  CONSTRAINT `installation_task_ibfk_1` FOREIGN KEY (`app_id`) REFERENCES `application` (`app_id`),
  CONSTRAINT `installation_task_ibfk_2` FOREIGN KEY (`emp_id`) REFERENCES `company_employee` (`emp_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `installation_task`
--

LOCK TABLES `installation_task` WRITE;
/*!40000 ALTER TABLE `installation_task` DISABLE KEYS */;
/*!40000 ALTER TABLE `installation_task` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoice`
--

DROP TABLE IF EXISTS `invoice`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice` (
  `inv_id` int NOT NULL AUTO_INCREMENT,
  `amount` float NOT NULL,
  `payment_status` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `app_id` int DEFAULT NULL,
  PRIMARY KEY (`inv_id`),
  UNIQUE KEY `app_id` (`app_id`),
  CONSTRAINT `invoice_ibfk_1` FOREIGN KEY (`app_id`) REFERENCES `application` (`app_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoice`
--

LOCK TABLES `invoice` WRITE;
/*!40000 ALTER TABLE `invoice` DISABLE KEYS */;
/*!40000 ALTER TABLE `invoice` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `meter`
--

DROP TABLE IF EXISTS `meter`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meter` (
  `mtr_id` int NOT NULL AUTO_INCREMENT,
  `mtr_serial` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `mtr_type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `acc_id` int DEFAULT NULL,
  `task_id` int DEFAULT NULL,
  PRIMARY KEY (`mtr_id`),
  UNIQUE KEY `mtr_serial` (`mtr_serial`),
  UNIQUE KEY `task_id` (`task_id`),
  KEY `acc_id` (`acc_id`),
  CONSTRAINT `meter_ibfk_1` FOREIGN KEY (`acc_id`) REFERENCES `unified_account` (`acc_id`),
  CONSTRAINT `meter_ibfk_2` FOREIGN KEY (`task_id`) REFERENCES `installation_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `meter`
--

LOCK TABLES `meter` WRITE;
/*!40000 ALTER TABLE `meter` DISABLE KEYS */;
/*!40000 ALTER TABLE `meter` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `moj_record`
--

DROP TABLE IF EXISTS `moj_record`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `moj_record` (
  `moj_id` int NOT NULL AUTO_INCREMENT,
  `deed_no` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `owner_national_id` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `owner_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `land_area` float NOT NULL,
  PRIMARY KEY (`moj_id`),
  UNIQUE KEY `deed_no` (`deed_no`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `moj_record`
--

LOCK TABLES `moj_record` WRITE;
/*!40000 ALTER TABLE `moj_record` DISABLE KEYS */;
INSERT INTO `moj_record` VALUES (1,'711029485736','1045938475','فهد بن محمد العتيبي',450),(2,'312049586744','1073849502','سارة بنت عبدالرحمن القحطاني',850),(3,'711099887766','1073849502','سارة بنت عبدالرحمن القحطاني',300),(4,'411039485762','1094857362','خالد بن عبدالله المطيري',675),(5,'711029384000','1029384756','نورة بنت سعد الشمري',1200),(6,'311029384756','1102938475','عبدالله بن صالح التميمي',550),(7,'712039485711','1059483726','ريم بنت فهد الدوسري',400),(8,'711039485722','1039485721','بندر بن عبدالعزيز السبيعي',950),(9,'311039485733','1039485721','بندر بن عبدالعزيز السبيعي',600),(10,'412039485799','1083948576','أمل بنت طارق الزهراني',710);
/*!40000 ALTER TABLE `moj_record` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification`
--

DROP TABLE IF EXISTS `notification`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification` (
  `notif_id` int NOT NULL AUTO_INCREMENT,
  `message_content` text COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `cust_id` int DEFAULT NULL,
  PRIMARY KEY (`notif_id`),
  KEY `cust_id` (`cust_id`),
  CONSTRAINT `notification_ibfk_1` FOREIGN KEY (`cust_id`) REFERENCES `customer` (`cust_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification`
--

LOCK TABLES `notification` WRITE;
/*!40000 ALTER TABLE `notification` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `otp_code`
--

DROP TABLE IF EXISTS `otp_code`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `otp_code` (
  `otp_id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(6) COLLATE utf8mb4_general_ci NOT NULL,
  `expiry_time` datetime NOT NULL,
  `is_used` tinyint(1) DEFAULT '0',
  `cust_id` int DEFAULT NULL,
  PRIMARY KEY (`otp_id`),
  KEY `cust_id` (`cust_id`),
  CONSTRAINT `otp_code_ibfk_1` FOREIGN KEY (`cust_id`) REFERENCES `customer` (`cust_id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `otp_code`
--

LOCK TABLES `otp_code` WRITE;
/*!40000 ALTER TABLE `otp_code` DISABLE KEYS */;
INSERT INTO `otp_code` VALUES (1,'879909','2026-08-02 07:38:38',1,13),(2,'446831','2026-08-02 07:38:37',1,13),(3,'942413','2026-08-02 07:41:43',1,13),(4,'169822','2026-08-02 07:43:31',1,3),(5,'116410','2026-08-02 07:43:53',0,3),(6,'920465','2026-08-02 07:53:07',1,13),(7,'847764','2026-08-02 07:53:50',0,14),(8,'305694','2026-08-02 07:54:03',1,14),(9,'758424','2026-08-02 08:02:45',1,16),(10,'556412','2026-08-02 08:14:24',0,3),(11,'147041','2026-08-02 08:20:28',1,3),(12,'414926','2026-08-02 08:21:55',0,3),(13,'950720','2026-08-02 08:32:26',0,3),(14,'201840','2026-08-02 08:35:27',1,3),(15,'493241','2026-08-02 08:36:16',0,3),(16,'950483','2026-08-02 08:36:29',0,3),(17,'733210','2026-08-02 08:37:12',0,3),(18,'493691','2026-08-02 08:38:30',1,3),(19,'191681','2026-08-02 08:39:59',1,3),(20,'888689','2026-08-02 08:44:28',1,3),(21,'265136','2026-08-02 08:46:12',1,3),(22,'700080','2026-08-02 08:50:09',0,3),(23,'249762','2026-08-02 08:51:05',1,3),(24,'627621','2026-08-02 08:53:03',1,3),(25,'951066','2026-08-02 08:57:49',1,3),(26,'510022','2026-08-02 09:01:01',1,3);
/*!40000 ALTER TABLE `otp_code` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `region`
--

DROP TABLE IF EXISTS `region`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `region` (
  `reg_id` int NOT NULL AUTO_INCREMENT,
  `reg_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`reg_id`),
  UNIQUE KEY `reg_name` (`reg_name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `region`
--

LOCK TABLES `region` WRITE;
/*!40000 ALTER TABLE `region` DISABLE KEYS */;
INSERT INTO `region` VALUES (4,'منطقة الجوف'),(3,'منطقة الحدود الشمالية'),(5,'منطقة الرياض'),(1,'منطقة القصيم'),(2,'منطقة حائل');
/*!40000 ALTER TABLE `region` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_type`
--

DROP TABLE IF EXISTS `service_type`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_type` (
  `srv_id` int NOT NULL AUTO_INCREMENT,
  `srv_name` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`srv_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_type`
--

LOCK TABLES `service_type` WRITE;
/*!40000 ALTER TABLE `service_type` DISABLE KEYS */;
INSERT INTO `service_type` VALUES (1,'شبكة مياه'),(2,'صرف صحي'),(3,'مياه وصرف صحي');
/*!40000 ALTER TABLE `service_type` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_role`
--

DROP TABLE IF EXISTS `system_role`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_role` (
  `role_id` int NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`role_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_role`
--

LOCK TABLES `system_role` WRITE;
/*!40000 ALTER TABLE `system_role` DISABLE KEYS */;
INSERT INTO `system_role` VALUES (1,'Admin'),(2,'Auditor'),(3,'Technician'),(4,'Admin'),(5,'Auditor'),(6,'Technician');
/*!40000 ALTER TABLE `system_role` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `unified_account`
--

DROP TABLE IF EXISTS `unified_account`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `unified_account` (
  `acc_id` int NOT NULL AUTO_INCREMENT,
  `creation_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `cust_id` int DEFAULT NULL,
  `deed_no` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`acc_id`),
  UNIQUE KEY `deed_no` (`deed_no`),
  KEY `cust_id` (`cust_id`),
  CONSTRAINT `unified_account_ibfk_1` FOREIGN KEY (`cust_id`) REFERENCES `customer` (`cust_id`),
  CONSTRAINT `unified_account_ibfk_2` FOREIGN KEY (`deed_no`) REFERENCES `moj_record` (`deed_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `unified_account`
--

LOCK TABLES `unified_account` WRITE;
/*!40000 ALTER TABLE `unified_account` DISABLE KEYS */;
/*!40000 ALTER TABLE `unified_account` ENABLE KEYS */;
UNLOCK TABLES;
/*!50112 SET @disable_bulk_load = IF (@is_rocksdb_supported, 'SET SESSION rocksdb_bulk_load = @old_rocksdb_bulk_load', 'SET @dummy_rocksdb_bulk_load = 0') */;
/*!50112 PREPARE s FROM @disable_bulk_load */;
/*!50112 EXECUTE s */;
/*!50112 DEALLOCATE PREPARE s */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-02  9:12:13
