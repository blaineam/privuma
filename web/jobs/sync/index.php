<?php
ini_set('mysql.connect_timeout', 3600);
ini_set('default_socket_timeout', 3600);

if ($argc > 1) parse_str(implode('&', array_slice($argv, 1)), $_GET);

require(__DIR__ . '/../../helpers/cloud-fs-operations.php'); 

$ops = new cloudFS\Operations();
$SYNC_FOLDER = "/data/privuma";
$DEBUG = PHP_OS_FAMILY == 'Darwin' ? true : true;
$ffmpegThreadCount = PHP_OS_FAMILY == 'Darwin' ? 4 : 1;
$ffmpegVideoCodec = PHP_OS_FAMILY == 'Darwin' ? "h264_videotoolbox" : "h264";
$ffmpegPath =  PHP_OS_FAMILY == 'Darwin' ? "/usr/local/bin/ffmpeg" : "/usr/bin/ffmpeg";
require_once(__DIR__.'/../../helpers/dotenv.php');
loadEnv(PHP_OS_FAMILY == 'Darwin' ? __DIR__ . '/../../config/mac.env' : __DIR__ . '/../../config/.env');
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

$pdo->exec("CREATE TABLE IF NOT EXISTS  `media` (
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

function scan_dir($dir) {
    if (isset($_GET['order']) && $_GET['order'] == "time" && isset($_GET['reverse'])) {
        exec("ls -rt '" . $dir . "'", $files);
        return ($files) ? $files : false;
    }
    if (isset($_GET['order']) && $_GET['order'] == "name" && isset($_GET['reverse'])) {
        exec("ls -r '" . $dir . "'", $files);
        return ($files) ? $files : false;
    }
    if (isset($_GET['order']) && $_GET['order'] == "name" && !isset($_GET['reverse'])) {
        exec("ls '" . $dir . "'", $files);
        return ($files) ? $files : false;
    }

    exec("ls -t '" . $dir . "'", $files);
    return ($files) ? $files : false;
}

function getDirContents($dir, &$results = array())
{
    global $pdo;
    global $DEBUG;
    global $ops;
    $files = array_diff($ops->scandir($dir), ['.','..']);
    $queue = [];
    foreach ($files as $value) {
        $path = $dir . DIRECTORY_SEPARATOR . $value;
        if (!$ops->is_dir($path)) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $filename = basename($path, "." . $ext);
            $filenameParts = explode("---", $filename);

            if (count($filenameParts) > 1 || $ext === "mp4") {
                //queue db lookup
                $queue[] = $path;
            } else {
                processFilePath($path);
            }

        } else if ($value != "." && $value != ".." && $value != "@eaDir") {
            if(isset($_GET['albums']) && !in_array(basename($path), explode(',', $_GET['albums']))) {
                continue;
            }

            getDirContents($path);
        }
    }

    if($DEBUG && count($queue) > 0) {
        echo PHP_EOL . "Checking for missed filesystem files for album: " . basename(dirname($queue[0]));
    }

    foreach ($queue as $path) {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $filename = basename($path, "." . $ext);
        $filenameParts = explode("---", $filename);
        $album = basename(dirname($path));

        if (count($filenameParts) >= 2) {
            $hash = $filenameParts[1];
        } else {
            $hash = $ops->md5_file($path);
        }

        $stmt = $pdo->prepare('SELECT * FROM media WHERE hash = ? AND album = ? AND MATCH(filename) AGAINST("' . trim($filenameParts[0],'-') . '")');
        $stmt->execute([$hash, $album]);
        $original = $stmt->fetch();

        if ($original === false || $ext === "webm") {
            processFilePath($path);
        }
    }
}

getDirContents($SYNC_FOLDER);

function processVideoFile($filePath)
{
    global $ffmpegThreadCount;
    global $ffmpegVideoCodec;
    global $ffmpegPath;
    global $pdo;
    global $DEBUG;
    global $ops;
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    $filename = basename($filePath, "." . $ext);
    $thumbnailPath = dirname($filePath) . DIRECTORY_SEPARATOR . $filename . ".jpg";
    $newFilePath = dirname($filePath) . DIRECTORY_SEPARATOR . $filename . "---compressed.mp4";


    $tempFile = $ops->pull($filePath);

    rename($tempFile, $tempFile . '.mp4');
    $tempFile = $tempFile . '.mp4';
    $newFileTemp = tempnam(sys_get_temp_dir(), 'PVMA');
    rename($newFileTemp, $newFileTemp . '.mp4');
    $newFileTemp = $newFileTemp  . '.mp4';
    $newThumbTemp = tempnam(sys_get_temp_dir(), 'PVMA');
    rename($newThumbTemp, $newThumbTemp . '.jpg');
    $newThumbTemp = $newThumbTemp  . '.jpg';

    exec("$ffmpegPath -threads $ffmpegThreadCount -hide_banner -loglevel error -y -ss `$ffmpegPath -threads $ffmpegThreadCount -y -i '" . $tempFile . "' 2>&1 | grep Duration | awk '{print $2}' | tr -d , | awk -F ':' '{print ($3+$2*60+$1*3600)/2}'` -i '" . $tempFile . "' -vcodec mjpeg -vframes 1 -an -f rawvideo '" . $newThumbTemp . "' > /dev/null", $void, $response);
    if ($response !== 0) {
        unset($response);
        unset($void);
        exec("$ffmpegPath -threads $ffmpegThreadCount -hide_banner -loglevel error -y -ss 00:00:01.00 -i '" . $tempFile . "' -vcodec mjpeg -vframes 1 -an -f rawvideo '" . $newThumbTemp . "' > /dev/null", $void, $response); 
    }
    
    if (strtolower($ext) == "mp4" && $ops->is_file($newFilePath)) {

        if($DEBUG) {
            echo PHP_EOL . "File is the correct format already";
        }

        exec("$ffmpegPath -threads $ffmpegThreadCount -hide_banner -loglevel error -y -i '" . $tempFile . "' -c copy -map 0 -movflags +faststart'" . $newFileTemp . "'", $void, $response3);
        if ($response3 == 0 && is_file($newFileTemp)) {
            $ops->copy($newFileTemp, $newFilePath, false);
        }
        unlink($tempFile);
        unlink($newFileTemp);
        unlink($newThumbTemp);
        return;
    }

    exec("$ffmpegPath -threads $ffmpegThreadCount -hide_banner -loglevel error -y -fflags +genpts -i '" . $tempFile . "' -c:v " . $ffmpegVideoCodec . " -r 24 -crf 24 -c:a aac -movflags +faststart -profile:v baseline -level 3.0 -pix_fmt yuv420p -vf \"scale='min(1920,iw+mod(iw,2))':'min(1080,ih+mod(ih,2)):flags=neighbor'\" '" . $newFileTemp . "'", $void, $response2);

    if ($response == 0 && $response2 == 0) {
        if($DEBUG) {
            echo PHP_EOL . "Video Conversion Was Successful for: " . $filename;
        }

        $ops->copy($newThumbTemp, $thumbnailPath, false);

        $newFastFileTemp = tempnam(sys_get_temp_dir(), 'PVMA');
        rename($newFastFileTemp, $newFastFileTemp . '.mp4');
        $newFastFileTemp = $newFastFileTemp  . '.mp4';
        exec("$ffmpegPath -threads $ffmpegThreadCount -hide_banner -loglevel error -y -i '" . $newFileTemp . "' -c copy -map 0 -movflags +faststart '" . $newFastFileTemp . "'", $void, $response3);
        if ($response3 == 0 && is_file($newFastFileTemp)) {
            $ops->copy($newFastFileTemp, $newFilePath, false);
        } else {
            $ops->copy($newFileTemp, $newFilePath, false);
        }


        unlink($newFastFileTemp);
    } else {
            echo PHP_EOL."Video Conversion Failed: " . $filename;
            $filenameParts = explode("---", $filename);
            $hash = $filenameParts[1];
            $album = basename(dirname($filePath));
            $stmt = $pdo->prepare('SELECT * FROM media WHERE hash = ? AND album = ? AND MATCH(filename) AGAINST("' . trim($filenameParts[0],'-') . '")');
            $stmt->execute([$hash, $album]);
            $ops->unlink($filePath);
            $ops->unlink($thumbnailPath);
        }

        unlink($newThumbTemp);
        unlink($newFileTemp);
        unlink($tempFile);
    
        unset($void);
}

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
    if($files === false) {
        $files = [];
    }
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

function processFilePath($filePath)
{
    $allowedPhotos = ["BMP", "GIF", "HEIC", "ICO", "JPG", "JPEG", "PNG", "TIFF", "WEBP"];
    $allowedVideos = ["3GP", "3G2", "ASF", "AVI", "DIVX", "M2T", "M2TS", "M4V", "MKV", "MMV", "MOD", "MOV", "MP4", "MPG", "MTS", "TOD", "WMV", "WEBM"];

    global $conn;
    global $pdo;
    global $DEBUG;
    global $ops;
    global $SYNC_FOLDER;

    /* check if server is alive */
    if ($conn->ping()) {
    } else {
        echo "Error: " . $conn->error;
        exit(1);
    }

    if ($ops->is_dir($filePath)) {
        return;
    }

    $ext = pathinfo($filePath, PATHINFO_EXTENSION);

    if ($ext == "DS_Store") {
        return;
    }

    if (in_array(strtoupper($ext), $allowedPhotos)) {
    } else if (in_array(strtoupper($ext), $allowedVideos)) {
    } else if (strtolower($ext) === 'sqlite') {
        return;
    } else {
        echo PHP_EOL . "Found unsupported " . $ext . " filetype: " . $filePath;
        $ops->unlink($filePath);
        return;
    }

    $filename = basename($filePath, "." . $ext);
    $albumName = basename(dirname($filePath));
    $fileIsDupe = strpos($filename, '---dupe') !== false ? 1 : 0;
    $fileParts = explode('---', $filename);
    if (count($fileParts) > 1 && $fileParts[1] !== "compressed" && !empty($fileParts[1])) {
        $hash = $fileParts[1];
    } else {
        $hash = $ops->md5_file($filePath);
        $size = $ops->filesize($filePath);
        if ($hash === 'd41d8cd98f00b204e9800998ecf8427e' || $size === false || $size === 0) {
            echo PHP_EOL."Found Empty File: " . $filePath . ", Hash: " . $hash . ", Size: " . $size;
            if ($ops->is_file($filePath)){
                $ops->unlink($filePath);
            }
            return;
        }

        if (!$hash) {
             echo PHP_EOL."Could not MD5 Hash file: " . $filePath;
            return;
        }
        $fileParts[1] = $hash;
    }
    /* check if server is alive */
    if ($conn->ping()) {
    } else {
        echo "Error: " . $conn->error;
        exit(1);
    }

    if (isset($original)) {
        unset($original);
    }

    $stmt = $pdo->prepare('SELECT * FROM media WHERE hash = ? AND (album != ? OR filename NOT LIKE "' . trim($fileParts[0],'-') . '%" ) and dupe = 0 ORDER BY time ASC');
    $stmt->execute([$hash, $albumName]);
    $original = $stmt->fetch();

    if ($original !== false && $fileIsDupe == 0) {
        $originalPath = realFilePath($SYNC_FOLDER . DIRECTORY_SEPARATOR . $original["album"] . DIRECTORY_SEPARATOR . $original["filename"]);

        if ($originalPath !== false && $ops->filesize($filePath) == $ops->filesize($originalPath)) {
            $fileIsDupe = 2;
        }
    }

    if ($fileIsDupe > 0) {
        $fileParts[2] = "dupe";
    }

    $oldExt = $ext;

    if (in_array(strtoupper($ext), $allowedVideos) && strtolower($ext) !== "mp4") {
        $ext = "mp4";
    }

    if (((in_array(strtoupper($ext), $allowedPhotos) && !$ops->is_file(dirname($filePath) . DIRECTORY_SEPARATOR . $filename . ".mp4")) || strtolower($ext) === "mp4")) {
        $fname = implode('---', $fileParts) . "." . $ext;
        $stmt = $pdo->prepare('SELECT * FROM media WHERE hash = ? AND album = ? AND filename LIKE "' . trim($fileParts[0],'-') . '%"  ORDER BY time ASC');
        $stmt->execute([$hash, $albumName, $fname]);
        $test = $stmt->fetch();

        if ($test === false) {
            $fname = implode('---', $fileParts) . "." . $ext;
            $date = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare('INSERT INTO media (dupe, album, hash, filename, time)
            VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$fileIsDupe, $albumName, $hash, $fname, $date]);
        }
    }

    $newPath = dirname($filePath) . DIRECTORY_SEPARATOR . implode('---', $fileParts) . "." . $oldExt;
    if($filePath !== $newPath) {
        echo PHP_EOL . "Moving: " . $filePath . ",  To: " . $newPath;
    }

    $ops->rename($filePath, $newPath);

    if ($fileIsDupe == 0) {
        if($DEBUG) {
            echo PHP_EOL . "File is Original";
        }

        if (in_array(strtoupper($ext), $allowedVideos)) {
            if($DEBUG) {
                echo PHP_EOL . "File is a Video";
            }

            $filename = basename($newPath, "." . $ext);
            $thumbnailPath = dirname($newPath) . DIRECTORY_SEPARATOR . $filename . ".jpg";
            $newFilePath = dirname($newPath) . DIRECTORY_SEPARATOR . $filename . ".mp4";

            if (!is_file($thumbnailPath) || !$ops->is_file($newFilePath)) {
                if($DEBUG) {
                    echo PHP_EOL . "File is New Video";
                }
                processVideoFile($newPath);
            }
        }
    }

    if ($fileIsDupe == 2) {
        $originalPath = $SYNC_FOLDER . DIRECTORY_SEPARATOR . $original["album"] . DIRECTORY_SEPARATOR . $original["filename"];
        $relativePath = basename(dirname($originalPath)) . DIRECTORY_SEPARATOR . basename($originalPath);

        if($DEBUG) {
            echo PHP_EOL . "Replacing contents of: " . $newPath . ", With: " . $relativePath;
        }
        $ops->file_put_contents($newPath, $relativePath);

        if (in_array(strtoupper($ext), $allowedVideos)) {
            $extension1 = pathinfo($originalPath, PATHINFO_EXTENSION);
            $extension2 = pathinfo($newPath, PATHINFO_EXTENSION);

            $thumbnailPath = dirname($newPath) . DIRECTORY_SEPARATOR . basename($newPath, "." . $extension2) . ".jpg";
            $relativethumbnailPath = basename(dirname($originalPath)) . DIRECTORY_SEPARATOR . basename($originalPath, "." . $extension1) . ".jpg";

            if($DEBUG) {
                echo PHP_EOL . "Replacing contents of: " . $thumbnailPath . ", With: " . $relativethumbnailPath;
            }

            $ops->file_put_contents($thumbnailPath, $relativethumbnailPath);
        }

        unset($originalPath);
        unset($original);
    }
}
