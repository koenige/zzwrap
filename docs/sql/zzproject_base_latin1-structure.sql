-- MySQL dump 10.13  Distrib 5.7.18, for osx10.12 (x86_64)
--
-- Host: localhost    Database: zzproject_base_latin1
-- ------------------------------------------------------
-- Server version	5.7.17

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `zzproject_base_latin1`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `zzproject_base_latin1` /*!40100 DEFAULT CHARACTER SET latin1 COLLATE latin1_german2_ci */;

USE `zzproject_base_latin1`;

--
-- Table structure for table `_logging`
--

DROP TABLE IF EXISTS `_logging`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `_logging` (
  `log_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `query` text COLLATE latin1_german2_ci NOT NULL,
  `record_id` int(10) unsigned DEFAULT NULL,
  `user` varchar(255) COLLATE latin1_german2_ci DEFAULT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `_relations`
--

DROP TABLE IF EXISTS `_relations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `_relations` (
  `rel_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `master_db` varchar(127) COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `master_table` varchar(127) COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `master_field` varchar(127) COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `detail_db` varchar(127) COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `detail_table` varchar(127) COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `detail_field` varchar(127) COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `delete` enum('delete','ask','no-delete','update') COLLATE latin1_general_cs NOT NULL DEFAULT 'no-delete',
  `detail_id_field` varchar(127) COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `detail_url` varchar(63) COLLATE latin1_general_cs DEFAULT NULL,
  PRIMARY KEY (`rel_id`),
  UNIQUE KEY `master_db` (`master_db`,`master_table`,`master_field`,`detail_db`,`detail_table`,`detail_field`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_general_cs;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `_settings`
--

DROP TABLE IF EXISTS `_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `_settings` (
  `setting_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `login_id` int(10) unsigned DEFAULT NULL,
  `setting_key` varchar(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `setting_value` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `explanation` text CHARACTER SET latin1 COLLATE latin1_general_ci,
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `setting_key_login_id` (`setting_key`,`login_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `_translationfields`
--

DROP TABLE IF EXISTS `_translationfields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `_translationfields` (
  `translationfield_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `db_name` varchar(255) COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `table_name` varchar(255) COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `field_name` varchar(255) COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `field_type` enum('varchar','text') COLLATE latin1_general_cs NOT NULL DEFAULT 'varchar',
  PRIMARY KEY (`translationfield_id`),
  UNIQUE KEY `db_name` (`db_name`,`table_name`,`field_name`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_general_cs;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `_translations_text`
--

DROP TABLE IF EXISTS `_translations_text`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `_translations_text` (
  `translation_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `translationfield_id` int(10) unsigned NOT NULL DEFAULT '0',
  `field_id` int(10) unsigned NOT NULL DEFAULT '0',
  `translation` text COLLATE latin1_german2_ci NOT NULL,
  `language_id` int(10) unsigned NOT NULL DEFAULT '0',
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`translation_id`),
  UNIQUE KEY `field_id` (`field_id`,`translationfield_id`,`language_id`),
  KEY `language_id` (`language_id`),
  KEY `translationfield_id` (`translationfield_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `_translations_varchar`
--

DROP TABLE IF EXISTS `_translations_varchar`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `_translations_varchar` (
  `translation_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `translationfield_id` int(10) unsigned NOT NULL DEFAULT '0',
  `field_id` int(10) unsigned NOT NULL DEFAULT '0',
  `translation` varchar(255) COLLATE latin1_german2_ci NOT NULL DEFAULT '',
  `language_id` int(10) unsigned NOT NULL DEFAULT '0',
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`translation_id`),
  UNIQUE KEY `field_id` (`field_id`,`translationfield_id`,`language_id`),
  KEY `translationfield_id` (`translationfield_id`),
  KEY `language_id` (`language_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `_uris`
--

DROP TABLE IF EXISTS `_uris`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `_uris` (
  `uri_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uri_scheme` varchar(15) COLLATE latin1_german2_ci NOT NULL,
  `uri_host` varchar(63) COLLATE latin1_german2_ci NOT NULL,
  `uri_path` varchar(127) COLLATE latin1_german2_ci NOT NULL,
  `uri_query` varchar(255) COLLATE latin1_german2_ci DEFAULT NULL,
  `content_type` varchar(127) COLLATE latin1_german2_ci NOT NULL,
  `character_encoding` varchar(31) COLLATE latin1_german2_ci DEFAULT NULL,
  `content_length` mediumint(8) unsigned NOT NULL,
  `user` varchar(64) COLLATE latin1_german2_ci NOT NULL DEFAULT 'none',
  `status_code` smallint(6) NOT NULL,
  `etag_md5` varchar(32) COLLATE latin1_german2_ci DEFAULT NULL,
  `last_modified` datetime DEFAULT NULL,
  `hits` int(10) unsigned NOT NULL,
  `first_access` datetime NOT NULL,
  `last_access` datetime NOT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`uri_id`),
  UNIQUE KEY `uri_scheme_uri_host_uri_path_uri_query_user` (`uri_scheme`,`uri_host`,`uri_path`,`uri_query`,`user`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `category_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(63) COLLATE latin1_german2_ci NOT NULL DEFAULT '',
  `description` text COLLATE latin1_german2_ci,
  `main_category_id` int(10) unsigned DEFAULT NULL,
  `path` varchar(63) CHARACTER SET latin1 COLLATE latin1_general_cs DEFAULT NULL,
  `parameters` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs DEFAULT NULL,
  `sequence` tinyint(3) unsigned DEFAULT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `path` (`path`),
  KEY `main_category_id` (`main_category_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `countries`
--

DROP TABLE IF EXISTS `countries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `countries` (
  `country_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `country_code` char(2) COLLATE latin1_german2_ci NOT NULL DEFAULT '',
  `country` varchar(63) COLLATE latin1_german2_ci NOT NULL DEFAULT '',
  `website` enum('yes','no') COLLATE latin1_german2_ci NOT NULL DEFAULT 'no',
  PRIMARY KEY (`country_id`),
  UNIQUE KEY `country_code` (`country_code`),
  KEY `website` (`website`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `filetypes`
--

DROP TABLE IF EXISTS `filetypes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `filetypes` (
  `filetype_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `filetype` varchar(7) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL DEFAULT '',
  `mime_content_type` varchar(31) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `mime_subtype` varchar(127) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `filetype_description` varchar(63) COLLATE latin1_german2_ci DEFAULT NULL,
  `extension` varchar(7) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  PRIMARY KEY (`filetype_id`),
  UNIQUE KEY `filetype` (`filetype`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `languages`
--

DROP TABLE IF EXISTS `languages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `languages` (
  `language_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `iso_639_2t` char(3) COLLATE latin1_german2_ci NOT NULL DEFAULT '',
  `iso_639_2b` char(3) COLLATE latin1_german2_ci DEFAULT NULL,
  `iso_639_1` char(2) COLLATE latin1_german2_ci DEFAULT NULL,
  `language_de` varchar(255) COLLATE latin1_german2_ci NOT NULL DEFAULT '',
  `language_en` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL DEFAULT '',
  `language_fr` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL DEFAULT '',
  `website` enum('yes','no') COLLATE latin1_german2_ci NOT NULL DEFAULT 'no',
  PRIMARY KEY (`language_id`),
  UNIQUE KEY `iso_639_2t` (`iso_639_2t`),
  KEY `website` (`website`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logins`
--

DROP TABLE IF EXISTS `logins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `logins` (
  `login_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(63) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `login_rights` enum('admin','read and write','read') COLLATE latin1_german2_ci NOT NULL DEFAULT 'read',
  `password` varchar(60) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `password_change` enum('yes','no') COLLATE latin1_german2_ci NOT NULL DEFAULT 'no',
  `logged_in` enum('yes','no') COLLATE latin1_german2_ci NOT NULL DEFAULT 'no',
  `last_click` int(10) unsigned DEFAULT NULL,
  `active` enum('yes','no') COLLATE latin1_german2_ci NOT NULL DEFAULT 'yes',
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`login_id`),
  UNIQUE KEY `benutzername` (`username`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `media`
--

DROP TABLE IF EXISTS `media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `media` (
  `medium_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `main_medium_id` int(10) unsigned DEFAULT NULL,
  `title` varchar(127) COLLATE latin1_german2_ci NOT NULL DEFAULT '',
  `description` text COLLATE latin1_german2_ci,
  `language_id` int(10) unsigned DEFAULT NULL,
  `date` date DEFAULT NULL,
  `time` time DEFAULT NULL,
  `source` varchar(255) COLLATE latin1_german2_ci DEFAULT NULL,
  `published` enum('yes','no') CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL DEFAULT 'yes',
  `clipping` enum('center','top','right','bottom','left') CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL DEFAULT 'center',
  `sequence` smallint(5) unsigned DEFAULT NULL,
  `filename` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `filetype_id` int(10) unsigned NOT NULL,
  `thumb_filetype_id` int(10) unsigned DEFAULT NULL,
  `filesize` int(10) unsigned DEFAULT NULL,
  `md5_hash` varchar(32) COLLATE latin1_german2_ci DEFAULT NULL,
  `version` tinyint(3) unsigned DEFAULT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`medium_id`),
  KEY `filetype_id` (`filetype_id`),
  KEY `thumb_filetype_id` (`thumb_filetype_id`),
  KEY `main_medium_id` (`main_medium_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `redirects`
--

DROP TABLE IF EXISTS `redirects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `redirects` (
  `redirect_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `old_url` varchar(127) COLLATE latin1_german2_ci NOT NULL,
  `new_url` varchar(127) COLLATE latin1_german2_ci NOT NULL,
  `code` smallint(5) unsigned NOT NULL DEFAULT '301',
  `area` varchar(15) COLLATE latin1_german2_ci DEFAULT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`redirect_id`),
  UNIQUE KEY `old` (`old_url`),
  KEY `area` (`area`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `text`
--

DROP TABLE IF EXISTS `text`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `text` (
  `text_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `text` varchar(255) COLLATE latin1_german2_ci NOT NULL,
  `more_text` text COLLATE latin1_german2_ci,
  `area` varchar(16) COLLATE latin1_german2_ci DEFAULT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`text_id`),
  UNIQUE KEY `text` (`text`),
  KEY `area` (`area`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webpages`
--

DROP TABLE IF EXISTS `webpages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `webpages` (
  `page_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(63) COLLATE latin1_german2_ci NOT NULL,
  `content` text COLLATE latin1_german2_ci NOT NULL,
  `identifier` varchar(127) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `ending` enum('.html','/','none') CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT 'none',
  `sequence` tinyint(4) NOT NULL DEFAULT '0',
  `mother_page_id` int(10) unsigned DEFAULT NULL,
  `live` enum('yes','no') COLLATE latin1_german2_ci NOT NULL DEFAULT 'yes',
  `menu` enum('top','bottom','internal') COLLATE latin1_german2_ci DEFAULT NULL,
  `parameters` varchar(255) COLLATE latin1_german2_ci DEFAULT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`page_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping routines for database 'zzproject_base_latin1'
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
