<?php

use privuma\privuma;
use privuma\helpers\cloudFS;
use privuma\helpers\mediaFile;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = privuma::getInstance();
$ops = $privuma->getCloudFS();

if ($argc > 1) parse_str(implode('&', array_slice($argv, 1)), $_GET);

$conn = $privuma->getPDO();

$album = '';
if(isset($_GET['album'])){
    $album = $conn->quote($_GET['album']);
    echo PHP_EOL."checking broken media in album: {$album}";
	$album = " and album = {$album} ";
}

$select_results = $conn->query("SELECT id, album, filename FROM media where url is null and album != 'Favorites' {$album} order by id desc");
$results = $select_results->fetchAll(PDO::FETCH_ASSOC);
echo PHP_EOL."Checking ". count($results) . " database records";
foreach(array_chunk($results, 2000) as $key => $chunk) {
    foreach($chunk as $key => $row) {
        $album = $row['album'];
        $filename = $row['filename'];
        if(!is_null($album) && !is_null($filename)) {
            $preserve = privuma::getDataFolder() . DIRECTORY_SEPARATOR . mediaFile::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . $filename;
            $fileMissing = !$ops->is_file($preserve);
            $connectionOk = count($ops->scandir(privuma::getDataFolder() . DIRECTORY_SEPARATOR . mediaFile::MEDIA_FOLDER)) > 0;
            if (
                $fileMissing && $connectionOk
            ) {
                $delete_stmt = $conn->prepare("delete FROM media WHERE id = ?");
                $delete_stmt->execute([$row['id']]);
                echo PHP_EOL.$delete_stmt->rowCount() . " - Deleted missing remote media: " . $album . "/" . $filename;
            }
        }
    }
}
