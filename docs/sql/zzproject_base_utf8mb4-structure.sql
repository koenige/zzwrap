-- MySQL dump 10.13  Distrib 8.0.21, for osx10.15 (x86_64)
--
-- Host: localhost    Database: zzproject_base_utf8mb4
-- ------------------------------------------------------
-- Server version	8.0.21

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
-- Current Database: `zzproject_base_utf8mb4`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `zzproject_base_utf8mb4` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;

USE `zzproject_base_utf8mb4`;

--
-- Table structure for table `_logging`
--

DROP TABLE IF EXISTS `_logging`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `_logging` (
  `log_id` int unsigned NOT NULL AUTO_INCREMENT,
  `query` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_id` int unsigned DEFAULT NULL,
  `user` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs DEFAULT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `record_id` (`record_id`),
  KEY `last_update` (`last_update`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `_relations`
--

DROP TABLE IF EXISTS `_relations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `_relations` (
  `rel_id` int unsigned NOT NULL AUTO_INCREMENT,
  `master_db` varchar(127) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `master_table` varchar(127) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `master_field` varchar(127) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `detail_db` varchar(127) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `detail_table` varchar(127) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `detail_field` varchar(127) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `delete` enum('delete','ask','no-delete','update') CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT 'no-delete',
  `detail_id_field` varchar(127) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `detail_url` varchar(63) CHARACTER SET latin1 COLLATE latin1_general_cs DEFAULT NULL,
  PRIMARY KEY (`rel_id`),
  UNIQUE KEY `master_db` (`master_db`,`master_table`,`master_field`,`detail_db`,`detail_table`,`detail_field`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_general_cs;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `_settings`
--

DROP TABLE IF EXISTS `_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `_settings` (
  `setting_id` int unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `setting_value` varchar(750) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `explanation` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `website_id` int unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `setting_key_website_id` (`setting_key`,`website_id`),
  KEY `website_id` (`website_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `_translationfields`
--

DROP TABLE IF EXISTS `_translationfields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `_translationfields` (
  `translationfield_id` int unsigned NOT NULL AUTO_INCREMENT,
  `db_name` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `table_name` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `field_name` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `field_type` enum('varchar','text') CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT 'varchar',
  PRIMARY KEY (`translationfield_id`),
  UNIQUE KEY `db_name` (`db_name`,`table_name`,`field_name`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_general_cs;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `_translations_text`
--

DROP TABLE IF EXISTS `_translations_text`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `_translations_text` (
  `translation_id` int unsigned NOT NULL AUTO_INCREMENT,
  `translationfield_id` int unsigned NOT NULL,
  `field_id` int unsigned NOT NULL,
  `translation` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `language_id` int unsigned NOT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`translation_id`),
  UNIQUE KEY `field_id` (`field_id`,`translationfield_id`,`language_id`),
  KEY `language_id` (`language_id`),
  KEY `translationfield_id` (`translationfield_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `_translations_varchar`
--

DROP TABLE IF EXISTS `_translations_varchar`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `_translations_varchar` (
  `translation_id` int unsigned NOT NULL AUTO_INCREMENT,
  `translationfield_id` int unsigned NOT NULL,
  `field_id` int unsigned NOT NULL,
  `translation` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `language_id` int unsigned NOT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`translation_id`),
  UNIQUE KEY `field_id` (`field_id`,`translationfield_id`,`language_id`),
  KEY `translationfield_id` (`translationfield_id`),
  KEY `language_id` (`language_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `_uris`
--

DROP TABLE IF EXISTS `_uris`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `_uris` (
  `uri_id` int unsigned NOT NULL AUTO_INCREMENT,
  `uri_scheme` varchar(15) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `uri_host` varchar(63) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `uri_path` varchar(127) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `uri_query` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `content_type` varchar(127) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `character_encoding` varchar(31) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `content_length` mediumint unsigned NOT NULL,
  `user` varchar(64) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL DEFAULT 'none',
  `status_code` smallint NOT NULL,
  `etag_md5` varchar(32) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `last_modified` datetime DEFAULT NULL,
  `hits` int unsigned NOT NULL,
  `first_access` datetime NOT NULL,
  `last_access` datetime NOT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`uri_id`),
  UNIQUE KEY `uri_scheme_uri_host_uri_path_uri_query_user` (`uri_scheme`,`uri_host`,`uri_path`,`uri_query`,`user`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `category_id` int unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(63) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `main_category_id` int unsigned DEFAULT NULL,
  `path` varchar(100) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `parameters` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `sequence` tinyint unsigned DEFAULT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `path` (`path`),
  KEY `main_category_id` (`main_category_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `countries`
--

DROP TABLE IF EXISTS `countries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `countries` (
  `country_id` int unsigned NOT NULL AUTO_INCREMENT,
  `country_code` char(2) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `country_code3` char(3) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `country` varchar(63) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `website` enum('yes','no') CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT 'no',
  PRIMARY KEY (`country_id`),
  UNIQUE KEY `country_code` (`country_code`),
  KEY `website` (`website`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `filetypes`
--

DROP TABLE IF EXISTS `filetypes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `filetypes` (
  `filetype_id` int unsigned NOT NULL AUTO_INCREMENT,
  `filetype` varchar(7) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `mime_content_type` varchar(31) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `mime_subtype` varchar(127) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `filetype_description` varchar(63) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `extension` varchar(7) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  PRIMARY KEY (`filetype_id`),
  UNIQUE KEY `filetype` (`filetype`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `languages`
--

DROP TABLE IF EXISTS `languages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `languages` (
  `language_id` int unsigned NOT NULL AUTO_INCREMENT,
  `iso_639_2t` char(3) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `iso_639_2b` char(3) CHARACTER SET latin1 COLLATE latin1_general_cs DEFAULT NULL,
  `iso_639_1` char(2) CHARACTER SET latin1 COLLATE latin1_general_cs DEFAULT NULL,
  `language_de` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `language_en` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `language_fr` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `variation` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` enum('yes','no') CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT 'no',
  PRIMARY KEY (`language_id`),
  UNIQUE KEY `iso_639_2t_variation` (`iso_639_2t`,`variation`),
  KEY `website` (`website`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logins`
--

DROP TABLE IF EXISTS `logins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logins` (
  `login_id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(63) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `login_rights` enum('admin','read and write','read') CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT 'read',
  `password` varchar(60) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `password_change` enum('yes','no') CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT 'no',
  `logged_in` enum('yes','no') CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT 'no',
  `last_click` int unsigned DEFAULT NULL,
  `active` enum('yes','no') CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT 'yes',
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`login_id`),
  UNIQUE KEY `benutzername` (`username`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_general_cs;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `media`
--

DROP TABLE IF EXISTS `media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `media` (
  `medium_id` int unsigned NOT NULL AUTO_INCREMENT,
  `main_medium_id` int unsigned DEFAULT NULL,
  `title` varchar(127) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `language_id` int unsigned DEFAULT NULL,
  `date` date DEFAULT NULL,
  `time` time DEFAULT NULL,
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `published` enum('yes','no') CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL DEFAULT 'yes',
  `clipping` enum('center','top','right','bottom','left') CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL DEFAULT 'center',
  `sequence` smallint unsigned DEFAULT NULL,
  `filename` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `filetype_id` int unsigned NOT NULL,
  `thumb_filetype_id` int unsigned DEFAULT NULL,
  `filesize` int unsigned DEFAULT NULL,
  `md5_hash` varchar(32) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `version` tinyint unsigned DEFAULT NULL,
  `width_px` mediumint unsigned DEFAULT NULL,
  `height_px` mediumint unsigned DEFAULT NULL,
  `parameters` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`medium_id`),
  KEY `filetype_id` (`filetype_id`),
  KEY `thumb_filetype_id` (`thumb_filetype_id`),
  KEY `main_medium_id` (`main_medium_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `redirects`
--

DROP TABLE IF EXISTS `redirects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `redirects` (
  `redirect_id` int unsigned NOT NULL AUTO_INCREMENT,
  `old_url` varchar(127) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `new_url` varchar(127) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `code` smallint unsigned NOT NULL DEFAULT '301',
  `area` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`redirect_id`),
  UNIQUE KEY `alt` (`old_url`),
  KEY `area` (`area`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `text`
--

DROP TABLE IF EXISTS `text`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `text` (
  `text_id` int unsigned NOT NULL AUTO_INCREMENT,
  `text` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `more_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `area` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`text_id`),
  UNIQUE KEY `text` (`text`(250)),
  KEY `area` (`area`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webpages`
--

DROP TABLE IF EXISTS `webpages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webpages` (
  `page_id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(63) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `identifier` varchar(127) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `ending` enum('.html','/','none') CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT 'none',
  `sequence` tinyint NOT NULL,
  `mother_page_id` int unsigned DEFAULT NULL,
  `live` enum('yes','no') CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT 'yes',
  `menu` enum('top','bottom','internal') CHARACTER SET latin1 COLLATE latin1_general_cs DEFAULT NULL,
  `parameters` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs DEFAULT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`page_id`),
  UNIQUE KEY `identifier` (`identifier`),
  KEY `mother_page_id` (`mother_page_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping routines for database 'zzproject_base_utf8mb4'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed
