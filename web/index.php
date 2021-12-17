<?php
//uncomment to allow app to reauth
//echo "[]";
//die();
ini_set('session.cookie_lifetime', 60 * 60 * 24 * 30);
ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 30);
date_default_timezone_set('America/Los_Angeles');

session_start();

require_once(__DIR__.'/helpers/dotenv.php');
loadEnv(__DIR__ . '/config/.env');
require(__DIR__ . '/helpers/cloud-fs-operations.php'); 

$ops = new cloudFS\Operations();


$USE_MIRROR= get_env('MIRROR_FILES');
$RCLONE_MIRROR = get_env('RCLONE_MIRROR');
$opsMirror = new cloudFS\Operations($RCLONE_MIRROR, true);

$SYNC_FOLDER = '/data/privuma';
$FALLBACK_ENDPOINT = get_env('FALLBACK_ENDPOINT');
$ENDPOINT = get_env('ENDPOINT');
$AUTHTOKEN = get_env('AUTHTOKEN');
$RCLONE_DESTINATION = get_env('RCLONE_DESTINATION');
$USE_X_Accel_Redirect = get_env('USE_X_Accel_Redirect');

// header("Content-Type: application/json");
// echo json_encode(array_column(get_data_dirs(dirname($SYNC_FOLDER)),'Path'), JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES);
// die();

$host = get_env('MYSQL_HOST');
$db   = get_env('MYSQL_DATABASE');
$user = get_env('MYSQL_USER');
$pass =  get_env('MYSQL_PASSWORD');

$charset = 'utf8mb4';
$port = 3306;

$conn = new mysqli(
    $host,
    $user,
    $pass,
    $db,
    $port
);

if ($conn->connect_error) {
    die("DB Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8");
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


function rollingTokens($seed) {
    $d1 = new \DateTime("yesterday");
    $d2 = new \DateTime("today");
    $d3 = new \DateTime("tomorrow");
    return [
        sha1(md5($d1->format('Y-m-d'))."-".$seed),
        sha1(md5($d2->format('Y-m-d'))."-".$seed),
        sha1(md5($d3->format('Y-m-d'))."-".$seed),
    ];
};

function checkToken($token, $seed) {
    return in_array($token, rollingTokens($seed));
}

function is_base64_encoded($data)
{
    if (preg_match('%^[a-zA-Z0-9/+]*={0,2}$%', $data)) {
       return TRUE;
    } else {
       return FALSE;
    }
};

if (session_status() == PHP_SESSION_ACTIVE && isset($_SESSION['SessionAuth']) && $_SESSION['SessionAuth'] === $AUTHTOKEN) {
    run();
} else if (isset($_GET['AuthToken']) && $_GET['AuthToken'] === $AUTHTOKEN) {
    $_SESSION['SessionAuth'] = $_GET['AuthToken'];
    run();
} else if(isset($_GET['token']) && checkToken($_GET['token'], $AUTHTOKEN)) {
    run();
} else {
    die("Malformed Request");
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

function realFilePath($filePath, $dirnamed_sync_folder = false)
{
    global $SYNC_FOLDER;
    global $ops;

    $root = $dirnamed_sync_folder ? dirname($SYNC_FOLDER) : $SYNC_FOLDER;

    $ext = pathinfo($filePath, PATHINFO_EXTENSION);

    $filename = basename($filePath, "." . $ext);
    $album = normalizeString(basename(dirname($filePath)));

    $filePath = $root . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . $filename . "." . $ext;
    $compressedFile = $root . DIRECTORY_SEPARATOR . $album  . DIRECTORY_SEPARATOR . $filename . "---compressed." . $ext;

    $dupe = $root . DIRECTORY_SEPARATOR . $album  . DIRECTORY_SEPARATOR . $filename . "---dupe." . $ext;
                
    $files = $ops->glob($root . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . explode('---', $filename)[0]. "*.*");
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

function getProtectedUrlForMediaPath($path, $use_fallback = false) {
    global $ENDPOINT;
    global $FALLBACK_ENDPOINT;
    global $AUTHTOKEN;
    $uri = "?token=" . rollingTokens($AUTHTOKEN)[1]  . "&media=" . urlencode(base64_encode($path));
    return $use_fallback ? $FALLBACK_ENDPOINT . $uri : $ENDPOINT . $uri;
}

function streamMedia($file, bool $useOps = false) {
    global $ops;
    header('Accept-Ranges: bytes');
    header('Content-Disposition: inline');
    if($useOps){
        header('Content-Type: ' . get_mime_by_filename($file));
        header('Content-Length:' . $ops->filesize($file));
        $ops->readfile($file);
    }else {
        $head = array_change_key_case(get_headers($file, TRUE));
        header('Content-Type: ' . $head['content-type']);
        header('Content-Length:' . $head['content-length']);
        readfile($file);
    }
    exit;
}


function run()
{
    global $conn;
    global $SYNC_FOLDER;
    global $ENDPOINT;
    global $AUTHTOKEN;
    global $USE_MIRROR;
    global $ops;
    global $opsMirror;
    global $RCLONE_MIRROR;
    global $USE_X_Accel_Redirect;

    if (isset($_GET['album']) || isset($_GET['amp;album'])) {
        $albumName = $_GET['album'] ?? $_GET['amp;album'];

        if(is_base64_encoded($albumName)) {
            $albumName = base64_decode($albumName);
        }

        $stmt = $conn->prepare('select filename, album, time, hash 
        from media 
        where hash in 
        (select hash from media where album = ? and dupe != 0 and hash != "compressed") 
        and dupe = 0
        union ALL 
        select filename, album, time, hash
        from media 
        WHERE 
        album = ? and dupe = 0 and hash != "compressed"
        group by filename
         order by
            CASE
                WHEN filename LIKE "%.gif" THEN 4
                WHEN filename LIKE "%.mp4" THEN 3
                WHEN filename LIKE "%.webm" THEN 2
                ELSE 1
            END DESC,
            time DESC
        ');

        $stmt->bind_param("ss", $albumName, $albumName);
        $stmt->execute();
        $result = $stmt->get_result();

        $media = [];
        while ($item = $result->fetch_assoc()) {
            if (!isset($item["filename"])) {
                continue;
            }

            $ext = pathinfo($item["filename"], PATHINFO_EXTENSION);
            $filename = basename($item["filename"], "." . $ext);
            $filePath = $SYNC_FOLDER . DIRECTORY_SEPARATOR . normalizeString($item['album']) . DIRECTORY_SEPARATOR . $item["filename"];
            $fileParts = explode('---', basename($filePath, "." . $ext));
            $hash = $fileParts[1];
            $relativePath = normalizeString($item['album']) . "-----" . basename($filePath);

            if (strtolower($ext) === "mp4") {
                $videoPath = (getProtectedUrlForMediaPath($relativePath));
                $relativeThumbnail = $item['album'] . "-----" . basename($filePath, ".".$ext) . ".jpg";
                $photoPath = (getProtectedUrlForMediaPath($relativeThumbnail));
            } else {
                $photoPath = (getProtectedUrlForMediaPath($relativePath));
            }

            $mime = (isset($videoPath)) ? "video/mp4": ((strtolower($ext) === "gif") ? "image/gif" :  ((strtolower($ext) === "png") ? "image/png" : "image/jpg")) ;
            if (!array_key_exists($hash, $media)) {
                $media[$hash] = array("img" => $photoPath ?? "", "updated" => strtotime($item["time"]), "video" => $videoPath ?? "", "id" => (string)$hash, "filename" => (string)$fileParts[0], "mime" => (string)$mime, "epoch" => strtotime($item["time"]));
            } elseif (isset($videoPath)) {
                $media[$hash]["video"] = $videoPath;
            } elseif (isset($photoPath)) {
                $media[$hash]["img"] = $photoPath;
            }

            unset($videoPath);
            unset($photoPath);
        }

        if(empty($media)) {
            $albumFSPath = str_replace('-----', DIRECTORY_SEPARATOR, $albumName);
            $scan = $ops->scandir(dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $albumFSPath, false, false, [...array_map(function($ext) {
                return '+ *.' . $ext;
            }, ['mp4','jpg','jpeg','gif','png','heif']), '-**']);
            if($scan === false) {
                $scan = [];
            }
            natcasesort($scan);
            foreach(array_diff($scan, ['.','..']) as $file) {
                $filePath = dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $albumFSPath . DIRECTORY_SEPARATOR . $file;

                $ext = pathinfo($file, PATHINFO_EXTENSION);
                $filename = basename($file, "." . $ext);

                $videoPathTest = dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $albumFSPath . DIRECTORY_SEPARATOR . $filename .".mp4";
                $thumbailPathTest = dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $albumFSPath . DIRECTORY_SEPARATOR . $filename .".jpg";
                $hash = md5(dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $albumFSPath . DIRECTORY_SEPARATOR . $filename);
                $fileParts = explode('---', basename($filePath, "." . $ext));
                $relativePath = str_replace(DIRECTORY_SEPARATOR, '-----', $filePath);

                if (strtolower($ext) === "mp4") {
                    $videoPath = (getProtectedUrlForMediaPath($relativePath));
                    $relativeThumbnail = str_replace(DIRECTORY_SEPARATOR, '-----', $thumbailPathTest);
                    $photoPath = (getProtectedUrlForMediaPath($relativeThumbnail));
                } else if($ops->is_file($videoPathTest)) {
                    $relativeVideo = str_replace(DIRECTORY_SEPARATOR, '-----', $videoPathTest);
                    $videoPath = (getProtectedUrlForMediaPath($relativeVideo));
                    $photoPath = (getProtectedUrlForMediaPath($relativePath));
                } else {
                    unset($videoPath);
                    $photoPath = (getProtectedUrlForMediaPath($relativePath));
                }

                $mime = (isset($videoPath)) ? "video/mp4": ((strtolower($ext) === "gif") ? "image/gif" :  ((strtolower($ext) === "png") ? "image/png" : "image/jpg")) ;

                $media[$hash] = array("img" => $photoPath ?? "", "updated" => 1, "video" => $videoPath ?? "", "id" => (string)$hash, "filename" => (string)$fileParts[0], "mime" => (string)$mime, "epoch" => 1);
            }
        }

        $media = array_values($media);
        $photos = array("gtoken" => urlencode($ENDPOINT."...".$AUTHTOKEN), "gdata" => $media);
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");
        header('Content-Type: application/json');
        print(json_encode($photos, JSON_UNESCAPED_SLASHES));

    } else if (isset($_GET['media'])) {
        if(is_base64_encoded($_GET['media'])) {
            $_GET['media'] = base64_decode($_GET['media']);
        }

        if ($_GET['media'] === "blank.gif") {
            header('Content-Type: image/gif');
            echo base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw==');
            return;
        }

        $mediaPath = str_replace('..', '', str_replace(DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR,DIRECTORY_SEPARATOR,str_replace('-----', DIRECTORY_SEPARATOR, $_GET['media'])));

        if($USE_X_Accel_Redirect && is_file(__DIR__ .  DIRECTORY_SEPARATOR . ltrim($ops->encode($mediaPath), DIRECTORY_SEPARATOR))){
            header('Content-Type: ' . mime_content_type(__DIR__ .  DIRECTORY_SEPARATOR . ltrim($ops->encode($mediaPath), DIRECTORY_SEPARATOR)));
        	header('X-Accel-Redirect: ' . DIRECTORY_SEPARATOR . ltrim($ops->encode($mediaPath), DIRECTORY_SEPARATOR));
            die();
        }

        if($USE_MIRROR && strpos($RCLONE_MIRROR, ':') !== false && !isset($_GET['direct'])){
            $path = $_GET['media'];
            $realFile = realFilePath(str_replace('-----', DIRECTORY_SEPARATOR, $path));

            $mediaPath = str_replace('..', '', str_replace(DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR,DIRECTORY_SEPARATOR,str_replace('-----', DIRECTORY_SEPARATOR, $_GET['media'])));
            $file = realFilePath($SYNC_FOLDER . DIRECTORY_SEPARATOR . $mediaPath);
    
            if($file === false) {
                $file =  DIRECTORY_SEPARATOR . ltrim($ops->encode($mediaPath), DIRECTORY_SEPARATOR);
            }

            $url = $opsMirror->public_link($file);
            if($url === false) {
            	  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
                header("Cache-Control: post-check=0, pre-check=0", false);
                header("Pragma: no-cache");
                header('Location: ' . getProtectedUrlForMediaPath($path, true) . '&direct');
                die();

            }
            
            $headers = get_headers($url, TRUE);
            $head = array_change_key_case($headers);
            if ( strpos($headers[0], '200') === FALSE || $head['content-type'] !== $ops->mime_content_type(DIRECTORY_SEPARATOR .'data'.DIRECTORY_SEPARATOR . 'privuma' . DIRECTORY_SEPARATOR . basename(dirname($realFile)).'/'.basename($realFile))) {
                header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
                header("Cache-Control: post-check=0, pre-check=0", false);
                header("Pragma: no-cache");
                header('Location: ' . getProtectedUrlForMediaPath($path, true) . '&direct');
                die();
            }


            header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
            header("Cache-Control: post-check=0, pre-check=0", false);
            header("Pragma: no-cache");
            header('Location: ' . $url);
            die();


        }

        if (strpos($ENDPOINT, $_SERVER['HTTP_HOST']) == false ){
            header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
            header("Cache-Control: post-check=0, pre-check=0", false);
            header("Pragma: no-cache");
            header('Location: ' . $ENDPOINT . "?token=" . rollingTokens($_SESSION['SessionAuth'])[1]  . "&media=" . urlencode($_GET['media']));
            die();
        }

        if(is_base64_encoded($_GET['media'])) {
            $_GET['media'] = base64_decode($_GET['media']);
        }

        if ($_GET['media'] === "blank.gif") {
            header('Content-Type: image/gif');
            echo base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw==');
            return;
        }

        $file = realFilePath($SYNC_FOLDER . DIRECTORY_SEPARATOR . $mediaPath);

        if($file === false) {
            $file =  DIRECTORY_SEPARATOR . ltrim($ops->encode($mediaPath), DIRECTORY_SEPARATOR);
        }

        $ext = pathinfo($mediaPath, PATHINFO_EXTENSION);
        $album = explode(DIRECTORY_SEPARATOR, $mediaPath)[0];
        if (!$ops->is_file($file)) {
            die('Media file not found' . $file);
        }
        
        $mediaPath = basename(dirname($file)) . DIRECTORY_SEPARATOR . basename($file);
        if (strpos($mediaPath, "---dupe") !== false && $ops->filesize($mediaPath) <= 512) {
            $mediaPath = $ops->file_get_contents($file);
            $file = realFilePath($SYNC_FOLDER . DIRECTORY_SEPARATOR . $mediaPath);
        }

        if (!$ops->is_file($file)) {
            die('Media file not found' . $file);
        }
        
        if ($USE_X_Accel_Redirect){
        	header('X-Accel-Redirect: ' . $ops->encode($file));
            die();
        }

        streamMedia($file, true);
    } else {
        $realbums = [];

        foreach(get_data_dirs(dirname($SYNC_FOLDER)) as $folderObj) {
            if($folderObj['HasThumbnailJpg']) {
                $ext = pathinfo($folderObj["Name"], PATHINFO_EXTENSION);
                $hash = md5(dirname($folderObj['Path']) . DIRECTORY_SEPARATOR . basename($folderObj['Path'], "." . $ext));
                $photoPath = $ENDPOINT . "?token=" . rollingTokens($_SESSION['SessionAuth'])[1]  . "&media=".urlencode(base64_encode(str_replace(DIRECTORY_SEPARATOR, '-----', dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $folderObj['Path']) . "-----" . "1.jpg"));
            } else {
                $photoPath = $ENDPOINT . "?token=" . rollingTokens($_SESSION['SessionAuth'])[1]  . "&media=blank.gif";;
                $hash = "checkCache";
            }
            $realbums[] = array("id" => (string)urlencode(base64_encode(implode('-----', explode(DIRECTORY_SEPARATOR, $folderObj['Path'])))), "updated" => (string)(strtotime(explode('.', $folderObj['ModTime'])[0])*1000), "title" => (string)implode('---', explode(DIRECTORY_SEPARATOR, $folderObj['Path'])), "img" => (string)$photoPath , "mediaId" => (string)$hash);
        }
        $result = $conn->query('select filename, album, max(time) as time, hash FROM media where dupe = 0 GROUP by album order by time DESC;');
        while ($album = $result->fetch_assoc()) {
            $ext = pathinfo($album["filename"], PATHINFO_EXTENSION);
            $filename = basename($album["filename"], "." . $ext);
            $filePath = $album['album'] . DIRECTORY_SEPARATOR . $album["filename"];
            $relativePath = $album['album'] . "-----" . basename($filePath);
            if (strtolower($ext) === "mp4") {
                $relativeThumbnail = $album['album'] . "-----" . basename($filePath, ".".$ext) . ".jpg";
                $photoPath = (getProtectedUrlForMediaPath($relativeThumbnail));
            } else {
                $photoPath = (getProtectedUrlForMediaPath($relativePath));
            }
            
            if (empty($photoPath)) {
                $photoPath = $ENDPOINT . "?token=" . rollingTokens($_SESSION['SessionAuth'])[1]  . "&media=blank.gif";
            }

            $realbums[] = array("id" => (string)urlencode(base64_encode($album["album"])), "updated" => (string)(strtotime($album["time"])*1000), "title" => (string)$album["album"], "img" => (string)$photoPath , "mediaId" => (string)$album["hash"]);
        }
        $realbums = array("gtoken" => urlencode($ENDPOINT."...".$AUTHTOKEN), "gdata" => $realbums);
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");
        header('Content-Type: application/json');
        print(json_encode($realbums, JSON_UNESCAPED_SLASHES, 10));
        exit();
    }
}

function get_data_dirs($dir)
{
    global $SYNC_FOLDER;
    global $ops;
    $scans = $ops->scandir($dir, true, true, ["+.mediadir", "+1.jpg", "-" . basename($SYNC_FOLDER) . "/**", "-@eaDir/**", "-**"], false, true);
    $paths = array_column($scans, "Path");
    array_multisort ($paths, SORT_NATURAL, $scans);
    $output = [];
    foreach($scans as $scan) {

        if($scan['Name'] === ".mediadir") {
            $scan['Path'] = dirname($scan['Path']);
        }

        if(!isset($output[$scan['Path']]['HasThumbnailJpg'])){
            $scan['HasThumbnailJpg'] = false;
        }
        if($scan['Name'] === "1.jpg") {
            $scan['Path'] = dirname($scan['Path']);
            $scan['HasThumbnailJpg'] = true;
        }

        if(isset($output[rtrim(dirname($scan['Path']), DIRECTORY_SEPARATOR)])) {
            unset($output[rtrim(dirname($scan['Path']), DIRECTORY_SEPARATOR)]);
        }
        $output[$scan['Path']] = $scan;
    }
    return $output;
}

function get_mime_by_filename($filename) {
    if (!is_file(__DIR__ .'/mimes.json')) {
       $db = json_decode(file_get_contents('https://cdn.jsdelivr.net/gh/jshttp/mime-db@master/db.json'), true);
       $mime_types = [];
       foreach ($db as $mime => $data) {
           if (!isset($data['extensions'])) {
               continue;
           }
           foreach ($data['extensions'] as $extension) {
               $mime_types[$extension] = $mime;
           }
       }

       file_put_contents(__DIR__ .'/mimes.json', json_encode($mime_types, JSON_PRETTY_PRINT));
    }

    $mime_types = json_decode(file_get_contents(__DIR__ .'/mimes.json'), true);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (array_key_exists($ext, $mime_types)) {
        return $mime_types[$ext];
    }
    else {
        return 'application/octet-stream';
    }
}