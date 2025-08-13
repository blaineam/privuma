<?php

use privuma\privuma;
use privuma\helpers\mediaFile;
use privuma\helpers\cloudFS;

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
    echo PHP_EOL . "checking media durations in album: {$albums}";
    $album = " and album in ({$albums}) ";
}

$exts = ['mp4', 'mov', 'webm'];
if (isset($_GET['exts'])) {
    $exts = explode(',', $_GET['exts']);
}

$refresh = '';
if (isset($_GET['refresh'])) {
    $refresh = ' or duration = -1 ';
    // Only Add Gif Duration during Refresh Call since Gifs are Slower to process.
    $exts[] = 'gif';
}

$extQuery = implode(' or ', array_map(function ($ext) {
    return " filename like '%.$ext' or url like '%.$ext%' ";
}, $exts));

$select_results = $conn->query("SELECT hash, album, filename, url FROM media where (duration is null {$refresh}) and ({$extQuery}) and album != 'Favorites' {$album} group by hash order by id desc");
$results = $select_results->fetchAll(PDO::FETCH_ASSOC);
$total = count($results);
echo PHP_EOL . 'Checking ' . $total . ' database records';

function getPos($innerKey, $outerKey, $total)
{
    $pos = ($outerKey * 2000) + $innerKey;
    $percent = round($pos / $total, 5) * 100;
    return "$pos / $total ($percent%)  ";
}

foreach (array_chunk($results, 2000) as $key => $chunk) {
    foreach ($chunk as $ikey => $row) {
        $album = $row['album'];
        $filename = $row['filename'];
        $url = $row['url'];
        if (!is_null($album) && !is_null($filename)) {
            if (is_null($url) || empty($url)) {
                $preserve = privuma::getDataFolder() . DIRECTORY_SEPARATOR . mediaFile::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . $filename;
                $url = 'http://' . privuma::getEnv('CLOUDFS_HTTP_ENDPOINT') . '/' . cloudFS::encode($preserve);
                //echo PHP_EOL . 'Missing URL, Using Media URL Instead: ' . $url;
            }
            $duration = shell_exec('ffprobe -i "' . $url . '" -show_entries format=duration -v quiet -of csv="p=0"');
            if ($duration === false || is_null($duration)) {
                echo PHP_EOL . getPos($ikey, $key, $total) . 'Failed to determine media Duration: ' . $url;
                $duration_stmt = $conn->prepare('update media set duration = ? WHERE hash = ? AND (duration is null OR duration = -1)');
                $duration_stmt->execute([-1, $row['hash']]);
                continue;
            }
            $duration = intval($duration);
            echo PHP_EOL . getPos($ikey, $key, $total) . 'Duration Determined: ' . $duration;
            $duration_stmt = $conn->prepare('update media set duration = ? WHERE hash = ? AND (duration is null OR duration = -1)');
            if ($duration_stmt !== false) {
                $duration_stmt->execute([$duration, $row['hash']]);
            }
        }
    }
}
