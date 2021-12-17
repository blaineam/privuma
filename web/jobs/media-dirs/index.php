<?php


require(__DIR__ . '/../../helpers/cloud-fs-operations.php'); 

$ops = new cloudFS\Operations();

$SYNC_FOLDER = "/data/";
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
            if(in_array(strtolower($ext), ['mp4','jpg','jpeg','gif','png','heif'])) {
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