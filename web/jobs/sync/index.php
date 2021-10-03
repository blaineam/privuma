<?php
ini_set('mysql.connect_timeout', 3600);
ini_set('default_socket_timeout', 3600);
$SYNC_FOLDER = __DIR__ . "/../../data/privuma/";
$DEBUG = false;
$ffmpegThreadCount = 1;
$ffmpegPath = "/usr/bin/ffmpeg";
include(__DIR__.'/../../helpers/dotenv.php');
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
    exec("ls -t '" . $dir . "'", $files);
    return ($files) ? $files : false;
}

function getDirContents($dir, &$results = array())
{
    global $pdo;
    global $DEBUG;
    $files = scan_dir($dir);
    $queue = [];
    foreach ($files as $key => $value) {
        $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
        if (!is_dir($path)) {
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
            getDirContents($path);
        }
    }

    if($DEBUG) {
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
            $hash = md5_file($path);
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
    global $ffmpegPath;
    global $pdo;
    global $DEBUG;
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    $filename = basename($filePath, "." . $ext);
    $thumbnailPath = dirname($filePath) . DIRECTORY_SEPARATOR . $filename . ".jpg";
    $newFilePath = dirname($filePath) . DIRECTORY_SEPARATOR . $filename . "---compressed.mp4";
    exec("$ffmpegPath -threads $ffmpegThreadCount -hide_banner -loglevel error -y -i '" . $filePath . "' -vcodec mjpeg -vframes 1 -an -f rawvideo -ss `$ffmpegPath -threads $ffmpegThreadCount -y -i '" . $filePath . "' 2>&1 | grep Duration | awk '{print $2}' | tr -d , | awk -F ':' '{print ($3+$2*60+$1*3600)/2}'` '" . $thumbnailPath . "' > /dev/null", $void, $response);
    if (strtolower($ext) == "mp4") {

        if($DEBUG) {
            echo PHP_EOL . "File is the correct format already";
        }

        exec("$ffmpegPath -threads $ffmpegThreadCount -hide_banner -loglevel error -y -i '" . $filePath . "' -c copy -map 0 -movflags +faststart'" . $newFilePath . "-fast.mp4" . "'", $void, $response3);
        if ($response3 == 0 && is_file($newFilePath . "-fast.mp4")) {
            unlink($newFilePath);
            rename($newFilePath . "-fast.mp4", $newFilePath);
        }
        return;
    }

    exec("$ffmpegPath -threads $ffmpegThreadCount -hide_banner -loglevel error -y -fflags +genpts -i '" . $filePath . "' -c:v h264 -r 24 -crf 24 -c:a aac -movflags frag_keyframe+empty_moov  -vf \"scale='min(1920,iw+mod(iw,2))':'min(1080,ih+mod(ih,2)):flags=neighbor'\" '" . $newFilePath . "'", $void, $response2);

    if ($response == 0 && $response2 == 0) {
        if($DEBUG) {
            echo PHP_EOL . "Video Conversion Was Successful for: " . $filename;
        }
        exec("$ffmpegPath -threads $ffmpegThreadCount -hide_banner -loglevel error -y -i '" . $newFilePath . "' -c copy -map 0 -movflags +faststart '" . $newFilePath . "-fast.mp4'", $void, $response3);
        if ($response3 == 0 && is_file($newFilePath . "-fast.mp4")) {
            unlink($newFilePath);
            rename($newFilePath . "-fast.mp4", $newFilePath);
        }

        unlink($filePath);
    } else {
            echo PHP_EOL."Video Conversion Failed: " . $filename;
            $filenameParts = explode("---", $filename);
            $hash = $filenameParts[1];
            $album = basename(dirname($filePath));
            $stmt = $pdo->prepare('SELECT * FROM media WHERE hash = ? AND album = ? AND MATCH(filename) AGAINST("' . trim($filenameParts[0],'-') . '")');
            $stmt->execute([$hash, $album]);
            unlink($filePath);
            unlink($thumbnailPath);
    
        }
    
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
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);

    $filename = basename($filePath, "." . $ext);
    $album = normalizeString(basename(dirname($filePath)));

    $filePath = $SYNC_FOLDER . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . $filename . "." . $ext;
    $compressedFile = $SYNC_FOLDER . DIRECTORY_SEPARATOR . $album  . DIRECTORY_SEPARATOR . $filename . "---compressed." . $ext;

    $dupe = $SYNC_FOLDER . DIRECTORY_SEPARATOR . $album  . DIRECTORY_SEPARATOR . $filename . "---dupe." . $ext;
                
    $files = glob($SYNC_FOLDER . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . explode('---', $filename)[0]. "*.*");
    if (is_file($filePath)) {
        return $filePath;
    } else if (is_file($compressedFile)) {
        return $compressedFile;
    } else if (is_file($dupe)) {
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
    global $SYNC_FOLDER;

    /* check if server is alive */
    if ($conn->ping()) {
    } else {
        echo "Error: " . $conn->error;
        exit(1);
    }

    if (is_dir($filePath)) {
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
        unlink($filePath);
        return;
    }

    $filename = basename($filePath, "." . $ext);
    $albumName = basename(dirname($filePath));
    $fileIsDupe = strpos($filename, '---dupe') !== false ? 1 : 0;
    $fileParts = explode('---', $filename);
    if (count($fileParts) > 1 && $fileParts[1] !== "compressed" && !empty($fileParts[1])) {
        $hash = $fileParts[1];
    } else {
        $hash = md5_file($filePath);

        if ($hash == 'd41d8cd98f00b204e9800998ecf8427e' || filesize($filePath) == 0) {
            echo PHP_EOL."Found Empty File: " . $filePath;
            if (is_file($filePath)){
                unlink($filePath);
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

        if ($originalPath !== false && filesize($filePath) == filesize($originalPath)) {
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

    if (((in_array(strtoupper($ext), $allowedPhotos) && !is_file(dirname($filePath) . DIRECTORY_SEPARATOR . $filename . ".mp4")) || strtolower($ext) === "mp4")) {
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

    rename($filePath, $newPath);

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

            if (!is_file($thumbnailPath) || !is_file($newFilePath)) {
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
        file_put_contents($newPath, $relativePath);

        if (in_array(strtoupper($ext), $allowedVideos)) {
            $extension1 = pathinfo($originalPath, PATHINFO_EXTENSION);
            $extension2 = pathinfo($newPath, PATHINFO_EXTENSION);

            $thumbnailPath = dirname($newPath) . DIRECTORY_SEPARATOR . basename($newPath, "." . $extension2) . ".jpg";
            $relativethumbnailPath = basename(dirname($originalPath)) . DIRECTORY_SEPARATOR . basename($originalPath, "." . $extension1) . ".jpg";

            if($DEBUG) {
                echo PHP_EOL . "Replacing contents of: " . $thumbnailPath . ", With: " . $relativethumbnailPath;
            }

            file_put_contents($thumbnailPath, $relativethumbnailPath);
        }

        unset($originalPath);
        unset($original);
    }
}