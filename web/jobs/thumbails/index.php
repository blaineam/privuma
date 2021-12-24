<?php


require(__DIR__ . '/../../helpers/cloud-fs-operations.php'); 

$ops = new cloudFS\Operations();

$SYNC_FOLDER = "/data";
$DEBUG = true;

function getDirContents($dir, &$results = array()) {
    global $ops;
    $files = $ops->scandir($dir, true);

    foreach ($files as $obj) {
        $value = $obj['Name'];
        $path = $dir . DIRECTORY_SEPARATOR . $value;
        if (!$obj['IsDir']) {
            processFilePath($path);
        } else if ($value != "." && $value != "..") {
            getDirContents($path);
        }
    }
}

getDirContents($SYNC_FOLDER);


function generateThumbnail($filePath) {

    global $ops;
    echo PHP_EOL. "Regenerating video thumbnail: ".$filePath;    
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);

    $filename = basename($filePath, ".".$ext);
    $newFilePath = dirname($filePath) . DIRECTORY_SEPARATOR . str_replace('---compressed.', '.', $filename) . ".jpg";

    $tempFile = $ops->pull($filePath);
    rename($tempFile, $tempFile . '.' . $ext);
    $tempFile = $tempFile  . '.' . $ext;
    echo PHP_EOL."File was pulled to work on";

    $newFileTemp = tempnam(sys_get_temp_dir(), 'PVMA');
    rename($newFileTemp, $newFileTemp . '.jpg');
    $newFileTemp = $newFileTemp  . '.jpg';

    $cmd = "/usr/bin/ffmpeg -threads 1 -hide_banner -loglevel error -y -ss `/usr/bin/ffmpeg -threads 1 -y -i '" . $tempFile . "' 2>&1 | grep Duration | awk '{print $2}' | tr -d , | awk -F ':' '{print ($3+$2*60+$1*3600)/2}'` -i '" . $tempFile . "' -vcodec mjpeg -vframes 1 -an -f rawvideo  '" . $newFileTemp . "'";

    var_dump($cmd);
    
    exec($cmd, $void, $response);

    if ($response !== 0) {
        unset($response);
        unset($void);
        exec("/usr/bin/ffmpeg -threads 1 -hide_banner -loglevel error -y -ss 00:00:01.00 -i '" . $tempFile . "' -vcodec mjpeg -vframes 1 -an -f rawvideo '" . $newFileTemp . "' > /dev/null", $void, $response); 
    }

    unset($void);

    if($response == 0){
        echo PHP_EOL . "Thumbnail generation was successful for: " . $filename;

        $ops->copy($newFileTemp, $newFilePath, false);

    } else {
        echo PHP_EOL . "Could not generate thumbnail";
    }

    unlink($tempFile);
    unlink($newFileTemp);


}

function processFilePath($filePath) {
    global $ops;
    global $DEBUG;

    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    if ($ext == "DS_Store") {
        return;
    }

    if (strpos(basename($filePath, "." . $ext), "---dupe") !== false) {
        if ($DEBUG) {
            echo PHP_EOL. "File is not original, skipping reference file";
        }

	    return;
    }

    if(strtolower($ext) === "mp4") {
        if (!$ops->is_file(dirname($filePath) . DIRECTORY_SEPARATOR . basename($filePath, "." . $ext) . ".jpg")) {
            if ($DEBUG) {
                echo PHP_EOL. "Missing video thumbnail, regenerating one now";
            }

            generateThumbnail($filePath);
        }
    }
}