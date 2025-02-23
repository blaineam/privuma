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
$opsNoEncodeNoPrefix = new cloudFS($downloadLocation, false, '/usr/bin/rclone', null, false);
$opsPlain = new cloudFS(
    $downloadLocationUnencrypted,
    false,
    '/usr/bin/rclone',
    null,
    false
);

echo PHP_EOL . 'Building list of media to download';
$stmt = $conn->prepare("select filename, album, time, hash, url, thumbnail
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
    "SELECT filename, album, dupe, time, hash, REGEXP_REPLACE(metadata, 'www\.[a-zA-Z0-9\_\.\/\:\-\?\=\&]*|(http|https|ftp):\/\/[a-zA-Z0-9\_\.\/\:\-\?\=\&]*', 'Link Removed') as metadata FROM (SELECT * FROM media WHERE (album = 'Favorites' or blocked = 0) and hash is not null and hash != '' and hash != 'compressed') t1 ORDER BY time desc;"
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

$metaDataFiles = [];
$mobiledata = str_replace('$', 'USD', str_replace("'", '-', str_replace('`', '-', json_encode(
    array_map(function ($item) use (&$metaDataFiles) {
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
        return [
          'album' => sanitizeLine($item['album']),
          'filename' => sanitizeLine(substr($item['filename'], 0, 20)) . '.' . pathinfo($item['filename'], PATHINFO_EXTENSION),
          'hash' => $item['hash'],
          'time' => $item['time'],
          'metadata' => $item['metadata'],
        ];
    }, json_decode($data, true)),
    JSON_THROW_ON_ERROR
))));

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

echo PHP_EOL . 'Downloading Mobile MetaData Stores';
foreach ($metaDataFiles as $prefix => $store) {
    $file = 'pr' . DIRECTORY_SEPARATOR . 'meta' . DIRECTORY_SEPARATOR . $prefix . '.json';
    echo PHP_EOL . 'Storing MetaData to: ' . $file;
    $opsNoEncodeNoPrefix->file_put_contents($file, json_encode($store));
}

echo PHP_EOL . 'Downloading Desktop Dataset';
$data = 'const encrypted_data = ' . $data . ';';
$ops->file_put_contents('encrypted_data.js', $data);
unset($data);

echo PHP_EOL . 'Database Downloads have been completed';
echo PHP_EOL .
  'Checking ' .
  count($dlData) .
  ' media items have been downloaded';

$previouslyDownloadedMedia = array_flip(
    array_map(
        fn ($item) => trim($item, "\/"),
        array_column(
            $ops->scandir('', true, true, null, false, true, true, true),
            'Name'
        )
    )
);

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
          '.mp4',
          '.m2t',
          '.m2ts',
          '.mts',
          '.mkv',
          '.webm',
        ],
        '.mp4',
        $item['filename']
    );
    $preserve = $item['hash'] . '.' . pathinfo($filename, PATHINFO_EXTENSION);
    $thumbnailPreserve = $item['hash'] . '.jpg';
    return !array_key_exists($preserve, $previouslyDownloadedMedia) || (str_contains($preserve, '.mp4') &&
      !array_key_exists($thumbnailPreserve, $previouslyDownloadedMedia));
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
          '.mp4',
          '.m2t',
          '.m2ts',
          '.mts',
          '.mkv',
          '.webm',
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
                if (strpos($filename, '.mp4') !== false) {
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
        strpos($filename, '.mp4') !== false &&
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
                'filename' => str_replace('.mp4', '.jpg', $filename),
                'url' => $item['thumbnail'],
                'download' => $downloadLocation . 'pr' . DIRECTORY_SEPARATOR,
                'hash' => $item['hash'],
              ],
            ])
        );
    }
}
echo PHP_EOL . 'Done queing ' . $newDlCount . ' Media to be downloaded';
