<?php
$SYNC_FOLDER = __DIR__ . "/../../data/privuma/";
$DEBUG = false;

function getDirContents($dir, &$results = array()) {
    $files = scandir($dir);

    foreach ($files as $key => $value) {
        $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
        if (!is_dir($path)) {
            processFilePath($path);
        } else if ($value != "." && $value != "..") {
            getDirContents($path);
        }
    }
}

getDirContents($SYNC_FOLDER);

function compressVideo($filePath) {
    echo PHP_EOL. "Compressing video: ".$filePath;    
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);

    $filename = basename($filePath, ".".$ext);
    $newFilePath = dirname($filePath) . DIRECTORY_SEPARATOR . $filename . "---compressed.mp4";
    
    exec("/usr/bin/ffmpeg -threads 1 -hide_banner -loglevel error -y -i '".$filePath."' -c:v h264 -crf 24 -c:a aac -movflags frag_keyframe+empty_moov  -vf \"scale='min(1920,iw+mod(iw,2))':'min(1080,ih+mod(ih,2)):flags=neighbor'\" '".$newFilePath."'", $void, $response);
    
    unset($void);

    if($response == 0){
        echo PHP_EOL . "Video Conversion Was Successful for: " . $filename;
        
        unlink($filePath);
    }
}

function compressPhoto($filePath){
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    echo PHP_EOL."Compressing image: ".$filePath;
    $filename = basename($filePath, ".".$ext);
    $newFilePath = dirname($filePath) . DIRECTORY_SEPARATOR . $filename . "---compressed." . $ext;

    if (strtolower($ext) === "gif") {
	    exec("/usr//bin/gifsicle --colors=72 -O3 --lossy=100 --color-method=median-cut --resize-fit 1920x1920 '" . $filePath . "' -o '" . $newFilePath . "'", $void, $response);
	    unset($void);
	    if($response == 0 ) {
		unlink($filePath);
	    }
    } else {
	    exec("/usr/local/bin/mogrify -resize 1920x1920 -quality 60 -fuzz 7% '".$filePath."'");
	    rename($filePath, $newFilePath);
    }
}

function processFilePath($filePath) {
    global $DEBUG;
    $allowedPhotos = ["BMP", "GIF", "HEIC", "ICO", "JPG", "JPEG", "PNG", "TIFF", "WEBP"];
    if(is_dir($filePath)) {
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
        if ($DEBUG) {
	        echo PHP_EOL. "File has already been compressed, skipping file";
        }

	    return;
    }

    $filename = basename($filePath, "." . $ext);
    $fileParts = explode('---', $filename);

    if (count($fileParts) < 2 || $fileParts[1] !== md5_file($filePath)) {
        if ($DEBUG) {
            echo  PHP_EOL."Missing or Mismatched MD5 file hash";
        }

        return;
    }

    if(in_array(strtoupper($ext), $allowedPhotos)) {
        compressPhoto($filePath);
    } else if(strtolower($ext) === "mp4") {
    	compressVideo($filePath);
    }
}