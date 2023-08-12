<?php

use privuma\privuma;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = new privuma();
$ops = $privuma->getCloudFS();

$SYNC_FOLDER = DIRECTORY_SEPARATOR . $privuma->getDataFolder() . DIRECTORY_SEPARATOR;
$DEBUG = true;

function getDirContents($dir, &$results = array()) {
    global $ops;
    global $DEBUG;
    $files = $ops->scandir($dir, true);
    $mediaDir = false;

    if ($DEBUG) {
        echo PHP_EOL. "Checking for media in: ".$dir;
    }
    foreach ($files as $fileObj) {
        $value = $fileObj['Name'];
        $path = $dir . DIRECTORY_SEPARATOR . $value;
        if (!$fileObj['IsDir']) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            if(in_array(strtolower($ext), ['mp4','jpg','jpeg','gif','png','heif']) && strpos($dir, $ops->encode('SCRATCH') === false)) {
                $mediaDir = true;
            }
        } else if ($value != "." && $value != ".." && $value !== "privuma"  && $value !== "@eaDir") {
            getDirContents($path);
        }
    }

    if ($mediaDir) {
        if ($DEBUG) {
            echo PHP_EOL. "Adding .mediadir file";
        }
        $ops->touch($dir . DIRECTORY_SEPARATOR . ".mediadir");
    } else {
        if ($DEBUG) {
            echo PHP_EOL. "Removing .mediadir file";
        }
        $ops->unlink($dir . DIRECTORY_SEPARATOR . ".mediadir");
    }

}

getDirContents($SYNC_FOLDER);

echo PHP_EOL . "marked mediadirs with dot files";


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

// $privuma->getQueueManager()->enqueue(json_encode([
//     'type' => 'cachePath',
//     'data' => [
//         'cacheName' => 'mediadirs',
//         'emptyCache' => true,
//     ],
// ]));

foreach(get_data_dirs($SYNC_FOLDER) as $key => $value) {
    $privuma->getQueueManager()->enqueue(json_encode([
        'type' => 'cachePath',
        'data' => [
            'cacheName' => 'mediadirs',
            'key' => $key,
            'value' => $value,
        ],
    ]));
}

echo PHP_EOL . "mediadirs cache updates queued ";

