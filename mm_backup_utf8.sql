-- MySQL dump 10.13  Distrib 8.4.3, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: mm
-- ------------------------------------------------------
-- Server version	8.4.3

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

--
-- Table structure for table `activity_log`
--

DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `log_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `causer_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `causer_id` bigint unsigned DEFAULT NULL,
  `properties` json DEFAULT NULL,
  `batch_uuid` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subject` (`subject_type`,`subject_id`),
  KEY `causer` (`causer_type`,`causer_id`),
  KEY `activity_log_log_name_index` (`log_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_log`
--

LOCK TABLES `activity_log` WRITE;
/*!40000 ALTER TABLE `activity_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `activity_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
INSERT INTO `cache` VALUES ('laravel_cache_livewire-rate-limiter:16d36dff9abd246c67dfac3e63b993a169af77e6','i:1;',1788196517),('laravel_cache_livewire-rate-limiter:16d36dff9abd246c67dfac3e63b993a169af77e6:timer','i:1788196517;',1788196517),('laravel_cache_spatie.permission.cache','a:3:{s:5:\"alias\";a:4:{s:1:\"a\";s:2:\"id\";s:1:\"b\";s:4:\"name\";s:1:\"c\";s:10:\"guard_name\";s:1:\"r\";s:5:\"roles\";}s:11:\"permissions\";a:48:{i:0;a:4:{s:1:\"a\";i:1;s:1:\"b\";s:10:\"trash.view\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:2;}}i:1;a:4:{s:1:\"a\";i:2;s:1:\"b\";s:13:\"trash.restore\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:2;}}i:2;a:4:{s:1:\"a\";i:3;s:1:\"b\";s:18:\"trash.force_delete\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:2;}}i:3;a:4:{s:1:\"a\";i:4;s:1:\"b\";s:15:\"children.import\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:4;a:4:{s:1:\"a\";i:5;s:1:\"b\";s:15:\"pregnant.import\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:5;a:4:{s:1:\"a\";i:6;s:1:\"b\";s:21:\"group_sessions.import\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:6;a:4:{s:1:\"a\";i:7;s:1:\"b\";s:23:\"mother_to_mother.import\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:7;a:4:{s:1:\"a\";i:8;s:1:\"b\";s:28:\"individual_counseling.import\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:8;a:4:{s:1:\"a\";i:9;s:1:\"b\";s:25:\"follow_up_children.import\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:9;a:4:{s:1:\"a\";i:10;s:1:\"b\";s:16:\"meal_report.view\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:5;}}i:10;a:4:{s:1:\"a\";i:11;s:1:\"b\";s:18:\"meal_report.export\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:5;}}i:11;a:4:{s:1:\"a\";i:12;s:1:\"b\";s:20:\"notifications.manage\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:12;a:4:{s:1:\"a\";i:13;s:1:\"b\";s:13:\"children.view\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:4:{i:0;i:1;i:1;i:2;i:2;i:3;i:3;i:4;}}i:13;a:4:{s:1:\"a\";i:14;s:1:\"b\";s:15:\"children.create\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:14;a:4:{s:1:\"a\";i:15;s:1:\"b\";s:13:\"children.edit\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:15;a:4:{s:1:\"a\";i:16;s:1:\"b\";s:15:\"children.delete\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:2;}}i:16;a:4:{s:1:\"a\";i:17;s:1:\"b\";s:15:\"children.export\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:4;}}i:17;a:4:{s:1:\"a\";i:18;s:1:\"b\";s:13:\"pregnant.view\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:4:{i:0;i:1;i:1;i:2;i:2;i:3;i:3;i:4;}}i:18;a:4:{s:1:\"a\";i:19;s:1:\"b\";s:15:\"pregnant.create\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:19;a:4:{s:1:\"a\";i:20;s:1:\"b\";s:13:\"pregnant.edit\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:20;a:4:{s:1:\"a\";i:21;s:1:\"b\";s:15:\"pregnant.delete\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:2;}}i:21;a:4:{s:1:\"a\";i:22;s:1:\"b\";s:15:\"pregnant.export\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:4;}}i:22;a:4:{s:1:\"a\";i:23;s:1:\"b\";s:19:\"group_sessions.view\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:5:{i:0;i:1;i:1;i:2;i:2;i:3;i:3;i:4;i:4;i:5;}}i:23;a:4:{s:1:\"a\";i:24;s:1:\"b\";s:21:\"group_sessions.create\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:24;a:4:{s:1:\"a\";i:25;s:1:\"b\";s:19:\"group_sessions.edit\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:25;a:4:{s:1:\"a\";i:26;s:1:\"b\";s:21:\"group_sessions.delete\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:2;}}i:26;a:4:{s:1:\"a\";i:27;s:1:\"b\";s:21:\"group_sessions.export\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:4:{i:0;i:1;i:1;i:2;i:2;i:4;i:3;i:5;}}i:27;a:4:{s:1:\"a\";i:28;s:1:\"b\";s:21:\"mother_to_mother.view\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:4:{i:0;i:1;i:1;i:2;i:2;i:3;i:3;i:4;}}i:28;a:4:{s:1:\"a\";i:29;s:1:\"b\";s:23:\"mother_to_mother.create\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:29;a:4:{s:1:\"a\";i:30;s:1:\"b\";s:21:\"mother_to_mother.edit\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:30;a:4:{s:1:\"a\";i:31;s:1:\"b\";s:23:\"mother_to_mother.delete\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:2;}}i:31;a:4:{s:1:\"a\";i:32;s:1:\"b\";s:23:\"mother_to_mother.export\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:4;}}i:32;a:4:{s:1:\"a\";i:33;s:1:\"b\";s:26:\"individual_counseling.view\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:5:{i:0;i:1;i:1;i:2;i:2;i:3;i:3;i:4;i:4;i:5;}}i:33;a:4:{s:1:\"a\";i:34;s:1:\"b\";s:28:\"individual_counseling.create\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:34;a:4:{s:1:\"a\";i:35;s:1:\"b\";s:26:\"individual_counseling.edit\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:35;a:4:{s:1:\"a\";i:36;s:1:\"b\";s:28:\"individual_counseling.delete\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:2;}}i:36;a:4:{s:1:\"a\";i:37;s:1:\"b\";s:28:\"individual_counseling.export\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:4:{i:0;i:1;i:1;i:2;i:2;i:4;i:3;i:5;}}i:37;a:4:{s:1:\"a\";i:38;s:1:\"b\";s:23:\"follow_up_children.view\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:5:{i:0;i:1;i:1;i:2;i:2;i:3;i:3;i:4;i:4;i:5;}}i:38;a:4:{s:1:\"a\";i:39;s:1:\"b\";s:25:\"follow_up_children.create\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:39;a:4:{s:1:\"a\";i:40;s:1:\"b\";s:23:\"follow_up_children.edit\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}}i:40;a:4:{s:1:\"a\";i:41;s:1:\"b\";s:25:\"follow_up_children.delete\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:2;}}i:41;a:4:{s:1:\"a\";i:42;s:1:\"b\";s:25:\"follow_up_children.export\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:4:{i:0;i:1;i:1;i:2;i:2;i:4;i:3;i:5;}}i:42;a:4:{s:1:\"a\";i:43;s:1:\"b\";s:12:\"users.manage\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:43;a:4:{s:1:\"a\";i:44;s:1:\"b\";s:12:\"roles.manage\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:44;a:4:{s:1:\"a\";i:45;s:1:\"b\";s:15:\"settings.manage\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:45;a:4:{s:1:\"a\";i:46;s:1:\"b\";s:13:\"backup.manage\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:46;a:4:{s:1:\"a\";i:47;s:1:\"b\";s:12:\"cache.manage\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:47;a:4:{s:1:\"a\";i:48;s:1:\"b\";s:13:\"activity.view\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:2;}}}s:5:\"roles\";a:5:{i:0;a:3:{s:1:\"a\";i:1;s:1:\"b\";s:11:\"Super Admin\";s:1:\"c\";s:3:\"web\";}i:1;a:3:{s:1:\"a\";i:2;s:1:\"b\";s:5:\"Admin\";s:1:\"c\";s:3:\"web\";}i:2;a:3:{s:1:\"a\";i:3;s:1:\"b\";s:10:\"Data Entry\";s:1:\"c\";s:3:\"web\";}i:3;a:3:{s:1:\"a\";i:5;s:1:\"b\";s:3:\"M&E\";s:1:\"c\";s:3:\"web\";}i:4;a:3:{s:1:\"a\";i:4;s:1:\"b\";s:6:\"Viewer\";s:1:\"c\";s:3:\"web\";}}}',1788282866);
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `children`
--

DROP TABLE IF EXISTS `children`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `children` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `visit_type` enum('new','follow_up') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `child_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_pwd` tinyint(1) DEFAULT '0',
  `organization` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `implementing_partner` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_of_reporting` date NOT NULL,
  `is_displaced` tinyint(1) DEFAULT '0',
  `screener_profession` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sex` enum('male','female') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `age_months` int DEFAULT NULL COMMENT 'Manual age entry in months',
  `muac_mm` decimal(5,1) DEFAULT NULL,
  `fi` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Food Insecurity indicator',
  `has_oedema` tinyint(1) NOT NULL DEFAULT '0',
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `height_cm` decimal(5,1) DEFAULT NULL,
  `whz` decimal(4,2) DEFAULT NULL COMMENT 'Weight-for-Height Z-score',
  `governorate` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `municipality` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `neighbourhood` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type_of_site` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_enrolled_bsfp` tinyint(1) DEFAULT '0',
  `is_sick_last_6_months` tinyint(1) DEFAULT '0',
  `is_mother_alive` tinyint(1) DEFAULT '1',
  `mother_full_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mother_id_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mother_date_of_birth` date DEFAULT NULL,
  `mother_age_years` int DEFAULT NULL,
  `mother_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `father_full_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `father_id_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `father_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `has_lactating_woman` tinyint(1) DEFAULT '0',
  `has_pregnant_last_trimester` tinyint(1) DEFAULT '0',
  `children_under_5` int DEFAULT '0',
  `head_of_household_sex` enum('male','female') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mother_marital_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mother_muac_mm` decimal(5,1) DEFAULT NULL,
  `is_mother_malnourished` tinyint(1) DEFAULT '0',
  `has_stable_income` tinyint(1) DEFAULT '0',
  `income_source` enum('government','unrwa','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_income_below_500` tinyint(1) DEFAULT '0',
  `male_children_under_5` int DEFAULT '0',
  `female_children_under_5` int DEFAULT '0',
  `family_size` int DEFAULT '1',
  `current_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `has_family_disability` tinyint(1) DEFAULT '0',
  `disability_cause` enum('war','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disability_cause_other` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `has_injured_after_oct7` tinyint(1) DEFAULT '0',
  `injured_count` int DEFAULT NULL,
  `has_unaccompanied_children` tinyint(1) DEFAULT '0',
  `unaccompanied_children_count` int DEFAULT NULL,
  `has_released_children` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `children_governorate_index` (`governorate`),
  KEY `children_municipality_index` (`municipality`),
  KEY `children_sex_index` (`sex`),
  KEY `children_is_displaced_index` (`is_displaced`),
  KEY `children_date_of_reporting_index` (`date_of_reporting`),
  KEY `children_type_of_site_index` (`type_of_site`),
  KEY `children_organization_index` (`organization`),
  KEY `children_visit_type_index` (`visit_type`),
  KEY `children_neighbourhood_index` (`neighbourhood`),
  KEY `children_child_id_index` (`child_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `children`
--

LOCK TABLES `children` WRITE;
/*!40000 ALTER TABLE `children` DISABLE KEYS */;
/*!40000 ALTER TABLE `children` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `follow_up_child_visits`
--

DROP TABLE IF EXISTS `follow_up_child_visits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `follow_up_child_visits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `follow_up_child_id` bigint unsigned NOT NULL,
  `visit_number` tinyint unsigned NOT NULL,
  `visit_date` date NOT NULL,
  `muac` decimal(5,1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `follow_up_child_visits_follow_up_child_id_visit_number_unique` (`follow_up_child_id`,`visit_number`),
  CONSTRAINT `follow_up_child_visits_follow_up_child_id_foreign` FOREIGN KEY (`follow_up_child_id`) REFERENCES `follow_up_children` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `follow_up_child_visits`
--

LOCK TABLES `follow_up_child_visits` WRITE;
/*!40000 ALTER TABLE `follow_up_child_visits` DISABLE KEYS */;
/*!40000 ALTER TABLE `follow_up_child_visits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `follow_up_children`
--

DROP TABLE IF EXISTS `follow_up_children`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `follow_up_children` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `child_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sex` enum('M','F') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `age` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shelter_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `governorate` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Gaza',
  `causes_of_admission` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admitted_with` enum('SAM','MAM') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admission_date` date DEFAULT NULL,
  `discharge_date` date DEFAULT NULL,
  `discharge_outcome` enum('cured','defaulted','discharge_to_opt','discharge_to_other','died','under_follow_up') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `follow_up_children_id_number_index` (`id_number`),
  KEY `follow_up_children_child_name_index` (`child_name`),
  KEY `follow_up_children_shelter_name_index` (`shelter_name`),
  KEY `follow_up_children_admission_date_index` (`admission_date`),
  KEY `follow_up_children_discharge_outcome_index` (`discharge_outcome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `follow_up_children`
--

LOCK TABLES `follow_up_children` WRITE;
/*!40000 ALTER TABLE `follow_up_children` DISABLE KEYS */;
/*!40000 ALTER TABLE `follow_up_children` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `group_sessions`
--

DROP TABLE IF EXISTS `group_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `group_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_date` date NOT NULL,
  `session_group_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_subject` enum('bf_support','relactation','complimentary_feeding','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_subject_other` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `locality` enum('tal_al_hawa','el_saftawi','el_nafaq','el_shatee','karamah') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `shelter_name` enum('mosaab_camp','mahabba','el_salam','el_qoqa') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name_ar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `visit_type` enum('new','follow_up') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `category` enum('grandmothers','reproductive_age','male','caregiver_child_under_6_months','caregiver_child_6_23_months','pregnant','lactating') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `newborn_dob` date DEFAULT NULL,
  `is_pwd` tinyint(1) NOT NULL DEFAULT '0',
  `marital_status` enum('married','divorced','widow','separated') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `has_gsfsh` tinyint(1) NOT NULL DEFAULT '0',
  `receives_supplementary` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `group_sessions_session_date_locality_index` (`session_date`,`locality`),
  KEY `group_sessions_shelter_name_index` (`shelter_name`),
  KEY `group_sessions_session_subject_index` (`session_subject`),
  KEY `group_sessions_visit_type_index` (`visit_type`),
  KEY `group_sessions_id_number_index` (`id_number`),
  KEY `group_sessions_full_name_ar_index` (`full_name_ar`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `group_sessions`
--

LOCK TABLES `group_sessions` WRITE;
/*!40000 ALTER TABLE `group_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `group_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `individual_counseling_followups`
--

DROP TABLE IF EXISTS `individual_counseling_followups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `individual_counseling_followups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `individual_counseling_id` bigint unsigned NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `follow_up_visit_date` date DEFAULT NULL,
  `assess_and_analyze` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `act` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ic_followups_parent_order_index` (`individual_counseling_id`,`sort_order`),
  CONSTRAINT `individual_counseling_followups_individual_counseling_id_foreign` FOREIGN KEY (`individual_counseling_id`) REFERENCES `individual_counselings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `individual_counseling_followups`
--

LOCK TABLES `individual_counseling_followups` WRITE;
/*!40000 ALTER TABLE `individual_counseling_followups` DISABLE KEYS */;
/*!40000 ALTER TABLE `individual_counseling_followups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `individual_counselings`
--

DROP TABLE IF EXISTS `individual_counselings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `individual_counselings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `health_educator` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `child_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `child_visit_type` enum('new','follow_up') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `child_dob` date DEFAULT NULL,
  `age_months` int DEFAULT NULL,
  `gender` enum('M','F') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `child_age_lactated` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `feeding_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p_l` enum('L','P','P+L') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `muac` decimal(5,1) DEFAULT NULL,
  `muac_degree` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mother_id_number` varchar(9) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mother_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mother_visit_type` enum('new','follow_up') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mother_dob` date DEFAULT NULL,
  `mother_age_years` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shelter_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `consultation` enum('complementary_feeding','bf_support','relactation','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `iycf_form_filled` tinyint(1) DEFAULT NULL,
  `status` enum('discharged','under_follow_up') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `outcome` enum('improved','dont_improve','non_response') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `assess` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `analyze` text COLLATE utf8mb4_unicode_ci,
  `act` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `pregnancy` enum('yes','no') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lactating` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivery_date` date DEFAULT NULL,
  `pregnancy_count` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `individual_counselings_date_status_index` (`date`,`status`),
  KEY `individual_counselings_child_name_index` (`child_name`),
  KEY `individual_counselings_mother_name_index` (`mother_name`),
  KEY `individual_counselings_mother_id_number_index` (`mother_id_number`),
  KEY `individual_counselings_shelter_name_index` (`shelter_name`),
  KEY `individual_counselings_outcome_index` (`outcome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `individual_counselings`
--

LOCK TABLES `individual_counselings` WRITE;
/*!40000 ALTER TABLE `individual_counselings` DISABLE KEYS */;
/*!40000 ALTER TABLE `individual_counselings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2022_12_14_083707_create_settings_table',1),(5,'2024_01_01_000001_create_children_table',1),(6,'2024_01_01_000002_create_pregnant_lactating_women_table',1),(7,'2024_01_01_000003_create_general_settings',1),(8,'2026_08_08_130504_create_permission_tables',1),(9,'2026_08_08_130526_create_activity_log_table',1),(10,'2026_08_08_130527_add_event_column_to_activity_log_table',1),(11,'2026_08_08_130528_add_batch_uuid_column_to_activity_log_table',1),(12,'2026_08_09_000000_add_query_indexes_to_child_and_pregnant_lactating_women_tables',1),(13,'2026_08_16_000000_add_avatar_to_users_table',1),(14,'2026_08_16_000001_create_group_sessions_table',1),(15,'2026_08_19_000001_create_mother_to_mother_sessions_table',1),(16,'2026_08_20_000001_create_individual_counselings_table',1),(17,'2026_08_20_000002_create_follow_up_children_table',1),(18,'2026_08_20_000003_create_follow_up_child_visits_table',1),(19,'2026_08_22_000001_create_notifications_table',1),(20,'2026_08_23_000000_drop_unique_constraint_from_child_and_mother_ids',1),(21,'2026_08_25_000001_add_trash_permissions',1),(22,'2026_08_25_000002_add_import_permissions',1),(23,'2026_08_26_100000_update_schema_for_validations',1),(24,'2026_08_26_110000_make_children_family_columns_nullable',1),(25,'2026_08_26_120000_widen_individual_counseling_option_columns',1),(26,'2026_08_26_120001_create_individual_counseling_followups_table',1),(27,'2026_08_26_130000_add_meal_report_permissions',1),(28,'2026_08_27_100000_widen_session_category_columns',1),(29,'2026_08_27_100001_convert_follow_up_children_discharge_date_to_date',1),(30,'2026_08_27_200000_create_notification_settings',1),(31,'2026_08_27_200001_add_notifications_manage_permission',1),(32,'2026_08_28_000000_add_notify_self_actions_to_notification_settings',1),(33,'2026_08_29_000001_add_analyze_to_individual_counselings_table',2),(34,'2026_08_29_000002_move_individual_counseling_flat_followup_into_sessions',2);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_permissions`
--

DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_permissions`
--

LOCK TABLES `model_has_permissions` WRITE;
/*!40000 ALTER TABLE `model_has_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `model_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_roles`
--

DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_roles`
--

LOCK TABLES `model_has_roles` WRITE;
/*!40000 ALTER TABLE `model_has_roles` DISABLE KEYS */;
INSERT INTO `model_has_roles` VALUES (1,'App\\Models\\User',1);
/*!40000 ALTER TABLE `model_has_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mother_to_mother_sessions`
--

DROP TABLE IF EXISTS `mother_to_mother_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mother_to_mother_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_date` date NOT NULL,
  `session_group_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_subject` enum('bf_support','relactation','complimentary_feeding','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_subject_other` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `locality` enum('mosaab_camp','el_salam_camp','mahabba_camp','el_qoqa') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `shelter_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name_ar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `visit_type` enum('new','follow_up') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `category` enum('grandmothers','reproductive_age','male','caregiver_child_under_6_months','caregiver_child_6_23_months','pregnant','lactating') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `newborn_dob` date DEFAULT NULL,
  `is_pwd` tinyint(1) NOT NULL DEFAULT '0',
  `marital_status` enum('married','divorced','widow','separated') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receives_supplementary` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mother_to_mother_sessions_session_date_locality_index` (`session_date`,`locality`),
  KEY `mother_to_mother_sessions_shelter_name_index` (`shelter_name`),
  KEY `mother_to_mother_sessions_session_subject_index` (`session_subject`),
  KEY `mother_to_mother_sessions_visit_type_index` (`visit_type`),
  KEY `mother_to_mother_sessions_id_number_index` (`id_number`),
  KEY `mother_to_mother_sessions_full_name_ar_index` (`full_name_ar`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mother_to_mother_sessions`
--

LOCK TABLES `mother_to_mother_sessions` WRITE;
/*!40000 ALTER TABLE `mother_to_mother_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `mother_to_mother_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_id` bigint unsigned NOT NULL,
  `data` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'trash.view','web','2026-08-28 14:59:05','2026-08-28 14:59:05'),(2,'trash.restore','web','2026-08-28 14:59:05','2026-08-28 14:59:05'),(3,'trash.force_delete','web','2026-08-28 14:59:05','2026-08-28 14:59:05'),(4,'children.import','web','2026-08-28 14:59:05','2026-08-28 14:59:05'),(5,'pregnant.import','web','2026-08-28 14:59:05','2026-08-28 14:59:05'),(6,'group_sessions.import','web','2026-08-28 14:59:05','2026-08-28 14:59:05'),(7,'mother_to_mother.import','web','2026-08-28 14:59:05','2026-08-28 14:59:05'),(8,'individual_counseling.import','web','2026-08-28 14:59:05','2026-08-28 14:59:05'),(9,'follow_up_children.import','web','2026-08-28 14:59:05','2026-08-28 14:59:05'),(10,'meal_report.view','web','2026-08-28 14:59:07','2026-08-28 14:59:07'),(11,'meal_report.export','web','2026-08-28 14:59:07','2026-08-28 14:59:07'),(12,'notifications.manage','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(13,'children.view','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(14,'children.create','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(15,'children.edit','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(16,'children.delete','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(17,'children.export','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(18,'pregnant.view','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(19,'pregnant.create','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(20,'pregnant.edit','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(21,'pregnant.delete','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(22,'pregnant.export','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(23,'group_sessions.view','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(24,'group_sessions.create','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(25,'group_sessions.edit','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(26,'group_sessions.delete','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(27,'group_sessions.export','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(28,'mother_to_mother.view','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(29,'mother_to_mother.create','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(30,'mother_to_mother.edit','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(31,'mother_to_mother.delete','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(32,'mother_to_mother.export','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(33,'individual_counseling.view','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(34,'individual_counseling.create','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(35,'individual_counseling.edit','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(36,'individual_counseling.delete','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(37,'individual_counseling.export','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(38,'follow_up_children.view','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(39,'follow_up_children.create','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(40,'follow_up_children.edit','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(41,'follow_up_children.delete','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(42,'follow_up_children.export','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(43,'users.manage','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(44,'roles.manage','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(45,'settings.manage','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(46,'backup.manage','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(47,'cache.manage','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(48,'activity.view','web','2026-08-28 14:59:08','2026-08-28 14:59:08');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pregnant_lactating_women`
--

DROP TABLE IF EXISTS `pregnant_lactating_women`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pregnant_lactating_women` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `visit_type` enum('new','follow_up') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `full_name_ar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mother_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_pwd` tinyint(1) DEFAULT '0',
  `organization` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `implementing_partner` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_of_reporting` date NOT NULL,
  `is_displaced` tinyint(1) DEFAULT '0',
  `screener_profession` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `age_years` int DEFAULT NULL COMMENT 'Manual age entry',
  `status_type` enum('pregnant','lactating') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `height_cm` decimal(5,1) DEFAULT NULL,
  `muac_mm` decimal(5,1) DEFAULT NULL,
  `fi` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `has_oedema` tinyint(1) NOT NULL DEFAULT '0',
  `governorate` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `municipality` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `neighbourhood` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type_of_site` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disability_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Type of disability if exists',
  `newborn_dob` date DEFAULT NULL COMMENT 'DOB of newborn - only for lactating',
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `husband_id_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `husband_full_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `husband_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `family_size` int DEFAULT '1',
  `children_count` int DEFAULT '0',
  `is_family_pwd` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pregnant_lactating_women_governorate_index` (`governorate`),
  KEY `pregnant_lactating_women_status_type_index` (`status_type`),
  KEY `pregnant_lactating_women_is_displaced_index` (`is_displaced`),
  KEY `pregnant_lactating_women_date_of_reporting_index` (`date_of_reporting`),
  KEY `pregnant_lactating_women_type_of_site_index` (`type_of_site`),
  KEY `pregnant_lactating_women_organization_index` (`organization`),
  KEY `pregnant_lactating_women_visit_type_index` (`visit_type`),
  KEY `pregnant_lactating_women_municipality_index` (`municipality`),
  KEY `pregnant_lactating_women_neighbourhood_index` (`neighbourhood`),
  KEY `pregnant_lactating_women_mother_id_index` (`mother_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pregnant_lactating_women`
--

LOCK TABLES `pregnant_lactating_women` WRITE;
/*!40000 ALTER TABLE `pregnant_lactating_women` DISABLE KEYS */;
/*!40000 ALTER TABLE `pregnant_lactating_women` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_has_permissions`
--

DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_has_permissions`
--

LOCK TABLES `role_has_permissions` WRITE;
/*!40000 ALTER TABLE `role_has_permissions` DISABLE KEYS */;
INSERT INTO `role_has_permissions` VALUES (1,1),(2,1),(3,1),(4,1),(5,1),(6,1),(7,1),(8,1),(9,1),(10,1),(11,1),(12,1),(13,1),(14,1),(15,1),(16,1),(17,1),(18,1),(19,1),(20,1),(21,1),(22,1),(23,1),(24,1),(25,1),(26,1),(27,1),(28,1),(29,1),(30,1),(31,1),(32,1),(33,1),(34,1),(35,1),(36,1),(37,1),(38,1),(39,1),(40,1),(41,1),(42,1),(43,1),(44,1),(45,1),(46,1),(47,1),(48,1),(1,2),(2,2),(3,2),(4,2),(5,2),(6,2),(7,2),(8,2),(9,2),(10,2),(11,2),(13,2),(14,2),(15,2),(16,2),(17,2),(18,2),(19,2),(20,2),(21,2),(22,2),(23,2),(24,2),(25,2),(26,2),(27,2),(28,2),(29,2),(30,2),(31,2),(32,2),(33,2),(34,2),(35,2),(36,2),(37,2),(38,2),(39,2),(40,2),(41,2),(42,2),(48,2),(4,3),(5,3),(6,3),(7,3),(8,3),(9,3),(13,3),(14,3),(15,3),(18,3),(19,3),(20,3),(23,3),(24,3),(25,3),(28,3),(29,3),(30,3),(33,3),(34,3),(35,3),(38,3),(39,3),(40,3),(13,4),(17,4),(18,4),(22,4),(23,4),(27,4),(28,4),(32,4),(33,4),(37,4),(38,4),(42,4),(10,5),(11,5),(23,5),(27,5),(33,5),(37,5),(38,5),(42,5);
/*!40000 ALTER TABLE `role_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Super Admin','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(2,'Admin','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(3,'Data Entry','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(4,'Viewer','web','2026-08-28 14:59:08','2026-08-28 14:59:08'),(5,'M&E','web','2026-08-28 14:59:08','2026-08-28 14:59:08');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES ('hEcbl6haFhDldZ57TifX9HQBu5uw44jbSTybAnW3',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Code/1.135.0 Chrome/148.0.7778.280 Electron/42.8.1 Safari/537.36','YTo1OntzOjY6Il90b2tlbiI7czo0MDoiMG9XM0g2c3BwQkpMek5DdElteHB4dHlYeWVDMVhtS2VOVzFYaWNpMiI7czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6Mjc6Imh0dHA6Ly8xMjcuMC4wLjE6ODAwMC9hZG1pbiI7czo1OiJyb3V0ZSI7czozMDoiZmlsYW1lbnQuYWRtaW4ucGFnZXMuZGFzaGJvYXJkIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319czo1MDoibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiO2k6MTtzOjE3OiJwYXNzd29yZF9oYXNoX3dlYiI7czo2NDoiNWMyYTc1NzI5N2M5NzVhYmY0MmM1MjcyMzRjNmUyYjJmZmEyYTJjOWVlMmZiOTgwYmNhOWVhY2I3Yjc5OWFiYyI7fQ==',1788196531);
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `group` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `locked` tinyint(1) NOT NULL DEFAULT '0',
  `payload` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_group_name_unique` (`group`,`name`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES (1,'general','site_name',0,'\"╪ث╪▒╪╢ ╪د┘╪ح┘╪│╪د┘ - ┘╪╕╪د┘à ╪د┘┘à╪│╪ص ╪د┘╪ز╪║╪░┘ê┘è\"','2026-08-28 14:59:03','2026-08-28 14:59:03'),(2,'general','primary_color',0,'\"#10b981\"','2026-08-28 14:59:03','2026-08-28 14:59:03'),(3,'general','secondary_color',0,'\"#3b82f6\"','2026-08-28 14:59:03','2026-08-28 14:59:03'),(4,'general','logo_path',0,'\"\"','2026-08-28 14:59:03','2026-08-28 14:59:03'),(5,'general','favicon_path',0,'\"\"','2026-08-28 14:59:03','2026-08-28 14:59:03'),(6,'general','footer_text',0,'\"┬ر 2024 ╪ث╪▒╪╢ ╪د┘╪ح┘╪│╪د┘ - ╪ش┘à┘è╪╣ ╪د┘╪ص┘é┘ê┘é ┘à╪ص┘┘ê╪╕╪ر\"','2026-08-28 14:59:03','2026-08-28 14:59:03'),(7,'general','contact_info',0,'\"\"','2026-08-28 14:59:03','2026-08-28 14:59:03'),(8,'general','default_locale',0,'\"ar\"','2026-08-28 14:59:03','2026-08-28 14:59:03'),(9,'notifications','enabled',0,'true','2026-08-28 14:59:07','2026-08-28 14:59:07'),(10,'notifications','enabled_actions',0,'[\"create\", \"update\", \"delete\", \"force_delete\", \"export\", \"import\"]','2026-08-28 14:59:07','2026-08-28 14:59:07'),(11,'notifications','recipient_user_ids',0,'[]','2026-08-28 14:59:07','2026-08-28 14:59:07'),(12,'notifications','group_window_seconds',0,'60','2026-08-28 14:59:07','2026-08-28 14:59:07'),(13,'notifications','notify_self_actions',0,'false','2026-08-28 14:59:08','2026-08-28 14:59:08');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `avatar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remember_token` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Super Admin','admin@nutrition-screening.org','2026-08-28 14:59:09','$2y$12$Awaop6u159U/xD5FR6/pKOVy5wtUvJhCJAeXSKDMpZt5GgAaAjuwy',NULL,NULL,'2026-08-28 14:59:09','2026-08-28 14:59:09');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'mm'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-31 20:46:17

