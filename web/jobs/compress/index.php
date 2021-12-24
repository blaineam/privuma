<?php


require(__DIR__ . '/../../helpers/cloud-fs-operations.php'); 

$ops = new cloudFS\Operations();

$SYNC_FOLDER = "/data/privuma/";
$DEBUG = false;

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

    $newFileTemp = tempnam(sys_get_temp_dir(), 'PVMA');
    rename($newFileTemp, $newFileTemp . '.jpg');
    $newFileTemp = $newFileTemp  . '.jpg';
    
    exec("/usr/bin/ffmpeg -threads 1 -hide_banner -loglevel error -y -ss `/usr/bin/ffmpeg -threads 1 -y -i '" . $tempFile . "' 2>&1 | grep Duration | awk '{print $2}' | tr -d , | awk -F ':' '{print ($3+$2*60+$1*3600)/2}'` -i '" . $tempFile . "' -vcodec mjpeg -vframes 1 -an -f rawvideo '" . $newFileTemp . "' > /dev/null", $void, $response);

    if ($response !== 0) {
        unset($response);
        unset($void);
        exec("/usr/bin/ffmpeg -threads 1 -hide_banner -loglevel error -y -ss 00:00:01.00 -i '" . $tempFile . "' -vcodec mjpeg -vframes 1 -an -f rawvideo '" . $newFileTemp . "' > /dev/null", $void, $response); 
    }

    unset($void);

    if($response == 0){
        echo PHP_EOL . "Thumbnail generation was successful for: " . $filename;

        $ops->copy($newFileTemp, $newFilePath, false);

    }

    unlink($tempFile);
    unlink($newFileTemp);


}


function compressVideo($filePath) {
    global $ops;
    echo PHP_EOL. "Compressing video: ".$filePath;    
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);

    $filename = basename($filePath, ".".$ext);
    $newFilePath = dirname($filePath) . DIRECTORY_SEPARATOR . $filename . "---compressed.mp4";

    $tempFile = $ops->pull($filePath);

    $newFileTemp = tempnam(sys_get_temp_dir(), 'PVMA');
    rename($newFileTemp, $newFileTemp . '.mp4');
    $newFileTemp = $newFileTemp  . '.mp4';
    
    exec("/usr/bin/ffmpeg -threads 1 -hide_banner -loglevel error -y -i '".$tempFile."' -c:v h264 -crf 24 -c:a aac -movflags frag_keyframe+empty_moov  -vf \"scale='min(1920,iw+mod(iw,2))':'min(1080,ih+mod(ih,2)):flags=neighbor'\" '".$newFileTemp."'", $void, $response);

    unset($void);

    if($response == 0){
        echo PHP_EOL . "Video conversion was successful for: " . $filename;

        $ops->copy($newFileTemp, $newFilePath, false);
        
        $ops->unlink($filePath);

    }

    unlink($tempFile);
    unlink($newFileTemp);
}

function compressPhoto($filePath){
    global $ops;
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    echo PHP_EOL."Compressing image: ".$filePath;
    $filename = basename($filePath, ".".$ext);
    $newFilePath = dirname($filePath) . DIRECTORY_SEPARATOR . $filename . "---compressed." . $ext;

    $tempFile = $ops->pull($filePath);
    rename($tempFile, $tempFile . '.' . $ext);
    $tempFile = $tempFile . '.' . $ext;

    $newFileTemp = tempnam(sys_get_temp_dir(), 'PVMA');
    rename($newFileTemp, $newFileTemp . '.' . $ext);
    $newFileTemp = $newFileTemp . '.' . $ext;

    if (strtolower($ext) === "gif") {
	    exec("/usr/bin/gifsicle --conserve-memory --no-ignore-errors --no-warnings --colors=72 -O3 --lossy=100 --color-method=median-cut --resize-fit 1920x1920 '" . $tempFile . "' -o '" . $newFileTemp . "'", $void, $response);
	    unset($void);
	    if($response == 0 ) {
            $ops->copy($newFileTemp, $newFilePath, false);
            
            $ops->unlink($filePath);
	    }
    } else { 
        $path = '/usr/local/bin/mogrify';
        exec($path . ' -h 2>&1', $test, $binNotFound);
        if($binNotFound !== 0){
               $path = '/usr/bin/mogrify';
        }
	    exec($path . " -resize 1920x1920 -quality 60 -fuzz 7% '".$ext.':'.$tempFile."'");
	    
        $ops->copy($tempFile, $newFilePath, false);
            
        $ops->unlink($filePath);
    }
    unlink($tempFile);
    unlink($newFileTemp);
}

function processFilePath($filePath) {
    global $ops;
    global $DEBUG;
    $allowedPhotos = ["BMP", "GIF", "HEIC", "ICO", "JPG", "JPEG", "PNG", "TIFF", "WEBP"];
    if($ops->is_dir($filePath)) {
        return;
    }

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

    if (strpos(basename($filePath, "." . $ext), "---compressed") !== false) {

        if(strtolower($ext) === "mp4") {
            if (!($ops->is_file(basename($filePath, "---compressed." . $ext) . ".jpg") || $ops->is_file(basename($filePath, "." . $ext) . ".jpg"))) {
                if ($DEBUG) {
                    echo PHP_EOL. "Missing video thumbnail, regenerating one now";
                }

                generateThumbnail($filePath);
            }
        }

        if ($DEBUG) {
	        echo PHP_EOL. "File has already been compressed, skipping file";
        }

	    return;
    }

    $filename = basename($filePath, "." . $ext);
    $fileParts = explode('---', $filename);

    if (count($fileParts) < 2 || $fileParts[1] !== $ops->md5_file($filePath)) {
        if ($DEBUG) {
            echo  PHP_EOL."Missing or mismatched MD5 file hash";
        }

        return;
    }

    if(in_array(strtoupper($ext), $allowedPhotos)) {
        compressPhoto($filePath);
    } else if(strtolower($ext) === "mp4") {
    	compressVideo($filePath);
    }
}