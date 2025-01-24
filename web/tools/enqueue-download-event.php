<?php

use privuma\privuma;
use privuma\helpers\mediaFile;
use privuma\helpers\tokenizer;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = privuma::getInstance();
$tokenizer = new tokenizer();
$downloadLocation = $privuma->getEnv('DOWNLOAD_LOCATION');

if ($argc > 1) {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}

$albumArg = $_GET['album'] ?? '';
$filenameArg = $_GET['filename'] ?? '';
$hashArg = $_GET['hash'] ?? null;
$item = (new mediaFile($filenameArg, $albumArg, null, $hashArg))->record();

$album = $item['album'];
$filename = str_replace(['.mpg', '.mod', '.mmv', '.tod', '.wmv', '.asf', '.avi', '.divx', '.mov', '.m4v', '.3gp', '.3g2', '.mp4', '.m2t', '.m2ts', '.mts', '.mkv', '.webm'], '.mp4', $item['filename']);

$preserve = $item['hash'] . '.' . pathinfo($filename, PATHINFO_EXTENSION);
$thumbnailPreserve = $item['hash'] . '.jpg';
$path = privuma::getDataFolder() . DIRECTORY_SEPARATOR . (new mediaFile($item['filename'], $item['album']))->path();
$thumbnailPath = str_replace('.mp4', '.jpg', $path);
if (!isset($item['url'])) {
    if ($item['url'] = $privuma->getCloudFS()->public_link($path) ?: $tokenizer->mediaLink($path, false, false, true)) {
        if (strpos($filename, '.mp4') !== false) {
            $item['thumbnail'] = $privuma->getCloudFS()->public_link($thumbnailPath) ?: $tokenizer->mediaLink($thumbnailPath, false, false, true);
        }
    } else {
        echo PHP_EOL . "Skipping unavailable media: $path";
        return;
    }
}
echo PHP_EOL . 'Queue Downloading of media file: ' . $preserve . ' from album: ' . $item['album'] . ' with potential thumbnail: ' . ($item['thumbnail'] ?? 'No thumbnail');
$privuma->getQueueManager()->enqueue(json_encode([
    'type' => 'processMedia',
    'data' => [
        'album' => $album,
        'filename' => $filename,
        'url' => $item['url'],
        'thumbnail' => $item['thumbnail'],
        'download' => $downloadLocation,
        'hash' => $item['hash'],
    ],
]));
