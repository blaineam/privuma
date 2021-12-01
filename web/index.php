<?php
//uncomment to allow app to reauth
//echo "[]";
//die();
ini_set('session.cookie_lifetime', 60 * 60 * 24 * 30);
ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 30);
date_default_timezone_set('America/Los_Angeles');

session_start();
include(__DIR__.'/helpers/dotenv.php');
loadEnv(__DIR__ . '/config/.env');
$SYNC_FOLDER = __DIR__ .'/data/privuma';
$FALLBACK_ENDPOINT = get_env('FALLBACK_ENDPOINT');
$ENDPOINT = get_env('ENDPOINT');
$AUTHTOKEN = get_env('AUTHTOKEN');
$USE_PCLOUD = get_env('USE_PCLOUD');
$PCLOUD_CLIENT_ID = get_env('PCLOUD_CLIENT_ID');
$PCLOUD_CLIENT_SECRET = get_env('PCLOUD_CLIENT_SECRET');
$USE_S3 = get_env('USE_S3');
$S3_PROXY = get_env('S3_PROXY');
$AWS_ACCESS = get_env('AWS_ACCESS_KEY');
$AWS_SECRET = get_env('AWS_SECRET_KEY');
$AWS_BUCKET = get_env('AWS_BUCKET');
$AWS_ENDPOINT = get_env('AWS_ENDPOINT');
$AWS_REGION = get_env('AWS_REGION');
$host = get_env('MYSQL_HOST');
$db   = get_env('MYSQL_DATABASE');
$user = get_env('MYSQL_USER');
$pass =  get_env('MYSQL_PASSWORD');

if(is_dir(__DIR__ . '/lib/pCloud/') && $USE_PCLOUD) {
    require_once(__DIR__ . '/lib/pCloud/autoload.php');

    if(isset($_GET['pcloud-auth'])) {
        $appKey=$PCLOUD_CLIENT_ID;
        $appSecret=$PCLOUD_CLIENT_SECRET;
        $redirect_uri="https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

        if(isset($_GET['code']) && isset($_GET['locationid'])) {
			$app = new pCloud\App();
			$app->setAppKey($appKey);
			$app->setAppSecret($appSecret);
			$app->setRedirectURI($redirect_uri);

			$token = $app->getTokenFromCode($_GET["code"], $_GET['locationid']);

            if(!file_put_contents(__DIR__."/.auth", json_encode($token))) {
                ?>
                <p>Please put the following text in a file at the privuma/web/.auth location</p>
                <pre>
                    <?= json_encode($token) ?>
                </pre>
                <?php
            }

            echo "Auth Token Saved. Please use your Privuma endpoint now.";
            exit();
        }


        try {
        
            $app = new pCloud\App();
            $app->setAppKey($appKey);
            $app->setAppSecret($appSecret);
            $app->setRedirectURI($redirect_uri);
        
            $codeUrl = $app->getAuthorizeCodeUrl();
        
            header('Location: '.$codeUrl);
            exit();
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }

}


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

function AWS_S3_PresignDownload($AWSAccessKeyId, $AWSSecretAccessKey, $BucketName, $canonical_uri, $AWSRegion = 'wasabi-us-west-1', $expires = 15 * 60) {
    $encoded_uri = str_replace('%2F', '/', rawurlencode($canonical_uri));
    if($AWSRegion == 'wasabi-us-west-1') {
        $AWSRegion = 'us-west-1';
        $hostname = trim($BucketName .".s3.us-west-1.wasabisys.com");
        $header_string = "host:" . $hostname . "\n";
        $signed_headers_string = "host";
    } else if($AWSRegion == 'us-east-1') {
        $hostname = trim($BucketName .".s3.amazonaws.com");
        $header_string = "host:" . $hostname . "\n";
        $signed_headers_string = "host";
    } else {
        $hostname =  trim($BucketName . ".s3-" . $AWSRegion . ".amazonaws.com");
        $header_string = "host:" . $hostname . "\n";
        $signed_headers_string = "host";
    }
    $timestamp = time();
    $date_text = gmdate('Ymd',  $timestamp);
    $time_text = gmdate('Ymd\THis\Z', $timestamp); 
    $algorithm = 'AWS4-HMAC-SHA256';
    $scope = $date_text . "/" . $AWSRegion . "/s3/aws4_request";
    
    $x_amz_params = array(
        'X-Amz-Algorithm' => $algorithm,
        'X-Amz-Credential' => $AWSAccessKeyId . '/' . $scope,
        'X-Amz-Date' => $time_text,
        'X-Amz-SignedHeaders' => $signed_headers_string
    );
    
    if ($expires > 0) {
        $x_amz_params['X-Amz-Expires'] = $expires;
    }

    ksort($x_amz_params);
    $query_string = "";
    foreach ($x_amz_params as $key => $value) {
        $query_string .= rawurlencode($key) . '=' . rawurlencode($value) . "&";
    }
    $query_string = substr($query_string, 0, -1);
    $canonical_request = "GET\n" . $encoded_uri . "\n" . $query_string . "\n" . $header_string . "\n" . $signed_headers_string . "\nUNSIGNED-PAYLOAD";
    $string_to_sign = $algorithm . "\n" . $time_text . "\n" . $scope . "\n" . hash('sha256', $canonical_request, false);
    $signing_key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $AWSRegion, hash_hmac('sha256', $date_text, 'AWS4' . $AWSSecretAccessKey, true), true), true), true);
    $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
    return 'https://' . $hostname . $encoded_uri . '?' . $query_string . '&X-Amz-Signature=' . $signature;
}

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

function getDirContents($dir, &$results = array())
{
    $files = scandir($dir);
    foreach ($files as $key => $value) {
        $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
        if (!is_dir($path)) {
            $results[] = $path;
        }
    }

    return $results;
}

function getDirs($dir, &$results = array())
{
    $files = scandir($dir);
    foreach ($files as $key => $value) {
        $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
        if (!is_dir($path)) {
        } else if (is_dir($path) && $value != "." && $value != "..") {
            $results[] = $path;
        }
    }

    return $results;
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
            foreach($files as $file) {
                $iext = pathinfo($file, PATHINFO_EXTENSION);
                if (strtolower($ext) == strtolower($iext)) {
                    return $file;
                }
            }
        } else {
        	
	        foreach($files as $file) {
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

function getS3UrlForMediaPath($path) {
    global $AWS_ACCESS;
    global $AWS_SECRET;
    global $AWS_BUCKET;
    global $AWS_REGION;
    return AWS_S3_PresignDownload($AWS_ACCESS, $AWS_SECRET, $AWS_BUCKET, '/data/privuma/'.$path, $AWS_REGION);
}

function getpcloudurlfrommediapath($path) {
    if(!is_file(__DIR__ . "/.auth")) {
        return "Please visit ?pcloud-auth to use pcloud mirroring.";
    }
    try {
        $token = json_decode(file_get_contents(__DIR__."/.auth"), true);
        $access_token = $token['access_token'];
        $locationid = $token['locationid'];

        $pCloudApp = new pCloud\App();
        $pCloudApp->setAccessToken($access_token);
        $pCloudApp->setLocationId($locationid);

        $pCloudFolder = new pCloud\Folder($pCloudApp);

        $pCloudFile = new pCloud\File($pCloudApp);


        $mediaFolderId = $pCloudFolder->listFolder("data/privuma/".dirname($path))["folderid"];

        foreach($pCloudFolder->getContent($mediaFolderId ) as $file) {
            if($file->name === basename($path)) {
                return $pCloudFile->getLink($file->fileid);
            }
        }

    } catch (Exception $e) {
        echo $e->getMessage();
    }
}

function streamMedia($file) {
    $head = array_change_key_case(get_headers($file, TRUE));
    header('Content-Type: ' . $head['content-type']);
    header('Content-Length:' . $head['content-length']);
    header('Accept-Ranges: bytes');
    header('Content-Disposition: inline');
    readfile($file);

    exit;
}


function run()
{
    global $conn;
    global $SYNC_FOLDER;
    global $ENDPOINT;
    global $AUTHTOKEN;
    global $USE_S3;
    global $S3_PROXY;
    global $USE_PCLOUD;

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

        $media = array_values($media);
        $photos = array("gtoken" => urlencode($ENDPOINT."...".$AUTHTOKEN), "gdata" => $media);
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");
        header('Content-Type: application/json');
        print(json_encode($photos, JSON_UNESCAPED_SLASHES));

    } else if (isset($_GET['media'])) {
        if ($USE_S3) {
            if(is_base64_encoded($_GET['media'])) {
                $_GET['media'] = base64_decode($_GET['media']);
            }

            if ($_GET['media'] === "blank.gif") {
                header('Content-Type: image/gif');
                echo base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw==');
                return;
            }

            $path = $_GET['media'];
            $url = getS3UrlForMediaPath(str_replace('-----', DIRECTORY_SEPARATOR, $path));
            $head = get_headers($url);
            if (strpos($head[0], '200') === FALSE) {
                header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
                header("Cache-Control: post-check=0, pre-check=0", false);
                header("Pragma: no-cache");
                header('Location: ' . getProtectedUrlForMediaPath($path, true));
                die();
            }

            if ($S3_PROXY) {
                streamMedia($url);
            } else {
                header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
                header("Cache-Control: post-check=0, pre-check=0", false);
                header("Pragma: no-cache");
                header('Location: ' . getS3UrlForMediaPath(str_replace('-----', DIRECTORY_SEPARATOR, $_GET['media'])));
                die();
            }
        }


        if (is_dir(__DIR__ . '/lib/pCloud/') && $USE_PCLOUD) {
            if(is_base64_encoded($_GET['media'])) {
                $_GET['media'] = base64_decode($_GET['media']);
            }

            if ($_GET['media'] === "blank.gif") {
                header('Content-Type: image/gif');
                echo base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw==');
                return;
            }

            $path = $_GET['media'];
            $url = getpcloudurlfrommediapath(str_replace('-----', DIRECTORY_SEPARATOR, $path));
            $head = get_headers($url);
            if (strpos($head[0], '200') === FALSE) {
                header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
                header("Cache-Control: post-check=0, pre-check=0", false);
                header("Pragma: no-cache");
                header('Location: ' . getProtectedUrlForMediaPath($path, true));
                die();
            }
            
            streamMedia($url);
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

        $mediaPath = str_replace('-----', DIRECTORY_SEPARATOR, $_GET['media']);
        $file = realFilePath($SYNC_FOLDER . DIRECTORY_SEPARATOR . $mediaPath);
        $ext = pathinfo($mediaPath, PATHINFO_EXTENSION);
        $album = explode(DIRECTORY_SEPARATOR, $mediaPath)[0];
        if (!is_file($file)) {
            die('Media file not found' . $file);
        }
        
        $mediaPath = basename(dirname($file)) . DIRECTORY_SEPARATOR . basename($file);
        if (strpos($mediaPath, "---dupe") !== false && filesize($mediaPath) <= 512) {
            $mediaPath = file_get_contents($file);
            $file = realFilePath($SYNC_FOLDER . DIRECTORY_SEPARATOR . $mediaPath);
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . mime_content_type($file));
        header('Content-Disposition: inline; filename=' . basename($file));
        header('Content-Transfer-Encoding: binary');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        header("X-Accel-Redirect: /data/privuma/" . $mediaPath);

        ob_clean();
        flush();
        exit;
    } else {
        $result = $conn->query('select filename, album, max(time) as time, hash FROM media where dupe = 0 GROUP by album order by time DESC;');
        $realbums = [];
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
