<?php

$dest = realpath(__DIR__ . '/../../data/privuma');


function getDirContents($dir, &$results = array())
{
    $files = scandir($dir);
    foreach ($files as $key => $value) {
        $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
        if (!is_dir($path)) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $filename = basename($path, "." . $ext);
            $filenameParts = explode("---", $filename);

            if (count($filenameParts) > 1 && $ext === "mp4" && end($filenameParts) == "compressed") {
                // attempt fast start
                exec("/usr/bin/ffmpeg -threads 1 -hide_banner -loglevel error -y -i '" . $path . "' -c copy -map 0 -movflags +faststart '" . $path . "-fast.mp4'", $void, $response);
                unset($void);
                if ($response == 0 ) {
                    echo PHP_EOL. "fast start successful for: " . $path;
                    rename($path."-fast.mp4", $path);
                }
            }

        } else if ($value != "." && $value != ".." && $value != "@eaDir") {
            getDirContents($path);
        }
    }


    getDirContents($dest);

