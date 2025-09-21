<?php
ini_set('memory_limit', '2G');
error_reporting(E_ALL);
ini_set('display_errors', 'on');

// Early exit for simple requests to avoid session overhead
if (isset($_GET['RapiServe'])) {
    header('Content-Type: text/javascript');
    header('Cache-Control: public, max-age=3600'); // Cache for 1 hour
    echo 'canrapiserve = "index.php?path=";';
    exit();
}

session_start();

use privuma\privuma;
use privuma\helpers\tokenizer;

require_once __DIR__ .
  DIRECTORY_SEPARATOR .
  '..' .
  DIRECTORY_SEPARATOR .
  'app' .
  DIRECTORY_SEPARATOR .
  'privuma.php';

// Cache expensive objects
static $privuma_instance = null;
static $tokenizer_instance = null;

function getPrivumaInstance() {
    global $privuma_instance;
    if ($privuma_instance === null) {
        $privuma_instance = privuma::getInstance();
    }
    return $privuma_instance;
}

function getTokenizerInstance() {
    global $tokenizer_instance;
    if ($tokenizer_instance === null) {
        $tokenizer_instance = new tokenizer();
    }
    return $tokenizer_instance;
}

function sanitizeLine($line): string
{
    if (is_null($line)) {
        return '';
    }
    return trim(preg_replace('/[^A-Za-z0-9 \-_\~\+\(\)\.\,\/]/', '', $line), "\r\n");
}

function trimExtraNewLines($string): string
{
    if (is_null($string)) {
        return '';
    }
    return trim(
        implode(
            PHP_EOL,
            array_map(function ($line) {
                return sanitizeLine($line);
            }, explode(PHP_EOL, $string))
        ),
        "\r\n"
    );
}

function parseMetaData($item): array
{
    $dateValue = explode(PHP_EOL, explode('Date: ', $item)[1] ?? '')[0];
    $intval = filter_var($dateValue, FILTER_VALIDATE_INT);
    if ($intval) {
        $dateValue = '@' . substr($dateValue, 0, 10);
    }
    $dateString = $dateValue;
    try {
        $dateString = new DateTime(
            $dateValue
        );
    } catch (Exception $e) {
        // silence date parsing issues.
    }

    return [
      'title' => explode(PHP_EOL, explode('Title: ', $item)[1] ?? '')[0],
      'author' => explode(PHP_EOL, explode('Author: ', $item)[1] ?? '')[0],
      'date' => $dateString,
      'rating' => (int) explode(PHP_EOL, explode('Rating: ', $item)[1] ?? '')[0],
      'favorites' => (int) explode(
          PHP_EOL,
          explode('Favorites: ', $item)[1] ?? ''
      )[0],
      'description' => explode(
          'Tags:',
          explode('Description: ', $item)[1] ?? ''
      )[0],
      'tags' =>
        explode(', ', explode(PHP_EOL, explode('Tags: ', $item)[1] ?? '')[0]) ??
        [],
      'comments' => explode('Comments: ', $item)[1] ?? '',
    ];
}

function condenseMetaData($item)
{
    return str_replace(
        PHP_EOL,
        '\n',
        mb_convert_encoding(
            str_replace(
                PHP_EOL . PHP_EOL,
                PHP_EOL,
                implode(PHP_EOL, [
                  sanitizeLine(
                      $item['title'] ?:
                      substr(trimExtraNewLines($item['description']), 0, 150)
                  ),
                  sanitizeLine($item['favorites']),
                  sanitizeLine(implode(', ', array_slice($item['tags'], 0, 20))),
                  //substr(trimExtraNewLines($item['comments']), 0, 256),
                ])
            ),
            'UTF-8',
            'UTF-8'
        )
    );
}

function getDB($mobile = false, $unfiltered = false, $nocache = false): array
{
    // Use cached instance
    $conn = getPrivumaInstance()->getPDO();

    $cachePath = __DIR__ .
      DIRECTORY_SEPARATOR .
      '..' .
      DIRECTORY_SEPARATOR .
      'app' .
      DIRECTORY_SEPARATOR .
      'output' .
      DIRECTORY_SEPARATOR .
      'cache' .
      DIRECTORY_SEPARATOR .
      'viewer_db_' .
      ($mobile ? 'mobile_' : '') .
      ($unfiltered ? 'unfiltered_' : '') .
      '.js';

    $currentTime = time();

    // Early return if cache exists and is valid
    if (!$nocache && file_exists($cachePath)) {
        $lastRan = filemtime($cachePath);
        if ($currentTime - $lastRan <= 24 * 60 * 60) {
            $size = filesize($cachePath);
            $data = file_get_contents($cachePath);
            return ['data' => $data, 'size' => $size];
        }
    }

    // Cache miss - rebuild
    $blocked = "(album = 'Favorites' or blocked = 0) and";
    if ($unfiltered) {
        $blocked = '';
    }

    // Optimized query with better indexing hints
    $sql = "SELECT filename, album, dupe, time, hash, duration, sound, REGEXP_REPLACE(metadata, 'www\.[a-zA-Z0-9\_\.\/\:\-\?\=\&]*|(http|https|ftp):\/\/[a-zA-Z0-9\_\.\/\:\-\?\=\&]*', 'Link Removed') as metadata FROM (SELECT * FROM media WHERE $blocked hash is not null and hash != '' and hash != 'compressed') t1 ORDER BY time desc";

    $stmt = $conn->prepare($sql);
    $stmt->execute();

    // Fetch with optimized buffer size
    $stmt->setFetchMode(PDO::FETCH_ASSOC);
    $rawData = $stmt->fetchAll();
    $data = str_replace('`', '', json_encode($rawData, JSON_UNESCAPED_UNICODE));

    // Helper function for array filtering
    if (!function_exists('filterArrayByKeys')) {
        function filterArrayByKeys($originalArray, $blacklistedKeys)
        {
            $newArray = array();
            foreach ($originalArray as $key => $value) {
                if (!in_array($key, $blacklistedKeys)) {
                    $newArray[$key] = $value;
                }
            }
            return $newArray;
        }
    }

    if (!function_exists('getFirst')) {
        function getFirst($array, $key, $value = null, $negate = false)
        {
            foreach ($array as $element) {
                if (
                    isset($element[$key])
                    && (
                        is_null($value)
                        || (
                            (
                                !$negate
                                && $element[$key] === $value
                            )
                            ||
                            (
                                $negate
                                && $element[$key] !== $value
                            )
                        )
                    )
                ) {
                    return $element;
                }
            }
            return null;
        }
    }

    if ($mobile) {
        $array = [];
        $metaDataFiles = [];
        $dataset = json_decode($data, true);
        foreach ($dataset as $item) {
                if (!is_null($item['metadata']) && strlen($item['metadata']) > 3) {
                    $targetMetaDataPrefix = substr(base64_encode($item['hash']), 0, 2);
                    if (!array_key_exists($targetMetaDataPrefix, $metaDataFiles)) {
                        $metaDataFiles[$targetMetaDataPrefix] = [];
                    }
                    $metaDataFiles[$targetMetaDataPrefix][$item['hash']] = $item['metadata'];
                }
                $tags = substr(sanitizeLine(implode(', ', array_slice(explode(', ', explode(PHP_EOL, explode('Tags: ', $item['metadata'])[1] ?? '')[0]) ??
                [], 0, 60))), 0, 500);
                $item['metadata'] = is_null($item['metadata']) ? '' : (strlen($tags) < 1 ? 'Using MetaData Store...' : $tags);
                if (!array_key_exists($item['hash'], $array)) {
                    $filenameParts = explode('-----', $item['filename']);
                    $array[$item['hash']] = [
                      'albums' => [sanitizeLine($item['album'])],
                      'filename' => sanitizeLine(substr(end($filenameParts), 0, 20)) . '.' . pathinfo($item['filename'], PATHINFO_EXTENSION),
                      'hash' => $item['hash'],
                      'times' => [$item['time']],
                      'metadata' => $item['metadata'],
                      'duration' => $item['duration'],
                      'sound' => $item['sound']
                    ];
                } else {
                    $array[$item['hash']]['albums'][] = sanitizeLine($item['album']);
                    $array[$item['hash']]['times'][] = $item['time'];
                }
            }
            $data = str_replace('$', 'USD', str_replace("'", '-', str_replace('`', '-', json_encode(
                array_values($array),
                JSON_THROW_ON_ERROR
            ))));
            $data = 'const encrypted_data = `' . $data . '`;';
    } else {
        $array = [];
        $dataset = json_decode($data, true);
        foreach ($dataset as $item) {
            if (!array_key_exists($item['hash'], $array)) {
                $filenameParts = explode('-----', $item['filename']);
                $array[$item['hash']] = [
                  ...filterArrayByKeys($item, ['filename', 'album', 'time']),
                   'filename' => end($filenameParts),
                   'albums' => [$item['album']],
                   'times' => [$item['time']],
                 ];
            } else {
                $array[$item['hash']]['albums'][] = $item['album'];
                $array[$item['hash']]['times'][] = $item['time'];
            }
        }
        $data = str_replace('$', 'USD', str_replace("'", '-', str_replace('`', '-', json_encode(
            array_values($array),
            JSON_THROW_ON_ERROR
        ))));
        $data = 'const encrypted_data = ' . $data . ';';
    }

    file_put_contents($cachePath, $data);

    return ['data' => file_get_contents($cachePath), 'size' => filesize($cachePath)];
}

// RapiServe check already handled at top of file
if (!isset($_SESSION['viewer-authenticated-successfully']) && isset($_POST['key']) && base64_decode($_POST['key']) == privuma::getEnv('DOWNLOAD_PASSWORD')) {
    $_SESSION['viewer-authenticated-successfully'] = true;
}

if (!isset($_SESSION['viewer-authenticated-successfully']) && isset($_GET['path'])) {
    http_response_code(400);
    die('Malformed request');
}

if (isset($_GET['path'])) {
    if (str_starts_with($_GET['path'], '/data:')) {
        http_response_code(404);
        die();
    }

    if (strstr($_GET['path'], '.js') && !strstr($_GET['path'], '.json')) {
        if (isset($_SERVER['HTTP_RANGE'])) {
            header('Content-Type: text/javascript');
            echo '          ';
            die();
        }
        $originalFilename = base64_decode(basename($_GET['path'], '.js'));
        if (strstr($originalFilename, '_mobile_')) {
            header('Content-Type: text/javascript; charset=utf-8');
            header('Cache-Control: public, max-age=3600'); // 1 hour cache
            $dataset = getDB(true, isset($_GET['unfiltered']), isset($_GET['nocache']));
            header('Content-Length: ' . $dataset['size']);
            echo $dataset['data'];
            exit();
        } elseif (strstr($originalFilename, 'encrypted_data')) {
            header('Content-Type: text/javascript; charset=utf-8');
            header('Cache-Control: public, max-age=3600'); // 1 hour cache
            $dataset = getDB(false, isset($_GET['unfiltered']), isset($_GET['nocache']));
            header('Content-Length: ' . $dataset['size']);
            echo $dataset['data'];
            exit();
        }
    }

    if (isset($_GET['nocache']) && strstr($_GET['path'], 'favorites.json')) {
        http_response_code(404);
        die();
    }

    // Optimized image serving with caching and early validation
    $path = $_GET['path'];

    // Fast path validation
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'webm'];

    if (!in_array($ext, $allowedExts) || !str_starts_with($path, '/pr')) {
        $dlurl = 'http://' . privuma::getEnv('CLOUDFS_HTTP_SECONDARY_ENDPOINT') . $path;

        // Add caching headers for external resources
        header('Cache-Control: public, max-age=86400'); // 24 hours
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');

        privuma::accel($dlurl);
        exit();
    }

    // Extract hash efficiently
    $basename = basename($path, '.' . $ext);
    $hash = base64_decode($basename);

    // Use cached tokenizer instance
    $token = getTokenizerInstance()->rollingTokens(privuma::getEnv('AUTHTOKEN'))[1];
    $uri = '/?token=' . $token . '&media=' . urlencode("h-$hash.$ext");

    // Add redirect caching headers
    header('Cache-Control: public, max-age=300'); // 5 minutes
    header('Location: ' . $uri);
    exit();
}

// Serve main HTML file with optimized caching
$htmlFile = 'index.html';
$lastModified = filemtime($htmlFile);

// Check if client has cached version
$etag = '"' . md5_file($htmlFile) . '"';
$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
$ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';

if ($ifNoneMatch === $etag || strtotime($ifModifiedSince) >= $lastModified) {
    header('HTTP/1.1 304 Not Modified');
    exit();
}

// Set caching headers for HTML
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300, must-revalidate'); // 5 minutes with validation
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
header('Content-Length: ' . filesize($htmlFile));

// Output the file efficiently
readfile($htmlFile);
exit();
