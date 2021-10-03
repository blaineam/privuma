-- Adminer 4.8.1 MySQL 5.5.5-10.6.4-MariaDB-1:10.6.4+maria~focal dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

USE `ofether_privuma`;

SET NAMES utf8mb4;

CREATE TABLE `media` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `dupe` int(11) DEFAULT 0,
  `hash` varchar(512) DEFAULT NULL,
  `album` varchar(1000) DEFAULT NULL,
  `filename` varchar(1000) DEFAULT NULL,
  `time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `media_id_IDX` (`id`) USING BTREE,
  KEY `media_hash_IDX` (`hash`) USING BTREE,
  KEY `media_album_IDX` (`album`(768)) USING BTREE,
  KEY `media_filename_IDX` (`filename`(768)) USING BTREE,
  KEY `media_time_IDX` (`time`) USING BTREE,
  KEY `media_idx_album_dupe_hash` (`album`(255),`dupe`,`hash`(255)),
  KEY `media_filename_time_IDX` (`filename`(512),`time`) USING BTREE,
  FULLTEXT KEY `media_filename_FULL_TEXT_IDX` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- 2021-10-01 00:59:46