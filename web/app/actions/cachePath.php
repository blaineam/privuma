<?php

namespace privuma\actions;

use privuma\privuma;

class cachePath {
    function __construct(array $data) {
        // default json cache;
        $cache = "mediadirs";

        if(isset($data['cacheName'])) {
            $cache = $data['cacheName'];
            unset($data['cacheName']);
            echo PHP_EOL. "Using cache: " . $cache;
        }

        if(isset($data['emptyCache'])) {
            $this->emptyCache($cache);
            unset($data['emptyCache']);
            echo PHP_EOL."Emptied Cache";
        }

        if(isset($data['key'])) {
            $this->appendCache($data['value'], $data['key'], $cache);
            echo PHP_EOL."Saved Cache Key: ". $data['key'];
        }
    }

    public static function emptyCache(string $cache) {

        $file = privuma::getOutputDirectory() . DIRECTORY_SEPARATOR . "cache" . DIRECTORY_SEPARATOR . $cache . ".json";
        unlink($file);

    }

    public static function appendCache($data, string $key, string $cache) {

        $file = privuma::getOutputDirectory() . DIRECTORY_SEPARATOR . "cache" . DIRECTORY_SEPARATOR . $cache . ".json";

        /*
        if file exists, open in read+ plus mode so we can try to lock it
        -- opening in w+ would truncate the file *before* we could get a lock!
        */

        if(version_compare(PHP_VERSION, '5.2.6') >= 0) {
            $mode = 'c+';
        } else {
            //'c+' would be the ideal $mode to use, but that's only
            //available in PHP >=5.2.6

            $mode = file_exists($file) ? 'r+' : 'w+';
            //there's a small chance of a race condition here
            // -- two processes could end up opening the file with 'w+'
        }

        //open file
        if($handle = @fopen($file, $mode)) {
            //get write lock
            flock($handle,LOCK_EX);

            //get current data
            $json = file_exists($file) ? json_decode(file_get_contents($file), true) ?? [] : [];

            //set new data;
            $json[$key] = $data;

            //write data
            file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT));

            //release write lock -- fclose does this automatically
            //but only in PHP <= 5.3.2
            flock($handle,LOCK_UN);

            //close file
            fclose($handle);
        }
    }
}
