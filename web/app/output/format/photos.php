<?php

namespace privuma\output\format;

//uncomment to allow app to reauth
//echo "[]";
//die();
/* ini_set('session.cookie_lifetime', 60 * 60 * 24 * 30); */
/* ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 30); */
date_default_timezone_set('America/Los_Angeles');

session_start();

use privuma\privuma;
use privuma\helpers\cloudFS;
use privuma\helpers\tokenizer;
use privuma\helpers\mediaFile;

$ops = privuma::getCloudFS();

$privuma = privuma::getInstance();

$tokenizer = new tokenizer();
$USE_MIRROR = privuma::getEnv('USE_MIRROR');
$RCLONE_MIRROR = privuma::getEnv('RCLONE_MIRROR');
$DEOVR_MIRROR = privuma::getEnv('DEOVR_MIRROR');
$DEOVR_USE_CLOUDFS_HTTP_ENDPOINT = privuma::getEnv(
    'DEOVR_USE_CLOUDFS_HTTP_ENDPOINT'
);
$CLOUDFS_HTTP_ENDPOINT = privuma::getEnv('CLOUDFS_HTTP_ENDPOINT');
$opsMirror = new cloudFS($RCLONE_MIRROR);

$SYNC_FOLDER = '/data/privuma';
$FALLBACK_ENDPOINT = privuma::getEnv('FALLBACK_ENDPOINT');
$ENDPOINT = privuma::getEnv('ENDPOINT');
$AUTHTOKEN = privuma::getEnv('AUTHTOKEN');
$RCLONE_DESTINATION = privuma::getEnv('RCLONE_DESTINATION');
$USE_X_Accel_Redirect = privuma::getEnv('USE_X_Accel_Redirect');
$STREAM_MEDIA_FROM_FALLBACK_ENDPOINT = privuma::getEnv(
    'STREAM_MEDIA_FROM_FALLBACK_ENDPOINT'
);
$USE_CLOUDFS_NGINX_AUTH_FOR_MEDIA = privuma::getEnv(
    'USE_CLOUDFS_NGINX_AUTH_FOR_MEDIA'
);

$rcloneConfig = parse_ini_file(
    privuma::getConfigDirectory() .
      DIRECTORY_SEPARATOR .
      'rclone' .
      DIRECTORY_SEPARATOR .
      'rclone.conf',
    true
);

$sqlFilter = "(album = 'Favorites' or blocked = 0)";
$sqlFilter = !isset($_GET['unfiltered']) ? $sqlFilter : '';

function isUrl($path): bool
{
    return filter_var(idn_to_ascii($path), FILTER_VALIDATE_URL) ||
      filter_var($path, FILTER_VALIDATE_URL);
}

function RClone_S3_PresignedURL(
    $AWSAccessKeyId,
    $AWSSecretAccessKey,
    $BucketName,
    $AWSRegion,
    $canonical_uri,
    $S3Endpoint = null,
    $expires = 86400
) {
    $encoded_uri = str_replace('%2F', '/', rawurlencode($canonical_uri));
    // Specify the hostname for the S3 endpoint
    if (!is_null($S3Endpoint)) {
        $hostname = trim(
            str_replace('https://', '', str_replace('http://', '', $S3Endpoint))
        );
        $encoded_uri = '/' . $BucketName . $encoded_uri;
        $header_string = 'host:' . $hostname . "\n";
        $signed_headers_string = 'host';
    } elseif ($AWSRegion == 'us-east-1') {
        $hostname = trim($BucketName . '.s3.amazonaws.com');
        $header_string = 'host:' . $hostname . "\n";
        $signed_headers_string = 'host';
    } else {
        $hostname = trim($BucketName . '.s3-' . $AWSRegion . '.amazonaws.com');
        $header_string = 'host:' . $hostname . "\n";
        $signed_headers_string = 'host';
    }

    $currentTime = time();
    $date_text = gmdate('Ymd', $currentTime);

    $time_text = $date_text . 'T' . gmdate('His', $currentTime) . 'Z';
    $algorithm = 'AWS4-HMAC-SHA256';
    $scope = $date_text . '/' . $AWSRegion . '/s3/aws4_request';

    $x_amz_params = [
      'X-Amz-Algorithm' => $algorithm,
      'X-Amz-Credential' => $AWSAccessKeyId . '/' . $scope,
      'X-Amz-Date' => $time_text,
      'X-Amz-SignedHeaders' => $signed_headers_string,
    ];

    // 'Expires' is the number of seconds until the request becomes invalid
    $x_amz_params['X-Amz-Expires'] = $expires + 30; // 30seocnds are less
    ksort($x_amz_params);

    $query_string = '';
    foreach ($x_amz_params as $key => $value) {
        $query_string .= rawurlencode($key) . '=' . rawurlencode($value) . '&';
    }

    $query_string = substr($query_string, 0, -1);

    $canonical_request =
      "GET\n" .
      $encoded_uri .
      "\n" .
      $query_string .
      "\n" .
      $header_string .
      "\n" .
      $signed_headers_string .
      "\nUNSIGNED-PAYLOAD";
    $string_to_sign =
      $algorithm .
      "\n" .
      $time_text .
      "\n" .
      $scope .
      "\n" .
      hash('sha256', $canonical_request, false);

    $signing_key = hash_hmac(
        'sha256',
        'aws4_request',
        hash_hmac(
            'sha256',
            's3',
            hash_hmac(
                'sha256',
                $AWSRegion,
                hash_hmac('sha256', $date_text, 'AWS4' . $AWSSecretAccessKey, true),
                true
            ),
            true
        ),
        true
    );

    $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
    return 'https://' .
      $hostname .
      $encoded_uri .
      '?' .
      $query_string .
      '&X-Amz-Signature=' .
      $signature;
}

function redirectToMedia($path)
{
    global $ops;
    global $USE_X_Accel_Redirect;
    global $STREAM_MEDIA_FROM_FALLBACK_ENDPOINT;
    global $USE_CLOUDFS_NGINX_AUTH_FOR_MEDIA;
    global $USE_MIRROR;
    global $tokenizer;
    global $AUTHTOKEN;
    global $ENDPOINT;
    $path = $ops->encode($path);

    if ($USE_CLOUDFS_NGINX_AUTH_FOR_MEDIA) {
        $url =
          $ENDPOINT .
          'cloudfs' .
          $path .
          '?key=' .
          $tokenizer->rollingTokens($AUTHTOKEN)[1];
        header("Location: {$url}");
        die();
    }

    if ($STREAM_MEDIA_FROM_FALLBACK_ENDPOINT) {
        $url = getProtectedUrlForMediaPath($path, true, true);
        header("Location: {$url}");
        die();
    }

    if ($USE_X_Accel_Redirect && !$USE_MIRROR) {
        header(
            'Content-Type: ' .
              get_mime_by_filename(
                  privuma::canonicalizePath(ltrim($path, DIRECTORY_SEPARATOR))
              )
        );
        header(
            'X-Accel-Redirect: ' .
              DIRECTORY_SEPARATOR .
              privuma::canonicalizePath(ltrim($path, DIRECTORY_SEPARATOR))
        );
        die();
    }

    if ($USE_MIRROR) {
        global $RCLONE_MIRROR;
        global $DEOVR_MIRROR;
        global $rcloneConfig;
        global $DEOVR_USE_CLOUDFS_HTTP_ENDPOINT;
        global $CLOUDFS_HTTP_ENDPOINT;
        $mirror_parts = explode(
            ':',
            isset($_GET['deovr']) ? $DEOVR_MIRROR ?? $RCLONE_MIRROR : $RCLONE_MIRROR
        );
        $rclone_config_key = $mirror_parts[0];
        $bucket = explode(
            DIRECTORY_SEPARATOR,
            trim($mirror_parts[1], DIRECTORY_SEPARATOR)
        )[0];
        $rclone_config = $rcloneConfig[$rclone_config_key];
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $filenameSansExtension = basename($path, '.' . $ext);
        $compressedPath =
          dirname($path) .
          DIRECTORY_SEPARATOR .
          $ops->encode(
              $ops->decode($filenameSansExtension) . '---compressed.' . $ext
          );

        if ($rclone_config['type'] == 's3') {
            $key = $rclone_config['access_key_id'];
            $secret = $rclone_config['secret_access_key'];
            $endpoint = $rclone_config['endpoint'];

            $url = RClone_S3_PresignedURL(
                $key,
                $secret,
                $bucket,
                isset($rclone_config['region']) ? $rclone_config['region'] : '',
                $path,
                $endpoint,
                $expires = 86400
            );
            $headers = get_headers($url, true);
            $head = array_change_key_case($headers);
            if (
                strpos($headers[0], '200') === false ||
                (strpos($head['content-type'], 'image') === false &&
                  strpos($head['content-type'], 'video') === false)
            ) {
                $url = RClone_S3_PresignedURL(
                    $key,
                    $secret,
                    $bucket,
                    isset($rclone_config['region']) ? $rclone_config['region'] : '',
                    $compressedPath,
                    $endpoint,
                    $expires = 86400
                );
                $headers = get_headers($url, true);
                $head = array_change_key_case($headers);
                if (
                    strpos($headers[0], '200') === false ||
                    (strpos($head['content-type'], 'image') === false &&
                      strpos($head['content-type'], 'video') === false)
                ) {
                    die('Mirror not operational for ' . $compressedPath);
                }
            }
        } elseif ($DEOVR_USE_CLOUDFS_HTTP_ENDPOINT) {
            header(
                'Content-Type: ' .
                  get_mime_by_filename(basename(explode('?', $path)[0]))
            );
            $internalMediaPath =
              DIRECTORY_SEPARATOR .
              'media' .
              DIRECTORY_SEPARATOR .
              'http' .
              DIRECTORY_SEPARATOR .
              $CLOUDFS_HTTP_ENDPOINT .
              DIRECTORY_SEPARATOR .
              ltrim($path, DIRECTORY_SEPARATOR) .
              DIRECTORY_SEPARATOR;
            header('X-Accel-Redirect: ' . $internalMediaPath);
            die();
        } else {
            $url = $ops->public_link($path);
            if ($url == false) {
                $url = $ops->public_link($compressedPath);
                if ($url == false) {
                    die('Mirrored File not found: ' . $compressedPath);
                }
            }
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Location: ' . $url);
        die();
    }
}

function is_base64_encoded($data)
{
    if (preg_match('%^[a-zA-Z0-9/+]*={0,2}$%', $data)) {
        return true;
    } else {
        return false;
    }
}

if (
    session_status() == PHP_SESSION_ACTIVE &&
    isset($_SESSION['SessionAuth']) &&
    $_SESSION['SessionAuth'] === $AUTHTOKEN
) {
    run();
} elseif (isset($_GET['AuthToken']) && $_GET['AuthToken'] === $AUTHTOKEN) {
    $_SESSION['SessionAuth'] = $_GET['AuthToken'];
    run();
} elseif (
    isset($_GET['token']) &&
    ($tokenizer->checkToken($_GET['token'], $AUTHTOKEN) ||
      (isset($_GET['favorite']) &&
        $tokenizer->checkToken(
            $_GET['token'],
            privuma::getEnv('DOWNLOAD_PASSWORD'),
            true,
            true
        )))
) {
    run();
} else {
    die('Malformed Request');
}

function normalizeString($str = '')
{
    //remove accents
    $str = strtr(
        utf8_decode($str),
        utf8_decode('àáâãäçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ'),
        'aaaaaceeeeiiiinooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUY'
    );
    //replace directory symbols
    $str = preg_replace("/[\/\\\\]+/", '-', $str);
    //replace symbols;
    $str = preg_replace("/[\:]+/", '_', $str);
    //replace foreign characters
    return preg_replace("/[^a-zA-Z0-9_\-\s\(\)~]+/", '', $str);
}

function realFilePath($filePath, $dirnamed_sync_folder = false)
{
    $mf = new mediaFile(basename($filePath), basename(dirname($filePath)));

    return $mf->realPath();

    global $SYNC_FOLDER;
    global $ops;

    $root = $dirnamed_sync_folder ? dirname($SYNC_FOLDER) : $SYNC_FOLDER;

    $ext = pathinfo($filePath, PATHINFO_EXTENSION);

    $filename = basename($filePath, '.' . $ext);
    $album = normalizeString(basename(dirname($filePath)));

    $filePath =
      $root .
      DIRECTORY_SEPARATOR .
      $album .
      DIRECTORY_SEPARATOR .
      $filename .
      '.' .
      $ext;
    $compressedFile =
      $root .
      DIRECTORY_SEPARATOR .
      $album .
      DIRECTORY_SEPARATOR .
      $filename .
      '---compressed.' .
      $ext;

    $dupe =
      $root .
      DIRECTORY_SEPARATOR .
      $album .
      DIRECTORY_SEPARATOR .
      $filename .
      '---dupe.' .
      $ext;

    $files = $ops->glob(
        $root .
          DIRECTORY_SEPARATOR .
          $album .
          DIRECTORY_SEPARATOR .
          explode('---', $filename)[0] .
          '*.*'
    );
    if ($files === false) {
        $files = [];
    }
    if ($ops->is_file($filePath)) {
        return $filePath;
    } elseif ($ops->is_file($compressedFile)) {
        return $compressedFile;
    } elseif ($ops->is_file($dupe)) {
        return $dupe;
    } elseif (count($files) > 0) {
        if (strtolower($ext) == 'mp4' || strtolower($ext) == 'webm') {
            foreach ($files as $file) {
                $iext = pathinfo($file, PATHINFO_EXTENSION);
                if (strtolower($ext) == strtolower($iext)) {
                    return $file;
                }
            }
        } else {
            foreach ($files as $file) {
                $iext = pathinfo($file, PATHINFO_EXTENSION);
                if (strtolower($iext) !== 'mp4' && strtolower($iext) !== 'webm') {
                    return $file;
                }
            }
        }
    }

    return false;
}

function getProtectedUrlForMediaPath(
    $path,
    $use_fallback = false,
    $noIp = false
) {
    global $ENDPOINT;
    global $FALLBACK_ENDPOINT;
    global $AUTHTOKEN;
    global $tokenizer;
    $uri =
      '?token=' .
      $tokenizer->rollingTokens($AUTHTOKEN, $noIp)[1] .
      '&media=' .
      urlencode(base64_encode($path));
    return $use_fallback ? $FALLBACK_ENDPOINT . $uri : $ENDPOINT . $uri;
}

function getProtectedUrlForMedia($media, $use_fallback = false)
{
    global $ENDPOINT;
    global $FALLBACK_ENDPOINT;
    global $AUTHTOKEN;
    global $tokenizer;
    $uri =
      '?token=' . $tokenizer->rollingTokens($AUTHTOKEN)[1] . '&media=' . $media;
    return $use_fallback ? $FALLBACK_ENDPOINT . $uri : $ENDPOINT . $uri;
}

function getProtectedUrlForMediaHash($hash, $use_fallback = false)
{
    global $ENDPOINT;
    global $FALLBACK_ENDPOINT;
    global $AUTHTOKEN;
    global $tokenizer;
    $uri =
      '?token=' .
      $tokenizer->rollingTokens($AUTHTOKEN)[1] .
      '&media=' .
      urlencode($hash);
    return $use_fallback ? $FALLBACK_ENDPOINT . $uri : $ENDPOINT . $uri;
}

function compressImage($url, $quality = 70, $maxWidth = 2048, $maxHeight = 2048)
{
    $extension = strtolower(pathinfo(explode('?', $url)[0], PATHINFO_EXTENSION));
    $command = implode(' ', [
      'convert',
      escapeshellarg($url),
      '-resize',
      escapeshellarg($maxWidth . 'x' . $maxHeight . '>'),
      '-quality',
      escapeshellarg($quality),
      '-fuzz',
      '4%',
      '+dither',
      '-layers',
      'optimize',
      "$extension:-",
    ]);
    header('Content-Type: image/' . $extension);
    header(
        'Content-Disposition: attachment;  filename="' .
          basename(explode('?', $url)[0]) .
          '"'
    );
    passthru($command);
}

function getCompressionUrl($url)
{
    if (
        isset($_GET['c']) &&
        $_GET['c'] == '1' &&
        in_array(strtolower(pathinfo(explode('?', $url)[0], PATHINFO_EXTENSION)), [
          'jpg',
          'jpeg',
          'png',
          'gif',
        ])
    ) {
        compressImage($url);
        die();
    }
    return $url;
}

function streamMedia($file, bool $useOps = false)
{
    global $USE_X_Accel_Redirect;
    if ($useOps) {
        global $ops;
        header('Accept-Ranges: bytes');
        header('Content-Disposition: inline');
        header('Content-Type: ' . get_mime_by_filename($file));
        header('Content-Length:' . $ops->filesize($file));
        $ops->readfile($file);
    } elseif ($USE_X_Accel_Redirect && isUrl($file)) {
        $file = getCompressionUrl($file);
        header(
            'Content-Type: ' . get_mime_by_filename(basename(explode('?', $file)[0]))
        );
        $protocol = parse_url($file, PHP_URL_SCHEME);
        $hostname = parse_url($file, PHP_URL_HOST);
        $path = ltrim(
            parse_url($file, PHP_URL_PATH) .
              (strpos($file, '?') !== false
                ? '?' . parse_url($file, PHP_URL_QUERY)
                : ''),
            DIRECTORY_SEPARATOR
        );
        $internalMediaPath =
          DIRECTORY_SEPARATOR .
          'media' .
          DIRECTORY_SEPARATOR .
          $protocol .
          DIRECTORY_SEPARATOR .
          $hostname .
          DIRECTORY_SEPARATOR .
          $path .
          DIRECTORY_SEPARATOR;
        header('X-Accel-Redirect: ' . $internalMediaPath);
        die();
    } elseif (pathinfo($file, PATHINFO_EXTENSION) !== 'mp4' || is_file($file)) {
        $file = getCompressionUrl($file);
        $headers = get_headers($file, true);
        $head = array_change_key_case($headers);
        header('Accept-Ranges: bytes');
        header('Content-Disposition: inline');
        header(
            'Content-Type: ' . ($head['content-type'] ?? get_mime_by_filename($file))
        );
        readfile($file);
    } else {
        set_time_limit(0);
        ini_set('max_execution_time', 0);
        $useragent =
          'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.96 Safari/537.36';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 222222);
        curl_setopt($ch, CURLOPT_URL, $file);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $info = curl_exec($ch);
        $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        header('Content-Type: video/mp4');

        header('Accept-Ranges: bytes');
        header('Cache-Control: public, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        $begin = 0;
        $end = $size - 1;
        if (isset($_SERVER['HTTP_RANGE'])) {
            if (
                preg_match(
                    "/bytes=\h*(\d+)-(\d*)[\D.*]?/i",
                    $_SERVER['HTTP_RANGE'],
                    $matches
                )
            ) {
                $begin = intval($matches[1]);
                if (!empty($matches[2])) {
                    $end = intval($matches[2]);
                }
            }
        }

        header('Content-Length:' . ($end - $begin + 1));

        header('Content-Transfer-Encoding: binary');
        if (isset($_SERVER['HTTP_RANGE'])) {
            header('x-meta: ' . $begin . ' + ' . $end);
            header('HTTP/1.1 206 Partial Content');
            header("Content-Range: bytes $begin-$end/$size");
        } else {
            header('HTTP/1.1 200 OK');
        }

        $ch = curl_init();
        if (isset($_SERVER['HTTP_RANGE'])) {
            $headers = ['Range: bytes=' . $begin . '-' . $end];
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 222222);
        curl_setopt($ch, CURLOPT_URL, $file);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
        curl_setopt($ch, CURLOPT_NOBODY, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_exec($ch);
    }
    exit();
}

function isValidMd5($md5 = '')
{
    return strlen($md5) == 32 && ctype_xdigit($md5);
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
    global $privuma;
    global $tokenizer;
    global $sqlFilter;

    $MAX_URL_CHARACTERS = 1600;

    if (isset($_GET['album']) || isset($_GET['amp;album'])) {
        $albumName = $_GET['album'] ?? $_GET['amp;album'];

        if (is_base64_encoded($albumName)) {
            $albumName = base64_decode($albumName);
        }

        $conn = $privuma->getPDO();

        $favoritesStmt = $conn->prepare("select filename, hash, time
            from media
            where album = 'Favorites'
        ");
        $favoritesStmt->execute([]);
        $favorites = [];
        foreach ($favoritesStmt->fetchAll() as $favorite) {
            $favorites[$favorite['hash']] = [];
            $favorites[$favorite['hash']]['hash'] = $favorite['hash'];
            $favorites[$favorite['hash']]['time'] = $favorite['time'];
            $favorites[$favorite['hash']]['album'] = explode(
                '-----',
                $favorite['filename']
            )[0];
            $favorites[$favorite['hash']]['filename'] = explode(
                '-----',
                $favorite['filename']
            )[1];
        }

        $stmt = $conn->prepare(
            "select filename, album, time, hash, url, thumbnail, metadata
        from media
        where hash in
        (select hash from media where {$sqlFilter} " .
              (!empty($sqlFilter) ? ' and ' : '') .
              " album = ? and hash != 'compressed')
        group by hash
         order by
         " .
              (strpos(strtolower($albumName), 'comic') !== false &&
              strpos(strtolower($albumName), '-comic') === false
                ? 'filename asc'
                : "
            CASE
                WHEN filename LIKE '%.gif' THEN 1
                WHEN filename LIKE '%.mp4' THEN 2
                WHEN filename LIKE '%.webm' THEN 3
                ELSE 4
            END,
            time DESC")
        );
        $stmt->execute([$albumName]);
        $data = $stmt->fetchAll();

        usort($data, function ($a, $b) use ($albumName, $favorites) {
            $aext = pathinfo($a['filename'], PATHINFO_EXTENSION);
            $bext = pathinfo($b['filename'], PATHINFO_EXTENSION);
            if (
                strpos(strtolower($albumName), 'comic') !== false &&
                strpos(strtolower($albumName), '-comic') === false
            ) {
                return strnatcmp($a['filename'], $b['filename']);
            }

            if ($aext == 'gif' && $bext != 'gif') {
                return -1;
            }

            if ($bext == 'gif' && $aext != 'gif') {
                return 1;
            }

            if (
                in_array($aext, ['webm', 'mp4']) &&
                !in_array($bext, ['webm', 'mp4'])
            ) {
                return -1;
            }

            if (
                in_array($bext, ['webm', 'mp4']) &&
                !in_array($aext, ['webm', 'mp4'])
            ) {
                return 1;
            }

            $adate = strtotime(
                $albumName === 'Favorites' ? $favorites[$a['hash']]['time'] : $a['time']
            );
            $bdate = strtotime(
                $albumName === 'Favorites' ? $favorites[$b['hash']]['time'] : $b['time']
            );
            return $bdate <=> $adate;
        });

        $media = [];
        foreach ($data as $item) {
            if (!isset($item['filename'])) {
                continue;
            }

            $ext = pathinfo($item['filename'], PATHINFO_EXTENSION);
            $filename = basename($item['filename'], '.' . $ext);
            $filePath =
              $SYNC_FOLDER .
              DIRECTORY_SEPARATOR .
              normalizeString($item['album']) .
              DIRECTORY_SEPARATOR .
              $item['filename'];
            $fileParts = explode('---', basename($filePath, '.' . $ext));
            $hash = $fileParts[1] ?? $item['hash'];
            $relativePath =
              normalizeString($item['album']) . '-----' . basename($filePath);
            $favroited = array_key_exists($hash, $favorites);
            if (strtolower($ext) === 'mp4' || strtolower($ext) === 'webm') {
                $destt =
                  $item['album'] .
                  DIRECTORY_SEPARATOR .
                  basename($filePath, '.' . $ext) .
                  '.jpg';
                $mediat = urlencode(base64_encode($destt));
                $dest =
                  $item['album'] .
                  DIRECTORY_SEPARATOR .
                  basename($filePath, '.' . $ext) .
                  '.' .
                  $ext;
                $mediaval = urlencode(base64_encode($dest));
                if (strlen($mediaval) > $MAX_URL_CHARACTERS) {
                    mediaFile::sanitize($hash, $dest);
                    $mediaval = $item['hash'];
                    $mediat = 't-' . $item['hash'];
                }
                $videoPath = getProtectedUrlForMedia($mediaval);
                $photoPath = getProtectedUrlForMedia($mediat);

                if (!is_null($item['url'])) {
                    $videoPath = getProtectedUrlForMediaHash(base64_encode($item['url']));
                    $photoPath = getProtectedUrlForMediaHash(
                        base64_encode($item['thumbnail'])
                    );
                }
            } else {
                $dest =
                  $item['album'] .
                  DIRECTORY_SEPARATOR .
                  basename($filePath, '.' . $ext) .
                  '.' .
                  $ext;
                $mediaval = urlencode(base64_encode($dest));
                if (strlen($mediaval) > $MAX_URL_CHARACTERS) {
                    mediaFile::sanitize($hash, $dest);
                    $mediaval = $item['hash'];
                    $mediat = 't-' . $item['hash'];
                }
                $photoPath = getProtectedUrlForMedia($mediaval);

                if (!is_null($item['url'])) {
                    $photoPath = getProtectedUrlForMediaHash(base64_encode($item['url']));
                }
            }

            $mime = isset($videoPath)
              ? 'video/mp4'
              : (strtolower($ext) === 'gif'
                ? 'image/gif'
                : (strtolower($ext) === 'png'
                  ? 'image/png'
                  : 'image/jpg'));
            if (!array_key_exists($hash, $media)) {
                $media[$hash] = [
                  'favorited' => $favroited,
                  'img' => $photoPath ?? '',
                  'updated' => strtotime($item['time']),
                  'video' => $videoPath ?? '',
                  'id' => (string) $hash,
                  'filename' => (string) $fileParts[0],
                  'mime' => (string) $mime,
                  'epoch' => strtotime($item['time']),
                  'metadata' => (string) $item['metadata'],
                ];
            } elseif (isset($videoPath)) {
                $media[$hash]['video'] = $videoPath;
            } elseif (isset($photoPath)) {
                $media[$hash]['img'] = $photoPath;
            }

            unset($videoPath);
            unset($photoPath);
        }

        if (empty($media)) {
            $albumFSPath = str_replace('-----', DIRECTORY_SEPARATOR, $albumName);
            $scan = $ops->scandir(
                dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $albumFSPath,
                false,
                false,
                [
                  ...array_map(
                      function ($ext) {
                          return '+ *.' . $ext;
                      },
                      ['mp4', 'jpg', 'jpeg', 'gif', 'png', 'heif']
                  ),
                  '-**',
                ]
            );
            if ($scan === false) {
                $scan = [];
            }
            natcasesort($scan);
            foreach (array_diff($scan, ['.', '..']) as $file) {
                $filePath =
                  dirname($SYNC_FOLDER) .
                  DIRECTORY_SEPARATOR .
                  $albumFSPath .
                  DIRECTORY_SEPARATOR .
                  $file;

                $ext = pathinfo($file, PATHINFO_EXTENSION);
                $filename = basename($file, '.' . $ext);

                $videoPathTest =
                  dirname($SYNC_FOLDER) .
                  DIRECTORY_SEPARATOR .
                  $albumFSPath .
                  DIRECTORY_SEPARATOR .
                  $filename .
                  '.mp4';
                $thumbailPathTest =
                  dirname($SYNC_FOLDER) .
                  DIRECTORY_SEPARATOR .
                  $albumFSPath .
                  DIRECTORY_SEPARATOR .
                  $filename .
                  '.jpg';
                $hash = md5(
                    dirname($SYNC_FOLDER) .
                      DIRECTORY_SEPARATOR .
                      $albumFSPath .
                      DIRECTORY_SEPARATOR .
                      $filename
                );

                $fileParts = explode('---', basename($filePath, '.' . $ext));
                $relativePath = str_replace(DIRECTORY_SEPARATOR, '-----', $filePath);

                if (strtolower($ext) === 'mp4') {
                    $destt =
                      dirname($SYNC_FOLDER) .
                      DIRECTORY_SEPARATOR .
                      $albumFSPath .
                      basename($filePath, '.' . $ext) .
                      '.jpg';
                    $mediat = urlencode(base64_encode($destt));
                    $dest =
                      dirname($SYNC_FOLDER) .
                      DIRECTORY_SEPARATOR .
                      $albumFSPath .
                      DIRECTORY_SEPARATOR .
                      basename($filePath, '.' . $ext) .
                      '.' .
                      $ext;
                    $mediai = urlencode(base64_encode($dest));
                    if (strlen($mediai) > $MAX_URL_CHARACTERS) {
                        mediaFile::sanitize($hash, $dest);
                        $mediai = $hash;
                        $mediat = 't-' . $hash;
                    }
                    $videoPath = getProtectedUrlForMedia($mediai);
                    $photoPath = getProtectedUrlForMedia($mediat);
                } elseif ($ops->is_file($videoPathTest)) {
                    $destt =
                      dirname($SYNC_FOLDER) .
                      DIRECTORY_SEPARATOR .
                      $albumFSPath .
                      basename($filePath, '.' . $ext) .
                      '.jpg';
                    $mediat = urlencode(base64_encode($destt));
                    $dest = $videoPathTest;
                    $mediai = urlencode(base64_encode($dest));
                    if (strlen($mediai) > $MAX_URL_CHARACTERS) {
                        mediaFile::sanitize($hash, $dest);
                        $mediai = $hash;
                        $mediat = 't-' . $hash;
                    }
                    $videoPath = getProtectedUrlForMedia($mediai);
                    $photoPath = getProtectedUrlForMedia($mediat);
                } else {
                    unset($videoPath);
                    $dest =
                      dirname($SYNC_FOLDER) .
                      DIRECTORY_SEPARATOR .
                      $albumFSPath .
                      DIRECTORY_SEPARATOR .
                      basename($filePath, '.' . $ext) .
                      '.' .
                      $ext;
                    $mediai = urlencode(base64_encode($dest));
                    if (strlen($mediai) > $MAX_URL_CHARACTERS) {
                        mediaFile::sanitize($hash, $dest);
                        $mediai = $hash;
                        $mediat = 't-' . $hash;
                    }
                    $photoPath = getProtectedUrlForMedia($mediai);
                }

                $mime = isset($videoPath)
                  ? 'video/mp4'
                  : (strtolower($ext) === 'gif'
                    ? 'image/gif'
                    : (strtolower($ext) === 'png'
                      ? 'image/png'
                      : 'image/jpg'));

                $media[$hash] = [
                  'img' => $photoPath ?? '',
                  'updated' => 1,
                  'video' => $videoPath ?? '',
                  'id' => (string) $hash,
                  'filename' => (string) $fileParts[0],
                  'mime' => (string) $mime,
                  'epoch' => 1,
                ];
            }
        }

        $media = array_values($media);

        $photos = [
          'gtoken' => urlencode(
              $ENDPOINT .
                (isset($_GET['unfiltered']) ? 'unfiltered/' : '') .
                '...' .
                $AUTHTOKEN
          ),
          'gdata' => $media,
        ];
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Content-Type: application/json');
        print json_encode($photos, JSON_UNESCAPED_SLASHES);
    } elseif (isset($_GET['favorite'])) {
        if (is_base64_encoded($_GET['favorite'])) {
            $_GET['favorite'] = base64_decode($_GET['favorite']);
        }

        $mediaPath = str_replace(
            '..',
            '',
            str_replace(
                DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR,
                DIRECTORY_SEPARATOR,
                str_replace('-----', DIRECTORY_SEPARATOR, $_GET['favorite'])
            )
        );
        $filePath = $SYNC_FOLDER . DIRECTORY_SEPARATOR . $mediaPath;
        echo mediaFile::load(
            $_GET['favorite'],
            $_GET['favorite'],
            basename($filePath),
            basename(dirname($filePath))
        )?->favorite()
          ? 'Item was added to Favorites'
          : 'Item was removed from Favorites';
        exit();
    } elseif (isset($_GET['media'])) {
        set_time_limit(2);
        if (strpos($_GET['media'], 't-') === 0) {
            $hash = str_replace('t-', '', $_GET['media']);
            $original = mediaFile::desanitize($hash);
            if ($original !== $hash) {
                $file = ltrim($original, DIRECTORY_SEPARATOR);
            } else {
                $file = str_replace(
                    mediaFile::MEDIA_FOLDER . DIRECTORY_SEPARATOR,
                    '',
                    (new mediaFile('foo', 'bar', null, $hash))->original()
                );
                mediaFile::sanitize($hash, $file);
            }

            $ext = pathinfo($file, PATHINFO_EXTENSION);
            $filename = basename($file, '.' . $ext);
            $_GET['media'] = str_Replace(
                DIRECTORY_SEPARATOR,
                '-----',
                dirname($file) . DIRECTORY_SEPARATOR . $filename . '.jpg'
            );
        } elseif (
            isValidMd5($_GET['media']) ||
            strpos($_GET['media'], 'h-') === 0
        ) {
            $hash = str_replace('h-', '', $_GET['media']);
            $mediaFileUrl = (new mediaFile('foo', 'bar', null, $hash))->source();
            if (is_string($mediaFileUrl)) {
                $_GET['media'] = $mediaFileUrl;
            } else {
                $original = mediaFile::desanitize($hash);
                if ($original !== $hash) {
                    $file = ltrim($original, DIRECTORY_SEPARATOR);
                } else {
                    $file = str_replace(
                        mediaFile::MEDIA_FOLDER . DIRECTORY_SEPARATOR,
                        '',
                        (new mediaFile('foo', 'bar', null, $hash))->original()
                    );
                    mediaFile::sanitize($hash, $file);
                }
                $ext = pathinfo($file, PATHINFO_EXTENSION);
                $filename = basename($file, '.' . $ext);
                $_GET['media'] = str_Replace(
                    DIRECTORY_SEPARATOR,
                    '-----',
                    dirname($file) . DIRECTORY_SEPARATOR . $filename . '.' . $ext
                );
            }
        } elseif (is_base64_encoded($_GET['media'])) {
            $_GET['media'] = base64_decode($_GET['media']);
        }

        if ($_GET['media'] === 'blank.gif') {
            header('Content-Type: image/gif');
            echo base64_decode(
                'R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw=='
            );
            return;
        }

        if (isUrl($_GET['media'])) {
            streamMedia($_GET['media'], false);
        }

        $mediaPath = str_replace(
            '..',
            '',
            str_replace(
                DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR,
                DIRECTORY_SEPARATOR,
                str_replace('-----', DIRECTORY_SEPARATOR, $_GET['media'])
            )
        );
        if (dirname($mediaPath, 2) === 'Favorites') {
            $mediaPath =
              basename(dirname($mediaPath)) .
              DIRECTORY_SEPARATOR .
              basename($mediaPath);
        }

        $pos = strpos($mediaPath, 'data' . DIRECTORY_SEPARATOR);
        if ($pos !== false) {
            $mediaPath = substr_replace(
                $mediaPath,
                '',
                $pos,
                strlen('data' . DIRECTORY_SEPARATOR)
            );
        }
        if (empty(trim($mediaPath, DIRECTORY_SEPARATOR . '.'))) {
            die('Invalid Media Path');
        }

        if (count(explode(DIRECTORY_SEPARATOR, $mediaPath)) == 2) {
            $file =
              DIRECTORY_SEPARATOR .
              privuma::getDataFolder() .
              DIRECTORY_SEPARATOR .
              mediaFile::MEDIA_FOLDER .
              DIRECTORY_SEPARATOR .
              ltrim($mediaPath, DIRECTORY_SEPARATOR);
        } else {
            $file =
              DIRECTORY_SEPARATOR .
              privuma::getDataFolder() .
              DIRECTORY_SEPARATOR .
              ltrim($mediaPath, DIRECTORY_SEPARATOR);
        }
        redirectToMedia($file);

        if (strpos($ENDPOINT, $_SERVER['HTTP_HOST']) == false) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Cache-Control: post-check=0, pre-check=0', false);
            header('Pragma: no-cache');
            header(
                'Location: ' .
                  $ENDPOINT .
                  '?token=' .
                  $tokenizer->rollingTokens($_SESSION['SessionAuth'])[1] .
                  '&media=' .
                  urlencode($_GET['media'])
            );
            die();
        }

        if (is_base64_encoded($_GET['media'])) {
            $_GET['media'] = base64_decode($_GET['media']);
        }

        if ($_GET['media'] === 'blank.gif') {
            header('Content-Type: image/gif');
            echo base64_decode(
                'R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw=='
            );
            return;
        }
        if (!isset($hash)) {
            $file = realFilePath($SYNC_FOLDER . DIRECTORY_SEPARATOR . $mediaPath);
        } else {
            $file = privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaPath;
        }

        if ($file === false) {
            $file =
              DIRECTORY_SEPARATOR .
              ltrim($ops->encode($mediaPath), DIRECTORY_SEPARATOR);
        }

        $ext = pathinfo($mediaPath, PATHINFO_EXTENSION);
        $album = explode(DIRECTORY_SEPARATOR, $mediaPath)[0];
        if (!$ops->is_file($file)) {
            die('Media file not found ' . $file);
        }

        $mediaPath =
          basename(dirname($file)) . DIRECTORY_SEPARATOR . basename($file);
        if (
            strpos($mediaPath, '---dupe') !== false &&
            $ops->filesize($mediaPath) <= 512
        ) {
            $mediaPath = $ops->file_get_contents($file);
            $file = realFilePath($SYNC_FOLDER . DIRECTORY_SEPARATOR . $mediaPath);
        }

        if (!$ops->is_file($file)) {
            die('Media file not found ' . $file);
        }

        if ($USE_X_Accel_Redirect) {
            header(
                'Content-Type: ' .
                  mime_content_type(
                      privuma::canonicalizePath(
                          ltrim($ops->encode($file), DIRECTORY_SEPARATOR)
                      )
                  )
            );
            header('X-Accel-Redirect: ' . DIRECTORY_SEPARATOR . $ops->encode($file));
            die();
        }

        streamMedia($file, true);
    } else {
        $realbums = [];
        $mediaDirsPath =
          privuma::getOutputDirectory() .
          DIRECTORY_SEPARATOR .
          'cache' .
          DIRECTORY_SEPARATOR .
          'mediadirs.json';
        if (file_exists($mediaDirsPath)) {
            foreach (
                json_decode(file_get_contents($mediaDirsPath), true)
                as $folderObj
            ) {
                if (
                    isset($folderObj['HasThumbnailJpg']) &&
                    $folderObj['HasThumbnailJpg']
                ) {
                    $ext = pathinfo($folderObj['Name'], PATHINFO_EXTENSION);
                    $hash = md5(
                        dirname($folderObj['Path']) .
                          DIRECTORY_SEPARATOR .
                          basename($folderObj['Path'], '.' . $ext)
                    );

                    $dest =
                      dirname($SYNC_FOLDER) .
                      DIRECTORY_SEPARATOR .
                      $folderObj['Path'] .
                      DIRECTORY_SEPARATOR .
                      '1.jpg';
                    $media = urlencode(base64_encode($dest));
                    if (strlen($media) > $MAX_URL_CHARACTERS) {
                        mediaFile::sanitize($hash, $dest);
                        $media = 't-' . $hash;
                    }
                    $photoPath = getProtectedUrlForMedia($media);

                    //$photoPath = $ENDPOINT . "?token=" . $tokenizer->rollingTokens($_SESSION['SessionAuth'])[1]  . "&media=".urlencode(base64_encode(str_replace(DIRECTORY_SEPARATOR, '-----', dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $folderObj['Path']) . "-----" . "1.jpg"));
                } else {
                    $photoPath =
                      $ENDPOINT .
                      '?token=' .
                      $tokenizer->rollingTokens($_SESSION['SessionAuth'])[1] .
                      '&media=blank.gif';
                    $hash = 'checkCache';
                }
                if (
                    !in_array(explode(DIRECTORY_SEPARATOR, $folderObj['Path'])[0], [
                      'SCRATCH',
                    ])
                ) {
                    $realbums[] = [
                      'id' => (string) urlencode(
                          base64_encode(
                              implode(
                                  '-----',
                                  explode(DIRECTORY_SEPARATOR, $folderObj['Path'])
                              )
                          )
                      ),
                      'updated' =>
                        (string) (strtotime(explode('.', $folderObj['ModTime'])[0]) *
                          1000),
                      'title' => (string) implode(
                          '---',
                          explode(DIRECTORY_SEPARATOR, $folderObj['Path'])
                      ),
                      'img' => (string) $photoPath,
                      'mediaId' => (string) $hash,
                    ];
                }
            }
        }

        $conn = $privuma->getPDO();
        $stmt = $conn->prepare(
            'select filename, album, url, thumbnail, time, hash FROM media
        inner join (select max(id) as id FROM media ' .
              (!empty($sqlFilter) ? 'where blocked = 0 ' : '') .
              ' GROUP by album) as sorted on sorted.id = media.id  order by time DESC;'
        );
        $stmt->execute([]);
        $data = $stmt->fetchAll();

        foreach ($data as $album) {
            $ext = pathinfo($album['filename'], PATHINFO_EXTENSION);
            $filename = basename($album['filename'], '.' . $ext);
            $filePath = $album['album'] . DIRECTORY_SEPARATOR . $album['filename'];
            if (strtolower($ext) === 'mp4' || strtolower($ext) === 'webm') {
                $dest =
                  $album['album'] .
                  DIRECTORY_SEPARATOR .
                  basename($filePath, '.' . $ext) .
                  '.jpg';
                $media = urlencode(base64_encode($dest));
                if (strlen($media) > $MAX_URL_CHARACTERS) {
                    mediaFile::sanitize($hash, $dest);
                    $media = 't-' . $album['hash'];
                }
                $photoPath = getProtectedUrlForMedia($media);
                if (!is_null($album['url'])) {
                    $photoPath = getProtectedUrlForMediaHash(
                        base64_encode($album['thumbnail'])
                    );
                }
            } else {
                $dest =
                  $album['album'] .
                  DIRECTORY_SEPARATOR .
                  basename($filePath, '.' . $ext) .
                  '.' .
                  $ext;
                $media = urlencode(base64_encode($dest));
                if (strlen($media) > $MAX_URL_CHARACTERS) {
                    mediaFile::sanitize($hash, $dest);
                    $media = $album['hash'];
                }
                $photoPath = getProtectedUrlForMedia($media);
                if (!is_null($album['url'])) {
                    $photoPath = getProtectedUrlForMediaHash(
                        base64_encode($album['url'])
                    );
                }
            }

            if (empty($photoPath)) {
                $photoPath =
                  $ENDPOINT .
                  '?token=' .
                  $tokenizer->rollingTokens($_SESSION['SessionAuth'])[1] .
                  '&media=blank.gif';
            }

            $realbums[] = [
              'id' => (string) urlencode(base64_encode($album['album'])),
              'updated' => (string) (strtotime($album['time']) * 1000),
              'title' => (string) $album['album'],
              'img' => (string) $photoPath,
              'mediaId' => (string) $album['hash'],
            ];
        }

        usort($realbums, function ($a1, $a2) {
            return $a2['updated'] <=> $a1['updated'];
        });

        $realbums = [
          'gtoken' => urlencode(
              $ENDPOINT .
                (isset($_GET['unfiltered']) ? 'unfiltered/' : '') .
                '...' .
                $AUTHTOKEN
          ),
          'gdata' => $realbums,
        ];
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Content-Type: application/json');
        print json_encode($realbums, JSON_UNESCAPED_SLASHES, 10);
        exit();
    }
}

function get_mime_by_filename($filename)
{
    if (
        !is_file(
            privuma::getOutputDirectory() .
              DIRECTORY_SEPARATOR .
              'cache' .
              DIRECTORY_SEPARATOR .
              'mimes.json'
        )
    ) {
        $db = json_decode(
            file_get_contents(
                'https://cdn.jsdelivr.net/gh/jshttp/mime-db@master/db.json'
            ),
            true
        );
        $mime_types = [];
        foreach ($db as $mime => $data) {
            if (!isset($data['extensions'])) {
                continue;
            }
            foreach ($data['extensions'] as $extension) {
                $mime_types[$extension] = $mime;
            }
        }

        file_put_contents(
            privuma::getOutputDirectory() .
              DIRECTORY_SEPARATOR .
              'cache' .
              DIRECTORY_SEPARATOR .
              'mimes.json',
            json_encode($mime_types, JSON_PRETTY_PRINT)
        );
    }
    $mime_types = json_decode(
        file_get_contents(
            privuma::getOutputDirectory() .
              DIRECTORY_SEPARATOR .
              'cache' .
              DIRECTORY_SEPARATOR .
              'mimes.json'
        ),
        true
    );
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (array_key_exists($ext, $mime_types)) {
        return $mime_types[$ext];
    } else {
        return 'application/octet-stream';
    }
}
