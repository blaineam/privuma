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

$select_results = $conn->query("SELECT id, album, filename, url FROM media where duration is null and (filename like '%.mp4' or filename like '%.mov' or filename like '%.webm' or filename like '%.gif' or url like '%.mp4%' or url like '%.mov%' or url like '%.webm%' or url like '%.gif%') and album != 'Favorites' {$album} order by id desc");
$results = $select_results->fetchAll(PDO::FETCH_ASSOC);
echo PHP_EOL . 'Checking ' . count($results) . ' database records';
foreach (array_chunk($results, 2000) as $key => $chunk) {
    foreach ($chunk as $key => $row) {
        $album = $row['album'];
        $filename = $row['filename'];
        $url = $row['url'];
        if (!is_null($album) && !is_null($filename)) {
            if (is_null($url)) {
                $preserve = privuma::getDataFolder() . DIRECTORY_SEPARATOR . mediaFile::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . $filename;
                $url = 'http://' . privuma::getEnv('CLOUDFS_HTTP_ENDPOINT') . '/' . $preserve;
                echo PHP_EOL . 'Missing URL, Using Media URL Instead: ' . $url;
            }
            $duration = shell_exec('ffprobe -i "' . $url . '" -show_entries format=duration -v quiet -of csv="p=0"');
            if ($duration === false || is_null($duration)) {
                echo PHP_EOL . 'Failed to determine media Duration: ' . $url;
                continue;
            }
            $duration = intval($duration);
            echo PHP_EOL . 'Duration Determined: ' . $duration;
            $duration_stmt = $conn->prepare('update media set duration = ? WHERE id = ?');
            $duration_stmt->execute([$duration, $row['id']]);
        }
    }
}
