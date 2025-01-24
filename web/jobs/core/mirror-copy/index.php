<?php

use privuma\privuma;
use privuma\helpers\cloudFS;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = new privuma();
$RCLONE_MIRROR = $privuma->getEnv('RCLONE_MIRROR');
$RCLONE_DESTINATION = $privuma->getEnv('RCLONE_DESTINATION');

$MAX_DEPTH = 20;
if (!$privuma->getEnv('MIRROR_FILES')) {
    exit();
}

var_dump([$RCLONE_DESTINATION, $RCLONE_MIRROR]);

$opsDest = new cloudFS($RCLONE_DESTINATION, false);
$currentDepth = 0;
function syncEncodedPath($path)
{
    global $opsDest;
    global $RCLONE_DESTINATION;
    global $RCLONE_MIRROR;
    global $currentDepth;
    global $MAX_DEPTH;

    if ($currentDepth > $MAX_DEPTH) {
        echo PHP_EOL . 'MAX DEPTH reached in recursion';
        return;
    };
    $currentDepth++;

    $scan = $opsDest->scandir($path, true);
    if ($scan === false) {
        $scan = [];
    }
    foreach ($scan as $child) {
        $childPath = $child['Name'];
        if ($childPath === '.DS_Store') {
            continue;
        }
        $target = str_replace(DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $path . DIRECTORY_SEPARATOR . $childPath);
        if (!$child['IsDir']) {
            if ((strpos($opsDest->decode($target), 'privuma') === false && strpos($opsDest->decode($target), '.webm') === false) || strpos($opsDest->decode($target), '.mp4') !== false || strpos($opsDest->decode($target), '.jpeg') !== false || strpos($opsDest->decode($target), '.jpg') !== false || strpos($opsDest->decode($target), '.gif') !== false || strpos($opsDest->decode($target), '.png') !== false || strpos($opsDest->decode($target), '.pdf') !== false) {
                if ($opsDest->sync($RCLONE_DESTINATION . $target, $RCLONE_MIRROR . dirname($target), false, false, false,  ['--track-renames --ignore-existing --size-only --transfers 2 --checkers 2  --s3-chunk-size 64M '])) {
                    echo PHP_EOL . 'synced: ' . $target;
                }
            }
        } elseif ($child['IsDir']) {
            if (!in_array(basename($opsDest->decode($target)), ['@eaDir'])) {
                echo PHP_EOL . $target;
                syncEncodedPath($target);
            }
        }
    }
}
syncEncodedPath(DIRECTORY_SEPARATOR . 'ZGF0YQ==');

$opsDest = new cloudFS($RCLONE_DESTINATION, false);
$currentDepth = 0;
function syncNoEncodePath($path)
{
    global $opsDest;
    global $RCLONE_DESTINATION;
    global $RCLONE_MIRROR;

    global $currentDepth;
    global $MAX_DEPTH;

    if ($currentDepth > $MAX_DEPTH) {
        echo PHP_EOL . 'MAX DEPTH reached in recursion';
        return;
    };
    $currentDepth++;

    $scan = $opsDest->scandir($path, true);
    if ($scan === false) {
        $scan = [];
    }
    foreach ($scan as $child) {
        $childPath = $child['Name'];
        if ($childPath === '.DS_Store') {
            continue;
        }
        $target = str_replace(DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $path . DIRECTORY_SEPARATOR . $childPath);
        if (!$child['IsDir']) {
            if (strpos($opsDest->decode($target), '.lock') === false || strpos($opsDest->decode($target), '.txt') === false) {
                if ($opsDest->sync($RCLONE_DESTINATION . $target, $RCLONE_MIRROR . dirname($target), false, false, false,  ['--track-renames --ignore-existing --size-only --transfers 2 --checkers 2  --s3-chunk-size 64M '])) {
                    echo PHP_EOL . 'synced: ' . $target;
                }
            }
        } elseif ($child['IsDir']) {
            if (!in_array(basename($opsDest->decode($target)), ['ZGF0YQ==', 'data', '#recycle', '@eaDir']) && !(strpos($opsDest->decode($target), 'jobs') !== false && in_array(basename($opsDest->decode($target)), ['scratch']))) {
                echo PHP_EOL . $target;
                syncNoEncodePath($target);
            }
        }
    }
}
syncNoEncodePath(DIRECTORY_SEPARATOR);
