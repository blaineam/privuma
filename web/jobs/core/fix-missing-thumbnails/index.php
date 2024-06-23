<?php

use privuma\privuma;
use privuma\helpers\mediaFile;
use privuma\queue\QueueManager;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = privuma::getInstance();
$ops = $privuma->getCloudFS();

if ($argc > 1) {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}

$conn = $privuma->getPDO();

$qm = new QueueManager();

$album = '';
if(isset($_GET['album'])) {
    $album = $conn->quote($_GET['album']);
    echo PHP_EOL . "checking missing thumbnails for media in album: {$album}";
    $album = " and album = {$album} ";
}

$select_results = $conn->query("SELECT id, album, filename FROM media where filename like '%.mp4' and url is null and album != 'Favorites' {$album} order by id desc");
$results = $select_results->fetchAll(PDO::FETCH_ASSOC);
echo PHP_EOL . 'Checking ' . count($results) . ' database records';
foreach(array_chunk($results, 2000) as $key => $chunk) {
    foreach($chunk as $key => $row) {
        $album = $row['album'];
        $filename = str_replace('.mp4', '.jpg', $row['filename']);
        if(!is_null($album) && !is_null($filename)) {
            print_r('🔍 ');
            $videoPath = privuma::getDataFolder() . DIRECTORY_SEPARATOR . mediaFile::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . $filename;
            $thumbnailPath = str_replace('.mp4', '.jpg', $videoPath);
            $videoExists = $ops->is_file($videoPath);
            $fileMissing = !$ops->is_file($thumbnailPath);
            $connectionOk = count($ops->scandir(privuma::getDataFolder() . DIRECTORY_SEPARATOR . mediaFile::MEDIA_FOLDER)) > 0;
            if (
                $videoExists && $fileMissing && $connectionOk
            ) {
                echo PHP_EOL . "Queuing thumbnail generation for {$album}/{$filename}";
                $qm->enqueue(json_encode(['type' => 'generateThumbnail', 'data' => ['path' => $videoPath]]));
            }
        }
    }
}
