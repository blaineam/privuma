<?php
ini_set('memory_limit', '4G');
use privuma\privuma;
use privuma\helpers\cloudFS;
use privuma\actions\preserveMedia;

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
$downloadLocation = $privuma->getEnv('DOWNLOAD_LOCATION');
if (!$downloadLocation) {
    echo PHP_EOL . 'Missing DOWNLOAD_LOCATION environment variable';
    exit();
}

$ops = new cloudFS($downloadLocation, false, '/usr/bin/rclone', null, false);

/**
 * Scan a directory for image thumbnails (.jpg, .png) that are missing a .webp version,
 * pull them from cloud, convert to WebP, and upload the .webp back.
 */
function convertThumbnailsToWebp(cloudFS $ops, string $directory, preserveMedia $pm): int
{
    echo PHP_EOL . "Scanning $directory/ for thumbnails needing WebP conversion";

    $scan = $ops->scandir($directory, true, true, null, false, true, true, true);
    if ($scan === false) {
        echo PHP_EOL . "Failed to scan $directory/";
        return 0;
    }

    // Build key -> [extensions] map, grouping files by directory + base name
    $hashMap = [];
    $pathMap = [];
    foreach ($scan as $item) {
        $file = trim($item['Path'] ?? $item['Name'] ?? '', "\/");
        if (empty($file) || in_array($file, ['.', '..', '/'])) {
            continue;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $baseName = pathinfo($file, PATHINFO_FILENAME);
        $dir = dirname($file);
        $key = ($dir !== '.' ? $dir . '/' : '') . $baseName;
        if (!isset($hashMap[$key])) {
            $hashMap[$key] = [];
            $pathMap[$key] = $key;
        }
        $hashMap[$key][] = $ext;
    }
    unset($scan);

    echo PHP_EOL . 'Found ' . count($hashMap) . " unique file groups in $directory/";

    $thumbnailExts = ['jpg', 'jpeg', 'png'];
    $converted = 0;

    foreach ($hashMap as $key => $exts) {
        // Skip if already has a .webp version
        if (in_array('webp', $exts)) {
            continue;
        }

        // Only convert thumbnails that accompany media files
        $hasMediaCompanion = in_array('mp4', $exts) || in_array('webm', $exts)
            || in_array('swf', $exts) || in_array('gif', $exts);
        if (!$hasMediaCompanion) {
            continue;
        }

        foreach ($exts as $ext) {
            if (!in_array($ext, $thumbnailExts)) {
                continue;
            }

            $srcFile = $directory . '/' . $pathMap[$key] . '.' . $ext;
            echo PHP_EOL . "Converting: $srcFile";

            // createWebP pulls from cloud, converts locally, uploads .webp back
            if ($pm->createWebP($srcFile, false)) {
                $converted++;
            }
        }
    }

    return $converted;
}

$pm = new preserveMedia([], $ops);
$totalConverted = 0;

// Convert VR thumbnails (jpg -> webp)
$totalConverted += convertThumbnailsToWebp($ops, 'vr', $pm);
$totalConverted += convertThumbnailsToWebp($ops, 'fa/vr', $pm);

// Convert Flash thumbnails (png -> webp)
$totalConverted += convertThumbnailsToWebp($ops, 'flash', $pm);
$totalConverted += convertThumbnailsToWebp($ops, 'fa/flash', $pm);

echo PHP_EOL . PHP_EOL . "Total thumbnails converted to WebP: $totalConverted";
echo PHP_EOL . 'DONE!';
