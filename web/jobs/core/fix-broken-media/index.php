<?php

use privuma\privuma;
use privuma\helpers\mediaFile;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = privuma::getInstance();
$ops = $privuma->getCloudFS();

if ($argc > 1) {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}

$conn = $privuma->getPDO();

$album = '';
if (isset($_GET['albums'])) {
    $albums = implode(', ', array_map(function ($albumItem) use ($conn) {
        return $conn->quote($albumItem);
    }, explode(',', $_GET['albums'])));
    echo PHP_EOL . "checking broken links in album: {$albums}";
    $album = " and album in ({$albums}) ";
}

$select_results = $conn->query("SELECT id, album, filename FROM media where url is null and album != 'Favorites' {$album} order by id desc");
$results = $select_results->fetchAll(PDO::FETCH_ASSOC);
echo PHP_EOL . 'Checking ' . count($results) . ' database records';
foreach (array_chunk($results, 2000) as $key => $chunk) {
    foreach ($chunk as $key => $row) {
        $album = $row['album'];
        $filename = $row['filename'];
        if (!is_null($album) && !is_null($filename)) {
            $preserve = privuma::getDataFolder() . DIRECTORY_SEPARATOR . mediaFile::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . $filename;
            $fileMissing = !$ops->is_file($preserve);
            $connectionOk = count($ops->scandir(privuma::getDataFolder() . DIRECTORY_SEPARATOR . mediaFile::MEDIA_FOLDER)) > 0;
            if (
                $fileMissing && $connectionOk
            ) {
                if (isset($_GET['clean'])) {
                    $delete_stmt = $conn->prepare('delete FROM media WHERE id = ?');
                    $delete_stmt->execute([$row['id']]);
                    echo PHP_EOL . $delete_stmt->rowCount() . ' - Deleted missing remote media: ' . $album . '/' . $filename;
                } else {
                    echo PHP_EOL . 'Found to be Deleted for missing remote media: ' . $album . '/' . $filename;
                }
            }
        }
    }
}
