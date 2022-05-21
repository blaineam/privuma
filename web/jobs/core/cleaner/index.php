<?php

// Query for cached images from media downloaders: 
// select album || '/' || filename as path from media where filename not like '%.mp4' and  filename not like '%.webm' and url is null and album not like '%comics---%' and album not like '%Syncs---%';

use privuma\privuma;

use privuma\helpers\mediaFile;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = privuma::getInstance();
if(!is_file(__DIR__.DIRECTORY_SEPARATOR."cleanup.txt")) {
    echo PHP_EOL."Nothing to clean";
    return;
}
$data = file_get_contents(__DIR__.DIRECTORY_SEPARATOR."cleanup.txt");
unlink(__DIR__.DIRECTORY_SEPARATOR."cleanup.txt");

if(empty($data)) {
    echo PHP_EOL."Nothing to clean";
    return;
}

$items = explode(PHP_EOL,$data);

$ops = $privuma->getCloudFS();
foreach($items as $item) {
    $parts = explode(',', $item);
    echo PHP_EOL."Deleting media at path: " . $parts[0];
    (new mediaFile(basename($parts[0]), dirname($parts[0])))->delete();
}
echo PHP_EOL."All Cleaned Up";