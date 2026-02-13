<?php
ini_set('memory_limit', '4G');
use privuma\privuma;
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
$downloadLocation = $privuma->getEnv('DOWNLOAD_LOCATION');
if (!$downloadLocation) {
    echo PHP_EOL . 'Missing DOWNLOAD_LOCATION environment variable';
    exit();
}

$deletionQueuePath = __DIR__ . '/deletion_queue.txt';

// Phase 2: If deletion queue exists from a previous dry-run, execute the actual deletions
if (file_exists($deletionQueuePath)) {
    echo PHP_EOL . 'Executing queued deletions from previous dry-run';
    passthru("rclone -v --checkers 32 --transfers 32 -P --retries 5 --multi-thread-streams 1 --files-from '$deletionQueuePath' delete $downloadLocation");
    unlink($deletionQueuePath);
    die('DONE!!!!');
}

// Phase 1: Scan and build deletion queue, then dry-run

$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'heic', 'ico', 'tiff'];

$ops = new cloudFS($downloadLocation . 'pr' . DIRECTORY_SEPARATOR, true, '/usr/bin/rclone', null, true);
$opsFavorites = new cloudFS($downloadLocation . 'fa' . DIRECTORY_SEPARATOR, true, '/usr/bin/rclone', null, true);
$opsUnfiltered = new cloudFS($downloadLocation . 'un' . DIRECTORY_SEPARATOR, true, '/usr/bin/rclone', null, true);

/**
 * Scan a download prefix folder and find image files that have a WebP replacement.
 *
 * Rules:
 * - Only delete image files (jpg, jpeg, png, gif, bmp, heic, ico, tiff)
 * - Only delete when hash.webp exists for the same hash
 * - KEEP .jpg if the hash also has .gif or .png (static thumbnail for animated content)
 * - Videos (.mp4, .webm) are never touched
 */
function findWebpDuplicates(cloudFS $ops, string $prefix, array $imageExts): array
{
    echo PHP_EOL . "Scanning $prefix/ for non-WebP images that have a WebP replacement";

    $scan = $ops->scandir('', true, true, null, false, true, true, true);
    if ($scan === false) {
        echo PHP_EOL . "Failed to scan $prefix/";
        return [];
    }

    $files = array_map(
        fn ($item) => trim($item, "\/"),
        array_column($scan, 'Name')
    );
    unset($scan);

    // Build hash -> [extensions] map
    $hashMap = [];
    foreach ($files as $file) {
        if (empty($file) || in_array($file, ['.', '..', '/'])) {
            continue;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $hash = pathinfo($file, PATHINFO_FILENAME);
        if (!isset($hashMap[$hash])) {
            $hashMap[$hash] = [];
        }
        $hashMap[$hash][] = $ext;
    }

    echo PHP_EOL . "Found " . count($hashMap) . " unique hashes in $prefix/";

    $deletions = [];
    foreach ($hashMap as $hash => $exts) {
        if (!in_array('webp', $exts)) {
            continue;
        }

        // Check if this hash has animated source content (gif or png)
        // whose .jpg thumbnail must be preserved
        $hasAnimatedSource = in_array('gif', $exts) || in_array('png', $exts);

        foreach ($exts as $ext) {
            if ($ext === 'webp') {
                continue;
            }
            if (!in_array($ext, $imageExts)) {
                continue;
            }

            // Keep .jpg if it's a static thumbnail for animated content (gif/animated png)
            if ($ext === 'jpg' && $hasAnimatedSource) {
                echo PHP_EOL . "  Keeping $prefix/$hash.jpg (animated content thumbnail)";
                continue;
            }

            echo PHP_EOL . "  Queuing $prefix/$hash.$ext (replaced by $hash.webp)";
            $deletions[] = $prefix . '/' . ltrim($ops->encode("$hash.$ext", true), './');
        }
    }

    echo PHP_EOL . "Found " . count($deletions) . " duplicates in $prefix/";
    return $deletions;
}

$deletions = [];
$deletions = array_merge($deletions, findWebpDuplicates($ops, 'pr', $imageExts));
$deletions = array_merge($deletions, findWebpDuplicates($opsFavorites, 'fa', $imageExts));
$deletions = array_merge($deletions, findWebpDuplicates($opsUnfiltered, 'un', $imageExts));

echo PHP_EOL . PHP_EOL . 'Total files to delete: ' . count($deletions);

if (count($deletions) === 0) {
    echo PHP_EOL . 'Nothing to clean up';
} else {
    file_put_contents($deletionQueuePath, implode(PHP_EOL, $deletions));
    echo PHP_EOL . PHP_EOL . '=== DRY RUN ===' . PHP_EOL;
    passthru("rclone -v --checkers 32 --transfers 32 -P --retries 5 --multi-thread-streams 1 --files-from '$deletionQueuePath' --dry-run delete $downloadLocation");
    echo PHP_EOL . PHP_EOL . 'Review the above dry-run output. Run this job again to execute the deletions.';
}

echo PHP_EOL . 'DONE!';
