<?php
ini_set('memory_limit', '2G');
use privuma\privuma;
use privuma\helpers\mediaFile;
use privuma\helpers\tokenizer;
use privuma\helpers\cloudFS;

require_once __DIR__ .
  DIRECTORY_SEPARATOR .
  '..' .
  DIRECTORY_SEPARATOR .
  '..' .
  DIRECTORY_SEPARATOR .
  '..' .
  DIRECTORY_SEPARATOR .
  'app' .
  DIRECTORY_SEPARATOR .
  'privuma.php';

$privuma = privuma::getInstance();
$tokenizer = new tokenizer();
$downloadLocation = $privuma->getEnv('DOWNLOAD_LOCATION');
if (!$downloadLocation) {
    exit();
}
$downloadLocationUnencrypted = $privuma->getEnv(
    'DOWNLOAD_LOCATION_UNENCRYPTED'
);
if (!$downloadLocationUnencrypted) {
    exit();
}

$conn = $privuma->getPDO();

$ops = new cloudFS($downloadLocation . 'pr' . DIRECTORY_SEPARATOR, true, '/usr/bin/rclone', null, true);
$opsFavorites = new cloudFS($downloadLocation . 'fa' . DIRECTORY_SEPARATOR, true, '/usr/bin/rclone', null, true);
$opsNoEncodeNoPrefix = new cloudFS($downloadLocation, false, '/usr/bin/rclone', null, false);
$opsPlain = new cloudFS(
    $downloadLocationUnencrypted,
    false,
    '/usr/bin/rclone',
    null,
    false
);

echo PHP_EOL . 'Building list of media to download';
$stmt = $conn->prepare("select filename, album, time, hash, url, thumbnail, duration, sound
from media
where hash is not null
and hash != ''
and hash != 'compressed'
and (album = 'Favorites' or blocked = 0)
and (dupe = 0 or album = 'Favorites')
group by hash
 order by
    time DESC");
$stmt->execute();
$dlData = $stmt->fetchAll();
echo PHP_EOL . 'Building web app payload of media to download';
$stmt = $conn->prepare(
    "SELECT filename, album, dupe, time, hash, duration, sound, REGEXP_REPLACE(metadata, 'www\.[a-zA-Z0-9\_\.\/\:\-\?\=\&]*|(http|https|ftp):\/\/[a-zA-Z0-9\_\.\/\:\-\?\=\&]*', 'Link Removed') as metadata FROM (SELECT * FROM media WHERE (album = 'Favorites' or blocked = 0) and hash is not null and hash != '' and hash != 'compressed') t1 ORDER BY time desc;"
);
$stmt->execute();
$data = str_replace('`', '', json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)));
function sanitizeLine($line)
{
    return trim(preg_replace('/[^A-Za-z0-9 \\-\\_\\~\\+\\(\\)\\.\\,\\/]/', '', $line), "\r\n");
}

function trimExtraNewLines($string)
{
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

function parseMetaData($item)
{
    $dateValue = explode(PHP_EOL, explode('Date: ', $item)[1] ?? '')[0];
    $intval = filter_var($dateValue, FILTER_VALIDATE_INT);
    if ($intval) {
        $dateValue = '@' . substr($dateValue, 0, 10);
    }
    return [
      'title' => explode(PHP_EOL, explode('Title: ', $item)[1] ?? '')[0],
      'author' => explode(PHP_EOL, explode('Author: ', $item)[1] ?? '')[0],
      'date' => new DateTime(
          $dateValue
      ),
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
$mobiledata = str_replace('$', 'USD', str_replace("'", '-', str_replace('`', '-', json_encode(
    array_values(array_filter($array, function ($item) {
        return !in_array('Favorites', $item['albums']);
    })),
    JSON_THROW_ON_ERROR
))));

$favorites = str_replace('$', 'USD', str_replace("'", '-', str_replace('`', '-', json_encode(
    array_values(array_filter($array, function ($item) {
        return in_array('Favorites', $item['albums']);
    })),
    JSON_THROW_ON_ERROR
))));

unset($array);

echo PHP_EOL . 'All Database Lookup Operations have been completed.';

$viewerHTML = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'viewer' . DIRECTORY_SEPARATOR . 'index.html');

$viewerHTML = str_replace(
    '{{ENDPOINT}}',
    privuma::getEnv('ENDPOINT'),
    $viewerHTML
);

echo PHP_EOL . 'Downloading Offline Web App Viewer HTML File';
$opsPlain->file_put_contents('index.html', $viewerHTML);
$opsNoEncodeNoPrefix->file_put_contents('index.html', $viewerHTML);
unset($viewerHTML);

echo PHP_EOL . 'Downloading encrypted database offline website payload';
echo PHP_EOL . 'Downloading Mobile Dataset';
$mobiledata = "const encrypted_data = '" . $mobiledata . "';";
$ops->file_put_contents('encrypted_mobile_data.js', $mobiledata);
unset($mobiledata);

$opsNoEncodeNoPrefix->file_put_contents('fa/favorites.json', $favorites);

$previouslyDownloadedMedia = array_flip(
    array_map(
        fn ($item) => trim($item, "\/"),
        array_column(
            $ops->scandir('', true, true, null, false, true, true, true),
            'Name'
        )
    )
);

echo PHP_EOL . 'Scanning favorites';
$favoritesJson = json_decode($favorites, true) ;
$existingFavoritesPaths = array_flip(
    array_map(
        fn ($item) => trim($item, "\/"),
        array_column(
            $opsFavorites->scandir('', true, true, null, false, true, true, true),
            'Path'
        )
    )
);
$existingFavorites = array_map(function ($item) {
    return basename($item);
}, $existingFavoritesPaths);
$newFavorites = array_filter($favoritesJson, function ($item) use (
    $existingFavorites,
    $previouslyDownloadedMedia
) {
    $filename = str_replace(
        [
          '.mpg',
          '.mod',
          '.mmv',
          '.tod',
          '.wmv',
          '.asf',
          '.avi',
          '.divx',
          '.mov',
          '.m4v',
          '.3gp',
          '.3g2',
          '.m2t',
          '.m2ts',
          '.mts',
          '.mkv',
        ],
        '.mp4',
        $item['filename']
    );
    $preserve = $item['hash'] . '.' . pathinfo($filename, PATHINFO_EXTENSION);
    $thumbnailPreserve = $item['hash'] . '.jpg';
    $isAnimated = (str_contains($preserve, '.webm') || str_contains($preserve, '.mp4') || str_contains($preserve, '.gif'));
    $newFavorite = !array_key_exists($preserve, $existingFavorites) || ($isAnimated && !array_key_exists($thumbnailPreserve, $existingFavorites));
    $fileExists = array_key_exists($preserve, $previouslyDownloadedMedia) && (!$isAnimated || array_key_exists($thumbnailPreserve, $previouslyDownloadedMedia));
    return $newFavorite && $fileExists;
});

echo PHP_EOL .
  'Found ' .
  count($newFavorites) .
  ' New Favorites';

$favoritesNames = array_map(function ($item) {
    $filename = str_replace(
        [
          '.mpg',
          '.mod',
          '.mmv',
          '.tod',
          '.wmv',
          '.asf',
          '.avi',
          '.divx',
          '.mov',
          '.m4v',
          '.3gp',
          '.3g2',
          '.m2t',
          '.m2ts',
          '.mts',
          '.mkv',
        ],
        '.mp4',
        $item['filename']
    );
    $preserve = $item['hash'] . '.' . pathinfo($filename, PATHINFO_EXTENSION);
    $thumbnailPreserve = $item['hash'] . '.jpg';
    return ['name' => $preserve, 'thumbnail' => $thumbnailPreserve];
}, $favoritesJson);
$removedFavorites = array_filter($existingFavoritesPaths, function ($name) use (
    $favoritesNames
) {
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    return !array_key_exists(basename($name), array_column($favoritesNames, 'name')) && !array_key_exists(basename($name), array_column($favoritesNames, 'thumbnail')) && count(explode(DIRECTORY_SEPARATOR, $name)) > 1 && in_array(strtolower($ext), ['webm', 'mp4', 'gif', 'jpg', 'png']);
});

echo PHP_EOL .
  'Found ' .
  count($removedFavorites) .
  ' Removed Favorites';

echo PHP_EOL . 'Syncing favorites';
foreach ($newFavorites as $favorite) {
    $ext = pathinfo($favorite['filename'], PATHINFO_EXTENSION);
    $src = cloudFS::canonicalize('pr' . DIRECTORY_SEPARATOR . cloudFS::encode($favorite['hash'] . '.' . $ext, true));
    $dst = cloudFS::canonicalize('fa' . DIRECTORY_SEPARATOR . cloudFS::encode($favorite['hash'] . '.' . $ext, true));
    echo PHP_EOL . 'Moving favorite: ' . $src . '  to: ' . $dst;
    $opsNoEncodeNoPrefix->rename($src, $dst, true);
    if (in_array(strtolower($ext), ['webm', 'mp4', 'gif'])) {
        $src = cloudFS::canonicalize('pr' . DIRECTORY_SEPARATOR . cloudFS::encode($favorite['hash'] . '.' . 'jpg', true));
        $dst = cloudFS::canonicalize('fa' . DIRECTORY_SEPARATOR . cloudFS::encode($favorite['hash'] . '.' . 'jpg', true));
        echo PHP_EOL . 'Moving favorite: ' . $src . '  to: ' . $dst;
        $opsNoEncodeNoPrefix->rename($src, $dst, true);
    }
}

foreach ($removedFavorites as $name) {
    $src = cloudFS::canonicalize('fa' . DIRECTORY_SEPARATOR . $name);
    $dst = cloudFS::canonicalize('pr' . DIRECTORY_SEPARATOR . $name);
    echo PHP_EOL . 'Moving de-favorited media: ' . $src . '  to: ' . $dst;
    $opsNoEncodeNoPrefix->rename($src, $dst, true);
}

echo PHP_EOL . 'Downloading Mobile MetaData Stores';
foreach ($metaDataFiles as $prefix => $item) {
    $file = 'pr' . DIRECTORY_SEPARATOR . 'meta' . DIRECTORY_SEPARATOR . $prefix . '.json';
    echo PHP_EOL . 'Storing MetaData to: ' . $file;
    $opsNoEncodeNoPrefix->file_put_contents($file, json_encode($item));
}
foreach ($metaDataFiles as $prefix => $item) {
    $file = 'fa' . DIRECTORY_SEPARATOR . 'meta' . DIRECTORY_SEPARATOR . $prefix . '.json';
    echo PHP_EOL . 'Storing MetaData to: ' . $file;
    $opsNoEncodeNoPrefix->file_put_contents($file, json_encode($item));
}

echo PHP_EOL . 'Downloading Desktop Dataset';
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
    array_values(array_filter($array, function ($item) {
        return !in_array('Favorites', $item['albums']);
    })),
    JSON_THROW_ON_ERROR
))));
$data = 'const encrypted_data = ' . $data . ';';
$ops->file_put_contents('encrypted_data.js', $data);
unset($data);

echo PHP_EOL . 'Database Downloads have been completed';

echo PHP_EOL .
  'Checking ' .
  count($dlData) .
  ' media items have been downloaded';

echo PHP_EOL .
  'Filtering ' .
  count($previouslyDownloadedMedia) .
  ' media items already downloaded';

$dlData = array_filter($dlData, function ($item) use (
    $previouslyDownloadedMedia
) {
    $filename = str_replace(
        [
          '.mpg',
          '.mod',
          '.mmv',
          '.tod',
          '.wmv',
          '.asf',
          '.avi',
          '.divx',
          '.mov',
          '.m4v',
          '.3gp',
          '.3g2',
          '.m2t',
          '.m2ts',
          '.mts',
          '.mkv',
        ],
        '.mp4',
        $item['filename']
    );
    $preserve = $item['hash'] . '.' . pathinfo($filename, PATHINFO_EXTENSION);
    $thumbnailPreserve = $item['hash'] . '.jpg';
    return !array_key_exists($preserve, $previouslyDownloadedMedia) || ((str_contains($preserve, '.webm') || str_contains($preserve, '.mp4')) &&
      !array_key_exists($thumbnailPreserve, $previouslyDownloadedMedia));
});

$existingFavorites = array_flip(
    array_map(
        fn ($item) => trim($item, "\/"),
        array_column(
            $opsFavorites->scandir('', true, true, null, false, true, true, true),
            'Name'
        )
    )
);

echo PHP_EOL .
  'Filtering ' .
  count($existingFavorites) .
  ' Favorited media items already downloaded';

$dlData = array_filter($dlData, function ($item) use (
    $existingFavorites
) {
    $filename = str_replace(
        [
          '.mpg',
          '.mod',
          '.mmv',
          '.tod',
          '.wmv',
          '.asf',
          '.avi',
          '.divx',
          '.mov',
          '.m4v',
          '.3gp',
          '.3g2',
          '.m2t',
          '.m2ts',
          '.mts',
          '.mkv',
        ],
        '.mp4',
        $item['filename']
    );
    $preserve = $item['hash'] . '.' . pathinfo($filename, PATHINFO_EXTENSION);
    $thumbnailPreserve = $item['hash'] . '.jpg';
    return !array_key_exists($preserve, $existingFavorites) || ((str_contains($preserve, '.webm') || str_contains($preserve, '.mp4')) &&
      !array_key_exists($thumbnailPreserve, $existingFavorites));
});

echo PHP_EOL . 'Found ' . count($dlData) . ' new media items to be downloaded';

$progress = 0;
$total = count($dlData);
$lastProgress = 0;
$newDlCount = 0;
foreach ($dlData as $item) {
    $progress++;
    $percentage = round(($progress / $total) * 100, 2);
    if ($percentage > $lastProgress + 5) {
        echo PHP_EOL . "Overall Progress: {$percentage}% ";
        $lastProgress = $percentage;
    }
    $album = $item['album'];
    $filename = str_replace(
        [
          '.mpg',
          '.mod',
          '.mmv',
          '.tod',
          '.wmv',
          '.asf',
          '.avi',
          '.divx',
          '.mov',
          '.m4v',
          '.3gp',
          '.3g2',
          '.m2t',
          '.m2ts',
          '.mts',
          '.mkv',
        ],
        '.mp4',
        $item['filename']
    );

    $preserve = $item['hash'] . '.' . pathinfo($filename, PATHINFO_EXTENSION);
    $thumbnailPreserve = $item['hash'] . '.jpg';
    $path =
      privuma::getDataFolder() .
      DIRECTORY_SEPARATOR .
      (new mediaFile($item['filename'], $item['album']))->path();
    $path = $privuma->getOriginalPath($path) ?: $path;
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $thumbnailPath = dirname($path) . DIRECTORY_SEPARATOR . basename($path, '.' . $ext) . '.jpg';
    if (!$ops->is_file($preserve)) {
        if (!isset($item['url'])) {
            if (
                $item['url'] =
                  $privuma->getCloudFS()->public_link($path) ?:
                  $tokenizer->mediaLink($path, false, false, true)
            ) {
                if (strpos($filename, '.webm') !== false || strpos($filename, '.mp4') !== false) {
                    $item['thumbnail'] =
                      $privuma->getCloudFS()->public_link($thumbnailPath) ?:
                      $tokenizer->mediaLink($thumbnailPath, false, false, true);
                }
            } else {
                echo PHP_EOL . "Skipping unavailable media: $path";
                continue;
            }
        }
        echo PHP_EOL .
          'Queue Downloading of media file: ' .
          $preserve .
          ' from album: ' .
          $item['album'] .
          ' with potential thumbnail: ' .
          ($item['thumbnail'] ?? 'No thumbnail');
        $privuma->getQueueManager()->enqueue(
            json_encode([
              'type' => 'processMedia',
              'data' => [
                'album' => $album,
                'filename' => $filename,
                'url' => $item['url'],
                'thumbnail' => $item['thumbnail'],
                'download' => $downloadLocation . 'pr' . DIRECTORY_SEPARATOR,
                'hash' => $item['hash'],
              ],
            ])
        );
        $newDlCount++;
    } elseif (
        (strpos($filename, '.webm') !== false || strpos($filename, '.mp4') !== false) &&
        is_null($item['thumbnail']) &&
        !$ops->is_file($thumbnailPreserve) &&
        ($item['thumbnail'] =
          $privuma->getCloudFS()->public_link($thumbnailPath) ?:
          $tokenizer->mediaLink($thumbnailPath, false, false, true))
    ) {
        echo PHP_EOL .
          'Queue Downloading of media file: ' .
          $thumbnailPreserve .
          ' from album: ' .
          $item['album'];
        $privuma->getQueueManager()->enqueue(
            json_encode([
              'type' => 'processMedia',
              'data' => [
                'album' => $album,
                'filename' => str_replace('.webm', '.jpg', str_replace('.mp4', '.jpg', $filename)),
                'url' => $item['thumbnail'],
                'download' => $downloadLocation . 'pr' . DIRECTORY_SEPARATOR,
                'hash' => $item['hash'],
              ],
            ])
        );
    }
}
echo PHP_EOL . 'Done queing ' . $newDlCount . ' Media to be downloaded';
