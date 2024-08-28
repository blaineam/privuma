<?php

namespace privuma;

$classes = glob(__DIR__ . '/**/*.php');

foreach ($classes as $class) {
    require_once $class;
}

use privuma\helpers\cloudFS;

use privuma\helpers\dotenv;

use PDO;
use privuma\queue\QueueManager;

class privuma
{
    public static string $binDirectory;

    public static string $configDirectory;

    public static string $dataDirectory;

    public static string $dataFolder;

    public static string $outputDirectory;

    public static dotenv $env;

    public static cloudFS $cloudFS;

    private PDO $pdo;

    public static QueueManager $queueManager;

    private static $instance;

    public function __construct(
        string $configDirectory =
            __DIR__ .
                DIRECTORY_SEPARATOR .
                '..' .
                DIRECTORY_SEPARATOR .
                'config',
        string $binDirectory =
            __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'bin',
        string $dataDirectory =
            __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'data',
        string $outputDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'output'
    ) {
        self::$configDirectory = self::canonicalizePath($configDirectory);
        self::$binDirectory = self::canonicalizePath($binDirectory);
        self::$dataFolder = basename($dataDirectory);
        $dataDirectory =
            dirname($dataDirectory) .
            DIRECTORY_SEPARATOR .
            cloudFS::encode(basename($dataDirectory));
        self::$dataDirectory = self::canonicalizePath($dataDirectory);
        self::$outputDirectory = self::canonicalizePath($outputDirectory);
        self::$cloudFS = new cloudFS();
        self::$queueManager = new QueueManager();

        self::$env = new dotenv();

        $host = self::$env->get('MYSQL_HOST');
        $db = self::$env->get('MYSQL_DATABASE');
        $user = self::$env->get('MYSQL_USER');
        $pass = self::$env->get('MYSQL_PASSWORD');
        $charset = 'utf8mb4';
        $port = self::$env->get('MYSQL_PORT') ?? 3306;

        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 900,
        ];
        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);

            $this->pdo->exec('CREATE TABLE IF NOT EXISTS  `media` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `dupe` int(11) DEFAULT 0,
                `hash` varchar(512) DEFAULT NULL,
                `album` varchar(1000) DEFAULT NULL,
                `filename` varchar(1000) DEFAULT NULL,
                `url` varchar(9512) DEFAULT NULL,
                `thumbnail` varchar(9512) DEFAULT NULL,
                `time` datetime DEFAULT NULL,
                `metadata` varchar(9512) DEFAULT NULL,
                `blocked` int(1) DEFAULT 1,
                PRIMARY KEY (`id`),
                UNIQUE KEY `media_id_IDX` (`id`) USING BTREE,
                KEY `media_hash_IDX` (`hash`) USING BTREE,
                KEY `media_album_IDX` (`album`(768)) USING BTREE,
                KEY `media_filename_IDX` (`filename`(768)) USING BTREE,
                KEY `media_time_IDX` (`time`) USING BTREE,
                KEY `media_idx_album_dupe_hash` (`album`(255),`dupe`,`hash`(255)),
                KEY `media_filename_time_IDX` (`filename`(512),`time`) USING BTREE,
                KEY `media_blocked_IDX` (`blocked`) USING BTREE,
                FULLTEXT KEY `media_filename_FULL_TEXT_IDX` (`filename`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
        } catch (\PDOException $e) {
            var_dump($e);
            die('skipping sqlite3');
            try {
                $this->pdo = new \PDO(
                    'sqlite:' . __DIR__ . DIRECTORY_SEPARATOR . 'db.sqlite3',
                    '',
                    '',
                    [
                        \PDO::ATTR_EMULATE_PREPARES => false,
                        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    ]
                );

                $this->pdo->exec('CREATE TABLE IF NOT EXISTS  `media` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `dupe` INTEGER DEFAULT 0,
                    `hash` TEXT DEFAULT NULL,
                    `album` TEXT DEFAULT NULL,
                    `filename` TEXT DEFAULT NULL,
                    `url` TEXT DEFAULT NULL,
                    `thumbnail` TEXT DEFAULT NULL,
                    `time` datetime DEFAULT CURRENT_TIMESTAMP,
                    `metadata` TEXT DEFAULT NULL,
                    `blocked` INTEGER DEFAULT 1
                    );');

                $this->pdo->exec(
                    'CREATE UNIQUE INDEX IF NOT EXISTS `media_media_id_IDX` ON `media` (`id`);'
                );
                $this->pdo->exec(
                    'CREATE INDEX IF NOT EXISTS `media_media_hash_IDX` ON `media` (`hash`);'
                );
                $this->pdo->exec(
                    'CREATE INDEX IF NOT EXISTS `media_media_album_IDX` ON `media` (`album`);'
                );
                $this->pdo->exec(
                    'CREATE INDEX IF NOT EXISTS `media_media_filename_IDX` ON `media` (`filename`);'
                );
                $this->pdo->exec(
                    'CREATE INDEX IF NOT EXISTS `media_media_time_IDX` ON `media` (`time`);'
                );
                $this->pdo->exec(
                    'CREATE INDEX IF NOT EXISTS `media_media_idx_album_dupe_hash` ON `media` (`album`);'
                );
                $this->pdo->exec(
                    'CREATE INDEX IF NOT EXISTS `media_media_filename_time_IDX` ON `media` (`filename`);'
                );
                $this->pdo->exec(
                    'CREATE INDEX IF NOT EXISTS `media_media_blocked_IDX` ON `media` (`blocked`);'
                );
            } catch (\PDOException $e2) {
                throw new \PDOException(
                    $e->getMessage() . PHP_EOL . $e2->getMessage(),
                    (int) $e2->getCode()
                );
                exit(1);
            }
        }

        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
    }

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function getBinDirectory()
    {
        return self::$binDirectory;
    }

    public static function getConfigDirectory()
    {
        return self::$configDirectory;
    }

    public static function getDataDirectory()
    {
        return self::$dataDirectory;
    }

    public static function getContent($url, $headers = [], $userAgent = null, $cookies = null, $getTempPath = false)
    {
        $ch = curl_init();

        curl_setopt(
            $ch,
            CURLOPT_DOH_URL,
            'https://cloudflare-dns.com/dns-query'
        );
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (!is_null($userAgent)) {
            curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        }

        if (
            !is_null($cookies) &&
            file_exists($cookies)
        ) {
            curl_setopt(
                $ch,
                CURLOPT_COOKIEFILE,
                $cookies
            );
            curl_setopt(
                $ch,
                CURLOPT_COOKIEJAR,
                $cookies
            );
        }

        $return = curl_exec($ch);
        curl_close($ch);
        if ($getTempPath) {
            $tmpPath = tempnam(sys_get_temp_dir(), 'PVMA-');
            $tmpPath .= '.' . pathinfo(explode('?', $url)[0], PATHINFO_EXTENSION);
            if (!file_put_contents($tmpPath, $return)) {
                return false;
            }
            $return = $tmpPath;
        }
        return $return;
    }

    public static function getDataFolder()
    {
        return self::$dataFolder;
    }

    public static function getOutputDirectory()
    {
        return self::$outputDirectory;
    }

    public static function getCloudFS()
    {
        return self::$cloudFS;
    }

    public static function getQueueManager()
    {
        return self::$queueManager;
    }

    public static function getEnv(?string $key = null)
    {
        if (!isset(self::$env)) {
            return (new dotenv())->get($key);
        }
        return self::$env->get($key);
    }

    public function getPDO()
    {
        return $this->pdo;
    }

    public static function canonicalizePath($path): string
    {
        $path = explode(DIRECTORY_SEPARATOR, $path);
        $stack = [];
        foreach ($path as $seg) {
            if ($seg == '..') {
                // Ignore this segment, remove last segment from stack
                array_pop($stack);
                continue;
            }

            if ($seg == '.') {
                // Ignore this segment
                continue;
            }

            $stack[] = $seg;
        }

        return implode(DIRECTORY_SEPARATOR, $stack);
    }
}
