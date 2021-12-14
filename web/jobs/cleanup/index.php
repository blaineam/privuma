<?php
ini_set('session.cookie_lifetime', 60 * 60 * 24 * 30);
ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 30);
require(__DIR__ . '/../../helpers/cloud-fs-operations.php'); 

$ops = new cloudFS\Operations();
$SYNC_FOLDER = "/data/privuma";
require_once(__DIR__ . '/../../helpers/dotenv.php');
loadEnv(__DIR__ . '/../../config/.env');
$host = get_env('MYSQL_HOST');
$db   = get_env('MYSQL_DATABASE');
$user = get_env('MYSQL_USER');
$pass =  get_env('MYSQL_PASSWORD');
$charset = 'utf8mb4';
$port = 3306;

// Create connection
$conn = new mysqli(
    $host,
    $user,
    $pass,
    $db,
    $port
);

// Check connection
if ($conn->connect_error) {
    echo "DB Connection failed: " . $conn->connect_error;
    exit(1);
}

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
    exit(1);
}

$result = $pdo->prepare('select distinct album from media order by album ASC');
$result->execute();

function normalizeString($str = '')
{
    //remove accents
    $str = strtr(utf8_decode($str), utf8_decode('àáâãäçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ'), 'aaaaaceeeeiiiinooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUY');
    //replace directory symbols
    $str = preg_replace('/[\/\\\\]+/', '-', $str);
    //replace symbols;
    $str = preg_replace('/[\:]+/', '_', $str);
    //replace foreign characters
    return preg_replace('/[^a-zA-Z0-9_\-\s\(\)~]+/', '', $str);
}

function realFilePath($filePath)
{
    global $SYNC_FOLDER;
    global $ops;
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);

    $filename = basename($filePath, "." . $ext);
    $album = normalizeString(basename(dirname($filePath)));

    $filePath = $SYNC_FOLDER . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . $filename . "." . $ext;
    $compressedFile = $SYNC_FOLDER . DIRECTORY_SEPARATOR . $album  . DIRECTORY_SEPARATOR . $filename . "---compressed." . $ext;

    $dupe = $SYNC_FOLDER . DIRECTORY_SEPARATOR . $album  . DIRECTORY_SEPARATOR . $filename . "---dupe." . $ext;
                
    $files = $ops->glob($SYNC_FOLDER . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . explode('---', $filename)[0]. "*.*");
    if ($ops->is_file($filePath)) {
        return $filePath;
    } else if ($ops->is_file($compressedFile)) {
        return $compressedFile;
    } else if ($ops->is_file($dupe)) {
        return $dupe;
    } else if (count($files) > 0) {
        if (strtolower($ext) == "mp4" || strtolower($ext) == "webm") {
            foreach ($files as $file) {
                $iext = pathinfo($file, PATHINFO_EXTENSION);
                if (strtolower($ext) == strtolower($iext)) {
                    return $file;
                }
            }
        } else {
            foreach ($files as $file) {
                $iext = pathinfo($file, PATHINFO_EXTENSION);
                if (strtolower($iext) !== "mp4" && strtolower($iext) !== "webm") {
                    return $file;
                }
            }
        }
    }

    return false;
}

function findFile($filePath, $blockDupes = false)
{
    $realFilePath = realFilePath($filePath);

    if (is_string($realFilePath) && strpos($realFilePath, "---dupe") >= 0 && $blockDupes) {
        $realFilePath = false;
    }

    return $realFilePath !== false;
}

function execFileSize($path)
{
    exec("stat -c %s '" . $path . "'", $size, $code);
    return $code == 0 ? (int)$size : false;
}

while ($album = $result->fetch()) {
    $media = $pdo->prepare('select id, filename, hash, dupe from media where album = ?');
    $media->execute([$album['album']]);
    $albumCounts = ["keeps" => 0, "deletes" => 0];
    while ($item = $media->fetch()) {
        if (!isset($item["filename"])) {
            continue;
        }

        $ext = pathinfo($item["filename"], PATHINFO_EXTENSION);
        $filename = basename($item["filename"], "." . $ext);
        $filePath = $SYNC_FOLDER . DIRECTORY_SEPARATOR . $album['album'] . DIRECTORY_SEPARATOR . $item["filename"];
        $realFilePath = realFilePath($filePath);

        if (strpos($album['album'], 'FA Users') !== false && $filename !== normalizeString($filename)) {
            $stmt = $pdo->prepare('delete from media where id = ?');
            $stmt->execute([$item['id']]);

            if ($ops->is_file($realFilePath)) {
                $ops->unlink($realFilePath);
            }
            continue;
        }

        if ($item["dupe"] !== 0) {
            if (!$ops->is_file(realFilePath($filePath))) {
                $stmt = $pdo->prepare('delete from media where id = ?');                    /* execute query */
                echo PHP_EOL . "Deleting db entry for missing dupe file: " . $item['id'] . " FilePath: " . $filePath . " Response: " . $stmt->execute([$item['id']]);
                continue;
            }

            if ($ops->is_file($realFilePath) && $ops->filesize($realFilePath) !== false && $ops->filesize($realFilePath) <= 512) {
                $mediaPath = $ops->file_get_contents($realFilePath);

                if (!$ops->is_file(realFilePath($SYNC_FOLDER . DIRECTORY_SEPARATOR . $mediaPath))) {
                    echo PHP_EOL . "Reference File Destination file not found: " . $mediaPath;
                    $stmt = $pdo->prepare('delete from media where hash = ?');
                    $stmt->execute([$item['hash']]);
                    $ops->unlink($realFilePath);
                }

                $originalFileSize = $ops->filesize(realFilePath($SYNC_FOLDER . DIRECTORY_SEPARATOR . $mediaPath));
                if (strstr($mediaPath, "---dupe") !== false && $originalFileSize  !== false && $originalFileSize <= 512) {
                    echo PHP_EOL . "File References should never have a dupe as its destination and dupes should never be more than 512 bytes:";
                    echo PHP_EOL . "Source: " . basename(dirname($realFilePath)) . DIRECTORY_SEPARATOR . basename($realFilePath);
                    echo PHP_EOL . "Destination: " . $mediaPath;
                    echo PHP_EOL . "FileSize(Bytes): " . $originalFileSize;
                    $stmt = $pdo->prepare('delete from media where id = ?');
                    $stmt->execute([$item['id']]);
                    $ops->unlink($realFilePath);

                    if ($ops->is_file($SYNC_FOLDER . DIRECTORY_SEPARATOR . $mediaPath)) {
                        $ops->unlink($SYNC_FOLDER . DIRECTORY_SEPARATOR . $mediaPath);
                    } else {
                        continue;
                    }
                }
            }
        }

        if (!$ops->is_file(realFilePath($filePath))) {
            $stmt = $pdo->prepare('delete from media where hash = ?');
            $stmt->execute([$item['hash']]);
            $albumCounts["deletes"]++;
            continue;
        }

        $albumCounts["keeps"]++;
    }

    if ($albumCounts["deletes"] > 0) {
        echo PHP_EOL . "Album: " . $album['album'] . PHP_EOL;
        var_dump($albumCounts);
    }
}