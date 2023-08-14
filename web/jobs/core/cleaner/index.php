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

function remove_utf8_bom($text)
{
    $bom = pack('H*','EFBBBF');
    $text = preg_replace("/^$bom/", '', $text);
    return $text;
}

$items = explode(PHP_EOL,$data);

$ops = $privuma->getCloudFS();
foreach($items as $item) {
    $parts = explode(',', $item);
    $path = remove_utf8_bom($parts[0]);
    $paths = explode(DIRECTORY_SEPARATOR, $path);
    $album = $paths[0];
    $filename = $paths[1];
    echo PHP_EOL."Deleting media at path: " . $parts[0];
    (new mediaFile($filename, $album))->delete();
}
echo PHP_EOL."All Cleaned Up";
