<?php

namespace privuma;

$classes = glob(__DIR__. '/**/*.php');

foreach ($classes as $class) {
    require_once($class);   
}

use privuma\helpers\cloudFS;

use privuma\helpers\dotenv;

use PDO;

class Privuma {

    public static string $binDirectory;

    public static string $configDirectory;

    public static string $dataDirectory;

    public static cloudFS $cloudFS;

    public static PDO $pdo;

    function __construct(
        string $configDirectory = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config', 
        string $binDirectory = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'bin', 
        string $dataDirectory = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . "data"
    ) {
        self::$configDirectory = $configDirectory;
        self::$binDirectory = $binDirectory;
        self::$dataDirectory = $dataDirectory;
        self::$cloudFS = new cloudFS(self::$dataDirectory);

        $env = new dotenv();

        $host = $env->get('MYSQL_HOST');
        $db   = $env->get('MYSQL_DATABASE');
        $user = $env->get('MYSQL_USER');
        $pass =  $env->get('MYSQL_PASSWORD');
        $charset = 'utf8mb4';
        $port = 3306;

        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            throw new \PDOException($e->getMessage(), (int)$e->getCode());
            exit(1);
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS  `media` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    }

    public static function getBinDirectory() {
        return self::$binDirectory;
    }

    public static function getConfigDirectory() {
        return self::$configDirectory;
    }

    public static function getDataDirectory() {
        return self::$dataDirectory;
    }

    public static function getCloudFS() {
        return self::$dataDirectory;
    }

    public static function getPDO() {
        return self::$pdo;
    }
}